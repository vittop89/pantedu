<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

use App\Core\Auth;
use App\Core\Database;
use App\Services\AclPolicy;
use PDO;

/**
 * Permission check per risdoc per-teacher overrides (Phase 21).
 *
 * Regole:
 *   - super-admin: can_view / can_edit / can_manage su tutto.
 *   - teacher owner: can_view + can_edit (solo i suoi override, privato).
 *   - teacher collaborator: can_view + can_edit (solo i suoi override).
 *   - teacher con visibility granted: can_view soltanto.
 *   - altri: 403.
 */
final class Permission
{
    public static function currentTeacherId(): int
    {
        $u = Auth::user();
        if (!$u) {
            return 0;
        }
        return \App\Support\TeacherContextResolver::userIdFromUsername((string)($u['username'] ?? ''));
    }

    public static function isSuperAdmin(): bool
    {
        return AclPolicy::isSuperAdmin();
    }

    /**
     * G22.S26 — `isOwner` deprecato: col `owner_id` rimossa nella migration 047.
     * Stub mantenuto per back-compat (nessun caller in production). Sempre false.
     * @deprecated Usa isCollaborator + isSuperAdmin.
     */
    public static function isOwner(int $templateId, int $teacherId): bool
    {
        return false;
    }

    public static function isCollaborator(int $templateId, int $teacherId): bool
    {
        if ($teacherId === 0) {
            return false;
        }
        $stmt = Database::connection()->prepare('SELECT 1 FROM risdoc_template_collaborators WHERE template_id=? AND teacher_id=? LIMIT 1');
        $stmt->execute([$templateId, $teacherId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * G22.S26 — Ritorna true se il collaboratore richiede revisione admin
     * sui suoi save (requires_review=1). Per super-admin: sempre false
     * (la review non si applica al super-admin che ha controllo diretto).
     */
    public static function requiresReview(int $templateId, int $teacherId): bool
    {
        if ($teacherId === 0 || self::isSuperAdmin()) {
            return false;
        }
        $stmt = Database::connection()->prepare(
            'SELECT requires_review FROM risdoc_template_collaborators
              WHERE template_id=? AND teacher_id=? LIMIT 1'
        );
        $stmt->execute([$templateId, $teacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && (int)$row['requires_review'] === 1;
    }

    public static function hasVisibility(int $templateId, int $teacherId): bool
    {
        if ($teacherId === 0) {
            return false;
        }
        $stmt = Database::connection()->prepare('SELECT visible FROM risdoc_template_visibility WHERE template_id=? AND teacher_id=? LIMIT 1');
        $stmt->execute([$templateId, $teacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && (int)$row['visible'] === 1;
    }

    public static function canView(int $templateId, int $teacherId): bool
    {
        if (self::isSuperAdmin()) {
            return true;
        }
        if (self::isCollaborator($templateId, $teacherId)) {
            return true;
        }
        if (self::hasVisibility($templateId, $teacherId)) {
            return true;
        }

        // G22.S26 — Dopo drop owner_id, TUTTI i template sono "istituzionali"
        // di default (modificabili solo da super-admin se senza collaboratori).
        // La visibilità per i teacher non-collab/non-visible è regolata da
        // `visibility_scope`:
        //   public    → tutti i teacher (default, backward-compat 24.62)
        //   institute → match institute_id
        //   indirizzo → match curriculum.indirizzo
        //   classe    → match curriculum.classe
        //   denied    → solo collab/super_admin (già gestiti sopra)
        // Fail-safe: scope sconosciuto → DENY.
        if ($teacherId <= 0) {
            return false;
        }
        try {
            $stmt = Database::connection()->prepare('SELECT visibility_scope, scope_institute_id, scope_indirizzo, scope_classe
                 FROM risdoc_templates WHERE id=? LIMIT 1');
            $stmt->execute([$templateId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            // Migration 013 non ancora applicata: fallback a "tutti visibili".
            $stmt = Database::connection()->prepare('SELECT 1 FROM risdoc_templates WHERE id=? LIMIT 1');
            $stmt->execute([$templateId]);
            return (bool)$stmt->fetchColumn();
        }
        if (!$row) {
            return false;
        }
        return self::matchVisibilityScope($row, $teacherId);
    }

    /**
     * Phase 25.B3 — risolve lo scope di visibilità di un template istituzionale.
     */
    private static function matchVisibilityScope(array $row, int $teacherId): bool
    {
        $scope = (string)($row['visibility_scope'] ?? 'public');
        return match ($scope) {
            'public'    => true,
            'denied'    => false,
            'institute' => self::matchInstituteScope($row, $teacherId),
            'indirizzo' => self::matchIndirizzoScope($row, $teacherId),
            'classe'    => self::matchClasseScope($row, $teacherId),
            default     => false,  // unknown scope = DENY (fail-safe)
        };
    }

    private static function matchInstituteScope(array $row, int $teacherId): bool
    {
        $tplInst = $row['scope_institute_id'] ?? null;
        if ($tplInst === null) {
            return true;
        }  // scope vuoto = qualsiasi institute
        try {
            $stmt = Database::connection()->prepare('SELECT institute_id FROM users WHERE id=? LIMIT 1');
            $stmt->execute([$teacherId]);
            $userInst = $stmt->fetchColumn();
            return $userInst !== false && (int)$userInst === (int)$tplInst;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function matchIndirizzoScope(array $row, int $teacherId): bool
    {
        $tplInd = (string)($row['scope_indirizzo'] ?? '');
        if ($tplInd === '') {
            return true;
        }
        // teacher_subjects è il tracking curriculum (Phase 13). Fallback
        // DENY se la tabella non popolata per questo teacher.
        try {
            $stmt = Database::connection()->prepare('SELECT 1 FROM teacher_subjects WHERE teacher_id=? AND indirizzo=? LIMIT 1');
            $stmt->execute([$teacherId, $tplInd]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function matchClasseScope(array $row, int $teacherId): bool
    {
        $tplCls = (string)($row['scope_classe'] ?? '');
        if ($tplCls === '') {
            return true;
        }
        try {
            $stmt = Database::connection()->prepare('SELECT 1 FROM teacher_subjects WHERE teacher_id=? AND classe=? LIMIT 1');
            $stmt->execute([$teacherId, $tplCls]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function canEdit(int $templateId, int $teacherId): bool
    {
        if (self::isSuperAdmin()) {
            return true;
        }
        // G22.S26 — Owner deprecato. Solo super-admin e collaboratori
        // hanno canEdit. Se collaboratore ha requires_review=1, le modifiche
        // verranno comunque accodate in pending_changes invece di applicarsi
        // direttamente — la rule canEdit non cambia, è la pipeline a divergere.
        return self::isCollaborator($templateId, $teacherId);
    }

    public static function canManageAdmin(): bool
    {
        return self::isSuperAdmin();
    }
}
