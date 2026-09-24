<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\CurriculumService;
use App\Support\Validator;
use Throwable;

/**
 * G22.S15.bis Fase 5+ — Curriculum scope per istituto.
 *
 * Routes:
 *   GET  /curriculum
 *        Ritorna catalog dell'istituto attivo del docente loggato +
 *        legacy entries (institute_id NULL). Se admin globale: tutto.
 *   GET  /api/teacher/curriculum?institute_id=N
 *        Catalog di uno specifico istituto (verifica collegamento).
 *   POST /api/teacher/curriculum/{kind}
 *        body: code, label, group?, active?, institute_id
 *        Add entry per quel istituto. Auth: docente collegato a institute_id.
 *   POST /api/teacher/curriculum/{id}/update
 *        body: label?, group?, active?
 *        Update entry; auth: collegato all'istituto dell'entry.
 *   POST /api/teacher/curriculum/{id}/remove
 *        Auth: collegato all'istituto. Cascade su pivot.
 */
final class CurriculumController
{
    private CurriculumService $svc;

    public function __construct(?CurriculumService $svc = null)
    {
        $this->svc = $svc ?? new CurriculumService(
            jsonPath:  Config::get('app.paths.storage') . '/data/curriculum.json',
        );
    }

    /**
     * GET /curriculum — public read, returns active entries only.
     * Scope: catalog dell'istituto attivo del docente (primo collegato);
     * admin globale: tutte le entries.
     */
    public function index(Request $req): Response
    {
        // G22.S22 — Tutti i kind sono per-docente: anche un super-admin che è
        // anche teacher vede solo le SUE entries quando accede via
        // /area-docente/profilo. La vista "globale" (NULL-owner legacy
        // catalog) è dead code post-refactor (entries non più usate dal
        // sidebar selector, dal pool, dalla sidepage). Per accedere alla
        // catalog legacy: ?admin_legacy=1 (esplicito, opt-in).
        $userId = (int)(Auth::user()['id'] ?? 0);
        $reqInst = (int)($req->query['institute_id'] ?? 0);
        $scopeAll = (($req->query['scope'] ?? '') === 'all')
                 || (($req->query['all_institutes'] ?? '') === '1');
        $adminLegacy = Auth::hasAccess('admin')
                    && (($req->query['admin_legacy'] ?? '') === '1');

        // Phase 25.Q.5 — lookup pubblico per registrazione studente:
        // /curriculum?institute_code=XXPS00000A → ritorna classi dell'istituto.
        // No auth required (studente è in registrazione).
        $reqCode = trim((string)($req->query['institute_code'] ?? ''));
        if ($reqCode !== '' && $userId === 0) {
            $iid = $this->resolveInstituteByCode($reqCode);
            if ($iid !== null) {
                return Response::json([
                    'ok' => true,
                    'institute_id' => $iid,
                    'institute_code' => $reqCode,
                    // Lookup pubblico (studente in registrazione): le voci
                    // attive del vocabolario dell'istituto (ADR-035).
                    'curriculum' => [
                        'indirizzi' => $this->svc->listActiveForInstitute('indirizzi', $iid),
                        'classi'    => $this->svc->listActiveForInstitute('classi', $iid),
                        'materie'   => $this->svc->listActiveForInstitute('materie', $iid),
                    ],
                ]);
            }
            return Response::json([
                'ok' => true,
                'institute_id' => null,
                'institute_code' => $reqCode,
                'curriculum' => ['indirizzi' => [], 'classi' => [], 'materie' => []],
                'hint' => 'institute_not_found_or_no_classes',
            ]);
        }

        // WS4 — guest (sidebar pubblica senza login): popola i selettori con il
        // curriculum del docente che pubblica in rete (cross-istituto), ma SOLO se
        // esistono sezioni publish_public (altrimenti niente da mostrare al
        // pubblico). Il docente lo decide PublicContentPolicy: fino al 15/9/2026
        // qui c'era una copia della regola.
        if ($userId === 0) {
            $saId = \App\Services\Study\PublicContentPolicy::proprietarioPubblico();
            if ($saId > 0 && \App\Services\Study\PublicContentPolicy::sezioniPubbliche() !== []) {
                return Response::json([
                    'ok' => true,
                    'institute_id' => null,
                    'scope' => 'public',
                    // Il super-admin possiede le stesse voci (stesso code) in piu'
                    // istituti: nei selettori della sidebar pubblica l'istituto NON
                    // e' selezionabile, quindi le entries vanno deduplicate per code
                    // e ridotte alle attive — altrimenti il guest vede doppioni.
                    'curriculum' => self::senzaDoppioni($this->svc->all(null, $saId)),
                ]);
            }
        }

        if ($scopeAll) {
            // Catalog del docente cross-institute (ramo (c) di loadFromDb).
            return Response::json([
                'ok' => true,
                'institute_id' => null,
                'scope' => 'all_institutes',
                'curriculum' => $this->svc->all(null, $userId),
            ]);
        }

        $instituteId = $this->resolveInstituteIdValidated($reqInst, $userId);
        if ($adminLegacy) {
            // Admin con opt-in: ritorna entries NULL-owner per istituto
            // (utile per cleanup tooling). Non usato dall'UI standard.
            return Response::json([
                'ok' => true,
                'institute_id' => $instituteId,
                'scope' => 'admin_legacy',
                'curriculum' => $this->svc->all($instituteId, null),
            ]);
        }
        // include_inactive=1 → catalog COMPLETO (attive + disattivate) per
        // l'EDITOR "Curriculum dell'istituto attivo": altrimenti, deselezionando
        // "Attiva" la riga sparirebbe dalla tabella e non sarebbe riattivabile.
        // I select della sidebar usano il default (solo attive) o filtrano client-side.
        $includeInactive = ($req->query['include_inactive'] ?? '') === '1';
        $full = $this->svc->all($instituteId, $userId); // attive + inattive
        $cur = $includeInactive
            ? $full
            : [
                'indirizzi' => array_values(array_filter($full['indirizzi'], fn($r) => (bool)($r['active'] ?? false))),
                'classi'    => array_values(array_filter($full['classi'], fn($r) => (bool)($r['active'] ?? false))),
                'materie'   => array_values(array_filter($full['materie'], fn($r) => (bool)($r['active'] ?? false))),
            ];
        // Il vocabolario della SCUOLA, attivo, per i tre kind: sono le voci che
        // CurriculumService::add() accetta da un docente. Il profilo le mostra
        // come caselle da spuntare (ADR-035), le classi con il loro corso, così
        // il rifiuto di una sigla inventata non arriva dopo averla digitata.
        $vocabolario = [];
        // ADR-041 — le sezioni che la modalità dell'istituto non ammette per chi
        // guarda non si offrono: resterebbe un rifiuto dopo averle scelte.
        $sezioni = new \App\Services\SezioniDeiDocenti();
        foreach ($this->svc->all($instituteId, null) as $kind => $voci) {
            $vocabolario[$kind] = array_values(array_map(
                static fn(array $r): array => [
                    'code'      => $r['code'],
                    'label'     => $r['label'],
                    'group'     => $r['group'] ?? null,
                    'indirizzo' => $r['indirizzo'] ?? null,
                ],
                array_filter($voci, static fn(array $r): bool => (bool)($r['active'] ?? false)
                    && ($kind !== 'classi' || $userId <= 0 || $instituteId === null
                        || $sezioni->ammessa($userId, $instituteId, (string)$r['code'], isset($r['indirizzo']) ? (string)$r['indirizzo'] : null)))
            ));
        }
        return Response::json([
            'ok' => true,
            'institute_id' => $instituteId,
            'curriculum' => $cur,
            'institute_vocabolario' => $vocabolario,
        ]);
    }

