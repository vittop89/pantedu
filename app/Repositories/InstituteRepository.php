<?php

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Repository per institutes + teacher_institutes pivot (Phase 13).
 */
final class InstituteRepository
{
    /** @return list<array> */
    public function listActive(): array
    {
        $stmt = Database::connection()->query(
            'SELECT id, code, name, header_label, city, region FROM institutes WHERE active = 1 ORDER BY name'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** G20.0 — update identity columns (header_label, footer_signature, logo_path). */
    public function updateIdentity(int $id, array $fields): bool
    {
        $allowed = ['header_label', 'footer_signature', 'logo_path'];
        $set = [];
        $args = [];
        foreach ($allowed as $col) {
            if (\array_key_exists($col, $fields)) {
                $set[] = "$col = ?";
                $args[] = $fields[$col];
            }
        }
        if (!$set) {
            return false;
        }
        $args[] = $id;
        $stmt = Database::connection()->prepare(
            'UPDATE institutes SET ' . implode(', ', $set) . ' WHERE id = ?'
        );
        $stmt->execute($args);
        return $stmt->rowCount() > 0;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM institutes WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Codice meccanografico MIUR reale: 2 lettere provincia + 8 alfanumerici
     * (es. XXSL000000, XXPS00000A). I codici sintetici contengono `-`
     * (es. MIUR-ESEMPIO-COMUNE ESEMPIO-ART) e NON matchano. Usato per scegliere il
     * codice canonico quando si riconciliano righe della stessa scuola.
     */
    public static function isRealMiurCode(string $code): bool
    {
        return (bool)preg_match('/^[A-Z]{2}[A-Z0-9]{8}$/', $code);
    }

    /**
     * Chiave naturale di deduplicazione: nome + città normalizzati (uppercase,
     * accenti translitterati, tutto ciò che non è alfanumerico rimosso). Due
     * righe con la stessa chiave sono la STESSA scuola fisica anche se hanno
     * `code` diversi (sintetico vs MIUR reale). È il boundary del tenant.
     */
    public static function dedupKey(string $name, ?string $city): string
    {
        $norm = static function (string $s): string {
            $s = (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            $s = strtoupper($s);
            return (string)preg_replace('/[^A-Z0-9]/', '', $s);
        };
        return $norm($name) . '|' . $norm((string)($city ?? ''));
    }

    /**
     * Trova un istituto esistente con la stessa dedupKey (stessa scuola), a
     * prescindere dal `code`. Ritorna la riga o null.
     */
    public function findByDedup(string $name, ?string $city): ?array
    {
        $target = self::dedupKey($name, $city);
        $stmt = Database::connection()->query('SELECT * FROM institutes');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (self::dedupKey((string)$row['name'], $row['city'] ?? null) === $target) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Upsert CANONICO multitenant — previene righe duplicate per la stessa
     * scuola (boundary del tenant). Ordine di risoluzione:
     *
     *   1. Match esatto per `code` → ritorna quella riga (idempotente).
     *   2. Match per dedupKey (nome+città) su una riga esistente:
     *        - esistente con code sintetico, incoming è MIUR reale → UPGRADE
     *          del code esistente al MIUR reale, ritorna quella riga;
     *        - esistente reale e incoming reale ma DIVERSI → scuole distinte
     *          (plessi): crea una nuova riga;
     *        - altrimenti → riusa la riga esistente (no dup).
     *   3. Nessun match → INSERT.
     *
     * @return int institute_id canonico
     */
    public function upsertCanonical(string $code, string $name, ?string $city = null, ?string $region = null): int
    {
        $this->assertValidCode($code);
        $this->assertValidName($name);
        if ($city !== null && $city !== '') {
            $this->assertValidLocation($city, 'city');
        }
        if ($region !== null && $region !== '') {
            $this->assertValidLocation($region, 'region');
        }

        // 1. match esatto per code
        $existing = $this->findByCode($code);
        if ($existing) {
            return (int)$existing['id'];
        }

        // 2. match per stessa scuola (dedupKey)
        $match = $this->findByDedup($name, $city);
        if ($match) {
            $incomingReal = self::isRealMiurCode($code);
            $existingReal = self::isRealMiurCode((string)$match['code']);
            if ($incomingReal && !$existingReal) {
                // upgrade del code sintetico → MIUR reale (safe: findByCode null sopra)
                $upd = Database::connection()->prepare('UPDATE institutes SET code = ? WHERE id = ?');
                $upd->execute([$code, (int)$match['id']]);
                return (int)$match['id'];
            }
            if ($incomingReal && $existingReal && $code !== (string)$match['code']) {
                // due codici MIUR reali diversi → plessi distinti: nuova riga
                return $this->insertNew($code, $name, $city, $region);
            }
            // sintetico↔sintetico, oppure incoming sintetico con esistente reale → riusa
            return (int)$match['id'];
        }

        // 3. nuova scuola
        return $this->insertNew($code, $name, $city, $region);
    }

    private function insertNew(string $code, string $name, ?string $city, ?string $region): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO institutes (code, name, city, region, active) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$code, $name, $city, $region]);
        return (int)Database::connection()->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM institutes WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Idempotente: insert o ritorna id esistente.
     *
     * Phase 25.R.1.1 — validazione stretta su code/name/city/region per
     * prevenire pollution da pentest/scanner (OWASP ZAP fuzz aveva inserito
     * 64 entries malformi tipo `../../../etc/passwd`, `;start-sleep -s 15.0`,
     * SQL injection payload, XSS payload).
     *
     * Regole:
     *   - code: 2-40 char alfanumerici + `_-` only (regex)
     *   - name: 1-200 char, no `<`, `>`, `/`, `\`, `;`, `&`, backtick, `${`,
     *           `<!--`, `\n`
     *   - city/region: 0-100 char, stesso strip dei pattern injection
     *
     * Throw InvalidArgumentException se input fallisce validation.
     */
    public function upsert(string $code, string $name, ?string $city = null, ?string $region = null): int
    {
        $this->assertValidCode($code);
        $this->assertValidName($name);
        if ($city !== null && $city !== '') {
            $this->assertValidLocation($city, 'city');
        }
        if ($region !== null && $region !== '') {
            $this->assertValidLocation($region, 'region');
        }

        $existing = $this->findByCode($code);
        if ($existing) {
            return (int)$existing['id'];
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO institutes (code, name, city, region, active) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$code, $name, $city, $region]);
        return (int)Database::connection()->lastInsertId();
    }

    private function assertValidCode(string $code): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{2,40}$/', $code)) {
            throw new \InvalidArgumentException(
                'institute_code_invalid: must match ^[A-Za-z0-9_-]{2,40}$'
            );
        }
    }

    private function assertValidName(string $name): void
    {
        $len = mb_strlen($name, 'UTF-8');
        if ($len < 1 || $len > 200) {
            throw new \InvalidArgumentException('institute_name_invalid: 1-200 chars required');
        }
        if (preg_match('#[<>\\\\;&`]|\\$\\{|<!--|\.\.[\\\\/]#u', $name)) {
            throw new \InvalidArgumentException(
                'institute_name_invalid: contains forbidden chars (HTML/shell injection patterns)'
            );
        }
    }

    private function assertValidLocation(string $value, string $field): void
    {
        $len = mb_strlen($value, 'UTF-8');
        if ($len > 100) {
            throw new \InvalidArgumentException("institute_{$field}_invalid: max 100 chars");
        }
        if (preg_match('#[<>\\\\;&`]|\\$\\{|<!--|\.\.[\\\\/]#u', $value)) {
            throw new \InvalidArgumentException(
                "institute_{$field}_invalid: contains forbidden chars"
            );
        }
    }

    /** @return list<array> */
    public function listForTeacher(int $teacherId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT i.id, i.code, i.name, i.header_label, i.city, i.region, ti.role_at_inst
             FROM institutes i
             INNER JOIN teacher_institutes ti ON ti.institute_id = i.id
             WHERE ti.user_id = ? AND i.active = 1
             ORDER BY i.name'
        );
        $stmt->execute([$teacherId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function linkTeacher(int $teacherId, int $instituteId, ?string $role = null): bool
    {
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO teacher_institutes (user_id, institute_id, role_at_inst) VALUES (?, ?, ?)'
        );
        $stmt->execute([$teacherId, $instituteId, $role]);
        return $stmt->rowCount() > 0;
    }

    public function unlinkTeacher(int $teacherId, int $instituteId): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM teacher_institutes WHERE user_id = ? AND institute_id = ?'
        );
        $stmt->execute([$teacherId, $instituteId]);
        return $stmt->rowCount() > 0;
    }
}
