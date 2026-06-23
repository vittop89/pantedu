<?php

declare(strict_types=1);

namespace App\Services\PdfImport\Session;

use App\Services\Crypto\TeacherCryptoService;
use App\Support\Storage\StorageFactory;
use App\Support\Storage\StorageProvider;

/**
 * Phase PDF-Import — I/O storage di una sessione, CIFRATO per-docente a riposo.
 *
 * Layout (sotto lo storage provider di default, es. /var/lib/pantedu-data):
 *   institutes/{iid}/private/{tid}/pdf-import/{sessionId}/
 *       source.pdf          (PDF originale — materiale potenzialmente coperto da
 *                            diritto d'autore → cifrato + cancellato dopo l'insert)
 *       page-{n}.png        immagini pagina (n 1-based)
 *       raw.json            [{page, items:[...]}]
 *       contracts.json      [{group...}]  (output ContractMapper)
 *
 * Sicurezza:
 *   - Ogni blob è cifrato con l'envelope crypto del docente (TeacherCryptoService,
 *     AES-256-GCM, KEK per-teacher) — stesso schema di verifica_documents. Il
 *     contenuto del file è la busta JSON {v,ct,iv,tag,kv} (binari in base64).
 *   - Fail-closed in prod: se KMS è configurato e la cifratura fallisce → eccezione.
 *     Solo in dev senza KMS si ripiega al plaintext (decBlob legge entrambi).
 *   - I file vivono in path privato per-docente, fuori webroot; accesso owner-gated.
 *   - Retention: deleteSession() rimuove TUTTI i file (post-insert / purge).
 */
final class SessionStorage
{
    private readonly StorageProvider $store;
    private readonly TeacherCryptoService $crypto;

    public function __construct(?StorageProvider $store = null, ?TeacherCryptoService $crypto = null)
    {
        $this->store = $store ?? StorageFactory::default();
        $this->crypto = $crypto ?? new TeacherCryptoService();
    }

    public static function default(): self
    {
        return new self();
    }

    public static function prefixFor(int $instituteId, int $teacherId, int $sessionId): string
    {
        return sprintf('institutes/%d/private/%d/pdf-import/%d', $instituteId, $teacherId, $sessionId);
    }

    // ── blob cifrati ─────────────────────────────────────────────────────────

    public function putSourcePdf(string $prefix, string $bytes, int $teacherId): void
    {
        $this->store->put($prefix . '/source.pdf', $this->encBlob($teacherId, $bytes), 'application/octet-stream');
    }

    public function putPagePng(string $prefix, int $page, string $bytes, int $teacherId): void
    {
        $this->store->put($prefix . '/page-' . $page . '.png', $this->encBlob($teacherId, $bytes), 'application/octet-stream');
    }

    public function getPagePng(string $prefix, int $page, int $teacherId): string
    {
        return $this->decBlob($teacherId, $this->store->get($prefix . '/page-' . $page . '.png'));
    }

    public function pagePngExists(string $prefix, int $page): bool
    {
        return $this->store->exists($prefix . '/page-' . $page . '.png');
    }

    /** @param array<int|string,mixed> $data */
    public function putJson(string $prefix, string $name, array $data, int $teacherId): void
    {
        $json = (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->store->put($prefix . '/' . $name, $this->encBlob($teacherId, $json), 'application/octet-stream');
    }

    /** @return array<mixed>|null */
    public function getJson(string $prefix, string $name, int $teacherId): ?array
    {
        if (!$this->store->exists($prefix . '/' . $name)) {
            return null;
        }
        $plain = $this->decBlob($teacherId, $this->store->get($prefix . '/' . $name));
        $decoded = json_decode($plain, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Cancella TUTTI i file della sessione (retention / post-insert). */
    public function deleteSession(string $prefix): int
    {
        $n = 0;
        foreach ($this->store->listPrefix($prefix, 1000) as $key) {
            if ($this->store->delete($key)) {
                $n++;
            }
        }
        return $n;
    }

    /** Cancella i soli artefatti pesanti/sensibili (PDF + pagine), tiene i JSON. */
    public function deleteHeavyArtifacts(string $prefix, int $pageCount): int
    {
        $n = 0;
        if ($this->store->delete($prefix . '/source.pdf')) $n++;
        for ($p = 1; $p <= $pageCount; $p++) {
            if ($this->store->delete($prefix . '/page-' . $p . '.png')) $n++;
        }
        return $n;
    }

    // ── cifratura busta ──────────────────────────────────────────────────────

    private function encBlob(int $teacherId, string $bytes): string
    {
        try {
            $env = $this->crypto->encrypt($teacherId, $bytes);
            return (string)json_encode([
                'v'   => 1,
                'ct'  => base64_encode($env['ciphertext']),
                'iv'  => base64_encode($env['iv']),
                'tag' => base64_encode($env['tag']),
                'kv'  => (int)$env['kv'],
            ]);
        } catch (\Throwable $e) {
            // Prod (KMS configurato) → fail-closed: non salvare mai in chiaro.
            if (($_ENV['KMS_MASTER_KEY'] ?? '') !== '') {
                throw $e;
            }
            // Dev senza KMS → fallback plaintext (decBlob lo rilegge trasparente).
            return $bytes;
        }
    }

    private function decBlob(int $teacherId, string $stored): string
    {
        $j = json_decode($stored, true);
        if (!is_array($j) || ($j['v'] ?? null) !== 1 || !isset($j['ct'])) {
            // Non è una busta → file in chiaro (sessione pre-cifratura / dev).
            return $stored;
        }
        return $this->crypto->decrypt($teacherId, [
            'ciphertext' => base64_decode((string)$j['ct']),
            'iv'         => base64_decode((string)$j['iv']),
            'tag'        => base64_decode((string)$j['tag']),
            'kv'         => (int)$j['kv'],
        ]);
    }
}
