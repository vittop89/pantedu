<?php

declare(strict_types=1);

namespace App\Services\Maps;

use App\Core\Config;
use App\Services\Crypto\TeacherCryptoService;
use App\Support\Ulid;
use RuntimeException;

/**
 * Phase G2 — Storage per blob mappe (drawio XML, PDF, PNG, HTML) cifrati
 * envelope con TKEK del docente proprietario.
 *
 * Layout su disk:
 *   storage/maps_enc/{teacher_id}/{ulid}.bin
 *
 * Layout binario del file:
 *   [12B IV] [16B GCM tag] [N B ciphertext]
 *
 * AES-256-GCM via TeacherCryptoService::encrypt; il file finale e' ricco
 * di tutti i metadati crypto (no necessita' di tabella separata per
 * iv/tag, restano embedded nel payload). Identificatore (ulid) generato
 * lato repository chiamante e persisted in teacher_content.map_blob_path.
 *
 * NB: la chiave (TKEK) NON e' nel file: solo IV+tag+ciphertext. La KEK
 * vive in teacher_keys.wrapped_kek (DB) e si recupera via TeacherCryptoService.
 * Crypto-shredding del docente (DELETE teacher_keys row) rende
 * IMMEDIATAMENTE illeggibili tutti i blob senza dover toccare il
 * filesystem (Art. 17 GDPR efficiente).
 *
 * Path traversal protection: il caller non sceglie mai il path completo,
 * solo (teacher_id, ulid) → il filesystem layout e' noto e fissato. ULID
 * e' [0-9A-Z]{26} → impossibile escapare con ../ tramite ulid.
 */
final class MapBlobStore
{
    private const KV_LEN  = 2;   // bytes (key_version, packed unsigned short BE)
    private const IV_LEN  = 12;
    private const TAG_LEN = 16;

    private TeacherCryptoService $crypto;
    private string $rootDir;

    public function __construct(
        ?TeacherCryptoService $crypto = null,
        ?string $rootDir = null
    ) {
        $this->crypto = $crypto ?? new TeacherCryptoService();
        $this->rootDir = $rootDir ?? (
            (string)Config::get('app.paths.storage', dirname(__DIR__, 3) . '/storage')
            . '/maps_enc'
        );
    }

    /**
     * Salva un blob plaintext per il teacher dato. Genera ULID nuovo (o
     * usa quello passato — utile per overwrite su edit). Restituisce il
     * path relativo a storage/maps_enc/, da persistere in
     * teacher_content.map_blob_path.
     *
     * @return string es. "77/01HZ6X2K4YQ8N3FKMNH9V0PJTC.bin"
     */
    public function put(int $teacherId, string $plaintext, ?string $ulid = null): string
    {
        if (!$this->crypto->isConfigured()) {
            throw new RuntimeException('map_blob_kms_not_configured');
        }

        $ulid    ??= Ulid::generate();
        $envelope = $this->crypto->encrypt($teacherId, $plaintext);

        // Layout binario: kv(2B BE) || iv(12B) || tag(16B) || ct(N B).
        // Il key_version e' embedded perche' una rotation futura (G6+/Phase 25.D5)
        // potrebbe usare KEK diverse per blob diversi dello stesso docente.
        $packed = pack('n', (int)$envelope['kv'])
                . $envelope['iv']
                . $envelope['tag']
                . $envelope['ciphertext'];

        $relPath = $teacherId . '/' . $ulid . '.bin';
        $absPath = $this->rootDir . '/' . $relPath;

        $dir = dirname($absPath);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('map_blob_mkdir_failed');
        }

        // Atomic write: scrivi temp + rename. Evita lettori che leggono
        // un file mezzo-scritto durante un write concorrente.
        $tmp = $absPath . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $packed, LOCK_EX) === false) {
            throw new RuntimeException('map_blob_write_failed');
        }
        if (!@rename($tmp, $absPath)) {
            @unlink($tmp);
            throw new RuntimeException('map_blob_rename_failed');
        }

        return $relPath;
    }

    /**
     * Recupera + decifra un blob. teacherId DEVE essere l'owner (chi
     * detiene la TKEK). Per cross-teacher access (share copy) il caller
     * passa l'owner_id, NON il viewer_id.
     */
    public function get(int $ownerTeacherId, string $relPath): string
    {
        $absPath = $this->rootDir . '/' . $relPath;
        $this->guardPath($relPath);

        if (!is_file($absPath)) {
            throw new RuntimeException('map_blob_not_found');
        }

        $raw = @file_get_contents($absPath);
        if ($raw === false) {
            throw new RuntimeException('map_blob_read_failed');
        }
        if (strlen($raw) < self::KV_LEN + self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('map_blob_truncated');
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

    /** Dimensione cifrata (utile per debug + space monitoring). */
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
            throw new RuntimeException('map_blob_invalid_path');
        }
    }
}
