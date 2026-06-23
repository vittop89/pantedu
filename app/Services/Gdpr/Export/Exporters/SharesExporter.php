<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export\Exporters;

use App\Core\Database;
use App\Services\Gdpr\Export\ContentExporterInterface;
use App\Services\Gdpr\Export\ExportContext;
use App\Services\Gdpr\Export\ExportSection;
use PDO;

/**
 * Phase 25.R.23 — Exporter share grants (Art. 15 GDPR).
 *
 * Esporta chi ha condiviso cosa con chi:
 *   - content_shares: condivisioni teacher → teacher / group / institute
 *   - share_groups: gruppi di cui l'utente è membro o ha creato
 *   - map_shares: condivisioni specifiche mappe
 */
final class SharesExporter implements ContentExporterInterface
{
    public function getKey(): string
    {
        return 'shares';
    }
    public function getLabel(): string
    {
        return 'Condivisioni';
    }
    public function getCategory(): string
    {
        return 'meta';
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
        $section = new ExportSection('shares', 'profile', $this->getLabel());
        $db = Database::connection();

        $stats = [];

        // content_shares (sia come owner che come destinatario)
        $contentShares = [];
        try {
            $stmt = $db->prepare(
                'SELECT id, content_id, owner_teacher_id, target_kind, target_id,
                        permissions, status, created_at, revoked_at
                 FROM content_shares
                 WHERE owner_teacher_id = ?
                    OR (target_kind = "teacher" AND target_id = ?)'
            );
            $stmt->execute([$ctx->userId, $ctx->userId]);
            $contentShares = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $stats['content_shares_count'] = count($contentShares);
        } catch (\Throwable) {
        }

        // share_groups (membership)
        $shareGroups = [];
        try {
            $stmt = $db->prepare(
                'SELECT sg.id, sg.name, sg.owner_user_id, sg.created_at,
                        (sg.owner_user_id = ?) AS is_owner
                 FROM share_groups sg
                 LEFT JOIN share_group_members sgm ON sgm.group_id = sg.id
                 WHERE sg.owner_user_id = ? OR sgm.user_id = ?
                 GROUP BY sg.id'
            );
            $stmt->execute([$ctx->userId, $ctx->userId, $ctx->userId]);
            $shareGroups = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $stats['share_groups_count'] = count($shareGroups);
        } catch (\Throwable) {
        }

        // map_shares
        $mapShares = [];
        try {
            $stmt = $db->prepare(
                'SELECT id, map_content_id, owner_user_id, target_kind, target_id,
                        access_level, created_at, expires_at
                 FROM map_shares
                 WHERE owner_user_id = ?
                    OR (target_kind = "teacher" AND target_id = ?)'
            );
            $stmt->execute([$ctx->userId, $ctx->userId]);
            $mapShares = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $stats['map_shares_count'] = count($mapShares);
        } catch (\Throwable) {
        }

        $section->addJsonFile('shares.json', [
            'content_shares' => $contentShares,
            'share_groups'   => $shareGroups,
            'map_shares'     => $mapShares,
        ]);
        $section->setSummary($stats);
        return $section;
    }
}
