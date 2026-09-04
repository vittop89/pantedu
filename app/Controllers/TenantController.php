<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Phase 25.Q — Tenant switch endpoint per UI selector istituto.
 *
 * Endpoint:
 *   POST /api/tenant/switch — body: institute_id
 *   GET  /api/tenant/current — restituisce id istituto attivo
 *
 * Validazione delegata a Auth::setCurrentInstitute() (verifica accesso
 * effettivo: pivot teacher_institutes / admin_institute_id / super-admin).
 */
final class TenantController
{
    /** POST /api/tenant/switch — body: institute_id */
    public function switch(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['ok' => false, 'error' => 'not_authenticated'], 401);
        }
        $iid = $req->post['institute_id'] ?? null;
        if ($iid === null || !ctype_digit((string)$iid)) {
            return Response::json(['ok' => false, 'error' => 'invalid_institute_id'], 400);
        }
        $iid = (int)$iid;
        if (!Auth::setCurrentInstitute($iid)) {
            return Response::json(['ok' => false, 'error' => 'forbidden_or_not_member'], 403);
        }
        return Response::json([
            'ok' => true,
            'current_institute_id' => $iid,
        ]);
    }

    /** GET /api/tenant/current */
    public function current(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['ok' => false, 'error' => 'not_authenticated'], 401);
        }
        return Response::json([
            'ok' => true,
            'current_institute_id' => Auth::currentInstitute(),
            'role' => Auth::role(),
            'is_super_admin' => Auth::isSuperAdmin(),
        ]);
    }
}
