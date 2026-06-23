<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Core\Config;
use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * G22.S20 — Recovery Key opzionale per docente (Modalità A).
 *
 * Permette al docente di:
 *   1) firmare manifest del bundle export con HMAC(R, payload), così l'import
 *      su altro server può verificare l'autenticità senza fidarsi del client;
 *   2) (futuro Modalità B) ricostruire KEK senza KMS via kek_recovery_wrapped
 *      stored in teacher_keys (migration 035).
 *
 * Modello:
 *   - R = 32 bytes random (mai persistito in chiaro). Mostrato al docente
 *     UNA SOLA VOLTA (download PDF cassaforte).
 *   - wrapped_recovery = AES-256-GCM(R, KMS_MASTER) → DB teacher_recovery_keys
 *     (permette ad admin/server di derivare R per re-firma manifest se KMS OK).
 *   - kek_recovery_wrapped = AES-256-GCM(KEK, R) → DB teacher_keys
 *     (permette ricostruzione KEK da R se KMS perso).
 *
 * Verifica import:
 *   - Client invia il manifest.json (con campo `hmac`) + R (codice docente).
 *   - Server calcola HMAC_SHA256(R, canonical_payload) e compara con `hmac`.
 *   - Se match → manifest autentico. NO check ownership: la Recovery Key
 *     è una chiave fisica condivisibile (modello "passa il PDF cassaforte").
 *
 * Audit:
 *   - generate / download / use / revoke loggati in teacher_recovery_audit.
 */
final class TeacherRecoveryService
{
    private const CIPHER_ALGO = 'aes-256-gcm';
    private const IV_LEN      = 12;
    private const TAG_LEN     = 16;
    private const R_LEN       = 32;
    private const HKDF_INFO   = 'pantedu-recovery-key-v1';

    private ?string $kmsMaster = null;

    public function __construct(?string $kmsMasterHex = null)
    {
        $hex = $kmsMasterHex
            ?? ($_ENV['KMS_MASTER_KEY'] ?? null)
            ?? (string)Config::get('crypto.kms_master_key', '');
        if ($hex === '' || !preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
            return;
        }
        $this->kmsMaster = hex2bin($hex);
    }

    public function isConfigured(): bool
    {
        return $this->kmsMaster !== null;
    }

    /**
     * Stato Recovery Key per il docente.
     * @return array{exists:bool,created_at?:string,download_count?:int,last_downloaded_at?:?string,revoked_at?:?string}
     */
    public function status(int $teacherId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT created_at, download_count, last_downloaded_at, revoked_at
               FROM teacher_recovery_keys
              WHERE user_id = ?'
        );
        $stmt->execute([$teacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['exists' => false];
        }
        return [
            'exists'             => true,
            'created_at'         => (string)$row['created_at'],
            'download_count'     => (int)$row['download_count'],
            'last_downloaded_at' => $row['last_downloaded_at'] ? (string)$row['last_downloaded_at'] : null,
            'revoked_at'         => $row['revoked_at'] ? (string)$row['revoked_at'] : null,
        ];
    }

