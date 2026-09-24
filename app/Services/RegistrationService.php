<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Services\Gdpr\ConservazioneDelleIscrizioni;
use App\Support\TransazioneConPuntoDiSalvataggio;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Self-signup pipeline.
 *
 * Le domande in attesa stanno in `registrations.json` (`{ pending: [...] }`);
 * gli account, una volta approvati, solo nella tabella `users`.
 *
 * Flow: submit() → pending. approve() crea l'account nel database e toglie la
 * domanda dal file; reject() la toglie e basta. L'esito resta nel registro
 * delle attività (`registration_approved`, `registration_rejected`).
 *
 * 2026-09-24 — fino a quel giorno c'erano altre tre cose, tolte:
 *
 *   - la domanda teneva IP e User-Agent in chiaro, anche di chi non veniva mai
 *     approvato, e per sempre: il lavoro dei 30 giorni cancellava da una
 *     tabella che nessuno scrive ({@see ConservazioneDelleIscrizioni});
 *   - il file teneva lo storico delle decisioni (nome utente, email, esito,
 *     motivazione), che nessun codice rilegge;
 *   - approve() copiava l'account, hash della password compreso, in
 *     `users.json`, che il login non legge dalla Phase 18 e che né la
 *     cancellazione né l'anonimizzazione toccavano. Quella copia era anche
 *     l'unico posto in cui si controllava se un nome utente o un'email erano
 *     già presi: un account nato solo nel database (il super-amministratore,
 *     un amministratore di istituto) non c'era, e approvare una domanda con lo
 *     stesso nome utente ne sovrascriveva la password
 *     (`ON DUPLICATE KEY UPDATE password_hash`), lasciandogli ruolo e
 *     super-amministrazione. Adesso il controllo guarda la tabella `users`, e
 *     l'inserimento non sovrascrive niente.
 */
final class RegistrationService
{
    public const ROLES = ['student', 'teacher'];

    /**
     * Chi dà il mittente dell'email al genitore: di norma
     * `Mailer::fromConfig()`, null = questa istanza non manda posta. Si
     * passa nelle prove (23/9/2026), per guardare il collegamento di
     * conferma senza mandare niente.
     *
     * @var \Closure(): ?Mailer
     */
    private \Closure $posta;

    /**
     * @param (\Closure(): ?Mailer)|null $posta
     */
    public function __construct(
        private readonly string $registrationsPath,
        ?\Closure $posta = null,
    ) {
        $this->posta = $posta ?? static fn(): ?Mailer => Mailer::fromConfig();
    }

