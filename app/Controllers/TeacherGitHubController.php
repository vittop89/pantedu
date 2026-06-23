<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\GitHub\GitHubSyncService;
use Throwable;

/**
 * G22.S15.bis Fase 5 — GitHub sync endpoints per docente.
 *
 *   GET  /api/teacher/github/status      — config corrente (no PAT)
 *   POST /api/teacher/github/configure   — body: {pat, owner, repo, branch?}
 *   POST /api/teacher/github/disconnect  — rimuove config
 *   POST /api/teacher/github/sync-test   — push file dummy per smoke test
 */
final class TeacherGitHubController
{
    private GitHubSyncService $svc;

    public function __construct(?GitHubSyncService $svc = null)
    {
        $this->svc = $svc ?? new GitHubSyncService(new TeacherCryptoService());
    }

    public function status(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $cfg = $this->svc->getConfig($tid);
        return Response::json([
            'ok' => true,
            'configured' => (bool)$cfg,
            'config' => $cfg,
        ]);
    }

    public function configure(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $pat    = trim((string)($payload['pat'] ?? ''));
        $owner  = trim((string)($payload['owner'] ?? ''));
        $repo   = trim((string)($payload['repo'] ?? ''));
        $branch = trim((string)($payload['branch'] ?? 'main'));
        try {
            $r = $this->svc->configure($tid, $pat, $owner, $repo, $branch);
            return Response::json($r);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function disconnect(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $this->svc->disconnect($tid);
        return Response::json(['ok' => true]);
    }

    /** Smoke test: push README.md con timestamp per verificare che il push funzioni. */
    public function syncTest(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        try {
            $content = "# Pantedu Backup\n\nUltimo test: " . date('Y-m-d H:i:s') . "\n\n"
                     . "Questo repository ospita il backup automatico delle tue mappe e verifiche.\n";
            $r = $this->svc->pushFile($tid, 'README.md', $content, 'Pantedu: smoke test sync');
            if ($r['ok']) {
                $this->svc->updateLastSync($tid, null);
            } else {
                $this->svc->updateLastSync($tid, (string)($r['error'] ?? 'unknown'));
            }
            return Response::json($r);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * G22.S15.bis Fase 5 — Real sync UNIFICATO con il bundle locale.
     *
     * Riusa lo stesso manifest di `/api/teacher/sync-local-bundle` (cioè
     * `VerificaController::buildLocalBundleManifest` + `materializeBundleEntry`)
     * → stessa struttura folder per Drive/Locale/GitHub:
     *   {institute}/texCommon/...                            (shared)
     *   {institute}/{ind}/griglie/{ind}_{materia}.tex         (per indirizzo)
     *   {institute}/{ind}/{cls}/{materia}/verifiche/{title}/{version}/main_*.tex
     *   {institute}/{ind}/{cls}/{materia}/verifiche/{title}/{version}/esercizi_*.tex
     *   {institute}/{ind}/{cls}/{materia}/verifiche/{title}/{version}/{variant}.pdf
     *   {institute}/mappe/...                                 (mappe concettuali)
     *
     * In più aggiunge sotto `modelli/`:
     *   modelli/risdoc/{rel}    (overrides texCommon docente, se presenti)
     *
     * Skip identici (pushFile dedupe via SHA). Idempotent.
     */
    public function syncAll(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            $cfg = $this->svc->getConfig($tid);
            if (!$cfg) {
                return Response::json(['ok' => false, 'error' => 'github_not_configured'], 422);
            }

            @set_time_limit(0);
            @ini_set('memory_limit', '512M');

            $stats = ['total' => 0, 'pushed' => 0, 'skipped' => 0];
            $errors = [];

            // ── 1. Bundle UNIFICATO (stesso layout local-sync) ──────────
            // G22.S15.bis Fase 5+ — VerificaSyncController split.
            $verifCtrl = new VerificaSyncController();
            $manifest = $verifCtrl->buildLocalBundleManifest($tid);
            foreach ($manifest as $entry) {
                $stats['total']++;
                try {
                    $materialized = $verifCtrl->materializeBundleEntry($tid, $entry);
                    $path = (string)($materialized['path'] ?? '');
                    $b64  = (string)($materialized['content'] ?? '');
                    if ($path === '' || $b64 === '') {
                        continue;
                    }
                    $bin = base64_decode($b64, true);
                    if ($bin === false) {
                        $errors[] = ['id' => $path, 'error' => 'base64_decode_failed'];
                        continue;
                    }
                    $r = $this->svc->pushFile(
                        $tid,
                        $path,
                        $bin,
                        "Pantedu: " . basename($path)
                    );
                    $this->tally($r, $stats, $errors, $path);
                } catch (Throwable $e) {
                    $errors[] = ['id' => (string)($entry['path'] ?? '?'), 'error' => $e->getMessage()];
                }
            }

            // G22.S15.bis Fase 5 — modelli risdoc/texCommon ora inclusi nel
            // buildLocalBundleManifest (sotto {institute}/modelli/...) → unified
            // sync, niente push duplicato.

            $this->svc->updateLastSync($tid, count($errors) > 0
                ? "{$stats['total']} totali, " . count($errors) . " errori" : null);

            return Response::json([
                'ok' => true,
                'total'        => $stats['total'],
                'pushed'       => $stats['pushed'],
                'skipped'      => $stats['skipped'],
                'errors'       => array_slice($errors, 0, 20),
                'errors_count' => count($errors),
            ]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Aggrega risultato pushFile in stats + errors. */
    private function tally(array $r, array &$stats, array &$errors, string $label): void
    {
        if ($r['ok']) {
            if (($r['action'] ?? '') === 'unchanged') {
                $stats['skipped']++;
            } else {
                $stats['pushed']++;
            }
        } else {
            $errors[] = ['id' => $label, 'error' => (string)($r['error'] ?? 'unknown')];
        }
    }

    /**
     * G22.S15.bis Fase 5 — POST /api/teacher/github/push-file
     *
     * Push singolo file (path + content base64) al repo configurato.
     * Usato dal client per streaming sync: drena local-bundle file-per-file
     * e chiama questo endpoint per ognuno → progress real-time.
     *
     * Body JSON: {path, content_b64, message?}
     * Risposta: {ok, action: 'created'|'updated'|'unchanged', sha?}
     */
    public function pushFile(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        // Release session lock: il push GitHub è long (round-trip ~500ms)
        // e blocca altre richieste sulla stessa session se non rilascio.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        try {
            $cfg = $this->svc->getConfig($tid);
            if (!$cfg) {
                return Response::json(['ok' => false, 'error' => 'github_not_configured'], 422);
            }
            $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $path = (string)($payload['path'] ?? '');
            $b64  = (string)($payload['content_b64'] ?? '');
            $msg  = trim((string)($payload['message'] ?? '')) ?: ('Pantedu: ' . basename($path));
            if ($path === '' || $b64 === '') {
                return Response::json(['ok' => false, 'error' => 'path_or_content_missing'], 400);
            }
            $bin = base64_decode($b64, true);
            if ($bin === false) {
                return Response::json(['ok' => false, 'error' => 'base64_decode_failed'], 400);
            }
            $r = $this->svc->pushFile($tid, $path, $bin, $msg);
            if (!$r['ok']) {
                $this->svc->updateLastSync($tid, (string)($r['error'] ?? 'unknown'));
            }
            return Response::json($r);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Sanitizza componente di path per GitHub: solo alfanumerici + _ - */
    private static function sanitizePathComponent(string $s): string
    {
        $s = preg_replace('/[^a-zA-Z0-9_.-]+/u', '_', $s) ?? $s;
        $s = trim($s, '_-.');
        return $s !== '' ? substr($s, 0, 100) : 'unnamed';
    }

    private function teacherId(): int
    {
        if (!Auth::check()) {
            return 0;
        }
        $u = Auth::user();
        return (int)($u['id'] ?? 0);
    }
}