    /**
     * Genera nuova Recovery Key (R 32 bytes). NON idempotente: se esiste
     * non rigenera (usa rotate() per rotazione esplicita).
     *
     * Side effects:
     *   1) INSERT teacher_recovery_keys(user_id, wrapped_recovery, recovery_kv)
     *   2) Per ogni teacher_keys row del docente: calcola kek_recovery_wrapped
     *      = AES-GCM(KEK, R) — permette ricostruzione futura KEK da R.
     *   3) Audit row con action=generate.
     *
     * @return array{ok:bool,recovery_hex?:string,recovery_b32?:string,error?:string}
     */
    public function generate(int $teacherId, ?string $ip = null, ?string $userAgent = null): array
    {
        $this->requireKms();
        $db = Database::connection();

        // Idempotency: skip se esiste e non revocata.
        $check = $db->prepare('SELECT user_id FROM teacher_recovery_keys WHERE user_id = ? AND revoked_at IS NULL');
        $check->execute([$teacherId]);
        if ($check->fetchColumn()) {
            $this->audit($teacherId, 'generate', false, 'already_exists', $ip, $userAgent);
            return ['ok' => false, 'error' => 'recovery_key_already_exists'];
        }

        $r  = random_bytes(self::R_LEN);
        $wrapped = $this->aesGcmEncrypt($r, $this->kmsMaster);
        $kv = 1;

        $db->beginTransaction();
        try {
            // Upsert recovery key (delete vecchia revocata se presente)
            $db->prepare('DELETE FROM teacher_recovery_keys WHERE user_id = ?')
               ->execute([$teacherId]);
            $ins = $db->prepare(
                'INSERT INTO teacher_recovery_keys (user_id, wrapped_recovery, recovery_kv)
                 VALUES (?, ?, ?)'
            );
            $ins->execute([$teacherId, $wrapped, $kv]);

            // Wrap KEK di ogni key_version con R → recovery future-proof Modalità B
            $this->wrapAllTeacherKeksWithR($teacherId, $r, $kv);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            $this->audit($teacherId, 'generate', false, 'exception: ' . $e->getMessage(), $ip, $userAgent);
            throw $e;
        }

        $this->audit($teacherId, 'generate', true, null, $ip, $userAgent);

        return [
            'ok'           => true,
            'recovery_hex' => bin2hex($r),
            'recovery_b32' => $this->base32Encode($r),
        ];
    }