    /**
     * 2026-09-08 — la forma dichiarata non elencava `username`, che il metodo
     * restituisce sempre (riga finale). Chi si fidava della firma non sapeva
     * che c'era.
     *
     * @return array{id:string, status:string, username:string}
     */
    public function submit(array $input): array
    {
        $role      = $this->requireRole($input['role'] ?? '');
        // WS3 — modalità registrazione studenti (super-admin /admin/system/deployment).
        // 'anonymous' = self-signup studente disabilitato (accesso via credenziale docente).
        if ($role === 'student' && \App\Support\StudentRegistration::isAnonymous()) {
            throw new RuntimeException('student_registration_disabled');
        }
        // ADR-032 — account studente solo nello scenario 3 (Istituto Titolare).
        if ($role === 'student' && !\App\Support\DeploymentScenario::studentAccountsEnabled()) {
            throw new RuntimeException('student_registration_disabled');
        }
        $firstName = $this->requireName($input['first_name'] ?? '', 'first_name');
        $lastName  = $this->requireName($input['last_name']  ?? '', 'last_name');
        $email     = $this->requireEmail($input['email']     ?? '');
        $password  = (string)($input['password'] ?? '');
        if (strlen($password) < 8) {
            throw new RuntimeException('password_too_short');
        }
        if (strlen($password) > 4096) {
            throw new RuntimeException('password_too_long');
        }

        $username = $this->deriveUsername($firstName, $lastName, $email);
        $this->assertUsernameAvailable($username);

        // Phase 13: institute scoping
        // - student → 1 istituto (institute_id, fk users.institute_id at approve)
        // - teacher → N istituti (institute_ids, pivot teacher_institutes at approve)
        $instituteId  = !empty($input['institute_id']) ? (int)$input['institute_id'] : null;
        $instituteIds = [];
        if (!empty($input['institute_ids']) && is_array($input['institute_ids'])) {
            foreach ($input['institute_ids'] as $iid) {
                $iid = (int)$iid;
                if ($iid > 0) {
                    $instituteIds[] = $iid;
                }
            }
            $instituteIds = array_values(array_unique($instituteIds));
        }
        if ($role === 'student' && !$instituteId) {
            throw new RuntimeException('institute_required');
        }
        // 2026-09-22 — la scuola del docente e' facoltativa, tranne dove la
        // piattaforma e' adottata da un Istituto.
        //
        // La scuola dice **dove lavori**, non che cosa insegni: e' un dato di
        // natura diversa da indirizzo e classe, e chiederlo per forza a chi si
        // iscrive a uno strumento personale non e' proporzionato.
        //
        // Nello scenario 3 resta obbligatoria, e non per principio: li' un
        // docente senza scuola non puo' avere incarichi di sezione ne'
        // pubblicare, e un account che non puo' fare niente e' peggio di un
        // campo in piu'. Vedi {@see \App\Services\SenzaScuola}.
        if ($role === 'teacher' && !$instituteIds && \App\Services\SenzaScuola::obbligatoria()) {
            throw new RuntimeException('institutes_required');
        }

        // Phase 13.5: studente seleziona anche indirizzo+classe →
        // diventano `course = "{indirizzo}.{classe}"` (formato che
        // ExerciseAccessPolicy::splitSection sa parsare).
        $regInd = trim((string)($input['indirizzo'] ?? '')) ?: null;
        $regCls = trim((string)($input['classe']    ?? '')) ?: null;
        if ($role === 'student' && (!$regInd || !$regCls)) {
            throw new RuntimeException('section_required');
        }
        $course = \App\Services\Student\StudentProfileService::course($regInd, $regCls);

        // WS3 — allowlist classi ammesse alla registrazione (gap chiuso): se la
        // tabella registration_allowed_classes è popolata (es. opzione "solo classi
        // del super-admin"), accetta lo studente solo per quelle coppie. Tabella
        // vuota = nessun vincolo (fail-open, retrocompat).
        if ($role === 'student' && $regInd && $regCls) {
            if (!(new RegistrationPolicy())->isClassAllowed($regInd, $regCls, $instituteId)) {
                throw new RuntimeException('class_not_allowed');
            }
        }

        // Phase 25.C2 — TOS + privacy disclosure obbligatoria (Art. 7 + 13).
        if (empty($input['accept_tos'])) {
            throw new RuntimeException('tos_required');
        }

        // Phase 25.C2+C7 — birth_date obbligatorio per studenti (validazione
        // Art. 8 GDPR minori). Soglia 14 anni (D.Lgs. 101/2018 Italia).
        // WS3 — birth_date/genitore raccolti SOLO in modalità 'full' (difesa in
        // profondità: anche se il form li invia, in 'reduced' non vengono salvati).
        $birthDate = \App\Support\StudentRegistration::isFull()
            ? (trim((string)($input['birth_date'] ?? '')) ?: null)
            : null;
        $parentEmail = null;
        $parentName  = null;
        $isMinor = false;
        // WS3 — in modalità 'reduced' NON si raccolgono data di nascita né dati
        // del genitore (minimizzazione; niente age-gating Art.8 — dichiarato nei
        // documenti DPO). La validazione minori resta solo in 'full'.
        if ($role === 'student' && \App\Support\StudentRegistration::isFull()) {
            if (!$birthDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
                throw new RuntimeException('birth_date_required');
            }
            $isMinor = \App\Services\Gdpr\ParentConsentService::requiresParentConsent($birthDate);
            if ($isMinor) {
                $parentEmail = trim((string)($input['parent_email'] ?? ''));
                if (!$parentEmail || !filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('parent_email_required_for_minor');
                }
                $parentName = trim((string)($input['parent_name'] ?? '')) ?: null;
            }
        }

        // L'hash prima del controllo dell'email, e il controllo per ultimo
        // (24/9/2026): un'email già usata non deve rispondere prima, e in un
        // tempo diverso, di una domanda che passa. Il modulo risponde allo
        // stesso modo nei due casi (RegistrationController::submit()), e un
        // quarto di secondo di bcrypt in meno lo direbbe lo stesso.
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->assertEmailAvailable($email);

        $entry = [
            'id'             => bin2hex(random_bytes(8)),
            'username'       => $username,
            'role'           => $role,
            'first_name'     => $firstName,
            'last_name'      => $lastName,
            'email'          => $email,
            'password_hash'  => $passwordHash,
            'status'         => 'pending',
            'created'        => date('Y-m-d H:i:s'),
            // 2026-09-24 — niente IP né User-Agent: restavano in chiaro anche
            // per chi non veniva mai approvato. Servivano solo al verbale di
            // accettazione dei Termini scritto all'approvazione, che per gli
            // account nati da una domanda ha data e versioni e basta.
            'institute_id'   => $instituteId,
            'institute_ids'  => $instituteIds,
            'indirizzo'      => $regInd,
            'classe'         => $regCls,
            'course'         => $course,
            // Phase 25.C2 — birth_date + parent consent flow
            'birth_date'     => $birthDate,
            'is_minor'       => $isMinor,
            'parent_email'   => $parentEmail,
            'parent_name'    => $parentName,
            'tos_accepted_at' => date('Y-m-d H:i:s'),
        ];

        $data = $this->readRegistrations();
        $data['pending'][] = $entry;
        $this->writeRegistrations($data);

        // Le registrazioni vivono in un file JSON, non in una tabella: senza
        // questa riga una domanda di iscrizione non compare in nessun
        // registro consultabile, e per un minore e' proprio il momento da cui
        // parte tutto il resto.
        \App\Services\Audit\ActivityLogger::event(
            'registration_submitted',
            subjectType: 'registration',
            subjectId:   $entry['id'],
            details:     [
                'username'  => $username,
                'role'      => $role,
                'is_minor'  => $isMinor,
                // L'indirizzo del genitore non si copia nel registro: basta
                // sapere che e' stato richiesto un consenso, il recapito sta
                // gia' in parent_consents.
                'parent_consent_required' => $isMinor && $parentEmail !== null,
            ],
        );

        return ['id' => $entry['id'], 'status' => 'pending', 'username' => $username];
    }

