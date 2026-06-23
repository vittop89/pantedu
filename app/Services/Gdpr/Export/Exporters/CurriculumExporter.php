<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export\Exporters;

use App\Core\Database;
use App\Services\Gdpr\Export\ContentExporterInterface;
use App\Services\Gdpr\Export\ExportContext;
use App\Services\Gdpr\Export\ExportSection;
use PDO;

/**
 * Phase 25.R.23 — Exporter curriculum docente (Art. 15 GDPR).
 *
 * Esporta indirizzi, classi, materie a cui il docente insegna
 * dalla tabella pivot curriculum_users (se esistente).
 */
final class CurriculumExporter implements ContentExporterInterface
{
    public function getKey(): string
    {
        return 'curriculum';
    }
    public function getLabel(): string
    {
        return 'Curriculum (materie, classi, indirizzi)';
    }
    public function getCategory(): string
    {
        return 'profile';
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
        $section = new ExportSection('curriculum', 'profile', $this->getLabel());

        $db = Database::connection();
        $entries = [];

        // Tabelle plausibili — proviamo in ordine di fallback
        $queries = [
            // Tabella pivot moderna
            ['SELECT cu.kind, cu.code, cu.label, cu.created_at
              FROM curriculum_users cu WHERE cu.user_id = ?'],
            // Schema legacy
            ['SELECT ce.kind, ce.code, ce.label
              FROM curriculum_entries ce
              WHERE ce.user_id = ?'],
        ];

        foreach ($queries as [$sql]) {
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute([$ctx->userId]);
                $entries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!empty($entries)) {
                    break;
                }
            } catch (\Throwable) {
                // Tabella non esiste — prova prossimo fallback
                continue;
            }
        }

        $section->addJsonFile('curriculum.json', [
            'user_id' => $ctx->userId,
            'entries' => $entries,
        ]);
        $section->setSummary(['count' => count($entries)]);
        return $section;
    }
}
