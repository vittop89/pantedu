<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Core\Config;
use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Phase 25.D — Envelope encryption per teacher_content + risdoc_overrides.
 *
 * Modello (vedi ADR-006):
 *   1. KMS_MASTER_KEY (env var, 32 bytes hex) → MAI in DB.
 *   2. HKDF-SHA256(KMS_MASTER, "pantedu-teacher-kek-v1|{teacher_id}",
 *      info=key_version) → TKEK_<teacher_id> (in-memory only).
 *   3. KEK random 32 bytes wrapped con TKEK via AES-256-GCM →
 *      teacher_keys.wrapped_kek (DB, una row per teacher).
 *   4. body_pt encrypt: AES-256-GCM(KEK, json_encode(body_pt)) →
 *      teacher_content.body_pt_ct/iv/tag/kv.
 *
 * API:
 *   - encrypt(int $teacherId, string $plaintext): array{ciphertext, iv, tag, kv}
 *   - decrypt(int $teacherId, array $envelope): string
 *   - shred(int $teacherId): void                  (Art. 17 GDPR O(1))
 *   - rotate(int $teacherId): int                  (next key_version)
 *   - ensureTeacherKey(int $teacherId): int        (auto-create on first use)
 *
 * Logging:
 *   Ogni operazione encrypt/decrypt/shred/rotate logga in crypto_access_log
 *   con (accessor_id, teacher_id, operation, reason?, outcome).
 *
 * KMS_MASTER_KEY format:
 *   64 hex chars (32 bytes). Generabile con:
 *     php tools/crypto/generate_kms_key.php
 *   Inserito in $_ENV['KMS_MASTER_KEY'] via .env (Dotenv safeLoad).
 *   In produzione: env var sistema (export KMS_MASTER_KEY=...).
 *
 * Errori:
 *   - kms_not_configured: KMS_MASTER_KEY mancante o malformata.
 *   - decrypt_tag_mismatch: ciphertext tampered.
 *   - kek_unwrap_failed: wrapped_kek corrotto in DB (data corruption).
 *   - teacher_key_missing: shred-ed teacher tries to decrypt → DENY.
 */
final class TeacherCryptoService
{
    private const HKDF_INFO_PREFIX = 'pantedu-teacher-kek-v1';
    private const CIPHER_ALGO      = 'aes-256-gcm';
    private const IV_LEN           = 12;   // bytes (GCM standard)
    private const TAG_LEN          = 16;   // bytes (GCM standard)
    private const KEK_LEN          = 32;   // bytes (AES-256)

    private ?string $kmsMaster = null;

    public function __construct(?string $kmsMasterHex = null)
    {
        // Phase 25.D — KMS lazy load: ottieni dalla env, validate hex 64-char.
        $hex = $kmsMasterHex
            ?? ($_ENV['KMS_MASTER_KEY'] ?? null)
            ?? (string)Config::get('crypto.kms_master_key', '');
        if ($hex === '' || !preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
            // Lazy: lasciamo $kmsMaster null. Errore esplicito al primo uso.
            return;
        }
        $this->kmsMaster = hex2bin($hex);
    }

    public function isConfigured(): bool
    {
        return $this->kmsMaster !== null;
    }

    /**
     * Encrypt plaintext per il teacher dato. Crea automaticamente la KEK
     * se non esiste (idempotent).
     *
     * @return array{ciphertext: string, iv: string, tag: string, kv: int}
     */
    public function encrypt(int $teacherId, string $plaintext, ?int $accessorId = null): array
    {
        $this->requireKms();
        $kv  = $this->ensureTeacherKey($teacherId);
        $kek = $this->loadAndUnwrapKek($teacherId, $kv);

        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $ct = openssl_encrypt(
            $plaintext,
            self::CIPHER_ALGO,
            $kek,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );
        if ($ct === false) {
            $this->logAccess($accessorId ?? $teacherId, $teacherId, 'encrypt', 'error', null, null);
            throw new RuntimeException('encrypt_failed');
        }
        $this->logAccess($accessorId ?? $teacherId, $teacherId, 'encrypt', 'ok', null, null);
        return [
            'ciphertext' => $ct,
            'iv'         => $iv,
            'tag'        => $tag,
            'kv'         => $kv,
        ];
    }

    /**
     * Decrypt ciphertext per il teacher dato. accessorId distingue
     * "teacher accede al proprio" vs "super_admin accede a altrui" (richiede
     * reason in caller).
     *
     * @param array{ciphertext: string, iv: string, tag: string, kv: int} $envelope
     */
    public function decrypt(int $teacherId, array $envelope, ?int $accessorId = null, ?string $reason = null): string
    {
        $this->requireKms();
        $kv = (int)$envelope['kv'];
        $kek = $this->loadAndUnwrapKek($teacherId, $kv);

        $plaintext = openssl_decrypt(
            $envelope['ciphertext'],
            self::CIPHER_ALGO,
            $kek,
            OPENSSL_RAW_DATA,
            $envelope['iv'],
            $envelope['tag']
        );
        if ($plaintext === false) {
            $this->logAccess($accessorId ?? $teacherId, $teacherId, 'decrypt', 'error', null, $reason);
            throw new RuntimeException('decrypt_tag_mismatch');
        }
        $this->logAccess($accessorId ?? $teacherId, $teacherId, 'decrypt', 'ok', null, $reason);
        return $plaintext;
    }

