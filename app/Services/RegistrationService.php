<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use RuntimeException;

/**
 * Self-signup pipeline.
 *
 * Two JSON stores:
 *   - registrations.json  { pending: [...], history: [...] }
 *   - users.json          { users: [...] }  (approved accounts the
 *                          UserRepository loads on every login)
 *
 * Flow: submit() → pending. Admin approve() moves the entry to
 * users.json and appends to history. reject() logs to history only.
 */
final class RegistrationService
{
    public const ROLES = ['student', 'teacher'];

    public function __construct(
        private readonly string $registrationsPath,
        private readonly string $usersPath,
    ) {
    }

    /** @return array{id:string, status:string} */
    public function submit(array $input): array
    {
        $role      = $this->requireRole($input['role'] ?? '');
        // WS3 — modalità registrazione studenti (super-admin /admin/system/deployment).
        // 'anonymous' = self-signup studente disabilitato (accesso via credenziale docente).
        if ($role === 'student' && \App\Support\StudentRegistration::isAnonymous()) {
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
        $this->assertEmailAvailable($email);

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
        if ($role === 'teacher' && !$instituteIds) {
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

        $entry = [
            'id'             => bin2hex(random_bytes(8)),
            'username'       => $username,
            'role'           => $role,
            'first_name'     => $firstName,
            'last_name'      => $lastName,
            'email'          => $email,
            'password_hash'  => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'status'         => 'pending',
            'created'        => date('Y-m-d H:i:s'),
            'ip'             => $input['ip'] ?? null,
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

        return ['id' => $entry['id'], 'status' => 'pending', 'username' => $username];
    }

    /** @return list<array> */
    public function pending(): array
    {
        $data = $this->readRegistrations();
        return array_values(array_map(
            fn(array $row) => $this->public($row),
            $data['pending'] ?? []
        ));
    }

    public function approve(string $id, string $actor): array
    {
        [$entry, $data] = $this->extractPending($id);

        $users = $this->readUsers();
        $users['users'][] = [
            'username'      => $entry['username'],
            'role'          => $entry['role'],
            'first_name'    => $entry['first_name'],
            'last_name'     => $entry['last_name'],
            'email'         => $entry['email'],
            'password_hash' => $entry['password_hash'],
            'status'        => 'approved',
            'active'        => true,
            'created'       => $entry['created'],
            'approved_at'   => date('Y-m-d H:i:s'),
            'approved_by'   => $actor,
            // Phase 13.5: studente eredita scope sezione (per ExerciseAccessPolicy)
            'course'        => $entry['course']    ?? null,
            'indirizzo'     => $entry['indirizzo'] ?? null,
            'classe'        => $entry['classe']    ?? null,
        ];
        $this->writeUsers($users);

        // M4: dual-write — appendi anche in users table così DB Auth la trova
        if (Config::get('database.enabled') && Database::isAvailable()) {
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

            $stmt = Database::connection()->prepare(
                'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, institute_id, indirizzo, classe, birth_date, created_at, approved_at, approved_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), status=VALUES(status), active=VALUES(active), institute_id=VALUES(institute_id), indirizzo=VALUES(indirizzo), classe=VALUES(classe), birth_date=VALUES(birth_date), approved_at=VALUES(approved_at), approved_by=VALUES(approved_by)'
            );
            $stmt->execute([
                $entry['username'], $entry['role'], $entry['first_name'], $entry['last_name'],
                $entry['email'], $entry['password_hash'],
                $userStatus, $userActive,
                $instituteId, $indirizzoSql, $classeSql, $birthDateSql,
                $entry['created'], date('Y-m-d H:i:s'), $actor,
            ]);

            // Phase 25.C2+C7+C8 — Per studenti minori: trigger parent consent
            // workflow. ParentConsentService::request genera token, salva
            // pending_consent row. ParentConsentMailer invia email al
            // genitore con link /parent-consent/{token} (Phase 25.C8).
            // Fail-safe: il token è loggato su file + DB così admin può
            // sempre recuperarlo se SMTP fallisce.
            if ($isMinor && !empty($entry['parent_email'])) {
                $uidStmt = Database::connection()->prepare('SELECT id FROM users WHERE username = ?');
                $uidStmt->execute([$entry['username']]);
                $studentId = (int)($uidStmt->fetchColumn() ?: 0);
                if ($studentId > 0) {
                    try {
                        $parentSvc = new \App\Services\Gdpr\ParentConsentService();
                        $parentName = !empty($entry['parent_name']) ? (string)$entry['parent_name'] : null;
                        $token = $parentSvc->request(
                            $studentId,
                            (string)$entry['parent_email'],
                            $parentName
                        );
                        error_log("[parent_consent] student_id=$studentId token=$token parent_email={$entry['parent_email']}");

                        // Phase 25.C8 — invio email al genitore
                        $mailFrom = (string)($_ENV['APP_MAIL_FROM'] ?? '');
                        if ($mailFrom !== '') {
                            $siteUrl = (string)($_ENV['APP_URL'] ?? 'https://www.pantedu.eu');
                            $logFile = __DIR__ . '/../../storage/logs/mail_audit.log';
                            $mailer = new \App\Services\ParentConsentMailer(
                                new \App\Services\Mailer($mailFrom),
                                $siteUrl,
                                $logFile
                            );
                            $mailer->requestConsent(
                                (string)$entry['parent_email'],
                                $token,
                                (string)$entry['first_name'],
                                $parentName
                            );
                        }
                    } catch (\Throwable $e) {
                        error_log("[parent_consent] failed for student_id=$studentId: " . $e->getMessage());
                    }
                }
            }

            // Teacher → pivot teacher_institutes
            if ($entry['role'] === 'teacher' && !empty($entry['institute_ids'])) {
                $uidStmt = Database::connection()->prepare('SELECT id FROM users WHERE username = ?');
                $uidStmt->execute([$entry['username']]);
                $uid = (int)($uidStmt->fetchColumn() ?: 0);
                if ($uid > 0) {
                    $linkStmt = Database::connection()->prepare(
                        'INSERT IGNORE INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)'
                    );
                    foreach ($entry['institute_ids'] as $iid) {
                        $linkStmt->execute([$uid, (int)$iid]);
                    }
                }
            }
        }

        $data['history'][] = $this->historyRow($entry, 'approved', $actor);
        $this->writeRegistrations($data);

        // Phase 19 — session rotation: se l'utente approvato e' lo stesso
        // loggato correntemente (caso raro, self-approve), rigenera session ID
        // + refresh claims per prevenire session fixation.
        if (
            session_status() === PHP_SESSION_ACTIVE
            && ($_SESSION['username'] ?? '') === $entry['username']
        ) {
            \App\Core\Session::regenerate();
            \App\Core\Auth::refreshCurrentUserClaims();
        }

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
        $data['history'][] = $this->historyRow($entry, 'rejected', $actor, $reason);
        $this->writeRegistrations($data);
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
        $existing = $this->existingUsernames();
        if (!\in_array($base, $existing, true)) {
            return $base;
        }
        for ($i = 2; $i < 100; $i++) {
            $candidate = $base . $i;
            if (!\in_array($candidate, $existing, true)) {
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

    /** @return list<string> */
    private function existingUsernames(): array
    {
        $out = [];
        foreach ($this->readUsers()['users'] ?? [] as $u) {
            if (!empty($u['username'])) {
                $out[] = $u['username'];
            }
        }
        foreach ($this->readRegistrations()['pending'] ?? [] as $u) {
            if (!empty($u['username'])) {
                $out[] = $u['username'];
            }
        }
        return $out;
    }

    private function assertUsernameAvailable(string $username): void
    {
        if (\in_array($username, $this->existingUsernames(), true)) {
            throw new RuntimeException('username_taken');
        }
    }

    private function assertEmailAvailable(string $email): void
    {
        foreach ($this->readUsers()['users'] ?? [] as $u) {
            if (strtolower((string)($u['email'] ?? '')) === $email) {
                throw new RuntimeException('email_taken');
            }
        }
        foreach ($this->readRegistrations()['pending'] ?? [] as $u) {
            if (strtolower((string)($u['email'] ?? '')) === $email) {
                throw new RuntimeException('email_pending');
            }
        }
    }

    /** @return array{0: array, 1: array} */
    private function extractPending(string $id): array
    {
        $data  = $this->readRegistrations();
        $found = null;
        $kept  = [];
        foreach ($data['pending'] ?? [] as $row) {
            if (($row['id'] ?? null) === $id && $found === null) {
                $found = $row;
            } else {
                $kept[] = $row;
            }
        }
        if ($found === null) {
            throw new RuntimeException('registration_not_found');
        }
        $data['pending'] = $kept;
        return [$found, $data];
    }

    private function historyRow(array $entry, string $action, string $actor, string $reason = ''): array
    {
        return [
            'id'        => $entry['id'],
            'username'  => $entry['username'],
            'email'     => $entry['email'],
            'role'      => $entry['role'],
            'action'    => $action,
            'actor'     => $actor,
            'reason'    => $reason,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
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
        return $this->readJson($this->registrationsPath, ['pending' => [], 'history' => []]);
    }

    private function readUsers(): array
    {
        return $this->readJson($this->usersPath, ['users' => []]);
    }

    private function writeRegistrations(array $data): void
    {
        $this->writeJson($this->registrationsPath, $data);
    }
    private function writeUsers(array $data): void
    {
        $this->writeJson($this->usersPath, $data);
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

    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot_create_dir');
        }
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw new RuntimeException('write_failed');
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('rename_failed');
        }
    }
}
