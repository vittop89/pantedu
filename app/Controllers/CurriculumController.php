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
            backupDir: Config::get('app.paths.storage') . '/backups',
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
                    // Lookup pubblico (studente in registrazione): aggrega le
                    // entry attive dell'istituto a prescindere dall'owner. Le
                    // classi sono per-docente (owner_user_id != NULL), quindi un
                    // listActive(..., null) — che filtra owner IS NULL — non
                    // troverebbe nulla anche se l'istituto ha classi.
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
        // curriculum del super-admin docente (cross-istituto), ma SOLO se esistono
        // sezioni publish_public (altrimenti niente da mostrare al pubblico).
        if ($userId === 0) {
            $saId = $this->publicSuperAdminId();
            if ($saId > 0 && (new \App\Repositories\SidebarSectionRepository())->publicSections() !== []) {
                return Response::json([
                    'ok' => true,
                    'institute_id' => null,
                    'scope' => 'public',
                    // Il super-admin possiede le stesse voci (stesso code) in piu'
                    // istituti: nei selettori della sidebar pubblica l'istituto NON
                    // e' selezionabile, quindi le entries vanno deduplicate per code
                    // e ridotte alle attive — altrimenti il guest vede doppioni.
                    'curriculum' => $this->dedupActiveByCode($this->svc->all(null, $saId)),
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
        return Response::json([
            'ok' => true,
            'institute_id' => $instituteId,
            'curriculum' => $cur,
        ]);
    }

    /**
     * Phase 25.Q.5 — risolve institute_id da codice MIUR (institutes.code).
     * Usato per lookup pubblico in registrazione studente.
     */
    /** WS4 — id del super-admin DOCENTE (per il curriculum pubblico guest). 0 se assente. */
    private function publicSuperAdminId(): int
    {
        if (!\App\Core\Database::isAvailable()) {
            return 0;
        }
        try {
            $id = \App\Core\Database::connection()->query(
                "SELECT id FROM users WHERE is_super_admin = 1 AND role = 'teacher' ORDER BY id LIMIT 1"
            )->fetchColumn();
            return (int)($id ?: 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Dedup per code + solo attive, per i selettori della sidebar PUBBLICA.
     * Il super-admin possiede le stesse voci (stesso code) in piu' istituti;
     * il guest non sceglie l'istituto, quindi i doppioni vanno collassati a una
     * sola opzione per code. Le inattive non vanno mostrate ai guest.
     *
     * @param array{indirizzi:list<array>,classi:list<array>,materie:list<array>} $curr
     * @return array{indirizzi:list<array>,classi:list<array>,materie:list<array>}
     */
    private function dedupActiveByCode(array $curr): array
    {
        $out = ['indirizzi' => [], 'classi' => [], 'materie' => []];
        foreach (array_keys($out) as $kind) {
            $seen = [];
            foreach (($curr[$kind] ?? []) as $row) {
                if (!($row['active'] ?? false)) {
                    continue;
                }
                $code = (string)($row['code'] ?? '');
                if ($code === '' || isset($seen[$code])) {
                    continue;
                }
                $seen[$code] = true;
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
     *  - fallback: firstInstituteId del teacher.
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
        $id = \App\Support\TeacherContextResolver::firstInstituteId($userId);
        return $id > 0 ? $id : null;
    }

    /** POST /api/teacher/curriculum/{kind} — body: code, label, group?, active?, institute_id */
    public function add(Request $req, array $params): Response
    {
        try {
            $kind  = $this->kind($params['kind'] ?? '');
            $instituteId = (int)($req->post['institute_id'] ?? 0);
            if (!$this->canModifyInstitute($instituteId)) {
                return Response::json(['ok' => false, 'error' => 'forbidden_institute'], 403);
            }
            $v = new Validator($req->post);
            $item = [
                'code'   => $v->string('code', regex: '#^[a-zA-Z0-9_\-]{1,16}$#'),
                'label'  => $v->string('label', max: 120),
                'group'  => $v->string('group', required: false, default: '', max: 60),
                'active' => ($req->post['active'] ?? 'true') === 'false' ? false : true,
            ];
            // G22.S22 — Tutti i kind (indirizzi/classi/materie) sono per-docente.
            // L'editor "Curriculum dell'istituto attivo" (profilo docente) è
            // l'UNICO consumer di questa route, e index() elenca SEMPRE le righe
            // dell'utente corrente (owner_user_id = utente), anche per i
            // super-admin: la catalog NULL-owner (anchor istituto) è dead-code
            // post-refactor, accessibile solo via index()?admin_legacy=1.
            // Quindi l'ADD deve creare righe di PROPRIETÀ dell'utente corrente,
            // non anchor NULL — altrimenti un super-admin (is_super_admin=1, che
            // ha hasAccess('admin')) crea righe invisibili nella propria lista e
            // poi collide su un duplicato anchor che non può vedere né cancellare.
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
                return Response::json(['ok' => false, 'error' => 'entry_not_found'], 404);
            }
            if (!$this->canModifyInstitute($entry['institute_id'] ?? 0)) {
                return Response::json(['ok' => false, 'error' => 'forbidden_institute'], 403);
            }
            // G22.S22 — Auth: per qualunque entry per-docente (owner non NULL),
            // solo l'owner (o admin) puo' modificare. Tutti i kind ora possono
            // avere owner_user_id (indirizzi/classi/materie).
            $entryOwner = $entry['owner_user_id'] ?? null;
            if (
                $entryOwner !== null
                && !Auth::hasAccess('admin')
                && $entryOwner !== (int)(Auth::user()['id'] ?? 0)
            ) {
                return Response::json(['ok' => false, 'error' => 'forbidden_owner'], 403);
            }
            $patch = [];
            foreach (['label', 'group', 'active', 'shared_with_pool'] as $f) {
                if (array_key_exists($f, $req->post)) {
                    $patch[$f] = $req->post[$f];
                }
            }
            return Response::json(['ok' => true, 'record' => $this->svc->updateById($entryId, $patch)]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** POST /api/teacher/curriculum/{id}/remove */
    public function remove(Request $req, array $params): Response
    {
        try {
            $entryId = (int)($params['id'] ?? 0);
            $entry = $this->svc->getById($entryId);
            if (!$entry) {
                return Response::json(['ok' => false, 'error' => 'entry_not_found'], 404);
            }
            if (!$this->canModifyInstitute($entry['institute_id'] ?? 0)) {
                return Response::json(['ok' => false, 'error' => 'forbidden_institute'], 403);
            }
            // G22.S22 — owner check su tutti i kind per-docente.
            $entryOwner = $entry['owner_user_id'] ?? null;
            if (
                $entryOwner !== null
                && !Auth::hasAccess('admin')
                && $entryOwner !== (int)(Auth::user()['id'] ?? 0)
            ) {
                return Response::json(['ok' => false, 'error' => 'forbidden_owner'], 403);
            }
            return Response::json(['ok' => $this->svc->removeById($entryId)]);
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

    /** @deprecated G22.S22 sostituito da resolveInstituteIdValidated().
     *  Mantenuto per retro-compatibilita' se altro codice lo invoca. */
    private function resolveCurrentInstitute(): ?int
    {
        $u = Auth::user();
        $tid = (int)($u['id'] ?? 0);
        $id = \App\Support\TeacherContextResolver::firstInstituteId($tid);
        return $id > 0 ? $id : null;
    }

    private function kind(string $input): string
    {
        if (!\in_array($input, CurriculumService::KINDS, true)) {
            throw new \RuntimeException('invalid_kind');
        }
        return $input;
    }
}
