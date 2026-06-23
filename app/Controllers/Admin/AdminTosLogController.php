<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Phase 25.Q — ToS acceptance log viewer (super-admin).
 *
 * Mostra:
 *   - elenco utenti teacher/admin
 *   - per ognuno: ultima versione ToS accettata, timestamp, IP, UA
 *   - flag "non aggiornato" se differente da tos.current_version
 *
 * Route: GET /admin/tos-log
 * Middleware: auth + role:admin + super_admin_required
 */
final class AdminTosLogController
{
    public function index(Request $req): Response
    {
        $currentVersion = \App\Services\Gdpr\TosAcceptanceService::TOS_VERSION_CURRENT;
        $rows = $this->fetchRows();
        $outdated = 0;
        foreach ($rows as $r) {
            if (($r['tos_version'] ?? null) !== $currentVersion) {
                $outdated++;
            }
        }

        $view = View::default();
        $body = $view->render('admin/tos_log', [
            'rows'           => $rows,
            'currentVersion' => $currentVersion,
            'outdated'       => $outdated,
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => 'ToS Acceptance Log — Admin',
            'body'  => $body,
        ]));
    }

    /** @return list<array<string,mixed>> */
    private function fetchRows(): array
    {
        if (!Config::get('database.enabled') || !Database::isAvailable()) {
            return [];
        }
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                "SELECT u.id, u.username, u.role, u.created_at,
                        a.tos_version, a.aup_version, a.accepted_at,
                        a.accepted_ip, a.user_agent
                 FROM users u
                 LEFT JOIN user_tos_acceptance a
                   ON a.user_id = u.id
                  AND a.tos_version = (
                      SELECT MAX(tos_version) FROM user_tos_acceptance WHERE user_id = u.id
                  )
                 WHERE u.role IN ('teacher','admin')
                 ORDER BY a.accepted_at IS NULL DESC, a.accepted_at DESC, u.username ASC"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $_) {
            return [];
        }
    }
}
