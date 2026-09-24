<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\TeacherCredentialRepository;
use App\Support\TeacherContextResolver;
use Throwable;

/**
 * Gestione credenziali di accesso per studenti alle risorse del docente
 * (Phase 13 — `teacher_access_credentials`).
 *
 *   GET  /api/teacher/credentials                → lista coppie del docente + sue materie
 *   GET  /api/teacher/credentials/anteprima-etichetta → etichetta che il server comporrebbe
 *   POST /api/teacher/credentials                → crea (username, password, materie, aggiunta;
 *                                                  l'etichetta la compone il server, ADR-044)
 *   POST /api/teacher/credentials/{id}/etichetta → ricompone l'etichetta
 *   POST /api/teacher/credentials/{id}/delete    → cancella
 *   POST /api/teacher/credentials/{id}/toggle    → on/off
 *
 * Endpoint pubblico (auth in sessione richiesta come studente o guest):
 *   POST /api/access/student-login   → body: { username, password }
 *     Verifica credenziali; se ok, stampa nella session
 *     `fm_teacher_access` = { teacher_id, label, indirizzo?, classe? }
 *     così frontend può mostrare risorse di quel docente.
 *   POST /api/access/student-logout
 */
final class TeacherCredentialController
{
    private TeacherCredentialRepository $repo;
    // ADR-032 — il grant vive in ClassAccessGrant, che e' l'unico a saperlo
    // leggere lato studio; dal piano classi (C) e' un portachiavi: una lista.

    public function __construct(?TeacherCredentialRepository $repo = null)
    {
        $this->repo = $repo ?? new TeacherCredentialRepository();
    }

    /**
     * GET /api/teacher/credentials[?institute_id=N] — le credenziali del
     * docente e, per il modulo, le sue materie spuntate nell'istituto chiesto
     * (se è suo) o in quello in cui lavora: sono le sigle che può mettere
     * nell'etichetta (ADR-044).
     */
    public function index(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $iid = (int)($req->query['institute_id'] ?? 0);
        if ($iid <= 0 || !TeacherContextResolver::isLinkedToInstitute($tid, $iid)) {
            $iid = (int)(\App\Support\CurriculumLookup::instituteForTeacher($tid) ?? 0);
        }
        return Response::json([
            'ok'                  => true,
            'credentials'         => $this->repo->listForTeacher($tid),
            'institute_id'        => $iid > 0 ? $iid : null,
            'materie_disponibili' => $iid > 0 ? (new \App\Services\TeacherSubjectService())->forTeacher($tid, $iid) : [],
        ]);
    }

