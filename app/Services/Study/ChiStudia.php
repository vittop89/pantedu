<?php

declare(strict_types=1);

namespace App\Services\Study;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\ContentVisibilityPolicy;
use App\Domain\Role;
use App\Domain\User;
use App\Domain\ViewerContext;
use App\Policies\ExerciseAccessPolicy;
use App\Repositories\SidebarSectionRepository;
use App\Services\Student\StudentProfileService;
use App\Support\ClassAccessGrant;
use App\Support\ClsNormalizer;
use App\Support\CurriculumLookup;
use App\Support\TeacherContextResolver;

/**
 * Chi sta guardando le pagine di studio, e dentro quale perimetro (19/9/2026).
 *
 * Estratto da ContentStudyController senza cambiare comportamento: il
 * contesto di chi guarda (era `viewerContext()`), le sezioni nascoste agli
 * studenti (era `hiddenSectionIdsForStudent()`) e i filtri di una ricerca di
 * studio che non dipendono dal tipo di contenuto (la parte comune di
 * `scopedFilters()`).
 *
 * Perché un servizio. Il selettore delle materie di chi studia deve fare la
 * stessa domanda dell'elenco dei contenuti — «in questa materia c'è qualcosa
 * che io posso vedere?» — e se le due domande le scrivessero due posti
 * diversi, prima o poi divergerebbero in silenzio: il selettore offrirebbe
 * una materia vuota, o ne nasconderebbe una piena. Vedi MaterieConMateriali.
 */
final class ChiStudia
{
    /**
     * Il {@see ViewerContext} di chi guarda, con lo scope dello studente
     * delegato a {@see ExerciseAccessPolicy} (sorgente di verità invariata).
     *
     * L'indirizzo e la classe del contesto derivano da `scopeConstraints()`
     * (incluso il sentinel dell'ospite `indirizzo='__deny__'`), così che
     * ContentVisibilityPolicy::studyListFilters() emetta il frammento
     * {visibility, student_scope, indirizzo, classe}.
     */
    public function contesto(): ViewerContext
    {
        $u = Auth::user();

        // ADR-032 — ospite con credenziale di classe (2026-09-04): e' uno
        // studente senza account. Vede i contenuti PUBBLICATI del docente
        // della credenziale (filtro teacher_id nei filtri) e, se la
        // credenziale e' delimitata, solo quelli della sua classe. Niente
        // istituto nel context: il vincolo "docenti che insegnano davvero
        // nella sezione" (migration 099) non ha senso qui, la credenziale
        // e' gia' l'autorizzazione del docente.
        if (!$u) {
            // Piano classi, C — il portachiavi: un perimetro per credenziale.
            $grants = ClassAccessGrant::all();
            if ($grants !== []) {
                return ViewerContext::forKeychain($grants);
            }
        }

        $policy = new ExerciseAccessPolicy($u ? $this->domainUser($u) : null);

        if ($policy->canSeeAllScopes()) {
            // admin|teacher: nessun vincolo di classe o di stato.
            // ADR-037, fase 1 — ma la scuola si': vedi scuolaDiChiVedeTutto().
            $role = Role::tryFromString((string)($u['role'] ?? '')) ?? Role::TEACHER;
            return new ViewerContext(
                role: $role,
                teacherId: TeacherContextResolver::currentTeacherId(),
                instituteId: $this->scuolaDiChiVedeTutto($role),
            );
        }

        // Studente / guest: lo scope (indirizzo/classe, o sentinel guest
        // '__deny__') viene da scopeConstraints(). Un context STUDENT fa
        // emettere a studyListFilters il frammento published+student_scope.
        $constraints = $policy->scopeConstraints();
        $uid = (int)($u['id'] ?? 0);

        // Ancora lo scope all'ACCOUNT registrato (DB autoritativo, migration 091):
        // istituto + indirizzo + classe vengono dal profilo studente, non dall'URL.
        // Fallback ai constraints (course/sentinel guest) per account legacy senza
        // colonne valorizzate.
        $profili   = new StudentProfileService();
        $scope     = $uid > 0 ? $profili->scopeForUser($uid) : null;
        $indirizzo = $scope['indirizzo'] ?? ($constraints['indirizzo'] ?? null);
        $classe    = $scope['classe']    ?? ($constraints['classe'] ?? null);
        $institute = $scope['institute_id'] ?? Auth::currentInstitute();
        // Piano classi, D — le sezioni passate note rendono l'archivio degli
        // anni precedenti preciso («2B» e non «una seconda qualsiasi»).
        $storico = $uid > 0 ? $profili->storicoForUser($uid) : [];

        return ViewerContext::forStudent($uid, $institute, $indirizzo, $classe, $storico);
    }

    /**
     * La scuola il cui vocabolario la barra mostra a chi studia, o null se chi
     * guarda non studia.
     *
     *   - lo studente con account: la scuola di iscrizione (Auth::currentInstitute);
     *   - l'ospite con la credenziale di classe: la scuola della credenziale, o
     *     del docente che l'ha creata (ADR-032);
     *   - docenti, amministratori e visitatori senza credenziale: null.
     *
     * È la stessa scelta che faceva views/layout/app.php in due rami separati;
     * la usa anche GET /api/study/materie.json.
     */
    public static function scuolaDelVocabolario(): ?int
    {
        $u = Auth::user();
        if ($u !== null) {
            if ((string)($u['role'] ?? '') !== Role::STUDENT->value) {
                return null;
            }
            try {
                return Auth::currentInstitute();
            } catch (\Throwable) {
                return null;
            }
        }
        $iid = ClassAccessGrant::instituteId();
        return $iid > 0 ? $iid : null;
    }

