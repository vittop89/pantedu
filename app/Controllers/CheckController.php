<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\CheckService;
use Throwable;

/**
 * Phase 12 — Check endpoints (check_password, check_file_protection).
 * Thin glue: CheckService fa il lavoro.
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

    /** POST /check_file_protection.php — body: fileUrl. Ritorna JSON isProtected flag. */
    public function fileProtection(Request $req): Response
    {
        try {
            return Response::json($this->svc->isFileProtected((string)($req->post['fileUrl'] ?? '')));
        } catch (Throwable $e) {
            return Response::json([
                'error'       => $e->getMessage(),
                'isProtected' => false,
            ]);
        }
    }
}
