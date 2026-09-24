<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Audit\ActivityLogger;
use App\Services\AvvisoIncarichiTolti;
use App\Services\Contenuti\MaterialiSuSezioniNonAmmesse;
use App\Services\TeacherSectionService;
use App\Services\TeacherSubjectService;
use InvalidArgumentException;
use PDO;

/**
 * Governo delle sezioni: chi insegna dove, e in quale sezione sta uno studente.
 *
 * Entrambe le cose sono decisioni della scuola, non del docente ne' dello
 * studente — per questo vivono qui e non in /area-docente/profilo.
 *
 * Routes:
 *   GET  /admin/sections                    → pannello
 *   POST /admin/sections/assign             → assegna un docente a una sezione
 *   POST /admin/sections/revoke             → revoca un incarico
 *   GET  /admin/sections/anteprima-revoca   → che cosa ha il docente sulle sezioni da togliere
 *                                             (l'avviso prima di confermare)
 *   POST /admin/sections/student            → sposta uno studente di classe
 *   POST /admin/sections/materiali          → porta sull'anno i materiali di un docente
 *                                             rimasti su una sezione senza incarico (ADR-041)
 *   POST /admin/sections/scadenza           → la data dell'avviso ai docenti (ADR-041)
 *
 * Lo spostamento di uno studente e' una RETTIFICA ex Art. 16 GDPR, non una
 * nuova iscrizione: reiscrivere creerebbe una seconda identita' per la stessa
 * persona, perdendo consensi, accettazione dei ToS e — per un minore — il
 * consenso genitoriale gia' raccolto, che andrebbe richiesto di nuovo al
 * genitore per un cambio di classe. Oltre a sbattere sul vincolo di unicita'
 * dell'email. Per questo si modifica, e la modifica va a registro.
 */
final class AdminSectionsController
{
    private TeacherSectionService $sections;
    private TeacherSubjectService $subjects;
    private ?MaterialiSuSezioniNonAmmesse $rimasti;
    private ?AvvisoIncarichiTolti $avviso;

    public function __construct(
        ?TeacherSectionService $sections = null,
        ?TeacherSubjectService $subjects = null,
        ?MaterialiSuSezioniNonAmmesse $rimasti = null,
        ?AvvisoIncarichiTolti $avviso = null
    ) {
        $this->sections = $sections ?? new TeacherSectionService();
        $this->subjects = $subjects ?? new TeacherSubjectService();
        $this->rimasti  = $rimasti;
        $this->avviso   = $avviso;
    }

