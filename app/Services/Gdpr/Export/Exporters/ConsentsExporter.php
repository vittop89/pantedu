<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export\Exporters;

use App\Core\Database;
use App\Services\Gdpr\Export\ContentExporterInterface;
use App\Services\Gdpr\Export\ExportContext;
use App\Services\Gdpr\Export\ExportSection;
use PDO;

/**
 * Phase 25.R.23 — Exporter consensi GDPR (Art. 7).
 *
 * Include:
 *   - consents: tutti i consensi attivi/revocati + storico (audit immutabile)
 *   - parent_consents: per minori <14 anni (Art. 8) se applicabile
 *   - tos_acceptance: log accettazione ToS docente
 */
final class ConsentsExporter implements ContentExporterInterface
{
    public function getKey(): string
    {
        return 'consents';
    }
    public function getLabel(): string
    {
        return 'Consensi GDPR';
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
        $section = new ExportSection('consents', 'profile', $this->getLabel());
        $db = Database::connection();

        // Consensi
        $consents = [];
        try {
            $stmt = $db->prepare(
                'SELECT id, consent_type, granted, granted_at, revoked_at,
                        text_version, ip_address, user_agent
                 FROM consents WHERE user_id = ? ORDER BY granted_at DESC'
            );
            $stmt->execute([$ctx->userId]);
            $consents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
        }

        // Consensi genitori (per minori)
        $parentConsents = [];
        try {
            $stmt = $db->prepare(
                'SELECT id, parent_email, status, confirmed_at, revoked_at, created_at
                 FROM parent_consents WHERE student_user_id = ?'
            );
            $stmt->execute([$ctx->userId]);
            $parentConsents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
        }

        // ToS acceptance
        $tos = [];
        try {
            $stmt = $db->prepare(
                'SELECT version, accepted_at, ip_address
                 FROM tos_acceptances WHERE user_id = ? ORDER BY accepted_at DESC'
            );
            $stmt->execute([$ctx->userId]);
            $tos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
        }

        $section->addJsonFile('consents.json', [
            'consents'         => $consents,
            'parent_consents'  => $parentConsents,
            'tos_acceptances'  => $tos,
        ]);

        $section->setSummary([
            'consents_count'         => count($consents),
            'parent_consents_count'  => count($parentConsents),
            'tos_acceptances_count'  => count($tos),
        ]);

        return $section;
    }
}
