<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export\Exporters;

use App\Core\Database;
use App\Repositories\TeacherContentRepository;
use App\Services\Gdpr\Export\ContentExporterInterface;
use App\Services\Gdpr\Export\ExportContext;
use App\Services\Gdpr\Export\ExportSection;
use App\Services\Maps\MapBlobStore;
use PDO;

/**
 * Phase 25.R.23 — Exporter contenuti docente (Art. 15/20 GDPR).
 *
 * Esporta tutti i `teacher_content` dell'utente:
 *   - mappe (XML drawio + PDF + PNG decifrati da map_blob_path)
 *   - esercizi (JSON plaintext)
 *   - lab (PT AST + risorse web component)
 *   - verifiche (delegate a VerificheExporter — qui solo metadata)
 *   - bes / didattica / risdoc (delegate a RisdocExporter)
 *
 * Note crypto: usa TeacherContentRepository per body decryption automatic
 * (envelope encryption con TKEK docente) + MapBlobStore per mappe.
 */
final class TeacherContentExporter implements ContentExporterInterface
{
    public function getKey(): string
    {
        return 'teacher_content';
    }
    public function getLabel(): string
    {
        return 'Contenuti docente (mappe, esercizi, lab)';
    }
    public function getCategory(): string
    {
        return 'content';
    }
    public function isAvailableForSelfService(): bool
    {
        return true;
    }
    public function isAvailableForAuthority(): bool
    {
        return true;
    }

    /**
     * Limite numerico safeguard: evita OOM per docenti con 100+ contenuti
     * (es. teacher_77 ha 303 contenuti ~139MB raw decifrati).
     * Override via $ctx->filters['max_per_type'].
     */
    private const DEFAULT_MAX_PER_TYPE = 50;

