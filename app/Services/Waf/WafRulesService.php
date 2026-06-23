<?php

declare(strict_types=1);

namespace App\Services\Waf;

use App\Core\Database;
use PDO;

/**
 * WAF Rules Engine — Cloudflare-style custom rules.
 *
 * Schema waf_rules.conditions (JSON):
 *   {
 *     "logic": "AND" | "OR",
 *     "conditions": [
 *       { "field": "ip"|"country"|"asn"|"user_agent"|"url"|"referer"|"cookie"|"method",
 *         "operator": "equals"|"contains"|"matches_regex"|"is_in_list"|"starts_with"|"ends_with",
 *         "value": "string or list" }
 *     ]
 *   }
 *
 * Action: "allow" | "challenge" | "block" | "log_only"
 *
 * Le rules vengono valutate in ordine di `priority` ASC (più bassa = prima).
 * La prima rule che matcha determina l'azione.
 */
final class WafRulesService
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /**
     * Valuta tutte le rule abilitate contro il contesto della request.
     *
     * @param array{ip:string,country:?string,asn:?string,user_agent:string,
     *              url:string,referer:string,cookie:string,method:string} $ctx
     * @return array{action:string,rule_id:int,rule_name:string}|null null se nessuna rule matcha
     */
    public function evaluate(array $ctx): ?array
    {
        $rules = $this->listEnabled();
        foreach ($rules as $rule) {
            try {
                $conds = json_decode((string)$rule['conditions'], true, 8, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($conds)) {
                continue;
            }
            if ($this->matches($conds, $ctx)) {
                $this->incrementHit((int)$rule['id']);
                return [
                    'action'    => (string)$rule['action'],
                    'rule_id'   => (int)$rule['id'],
                    'rule_name' => (string)$rule['name'],
                ];
            }
        }
        return null;
    }

    /**
     * Valuta un nodo `conditions` JSON contro il context.
     *
     * @param array{logic?:string,conditions?:list<array<string,mixed>>} $node
     * @param array<string,mixed> $ctx
     */
    private function matches(array $node, array $ctx): bool
    {
        $logic = strtoupper((string)($node['logic'] ?? 'AND'));
        $conds = $node['conditions'] ?? [];
        if (!is_array($conds) || empty($conds)) {
            return false;
        }
        foreach ($conds as $c) {
            $hit = $this->matchSingle((array)$c, $ctx);
            if ($logic === 'OR' && $hit) {
                return true;
            }
            if ($logic === 'AND' && !$hit) {
                return false;
            }
        }
        return $logic === 'AND';
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,mixed> $ctx
     */
    private function matchSingle(array $c, array $ctx): bool
    {
        $field = (string)($c['field'] ?? '');
        $op    = (string)($c['operator'] ?? '');
        $value = $c['value'] ?? '';
        $actual = (string)($ctx[$field] ?? '');

        return match ($op) {
            'equals'         => $actual === (string)$value,
            'contains'       => $value !== '' && str_contains($actual, (string)$value),
            'starts_with'    => $value !== '' && str_starts_with($actual, (string)$value),
            'ends_with'      => $value !== '' && str_ends_with($actual, (string)$value),
            'matches_regex'  => is_string($value) && self::safeRegexMatch($value, $actual),
            'is_in_list'     => is_array($value) && in_array($actual, array_map('strval', $value), true),
            'ip_in_cidr'     => is_string($value) && GeoIpService::ipInCidr($actual, $value),
            // Phase 25.I — match against threat-intel ASN category table.
            // $field deve essere 'asn' (es. ctx['asn']='AS207043'), $value = nome categoria
            // (hosting/vpn/tor/cdn/malware).
            'asn_in_category' => is_string($value) && $value !== ''
                && (new WafThreatIntelService())->asnInCategory($actual, $value),
            default          => false,
        };
    }

    /**
     * Match regex anti-ReDoS: limita la lunghezza del subject e abbassa il
     * backtrack limit PCRE per la durata del match, così un pattern
     * catastrofico (admin compromesso/errore) non blocca il worker FPM.
     */
    public static function safeRegexMatch(string $pattern, string $subject): bool
    {
        if ($pattern === '' || strlen($pattern) > 512) {
            return false;
        }
        // Subject limitato: i campi WAF (UA/URL/referer) sono corti per natura;
        // troncare elimina l'esplosione combinatoria su input lunghi.
        if (strlen($subject) > 2048) {
            $subject = substr($subject, 0, 2048);
        }
        $prevBt = ini_get('pcre.backtrack_limit');
        $prevRc = ini_get('pcre.recursion_limit');
        @ini_set('pcre.backtrack_limit', '50000');
        @ini_set('pcre.recursion_limit', '5000');
        try {
            $delim = '/' . str_replace('/', '\\/', $pattern) . '/u';
            $r = @preg_match($delim, $subject);
            // preg_last_error() != 0 → backtrack limit colpito → no match (safe).
            return $r === 1 && preg_last_error() === PREG_NO_ERROR;
        } finally {
            if ($prevBt !== false) {
                @ini_set('pcre.backtrack_limit', (string)$prevBt);
            }
            if ($prevRc !== false) {
                @ini_set('pcre.recursion_limit', (string)$prevRc);
            }
        }
    }

    /**
     * Valida una condizione regex al salvataggio: pattern compilabile,
     * lunghezza ragionevole, e dry-run che non colpisce il backtrack limit
     * su un input avversariale di prova.
     */
    public static function isRegexConditionSafe(string $pattern): bool
    {
        if ($pattern === '' || strlen($pattern) > 512) {
            return false;
        }
        $delim = '/' . str_replace('/', '\\/', $pattern) . '/u';
        if (@preg_match($delim, '') === false) {
            return false; // pattern non compilabile
        }
        // Dry-run su input "cattivo" (ripetizioni) per scovare blow-up.
        $bad = str_repeat('a', 2048) . '!';
        return self::safeRegexMatch($pattern, $bad) !== null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listEnabled(): array
    {
        try {
            $stmt = $this->db()->query(
                'SELECT id, name, description, priority, conditions, action, match_count
                 FROM waf_rules WHERE enabled = 1 ORDER BY priority ASC, id ASC'
            );
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listAll(): array
    {
        try {
            $stmt = $this->db()->query(
                'SELECT id, name, description, enabled, priority, conditions, action, match_count,
                        created_at, updated_at
                 FROM waf_rules ORDER BY priority ASC, id ASC'
            );
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function find(int $id): ?array
    {
        try {
            $stmt = $this->db()->prepare('SELECT * FROM waf_rules WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{name:string,description?:string,enabled?:bool,priority?:int,
     *              conditions:array<string,mixed>,action:string} $data
     */
    public function create(array $data, ?int $userId = null): int
    {
        $sql = 'INSERT INTO waf_rules (name, description, enabled, priority, conditions, action, created_by)
                VALUES (:name, :desc, :en, :prio, :cond, :act, :uid)';
        $stmt = $this->db()->prepare($sql);
        $stmt->execute([
            ':name' => $data['name'],
            ':desc' => $data['description'] ?? null,
            ':en'   => (int)($data['enabled'] ?? true),
            ':prio' => (int)($data['priority'] ?? 100),
            ':cond' => json_encode($data['conditions'], JSON_THROW_ON_ERROR),
            ':act'  => $data['action'],
            ':uid'  => $userId,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * @param array{name?:string,description?:string,enabled?:bool,priority?:int,
     *              conditions?:array<string,mixed>,action?:string} $data
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['name', 'description', 'enabled', 'priority', 'action'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = :$f";
                $params[":$f"] = is_bool($data[$f]) ? (int)$data[$f] : $data[$f];
            }
        }
        if (array_key_exists('conditions', $data)) {
            $fields[] = 'conditions = :conditions';
            $params[':conditions'] = json_encode($data['conditions'], JSON_THROW_ON_ERROR);
        }
        if (empty($fields)) {
            return false;
        }
        $sql = 'UPDATE waf_rules SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->db()->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        try {
            $stmt = $this->db()->prepare('DELETE FROM waf_rules WHERE id = ?');
            return $stmt->execute([$id]);
        } catch (\Throwable) {
            return false;
        }
    }

    public function setEnabled(int $id, bool $enabled): bool
    {
        try {
            $stmt = $this->db()->prepare('UPDATE waf_rules SET enabled = ? WHERE id = ?');
            return $stmt->execute([(int)$enabled, $id]);
        } catch (\Throwable) {
            return false;
        }
    }

    private function incrementHit(int $id): void
    {
        try {
            $this->db()->prepare('UPDATE waf_rules SET match_count = match_count + 1 WHERE id = ?')
                ->execute([$id]);
        } catch (\Throwable) {
        }
    }

    // === Blacklist / Whitelist ===

    /**
     * @return list<string> Lista CIDR/IP whitelist non scaduti
     */
    public function whitelistCidrs(): array
    {
        try {
            $stmt = $this->db()->query(
                "SELECT ip_or_cidr FROM waf_whitelisted_ips
                 WHERE expires_at IS NULL OR expires_at > NOW()"
            );
            return $stmt ? array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    public function blacklistCidrs(): array
    {
        try {
            $stmt = $this->db()->query(
                "SELECT ip_or_cidr FROM waf_blocked_ips
                 WHERE expires_at IS NULL OR expires_at > NOW()"
            );
            return $stmt ? array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function addBlacklist(string $ipOrCidr, ?string $reason, ?\DateTimeImmutable $expiresAt, ?int $userId): bool
    {
        try {
            $stmt = $this->db()->prepare(
                'INSERT INTO waf_blocked_ips (ip_or_cidr, reason, expires_at, created_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE reason=VALUES(reason), expires_at=VALUES(expires_at)'
            );
            return $stmt->execute([
                $ipOrCidr,
                $reason,
                $expiresAt?->format('Y-m-d H:i:s'),
                $userId,
            ]);
        } catch (\Throwable) {
            return false;
        }
    }

    public function addWhitelist(string $ipOrCidr, ?string $reason, ?\DateTimeImmutable $expiresAt, ?int $userId): bool
    {
        try {
            $stmt = $this->db()->prepare(
                'INSERT INTO waf_whitelisted_ips (ip_or_cidr, reason, expires_at, created_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE reason=VALUES(reason), expires_at=VALUES(expires_at)'
            );
            return $stmt->execute([
                $ipOrCidr,
                $reason,
                $expiresAt?->format('Y-m-d H:i:s'),
                $userId,
            ]);
        } catch (\Throwable) {
            return false;
        }
    }

    public function deleteBlacklist(int $id): bool
    {
        try {
            return $this->db()->prepare('DELETE FROM waf_blocked_ips WHERE id = ?')->execute([$id]);
        } catch (\Throwable) {
            return false;
        }
    }

    public function deleteWhitelist(int $id): bool
    {
        try {
            return $this->db()->prepare('DELETE FROM waf_whitelisted_ips WHERE id = ?')->execute([$id]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listBlacklist(): array
    {
        try {
            $stmt = $this->db()->query(
                'SELECT * FROM waf_blocked_ips ORDER BY created_at DESC'
            );
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listWhitelist(): array
    {
        try {
            $stmt = $this->db()->query(
                'SELECT * FROM waf_whitelisted_ips ORDER BY created_at DESC'
            );
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