    /**
     * ADR-027 Step 8 — section_id delle sezioni NON visibili agli studenti
     * per l'istituto di chi guarda (lo studente, o la credenziale dell'ospite).
     * Serve a escludere i loro contenuti da liste e pagine (anche via URL
     * diretto).
     *
     * @return list<int>
     */
    public function sezioniNascoste(): array
    {
        try {
            $u = Auth::user();
            $uid = (int)($u['id'] ?? 0);
            if ($uid <= 0) {
                // ADR-032 — ospite con credenziale di classe: le sezioni
                // nascoste agli studenti restano nascoste anche a lui.
                $iid = ClassAccessGrant::instituteId();
                if ($iid <= 0) {
                    return [];
                }
            } else {
                $stmt = Database::connection()->prepare('SELECT institute_id FROM users WHERE id=? LIMIT 1');
                $stmt->execute([$uid]);
                $iid = (int)$stmt->fetchColumn();
            }
            $hidden = [];
            foreach ((new SidebarSectionRepository())->resolveFor($iid, null) as $s) {
                if (!in_array('student', $s['visible_roles'], true)) {
                    $hidden[] = (int)$s['id'];
                }
            }
            return $hidden;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * I filtri di una ricerca di studio, senza il tipo di contenuto: materia,
     * indirizzo e classe chiesti, più il frammento del gate unico
     * (ContentVisibilityPolicy::studyListFilters) per chi guarda.
     *
     * La classe chiesta e' una richiesta, non un diritto: il gate la accetta
     * solo se e' una classe frequentata (anni precedenti), altrimenti riporta
     * alla classe propria.
     *
     * @param array{subj?:?string,ind?:?string,cls?:?string} $params
     * @return array<string,mixed>
     */
    public function filtri(array $params): array
    {
        $ctx = $this->contesto();

        $base = [
            'subject_code' => $params['subj'] ?? null,
            'indirizzo'    => $params['ind']  ?? null,
            // G19.49 — DB normalizzato a short codes ("1".."5"), no piu' "2s".
            // shrink() accetta sia "2" sia "2s" (legacy URL bookmark) → "2".
            'classe'       => isset($params['cls']) ? ClsNormalizer::shrink((string)$params['cls']) : null,
            // visibility null per all-scopes; 'published' per studenti — emesso
            // dal frammento policy sotto.
            'visibility'   => null,
        ];
        // Pilota #1 — gate unico: ContentVisibilityPolicy::studyListFilters()
        // produce {visibility:published, student_scope, indirizzo/classe scope,
        // section_id_not_in} per gli studenti, [] per gli all-scopes.
        // ADR-027 Step 8 — section_id_not_in: sezioni NON visibili agli studenti.
        // Migration 069 — student_scope: include 'general'/'classes' inclusivi.
        $hidden = $ctx->canSeeAllScopes() ? [] : $this->sezioniNascoste();
        $fragment = (new ContentVisibilityPolicy())->studyListFilters(
            $ctx,
            $hidden,
            isset($params['cls']) ? (string)$params['cls'] : null,
            isset($params['ind']) ? (string)$params['ind'] : null
        );
        foreach ($fragment as $k => $v) {
            $base[$k] = $v;
        }
        $out = array_filter($base, static fn($v) => $v !== null);
        // array_filter rimuove gli array vuoti ma non quelli pieni; section_id_not_in
        // (array) sopravvive. Reinserisce se per caso filtrato.
        if (!empty($fragment['section_id_not_in'])) {
            $out['section_id_not_in'] = $fragment['section_id_not_in'];
        }
        // ADR-032 — la credenziale di classe vale per i SUOI docenti: il
        // perimetro per credenziale sta in `grants` (piano classi, C), cosi' un
        // ospite non vede i contenuti pubblicati da altri docenti per la
        // stessa classe. Il filtro teacher_id singolo resta per il caso in cui
        // il gate non abbia emesso `grants`.
        if (!Auth::user() && empty($out['grants'])) {
            $grantTeacher = ClassAccessGrant::teacherId();
            if ($grantTeacher > 0) {
                $out['teacher_id'] = $grantTeacher;
            }
        }
        return $out;
    }

    /**
     * ADR-037, fase 1 — la scuola in cui naviga chi vede tutti gli scope.
     *
     *   - il docente: la sua scuola attiva (la stessa in cui la barra risolve
     *     le sigle), anche se ha anche il flag di super-amministratore: sulle
     *     pagine di studio lavora da docente;
     *   - il super-amministratore che non è docente: nessuna, cioè ovunque;
     *   - l'amministratore di un istituto (e chi ha un istituto in sessione):
     *     quello (S7).
     *
     * Null = nessun vincolo di scuola, come prima della fase 1.
     */
    private function scuolaDiChiVedeTutto(Role $role): ?int
    {
        try {
            if ($role->isTeacher()) {
                return CurriculumLookup::instituteForTeacher(TeacherContextResolver::currentTeacherId());
            }
            if (Auth::isSuperAdmin()) {
                return null;
            }
            $iid = (int)(Auth::currentInstitute() ?? 0);
            return $iid > 0 ? $iid : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $a */
    private function domainUser(array $a): User
    {
        return new User(
            username:     (string)($a['username'] ?? ''),
            passwordHash: '',
            role:         (string)($a['role']     ?? 'guest'),
            active:       true,
            course:       $a['course'] ?? null,
        );
    }
}
