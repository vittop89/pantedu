<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\VerificheService;
use Throwable;

/**
 * Verifiche endpoints — Phase 12 full extraction.
 */
final class VerificheController
{
    public function __construct(private readonly VerificheService $svc = new VerificheService())
    {
    }

    // Phase 18 — listFolders rimosso: sostituito da /api/study/topics.json
    // (DB-backed). Mantenuti solo print-info + scelte (print flow).

    /** POST|GET /manage_print_info.php */
    public function managePrintInfo(Request $req): Response
    {
        try {
            $username = Auth::user()['username'] ?? null;
            if ($req->method === 'POST') {
                return Response::json($this->svc->savePrintInfo($username, $req->post));
            }
            $data = $this->svc->loadPrintInfo($username, $req->query);
            return Response::json($data !== null
                ? ['success' => true,  'data' => $data]
                : ['success' => false, 'data' => null]);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /** POST /save_load_scelte.php — body: action, verFilePath, versionKey, data?
     *  G19.4 — passa username al service per isolation per-docente. */
    public function saveLoadScelte(Request $req): Response
    {
        try {
            $username = Auth::user()['username'] ?? null;
            $result = $this->svc->handleScelte(
                (string)($req->post['action']      ?? ''),
                (string)($req->post['verFilePath'] ?? ''),
                (string)($req->post['versionKey']  ?? 'v1'),
                $req->post,
                $username,
            );
            $status = (int)($result['status'] ?? 200);
            unset($result['status']);
            return Response::json($result, $status);
        } catch (Throwable $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage(), 'data' => null]);
        }
    }
}
