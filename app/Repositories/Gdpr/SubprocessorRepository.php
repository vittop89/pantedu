<?php

declare(strict_types=1);

namespace App\Repositories\Gdpr;

use App\Core\Database;
use PDO;

/**
 * Phase 25.R.4.1 — Repository per `subprocessors` (DPA art. 9, GDPR Art. 28).
 */
final class SubprocessorRepository
{
    /** @return list<array<string,mixed>> */
    public function listAll(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM subprocessors';
        if ($onlyActive) {
            $sql .= ' WHERE active = 1';
        }
        $sql .= ' ORDER BY name';
        return Database::connection()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM subprocessors WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(array $data): int
    {
        $this->validate($data);
        $stmt = Database::connection()->prepare(
            'INSERT INTO subprocessors
                (name, service_description, country, extra_eu_transfer,
                 transfer_safeguards, dpa_signed, dpa_url, contact_email, notes, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            trim((string)$data['name']),
            trim((string)$data['service_description']),
            trim((string)$data['country']),
            !empty($data['extra_eu_transfer']) ? 1 : 0,
            $data['transfer_safeguards'] !== '' ? trim((string)$data['transfer_safeguards']) : null,
            !empty($data['dpa_signed']) ? 1 : 0,
            $data['dpa_url']       !== '' ? trim((string)$data['dpa_url'])       : null,
            $data['contact_email'] !== '' ? trim((string)$data['contact_email']) : null,
            ($data['notes'] ?? '') !== '' ? trim((string)$data['notes']) : null,
            !empty($data['active']) ? 1 : 0,
        ]);
        return (int)Database::connection()->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $this->validate($data);
        $stmt = Database::connection()->prepare(
            'UPDATE subprocessors SET
                name = ?, service_description = ?, country = ?,
                extra_eu_transfer = ?, transfer_safeguards = ?, dpa_signed = ?,
                dpa_url = ?, contact_email = ?, notes = ?, active = ?
             WHERE id = ?'
        );
        return $stmt->execute([
            trim((string)$data['name']),
            trim((string)$data['service_description']),
            trim((string)$data['country']),
            !empty($data['extra_eu_transfer']) ? 1 : 0,
            $data['transfer_safeguards'] !== '' ? trim((string)$data['transfer_safeguards']) : null,
            !empty($data['dpa_signed']) ? 1 : 0,
            $data['dpa_url']       !== '' ? trim((string)$data['dpa_url'])       : null,
            $data['contact_email'] !== '' ? trim((string)$data['contact_email']) : null,
            ($data['notes'] ?? '') !== '' ? trim((string)$data['notes']) : null,
            !empty($data['active']) ? 1 : 0,
            $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM subprocessors WHERE id = ?'
        );
        return $stmt->execute([$id]);
    }

    /** Validation guard contro injection — pattern coerente con InstituteRepository. */
    private function validate(array $data): void
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            throw new \InvalidArgumentException('name 1-160 char required');
        }
        if (preg_match('#[<>\\\\;&`]|\\$\\{|<!--#u', $name)) {
            throw new \InvalidArgumentException('name contiene caratteri non ammessi');
        }
        $svc = trim((string)($data['service_description'] ?? ''));
        if ($svc === '' || mb_strlen($svc) > 255) {
            throw new \InvalidArgumentException('service_description 1-255 char required');
        }
        $country = trim((string)($data['country'] ?? ''));
        if ($country === '' || mb_strlen($country) > 64) {
            throw new \InvalidArgumentException('country 1-64 char required');
        }
        $email = trim((string)($data['contact_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('contact_email non valida');
        }
        $url = trim((string)($data['dpa_url'] ?? ''));
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('dpa_url non valido');
        }
    }
}
