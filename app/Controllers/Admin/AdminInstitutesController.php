<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\InstituteRepository;
use App\Services\Audit\ActivityLogger;
use App\Services\MiurAdozioniImporter;
use App\Services\MiurSchoolsService;
use App\Support\MiurCurriculumAlias;

/**
 * Phase 25.Q — Onboarding wizard nuovo istituto (super-admin only).
 *
 * Routes:
 *   GET  /admin/institutes        → lista istituti
 *   GET  /admin/institutes/new    → form wizard
 *   POST /admin/institutes/new    → crea istituto + admin di istituto iniziale
 *
 * Crea atomicamente:
 *   1. Riga in `institutes` (code, name, city, region, header_label)
 *   2. Riga in `users` con role='admin' + admin_institute_id = N + password
 *      random one-time (mostrata UNA SOLA VOLTA al super-admin).
 *
 * L'admin di istituto userà poi le proprie credenziali per gestire i propri
 * docenti via `/admin/registrations` (scope automaticamente filtrato).
 */
final class AdminInstitutesController
{
    public function index(Request $req): Response
    {
        $repo = new InstituteRepository();
        $rows = $repo->listWithUsage();
        $miur = MiurSchoolsService::fromConfig();
        $view = View::default();
        $body = $view->render('admin/institutes_index', [
            'rows'  => $rows,
            'flash' => $_SESSION['flash'] ?? null,
            'csrf'  => Csrf::token(),
            'miur_sources' => $miur->sourcesStatus(),
            'miur_index'   => $miur->indexStatus(),
            // Se il registro alias non si carica, l'import propone sigle
            // derivate al posto di quelle decise e i doppioni nascono in
            // silenzio: meglio vederlo prima di caricare 50 MB.
            'alias_status' => MiurCurriculumAlias::status(),
        ]);
        unset($_SESSION['flash']);
        return Response::html($view->render('layout/shell', [
            'title' => 'Istituti — Admin',
            'body'  => $body,
        ]));
    }

    /** GET /admin/institutes/new — form. */
    public function newForm(Request $req): Response
    {
        $view = View::default();
        $body = $view->render('admin/institutes_new', [
            'csrf'  => Csrf::token(),
            'error' => $_SESSION['institute_new_error'] ?? null,
            'old'   => $_SESSION['institute_new_old']   ?? [],
        ]);
        unset($_SESSION['institute_new_error'], $_SESSION['institute_new_old']);
        return Response::html($view->render('layout/shell', [
            'title' => 'Nuovo istituto — Admin',
            'body'  => $body,
        ]));
    }

    /** POST /admin/institutes/new — crea istituto + admin iniziale. */
    public function create(Request $req): Response
    {
        $post = $req->post;
        $code  = trim((string)($post['code']  ?? ''));
        $name  = trim((string)($post['name']  ?? ''));
        $city  = trim((string)($post['city']  ?? ''));
        $region = trim((string)($post['region'] ?? ''));
        $header = trim((string)($post['header_label'] ?? ''));
        $adminUsername = trim((string)($post['admin_username'] ?? ''));
        $adminEmail    = trim((string)($post['admin_email']    ?? ''));
        $adminFirst    = trim((string)($post['admin_first_name'] ?? ''));
        $adminLast     = trim((string)($post['admin_last_name']  ?? ''));

        $errs = [];
        // Codice MIUR vero: due lettere di provincia + 8 alfanumerici. Un codice
        // inventato non aggancia i dataset ministeriali, quindi quella scuola
        // non avrebbe mai indirizzi, sezioni ne' materie — e nessuno capirebbe
        // perche'. Il codice si trova sull'anagrafica scuole caricata qui sotto.
        if ($code === '' || !InstituteRepository::isRealMiurCode($code)) {
            $errs[] = 'Codice meccanografico MIUR non valido (es. XXPS00000A: '
                . 'due lettere di provincia + 8 caratteri). Lo trovi cercando la scuola '
                . 'nella anagrafica scuole MIUR. Un codice inventato non si aggancia ai dati '
                . 'ministeriali, e la scuola resterebbe senza indirizzi, sezioni e materie.';
        }
        if ($name === '') {
            $errs[] = 'Nome istituto richiesto.';
        }
        if ($adminUsername === '' || !preg_match('/^[a-z0-9._-]{3,32}$/', $adminUsername)) {
            $errs[] = 'Username admin non valido (3-32 char minuscole/numeri/._-).';
        }
        if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errs[] = 'Email admin non valida.';
        }

        if ($errs) {
            $_SESSION['institute_new_error'] = implode(' ', $errs);
            $_SESSION['institute_new_old']   = $post;
            return Response::redirect('/admin/institutes/new');
        }