    /**
     * Crypto-shredding O(1) — Art. 17 GDPR.
     *
     * Cancella TUTTE le row teacher_keys del teacher. Dopo: tutti i body
     * cifrati nel DB diventano UNRECOVERABLE (no KEK, no decrypt possibile).
     * Il dato cifrato resta nelle tabelle ma è cryptographically erased.
     *
     * Idempotent: chiamarlo 2 volte non causa errore.
     */
    public function shred(int $teacherId, ?int $accessorId = null, ?string $reason = null): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM teacher_keys WHERE teacher_id = ?'
        );
        $stmt->execute([$teacherId]);
        $this->logAccess($accessorId ?? 0, $teacherId, 'shred', 'ok', null, $reason);
    }

    /**
     * Rotation annuale: crea una nuova KEK con incremented key_version.
     * Le row esistenti restano valide (decrypt usa il loro body_*_kv).
     * Una migration job successiva re-encrypts row con new kv (lazy:
     * ad ogni write, oppure batch via tools/crypto/rotate_kek.php).
     *
     * @return int next key_version creato
     */
    public function rotate(int $teacherId, ?int $accessorId = null, ?string $reason = null): int
    {
        $this->requireKms();
        $db = Database::connection();
        // Trova versione corrente max
        $stmt = $db->prepare('SELECT MAX(key_version) FROM teacher_keys WHERE teacher_id = ?');
        $stmt->execute([$teacherId]);
        $current = (int)($stmt->fetchColumn() ?: 0);
        $next = $current + 1;

        $kek = random_bytes(self::KEK_LEN);
        $tkek = $this->deriveTkek($teacherId, $next);
        $wrapped = $this->wrapKek($kek, $tkek);

        $ins = $db->prepare(
            'INSERT INTO teacher_keys (teacher_id, key_version, wrapped_kek, created_at, rotated_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        );
        $ins->execute([$teacherId, $next, $wrapped]);
        $this->logAccess($accessorId ?? $teacherId, $teacherId, 'rotate', 'ok', null, $reason);
        return $next;
    }

    /**
     * Idempotent: crea la KEK del teacher se non esiste, altrimenti ritorna
     * l'attuale max key_version. Chiamata implicitamente da encrypt().
     */
    public function ensureTeacherKey(int $teacherId): int
    {
        $this->requireKms();
        $db = Database::connection();
        $stmt = $db->prepare('SELECT MAX(key_version) FROM teacher_keys WHERE teacher_id = ?');
        $stmt->execute([$teacherId]);
        $kv = $stmt->fetchColumn();
        if ($kv !== false && $kv !== null) {
            return (int)$kv;
        }

        // SAFEGUARD G26.recovery — se teacher_keys row mancante MA esistono già
        // dati cifrati per il teacher, NON generare nuova KEK (renderebbe
        // tutto indecifrabile). Probabile incidente: row cancellata per errore.
        //
        // Override esplicito via env ALLOW_CRYPTO_REGENERATE=1 per casi
        // legittimi (es. nuovo teacher dopo shred volontario in audit log).
        $allowRegenerate = ($_ENV['ALLOW_CRYPTO_REGENERATE'] ?? '') === '1';
        if (!$allowRegenerate) {
            $this->guardAgainstAccidentalKeyRegen($teacherId);
        }

        // Prima encrypt (legittimo): genera KEK v1
        $kek  = random_bytes(self::KEK_LEN);
        $tkek = $this->deriveTkek($teacherId, 1);
        $wrapped = $this->wrapKek($kek, $tkek);

        // INSERT con ON DUPLICATE per concorrenza (Phase 25.B2 pattern)
        $ins = $db->prepare(
            'INSERT INTO teacher_keys (teacher_id, key_version, wrapped_kek)
             VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE key_version = key_version'
        );
        $ins->execute([$teacherId, $wrapped]);
        $this->logAccess($teacherId, $teacherId, 'wrap', 'ok', null, 'auto-create on first encrypt');
        return 1;
    }

    /**
     * G26 — Difesa contro accidentale wipe di teacher_keys che renderebbe
     * indecifrabili tutti i blob esistenti. Conta righe già cifrate; se >0,
     * solleva eccezione esplicita richiedendo intervento manuale.
     */
    private function guardAgainstAccidentalKeyRegen(int $teacherId): void
    {
        $db = Database::connection();

        // Check 1: teacher_content con body_pt cifrato o map_blob_path
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM teacher_content
             WHERE teacher_id = ?
               AND (body_pt_ct IS NOT NULL OR map_blob_path IS NOT NULL)'
        );
        $stmt->execute([$teacherId]);
        $encryptedRows = (int)$stmt->fetchColumn();

        // Check 2: file blob in storage/maps_enc/{tid}/
        $blobDir = (string)Config::get('app.paths.storage', dirname(__DIR__, 3) . '/storage')
                 . '/maps_enc/' . $teacherId;
        $blobFiles = is_dir($blobDir) ? count(glob($blobDir . '/*.bin') ?: []) : 0;

        if ($encryptedRows > 0 || $blobFiles > 0) {
            $this->logAccess(
                $teacherId,
                $teacherId,
                'wrap',
                'denied',
                null,
                "guard: teacher_keys missing but encrypted_rows=$encryptedRows blob_files=$blobFiles"
            );
            throw new RuntimeException(
                "kek_regen_guard: teacher_keys row mancante per teacher_id=$teacherId "
                . "MA esistono già $encryptedRows row cifrate + $blobFiles blob file. "
                . "Generare nuova KEK renderebbe tutto indecifrabile.\n\n"
                . "Cosa fare:\n"
                . "  1. Verifica se teacher_keys row è stata cancellata accidentalmente.\n"
                . "  2. Ripristina da backup DB (mysqldump teacher_keys).\n"
                . "  3. Se davvero vuoi rigenerare (e perdere tutti i blob esistenti),\n"
                . "     setta ALLOW_CRYPTO_REGENERATE=1 in .env.local e riavvia."
            );
        }
    }

    /** Verifica che KMS_MASTER_KEY sia presente, lancia eccezione se no. */
    private function requireKms(): void
    {
        if ($this->kmsMaster === null) {
            throw new RuntimeException(
                'kms_not_configured: KMS_MASTER_KEY env var missing or invalid. '
                . 'Generate with: php tools/crypto/generate_kms_key.php'
            );
        }
    }

    /**
     * HKDF-SHA256(KMS_MASTER, salt, info) → TKEK 32 bytes.
     * Salt include teacher_id per per-teacher derivation.
     * Info include key_version per supportare rotation senza re-derivare
     * da scratch (next version = HKDF con nuovo info).
     */
    private function deriveTkek(int $teacherId, int $keyVersion): string
    {
        $salt = self::HKDF_INFO_PREFIX . '|' . $teacherId;
        $info = (string)$keyVersion;
        $tkek = hash_hkdf('sha256', $this->kmsMaster, 32, $info, $salt);
        if ($tkek === false || strlen($tkek) !== 32) {
            throw new RuntimeException('hkdf_failed');
        }
        return $tkek;
    }

    /** Wrap KEK con TKEK via AES-256-GCM. Output: iv(12) || ct(32) || tag(16) = 60 bytes. */
    private function wrapKek(string $kek, string $tkek): string
    {
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $ct = openssl_encrypt(
            $kek,
            self::CIPHER_ALGO,
            $tkek,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );
        if ($ct === false) {
            throw new RuntimeException('kek_wrap_failed');
        }
        return $iv . $ct . $tag;
    }

    /** Unwrap wrapped_kek da DB → KEK in chiaro (memoria). */
    private function loadAndUnwrapKek(int $teacherId, int $keyVersion): string
    {
        $stmt = Database::connection()->prepare(
            'SELECT wrapped_kek FROM teacher_keys WHERE teacher_id = ? AND key_version = ?'
        );
        $stmt->execute([$teacherId, $keyVersion]);
        $wrapped = $stmt->fetchColumn();
        if ($wrapped === false || $wrapped === null) {
            // Crypto-shredding effetto: row teacher_keys cancellata.
            $this->logAccess($teacherId, $teacherId, 'unwrap', 'denied', null, 'teacher_key_missing');
            throw new RuntimeException('teacher_key_missing');
        }

        $expectedLen = self::IV_LEN + self::KEK_LEN + self::TAG_LEN;
        if (strlen($wrapped) !== $expectedLen) {
            throw new RuntimeException('wrapped_kek_corrupted');
        }
        $iv  = substr($wrapped, 0, self::IV_LEN);
        $ct  = substr($wrapped, self::IV_LEN, self::KEK_LEN);
        $tag = substr($wrapped, self::IV_LEN + self::KEK_LEN, self::TAG_LEN);

        $tkek = $this->deriveTkek($teacherId, $keyVersion);
        $kek = openssl_decrypt($ct, self::CIPHER_ALGO, $tkek, OPENSSL_RAW_DATA, $iv, $tag);
        if ($kek === false) {
            throw new RuntimeException('kek_unwrap_failed');
        }
        return $kek;
    }

    /**
     * Append-only log di ogni operazione. Reason obbligatoria se accessor
     * != teacher (verificato dal caller, non hardcoded qui per non
     * inquinare le chiamate self-access).
     */
    private function logAccess(int $accessorId, int $teacherId, string $operation, string $outcome, ?int $rowId = null, ?string $reason = null): void
    {
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO crypto_access_log
                    (accessor_id, teacher_id, table_name, row_id, operation, reason, outcome)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $accessorId, $teacherId, 'teacher_content', $rowId,
                $operation, $reason, $outcome,
            ]);
        } catch (\Throwable) {
            // Best-effort: log failure non deve bloccare crypto operation.
        }
    }
}
