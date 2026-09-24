<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use PDO;
use Throwable;

/**
 * Phase 25.R follow-up — Pannello centralizzato per operazioni di backup
 * (super_admin only).
 *
 * Tre layer di backup tracciati:
 *   1. Snapshot Hetzner Cloud         — automatic pre-deploy + manuale (€1.40/mese)
 *   2. Backup Backblaze B2 (encrypted) — automatic daily via encrypted_backup.sh
 *   3. Cold backup HDD esterno         — manuale mensile via PowerShell script
 *
 * Endpoints:
 *   GET  /admin/backup                    → dashboard
 *   POST /admin/backup/cold-completed     → registra cold backup completato
 *   POST /admin/backup/b2-verified        → registra verifica integrità B2
 *
 * Storico: tutto in crypto_custody_events con event_type estesi (migration 064).
 */
final class AdminBackupController
{
    /** GET /admin/backup */
    public function index(Request $req): Response
    {
        $pdo = Database::connection();

        // Ultimo cold backup HDD
        $lastColdBackup = $pdo->query(
            "SELECT * FROM crypto_custody_events
              WHERE event_type = 'cold_backup_completed'
              ORDER BY occurred_at DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: null;

        // Ultima verifica B2
        $lastB2Verified = $pdo->query(
            "SELECT * FROM crypto_custody_events
              WHERE event_type = 'b2_backup_verified'
              ORDER BY occurred_at DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: null;

        // Conteggi history
        $historyCounts = [];
        try {
            $rows = $pdo->query(
                "SELECT event_type, COUNT(*) c FROM crypto_custody_events
                  WHERE event_type IN ('cold_backup_completed','b2_backup_verified','hetzner_snapshot_taken')
                  GROUP BY event_type"
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            $historyCounts = $rows ?: [];
        } catch (Throwable $e) {
/* ignore */
        }

        // Reminder cold backup se >35g
        $coldBackupStale = false;
        $coldBackupDaysAgo = null;
        if ($lastColdBackup && !empty($lastColdBackup['occurred_at'])) {
            $ts = strtotime((string)$lastColdBackup['occurred_at']);
            if ($ts) {
                $coldBackupDaysAgo = (int)floor((time() - $ts) / 86400);
                $coldBackupStale = $coldBackupDaysAgo > 35;
            }
        } else {
            $coldBackupStale = true; // mai eseguito
        }

        $view = View::default();
        $body = $view->render('admin/backup', [
            'lastColdBackup'    => $lastColdBackup,
            'lastB2Verified'    => $lastB2Verified,
            'historyCounts'     => $historyCounts,
            'coldBackupStale'   => $coldBackupStale,
            'coldBackupDaysAgo' => $coldBackupDaysAgo,
            'csrf'              => Csrf::token(),
            'flash'             => $_SESSION['flash'] ?? null,
            'user'              => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
        ]);
        unset($_SESSION['flash']);
        return Response::html($view->render('layout/shell', [
            'title' => 'Backup — Admin',
            'body'  => $body,
        ]));
    }

    /**
     * POST /admin/backup/cold-completed
     * Registra "ho completato il cold backup HDD esterno" dopo aver lanciato
     * lo script PowerShell.
     */
    public function coldCompleted(Request $req): Response
    {
        $hdd     = trim((string)($req->post['hdd_label'] ?? ''));
        $sizeMb  = (int)($req->post['size_mb'] ?? 0);
        $sha256  = trim((string)($req->post['sha256'] ?? ''));
        $notes   = trim((string)($req->post['notes'] ?? ''));

        if ($hdd === '' || mb_strlen($hdd) > 160) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Etichetta HDD obbligatoria (max 160 char)'];
            return Response::redirect('/admin/backup');
        }

        $description = "Cold backup HDD esterno completato.\n"
            . "HDD: {$hdd}\n"
            . ($sizeMb > 0 ? "Size: ~{$sizeMb} MB\n" : '')
            . ($sha256 !== '' ? "Manifest SHA-256: {$sha256}\n" : '')
            . ($notes !== '' ? "\nNote: {$notes}" : '');

        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO crypto_custody_events
                    (event_type, actor_user_id, custody_location, description,
                     legal_basis, occurred_at)
                 VALUES ("cold_backup_completed", ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                (int)(Auth::user()['id'] ?? 0) ?: null,
                $hdd,
                $description,
                'Art. 32(1)(c) GDPR — capacità di ripristinare disponibilità.',
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Cold backup registrato.'];
        } catch (Throwable $e) {
            error_log('[backup] ' . $e->getMessage()); // Audit 25.R.31 — no leak al client
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Operazione fallita.'];
        }
        return Response::redirect('/admin/backup');
    }

    /** POST /admin/backup/b2-verified — registra verifica integrità B2. */
    public function b2Verified(Request $req): Response
    {
        $sha256  = trim((string)($req->post['sha256'] ?? ''));
        $notes   = trim((string)($req->post['notes'] ?? ''));
        $description = "Verifica integrità ultimo backup Backblaze B2 eseguita.\n"
            . ($sha256 !== '' ? "SHA-256 verificato: {$sha256}\n" : '')
            . ($notes !== '' ? "\nNote: {$notes}" : '');
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO crypto_custody_events
                    (event_type, actor_user_id, custody_location, description,
                     legal_basis, occurred_at)
                 VALUES ("b2_backup_verified", ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                (int)(Auth::user()['id'] ?? 0) ?: null,
                'Backblaze B2 pantedu-backup-vps (eu-central-003)',
                $description,
                'Art. 32(1)(d) GDPR — verifica regolare efficacia misure.',
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Verifica B2 registrata.'];
        } catch (Throwable $e) {
            error_log('[backup] ' . $e->getMessage()); // Audit 25.R.31 — no leak al client
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Operazione fallita.'];
        }
        return Response::redirect('/admin/backup');
    }
}