        if (!Database::isAvailable()) {
            $_SESSION['institute_new_error'] = 'DB non disponibile.';
            return Response::redirect('/admin/institutes/new');
        }

        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();

            // 1. Insert / upsert institutes (canonico: dedup per stessa scuola)
            $repo = new InstituteRepository();
            $iid = $repo->upsertCanonical($code, $name, $city !== '' ? $city : null, $region !== '' ? $region : null);
            // Aggiorna header_label se fornito (upsert minimal non lo include)
            if ($header !== '') {
                $upd = $pdo->prepare('UPDATE institutes SET header_label = ? WHERE id = ?');
                $upd->execute([$header, $iid]);
            }

            // 2. Insert admin user con password random one-time
            $plainPassword = $this->generatePassword();
            $hash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            // Audit 25.R.31 (L7) — must_change_password=1: l'admin iniziale deve
            // cambiare la password one-time al primo login (enforced da AuthMiddleware).
            $stmt = $pdo->prepare(
                'INSERT INTO users
                    (username, role, first_name, last_name, email, password_hash,
                     must_change_password, status, active, admin_institute_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $adminUsername, 'admin', $adminFirst, $adminLast,
                $adminEmail, $hash,
                1, 'approved', 1, $iid,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $_SESSION['institute_new_error'] = 'Errore creazione: ' . $e->getMessage();
            $_SESSION['institute_new_old']   = $post;
            return Response::redirect('/admin/institutes/new');
        }

        // Mostra credenziali UNA SOLA VOLTA
        $_SESSION['flash'] = [
            'type'    => 'success',
            'title'   => "Istituto «{$name}» creato.",
            'message' => "Admin iniziale: {$adminUsername} — password one-time: <code>{$plainPassword}</code> (annota e cambia al primo login).",
        ];
        return Response::redirect('/admin/institutes');
    }