    /**
     * Phase 25.Q.5 — risolve institute_id da codice MIUR (institutes.code).
     * Usato per lookup pubblico in registrazione studente.
     */
    /**
     * Senza doppioni + solo attive, per i selettori della sidebar PUBBLICA.
     * Il docente che pubblica in rete possiede le stesse voci (stesso code) in
     * piu' istituti; il guest non sceglie l'istituto, quindi i doppioni vanno
     * collassati a una sola opzione. Le inattive non vanno mostrate ai guest.
     *
     * Una classe e' doppia solo con lo stesso codice **e lo stesso indirizzo**:
     * «1A» di Musicale e «1A» di Scientifico sono due classi. Fino al 15/9/2026
     * contava il solo codice, e la seconda spariva (misurato sulle voci del
     * docente in produzione).
     *
     * @param array{indirizzi?:list<array>,classi?:list<array>,materie?:list<array>} $curr
     * @return array{indirizzi:list<array>,classi:list<array>,materie:list<array>}
     */
    public static function senzaDoppioni(array $curr): array
    {
        $out = ['indirizzi' => [], 'classi' => [], 'materie' => []];
        foreach (array_keys($out) as $kind) {
            $seen = [];
            foreach (($curr[$kind] ?? []) as $row) {
                if (!($row['active'] ?? false)) {
                    continue;
                }
                $code = (string)($row['code'] ?? '');
                $chiave = $kind === 'classi' ? $code . '|' . (string)($row['indirizzo'] ?? '') : $code;
                if ($code === '' || isset($seen[$chiave])) {
                    continue;
                }
                $seen[$chiave] = true;
                $out[$kind][] = $row;
            }
        }
        return $out;
    }

