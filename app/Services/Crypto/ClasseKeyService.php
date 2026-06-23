<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Phase 25.D6 — Envelope encryption per published_content (decoupled da
 * teacher KEK).
 *
 * Modello (parallelo a TeacherCryptoService ma scoped per classe):
 *   1. KMS_MASTER_KEY → HKDF (info=anno_scolastico|key_version,
 *      salt="pantedu-class-cek-v1|{ind}|{cls}") → CKEK 32B (in-memory).
 *   2. Class key random 32B → wrap con CKEK → classe_keys.wrapped_key.
 *   3. Body content encrypt con class_key direct (no DEK separato per
 *      coerenza con teacher path).
 *
 * Decoupled da TeacherCryptoService: cancellazione del teacher (Art. 17
 * GDPR shred) NON tocca classe_keys → studenti continuano a vedere
 * pubblicazioni precedenti finché class_key resta valida (ciclo annuale).
 *
 * Cambio anno scolastico → archivio: ALTER classe_keys SET archived_at = NOW()
 * sulle row dell'anno passato. Le wrapped_key restano per audit; il
 * published_content scaduto può essere DELETED batch.
 *
 * API:
 *   - getOrCreateActiveKey(string $ind, string $cls, string $anno): int classeKeyId
 *   - encrypt(int $classeKeyId, string $plain): array{ct,iv,tag,kv}
 *   - decrypt(int $classeKeyId, array $env): string
 *   - rotateKey(string $ind, string $cls, string $anno): int newKv
 *   - archiveYear(string $anno): int rows_archived
 */
final class ClasseKeyService
{
    private const HKDF_INFO_PREFIX = 'pantedu-class-cek-v1';
    private const CIPHER_ALGO      = 'aes-256-gcm';
    private const IV_LEN           = 12;
    private const TAG_LEN          = 16;
    private const KEY_LEN          = 32;

    private ?string $kmsMaster = null;