    /**
     * POST /admin/institutes/{id}/compilation-storage — le compilazioni dei
     * modelli istituzionali dei docenti di questo Istituto si salvano sul
     * server (storage=1) o restano nel browser del docente (storage=0).
     *
     * 2026-09-04 — e' la risposta tecnica a un'obiezione di gestione
     * documentale: piano annuale e relazione finale sono atti dell'Istituto e
     * parlano di studenti. Il DPO di un Istituto puo' chiedere che per i suoi
     * docenti la bozza non resti su questo server; l'amministratore lo imposta
     * qui, e CompilationController::save rifiuta il salvataggio per quei
     * docenti (CompilationStoragePolicy). Le compilazioni gia' salvate restano
     * leggibili e cancellabili: non si distrugge nulla di nascosto.
     */
    public function toggleCompilationStorage(Request $req, array $params): Response
    {
        $id = (int)($params['id'] ?? 0);
        $enabled = (string)($req->post['storage'] ?? '') === '1';
        if ($id <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'title' => 'Istituto non valido.'];
            return Response::redirect('/admin/institutes');
        }
        if (!Database::isAvailable()) {
            $_SESSION['flash'] = ['type' => 'error', 'title' => 'DB non disponibile.'];
            return Response::redirect('/admin/institutes');
        }
        $repo = new InstituteRepository();
        $inst = $repo->findById($id);
        if (!$inst) {
            $_SESSION['flash'] = ['type' => 'error', 'title' => 'Istituto inesistente.'];
            return Response::redirect('/admin/institutes');
        }
        try {
            $ok = $repo->setCompilationStorage($id, $enabled);
        } catch (\Throwable $e) {
            error_log('[admin/institutes/compilation-storage] ' . $e->getMessage());
            $_SESSION['flash'] = ['type' => 'error', 'title' => 'Impostazione non disponibile: migration 103 non applicata.'];
            return Response::redirect('/admin/institutes');
        }
        ActivityLogger::event(
            $enabled ? 'institute_compilation_storage_on' : 'institute_compilation_storage_off',
            subjectType: 'institute',
            subjectId:   (string)$id,
            details:     ['code' => (string)($inst['code'] ?? ''), 'name' => (string)($inst['name'] ?? '')],
            outcome:     $ok ? 'ok' : 'noop',
        );
        \App\Core\PrivilegedAccessLogger::log(
            'institute_compilation_storage',
            'institute',
            (string)$id,
            sprintf(
                'compilazioni dei modelli istituzionali per «%s»: %s',
                (string)($inst['name'] ?? $id),
                $enabled ? 'salvate sul server' : 'solo nel browser del docente (indicazione del DPO dell\'Istituto)'
            ),
        );
        $_SESSION['flash'] = [
            'type'  => 'success',
            'title' => sprintf(
                'Istituto «%s»: compilazioni dei modelli istituzionali %s.',
                (string)($inst['name'] ?? $id),
                $enabled ? 'salvate sul server' : 'solo nel browser del docente'
            ),
            'message' => $enabled
                ? 'I docenti tornano a salvare le compilazioni, cifrate con la loro chiave.'
                : 'Nuovi salvataggi rifiutati: la bozza resta nel browser del docente, che esporta il PDF. Le compilazioni già salvate restano leggibili e cancellabili.',
        ];
        return Response::redirect('/admin/institutes');
    }

    /**
     * POST /admin/institutes/{id}/active — sospende o riattiva un istituto.
     *
     * Sospendere non cancella e non scollega: toglie l'istituto dalle liste in
     * cui lo si SCEGLIE (registrazione, link docente, selettori admin). Chi c'e'
     * gia' dentro continua a lavorare. Serve per i gusci — un istituto senza
     * indirizzi e' scegliibile in registrazione ma non mostra niente a chi ci
     * si iscrive — e per i doppioni che non si vogliono perdere.
     */
    public function toggleActive(Request $req, array $params): Response
    {
        $id = (int)($params['id'] ?? 0);
        $attivo = (string)($req->post['active'] ?? '') === '1';
        if ($id <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'title' => 'Istituto non valido.'];
            return Response::redirect('/admin/institutes');
        }
        if (!Database::isAvailable()) {
            $_SESSION['flash'] = ['type' => 'error', 'title' => 'DB non disponibile.'];
            return Response::redirect('/admin/institutes');
        }

        $repo = new InstituteRepository();
        $inst = $repo->findById($id);
        if (!$inst) {
            $_SESSION['flash'] = ['type' => 'error', 'title' => 'Istituto inesistente.'];
            return Response::redirect('/admin/institutes');
        }
        $ok = $repo->setActive($id, $attivo);
        ActivityLogger::event(
            $attivo ? 'institute_reactivated' : 'institute_suspended',
            subjectType: 'institute',
            subjectId:   (string)$id,
            details:     ['code' => (string)($inst['code'] ?? ''), 'name' => (string)($inst['name'] ?? '')],
            outcome:     $ok ? 'ok' : 'noop',
        );
        $_SESSION['flash'] = [
            'type'  => 'success',
            'title' => sprintf(
                'Istituto «%s» %s.',
                (string)($inst['name'] ?? $id),
                $attivo ? 'riattivato' : 'sospeso'
            ),
            'message' => $attivo
                ? 'Torna scegliibile in registrazione e nei selettori.'
                : 'Non e\' piu\' scegliibile in registrazione. Chi era gia\' iscritto continua a lavorare.',
        ];
        return Response::redirect('/admin/institutes');
    }

    /**
     * POST /admin/institutes/miur/update — carica le anagrafiche scuole MIUR
     * (file JSON-LD scaricati dal catalogo opendata dati.istruzione.it) e
     * rigenera l'indice di ricerca.
     *
     * Upload (multipart): statali_file, paritarie_file (almeno uno). Solo JSON-LD
     * con "@graph". super_admin only (middleware di gruppo). Le sorgenti vengono
     * unite nell'indice. NB: richiede upload_max_filesize/post_max_size adeguati
     * (statali ~51 MB, paritarie ~8 MB) + nginx client_max_body_size.
     */
    public function miurUpdate(Request $req): Response
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M'); // rebuild json_decode del JSON ~50MB
        $storage = (string)Config::get('app.paths.storage');
        $dataDir = $storage . '/data';
        if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
            return Response::json(['ok' => false, 'error' => 'data_dir_failed'], 500);
        }

        $targets = [
            'statali'   => $dataDir . '/scuole_miur.json',
            'paritarie' => $dataDir . '/scuole_miur_paritarie.json',
        ];
        $loaded = [];
        foreach (['statali', 'paritarie'] as $k) {
            $f = $_FILES[$k . '_file'] ?? null;
            $errCode = is_array($f) ? (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            if ($errCode === UPLOAD_ERR_NO_FILE) {
                continue; // campo non compilato
            }
            if ($errCode !== UPLOAD_ERR_OK) {
                return Response::json([
                    'ok' => false, 'error' => 'upload_error',
                    'field' => $k . '_file', 'detail' => $this->uploadErrMsg($errCode),
                ], 400);
            }
            $tmp = (string)($f['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                return Response::json(['ok' => false, 'error' => 'not_uploaded', 'field' => $k . '_file'], 400);
            }
            if ($vErr = $this->validateMiurJsonFile($tmp)) {
                return Response::json(['ok' => false, 'error' => $vErr, 'field' => $k . '_file'], 400);
            }
            $dest = $targets[$k];
            if (is_file($dest)) {
                @rename($dest, $dest . '.prev'); // rollback rapido se serve
            }
            if (!move_uploaded_file($tmp, $dest)) {
                return Response::json(['ok' => false, 'error' => 'move_failed', 'field' => $k . '_file'], 500);
            }
            @chmod($dest, 0644);
            $loaded[$k] = (int)($f['size'] ?? filesize($dest));
        }
        if (!$loaded) {
            return Response::json(['ok' => false, 'error' => 'no_file'], 400);
        }

        // Rigenera l'indice unendo le sorgenti presenti (statali + paritarie).
        $svc = MiurSchoolsService::fromConfig();
        try {
            $count = $svc->rebuild();
        } catch (\Throwable $e) {
            return Response::json([
                'ok' => false, 'error' => 'index_rebuild_failed',
                'detail' => $e->getMessage(), 'loaded' => $loaded,
            ], 500);
        }

        return Response::json([
            'ok'      => true,
            'loaded'  => $loaded,
            'records' => $count,
            'sources' => $svc->sourcesStatus(),
            'index'   => $svc->indexStatus(),
        ]);
    }

    /**
     * POST /admin/institutes/miur/adozioni — carica il CSV regionale delle
     * adozioni e calcola l'ANTEPRIMA. Non scrive niente nel curriculum.
     *
     * E' l'altro dataset MIUR, e non e' un gemello dell'anagrafica: quello dice
     * quali scuole esistono, questo quali indirizzi e sezioni sono davvero
     * attivi dentro una scuola. E' per regione, non nazionale, e serve solo al
     * momento dell'import — nessuna parte dell'app lo legge a runtime.
     */
    public function adozioniUpload(Request $req): Response
    {
        @set_time_limit(0);
        $dataDir = (string)Config::get('app.paths.storage') . '/data';
        if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
            return $this->adozioniErr('Cartella dati non creabile.');
        }
        if (!Database::isAvailable()) {
            return $this->adozioniErr('DB non disponibile.');
        }

        $f = $_FILES['adozioni_file'] ?? null;
        $errCode = is_array($f) ? (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($errCode === UPLOAD_ERR_NO_FILE) {
            return $this->adozioniErr('Nessun file selezionato.');
        }
        if ($errCode !== UPLOAD_ERR_OK) {
            return $this->adozioniErr('Upload fallito: ' . $this->uploadErrMsg($errCode));
        }
        $tmp = (string)($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return $this->adozioniErr('File non ricevuto.');
        }

        $dest = $dataDir . '/adozioni_miur.csv';
        if (is_file($dest)) {
            @rename($dest, $dest . '.prev');
        }
        if (!move_uploaded_file($tmp, $dest)) {
            return $this->adozioniErr('Impossibile salvare il file sul server.');
        }
        @chmod($dest, 0644);

        $onlyCode = trim((string)($req->post['institute_code'] ?? ''));
        try {
            $importer = new MiurAdozioniImporter(Database::connection());
            $plan = $importer->scan($dest, $onlyCode !== '' ? $onlyCode : null);
        } catch (\Throwable $e) {
            // Il messaggio dell'importer dice gia' cosa non torna (colonna
            // mancante, regione sbagliata): passarlo cosi' com'e' e' piu' utile
            // di un "errore generico".
            return $this->adozioniErr('Il file non e\' utilizzabile: ' . $e->getMessage());
        }

        // Il piano viaggia in sessione, non ricalcolato all'apply: cosi' cio'
        // che si conferma e' esattamente cio' che si e' letto. Un secondo scan
        // sullo stesso file darebbe lo stesso risultato solo finche' nessuno
        // tocca il curriculum nel frattempo, e non e' una garanzia da dare.
        $_SESSION['miur_adozioni_plan'] = [
            'token'   => bin2hex(random_bytes(16)),
            'plan'    => $plan,
            'file'    => basename((string)($f['name'] ?? 'adozioni.csv')),
            'filtro'  => $onlyCode,
            'creato'  => time(),
        ];
        return Response::redirect('/admin/institutes/adozioni');
    }

    /** GET /admin/institutes/adozioni — mostra l'anteprima in attesa di conferma. */
    public function adozioniPreview(Request $req): Response
    {
        $sess = $_SESSION['miur_adozioni_plan'] ?? null;
        if (!is_array($sess)) {
            return Response::redirect('/admin/institutes');
        }
        $view = View::default();
        $body = $view->render('admin/institutes_adozioni', [
            'plan'   => $sess['plan'],
            'token'  => (string)$sess['token'],
            'fileCaricato' => (string)$sess['file'],
            'filtro' => (string)$sess['filtro'],
            'creato' => (int)$sess['creato'],
            'csrf'   => Csrf::token(),
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => 'Anteprima import adozioni — Admin',
            'body'  => $body,
        ]));
    }

    /** POST /admin/institutes/adozioni/apply — scrive il piano confermato. */
    public function adozioniApply(Request $req): Response
    {
        @set_time_limit(0);
        $sess = $_SESSION['miur_adozioni_plan'] ?? null;
        $token = (string)($req->post['plan_token'] ?? '');
        if (!is_array($sess) || $token === '' || !hash_equals((string)$sess['token'], $token)) {
            // Anteprima scaduta o di un'altra scansione: rifare, non indovinare.
            $_SESSION['flash'] = [
                'type'  => 'error',
                'title' => 'Anteprima non piu\' valida.',
                'message' => 'Ricarica il file e riconferma: non applico un piano che non e\' quello che hai visto.',
            ];
            unset($_SESSION['miur_adozioni_plan']);
            return Response::redirect('/admin/institutes');
        }
        if (!Database::isAvailable()) {
            return $this->adozioniErr('DB non disponibile.');
        }

        try {
            $importer = new MiurAdozioniImporter(Database::connection());
            $fatte = $importer->apply(is_array($sess['plan']) ? $sess['plan'] : []);
        } catch (\Throwable $e) {
            ActivityLogger::event(
                'miur_adozioni_import',
                subjectType: 'curriculum',
                details:     ['errore' => $e->getMessage()],
                outcome:     'error',
            );
            return $this->adozioniErr('Scrittura fallita (rollback eseguito): ' . $e->getMessage());
        }
        unset($_SESSION['miur_adozioni_plan']);

        ActivityLogger::event(
            'miur_adozioni_import',
            subjectType: 'curriculum',
            details:     $fatte + ['file' => (string)$sess['file'], 'filtro' => (string)$sess['filtro']],
        );
        $_SESSION['flash'] = [
            'type'    => 'success',
            'title'   => 'Import adozioni applicato.',
            // Le materie devono comparire qui: sono arrivate dopo indirizzi e
            // sezioni, e il riepilogo era rimasto quello di prima. Un import
            // che ne scrive venti e annuncia "0 · 0 · 0" fa credere di non aver
            // fatto niente — ed e' successo davvero.
            'message' => sprintf(
                'Indirizzi nuovi: %d · sezioni nuove: %d · materie nuove: %d · '
                . 'sezioni a cui e\' stato assegnato l\'indirizzo: %d.',
                $fatte['indirizzi'],
                $fatte['sezioni'],
                $fatte['materie'],
                $fatte['sistemate']
            ),
        ];
        return Response::redirect('/admin/institutes');
    }

    /** Errore del flusso adozioni: flash + ritorno alla lista. */
    private function adozioniErr(string $msg): Response
    {
        $_SESSION['flash'] = ['type' => 'error', 'title' => 'Import adozioni', 'message' => $msg];
        return Response::redirect('/admin/institutes');
    }

    /** Valida un file caricato: JSON-LD MIUR con "@graph". @return string|null errore. */
    private function validateMiurJsonFile(string $path): ?string
    {
        $size = (int)@filesize($path);
        if ($size < 1024) {
            return 'file_too_small';
        }
        // Legge i primi 256KB: deve essere JSON ({…) con "@graph". No decode in RAM.
        $head = (string)@file_get_contents($path, false, null, 0, 262144);
        $trim = ltrim($head);
        if ($trim === '') {
            return 'file_unreadable';
        }
        if ($trim[0] !== '{') {
            return 'not_json';
        }
        if (strpos($head, '@graph') === false) {
            return 'not_miur_graph_json';
        }
        return null;
    }

    /** Messaggio leggibile per i codici UPLOAD_ERR_*. */
    private function uploadErrMsg(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'file troppo grande (supera upload_max_filesize/post_max_size)',
            UPLOAD_ERR_PARTIAL    => 'upload incompleto (connessione interrotta)',
            UPLOAD_ERR_NO_TMP_DIR => 'cartella temporanea mancante sul server',
            UPLOAD_ERR_CANT_WRITE => 'impossibile scrivere il file su disco',
            UPLOAD_ERR_EXTENSION  => 'upload bloccato da un\'estensione PHP',
            default               => 'errore upload (codice ' . $code . ')',
        };
    }

    private function generatePassword(int $len = 16): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}
