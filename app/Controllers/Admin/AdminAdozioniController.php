<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\Curriculum\AdozioniRepository;
use App\Services\Audit\ActivityLogger;

/**
 * Il catalogo delle adozioni a mano, per l'amministratore (ADR-036).
 *
 *   GET  /api/admin/adozioni?institute_id=N        i libri dell'istituto
 *   POST /api/admin/adozioni                        aggiunge un libro (origine «istituto»)
 *   POST /api/admin/adozioni/{id}/delete            lo toglie
 *
 * L'import del CSV MIUR resta la strada principale; queste rotte servono per
 * un libro adottato dopo la chiusura del dataset, per un testo consigliato, e
 * alla suite end-to-end, che deve poter mettere un libro nel catalogo senza
 * caricare un file da cinquanta megabyte.
 */
final class AdminAdozioniController
{
    public function __construct(private readonly ?AdozioniRepository $repo = null)
    {
    }

    private function repo(): AdozioniRepository
    {
        return $this->repo ?? new AdozioniRepository();
    }

    public function index(Request $req): Response
    {
        if (!Database::isAvailable()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $iid = (int)($req->query['institute_id'] ?? 0);
        if ($iid <= 0) {
            return Response::json(['error' => 'institute_id_required'], 400);
        }
        $anno = trim((string)($req->query['anno'] ?? ''));
        return Response::json([
            'ok'    => true,
            'libri' => $this->repo()->perClassiEMaterie($iid, [], [], $anno !== '' ? $anno : null),
        ]);
    }

    public function create(Request $req): Response
    {
        if (!Database::isAvailable()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $body = $req->body();
        $iid = (int)($body['institute_id'] ?? 0);
        if ($iid <= 0) {
            return Response::json(['error' => 'institute_id_required'], 400);
        }
        $chk = Database::connection()->prepare('SELECT 1 FROM institutes WHERE id = ? LIMIT 1');
        $chk->execute([$iid]);
        if (!$chk->fetchColumn()) {
            return Response::json(['error' => 'institute_not_found'], 404);
        }
        try {
            $id = $this->repo()->inserisci($iid, $body, 'istituto');
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
        if ($id === 0) {
            return Response::json(['error' => 'adozione_duplicata'], 409);
        }
        ActivityLogger::event(
            'adozione_aggiunta',
            subjectType: 'adozioni_libri',
            subjectId:   (string)$id,
            details:     ['institute_id' => $iid, 'isbn' => (string)($body['isbn'] ?? ''), 'classe' => (string)($body['classe'] ?? '')],
        );
        return Response::json(['ok' => true, 'id' => $id, 'libro' => $this->repo()->trova($id)]);
    }

    /** @param array<string,mixed> $params */
    public function delete(Request $req, array $params): Response
    {
        if (!Database::isAvailable()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $id = (int)($params['id'] ?? 0);
        $libro = $id > 0 ? $this->repo()->trova($id) : null;
        if ($libro === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $ok = $this->repo()->elimina($id, (int)$libro['institute_id']);
        if ($ok) {
            ActivityLogger::event(
                'adozione_tolta',
                subjectType: 'adozioni_libri',
                subjectId:   (string)$id,
                details:     ['institute_id' => (int)$libro['institute_id'], 'isbn' => (string)$libro['isbn']],
            );
        }
        return Response::json(['ok' => $ok]);
    }
}
