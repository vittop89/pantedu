<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export\Exporters;

use App\Core\Database;
use App\Services\Gdpr\Export\ContentExporterInterface;
use App\Services\Gdpr\Export\ExportContext;
use App\Services\Gdpr\Export\ExportSection;
use PDO;

/**
 * Phase 25.R.23 — Exporter contenuti pubblicati (con orphan recovery).
 *
 * `published_content` è cifrata con `classe_key` (NON teacher_key) per
 * sopravvivere allo shred del docente. Esportiamo metadati pubblicazione
 * + (se classe_key disponibile) il contenuto decifrato.
 *
 * Casi d'uso:
 *   - docente attivo: vede i propri contenuti pubblicati alle classi
 *   - docente cancellato (orphan): teacher_id=NULL ma source_id punta
 *     ancora alla sua opera; admin authority può recuperare con classe_key
 */
final class PublishedContentExporter implements ContentExporterInterface
{
    public function getKey(): string
    {
        return 'published_content';
    }
    public function getLabel(): string
    {
        return 'Contenuti pubblicati alle classi';
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
        $section = new ExportSection('published_content', 'content/published', $this->getLabel());

        // Query semplice senza JOIN (la tabella `classes` non esiste su tutti i deploy).
        // Orphan recovery: copre i casi teacher_id=NULL post-shred.
        try {
            $stmt = Database::connection()->prepare(
                'SELECT id, source_id, content_type, teacher_id,
                        classe_key_id, subject_code, title, topic, published_at,
                        expires_at, revoked_at, body_kv,
                        LENGTH(body_ct) AS body_size
                 FROM published_content
                 WHERE teacher_id = ?
                    OR (teacher_id IS NULL AND source_id IN (
                        SELECT id FROM teacher_content WHERE teacher_id = ?
                    ))
                 ORDER BY published_at DESC'
            );
            $stmt->execute([$ctx->userId, $ctx->userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            // Tabella published_content potrebbe non esistere → graceful fallback
            $section->summary = [
                'count' => 0,
                'note'  => 'published_content table missing or schema differs: ' . $e->getMessage(),
            ];
            return $section;
        }

        $section->addJsonFile('published.json', [
            'note'         => 'Cifrato con classe_key (non teacher_key). Decryption '
                            . 'richiede chiave anno scolastico. Vedi docs/security/operations/authority-cooperation.md §3.4.',
            'publications' => $rows,
        ]);
        $section->setSummary([
            'count'         => count($rows),
            'orphan_count'  => count(array_filter($rows, static fn($r) => $r['teacher_id'] === null)),
        ]);
        return $section;
    }
}