    /** GET /admin/sections */
    public function index(Request $req): Response
    {
        $instituteId = (int)($req->query['institute_id'] ?? 0)
            ?: (int)(Auth::currentInstitute() ?? 0);

        $view = View::default();
        $body = $view->render('admin/sections', [
            'csrf'         => Csrf::token(),
            'user'         => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
            'institutes'   => $this->institutes(),
            'instituteId'  => $instituteId,
            // ADR-041 — la modalità delle sezioni dei docenti di questo istituto.
            'modoSezioni'  => $instituteId > 0 ? (new \App\Services\SezioniDeiDocenti())->modalita($instituteId) : null,
            'assignments'  => $instituteId > 0 ? $this->sections->listForInstitute($instituteId) : [],
            'teachers'     => $instituteId > 0 ? $this->teachers($instituteId) : [],
            'students'     => $instituteId > 0 ? $this->students($instituteId) : [],
            'indirizzi'    => $instituteId > 0 ? $this->curriculum($instituteId, 'indirizzi') : [],
            'classi'       => $instituteId > 0 ? $this->curriculum($instituteId, 'classi') : [],
            'senzaSezione' => $instituteId > 0 ? $this->studentiSenzaSezione($instituteId) : [],
            'scoperte'     => $instituteId > 0 ? $this->classiScoperte($instituteId) : [],
            'materie'      => $instituteId > 0 ? $this->subjects->available($instituteId) : [],
            'materieDoc'   => $instituteId > 0 ? $this->subjects->byInstitute($instituteId) : [],
            'senzaMaterie' => $instituteId > 0 ? $this->subjects->senzaMaterie($instituteId) : [],
            // ADR-041 — i materiali rimasti su sezioni che i docenti non possono più usare.
            'rimasti'      => $instituteId > 0 ? $this->rimasti()->perIstituto($instituteId) : [],
            'scadenza'     => $instituteId > 0 ? $this->rimasti()->scadenza($instituteId) : null,
            'flash'        => $this->flash($req),
            // Il docente e l'indirizzo dell'ultimo salvataggio degli incarichi.
            'sceltaDocente'   => (int)($req->query['docente'] ?? 0),
            'sceltaIndirizzo' => trim((string)($req->query['indirizzo'] ?? '')),
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => 'Sezioni — Admin',
            'body'  => $body,
        ]));
    }

    /** POST /admin/sections/assign */
    public function assign(Request $req): Response
    {
        $instituteId = (int)($req->post['institute_id'] ?? 0);
        $userId      = (int)($req->post['user_id'] ?? 0);
        $indirizzo   = trim((string)($req->post['indirizzo'] ?? ''));

        // Un docente sta quasi sempre su piu' sezioni dello stesso indirizzo
        // (la 1A e la 1B di matematica): assegnarle una alla volta e' lavoro
        // inutile e invita a usare l'anno "1" come scorciatoia, che e' proprio
        // cio' che si vuole evitare quando le sezioni contano.
        $classi = $req->post['classe'] ?? [];
        $classi = array_values(array_filter(array_map(
            static fn($c) => trim((string)$c),
            is_array($classi) ? $classi : [$classi]
        ), static fn($c) => $c !== ''));

        // Lo stato che il modulo aveva davanti quando e' stato aperto: lo
        // scrive il JS insieme alle caselle che ha spuntato. Serve a togliere
        // gli incarichi a cui l'amministratore ha tolto la spunta — e solo
        // quelli. Se il JS non ha girato arriva vuoto, e allora non si toglie
        // niente: un modulo che non ha potuto mostrare lo stato non puo'
        // nemmeno cambiarlo.
        $stato = $req->post['stato'] ?? '';
        $stato = array_values(array_filter(array_map(
            static fn($c) => strtoupper(trim((string)$c)),
            explode(',', (string)$stato)
        ), static fn($c) => $c !== ''));

        $daTogliere = array_values(array_diff($stato, array_map('strtoupper', $classi)));

        // Docente e indirizzo restano scelti dopo il salvataggio: il modulo si
        // ripresenta sullo stesso docente, con le caselle aggiornate. Fino al
        // 15/9/2026 tornava vuoto, e il messaggio non diceva a chi.
        $scelta = ['docente' => $userId, 'indirizzo' => $indirizzo];
        $chi = $this->nomeDocente($userId);

        if ($classi === [] && $daTogliere === []) {
            return $this->back($instituteId, 'error', 'Seleziona almeno una classe o sezione.', $scelta);
        }

        $fatte = [];
        foreach ($classi as $classe) {
            try {
                $this->sections->assign($userId, $instituteId, $indirizzo, $classe, $this->actorId());
                $fatte[] = $classe;
            } catch (InvalidArgumentException $e) {
                // Si ferma al primo errore ma NON annulla le assegnazioni gia'
                // riuscite: sono idempotenti, e rifarle non costa nulla.
                $msg = $this->human($e->getMessage());
                if ($fatte !== []) {
                    $msg .= ' (assegnate comunque a ' . $chi . ': ' . implode(', ', $fatte) . ')';
                }
                return $this->back($instituteId, 'error', $msg, $scelta);
            }
        }

        $tolte = [];
        foreach ($daTogliere as $classe) {
            if ($this->sections->revokeSection($userId, $instituteId, $indirizzo, $classe)) {
                $tolte[] = $classe;
            }
        }

        // Al docente si scrive solo se gli si toglie qualcosa (scelta dell'utente, 15/9/2026).
        $email = $tolte !== [] ? $this->avviso()->invia($userId, $instituteId, $indirizzo, $tolte, $this->actorId()) : null;

        ActivityLogger::event(
            'teacher_section_assigned',
            subjectType: 'user',
            subjectId:   (string)$userId,
            details:     ['institute_id' => $instituteId, 'indirizzo' => $indirizzo,
                          'classi' => $fatte, 'revocate' => $tolte, 'email' => $email],
        );

        $parti = [];
        if ($fatte !== []) {
            $parti[] = 'assegnate ' . implode(', ', $fatte);
        }
        if ($tolte !== []) {
            $parti[] = 'tolte ' . implode(', ', $tolte);
        }
        $msg = 'Incarichi di ' . $chi . ' in ' . $indirizzo . ': '
            . ($parti !== [] ? implode(' · ', $parti) : 'nessuna modifica') . '.'
            . ($email !== null ? ' ' . self::esitoEmail($email, $chi) : '');
        return $this->back($instituteId, 'ok', $msg, $scelta);
    }

    /**
     * POST /admin/sections/subjects — fissa le materie di un docente.
     *
     * E' un "set": arrivano le caselle spuntate e quelle non spuntate vengono
     * disattivate. Togliere non cancella, disattiva — i contenuti gia'
     * pubblicati puntano a quella riga.
     */
    public function subjects(Request $req): Response
    {
        $instituteId = (int)($req->post['institute_id'] ?? 0);
        $userId      = (int)($req->post['user_id'] ?? 0);
        $codici      = $req->post['materia'] ?? [];
        $codici      = is_array($codici) ? $codici : [$codici];

        try {
            $esito = $this->subjects->set($userId, $instituteId, $codici, $this->actorId());
        } catch (InvalidArgumentException $e) {
            return $this->back($instituteId, 'error', $this->human($e->getMessage()));
        }

        ActivityLogger::event(
            'teacher_subjects_set',
            subjectType: 'user',
            subjectId:   (string)$userId,
            details:     ['institute_id' => $instituteId] + $esito,
        );
        if ($esito['attivate'] === [] && $esito['disattivate'] === []) {
            return $this->back($instituteId, 'ok', 'Nessuna modifica alle materie.');
        }
        $parti = [];
        if ($esito['attivate'] !== []) {
            $parti[] = 'aggiunte: ' . implode(', ', $esito['attivate']);
        }
        if ($esito['disattivate'] !== []) {
            $parti[] = 'tolte: ' . implode(', ', $esito['disattivate']);
        }
        return $this->back($instituteId, 'ok', 'Materie aggiornate — ' . implode(' · ', $parti));
    }

    /** POST /admin/sections/revoke */
    public function revoke(Request $req): Response
    {
        $instituteId = (int)($req->post['institute_id'] ?? 0);
        $id          = (int)($req->post['assignment_id'] ?? 0);

        // Chi e dove, prima che la riga sparisca: servono all'email.
        $st = Database::connection()->prepare('SELECT user_id, indirizzo, classe FROM teacher_sections WHERE id = ?');
        $st->execute([$id]);
        $incarico = $st->fetch(PDO::FETCH_ASSOC);

        $ok = $this->sections->revoke($id);
        $email = $ok && \is_array($incarico)
            ? $this->avviso()->invia((int)$incarico['user_id'], $instituteId, (string)$incarico['indirizzo'], [(string)$incarico['classe']], $this->actorId())
            : null;
        ActivityLogger::event(
            'teacher_section_revoked',
            subjectType: 'teacher_section',
            subjectId:   (string)$id,
            details:     ['institute_id' => $instituteId, 'email' => $email],
            outcome:     $ok ? 'ok' : 'not_found',
        );
        if ($email === null || !\is_array($incarico)) {
            return $this->back($instituteId, 'error', 'Incarico inesistente.');
        }
        $chi = $this->nomeDocente((int)$incarico['user_id']);
        return $this->back(
            $instituteId,
            'ok',
            'Incarico di ' . $chi . ' su ' . $incarico['classe'] . ' revocato. ' . self::esitoEmail($email, $chi)
        );
    }

    /**
     * GET /admin/sections/anteprima-revoca — prima di togliere incarichi, che cosa
     * ha il docente su quelle sezioni: la finestra di conferma lo mostra
     * all'amministratore, con il nome del docente e se gli arriverà l'email.
     *
     * ?institute_id=&user_id=&indirizzo=&classi=2A,2B
     */
    public function anteprimaRevoca(Request $req): Response
    {
        $instituteId = (int)($req->query['institute_id'] ?? 0);
        $userId      = (int)($req->query['user_id'] ?? 0);
        $indirizzo   = trim((string)($req->query['indirizzo'] ?? ''));
        $classi = array_values(array_filter(
            array_map(static fn(string $c): string => strtoupper(trim($c)), explode(',', (string)($req->query['classi'] ?? ''))),
            static fn(string $c): bool => preg_match('/^[1-9][A-Z0-9]{0,15}$/', $c) === 1
        ));
        if ($instituteId <= 0 || $userId <= 0 || $classi === [] || \count($classi) > 60) {
            return Response::json(['ok' => false, 'error' => 'richiesta_non_valida'], 400);
        }
        return Response::json(['ok' => true] + $this->avviso()->anteprima($userId, $instituteId, $indirizzo, $classi));
    }

    /** Che cosa è successo all'email, detto all'amministratore. */
    private static function esitoEmail(string $esito, string $chi): string
    {
        return match ($esito) {
            AvvisoIncarichiTolti::INVIATA         => 'Email inviata a ' . $chi . '.',
            AvvisoIncarichiTolti::NIENTE_DA_FARE  => 'Nessuna email a ' . $chi . ': non aveva materiali né credenziali su quelle classi.',
            AvvisoIncarichiTolti::SENZA_INDIRIZZO => 'Nessuna email: ' . $chi . ' non ha un indirizzo valido.',
            AvvisoIncarichiTolti::SENZA_POSTA     => 'Nessuna email: questa installazione non manda posta.',
            default                               => 'L\'email a ' . $chi . ' non è partita.',
        };
    }

    /**
     * POST /admin/sections/materiali — porta sull'anno i materiali di un docente
     * rimasti su una sezione che non può più usare (ADR-041).
     *
     * A mano, docente per docente e sezione per sezione, con il motivo a
     * registro (`audit_reason`): scelta dell'utente, nessuno spostamento parte
     * da solo. La tabella della pagina è l'anteprima: quanti contenuti,
     * verifiche e posti, e dove andranno.
     */
    public function materiali(Request $req): Response
    {
        $instituteId = (int)($req->post['institute_id'] ?? 0);
        $userId      = (int)($req->post['user_id'] ?? 0);
        $sezioneId   = (int)($req->post['classe_id'] ?? 0);

        try {
            $esito = $this->rimasti()->portaSullAnno($instituteId, $userId, $sezioneId);
        } catch (InvalidArgumentException $e) {
            return $this->back($instituteId, 'error', $this->human($e->getMessage()));
        }
        ActivityLogger::event(
            'sezione_materiali_sull_anno',
            subjectType: 'user',
            subjectId:   (string)$userId,
            details:     ['institute_id' => $instituteId, 'da' => $sezioneId, 'a' => $esito['classe'],
                          'contenuti' => $esito['contenuti'], 'verifiche' => $esito['verifiche'],
                          'varianti' => $esito['varianti'], 'doppi' => $esito['doppi'],
                          'posti' => $esito['posti'], 'anno_spuntato' => $esito['anno_spuntato']],
        );
        $msg = sprintf('Portati sull\'anno «%s»: %d contenuti e %d verifiche.', $esito['classe'], $esito['contenuti'], $esito['verifiche']);
        if ($esito['doppi'] > 0) {
            $msg .= sprintf(' %d restano con due posti nella stessa classe: li sistema il docente da «Dove vale».', $esito['doppi']);
        }
        return $this->back($instituteId, 'ok', $msg);
    }

    /** POST /admin/sections/scadenza — la data dell'avviso ai docenti (vuota: si toglie). */
    public function scadenza(Request $req): Response
    {
        $instituteId = (int)($req->post['institute_id'] ?? 0);
        $data        = (string)($req->post['scadenza'] ?? '');
        try {
            $this->rimasti()->impostaScadenza($instituteId, $data);
        } catch (InvalidArgumentException $e) {
            return $this->back($instituteId, 'error', $this->human($e->getMessage()));
        }
        ActivityLogger::event(
            'sezione_scadenza_impostata',
            subjectType: 'institute',
            subjectId:   (string)$instituteId,
            details:     ['scadenza' => $data !== '' ? $data : null],
        );
        return $this->back($instituteId, 'ok', $data !== '' ? 'Data dell\'avviso salvata.' : 'Data dell\'avviso tolta.');
    }

    /**
     * POST /admin/sections/student — sposta uno studente di indirizzo/classe.
     */
    public function student(Request $req): Response
    {
        $instituteId = (int)($req->post['institute_id'] ?? 0);
        $userId      = (int)($req->post['user_id'] ?? 0);
        $indirizzo   = strtoupper(trim((string)($req->post['indirizzo'] ?? '')));
        $classe      = strtoupper(trim((string)($req->post['classe'] ?? '')));

        if ($userId <= 0 || !preg_match('/^[A-Z]{3,6}$/', $indirizzo) || !preg_match('/^[1-9][A-Z0-9]{0,5}$/', $classe)) {
            return $this->back($instituteId, 'error', 'Indirizzo o sezione non validi.');
        }
        // La sezione e' obbligatoria: un codice di solo numero e' l'anno, e uno
        // studente ancorato all'anno non verrebbe raggiunto da nessun docente
        // assegnato per sezione — resterebbe senza contenuti e senza spiegazione.
        if (preg_match('/^[1-9]$/', $classe)) {
            return $this->back($instituteId, 'error', 'Serve una sezione (es. 1A), non il solo anno.');
        }

        $pdo = Database::connection();
        $prima = $pdo->prepare('SELECT indirizzo, classe FROM users WHERE id = ? AND role = "student" LIMIT 1');
        $prima->execute([$userId]);
        $before = $prima->fetch(PDO::FETCH_ASSOC);
        if ($before === false) {
            return $this->back($instituteId, 'error', 'Studente inesistente.');
        }

        $upd = $pdo->prepare('UPDATE users SET indirizzo = ?, classe = ? WHERE id = ? AND role = "student"');
        $upd->execute([$indirizzo, $classe, $userId]);
        // Piano classi, D — la classe lasciata entra nello storico delle classi
        // frequentate: cosi' lo studente di 3A rivede la sua 2B, non una
        // seconda qualsiasi. Il sistema lo annota da solo, al cambio.
        if (($before['classe'] ?? '') !== '' && strcasecmp((string)$before['classe'], $classe) !== 0) {
            (new \App\Services\Student\StudentProfileService())
                ->recordClassChange($userId, $instituteId, $before['indirizzo'] ?? null, (string)$before['classe']);
        }

        // Il prima/dopo e' il punto della registrazione: senza, la riga direbbe
        // che qualcuno ha spostato qualcuno, non da dove a dove.
        ActivityLogger::event(
            'student_section_changed',
            subjectType: 'user',
            subjectId:   (string)$userId,
            details:     [
                'institute_id' => $instituteId,
                'da' => ($before['indirizzo'] ?? '—') . ' ' . ($before['classe'] ?? '—'),
                'a'  => $indirizzo . ' ' . $classe,
            ],
        );
        return $this->back($instituteId, 'ok', "Studente spostato in $indirizzo $classe.");
    }

    // ── supporto ─────────────────────────────────────────────────────────────

    private function actorId(): ?int
    {
        $id = (int)(Auth::user()['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * @param array{docente?:int, indirizzo?:string} $scelta docente e indirizzo da
     *        ripresentare nel modulo degli incarichi
     */
    private function back(int $instituteId, string $kind, string $msg, array $scelta = []): Response
    {
        $dove = '/admin/sections?institute_id=' . $instituteId;
        if (($scelta['docente'] ?? 0) > 0) {
            $dove .= '&docente=' . (int)$scelta['docente'];
        }
        if (($scelta['indirizzo'] ?? '') !== '') {
            $dove .= '&indirizzo=' . rawurlencode((string)$scelta['indirizzo']);
        }
        return Response::redirect($dove . '&' . $kind . '=' . rawurlencode($msg));
    }

    /** Il nome del docente come lo mostra il modulo; lo username se nome e cognome mancano. */
    private function nomeDocente(int $userId): string
    {
        $st = Database::connection()->prepare(
            'SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(" ", first_name, last_name)), ""), username)
               FROM users WHERE id = ?'
        );
        $st->execute([$userId]);
        $nome = $st->fetchColumn();
        return is_string($nome) && $nome !== '' ? $nome : 'docente #' . $userId;
    }

    /** @return array{type:string,msg:string}|null */
    private function flash(Request $req): ?array
    {
        foreach (['ok', 'error'] as $k) {
            if (isset($req->query[$k]) && $req->query[$k] !== '') {
                return ['type' => $k, 'msg' => (string)$req->query[$k]];
            }
        }
        return null;
    }

    private function rimasti(): MaterialiSuSezioniNonAmmesse
    {
        return $this->rimasti ??= new MaterialiSuSezioniNonAmmesse();
    }

    private function avviso(): AvvisoIncarichiTolti
    {
        return $this->avviso ??= new AvvisoIncarichiTolti();
    }

    private function human(string $code): string
    {
        if (str_starts_with($code, 'anno_non_ammesso:')) {
            return sprintf(
                'Il docente non ha l\'incarico sull\'anno «%s» di quell\'indirizzo: portati lì, i materiali resterebbero fuori dai suoi menù. Dagli prima l\'incarico. Niente è stato spostato.',
                substr($code, strlen('anno_non_ammesso:'))
            );
        }
        if (str_starts_with($code, 'anno_mancante:')) {
            return sprintf(
                'Nel catalogo dell\'istituto manca l\'anno «%s»: aggiungilo da Istituti → Catalogo, poi riprova. Niente è stato spostato.',
                substr($code, strlen('anno_mancante:'))
            );
        }
        return match ($code) {
            'sezione_ammessa'    => 'Il docente può usare questa sezione (ha l\'incarico, o la modalità lo permette): i materiali restano dove sono.',
            'sezione_non_valida' => 'Non è una sezione di questo istituto.',
            'niente_da_spostare' => 'Il docente non ha materiali su questa sezione.',
            'data_non_valida'    => 'Data non valida.',
            'invalid_indirizzo'  => 'Indirizzo non valido (3-6 lettere maiuscole).',
            'invalid_classe'     => 'Sezione non valida (es. 1A, 1BLSS).',
            'teacher_not_linked_to_institute' =>
                'Il docente non è collegato a questo istituto: collegalo prima, '
                . 'altrimenti l\'incarico resterebbe orfano e lo studente non vedrebbe nulla.',
            'invalid_assignment_target' => 'Docente o istituto mancante.',
            'invalid_target'            => 'Docente o istituto mancante.',
            default => $code,
        };
    }

    /** @return list<array<string,mixed>> */
    private function institutes(): array
    {
        return Database::connection()
            ->query('SELECT id, code, name FROM institutes WHERE active = 1 ORDER BY name')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    private function teachers(int $instituteId): array
    {
        $st = Database::connection()->prepare(
            'SELECT u.id, u.username,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(" ", u.first_name, u.last_name)), ""), u.username) AS nome
               FROM users u
               JOIN teacher_institutes ti ON ti.user_id = u.id
              WHERE ti.institute_id = ? AND u.role = "teacher" AND u.deleted_at IS NULL
              ORDER BY nome'
        );
        $st->execute([$instituteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    private function students(int $instituteId): array
    {
        $st = Database::connection()->prepare(
            'SELECT id, username, indirizzo, classe, status
               FROM users
              WHERE institute_id = ? AND role = "student" AND deleted_at IS NULL
              ORDER BY indirizzo, classe, username'
        );
        $st->execute([$instituteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Studenti ancorati al solo anno o senza indirizzo: con le sezioni attive
     * nessun docente li raggiunge. L'avviso serve a non scoprirlo da un utente
     * che segnala di non vedere piu' niente.
     *
     * @return list<array<string,mixed>>
     */
    /**
     * Classi con studenti dentro che nessun docente raggiunge.
     *
     * Da quando il filtro per sezione e' sempre attivo, questi studenti non
     * vedono NIENTE: non e' un caso limite, e' il comportamento normale finche'
     * non c'e' un incarico. La differenza fra "nessuno insegna qui" e "e' vuoto
     * perche' non l'abbiamo ancora configurato" non e' deducibile dai dati, e
     * l'unico modo di non farla passare in silenzio e' scriverla qui.
     *
     * @return list<array{indirizzo:string,classe:string,studenti:int}>
     */
    private function classiScoperte(int $instituteId): array
    {
        $st = Database::connection()->prepare(
            'SELECT indirizzo, classe, COUNT(*) AS studenti
               FROM users
              WHERE institute_id = ? AND role = "student" AND deleted_at IS NULL
                AND indirizzo IS NOT NULL AND indirizzo <> ""
                AND classe IS NOT NULL AND classe <> ""
              GROUP BY indirizzo, classe
              ORDER BY indirizzo, classe'
        );
        $st->execute([$instituteId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ind = (string)$r['indirizzo'];
            $cls = (string)$r['classe'];
            if ($this->sections->teachersForStudent($instituteId, $ind, $cls) === []) {
                $out[] = ['indirizzo' => $ind, 'classe' => $cls, 'studenti' => (int)$r['studenti']];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function studentiSenzaSezione(int $instituteId): array
    {
        $st = Database::connection()->prepare(
            'SELECT id, username, indirizzo, classe
               FROM users
              WHERE institute_id = ? AND role = "student" AND deleted_at IS NULL
                AND (indirizzo IS NULL OR indirizzo = "" OR classe IS NULL OR classe REGEXP "^[1-9]$")
              ORDER BY username'
        );
        $st->execute([$instituteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    private function curriculum(int $instituteId, string $kind): array
    {
        $st = Database::connection()->prepare(
            'SELECT DISTINCT code, label, indirizzo
               FROM curriculum_entries
              WHERE kind = ? AND institute_id = ? AND active = 1
              ORDER BY code'
        );
        $st->execute([$kind, $instituteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
