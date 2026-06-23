<?php

namespace App\Core;

use Throwable;

/**
 * Phase 14 — log append-only per accessi privilegiati.
 *
 * Ogni operazione di super-admin o scopo audit su risorse non-proprie
 * (materiali altri docenti, log tecnici, backup) deve essere loggata qui
 * con `reason` obbligatorio. Fallback su file append-only se DB non
 * disponibile.
 *
 * Immutabilità: il DB role applicativo dovrebbe avere GRANT SELECT,
 * INSERT only su privileged_access_log (no UPDATE, no DELETE).
 * Vedi docs/privacy/ per la procedura di hardening DB.
 */
final class PrivilegedAccessLogger
{
    public static function log(
        string $action,
        string $resourceType,
        ?string $resourceId,
        string $reason,
        string $outcome = 'ok',
    ): void {
        if (trim($reason) === '') {
            $reason = '(motivo_mancante)';
            $outcome = 'denied';
        }
        $user  = Auth::user() ?? [];
        $name  = (string)($user['username'] ?? 'anonymous');
        $role  = (string)($user['role']     ?? 'guest');
        $ip    = self::ip();
        $ua    = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        if (Config::get('database.enabled') && Database::isAvailable()) {
            try {
                $pdo = Database::connection();
                $uidStmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                $uidStmt->execute([$name]);
                $uid = (int)($uidStmt->fetchColumn() ?: 0) ?: null;
                $stmt = $pdo->prepare(
                    'INSERT INTO privileged_access_log
                       (user_id, actor_name, actor_role, action, resource_type, resource_id,
                        reason, ip_address, user_agent, outcome)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $uid, $name, $role, $action, $resourceType, $resourceId,
                    $reason, $ip, $ua, $outcome,
                ]);
                return;
            } catch (Throwable $e) {
                // Fallback sotto
            }
        }
        self::appendFile([
            'ts'            => date('c'),
            'actor_name'    => $name,
            'actor_role'    => $role,
            'action'        => $action,
            'resource_type' => $resourceType,
            'resource_id'   => $resourceId,
            'reason'        => $reason,
            'ip'            => $ip,
            'ua'            => $ua,
            'outcome'       => $outcome,
        ]);
    }

    private static function appendFile(array $entry): void
    {
        $dir = (string)Config::get('app.paths.logs', dirname(__DIR__, 2) . '/storage/logs');
        $file = $dir . '/privileged_access.log';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function ip(): string
    {
        return (string)($_SERVER['HTTP_CLIENT_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'unknown');
    }
}
