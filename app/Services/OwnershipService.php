<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Maps content paths (mappe / eser / lab / verifiche) to the teacher
 * that owns them. Backed by storage/data/ownership.json.
 * Schema:
 *   { owners: { "<username>": { mappe: [...], eser: [...], lab: [...], verifiche: [...] } } }
 */
final class OwnershipService
{
    public const KINDS = ['mappe', 'eser', 'lab', 'verifiche'];

    public function __construct(private readonly string $jsonPath)
    {
    }

    private function useDb(): bool
    {
        return Config::get('database.enabled') && Database::isAvailable();
    }

    private function userId(string $username): ?int
    {
        $id = \App\Support\TeacherContextResolver::userIdFromUsername($username);
        return $id > 0 ? $id : null;
    }

    /** @return array<string, list<string>> */
    public function listFor(string $username): array
    {
        if ($this->useDb()) {
            $uid = $this->userId($username);
            $out = array_fill_keys(self::KINDS, []);
            if ($uid === null) {
                return $out;
            }
            $stmt = Database::connection()->prepare(
                'SELECT kind, path FROM ownership WHERE user_id = ? ORDER BY created_at DESC'
            );
            $stmt->execute([$uid]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($out[$row['kind']])) {
                    $out[$row['kind']][] = $row['path'];
                }
            }
            return $out;
        }
        $data = $this->read();
        $rows = $data['owners'][$username] ?? [];
        $out  = [];
        foreach (self::KINDS as $k) {
            $out[$k] = array_values(array_unique(array_map('strval', $rows[$k] ?? [])));
        }
        return $out;
    }

    public function counts(string $username): array
    {
        $out = [];
        foreach ($this->listFor($username) as $k => $items) {
            $out[$k] = count($items);
        }
        return $out;
    }

    public function assign(string $username, string $kind, string $path): void
    {
        $this->assertKind($kind);
        if ($this->useDb()) {
            $uid = $this->userId($username);
            if ($uid === null) {
                return; // utente non in DB → nessun assign
            }
            $stmt = Database::connection()->prepare(
                'INSERT IGNORE INTO ownership (user_id, kind, path) VALUES (?,?,?)'
            );
            $stmt->execute([$uid, $kind, $path]);
            if (Config::get('database.dual_write')) {
                $this->jsonAssign($username, $kind, $path);
            }
            return;
        }
        $this->jsonAssign($username, $kind, $path);
    }

    public function unassign(string $username, string $kind, string $path): bool
    {
        $this->assertKind($kind);
        if ($this->useDb()) {
            $uid = $this->userId($username);
            if ($uid === null) {
                return false;
            }
            $stmt = Database::connection()->prepare(
                'DELETE FROM ownership WHERE user_id = ? AND kind = ? AND path = ?'
            );
            $stmt->execute([$uid, $kind, $path]);
            $deleted = $stmt->rowCount() > 0;
            if (Config::get('database.dual_write')) {
                $this->jsonUnassign($username, $kind, $path);
            }
            return $deleted;
        }
        return $this->jsonUnassign($username, $kind, $path);
    }

    private function jsonAssign(string $username, string $kind, string $path): void
    {
        $data = $this->read();
        $data['owners'][$username][$kind] ??= [];
        if (!\in_array($path, $data['owners'][$username][$kind], true)) {
            $data['owners'][$username][$kind][] = $path;
            $this->write($data);
        }
    }

    private function jsonUnassign(string $username, string $kind, string $path): bool
    {
        $data = $this->read();
        $list = $data['owners'][$username][$kind] ?? [];
        $kept = array_values(array_filter($list, fn($p) => $p !== $path));
        if (count($kept) === count($list)) {
            return false;
        }
        $data['owners'][$username][$kind] = $kept;
        $this->write($data);
        return true;
    }

    private function assertKind(string $kind): void
    {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('invalid_ownership_kind');
        }
    }

    private function read(): array
    {
        if (!is_file($this->jsonPath)) {
            return ['owners' => []];
        }
        $data = json_decode((string)file_get_contents($this->jsonPath), true);
        return \is_array($data) ? $data : ['owners' => []];
    }

    private function write(array $data): void
    {
        $dir = dirname($this->jsonPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot_create_dir');
        }
        $tmp = $this->jsonPath . '.tmp';
        if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw new RuntimeException('write_failed');
        }
        if (!rename($tmp, $this->jsonPath)) {
            @unlink($tmp);
            throw new RuntimeException('rename_failed');
        }
    }
}
