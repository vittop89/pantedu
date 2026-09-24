<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * G20.0 Phase 8 — Pagina profilo docente:
 *   /area-docente/profilo
 * Permette CRUD link teacher↔institute (multi-istituto).
 */
final class TeacherProfileController
{
    public function page(Request $req): Response
    {
        if (!\App\Support\AuthHelpers::isTeacherOrAdmin()) {
            return Response::html('<h1>403</h1><p>Solo docenti/admin.</p>', 403);
        }
        ob_start();
        require __DIR__ . '/../../views/area_docente/profilo.php';
        $html = ob_get_clean();
        $r = new Response($html, 200);
        $r->headers['Content-Type'] = 'text/html; charset=UTF-8';
        return $r;
    }

    public function fontiPage(Request $req): Response
    {
        if (!\App\Support\AuthHelpers::isTeacherOrAdmin()) {
            return Response::html('<h1>403</h1><p>Solo docenti/admin.</p>', 403);
        }
        ob_start();
        require __DIR__ . '/../../views/area_docente/fonti.php';
        $html = ob_get_clean();
        $r = new Response($html, 200);
        $r->headers['Content-Type'] = 'text/html; charset=UTF-8';
        return $r;
    }

    public function templatesPage(Request $req): Response
    {
        if (!\App\Support\AuthHelpers::isTeacherOrAdmin()) {
            return Response::html('<h1>403</h1><p>Solo docenti/admin.</p>', 403);
        }
        // G20.2 / G22.S13 / G22.S15.bis Fase 5 — sub-tab whitelist:
        //   verifiche (default) | esercizi | risdoc | drawio | tikz
        $tab = (string)($req->query['tab'] ?? 'verifiche');
        if (!\in_array($tab, ['verifiche', 'esercizi', 'risdoc', 'drawio', 'tikz', 'scorciatoie', 'pdf-import'], true)) {
            $tab = 'verifiche';
        }
        $user = Auth::user(); // disponibile nel partial esercizi
        ob_start();
        require __DIR__ . '/../../views/area_docente/templates.php';
        $html = ob_get_clean();
        $r = new Response($html, 200);
        $r->headers['Content-Type'] = 'text/html; charset=UTF-8';
        return $r;
    }

    /**
     * Phase 25 — Pagina dedicata gestione categorie (nuova/rinomina/elimina)
     * per le sezioni document (Risorse docente=risdoc, BES/DSA=bes). Sostituisce
     * la gestione inline nel sidepage (che ora rimanda qui via doppio-click).
     */
    public function categoriePage(Request $req): Response
    {
        if (!\App\Support\AuthHelpers::isTeacherOrAdmin()) {
            return Response::html('<h1>403</h1><p>Solo docenti/admin.</p>', 403);
        }
        ob_start();
        require __DIR__ . '/../../views/area_docente/categorie.php';
        $html = ob_get_clean();
        $r = new Response($html, 200);
        $r->headers['Content-Type'] = 'text/html; charset=UTF-8';
        return $r;
    }
}
