<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\InstituteRepository;
use App\Services\Mailer;
use App\Services\RegistrationMailer;
use App\Services\RegistrationService;
use Throwable;

final class RegistrationController
{
    private RegistrationService $svc;
    private ?RegistrationMailer $mailer;

    public function __construct(?RegistrationService $svc = null, ?RegistrationMailer $mailer = null)
    {
        $this->svc = $svc ?? new RegistrationService(
            registrationsPath: Config::get('auth.paths.registrations'),
            usersPath:         Config::get('auth.paths.registered_users'),
        );
        $this->mailer = $mailer ?? $this->defaultMailer();
    }

    private function defaultMailer(): ?RegistrationMailer
    {
        $from = (string)($_ENV['MAIL_FROM'] ?? 'operatore@example.net');
        $siteUrl = (string)(Config::get('app.url') ?: 'https://pantedu.eu');
        if ($from === '') {
            return null;
        }
        $log = Config::get('app.paths.storage') . '/logs/mail.log';
        return new RegistrationMailer(new Mailer($from), $siteUrl, $log);
    }

    public function showForm(Request $req): Response
    {
        // Phase S2 (ADR-017) — in entrambi i modi self-signup è aperto per
        // STUDENTI (Operatore approva). Differenze:
        //   - SINGLE: role=teacher pre-filled hidden + non modificabile dal form
        //     (solo Operatore = 1 docente; nuovi docenti via admin manuale).
        //   - INSTITUTE: role selezionabile (student | teacher), entrambi approve.
        $view  = View::default();
        $body  = $view->render('auth/register', [
            'csrf'           => Csrf::token(),
            'errorMessage'   => $this->errorMessage((string)($req->query['error'] ?? '')),
            'done'           => isset($req->query['ok']),
            // Phase 25.Q.6 — passa username generato per visualizzazione
            'createdUsername' => trim((string)($req->query['u'] ?? '')) ?: null,
            // Phase S2 (ADR-017) — in single mode nasconde lo switch role
            // (registrazione confinata a studenti).
            'singleMode'      => \App\Support\DeploymentMode::isSingle(),
            // WS3 — modalità acquisizione dati studente: la form adatta i campi
            // (reduced nasconde data di nascita/genitore; anonymous disattiva il signup studente).
            'studentRegMode'  => \App\Support\StudentRegistration::mode(),
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => 'Registrazione — Pantedu',
            'body'  => $body,
            'modal' => true,
        ]));
    }

    private function errorMessage(string $code): ?string
    {
        if ($code === '') {
            return null;
        }
        return match ($code) {
            'invalid_role'            => 'Ruolo non valido.',
            'invalid_first_name',
            'invalid_last_name',
            'invalid_chars_first_name',
            'invalid_chars_last_name' => 'Nome o cognome non valido.',
            'invalid_email'           => 'Email non valida.',
            'password_too_short'      => 'Password troppo corta (min 8 caratteri).',
            'password_too_long'       => 'Password troppo lunga.',
            'username_taken'          => 'Nome utente già in uso.',
            'email_taken'             => 'Email già registrata.',
            'email_pending'           => 'Email già in attesa di approvazione.',
            'institute_required'      => 'Seleziona l\'istituto.',
            'institutes_required'     => 'Seleziona almeno un istituto.',
            'section_required'        => 'Seleziona indirizzo e classe.',
            'class_not_allowed'       => 'La classe/indirizzo selezionati non sono ammessi alla registrazione su questo sito. Contatta la scuola.',
            // Phase 25.C2+C7 — error code per ToS/birth_date/parent_consent
            'tos_required'                  => 'Devi accettare Termini di Servizio, AUP e Informativa Privacy per procedere.',
            'birth_date_required'           => 'Data di nascita obbligatoria per studenti (validazione GDPR Art. 8).',
            'parent_email_required_for_minor' => 'Per studenti minori di 14 anni è richiesta l\'email del genitore (consenso GDPR Art. 8).',
            'parent_email_invalid'          => 'Email genitore non valida.',
            'birth_date_invalid'            => 'Data di nascita non valida (formato AAAA-MM-GG).',
            'birth_date_future'             => 'La data di nascita non può essere nel futuro.',
            'birth_date_too_old'            => 'Data di nascita non plausibile (oltre 120 anni).',
            'teacher_signup_disabled_single' => 'Registrazione docente non disponibile su questa istanza. Contatta l\'amministratore.',
            'student_registration_disabled'  => 'La registrazione studenti non è attiva su questo sito: l\'accesso avviene tramite la credenziale fornita dal docente.',
            default                   => 'Errore: ' . $code,
        };
    }

    public function submit(Request $req): Response
    {
        // Phase S2 (ADR-017) — in single mode è ammesso SOLO role=student
        // (Operatore = unico docente; nuovi docenti vanno aggiunti via admin
        // manualmente, non via self-signup pubblica).
        // NB: Request::$post è readonly → NON mutarla (causa Error fatale 500).
        // Usiamo una variabile locale $role passata a submit() più sotto.
        $role = (string)($req->post['role'] ?? '');
        if (\App\Support\DeploymentMode::isSingle()) {
            if ($role !== '' && $role !== 'student') {
                return Response::redirect('/register?error=teacher_signup_disabled_single', 303);
            }
            // Forza student (defense in depth: anche se il form omette il campo)
            $role = 'student';
        }

        try {
            // Phase 14: MIUR autocomplete → risolvi denom/city in institute_id
            // via upsert idempotente. Legacy institute_id/institute_ids[]
            // ancora accettati per retrocompat (form vecchio o test).
            $instituteId  = isset($req->post['institute_id']) && $req->post['institute_id'] !== ''
                ? (int)$req->post['institute_id'] : null;
            $instituteIds = [];
            if (isset($req->post['institute_ids']) && is_array($req->post['institute_ids'])) {
                foreach ($req->post['institute_ids'] as $iid) {
                    $iid = (int)$iid;
                    if ($iid > 0) {
                        $instituteIds[] = $iid;
                    }
                }
                $instituteIds = array_values(array_unique($instituteIds));
            }

            // Phase 14 resolver: MIUR fields → institute_id (student)
            $miurDenom  = trim((string)($req->post['institute_denom']  ?? ''));
            $miurCity   = trim((string)($req->post['institute_comune'] ?? ''));
            $miurCode   = trim((string)($req->post['institute_code']   ?? ''));
            if (!$instituteId && $miurDenom !== '') {
                $instituteId = $this->resolveInstituteId($miurCode, $miurDenom, $miurCity);
            }

            // Phase 14 resolver: teacher_institutes_json → institute_ids[] (teacher)
            $miurPicks = $this->decodePicks((string)($req->post['teacher_institutes_json'] ?? ''));
            if (!$instituteIds && $miurPicks) {
                foreach ($miurPicks as $p) {
                    $iid = $this->resolveInstituteId(
                        (string)($p['code']  ?? ''),
                        (string)($p['denom'] ?? ''),
                        (string)($p['city']  ?? ''),
                    );
                    if ($iid > 0) {
                        $instituteIds[] = $iid;
                    }
                }
                $instituteIds = array_values(array_unique($instituteIds));
            }

            // ADR-028 Fase 1 — classi ammesse all'iscrizione (trasversale, vale
            // anche in SINGLE). Se l'admin ha configurato un'allowlist di
            // (indirizzo, classe), uno studente può iscriversi solo per quelle.
            $regIndirizzo = trim((string)($req->post['reg_indirizzo'] ?? ''));
            $regClasse    = trim((string)($req->post['reg_classe']    ?? ''));
            if (
                $role === 'student'
                && !(new \App\Services\RegistrationPolicy())->isClassAllowed($regIndirizzo, $regClasse, $instituteId)
            ) {
                return Response::redirect('/register?error=class_not_allowed', 303);
            }

            $out = $this->svc->submit([
                'role'           => $role,
                'first_name'     => (string)($req->post['first_name'] ?? ''),
                'last_name'      => (string)($req->post['last_name']  ?? ''),
                'email'          => (string)($req->post['email']      ?? ''),
                'password'       => (string)($req->post['password']   ?? ''),
                'ip'             => $req->server['REMOTE_ADDR'] ?? null,
                'institute_id'   => $instituteId,
                'institute_ids'  => $instituteIds,
                // Phase 13.5: studente seleziona indirizzo + classe per stats docenti
                'indirizzo'      => trim((string)($req->post['reg_indirizzo'] ?? '')) ?: null,
                'classe'         => trim((string)($req->post['reg_classe']    ?? '')) ?: null,
                // Phase 25.C2 — accettazione ToS+AUP+Privacy (checkbox required)
                'accept_tos'     => !empty($req->post['accept_tos']),
                // Phase 25.C2+C7 — birth_date studenti (validazione GDPR Art. 8 minori)
                'birth_date'     => trim((string)($req->post['birth_date']   ?? '')) ?: null,
                'parent_email'   => trim((string)($req->post['parent_email'] ?? '')) ?: null,
                'parent_name'    => trim((string)($req->post['parent_name']  ?? '')) ?: null,
            ]);
            Csrf::rotate();

            // Invia email di attesa approvazione (best-effort: errori silenziati)
            if ($this->mailer) {
                try {
                    $this->mailer->pending(
                        (string)$req->post['email'],
                        (string)$req->post['first_name']
                    );
                } catch (Throwable $e) {
/* log via Mailer::logSend già fatto */
                }
            }

            return Response::redirect('/register?ok=1&u=' . urlencode($out['username']));
        } catch (Throwable $e) {
            return Response::redirect('/register?error=' . urlencode($e->getMessage()));
        }
    }

    // ──────────── Admin approval endpoints ────────────

    public function listPending(Request $req): Response
    {
        return Response::json(['ok' => true, 'pending' => $this->svc->pending()]);
    }

    public function approve(Request $req, array $params): Response
    {
        try {
            $id    = (string)($params['id'] ?? '');
            $actor = Auth::user()['username'] ?? 'admin';
            $out   = $this->svc->approve($id, $actor);
            if ($this->mailer && !empty($out['email'])) {
                try {
                    $this->mailer->approved($out['email'], $out['first_name'] ?? '', $out['username']);
                } catch (Throwable $e) {
/* best-effort */
                }
            }
            return Response::json(['ok' => true] + $out);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function reject(Request $req, array $params): Response
    {
        try {
            $id     = (string)($params['id'] ?? '');
            $reason = (string)($req->post['reason'] ?? '');
            $actor  = Auth::user()['username'] ?? 'admin';
            $out    = $this->svc->reject($id, $actor, $reason);
            if ($this->mailer && !empty($out['email'])) {
                try {
                    $this->mailer->rejected($out['email'], $out['first_name'] ?? '', $reason);
                } catch (Throwable $e) {
/* best-effort */
                }
            }
            return Response::json(['ok' => true] + $out);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    // ───────────── Phase 14 helpers ─────────────

    /**
     * Upsert istituto a partire dai campi MIUR. Ritorna institute_id o 0
     * se il DB non è disponibile (in tal caso l'errore è gestito a valle:
     * lo studente/teacher riceve 'institute_required').
     */
    private function resolveInstituteId(string $miurCode, string $denom, string $city): int
    {
        if ($denom === '' || !Database::isAvailable()) {
            return 0;
        }
        $code = $miurCode !== '' ? strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $miurCode)) : '';
        if ($code === '') {
            $slug = fn(string $s) => strtoupper(substr((string)preg_replace(
                '/[^A-Za-z0-9]+/',
                '-',
                (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s)
            ), 0, 24));
            $code = 'MIUR-' . $slug($denom) . ($city !== '' ? '-' . $slug($city) : '');
            $code = substr($code, 0, 32);
        }
        $repo = new InstituteRepository();
        // upsertCanonical: riconcilia con una riga esistente della stessa scuola
        // (dedupKey nome+città) invece di creare un duplicato quando il code MIUR
        // reale differisce dal code sintetico già presente. Boundary tenant.
        return $repo->upsertCanonical($code, $denom, $city ?: null);
    }

    /** @return list<array> */
    private function decodePicks(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!\is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $out[] = [
                'denom' => (string)($row['denom'] ?? ''),
                'city'  => (string)($row['city']  ?? ''),
                'code'  => (string)($row['code']  ?? ''),
                'type'  => (string)($row['type']  ?? ''),
            ];
        }
        return $out;
    }
}