    /**
     * Le domande da decidere: solo quelle nel termine (24/9/2026).
     *
     * @return list<array>
     */
    public function pending(): array
    {
        return array_values(array_map(
            fn(array $row) => $this->public($row),
            $this->domandeInTermine(),
        ));
    }

    public function approve(string $id, string $actor): array
    {
        [$entry, $data] = $this->extractPending($id);

        // 2026-09-24 — l'account sta solo nel database. Prima si scriveva
        // comunque in users.json, e nel database solo se c'era: senza database
        // la domanda spariva, l'approvazione rispondeva «ok» e l'account non
        // poteva entrare, perché il login legge solo la tabella users. Adesso
        // senza database non si approva, e la domanda resta dov'è.
        $pdo = $this->database();
        if ($pdo === null) {
            throw new RuntimeException('database_unavailable');
        }
        // Il nome utente e l'email possono essere stati presi dopo la domanda
        // (un account creato dall'amministrazione, un'altra domanda approvata).
        if ($this->usernameNelDatabase($pdo, (string)$entry['username'])) {
            throw new RuntimeException('username_taken');
        }
        if ($this->emailNelDatabase($pdo, (string)$entry['email'])) {
            throw new RuntimeException('email_taken');
        }

        $instituteId = !empty($entry['institute_id']) ? (int)$entry['institute_id'] : null;

        // Phase 25.C2+C7 — Studente minore: status='pending_parent_consent',
        // active=0 fino a conferma genitore via /parent-consent/{token}.
        // Studente maggiorenne / docente: attivato direttamente.
        $isMinor = !empty($entry['is_minor']);
        $userStatus = $isMinor ? 'pending_parent_consent' : 'approved';
        $userActive = $isMinor ? 0 : 1;
        $birthDateSql = !empty($entry['birth_date']) ? $entry['birth_date'] : null;

        // Scope studente persistito in colonne DB autoritative (migration 091):
        // indirizzo/classe servono ad ancorare la visibilità all'account.
        $indirizzoSql = !empty($entry['indirizzo']) ? (string)$entry['indirizzo'] : null;
        $classeSql    = !empty($entry['classe'])    ? (string)$entry['classe']    : null;

        // Un INSERT e basta: fino al 24/9/2026 c'era `ON DUPLICATE KEY
        // UPDATE password_hash=…, status=…, active=…`, e una domanda con il
        // nome utente di un account esistente ne prendeva la password.
        // Il controllo qui sopra dà il messaggio; il vincolo UNIQUE su
        // username resta l'ultima parola, anche fra due approvazioni
        // contemporanee.
        //
        // Tutto o niente (24/9/2026): account, verbale dei Termini e scuole
        // del docente si confermano solo dopo che la domanda è uscita dal
        // file. Prima l'INSERT era definitivo subito, e se la scrittura del
        // file falliva l'account restava attivo, la domanda in attesa, senza
        // evento né email, e ogni nuovo tentativo rispondeva `username_taken`.
        $fileScritto = false;
        try {
            $newUserId = (new TransazioneConPuntoDiSalvataggio($pdo))->run(function () use (
                $pdo,
                $entry,
                $data,
                $actor,
                $instituteId,
                $userStatus,
                $userActive,
                $indirizzoSql,
                $classeSql,
                $birthDateSql,
                &$fileScritto,
            ): int {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, institute_id, indirizzo, classe, birth_date, created_at, approved_at, approved_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                try {
                    $stmt->execute([
                        $entry['username'], $entry['role'], $entry['first_name'], $entry['last_name'],
                        $entry['email'], $entry['password_hash'],
                        $userStatus, $userActive,
                        $instituteId, $indirizzoSql, $classeSql, $birthDateSql,
                        $entry['created'], date('Y-m-d H:i:s'), $actor,
                    ]);
                } catch (PDOException $e) {
                    // Solo la chiave doppia (1062 in MariaDB): anche una chiave esterna
                    // violata è SQLSTATE 23000, e non è un nome utente preso.
                    if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                        throw new RuntimeException('username_taken');
                    }
                    throw $e;
                }
                $uid = (int)$pdo->lastInsertId();

                // La spunta ToS/AUP era raccolta al submit e poi persa: restava
                // solo in registrations.json, mai in user_tos_acceptance. Ogni
                // docente approvato risultava quindi "mai accettato" al gate e nel
                // log admin. Qui la riga viene finalmente scritta, con le versioni
                // vigenti alla data della spunta; senza IP né User-Agent
                // (24/9/2026), che la domanda non tiene più.
                if (!empty($entry['tos_accepted_at']) && $uid > 0) {
                    try {
                        (new \App\Services\Gdpr\TosAcceptanceService($pdo))
                            ->recordHistoricAcceptance($uid, (string)$entry['tos_accepted_at'], '', null);
                    } catch (\Throwable $e) {
                        // L'approvazione non va persa per questo, ma senza la riga
                        // il docente finirà al gate: va visto nei log.
                        error_log('[RegistrationService] tos acceptance record failed for '
                            . $entry['username'] . ': ' . $e->getMessage());
                    }
                }

                // Teacher → pivot teacher_institutes
                if ($entry['role'] === 'teacher' && !empty($entry['institute_ids']) && $uid > 0) {
                    $linkStmt = $pdo->prepare(
                        'INSERT IGNORE INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)'
                    );
                    foreach ($entry['institute_ids'] as $iid) {
                        $linkStmt->execute([$uid, (int)$iid]);
                    }
                }

                $this->writeRegistrations($data);
                $fileScritto = true;
                return $uid;
            });
        } catch (\Throwable $e) {
            // La conferma è fallita dopo che la domanda era già uscita dal
            // file: la si rimette, o sparirebbe senza account.
            if ($fileScritto) {
                $this->rimettiInAttesa($entry);
            }
            throw $e;
        }

