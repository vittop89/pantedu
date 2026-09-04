<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\Crypto\TeacherRecoveryService;
use Throwable;

/**
 * G22.S20 — Recovery Key endpoints (Modalità A: signed manifest).
 *
 *   GET  /api/teacher/recovery-key/status   — stato (esiste? quando creata?)
 *   POST /api/teacher/recovery-key/generate — genera R 32 bytes (una volta sola)
 *   POST /api/teacher/recovery-key/revoke   — segna revoked_at
 *
 * Owner-only: ogni endpoint opera sul teacher autenticato. NESSUN endpoint
 * espone R post-generation: il client deve salvarla immediatamente.
 */
final class TeacherRecoveryController
{
    private TeacherRecoveryService $svc;

    public function __construct(?TeacherRecoveryService $svc = null)
    {
        $this->svc = $svc ?? new TeacherRecoveryService();
    }

    public function status(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        try {
            return Response::json(['ok' => true, 'status' => $this->svc->status($tid)]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Genera Recovery Key. NON idempotente: ritorna 409 se già esiste e non
     * revocata. R restituita UNA SOLA VOLTA in chiaro nel response body.
     * Successivi accessi non possono mai retrievare R (solo via PDF cassaforte).
     */
    public function generate(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $ip = $this->clientIp();
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        try {
            $r = $this->svc->generate($tid, $ip, $ua);
            if (!$r['ok']) {
                return Response::json($r, 409);
            }
            $this->svc->markDownload($tid, $ip, $ua);
            return Response::json($r);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function revoke(Request $req): Response
    {
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $ip = $this->clientIp();
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        try {
            return Response::json($this->svc->revoke($tid, $ip, $ua));
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function teacherId(): int
    {
        if (!Auth::check()) {
            return 0;
        }
        $u = Auth::user();
        return (int)($u['id'] ?? 0);
    }

    private function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return $ip ? substr((string)$ip, 0, 45) : null;
    }
}