    public function create(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        try {
            // ADR-044 — l'etichetta la compone il server: un 'label' mandato dal
            // client non si legge più.
            $creata = $this->repo->crea($tid, [
                'username'     => $req->post['username']     ?? '',
                'password'     => $req->post['password']     ?? '',
                'indirizzo'    => $req->post['indirizzo']    ?? null,
                'classe'       => $req->post['classe']       ?? null,
                'institute_id' => $req->post['institute_id'] ?? null,
                'materie'      => $req->post['materie']      ?? null,
                'aggiunta'     => $req->post['aggiunta']     ?? null,
                // Piano classi, C — scadenza scelta dal docente (vuota = 31 agosto).
                'expires_at'   => $req->post['expires_at']   ?? null,
            ]);
            return Response::json([
                'ok'              => true,
                'id'              => $creata['id'],
                'label'           => $creata['label'],
                // L'username che il docente consegna alla classe: lo dice il
                // server, ripulito come è finito nel database, così il
                // pannello non deve rileggerlo dal modulo (che intanto si
                // svuota) né aspettare il giro dell'elenco.
                'access_username' => $creata['username'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            // Il messaggio del database resta nel registro: fino al 19 settembre
            // 2026 arrivava al client in 'detail', con l'id del docente e
            // l'username (un doppione dava «Duplicate entry '77-…'»).
            error_log('[credenziali] creazione non riuscita: ' . $e->getMessage());
            return Response::json(['error' => 'persist_failed'], 500);
        }
    }

    /**
     * GET /api/teacher/credentials/anteprima-etichetta — l'etichetta che il
     * server comporrebbe con classe, indirizzo, istituto, materie e aggiunta
     * (o, con id, per una credenziale esistente). Il modulo la mostra mentre
     * il docente sceglie: la composizione sta in un posto solo, qui.
     */
    public function labelPreview(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        try {
            $anteprima = $this->repo->anteprimaEtichetta($tid, [
                'id'           => $req->query['id']           ?? null,
                'classe'       => $req->query['classe']       ?? null,
                'indirizzo'    => $req->query['indirizzo']    ?? null,
                'institute_id' => $req->query['institute_id'] ?? null,
                'materie'      => $req->query['materie']      ?? null,
                'aggiunta'     => $req->query['aggiunta']     ?? null,
            ]);
            return Response::json(['ok' => true] + $anteprima);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /api/teacher/credentials/{id}/etichetta — ricompone l'etichetta con
     * le materie e l'aggiunta scelte ora. Gli studenti già entrati la vedono
     * alla richiesta successiva (ClassAccessGrant::revalidate la rilegge).
     */
    public function relabel(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        try {
            $label = $this->repo->setEtichetta(
                $tid,
                (int)($params['id'] ?? 0),
                $req->post['materie'] ?? null,
                $req->post['aggiunta'] ?? null
            );
            if ($label === null) {
                return Response::json(['error' => 'not_found'], 404);
            }
            return Response::json(['ok' => true, 'label' => $label]);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }

    public function delete(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $ok = $this->repo->delete($tid, (int)($params['id'] ?? 0));
        return Response::json(['ok' => $ok]);
    }

    public function toggle(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $active = !empty($req->post['active']);
        $ok = $this->repo->setActive($tid, (int)($params['id'] ?? 0), $active);
        return Response::json(['ok' => $ok, 'active' => $active]);
    }

    /**
     * Endpoint per studente: login alle risorse di un docente.
     *
     * Tenta in 2 fasi:
     *   1. teacher_access_credentials (codici condivisi che il docente
     *      ha generato per i propri studenti)
     *   2. fallback: Auth::attempt() su account utente standard
     *      (admin/teacher loggato → grant equivalente a "self-access").
     *      Permette al docente/admin di usare le proprie credenziali
     *      direttamente senza dover prima generare un access code.
     */
    public function studentLogin(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $username = trim((string)($req->post['username'] ?? ''));
        $password = (string)($req->post['password'] ?? '');
        if ($username === '' || $password === '') {
            return Response::json(['error' => 'missing_credentials'], 400);
        }

        // 1) Try teacher_access_credentials
        $row = $this->repo->verify($username, $password);
        if ($row) {
            return $this->enter($row, !empty($req->post['remember']));
        }

        // 2) Fallback: account utente reale (admin/teacher self-access)
        //
        // establishSession: false — 2026-09-01. Questo endpoint serve a dare
        // allo STUDENTE un grant di lettura sulle risorse del docente, ma
        // Auth::attempt apriva anche una sessione autenticata piena col ruolo
        // dell'account. Due conseguenze, entrambe indesiderate: una pagina
        // pensata per gli studenti concedeva privilegi da docente o da
        // amministratore, e — introdotta la verifica in due passaggi — sarebbe
        // bastato passare di qui con la sola password per aggirarla.
        [$user, $reason] = Auth::attempt($username, $password, null, null, establishSession: false);
        unset($reason);

        // Chi ha attivato il secondo fattore non entra da una scorciatoia che
        // non e' in grado di chiederglielo: passa dal login normale.
        if ($user && (new \App\Services\Security\TwoFactorPolicy())->enabledFor($user->username)) {
            return Response::json([
                'error'   => 'two_factor_required',
                'message' => 'Questo account usa la verifica in due passaggi: accedi da /login.',
            ], 401);
        }

        if ($user && in_array($user->role, ['administrator', 'teacher'], true)) {
            $tid = $this->lookupUserIdByUsername($user->username);
            $grant = [
                'teacher_id'   => $tid,
                'label'        => "Self-access ({$user->role}): {$user->username}",
                'indirizzo'    => null,
                'classe'       => null,
                'institute_id' => null,
                'source'       => 'user_account',
                'granted_at'   => time(),
            ];
            \App\Support\ClassAccessGrant::add($grant);
            return Response::json(['ok' => true, 'grant' => $grant, 'grants' => \App\Support\ClassAccessGrant::all()]);
        }

        return Response::json(['error' => 'invalid_credentials'], 401);
    }

    /**
     * GET /accesso-classe/qr/{token} — entra con il QR della credenziale
     * (permanente o a tempo). Piu' codici separati da virgola sono il
     * «pacchetto di classe»: un portachiavi intero in una scansione. Chi
     * scansiona non digita nulla e non lascia dati: come la password, solo
     * piu' comodo. Stesso limite di tentativi della password.
     */
    public function qrLogin(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::redirect('/accesso-classe?errore=servizio', 303);
        }
        $tokens = array_values(array_filter(array_map('trim', explode(',', (string)($params['token'] ?? '')))));
        $entrati = 0;
        foreach (array_slice($tokens, 0, 12) as $token) {
            $row = $this->repo->findByToken($token);
            if ($row !== null) {
                $this->enter($row, false);
                $entrati++;
            }
        }
        if ($entrati === 0) {
            return Response::redirect('/accesso-classe?errore=qr', 303);
        }
        return Response::redirect('/', 303);
    }

    /**
     * GET /accesso-classe/pacchetto.svg — QR del portachiavi corrente: chi lo
     * scansiona ottiene le stesse credenziali. E' un foglio che gira, come
     * oggi girano le password: nessun riferimento a chi lo mostra.
     */
    public function bundleSvg(Request $req): Response
    {
        $ids = [];
        foreach (\App\Support\ClassAccessGrant::all() as $g) {
            if ($g['credential_id'] !== null) {
                $ids[] = $g['credential_id'];
            }
        }
        $tokens = ($ids !== [] && $this->dbReady()) ? $this->repo->qrTokensFor($ids) : [];
        if ($tokens === []) {
            return Response::json(['error' => 'no_keychain'], 404);
        }
        return $this->qrDelCollegamento('/accesso-classe/qr/' . implode(',', $tokens));
    }

    /** POST /api/teacher/credentials/{id}/password — solo la password: l'username resta. */
    public function rotate(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        try {
            $ok = $this->repo->setPassword($tid, (int)($params['id'] ?? 0), (string)($req->post['password'] ?? ''));
            return Response::json(['ok' => $ok]);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }

    /** POST /api/teacher/credentials/{id}/expiry — scadenza YYYY-MM-DD, vuota = senza scadenza. */
    public function expiry(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        try {
            $date = trim((string)($req->post['expires_at'] ?? ''));
            $ok = $this->repo->setExpiry($tid, (int)($params['id'] ?? 0), $date === '' ? null : $date);
            return Response::json(['ok' => $ok]);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/teacher/credentials/{id}/qr.svg[?mode=temp] — il QR della
     * credenziale. `temp` emette un codice a tempo (dieci minuti) e ne
     * restituisce il QR: e' quello da proiettare in classe.
     */
    public function qrSvg(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $id  = (int)($params['id'] ?? 0);
        $row = $this->repo->find($tid, $id);
        if ($row === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        if ((string)($req->query['mode'] ?? '') === 'temp') {
            $temp  = $this->repo->issueTempToken($tid, $id, 10);
            $token = $temp['token'] ?? null;
        } else {
            $token = $row['qr_token'] ?? null;
            if ($token === null || $token === '') {
                $token = $this->repo->regenerateQrToken($tid, $id);
            }
        }
        if ($token === null || $token === '') {
            return Response::json(['error' => 'qr_unavailable'], 500);
        }
        return $this->qrDelCollegamento('/accesso-classe/qr/' . $token);
    }

    /** POST /api/teacher/credentials/{id}/qr/regenerate — il vecchio QR smette di valere. */
    public function qrRegenerate(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $token = $this->repo->regenerateQrToken($tid, (int)($params['id'] ?? 0));
        return Response::json(['ok' => $token !== null]);
    }

    /**
     * Entra con una credenziale verificata: grant nel portachiavi, contatore,
     * eventuale cookie «ricorda» fino alla scadenza della credenziale.
     *
     * @param array<string,mixed> $row riga di teacher_access_credentials
     */
    private function enter(array $row, bool $remember): Response
    {
        $grant = [
            'teacher_id'    => (int)$row['teacher_id'],
            'label'         => (string)$row['label'],
            'indirizzo'     => $row['indirizzo'],
            'classe'        => $row['classe'],
            'institute_id'  => !empty($row['institute_id']) ? (int)$row['institute_id'] : null,
            'credential_id' => (int)$row['id'],
            'source'        => 'teacher_access_credentials',
            'granted_at'    => time(),
        ];
        \App\Support\ClassAccessGrant::add($grant);
        $this->repo->touchUse((int)$row['id']);
        $grants = \App\Support\ClassAccessGrant::all();
        if ($remember) {
            $this->remember($grants);
        }
        return Response::json(['ok' => true, 'grant' => $grant, 'grants' => $grants]);
    }

    /**
     * Cookie «ricorda su questo dispositivo»: dura quanto la credenziale che
     * scade prima (per default il 31 agosto), mai oltre.
     *
     * @param list<array<string,mixed>> $grants
     */
    private function remember(array $grants): void
    {
        if (!\App\Support\ClassKeychainCookie::enabled()) {
            return;
        }
        $ids = [];
        foreach ($grants as $g) {
            if ($g['credential_id'] !== null) {
                $ids[] = $g['credential_id'];
            }
        }
        if ($ids === []) {
            return;
        }
        $until = $this->repo->earliestExpiry($ids) ?? TeacherCredentialRepository::defaultExpiry();
        \App\Support\ClassKeychainCookie::issue($ids, new \DateTimeImmutable($until . ' 23:59:59'));
    }

    private function svg(string $text): Response
    {
        return new Response(\App\Services\Security\QrCode::svg($text, 6, 4), 200, [
            'Content-Type'  => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Il QR di un collegamento del sito. La radice è `app.url` e basta
     * (IndirizzoPubblico, 23/9/2026): fino a quel giorno, senza `app.url`, si
     * prendeva l'intestazione `Host` della richiesta. Senza radice il QR non
     * si fa — il collegamento è tutto quello che contiene — e lo si dice con
     * un errore, non con un codice che porta altrove.
     */
    private function qrDelCollegamento(string $percorso): Response
    {
        try {
            $radice = \App\Support\IndirizzoPubblico::radice('credenziali_di_classe');
        } catch (\App\Support\IndirizzoPubblicoMancante) {
            return Response::json(['error' => 'qr_unavailable'], 500);
        }
        return $this->svg($radice . $percorso);
    }

    /** POST /api/access/student-logout — una credenziale (credential_id) o tutte. */
    public function studentLogout(Request $req): Response
    {
        $cid = (int)($req->post['credential_id'] ?? 0);
        if ($cid > 0) {
            \App\Support\ClassAccessGrant::remove($cid);
        } else {
            \App\Support\ClassAccessGrant::clear();
            \App\Support\ClassKeychainCookie::clear();
        }
        $grants = \App\Support\ClassAccessGrant::all();
        if ($grants === []) {
            \App\Support\ClassKeychainCookie::clear();
        }
        return Response::json(['ok' => true, 'grants' => $grants]);
    }

    /** GET /api/access/status — il portachiavi, il primo grant e gli avvisi (consumati). */
    public function studentStatus(Request $req): Response
    {
        $grants = \App\Support\ClassAccessGrant::all();
        return Response::json([
            'ok'               => true,
            'grant'            => $grants[0] ?? null,
            'grants'           => $grants,
            'notices'          => \App\Support\ClassAccessGrant::notices(),
            'remember_enabled' => \App\Support\ClassKeychainCookie::enabled(),
        ]);
    }

    private function dbReady(): bool
    {
        return (bool)Config::get('database.enabled') && Database::isAvailable();
    }

    private function lookupUserIdByUsername(string $username): int
    {
        return \App\Support\TeacherContextResolver::userIdFromUsername($username);
    }
}
