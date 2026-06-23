<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export\Exporters;

use App\Core\Database;
use App\Services\Crypto\EncryptedBlobStore;
use App\Services\Gdpr\Export\ContentExporterInterface;
use App\Services\Gdpr\Export\ExportContext;
use App\Services\Gdpr\Export\ExportSection;
use PDO;

/**
 * Phase 25.R.23 — Exporter verifiche scolastiche (Art. 15/20 GDPR).
 *
 * Esporta da `verifica_documents` per il docente:
 *   - TEX sorgente decifrato (storage/verifiche_enc/{tid}/{ulid}.bin)
 *   - PDF compilato decifrato (se presente)
 *   - metadata JSON (title, materia, exercise_ids, template applicato)
 *
 * Crypto: envelope encryption AES-256-GCM via EncryptedBlobStore('verifiche_enc').
 */
final class VerificheExporter implements ContentExporterInterface
{
    public function getKey(): string
    {
        return 'verifiche';
    }
    public function getLabel(): string
    {
        return 'Verifiche scolastiche';
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

    public function export(ExportContext $ctx): ExportSection
    {
        $section = new ExportSection('verifiche', 'content/verifiche', $this->getLabel());

        try {
            $stmt = Database::connection()->prepare(
                'SELECT id, materia, indirizzo, classe, title, fm_db_section,
                        exercise_ids, version_label, variant,
                        tex_blob_path, tex_size, tex_sha256,
                        pdf_blob_path, pdf_size, pdf_filename, pdf_uploaded_at,
                        created_at, updated_at
                 FROM verifica_documents
                 WHERE teacher_id = ?
                 ORDER BY created_at DESC'
            );
            $stmt->execute([$ctx->userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $section->summary['_error'] = 'query_failed: ' . $e->getMessage();
            return $section;
        }

        if (empty($rows)) {
            $section->setSummary(['count' => 0]);
            return $section;
        }

        $store = new EncryptedBlobStore('verifiche_enc');
        $stats = ['tex_decrypted' => 0, 'pdf_decrypted' => 0, 'errors' => []];

        foreach ($rows as $r) {
            $id = (int)$r['id'];

            // Metadata JSON sempre
            $section->addJsonFile("{$id}_metadata.json", $r);

            // TEX
            if (!empty($r['tex_blob_path'])) {
                try {
                    $tex = $store->get($ctx->userId, (string)$r['tex_blob_path']);
                    $section->addFile("{$id}.tex", $tex, 'application/x-tex');
                    $stats['tex_decrypted']++;
                } catch (\Throwable $e) {
                    $stats['errors'][] = "tex {$id}: " . $e->getMessage();
                }
            }

            // PDF (opzionale)
            if (!empty($r['pdf_blob_path'])) {
                try {
                    $pdf = $store->get($ctx->userId, (string)$r['pdf_blob_path']);
                    $section->addFile("{$id}.pdf", $pdf, 'application/pdf');
                    $stats['pdf_decrypted']++;
                } catch (\Throwable $e) {
                    $stats['errors'][] = "pdf {$id}: " . $e->getMessage();
                }
            }
        }

        $section->setSummary([
            'count'         => count($rows),
            'tex_decrypted' => $stats['tex_decrypted'],
            'pdf_decrypted' => $stats['pdf_decrypted'],
            'errors'        => $stats['errors'],
        ]);

        return $section;
    }
}
