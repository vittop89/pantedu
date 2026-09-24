<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Contenuti\CopiaIndipendente;
use App\Services\Contenuti\CopiaVerifica;
use App\Services\Contenuti\DoveVale;
use App\Services\Contenuti\DoveValeVerifica;
use App\Support\TeacherContextResolver;
use InvalidArgumentException;

/**
 * ADR-037, fase 2 — le API di «Dove vale» e di «Duplica in…».
 *
 *   GET  /api/teacher/pubblicazioni/luoghi              le scuole del docente e le voci spuntate
 *   GET  /api/teacher/content/{id}/pubblicazioni        le pubblicazioni di un suo contenuto
 *   POST /api/teacher/content/{id}/pubblicazioni        ne aggiunge una (scuola, indirizzo, classe, materia, stato)
 *   POST /api/teacher/pubblicazioni/{id}/stato          ne cambia lo stato
 *   POST /api/teacher/pubblicazioni/{id}/togli          ne toglie una
 *   POST /api/teacher/content/{id}/duplica              una copia indipendente nel posto scelto
 *
 * ADR-037, fase 3 — le stesse per le verifiche, sulla verifica intera (tutte
 * le varianti e le versioni con quel titolo):
 *
 *   GET  /api/verifica/{id}/pubblicazioni                 i posti della verifica
 *   POST /api/verifica/{id}/pubblicazioni                 ne aggiunge uno (scuola, indirizzo, classe, materia, stato)
 *   POST /api/verifica/{id}/pubblicazioni/{pub}/stato     ne cambia lo stato, principale compresa
 *   POST /api/verifica/{id}/pubblicazioni/{pub}/togli     ne toglie uno
 *   POST /api/verifica/{id}/duplica                       una copia indipendente del pacchetto
 *
 * Le regole stanno nei servizi (DoveVale, CopiaIndipendente, DoveValeVerifica,
 * CopiaVerifica); qui si leggono i parametri e si traducono i codici d'errore.
 * «non_trovato» è 404 anche quando il documento esiste ma è di un altro
 * docente (S1).
 */
final class TeacherPublicationsController
{
    public function __construct(
        private ?DoveVale $doveVale = null,
        private ?CopiaIndipendente $copia = null,
        private ?DoveValeVerifica $doveValeVerifica = null,
        private ?CopiaVerifica $copiaVerifica = null,
    ) {
    }

    private function doveVale(): DoveVale
    {
        return $this->doveVale ??= new DoveVale();
    }

    private function doveValeVerifica(): DoveValeVerifica
    {
        return $this->doveValeVerifica ??= new DoveValeVerifica();
    }

    public function elencoVerifica(Request $req, array $params): Response
    {
        $docente = $this->docente();
        if ($docente instanceof Response) {
            return $docente;
        }
        try {
            return Response::ok($this->doveValeVerifica()->elenco((int)($params['id'] ?? 0), $docente))->withNoCache();
        } catch (InvalidArgumentException $e) {
            return $this->errore($e);
        }
    }

    public function aggiungiVerifica(Request $req, array $params): Response
    {
        $docente = $this->docente();
        if ($docente instanceof Response) {
            return $docente;
        }
        $p = $this->parametri($req);
        try {
            $n = $this->doveValeVerifica()->aggiungi(
                (int)($params['id'] ?? 0),
                $docente,
                (int)($p['scuola'] ?? 0),
                (int)($p['indirizzo'] ?? 0),
                (int)($p['classe'] ?? 0),
                (int)($p['materia'] ?? 0),
                (string)($p['stato'] ?? 'draft'),
                !empty($p['archivio']),
            );
            return Response::ok(['varianti' => $n]);
        } catch (InvalidArgumentException $e) {
            return $this->errore($e);
        }
    }

    public function statoVerifica(Request $req, array $params): Response
    {
        $docente = $this->docente();
        if ($docente instanceof Response) {
            return $docente;
        }
        $p = $this->parametri($req);
        try {
            $n = $this->doveValeVerifica()->impostaStato(
                (int)($params['id'] ?? 0),
                (int)($params['pub'] ?? 0),
                $docente,
                (string)($p['stato'] ?? ''),
                array_key_exists('archivio', $p) ? !empty($p['archivio']) : null,
            );
            return Response::ok(['varianti' => $n]);
        } catch (InvalidArgumentException $e) {
            return $this->errore($e);
        }
    }

