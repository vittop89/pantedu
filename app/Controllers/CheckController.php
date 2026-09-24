<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\CheckService;

/**
 * Phase 12 — Check endpoints (check_password).
 * Thin glue: CheckService fa il lavoro. Il controllo «file protetto»
 * (/check/file-protection) è stato tolto il 2026-09-05 con l'entry point
 * legacy log/auth/AuthCode.php che cercava nei file.
 */
final class CheckController
{
    public function __construct(private readonly CheckService $svc = new CheckService())
    {
    }

    /** POST /check_password.php — body: password. Ritorna 'correct' o 'incorrect' (text/plain). */
    public function password(Request $req): Response
    {
        $ok = $this->svc->verifyAdminPassword((string)($req->post['password'] ?? ''));
        return new Response(
            $ok ? 'correct' : 'incorrect',
            200,
            ['Content-Type' => 'text/plain; charset=utf-8']
        );
    }
}
