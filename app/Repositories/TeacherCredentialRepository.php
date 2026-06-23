<?php

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Repository per `teacher_access_credentials` (Phase 13).
 *
 * Ogni docente espone N coppie (access_username, password) per
 * consentire agli studenti di accedere alle proprie risorse senza
 * dover essere loro stessi registrati con account individuale.
 *
 * Lo studente inserisce username+password nel prompt sidebar; il
 * backend verifica `password_verify($plain, password_hash)` + scope
 * (institute/indirizzo/classe se settati) → emette token sessione
 * `fm_teacher_access` collegato al teacher_id.
 */
final class TeacherCredentialRepository
{
    public const PASSWORD_MIN = 6;

    /** @return list<array> */
    public function listForTeacher(int $teacherId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT id, label, access_username, indirizzo, classe, institute_id, active, created_at
             FROM teacher_access_credentials
             WHERE teacher_id = ?
             ORDER BY created_at DESC"
        );
        $stmt->execute([$teacherId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(int $teacherId, array $data): int
    {
        $label    = trim((string)($data['label']    ?? ''));
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $ind      = $this->blankToNull($data['indirizzo']    ?? null);
        $cls      = $this->blankToNull($data['classe']       ?? null);
        $iid      = !empty($data['institute_id']) ? (int)$data['institute_id'] : null;

        if ($label === '' || mb_strlen($label) > 128) {
            throw new \InvalidArgumentException('invalid_label');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
            throw new \InvalidArgumentException('invalid_username');
        }
        if (mb_strlen($password) < self::PASSWORD_MIN) {
            throw new \InvalidArgumentException('weak_password');
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        // G22.S20 v2.C2 Fase D — solo FK ids (varchar dropped).
        $instLookup = $iid > 0 ? $iid : null;
        $indId = $ind !== null && $ind !== ''
            ? \App\Support\CurriculumLookup::idFromCode('indirizzi', (string)$ind, $instLookup) : null;
        $clsId = $cls !== null && $cls !== ''
            ? \App\Support\CurriculumLookup::idFromCode('classi', (string)$cls, $instLookup) : null;
        $stmt = Database::connection()->prepare(
            'INSERT INTO teacher_access_credentials_data
                (teacher_id, label, access_username, password_hash,
                 indirizzo_id, classe_id, institute_id, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$teacherId, $label, $username, $hash, $indId, $clsId, $iid]);
        return (int)Database::connection()->lastInsertId();
    }

    public function delete(int $teacherId, int $credentialId): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM teacher_access_credentials_data WHERE id = ? AND teacher_id = ?'
        );
        $stmt->execute([$credentialId, $teacherId]);
        return $stmt->rowCount() > 0;
    }

    public function setActive(int $teacherId, int $credentialId, bool $active): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE teacher_access_credentials_data SET active = ? WHERE id = ? AND teacher_id = ?'
        );
        $stmt->execute([$active ? 1 : 0, $credentialId, $teacherId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Verifica username+password. Restituisce la credential row + teacher_id
     * se valida, altrimenti null.
     */
    public function verify(string $username, string $password): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM teacher_access_credentials
             WHERE access_username = ? AND active = 1 LIMIT 5'
        );
        $stmt->execute([$username]);
        // Multiple teacher possono avere stesso access_username (rare).
        // Verifica password contro tutte; primo match wins.
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (password_verify($password, (string)$row['password_hash'])) {
                return $row;
            }
        }
        return null;
    }

    private function blankToNull(mixed $v): ?string
    {
        $s = is_string($v) ? trim($v) : null;
        return ($s === null || $s === '') ? null : $s;
    }
}
