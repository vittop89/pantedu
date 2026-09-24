<?php

declare(strict_types=1);

namespace App\Controllers\Risdoc;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\Risdoc\CompilationRepository;
use App\Services\Risdoc\Permission;

/**
 * REST API per risdoc per-teacher compilations.
 *
 *   GET    /api/risdoc/templates/{id}/compilations       → lista (filtrata)
 *   GET    /api/risdoc/compilations/{id}                 → dettaglio (con data_json)
 *   POST   /api/risdoc/templates/{id}/compilations       → upsert
 *   POST   /api/risdoc/compilations/{id}/delete          → delete
 *
 * Tutte protette da auth teacher+. Write protette da CSRF tramite
 * gruppo routes (vedi routes/web.php).
 */
final class CompilationController
{
    public function __construct(private CompilationRepository $repo = new CompilationRepository())
    {
    }

    public function index(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if ($tid === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        if ($id === 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        // Filtri contesto: se specificati lato client, la lista mostra
        // solo le compilazioni con classe/sezione/indirizzo/disciplina
        // identici. Null = no filter su quel campo.
        $classe     = $this->nullable($req->query['classe']     ?? null);
        $sezione    = $this->nullable($req->query['sezione']    ?? null);
        $indirizzo  = $this->nullable($req->query['indirizzo']  ?? null);
        $disciplina = $this->nullable($req->query['disciplina'] ?? null);
        $rows = $this->repo->listByTeacher($tid, $id, $classe, $sezione, $indirizzo, $disciplina);
        return Response::json(['ok' => true, 'count' => count($rows), 'compilations' => $rows]);
    }

    public function show(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if ($tid === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        if ($id === 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }

        $row = $this->repo->find($tid, $id);
        if (!$row) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return Response::json(['ok' => true, 'compilation' => $row]);
    }

    public function save(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if ($tid === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        if ($id === 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        // 2026-09-22 — la decisione e' del Titolare della piattaforma, non
        // dell'Istituto: prima questo commento diceva «su indicazione del suo
        // DPO», e descriveva quindi un mezzo del trattamento come determinato
        // da un soggetto esterno. E' proprio l'elemento che l'art. 26 cerca.
        // Il raggruppamento per Istituto e' una scelta tecnica di chi conduce
        // la piattaforma, non l'esecuzione di un'istruzione ricevuta.
        // Il Titolare puo' aver deciso che la compilazione non
        // resti sul server. Il client tiene la bozza nel browser ed esporta
        // il PDF. Vedi CompilationStoragePolicy.
        $tmpl = (new \App\Services\Risdoc\TemplateResolver())->findTemplate($id);
        if ($tmpl !== null && !\App\Services\Risdoc\CompilationStoragePolicy::allowedFor($tid, $tmpl)) {
            return Response::json([
                'error'   => 'compilation_storage_disabled',
                'message' => 'Per il tuo Istituto le compilazioni di questo modello non vengono salvate sul server: la bozza resta in questo browser, esporta il PDF e depositalo nei sistemi della scuola.',
            ], 403);
        }

        $key   = trim((string)($req->post['compilation_key'] ?? ''));
        $label = trim((string)($req->post['label'] ?? ''));
        $data  = (string)($req->post['data'] ?? '');
        if ($key === '') {
            return Response::json(['error' => 'compilation_key_required'], 400);
        }
        if ($label === '') {
            return Response::json(['error' => 'label_required'], 400);
        }
        if ($data === '') {
            return Response::json(['error' => 'data_required'], 400);
        }
        $decoded = json_decode($data, true);
        if (!\is_array($decoded) && json_last_error() !== JSON_ERROR_NONE) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        if (strlen($data) > 2 * 1024 * 1024) {
// 2MB safeguard
            return Response::json(['error' => 'payload_too_large'], 413);
        }

        // 2026-09-04 — senza account studente (scenari 1 e 2, o 3 in modalita'
        // Anonima) i campi riferiti a studenti o genitori non si salvano:
        // il valore viene svuotato prima di toccare il database e i nomi dei
        // campi tornano al client. Vedi CompilationScrubber.
        $scrubbed = [];
        if (\is_array($decoded) && !\App\Support\DeploymentScenario::studentAccountsEnabled()) {
            $res      = \App\Services\Risdoc\CompilationScrubber::scrub($decoded);
            $scrubbed = $res['scrubbed'];
            if ($scrubbed !== []) {
                $data = json_encode($res['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($data === false) {
                    return Response::json(['error' => 'invalid_json'], 400);
                }
            }
        }

        $classe     = $this->nullable($req->post['classe']     ?? null);
        $sezione    = $this->nullable($req->post['sezione']    ?? null);
        $indirizzo  = $this->nullable($req->post['indirizzo']  ?? null);
        $disciplina = $this->nullable($req->post['disciplina'] ?? null);

        $compilationId = $this->repo->save(
            $tid,
            $id,
            $key,
            $label,
            $classe,
            $sezione,
            $indirizzo,
            $disciplina,
            $data
        );
        return Response::json(['ok' => true, 'id' => $compilationId, 'scrubbed' => $scrubbed]);
    }

    public function delete(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if ($tid === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        if ($id === 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }

        $ok = $this->repo->delete($tid, $id);
        if (!$ok) {
            return Response::json(['error' => 'not_found_or_forbidden'], 404);
        }
        return Response::ok();
    }

    /**
     * POST /api/risdoc/compilations/{id}/scaricata — il docente ha portato via
     * il documento.
     *
     * PERCHE' LO DICE IL CLIENT (22 settembre 2026, ADR-046)
     *
     * Lo scaricamento del PDF avviene interamente nel browser: il gestore del
     * pulsante prende i byte gia' in memoria e li da' al browser come file.
     * Nessuna richiesta parte. Il server non puo' accorgersene da solo — e non
     * poteva agganciarsi alla compilazione del PDF, che parte all'apertura
     * dell'anteprima e ogni due secondi con la ricompilazione automatica,
     * cioe' mentre il docente sta ancora scrivendo.
     *
     * COSA VUOL DIRE, E COSA NO
     *
     * Vuol dire «questa bozza ha una copia fuori di qui», ed e' la condizione
     * perche' cancellarla non tolga niente a nessuno. Non vuol dire «ho
     * finito»: la grazia si conta dall'ultima fra scaricamento e modifica, e
     * una modifica successiva la fa ripartire da capo.
     *
     * QUESTA SEGNALAZIONE NON E' UN FATTO CERTO. Se la rete cade, se la scheda
     * si chiude, se il blocco JS fallisce, il docente ha il PDF e la data non
     * si scrive. La riga non si cancella: cade nella rete di fine anno. E' il
     * verso giusto — un guasto lascia il lavoro dov'e'.
     *
     * `{id}` E' LA COMPILAZIONE, non il modello. Quindi niente
     * `Permission::canView()`, che e' un controllo di visibilita' del modello e
     * per giunta risponde si' a qualunque super-admin: l'appartenenza si impone
     * nella WHERE del repository, esattamente come fa {@see delete()}.
     */
    public function scaricata(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if ($tid === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        if ($id === 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }

        if (!$this->repo->segnaScaricata($tid, $id)) {
            return Response::json(['error' => 'not_found_or_forbidden'], 404);
        }

        return Response::ok([
            'scade_fra_giorni' => \App\Services\Risdoc\ScadenzaDelleBozze::GRAZIA_GIORNI,
        ]);
    }

    private function nullable(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim($v);
        return $t === '' ? null : $t;
    }
}
