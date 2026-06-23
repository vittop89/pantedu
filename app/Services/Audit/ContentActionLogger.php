<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use Throwable;

/**
 * Phase 25.R.25 — Content Action Logger.
 *
 * Append-only logger per eventi del docente sui suoi contenuti.
 * Output: tabella `content_action_log` (migration 065).
 *
 * Pattern: chiamata dal repository/controller dopo l'operazione DB principale.
 * Errori silenziosi (no propagation): il logging NON deve mai bloccare l'op
 * principale. In caso di failure, error_log() solo.
 *
 * USO:
 *   ContentActionLogger::log('content_created', $teacherId, $contentId, 'mappa');
 *   ContentActionLogger::log('content_published', $tid, $cid, 'verifica',
 *       details: ['from_visibility' => 'draft', 'to_visibility' => 'published']);
 *
 * Le azioni standard sono enumerate sotto. Aggiungere altre = solo stringa
 * (NO check enum a livello applicativo, per estensibilità).
 */
final class ContentActionLogger
{
    public const ACTION_CREATED      = 'content_created';
    public const ACTION_UPDATED      = 'content_updated';
    public const ACTION_PUBLISHED    = 'content_published';
    public const ACTION_UNPUBLISHED  = 'content_unpublished';
    public const ACTION_ARCHIVED     = 'content_archived';
    public const ACTION_DELETED      = 'content_deleted';
    public const ACTION_CLONED_FROM  = 'content_cloned_from';
    public const ACTION_SHARED       = 'content_shared';
    public const ACTION_UNSHARED     = 'content_unshared';
    public const ACTION_EXPORTED     = 'content_exported';

    /**
     * Logga un evento. Errori silenziosi (no throw).
     *
     * @param string $action      es. self::ACTION_CREATED
     * @param int    $teacherId   owner del contenuto
     * @param int    $contentId   teacher_content.id
     * @param string $contentType es. 'mappa' / 'esercizio'
     * @param ?array $details     dati extra (es. previous_visibility, source_id)
     */
    public static function log(
        string $action,
        int $teacherId,
        int $contentId,
        string $contentType,
        ?array $details = null
    ): void {
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO content_action_log
                    (teacher_id, actor_user_id, content_id, content_type, action,
                     details_json, ip_address, user_agent, occurred_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $teacherId,
                self::currentActorId(),
                $contentId,
                $contentType,
                $action,
                $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                self::currentIp(),
                self::currentUserAgent(),
            ]);
        } catch (Throwable $e) {
            error_log('[ContentActionLogger] insert failed: ' . $e->getMessage());
        }
    }

    /**
     * Logga visibility change automatica detection.
     * Esegue azione corretta in base alla transizione vecchia → nuova.
     */
    public static function logVisibilityChange(
        int $teacherId,
        int $contentId,
        string $contentType,
        ?string $fromVisibility,
        string $toVisibility
    ): void {
        if ($fromVisibility === $toVisibility) {
            return;  // no-op
        }
        $action = match ($toVisibility) {
            'published' => self::ACTION_PUBLISHED,
            'archived'  => self::ACTION_ARCHIVED,
            'draft'     => $fromVisibility === 'published'
                            ? self::ACTION_UNPUBLISHED
                            : self::ACTION_UPDATED,
            default     => self::ACTION_UPDATED,
        };
        self::log($action, $teacherId, $contentId, $contentType, [
            'from_visibility' => $fromVisibility,
            'to_visibility'   => $toVisibility,
        ]);
    }

    /** @return list<array<string,mixed>> Ultimi N eventi (per UI) */
    public static function recent(int $limit = 100, ?int $teacherId = null): array
    {
        $sql = 'SELECT id, occurred_at, teacher_id, actor_user_id, content_id,
                       content_type, action, details_json, ip_address, user_agent
                FROM content_action_log';
        $args = [];
        if ($teacherId !== null) {
            $sql .= ' WHERE teacher_id = ?';
            $args[] = $teacherId;
        }
        $sql .= ' ORDER BY occurred_at DESC LIMIT ?';
        $args[] = max(1, min(1000, $limit));

        try {
            $stmt = Database::connection()->prepare($sql);
            $stmt->execute($args);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function currentActorId(): ?int
    {
        try {
            $u = Auth::user();
            return $u && isset($u['id']) ? (int)$u['id'] : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function currentIp(): ?string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    }

    private static function currentUserAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return $ua ? substr($ua, 0, 512) : null;
    }
}
