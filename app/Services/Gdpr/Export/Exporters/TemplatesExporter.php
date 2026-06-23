<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export\Exporters;

use App\Core\Database;
use App\Services\Gdpr\Export\ContentExporterInterface;
use App\Services\Gdpr\Export\ExportContext;
use App\Services\Gdpr\Export\ExportSection;
use PDO;

/**
 * Phase 25.R.23 — Exporter template verifiche (Art. 15 GDPR).
 *
 * Esporta `verifica_templates` (4 sezioni TEX per docente:
 * intestazione, griglia voti, criteri valutazione, footer).
 *
 * Plaintext nel DB (no crypto) — questi template sono modelli editabili
 * con macro LaTeX, non contenuti sensibili.
 */
final class TemplatesExporter implements ContentExporterInterface
{
    public function getKey(): string
    {
        return 'templates';
    }
    public function getLabel(): string
    {
        return 'Template LaTeX verifiche';
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
        $section = new ExportSection('templates', 'content/templates', $this->getLabel());

        try {
            $stmt = Database::connection()->prepare(
                'SELECT id, name, intestazione, griglia_voti, criteri, footer,
                        is_default, created_at, updated_at
                 FROM verifica_templates
                 WHERE teacher_id = ?
                 ORDER BY is_default DESC, name ASC'
            );
            $stmt->execute([$ctx->userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            // Tabella non esiste su questo deploy (migration 021 non eseguita).
            // Skip silently: section vuota + nota in summary.
            $section->addJsonFile('templates.json', []);
            $section->setSummary([
                'count' => 0,
                'note'  => 'verifica_templates table missing on this deployment (migration 021 pending)',
            ]);
            return $section;
        }

        $section->addJsonFile('templates.json', $rows);

        // Phase 25.R.23.2 — include anche risdoc_templates creati/forkati dal docente
        $risdocTemplates = [];
        try {
            $stmt = Database::connection()->prepare(
                'SELECT id, slug, label, owner_user_id, visibility, version,
                        created_at, updated_at
                 FROM risdoc_templates
                 WHERE owner_user_id = ?'
            );
            $stmt->execute([$ctx->userId]);
            $risdocTemplates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            // tabella risdoc_templates schema variabile su deploy
        }
        if ($risdocTemplates) {
            $section->addJsonFile('risdoc_templates.json', $risdocTemplates);
        }

        $section->setSummary([
            'verifica_templates_count' => count($rows),
            'risdoc_templates_count'   => count($risdocTemplates),
        ]);
        return $section;
    }
}
