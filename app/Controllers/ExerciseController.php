<?php

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ExerciseRepository;
use Throwable;

/**
 * Phase 18 — solo search su `exercises` (admin-imported). Tutti i
 * metodi CRUD (saveNew, duplicateCollex, count, ensureCollexIds,
 * cloneCollexItem) rimossi: ContractRepository copre il nuovo flusso
 * via /api/teacher/content/*.
 */
final class ExerciseController
{
    /**
     * GET /exercises/search.json — JSON API per filtri DB-backed.
     * Parametri: indirizzo, classe, materia, topic, difficulty, tag, q, limit, offset.
     *
     * @deprecated G22.S15.bis Fase 5+ (PROBLEM-16) — ZERO callers in UI moderna.
     *   Sostituito da /api/study/content.json + /api/teacher/content
     *   (ContractRepository-based). Pianificato remove in Phase 26 con la
     *   tabella legacy `exercises`. NON usare in nuovo codice.
     */
    public function searchJson(Request $req): Response
    {
        if (!Config::get('database.enabled') || !Database::isAvailable()) {
            return Response::json(['error' => 'database_unavailable'], 503);
        }
        try {
            $repo = new ExerciseRepository();
            $q    = $req->query;
            $rows = $repo->search([
                'indirizzo'  => $q['indirizzo']  ?? null,
                'classe'     => $q['classe']     ?? null,
                'materia'    => $q['materia']    ?? null,
                'topic'      => $q['topic']      ?? null,
                'difficulty' => isset($q['difficulty']) ? (int)$q['difficulty'] : null,
                'tag'        => $q['tag']        ?? null,
                'q'          => $q['q']          ?? null,
                'limit'      => (int)($q['limit']  ?? 50),
                'offset'     => (int)($q['offset'] ?? 0),
            ]);
            return Response::json(['ok' => true, 'count' => \count($rows), 'rows' => $rows]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** GET /exercises — pagina HTML con filtro UI. */
    public function searchPage(Request $req): Response
    {
        ob_start();
        require \dirname(__DIR__, 2) . '/views/exercises/search.php';
        $body = (string)ob_get_clean();
        return new Response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