        // Da qui l'account c'è e la domanda no: consenso del genitore,
        // sessione ed evento (PassiDopoLApprovazione).
        PassiDopoLApprovazione::esegui($newUserId, (string)$id, $entry, $actor, $this->posta);

        return [
            'ok'         => true,
            'username'   => $entry['username'],
            'email'      => $entry['email'],
            'first_name' => $entry['first_name'],
            'rotated'    => true,
        ];
    }

    public function reject(string $id, string $actor, string $reason = ''): array
    {
        [$entry, $data] = $this->extractPending($id);
        $this->writeRegistrations($data);

        \App\Services\Audit\ActivityLogger::event(
            'registration_rejected',
            subjectType: 'registration',
            subjectId:   (string)$id,
            outcome:     'denied',
            details:     [
                'username'    => $entry['username'],
                'role'        => $entry['role'],
                'rejected_by' => $actor,
                'reason'      => $reason,
            ],
        );

        return [
            'ok'         => true,
            'username'   => $entry['username'],
            'email'      => $entry['email'],
            'first_name' => $entry['first_name'],
            'reason'     => $reason,
        ];
    }

    // ───────────── helpers ─────────────

    private function requireRole(string $role): string
    {
        if (!\in_array($role, self::ROLES, true)) {
            throw new RuntimeException('invalid_role');
        }
        return $role;
    }

    private function requireName(string $name, string $field): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 80) {
            throw new RuntimeException("invalid_$field");
        }
        if (!preg_match("#^[\p{L}'\- ]+$#u", $name)) {
            throw new RuntimeException("invalid_chars_$field");
        }
        return $name;
    }

    private function requireEmail(string $email): string
    {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
            throw new RuntimeException('invalid_email');
        }
        return strtolower($email);
    }

    private function deriveUsername(string $first, string $last, string $email): string
    {
        $base  = strtolower($this->slug($first) . '.' . $this->slug($last));
        if ($base === '.') {
            $base = strtolower(strstr($email, '@', true) ?: 'user');
        }
        if (!$this->usernameInUso($base)) {
            return $base;
        }
        for ($i = 2; $i < 100; $i++) {
            $candidate = $base . $i;
            if (!$this->usernameInUso($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('username_collision');
    }

    private function slug(string $s): string
    {
        $s = preg_replace('#[^a-zA-Z0-9]+#', '', (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s));
        return strtolower((string)$s);
    }

    /**
     * Un nome utente è in uso se ce l'ha un account o una domanda in attesa.
     *
     * 2026-09-24 — gli account si guardano nella tabella `users`. Prima si
     * guardava `users.json`, la copia degli account nati da una domanda: un
     * account nato solo nel database non c'era, e la sua password finiva
     * sovrascritta all'approvazione. Senza database il controllo si fa sulle
     * sole domande: approve(), che il database lo richiede, rifà il suo.
     */
    private function usernameInUso(string $username): bool
    {
        foreach ($this->domandeInTermine() as $u) {
            if (\is_array($u) && ($u['username'] ?? null) === $username) {
                return true;
            }
        }
        $pdo = $this->database();
        return $pdo !== null && $this->usernameNelDatabase($pdo, $username);
    }

    private function assertUsernameAvailable(string $username): void
    {
        if ($this->usernameInUso($username)) {
            throw new RuntimeException('username_taken');
        }
    }

    private function assertEmailAvailable(string $email): void
    {
        $pdo = $this->database();
        if ($pdo !== null && $this->emailNelDatabase($pdo, $email)) {
            throw new RuntimeException('email_taken');
        }
        foreach ($this->domandeInTermine() as $u) {
            if (strtolower((string)($u['email'] ?? '')) === $email) {
                throw new RuntimeException('email_pending');
            }
        }
    }

    /** La connessione, o null dove il database è spento o non risponde. */
    private function database(): ?PDO
    {
        if (!Config::get('database.enabled') || !Database::isAvailable()) {
            return null;
        }
        return Database::connection();
    }

    /**
     * Rimette nel file una domanda che ne era uscita per un'approvazione poi
     * annullata. Rilegge il file, perché nel frattempo può esserne arrivata
     * un'altra.
     *
     * @param array<string,mixed> $entry
     */
    private function rimettiInAttesa(array $entry): void
    {
        try {
            $dati = $this->readRegistrations();
            foreach ($dati['pending'] ?? [] as $voce) {
                if (\is_array($voce) && ($voce['id'] ?? null) === ($entry['id'] ?? null)) {
                    return;
                }
            }
            $dati['pending'][] = $entry;
            $this->writeRegistrations($dati);
        } catch (\Throwable $e) {
            error_log('[RegistrationService] domanda ' . (string)($entry['id'] ?? '?')
                . ' non rimessa in attesa dopo un\'approvazione annullata: ' . $e->getMessage());
        }
    }

    private function usernameNelDatabase(PDO $pdo, string $username): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
        $st->execute([$username]);
        return $st->fetchColumn() !== false;
    }

    private function emailNelDatabase(PDO $pdo, string $email): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(email) = ? LIMIT 1');
        $st->execute([strtolower($email)]);
        return $st->fetchColumn() !== false;
    }

    /**
     * La domanda da decidere, e il contenuto del file senza di lei. Solo fra
     * le domande nel termine: una scaduta non si approva né si rifiuta anche
     * se il giro notturno non è ancora passato (24/9/2026).
     *
     * @return array{0: array, 1: array}
     */
    private function extractPending(string $id): array
    {
        $grezzi = $this->readRegistrations();
        $found  = null;
        $kept   = [];
        foreach ($this->domandeInTermine($grezzi) as $row) {
            if (($row['id'] ?? null) === $id && $found === null) {
                $found = $row;
            } else {
                $kept[] = $row;
            }
        }
        if ($found === null) {
            foreach ($grezzi['pending'] ?? [] as $row) {
                if (\is_array($row) && ($row['id'] ?? null) === $id) {
                    throw new RuntimeException('registration_expired');
                }
            }
            throw new RuntimeException('registration_not_found');
        }
        return [$found, ['pending' => $kept]];
    }

    private function public(array $row): array
    {
        return [
            'id'             => $row['id']             ?? '',
            'username'       => $row['username']       ?? '',
            'first_name'     => $row['first_name']     ?? '',
            'last_name'      => $row['last_name']      ?? '',
            'email'          => $row['email']          ?? '',
            'role'           => $row['role']           ?? '',
            'created'        => $row['created']        ?? '',
            'institute_id'   => isset($row['institute_id']) ? (int)$row['institute_id'] : null,
            'institute_ids'  => isset($row['institute_ids']) && is_array($row['institute_ids']) ? array_map('intval', $row['institute_ids']) : [],
            // Phase 13.5: scope sezione studente
            'indirizzo'      => $row['indirizzo']      ?? null,
            'classe'         => $row['classe']         ?? null,
            'course'         => $row['course']         ?? null,
        ];
    }

    private function readRegistrations(): array
    {
        return $this->readJson($this->registrationsPath, ['pending' => []]);
    }

    /**
     * Le domande in attesa nel termine, dal file o dai dati già letti.
     *
     * @param array<string,mixed>|null $dati
     * @return list<array<string,mixed>>
     */
    private function domandeInTermine(?array $dati = null): array
    {
        return ConservazioneDelleIscrizioni::domandeInTermine(
            $dati ?? $this->readRegistrations(),
            new \DateTimeImmutable('now'),
            ConservazioneDelleIscrizioni::giorniConfigurati(),
        );
    }

    /**
     * Ogni scrittura del file applica il termine delle domande
     * ({@see ConservazioneDelleIscrizioni::ripulisci()}): le domande scadute
     * se ne vanno, e da quelle di prima si tolgono IP e User-Agent e lo
     * storico, alla prima occasione e non solo di notte.
     */
    private function writeRegistrations(array $data): void
    {
        $giorni = ConservazioneDelleIscrizioni::giorniConfigurati();
        [$puliti] = ConservazioneDelleIscrizioni::ripulisci($data, new \DateTimeImmutable('now'), $giorni);
        ConservazioneDelleIscrizioni::scriviFile($this->registrationsPath, $puliti);
    }

    private function readJson(string $path, array $default): array
    {
        if (!is_file($path)) {
            return $default;
        }
        $raw = file_get_contents($path);
        $data = json_decode((string)$raw, true);
        return \is_array($data) ? $data : $default;
    }
}