    public function __construct(?string $kmsMasterHex = null)
    {
        $hex = $kmsMasterHex
            ?? ($_ENV['KMS_MASTER_KEY'] ?? null)
            ?? (string)\App\Core\Config::get('crypto.kms_master_key', '');
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
     * Idempotent: ritorna l'id della classe_key attiva per (ind, cls, anno).
     * Crea se mancante. Race-safe via UNIQUE constraint + ON DUPLICATE KEY.
     */
    public function getOrCreateActiveKey(string $indirizzo, string $classe, string $anno): int
    {
        $this->requireKms();
        // CANONICALIZZA i codici: il DB li memorizza canonici (es. '__TEST__',
        // '1A') e la VIEW classe_keys li rilegge canonici in fase di unwrap. La
        // CKEK (HKDF salt = prefix|ind|cls, case-sensitive) DEVE usare la STESSA
        // forma sia in wrap (qui) sia in unwrap → altrimenti class_key_unwrap_failed.
        $indirizzo = \App\Support\CurriculumLookup::canonicalize('indirizzi', $indirizzo);
        $classe    = \App\Support\CurriculumLookup::canonicalize('classi', $classe);
        $db = Database::connection();

        // Lookup: solo non-archiviata.
        $stmt = $db->prepare(
            'SELECT id FROM classe_keys
             WHERE indirizzo=? AND classe=? AND anno_scolastico=? AND archived_at IS NULL
             ORDER BY key_version DESC LIMIT 1'
        );
        $stmt->execute([$indirizzo, $classe, $anno]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }

        // Nessuna active: calcola next key_version = max(esistenti) + 1
        // (esistenti includono archived per non collidere su UNIQUE).
        $maxStmt = $db->prepare(
            'SELECT COALESCE(MAX(key_version), 0) FROM classe_keys
             WHERE indirizzo=? AND classe=? AND anno_scolastico=?'
        );
        $maxStmt->execute([$indirizzo, $classe, $anno]);
        $nextKv = (int)$maxStmt->fetchColumn() + 1;

        $classKey = random_bytes(self::KEY_LEN);
        $ckek = $this->deriveCkek($indirizzo, $classe, $anno, $nextKv);
        $wrapped = $this->wrap($classKey, $ckek);

        // Fase D — solo FK ids (varchar dropped). idFromCode('indirizzi',$c,null)
        // usa il path "legacy globale" (institute_id IS NULL) ormai VUOTO post
        // migrazione per-istituto → tornava NULL → indirizzo_id NULL → chiavi
        // NON idempotenti (la VIEW classe_keys, JOIN su indirizzo_id, non
        // ritrovava la chiave per code). resolveCurriculumId risolve a un id
        // stabile (qualsiasi entry con quel code) → round-trip per code OK.
        $indId = $this->resolveCurriculumId('indirizzi', $indirizzo);
        $clsId = $this->resolveCurriculumId('classi', $classe);
        $ins = $db->prepare(
            'INSERT INTO classe_keys_data (indirizzo_id, classe_id, anno_scolastico, key_version, wrapped_key)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
        );
        $ins->execute([$indId, $clsId, $anno, $nextKv, $wrapped]);
        return (int)$db->lastInsertId();
    }

    /**
     * Risolve un codice curriculum (indirizzo/classe) a un id curriculum_entries
     * in modo CONSISTENTE (prima entry per code, canonicalizzato), indipendente
     * dall'istituto. Le classe_key sono per (indirizzo,classe,anno): serve solo
     * un id stabile per il round-trip via VIEW. NB: lo scoping per-istituto è una
     * raffinazione futura (oggi nessun caller in prod; published_content vuota).
     */
    private function resolveCurriculumId(string $kind, string $code): ?int
    {
        $canon = \App\Support\CurriculumLookup::canonicalize($kind, $code);
        if ($canon === '') {
            return null;
        }
        $st = Database::connection()->prepare(
            'SELECT id FROM curriculum_entries WHERE kind = ? AND code = ? ORDER BY id LIMIT 1'
        );
        $st->execute([$kind, $canon]);
        $id = $st->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /**
     * @return array{ciphertext: string, iv: string, tag: string, kv: int}
     */
    public function encrypt(int $classeKeyId, string $plaintext): array
    {
        $this->requireKms();
        $key = $this->loadAndUnwrapClassKey($classeKeyId);
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $ct = openssl_encrypt(
            $plaintext,
            self::CIPHER_ALGO,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );
        if ($ct === false) {
            throw new RuntimeException('classe_encrypt_failed');
        }
        return [
            'ciphertext' => $ct,
            'iv'         => $iv,
            'tag'        => $tag,
            'kv'         => $this->classKeyVersion($classeKeyId),
        ];
    }

    /** @param array{ciphertext: string, iv: string, tag: string, kv: int} $env */
    public function decrypt(int $classeKeyId, array $env): string
    {
        $this->requireKms();
        $key = $this->loadAndUnwrapClassKey($classeKeyId);
        $plain = openssl_decrypt(
            $env['ciphertext'],
            self::CIPHER_ALGO,
            $key,
            OPENSSL_RAW_DATA,
            $env['iv'],
            $env['tag']
        );
        if ($plain === false) {
            throw new RuntimeException('classe_decrypt_tag_mismatch');
        }
        return $plain;
    }

    /**
     * Rotation della classe_key: crea nuovo key_version per (ind, cls, anno).
     * I published_content esistenti NON vengono re-encrypted automatic; serve
     * un job batch separato (per non bloccare service).
     *
     * @return int next key_version
     */
    public function rotateKey(string $indirizzo, string $classe, string $anno): int
    {
        $this->requireKms();
        // Canonicalizza (vedi getOrCreateActiveKey): consistenza CKEK wrap/unwrap.
        $indirizzo = \App\Support\CurriculumLookup::canonicalize('indirizzi', $indirizzo);
        $classe    = \App\Support\CurriculumLookup::canonicalize('classi', $classe);
        $db = Database::connection();

        $maxKv = (int)$db->query(
            "SELECT MAX(key_version) FROM classe_keys
             WHERE indirizzo='" . addslashes($indirizzo) . "'
               AND classe='" . addslashes($classe) . "'
               AND anno_scolastico='" . addslashes($anno) . "'"
        )->fetchColumn();
        $next = $maxKv + 1;

        $key = random_bytes(self::KEY_LEN);
        $ckek = $this->deriveCkek($indirizzo, $classe, $anno, $next);
        $wrapped = $this->wrap($key, $ckek);

        // Fase D — solo FK ids (varchar dropped). Vedi resolveCurriculumId:
        // idFromCode(null) usa il path legacy-globale ormai vuoto → id stabile via resolve.
        $indId = $this->resolveCurriculumId('indirizzi', $indirizzo);
        $clsId = $this->resolveCurriculumId('classi', $classe);
        $ins = $db->prepare(
            'INSERT INTO classe_keys_data (indirizzo_id, classe_id, anno_scolastico, key_version, wrapped_key, rotated_at)
             VALUES (?,?,?,?,?,NOW())'
        );
        $ins->execute([$indId, $clsId, $anno, $next, $wrapped]);
        return $next;
    }

    /**
     * Archive end-of-year: marca tutte le classe_keys di un anno come archived.
     * Le row restano consultabili per audit + decrypt published_content
     * dell'anno (immutabili). Cleanup published_content via DELETE separato.
     *
     * @return int row marcate
     */
    public function archiveYear(string $anno): int
    {
        $stmt = Database::connection()->prepare(
            'UPDATE classe_keys_data SET archived_at=NOW()
             WHERE anno_scolastico=? AND archived_at IS NULL'
        );
        $stmt->execute([$anno]);
        return $stmt->rowCount();
    }

    private function requireKms(): void
    {
        if ($this->kmsMaster === null) {
            throw new RuntimeException('kms_not_configured: KMS_MASTER_KEY missing');
        }
    }

    private function deriveCkek(string $ind, string $cls, string $anno, int $kv): string
    {
        $salt = self::HKDF_INFO_PREFIX . '|' . $ind . '|' . $cls;
        $info = $anno . '|' . $kv;
        $ckek = hash_hkdf('sha256', $this->kmsMaster, 32, $info, $salt);
        if ($ckek === false || strlen($ckek) !== 32) {
            throw new RuntimeException('hkdf_failed');
        }
        return $ckek;
    }

    private function wrap(string $key, string $ckek): string
    {
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $ct = openssl_encrypt(
            $key,
            self::CIPHER_ALGO,
            $ckek,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );
        if ($ct === false) {
            throw new RuntimeException('class_key_wrap_failed');
        }
        return $iv . $ct . $tag;
    }

    private function loadAndUnwrapClassKey(int $classeKeyId): string
    {
        $stmt = Database::connection()->prepare(
            'SELECT indirizzo, classe, anno_scolastico, key_version, wrapped_key
             FROM classe_keys WHERE id=?'
        );
        $stmt->execute([$classeKeyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('classe_key_missing');
        }

        $expectedLen = self::IV_LEN + self::KEY_LEN + self::TAG_LEN;
        if (strlen($row['wrapped_key']) !== $expectedLen) {
            throw new RuntimeException('wrapped_key_corrupted');
        }
        $iv  = substr($row['wrapped_key'], 0, self::IV_LEN);
        $ct  = substr($row['wrapped_key'], self::IV_LEN, self::KEY_LEN);
        $tag = substr($row['wrapped_key'], self::IV_LEN + self::KEY_LEN, self::TAG_LEN);

        $ckek = $this->deriveCkek(
            $row['indirizzo'],
            $row['classe'],
            $row['anno_scolastico'],
            (int)$row['key_version']
        );
        $key = openssl_decrypt($ct, self::CIPHER_ALGO, $ckek, OPENSSL_RAW_DATA, $iv, $tag);
        if ($key === false) {
            throw new RuntimeException('class_key_unwrap_failed');
        }
        return $key;
    }

    private function classKeyVersion(int $classeKeyId): int
    {
        $stmt = Database::connection()->prepare('SELECT key_version FROM classe_keys WHERE id=?');
        $stmt->execute([$classeKeyId]);
        return (int)($stmt->fetchColumn() ?: 1);
    }
}