    /**
     * Revoca Recovery Key esistente (segnando revoked_at). NB: non rotea la
     * KEK quindi un attaccante in possesso della vecchia R può ancora
     * ricostruire la KEK fino a rotate() esplicito di TeacherCryptoService.
     */
    public function revoke(int $teacherId, ?string $ip = null, ?string $userAgent = null): array
    {
        $stmt = Database::connection()->prepare(
            'UPDATE teacher_recovery_keys SET revoked_at = NOW()
              WHERE user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$teacherId]);
        $changed = $stmt->rowCount() > 0;
        $this->audit($teacherId, 'revoke', $changed, $changed ? null : 'not_found_or_already_revoked', $ip, $userAgent);
        return ['ok' => $changed];
    }

    /**
     * Marca download (per audit). Da chiamare ogni volta che il PDF viene
     * generato/scaricato dal docente.
     */
    public function markDownload(int $teacherId, ?string $ip = null, ?string $userAgent = null): void
    {
        Database::connection()->prepare(
            'UPDATE teacher_recovery_keys
                SET download_count = download_count + 1, last_downloaded_at = NOW()
              WHERE user_id = ?'
        )->execute([$teacherId]);
        $this->audit($teacherId, 'download', true, null, $ip, $userAgent);
    }

    /**
     * Firma manifest bundle. Calcola HMAC_SHA256(R, canonical_payload).
     * Richiede che il docente abbia già generato la Recovery Key.
     * Usa wrapped_recovery → unwrap con KMS → derive auth-key via HKDF(R).
     *
     * @param array $payload manifest dict (sarà serializzato canonical)
     * @return string|null base64 dell'HMAC, o null se Recovery Key assente/revocata
     */
    public function signManifestForExporter(int $teacherId, array $payload): ?string
    {
        $r = $this->loadR($teacherId);
        if ($r === null) {
            return null;
        }
        return $this->computeHmac($r, $payload);
    }

    /**
     * Verifica HMAC del manifest fornito dal client all'import.
     * R viene fornita dal CLIENT (codice digitato dal docente importatore).
     * NO query DB: chiunque possieda R e il manifest può importare.
     *
     * @param string $rHexOrB32 codice R inserito dall'utente (64 hex chars o base32)
     * @param array  $payload   manifest senza campo `hmac`
     * @param string $hmacB64   HMAC da verificare (base64 url-safe)
     * @return bool true se HMAC matches (constant-time compare)
     */
    public function verifyManifestHmac(string $rHexOrB32, array $payload, string $hmacB64): bool
    {
        $r = $this->parseRecoveryCode($rHexOrB32);
        if ($r === null) {
            return false;
        }
        $expected = $this->computeHmac($r, $payload);
        return hash_equals($expected, $hmacB64);
    }

    /**
     * Parse codice utente: accetta 64 hex chars (con/senza spazi) o base32
     * 52 chars (32 bytes encoded). Ritorna 32 bytes binary o null se invalid.
     */
    public function parseRecoveryCode(string $input): ?string
    {
        $clean = preg_replace('/[\s\-]+/', '', strtoupper(trim($input))) ?? '';
        // Hex 64 char
        if (preg_match('/^[0-9A-F]{64}$/', $clean)) {
            return hex2bin($clean) ?: null;
        }
        // Base32 52 char (32 bytes = ceil(32*8/5) = 52 chars con padding) — ma
        // accetta anche senza padding (52 o 56 con padding).
        $b32 = preg_replace('/=+$/', '', $clean);
        if (preg_match('/^[A-Z2-7]{52}$/', $b32 ?? '')) {
            $bin = $this->base32Decode($b32);
            if ($bin !== null && strlen($bin) === self::R_LEN) {
                return $bin;
            }
        }
        return null;
    }

    /**
     * Log attempt fallito di verifica (per detection brute force). Non
     * espone se la Recovery Key esiste o meno.
     */
    public function logVerifyAttempt(int $teacherId, bool $success, ?string $ip = null, ?string $userAgent = null, ?string $note = null): void
    {
        $this->audit($teacherId, 'use', $success, $note, $ip, $userAgent);
    }

    // ──────── internals ────────

    /** Unwrap R dal DB usando KMS. Ritorna null se non esiste/revocata. */
    private function loadR(int $teacherId): ?string
    {
        $this->requireKms();
        $stmt = Database::connection()->prepare(
            'SELECT wrapped_recovery FROM teacher_recovery_keys
              WHERE user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$teacherId]);
        $wrapped = $stmt->fetchColumn();
        if ($wrapped === false || $wrapped === null) {
            return null;
        }
        return $this->aesGcmDecrypt((string)$wrapped, $this->kmsMaster);
    }

    /**
     * Per ogni teacher_keys row del docente, calcola kek_recovery_wrapped =
     * AES-GCM(KEK, R) usando la TKEK derivata dal KMS. Permette ricostruzione
     * KEK da R nel caso il KMS sia perso (Modalità B).
     */
    private function wrapAllTeacherKeksWithR(int $teacherId, string $r, int $kv): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT key_version, wrapped_kek FROM teacher_keys WHERE teacher_id = ?');
        $stmt->execute([$teacherId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return;
        }

        $crypto = new TeacherCryptoService();
        foreach ($rows as $row) {
            $kvRow = (int)$row['key_version'];
            // Re-derive TKEK + unwrap KEK (usiamo reflection-free: chiama
            // metodo pubblico via wrapper privato di TeacherCryptoService).
            $kek = $this->unwrapKekForVersion($teacherId, $kvRow, (string)$row['wrapped_kek']);
            if ($kek === null) {
                continue;
            }
            $wrappedWithR = $this->aesGcmEncrypt($kek, $r);
            $upd = $db->prepare(
                'UPDATE teacher_keys SET kek_recovery_wrapped = ?, recovery_wrap_kv = ?
                  WHERE teacher_id = ? AND key_version = ?'
            );
            $upd->execute([$wrappedWithR, $kv, $teacherId, $kvRow]);
        }
    }

    /**
     * Replica logica di TeacherCryptoService::loadAndUnwrapKek (non esposta).
     * HKDF(KMS, teacher_id, kv) → TKEK → AES-GCM unwrap.
     */
    private function unwrapKekForVersion(int $teacherId, int $kv, string $wrapped): ?string
    {
        $hkdfPrefix = 'pantedu-teacher-kek-v1';
        $salt = $hkdfPrefix . '|' . $teacherId;
        $info = (string)$kv;
        $tkek = hash_hkdf('sha256', $this->kmsMaster, 32, $info, $salt);
        if ($tkek === false) {
            return null;
        }
        $expectedLen = self::IV_LEN + 32 + self::TAG_LEN;
        if (strlen($wrapped) !== $expectedLen) {
            return null;
        }
        $iv  = substr($wrapped, 0, self::IV_LEN);
        $ct  = substr($wrapped, self::IV_LEN, 32);
        $tag = substr($wrapped, self::IV_LEN + 32, self::TAG_LEN);
        $kek = openssl_decrypt($ct, self::CIPHER_ALGO, $tkek, OPENSSL_RAW_DATA, $iv, $tag);
        return $kek !== false ? $kek : null;
    }

    /** AES-256-GCM encrypt; output: iv(12) || ct(N) || tag(16). */
    private function aesGcmEncrypt(string $plaintext, string $key): string
    {
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $ct = openssl_encrypt($plaintext, self::CIPHER_ALGO, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);
        if ($ct === false) {
            throw new RuntimeException('aes_gcm_encrypt_failed');
        }
        return $iv . $ct . $tag;
    }

    private function aesGcmDecrypt(string $blob, string $key): ?string
    {
        $ivLen = self::IV_LEN;
        $tagLen = self::TAG_LEN;
        if (strlen($blob) < $ivLen + $tagLen) {
            return null;
        }
        $iv  = substr($blob, 0, $ivLen);
        $ct  = substr($blob, $ivLen, strlen($blob) - $ivLen - $tagLen);
        $tag = substr($blob, -$tagLen);
        $pt = openssl_decrypt($ct, self::CIPHER_ALGO, $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $pt !== false ? $pt : null;
    }

    /**
     * HMAC del manifest. Deriva una chiave auth-only da R via HKDF (no riuso
     * di R diretta come HMAC key per allinearsi a best practice domain-separation).
     */
    private function computeHmac(string $r, array $payload): string
    {
        $authKey = hash_hkdf('sha256', $r, 32, 'manifest-hmac', self::HKDF_INFO);
        if ($authKey === false) {
            throw new RuntimeException('hkdf_authkey_failed');
        }
        $canonical = $this->canonicalize($payload);
        return base64_encode(hash_hmac('sha256', $canonical, $authKey, true));
    }

    /**
     * Canonicalizza payload in modo deterministico: JSON con chiavi sortite
     * ricorsivamente, no whitespace, no escape Unicode/Slash.
     */
    private function canonicalize(array $payload): string
    {
        $sort = function (&$v) use (&$sort) {
            if (is_array($v)) {
                $isAssoc = array_keys($v) !== range(0, count($v) - 1);
                if ($isAssoc) {
                    ksort($v);
                }
                foreach ($v as &$child) {
                    $sort($child);
                }
            }
        };
        $copy = $payload;
        $sort($copy);
        return json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function audit(int $teacherId, string $action, bool $success, ?string $note, ?string $ip, ?string $userAgent): void
    {
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO teacher_recovery_audit (user_id, action, ip, user_agent, success, note)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$teacherId, $action, $ip, $userAgent, $success ? 1 : 0, $note]);
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function requireKms(): void
    {
        if ($this->kmsMaster === null) {
            throw new RuntimeException('kms_not_configured');
        }
    }

    // ──────── base32 (RFC 4648, no padding required on decode) ────────

    private function base32Encode(string $bin): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i = 0, $n = strlen($bin); $i < $n; $i++) {
            $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i < strlen($bits); $i += 5) {
            $chunk = substr($bits, $i, 5);
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $out .= $alphabet[bindec($chunk)];
        }
        return $out;
    }

    private function base32Decode(string $b32): ?string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        $b32 = strtoupper($b32);
        for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
            $pos = strpos($alphabet, $b32[$i]);
            if ($pos === false) {
                return null;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }
        return $out;
    }
}
