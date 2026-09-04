<?php

declare(strict_types=1);

namespace App\Services\Waf;

use App\Core\Database;
use PDO;

/**
 * WAF Config singleton repository (key/value table `waf_config`).
 *
 * Cache in-memory per request (config letta una sola volta a inizio middleware).
 */
final class WafConfigRepository
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /**
     * Tutta la config come array key→value.
     *
     * @return array<string,string>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        try {
            $stmt = $this->db()->query('SELECT config_key, config_value FROM waf_config');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
            $this->cache = is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            $this->cache = [];
        }
        return $this->cache;
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->all()[$key] ?? $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $v = $this->get($key, $default ? '1' : '0');
        return $v === '1' || strtolower($v) === 'true';
    }

    public function getInt(string $key, int $default = 0): int
    {
        $v = $this->get($key, (string)$default);
        return is_numeric($v) ? (int)$v : $default;
    }

    /**
     * @param array<string,string|int|bool> $values
     */
    public function set(array $values, ?int $updatedBy = null): void
    {
        $sql = 'INSERT INTO waf_config (config_key, config_value, updated_by) VALUES (:k, :v, :uid)
                ON DUPLICATE KEY UPDATE config_value=VALUES(config_value), updated_by=VALUES(updated_by)';
        $stmt = $this->db()->prepare($sql);
        foreach ($values as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? '1' : '0';
            }
            $stmt->execute([':k' => $k, ':v' => (string)$v, ':uid' => $updatedBy]);
        }
        $this->cache = null;
    }
}
