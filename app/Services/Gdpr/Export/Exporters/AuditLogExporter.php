<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export\Exporters;

use App\Core\Database;
use App\Services\Gdpr\Export\ContentExporterInterface;
use App\Services\Gdpr\Export\ExportContext;
use App\Services\Gdpr\Export\ExportSection;
use PDO;

/**
 * Phase 25.R.23 — Exporter audit log (Art. 6(1)(c) GDPR).
 *
 * Disponibile SOLO per export authority/admin: include log accessi
 * privilegiati + crypto access log per investigation forensics.
 * Skip in self-service (l'utente non ha bisogno del proprio log di accesso).
 */
final class AuditLogExporter implements ContentExporterInterface
{
    public function getKey(): string
    {
        return 'audit_log';
    }
    public function getLabel(): string
    {
        return 'Audit log (accessi + crypto)';
    }
    public function getCategory(): string
    {
        return 'meta';
    }
    public function isAvailableForSelfService(): bool
    {
        return false; // NON in self-service
    }
    public function isAvailableForAuthority(): bool
    {
        return true;
    }

    public function export(ExportContext $ctx): ExportSection
    {
        $section = new ExportSection('audit_log', 'audit', $this->getLabel());
        $db = Database::connection();

        // Privileged access (accessi admin a dati di questo user)
        $privAccess = [];
        try {
            $stmt = $db->prepare(
                'SELECT id, accessed_at, actor_user_id, action, resource_type,
                        resource_id, reason, outcome
                 FROM privileged_access_log
                 WHERE resource_type = "user" AND resource_id = ?
                 ORDER BY accessed_at DESC LIMIT 1000'
            );
            $stmt->execute([(string)$ctx->userId]);
            $privAccess = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
        }

        // Crypto access log (operazioni crypto su KEK di questo teacher)
        $cryptoAccess = [];
        try {
            $stmt = $db->prepare(
                'SELECT id, occurred_at, teacher_id, operation, actor_user_id,
                        outcome, kv, fp_hash
                 FROM crypto_access_log
                 WHERE teacher_id = ?
                 ORDER BY occurred_at DESC LIMIT 1000'
            );
            $stmt->execute([$ctx->userId]);
            $cryptoAccess = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
        }

        // Crypto custody events (autorità che hanno richiesto accesso a questo teacher)
        $custodyEvents = [];
        try {
            $stmt = $db->prepare(
                'SELECT id, event_type, occurred_at, actor_user_id, authority_name,
                        authority_ref, legal_basis, description, evidence_url
                 FROM crypto_custody_events
                 WHERE teacher_id = ?
                 ORDER BY occurred_at DESC LIMIT 500'
            );
            $stmt->execute([$ctx->userId]);
            $custodyEvents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
        }

        $section->addJsonFile('privileged_access_log.json', $privAccess);
        $section->addJsonFile('crypto_access_log.json', $cryptoAccess);
        $section->addJsonFile('crypto_custody_events.json', $custodyEvents);

        $section->setSummary([
            'privileged_access_count' => count($privAccess),
            'crypto_access_count'     => count($cryptoAccess),
            'custody_events_count'    => count($custodyEvents),
        ]);
        return $section;
    }
}