    private function resolveInstituteByCode(string $code): ?int
    {
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,40}$/i', $code)) {
            return null;
        }
        try {
            $stmt = \App\Core\Database::connection()->prepare(
                'SELECT id FROM institutes WHERE code = ? AND active = 1 LIMIT 1'
            );
            $stmt->execute([$code]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int)$id : null;
        } catch (\Throwable $_) {
            return null;
        }
    }

    /**
     * G22.S22 — Risolve institute_id richiesto:
     *  - admin: accetta qualunque institute_id;
     *  - teacher: valida che institute_id sia tra i suoi teacher_institutes;
     *  - fallback: la casa dei file privati del docente
     *    (privateFilesInstituteId). Il client manda institute_id quando c'è
     *    il selettore delle scuole; senza, il docente ne ha una sola e casa e
     *    istituto attivo coincidono (23/9/2026, A-7: lasciato com'era).
     */
    private function resolveInstituteIdValidated(int $reqInst, int $userId): ?int
    {
        if (Auth::hasAccess('admin') && $reqInst > 0) {
            return $reqInst;
        }
        if ($userId <= 0) {
            return null;
        }
        if ($reqInst > 0) {
            $stmt = \App\Core\Database::connection()->prepare(
                'SELECT 1 FROM teacher_institutes WHERE user_id=? AND institute_id=? LIMIT 1'
            );
            $stmt->execute([$userId, $reqInst]);
            if ($stmt->fetchColumn()) {
                return $reqInst;
            }
        }
        $id = \App\Support\TeacherContextResolver::privateFilesInstituteId($userId);
        return $id > 0 ? $id : null;
    }

    /** POST /api/teacher/curriculum/{kind} — body: code, label, group?, active?, institute_id */
    public function add(Request $req, array $params): Response
    {
        try {
            $kind  = $this->kind($params['kind'] ?? '');
            $instituteId = (int)($req->post['institute_id'] ?? 0);
            if (!$this->canModifyInstitute($instituteId)) {
                return Response::fail('forbidden_institute', 403);
            }
            $v = new Validator($req->post);
            $item = [
                'code'   => $v->string('code', regex: '#^[a-zA-Z0-9_\-]{1,16}$#'),
                'label'  => $v->string('label', max: 120),
                'group'  => $v->string('group', required: false, default: '', max: 60),
                'active' => ($req->post['active'] ?? 'true') === 'false' ? false : true,
                // ADR-042 — un anno si spunta nel suo corso.
                'indirizzo' => $v->string('indirizzo', required: false, default: '', max: 16),
            ];
            // ADR-035 — l'editor del profilo è l'unico consumer di questa rotta,
            // e lì si agisce sempre come docente: aggiungere vuol dire SPUNTARE
            // una voce che la scuola ha (anche per un super-admin, che sul
            // proprio profilo è un docente come gli altri). Le voci nuove del
            // vocabolario nascono dal pannello del catalogo, non da qui.
            $ownerUserId = (int)(Auth::user()['id'] ?? 0) ?: null;
            $record = $this->svc->add($kind, $item, $instituteId, $ownerUserId);
            return Response::json(['ok' => true, 'record' => $record]);
        } catch (Throwable $e) {
            // G22.S22 — messaggi più chiari (Italian) per errori comuni.
            $human = match ($e->getMessage()) {
                'invalid_code_for_indirizzi',
                'invalid_code_for_materie'   => 'Codice non valido: usa 3-6 lettere MAIUSCOLE (es. MAT, FIS).',
                'invalid_code_for_classi'    => 'Codice classe non valido: usa un numero 1-9 con suffisso opzionale (es. 1, 2B).',
                'invalid_label'              => 'Etichetta vuota o troppo lunga (max 120 caratteri).',
                'invalid_group'              => 'Gruppo troppo lungo (max 60 caratteri).',
                'duplicate_code'             => 'Esiste già una voce con questo codice in questo istituto.',
                'institute_id_required'      => 'Manca l\'istituto: seleziona l\'istituto attivo prima di aggiungere.',
                'not_linked_to_institute'    => 'Non sei collegato a questo istituto: collegati dal Profilo prima di aggiungere.',
                'unknown_indirizzi_for_institute' => 'Questo indirizzo non esiste in questo istituto. '
                    . 'Gli indirizzi li definisce la scuola: scegline uno fra quelli disponibili, '
                    . 'oppure chiedi a un amministratore di aggiungerlo. Inventare una sigla nuova '
                    . 'creerebbe un doppione che gli studenti non saprebbero distinguere.',
                'unknown_classi_for_institute' => 'Questa classe non esiste in questo istituto. '
                    . 'Le sezioni arrivano dai dati MIUR importati dalla scuola: se manca la tua, '
                    . 'chiedi a un amministratore di aggiornarle. Una sezione inventata non '
                    . 'corrisponde a nessuna classe reale, e i tuoi studenti non la vedrebbero.',
                'anno_senza_indirizzo'       => 'Un anno si spunta dentro un indirizzo: la «3» dello scientifico e la «3» dell\'artistico sono due classi diverse.',
                'sezione_non_ammessa'        => 'Questa classe non la puoi usare: in questo istituto anni e sezioni li usa solo il docente che ne ha l\'incarico (con «nessuno», le sezioni non le usa nessuno). Chiedi l\'incarico a un amministratore.',
                'unknown_materie_for_institute' => 'Questa materia non esiste in questo istituto. '
                    . 'Le materie le definisce la scuola: se manca la tua, chiedi a un '
                    . 'amministratore di aggiungerla. Una materia inventata resta solo tua, e '
                    . 'i contenuti che le agganci non compaiono nel catalogo di nessun altro.',
                default                      => $e->getMessage(),
            };
            return Response::json(['ok' => false, 'error' => $human, 'error_code' => $e->getMessage()], 400);
        }
    }

    /** POST /api/teacher/curriculum/{id}/update */
    public function update(Request $req, array $params): Response
    {
        try {
            $entryId = (int)($params['id'] ?? 0);
            $entry = $this->svc->getById($entryId);
            if (!$entry) {
                return Response::fail('entry_not_found', 404);
            }
            if (!$this->canModifyInstitute($entry['institute_id'] ?? 0)) {
                return Response::fail('forbidden_institute', 403);
            }
            // ADR-035 — dal profilo si modifica la PROPRIA spunta (stato,
            // condivisione, etichetta personale), mai la voce della scuola: un
            // docente che rinomina «Scienze» in «Scienze naturali» cambia ciò
            // che vede lui. `group` è della scuola e qui non si tocca.
            $patch = [];
            foreach (['label', 'active', 'shared_with_pool'] as $f) {
                if (array_key_exists($f, $req->post)) {
                    $patch[$f] = $req->post[$f];
                }
            }
            $me = (int)(Auth::user()['id'] ?? 0);
            return Response::json(['ok' => true, 'record' => $this->svc->updateById($entryId, $patch, $me)]);
        } catch (Throwable $e) {
            $human = $e->getMessage() === 'sezione_non_ammessa' ? 'Questa sezione non la puoi usare: in questo istituto le sezioni le vede solo il docente che ne ha l\'incarico, oppure nessuno. Usa l\'anno (per esempio «2» vale per tutte le seconde), o chiedi l\'incarico a un amministratore.' : $e->getMessage();
            return Response::json(['ok' => false, 'error' => $human, 'error_code' => $e->getMessage()], 400);
        }
    }

    /** POST /api/teacher/curriculum/{id}/remove */
    public function remove(Request $req, array $params): Response
    {
        try {
            $entryId = (int)($params['id'] ?? 0);
            $entry = $this->svc->getById($entryId);
            if (!$entry) {
                return Response::fail('entry_not_found', 404);
            }
            if (!$this->canModifyInstitute($entry['institute_id'] ?? 0)) {
                return Response::fail('forbidden_institute', 403);
            }
            // ADR-035 — si toglie la PROPRIA spunta: la voce della scuola resta,
            // e i contenuti che ci puntano non perdono la categoria.
            $me = (int)(Auth::user()['id'] ?? 0);
            return Response::json(['ok' => $this->svc->removeById($entryId, $me)]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Verifica permesso modifica curriculum di un istituto:
     *  - admin globale → sempre OK
     *  - teacher → solo se collegato (teacher_institutes pivot)
     *  - institute_id NULL (legacy globali) → solo admin
     */
    private function canModifyInstitute(?int $instituteId): bool
    {
        if (Auth::hasAccess('admin')) {
            return true;
        }
        if ($instituteId === null || $instituteId === 0) {
            return false;
        }
        $u = Auth::user();
        $tid = (int)($u['id'] ?? 0);
        return \App\Support\TeacherContextResolver::isLinkedToInstitute($tid, $instituteId);
    }

    private function kind(string $input): string
    {
        if (!\in_array($input, CurriculumService::KINDS, true)) {
            throw new \RuntimeException('invalid_kind');
        }
        return $input;
    }
}