    public function togliVerifica(Request $req, array $params): Response
    {
        $docente = $this->docente();
        if ($docente instanceof Response) {
            return $docente;
        }
        try {
            $n = $this->doveValeVerifica()->togli((int)($params['id'] ?? 0), (int)($params['pub'] ?? 0), $docente);
            return Response::ok(['varianti' => $n]);
        } catch (InvalidArgumentException $e) {
            return $this->errore($e);
        }
    }

    public function duplicaVerifica(Request $req, array $params): Response
    {
        $docente = $this->docente();
        if ($docente instanceof Response) {
            return $docente;
        }
        $p = $this->parametri($req);
        try {
            $ids = ($this->copiaVerifica ??= new CopiaVerifica())->duplica(
                (int)($params['id'] ?? 0),
                $docente,
                (int)($p['scuola'] ?? 0),
                (int)($p['indirizzo'] ?? 0),
                (int)($p['classe'] ?? 0),
                (int)($p['materia'] ?? 0),
            );
            return Response::ok(['id' => $ids[0], 'varianti' => $ids]);
        } catch (InvalidArgumentException $e) {
            return $this->errore($e);
        }
    }

    public function luoghi(Request $req): Response
    {
        $docente = $this->docente();
        if ($docente instanceof Response) {
            return $docente;
        }
        return Response::ok(['scuole' => $this->doveVale()->luoghi($docente)]);
    }

    public function elenco(Request $req, array $params): Response
    {
        $docente = $this->docente();
        if ($docente instanceof Response) {
            return $docente;
        }
        try {
            return Response::ok($this->doveVale()->elenco((int)($params['id'] ?? 0), $docente))->withNoCache();
        } catch (InvalidArgumentException $e) {
            return $this->errore($e);
        }
    }

    public function aggiungi(Request $req, array $params): Response
    {
        $docente = $this->docente();
        if ($docente instanceof Response) {
            return $docente;
        }
        $p = $this->parametri($req);
        try {
            $id = $this->doveVale()->aggiungi(
                (int)($params['id'] ?? 0),
                $docente,
                (int)($p['scuola'] ?? 0),
                (int)($p['indirizzo'] ?? 0),
                (int)($p['classe'] ?? 0),
                (int)($p['materia'] ?? 0),
                (string)($p['stato'] ?? 'draft'),
                !empty($p['archivio']),
            );
            return Response::ok(['id' => $id]);
        } catch (InvalidArgumentException $e) {
            return $this->errore($e);
        }
    }

    public function stato(Request $req, array $params): Response
    {
        $docente = $this->docente();
        if ($docente instanceof Response) {
            return $docente;
        }
        $p = $this->parametri($req);
        try {
            $this->doveVale()->impostaStato(
                (int)($params['id'] ?? 0),
                $docente,
                (string)($p['stato'] ?? ''),
                array_key_exists('archivio', $p) ? !empty($p['archivio']) : null,
            );
            return Response::ok();
        } catch (InvalidArgumentException $e) {
            return $this->errore($e);
        }
    }

    public function togli(Request $req, array $params): Response
    {
        $docente = $this->docente();
        if ($docente instanceof Response) {
            return $docente;
        }
        try {
            $this->doveVale()->togli((int)($params['id'] ?? 0), $docente);
            return Response::ok();
        } catch (InvalidArgumentException $e) {
            return $this->errore($e);
        }
    }

    public function duplica(Request $req, array $params): Response
    {
        $docente = $this->docente();
        if ($docente instanceof Response) {
            return $docente;
        }
        $p = $this->parametri($req);
        try {
            $id = ($this->copia ??= new CopiaIndipendente())->duplica(
                (int)($params['id'] ?? 0),
                $docente,
                (int)($p['scuola'] ?? 0),
                (int)($p['indirizzo'] ?? 0),
                (int)($p['classe'] ?? 0),
                (int)($p['materia'] ?? 0),
            );
            return Response::ok(['id' => $id]);
        } catch (InvalidArgumentException $e) {
            return $this->errore($e);
        }
    }

    // ── aiuti ────────────────────────────────────────────────────────────

    /** L'id del docente collegato, o la risposta da restituire. */
    private function docente(): int|Response
    {
        if (!(bool)Config::get('database.enabled') || !Database::isAvailable()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        return $tid > 0 ? $tid : Response::json(['error' => 'unauthorized'], 401);
    }

    /** @return array<string,mixed> form o JSON */
    private function parametri(Request $req): array
    {
        return $req->isJson() ? $req->json() : $req->post;
    }

    private function errore(InvalidArgumentException $e): Response
    {
        $codice = $e->getMessage();
        $stato = $codice === 'non_trovato' ? 404 : ($codice === 'copia_non_riuscita' ? 500 : 400);
        return Response::json(['error' => $codice], $stato);
    }
}
