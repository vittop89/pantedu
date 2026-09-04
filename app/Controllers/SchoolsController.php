<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\MiurSchoolsService;
use Throwable;

/**
 * Phase 14 — Endpoint pubblico per autocomplete MIUR su registrazione.
 *
 *   GET /api/scuole?q=<stringa>[&types_for=<denom>]
 *
 * Ritorna massimo 15 risultati. Il JSON MIUR completo non viene MAI esposto.
 * La sorgente (~54 MB) è proiettata in un indice compatto server-side.
 */
final class SchoolsController
{
    private MiurSchoolsService $svc;

    public function __construct(?MiurSchoolsService $svc = null)
    {
        $this->svc = $svc ?? MiurSchoolsService::fromConfig();
    }

    public function search(Request $req): Response
    {
        try {
            $typesFor = trim((string)($req->query['types_for'] ?? ''));
            if ($typesFor !== '') {
                $types = $this->svc->typesForDenomination($typesFor);
                return Response::json(['ok' => true, 'types' => $types]);
            }
            $q = (string)($req->query['q'] ?? '');
            $items = $this->svc->search($q, limit: 15);
            return Response::json(['ok' => true, 'items' => $items]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'search_failed'], 500);
        }
    }
}
