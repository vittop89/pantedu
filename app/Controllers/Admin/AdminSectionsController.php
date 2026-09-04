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
 *   POST /admin/sections/student            → sposta uno studente di classe
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

    public function __construct(
        ?TeacherSectionService $sections = null,
        ?TeacherSubjectService $subjects = null
    ) {
        $this->sections = $sections ?? new TeacherSectionService();
        $this->subjects = $subjects ?? new TeacherSubjectService();
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
            'flash'        => $this->flash($req),
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

        if ($classi === [] && $daTogliere === []) {
            return $this->back($instituteId, 'error', 'Seleziona almeno una classe o sezione.');
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
                    $msg .= ' (assegnate comunque: ' . implode(', ', $fatte) . ')';
                }
                return $this->back($instituteId, 'error', $msg);
            }
        }

        $tolte = [];
        foreach ($daTogliere as $classe) {
            if ($this->sections->revokeSection($userId, $instituteId, $indirizzo, $classe)) {
                $tolte[] = $classe;
            }
        }

        ActivityLogger::event(
            'teacher_section_assigned',
            subjectType: 'user',
            subjectId:   (string)$userId,
            details:     ['institute_id' => $instituteId, 'indirizzo' => $indirizzo,
                          'classi' => $fatte, 'revocate' => $tolte],
        );

        $msg = $fatte !== [] ? 'Assegnate: ' . $indirizzo . ' ' . implode(', ', $fatte) : '';
        if ($tolte !== []) {
            $msg .= ($msg !== '' ? ' · ' : '') . 'Tolte: ' . implode(', ', $tolte);
        }
        return $this->back($instituteId, 'ok', $msg !== '' ? $msg : 'Nessuna modifica.');
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

        $ok = $this->sections->revoke($id);
        ActivityLogger::event(
            'teacher_section_revoked',
            subjectType: 'teacher_section',
            subjectId:   (string)$id,
            details:     ['institute_id' => $instituteId],
            outcome:     $ok ? 'ok' : 'not_found',
        );
        return $this->back($instituteId, $ok ? 'ok' : 'error', $ok ? 'Incarico revocato.' : 'Incarico inesistente.');
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

    private function back(int $instituteId, string $kind, string $msg): Response
    {
        return Response::redirect(
            '/admin/sections?institute_id=' . $instituteId
            . '&' . $kind . '=' . rawurlencode($msg)
        );
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

    private function human(string $code): string
    {
        return match ($code) {
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
