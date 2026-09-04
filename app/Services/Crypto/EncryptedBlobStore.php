<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Core\Config;
use App\Support\Ulid;
use RuntimeException;

/**
 * Phase G8 — Storage generico per blob cifrati envelope (AES-256-GCM)
 * con TKEK del teacher proprietario.
 *
 * Generalizzazione di MapBlobStore (Phase G2) per riusare lo stesso
 * pattern fra mappe (storage/maps_enc/), verifiche TEX/PDF
 * (storage/verifiche_enc/), e futuri artefatti (PT exports, ecc).
 *
 * Layout su disk:
 *   {rootDir}/{teacher_id}/{ulid}.bin
 *
 * Layout binario del file:
 *   [2B kv (BE unsigned short)] [12B IV] [16B GCM tag] [N B ciphertext]
 *
 * Crypto-shredding O(1):
 *   La chiave (TKEK) NON e' nel file: solo IV+tag+ciphertext+kv.
 *   La KEK vive in teacher_keys.wrapped_kek; cancellando la riga del
 *   teacher tutti i blob diventano illeggibili senza toccare il
 *   filesystem (Art. 17 GDPR efficiente).
 *
 * Path traversal protection:
 *   Il caller non sceglie mai il path completo, solo (teacher_id, ulid).
 *   ULID e' [0-9A-Z]{26} → impossibile escapare con ../.
 *
 * Uso tipico:
 *   $store = new EncryptedBlobStore('verifiche_enc');
 *   $rel = $store->put($teacherId, $texContent);
 *   $tex = $store->get($teacherId, $rel);
 */
class EncryptedBlobStore
{
    private const KV_LEN  = 2;   // bytes (key_version, packed unsigned short BE)
    private const IV_LEN  = 12;
    private const TAG_LEN = 16;

    private TeacherCryptoService $crypto;
    private string $rootDir;

    /**
     * @param string $namespace Subdir relativa a storage/ (es. 'maps_enc',
     *                          'verifiche_enc'). NON deve contenere '/'.
     */
    public function __construct(
        string $namespace,
        ?TeacherCryptoService $crypto = null,
        ?string $rootDirOverride = null
    ) {
        if ($namespace === '' || strpos($namespace, '/') !== false || strpos($namespace, '..') !== false) {
            throw new RuntimeException('blob_store_invalid_namespace');
        }
        $this->crypto  = $crypto ?? new TeacherCryptoService();
        $this->rootDir = $rootDirOverride ?? (
            (string)Config::get('app.paths.storage', dirname(__DIR__, 3) . '/storage')
            . '/' . $namespace
        );
    }

    /**
     * Salva un blob plaintext per il teacher dato. Genera ULID nuovo (o
     * usa quello passato per overwrite). Restituisce il path relativo a
     * {rootDir}/, da persistere nel record DB.
     *
     * @return string es. "77/01HZ6X2K4YQ8N3FKMNH9V0PJTC.bin"
     */
    public function put(int $teacherId, string $plaintext, ?string $ulid = null): string
    {
        if (!$this->crypto->isConfigured()) {
            throw new RuntimeException('blob_store_kms_not_configured');
        }

        $ulid    ??= Ulid::generate();
        $envelope = $this->crypto->encrypt($teacherId, $plaintext);

        $packed = pack('n', (int)$envelope['kv'])
                . $envelope['iv']
                . $envelope['tag']
                . $envelope['ciphertext'];

        $relPath = $teacherId . '/' . $ulid . '.bin';
        $absPath = $this->rootDir . '/' . $relPath;

        $dir = dirname($absPath);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('blob_store_mkdir_failed');
        }

        $tmp = $absPath . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $packed, LOCK_EX) === false) {
            throw new RuntimeException('blob_store_write_failed');
        }
        if (!@rename($tmp, $absPath)) {
            @unlink($tmp);
            throw new RuntimeException('blob_store_rename_failed');
        }

        return $relPath;
    }

    /**
     * Recupera + decifra un blob. teacherId DEVE essere l'owner (chi
     * detiene la TKEK). Per cross-teacher access il caller passa l'owner_id,
     * NON il viewer_id.
     */
    public function get(int $ownerTeacherId, string $relPath): string
    {
        $this->guardPath($relPath);
        $absPath = $this->rootDir . '/' . $relPath;

        if (!is_file($absPath)) {
            throw new RuntimeException('blob_store_not_found');
        }

        $raw = @file_get_contents($absPath);
        if ($raw === false) {
            throw new RuntimeException('blob_store_read_failed');
        }
        if (strlen($raw) < self::KV_LEN + self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('blob_store_truncated');
        }

        $kv  = unpack('n', substr($raw, 0, self::KV_LEN))[1];
        $iv  = substr($raw, self::KV_LEN, self::IV_LEN);
        $tag = substr($raw, self::KV_LEN + self::IV_LEN, self::TAG_LEN);
        $ct  = substr($raw, self::KV_LEN + self::IV_LEN + self::TAG_LEN);

        return $this->crypto->decrypt($ownerTeacherId, [
            'ciphertext' => $ct,
            'iv'         => $iv,
            'tag'        => $tag,
            'kv'         => (int)$kv,
        ]);
    }

    /** Recupera la kv embedded senza decifrare (audit/rotation). */
    public function readKv(string $relPath): int
    {
        $this->guardPath($relPath);
        $absPath = $this->rootDir . '/' . $relPath;
        $fh = @fopen($absPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('blob_store_read_failed');
        }
        $kvBytes = fread($fh, self::KV_LEN);
        fclose($fh);
        if ($kvBytes === false || strlen($kvBytes) !== self::KV_LEN) {
            throw new RuntimeException('blob_store_truncated');
        }
        return (int)unpack('n', $kvBytes)[1];
    }

    public function exists(string $relPath): bool
    {
        $this->guardPath($relPath);
        return is_file($this->rootDir . '/' . $relPath);
    }

    public function delete(string $relPath): void
    {
        $this->guardPath($relPath);
        @unlink($this->rootDir . '/' . $relPath);
    }

    /** Dimensione del ciphertext sul disco (debug + space monitoring). */
    public function ciphertextSize(string $relPath): int
    {
        $this->guardPath($relPath);
        $sz = @filesize($this->rootDir . '/' . $relPath);
        return $sz === false ? 0 : (int)$sz;
    }

    /**
     * Path traversal guard. Format atteso: "{int}/{ULID}.bin"
     *   - teacher_id numerico (no ../)
     *   - ULID 26 char [0-9A-Z]
     */
    private function guardPath(string $relPath): void
    {
        if (!preg_match('#^\d+/[0-9A-Z]{26}\.bin$#', $relPath)) {
            throw new RuntimeException('blob_store_invalid_path');
        }
    }
}