    public function export(ExportContext $ctx): ExportSection
    {
        $section = new ExportSection('teacher_content', 'content', $this->getLabel());

        $maxPerType = (int)($ctx->filters['max_per_type'] ?? self::DEFAULT_MAX_PER_TYPE);
        $maxPerType = max(1, min(500, $maxPerType));

        // Phase 25.R.23.2 — Apply date_from/date_to filters da $ctx->filters
        $dateFrom   = $ctx->filters['date_from']   ?? null;
        $dateTo     = $ctx->filters['date_to']     ?? null;
        // Phase 25.R.24 — content_ids: se specificati, override date+limit
        // (export mirato su singoli documenti, es. decreto su content_id=123)
        $contentIds = $ctx->filters['content_ids'] ?? [];
        if (!is_array($contentIds)) {
            $contentIds = [];
        }

        $repo = new TeacherContentRepository();
        // GDPR Art.15/20 — VISIBILITY GATE INTENZIONALMENTE NON APPLICATA QUI.
        // App\Domain\ContentVisibilityPolicy NON deve filtrare questo export: l'oggetto
        // dei dati ha diritto a TUTTI i propri contenuti, incluse le bozze (draft) e
        // gli archiviati. L'unico vincolo è l'ownership (teacher_id = ?), già nel WHERE.
        // NON aggiungere `visibility = 'published'` né chiamare la policy: violerebbe
        // il diritto di accesso. (Behavior-preserving by design — non un check dimenticato.)
        // Limit per content_type. Window function ROW_NUMBER() per partition by content_type → top N per tipo.
        // Date filter applicato PRIMA del ROW_NUMBER → "top N nel range" non "top N globale".
        $where = ["teacher_id = ?", "content_type IN ('mappa','esercizio','lab')"];
        $args  = [$ctx->userId];
        if (!empty($contentIds)) {
            // Override: ignora date filter quando contentIds specificati
            $placeholders = implode(',', array_fill(0, count($contentIds), '?'));
            $where[] = "id IN ($placeholders)";
            foreach ($contentIds as $cid) {
                $args[] = $cid;
            }
        } else {
            if ($dateFrom !== null) {
                $where[] = "created_at >= ?";
                $args[]  = $dateFrom . ' 00:00:00';
            }
            if ($dateTo !== null) {
                $where[] = "created_at <= ?";
                $args[]  = $dateTo . ' 23:59:59';
            }
        }
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT id, content_type, title, topic, subject_code, classe, indirizzo,
                       visibility, created_at, updated_at, map_blob_path, metadata_json
                FROM (
                    SELECT *, ROW_NUMBER() OVER (PARTITION BY content_type ORDER BY created_at DESC) AS rn
                    FROM teacher_content
                    WHERE {$whereSql}
                ) tc
                WHERE rn <= ?
                ORDER BY content_type, created_at DESC";
        $args[] = $maxPerType;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stats = ['mappa' => 0, 'esercizio' => 0, 'lab' => 0];
        $errors = [];
        $contractsExported = 0;
        $mapStore = $this->mapStore();
        // Phase 25.R.23.2 — root storage objects per risolvere contract_key
        $storageRoot = (string)\App\Core\Config::get('app.paths.storage', dirname(__DIR__, 5) . '/storage');
        $objectsRoot = $storageRoot . '/objects';

        foreach ($rows as $meta) {
            $type = (string)$meta['content_type'];
            $id   = (int)$meta['id'];

            // Body decifrato via repository
            try {
                $full = $repo->find($id);
            } catch (\Throwable $e) {
                $errors[] = "content_id={$id}: " . $e->getMessage();
                continue;
            }
            if (!$full) {
                continue;
            }

            // Strip campi crypto-internal
            foreach (
                ['body_html_ct','body_html_iv','body_html_tag','body_html_kv',
                      'body_pt_ct','body_pt_iv','body_pt_tag','body_pt_kv',
                      'metadata_ct','metadata_iv','metadata_tag','metadata_kv',
                      '_crypto_error'] as $k
            ) {
                unset($full[$k]);
            }

            $folder = $type === 'mappa' ? 'mappe' : ($type === 'lab' ? 'lab' : 'esercizi');
            $section->addJsonFile("$folder/{$id}_metadata.json", $full);

            // Phase 25.R.23.2 — Q4: risolvi metadata.contract_key → carica file
            // plaintext da storage/objects/ (esercizi legacy + lab hanno il VERO
            // contenuto in .contract.json referenziato dal metadata).
            $contractKey = null;
            $meta = $full['metadata'] ?? null;
            if (is_array($meta)) {
                $contractKey = (string)($meta['contract_key'] ?? '');
            }
            if ($contractKey === '' && !empty($full['metadata_json'])) {
                $decoded = json_decode((string)$full['metadata_json'], true);
                if (is_array($decoded)) {
                    $contractKey = (string)($decoded['contract_key'] ?? '');
                }
            }
            if ($contractKey !== '') {
                $contractPath = $objectsRoot . '/' . ltrim($contractKey, '/');
                if (is_file($contractPath)) {
                    try {
                        $body = (string)@file_get_contents($contractPath);
                        $ext  = pathinfo($contractKey, PATHINFO_EXTENSION) ?: 'json';
                        $base = basename($contractKey);
                        $section->addFile(
                            "$folder/{$id}_{$base}",
                            $body,
                            $ext === 'json' ? 'application/json' : 'application/octet-stream'
                        );
                        $contractsExported++;
                    } catch (\Throwable $e) {
                        $errors[] = "contract {$id} [{$contractKey}]: " . $e->getMessage();
                    }
                } else {
                    $errors[] = "contract {$id}: file not found {$contractKey}";
                }
            }

            // Per mappe: decifra anche il blob (drawio/PDF/PNG)
            if ($type === 'mappa' && !empty($meta['map_blob_path']) && $mapStore !== null) {
                try {
                    $blobPath = (string)$meta['map_blob_path'];
                    $plain = $mapStore->get($ctx->userId, $blobPath);
                    // map_blob_path è del tipo "77/01KRX.bin" — usiamo ulid + estensione mime
                    $ulid = basename($blobPath, '.bin');
                    $mime = (string)($meta['map_mime'] ?? 'application/xml');
                    $extFromMime = match (true) {
                        str_contains($mime, 'pdf')   => 'pdf',
                        str_contains($mime, 'png')   => 'png',
                        str_contains($mime, 'jpeg')  => 'jpg',
                        str_contains($mime, 'xml'),
                        str_contains($mime, 'drawio') => 'drawio',
                        default                       => 'bin',
                    };
                    $section->addFile("mappe/{$id}_{$ulid}.{$extFromMime}", $plain, $mime);
                } catch (\Throwable $e) {
                    $errors[] = "map_blob {$id}: " . $e->getMessage();
                }
            }

            $stats[$type] = ($stats[$type] ?? 0) + 1;
        }

        // Conta totale records esistenti per warning truncation
        $totalRows = 0;
        try {
            $countStmt = Database::connection()->prepare(
                "SELECT COUNT(*) FROM teacher_content
                 WHERE teacher_id = ? AND content_type IN ('mappa','esercizio','lab')"
            );
            $countStmt->execute([$ctx->userId]);
            $totalRows = (int)$countStmt->fetchColumn();
        } catch (\Throwable) {
        }

        $summary = [
            'mappe_count'        => $stats['mappa'],
            'esercizi_count'     => $stats['esercizio'],
            'lab_count'          => $stats['lab'],
            'contracts_exported' => $contractsExported,
            'max_per_type'       => $maxPerType,
            'date_from'          => $dateFrom,
            'date_to'            => $dateTo,
            'errors'             => $errors,
        ];
        if ($totalRows > count($rows)) {
            $summary['_truncated'] = true;
            $summary['total_in_db'] = $totalRows;
            $summary['exported']    = count($rows);
            $summary['note'] = "Export limitato a {$maxPerType} contenuti per tipo (totale DB: {$totalRows}). "
                             . "Per export completo: usa filters['max_per_type'] o ridurre scope.";
        }
        $section->setSummary($summary);

        return $section;
    }

    private function mapStore(): ?MapBlobStore
    {
        try {
            // MapBlobStore signature: ($crypto = null, $rootDir = null)
            // Lasciamo entrambi null → Config picks storage path automaticamente.
            return new MapBlobStore();
        } catch (\Throwable) {
            return null;
        }
    }
}
