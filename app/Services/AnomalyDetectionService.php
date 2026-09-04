<?php

namespace App\Services;

use App\Core\Config;

/**
 * Anomaly detection (Phase 13) — porting modernizzato di
 * `log/security/alerts/alert_functions.php::detectAnomalousAccessForAlerts`
 * dal ramo master.
 *
 * Rileva:
 *   - **excessive_access** (DoS-like): troppi accessi dallo stesso
 *     (ip + section) entro time_window_hours → soglia configurabile.
 *     Risk levels: low/medium/high in base a #accessi.
 *   - **credential_sharing**: stesso username usato da N IP distinti
 *     entro la finestra → indica condivisione credenziali / leak.
 *
 * Source: `log/data/access_log.json` (legacy-compatible).
 *
 * Config: legge `log/security/alerts/config.json` se presente, altrimenti
 * usa i defaults (mirror master). Output array di anomalie con tutti i
 * dettagli necessari per l'UI admin.
 */
final class AnomalyDetectionService
{
    public function __construct(
        private readonly string $accessLogPath,
        private readonly string $configPath,
        private readonly string $blockedCredsPath,
        private readonly string $blockedIpsPath,
    ) {
    }

    public static function default(): self
    {
        $base = (string)Config::get('app.paths.base', dirname(__DIR__, 2));
        // Phase 25.J — AccessLogger scrive in app.paths.logs (default
        // storage/logs/), NON nel vecchio log/data/. Allineato il reader.
        // Fallback al path legacy se storage/logs non esiste (back-compat).
        $logsDir = (string)Config::get('app.paths.logs', $base . '/storage/logs');
        $modernAccessLog = $logsDir . '/access_log.json';
        $legacyAccessLog = $base . '/log/data/access_log.json';
        $accessLog = is_file($modernAccessLog) ? $modernAccessLog : $legacyAccessLog;

        return new self(
            accessLogPath:    $accessLog,
            configPath:       $base . '/log/security/alerts/config.json',
            blockedCredsPath: $base . '/log/data/blocked_credentials.json',
            blockedIpsPath:   $base . '/log/data/blocked_ips.json',
        );
    }

    /**
     * @return list<array{
     *   type: string, risk_level: string, ip?: string, username?: string,
     *   section?: string, count: int, ips?: list<string>, first_seen: string,
     *   last_seen: string, blocked: bool, fingerprint: string
     * }>
     */
    public function detect(): array
    {
        $cfg     = $this->loadConfig();
        $log     = $this->readJson($this->accessLogPath) ?? [];
        $blocked = ['credentials' => $this->blockedUsernames(), 'ips' => $this->blockedIps()];

        return array_merge(
            $this->detectExcessiveAccess($log, $cfg, $blocked),
            $this->detectCredentialSharing($log, $cfg, $blocked),
        );
    }

    public function summary(): array
    {
        $alerts = $this->detect();
        $byType = ['excessive_access' => 0, 'credential_sharing' => 0];
        $active = 0; // attivi = non già bloccati
        foreach ($alerts as $a) {
            $byType[$a['type']] = ($byType[$a['type']] ?? 0) + 1;
            if (!$a['blocked']) {
                $active++;
            }
        }
        return [
            'total'              => count($alerts),
            'active'             => $active,
            'excessive_access'   => $byType['excessive_access'],
            'credential_sharing' => $byType['credential_sharing'],
            'generated_at'       => date('c'),
        ];
    }

    // ──────────── Excessive access (DoS-like) ────────────

    /** @return list<array> */
    private function detectExcessiveAccess(array $log, array $cfg, array $blocked): array
    {
        $sec = $cfg['security_alerts']['excessive_access'] ?? [];
        if (!($sec['enabled'] ?? true)) {
            return [];
        }
        $windowH   = (int)($sec['time_window_hours'] ?? 24);
        $cutoff    = time() - $windowH * 3600;
        $thresh    = (int)($sec['threshold_per_section'] ?? 3);
        $riskLvls  = $sec['risk_levels'] ?? [
            'low'    => ['min_accesses' => 3,  'max_accesses' => 25],
            'medium' => ['min_accesses' => 26, 'max_accesses' => 50],
            'high'   => ['min_accesses' => 51, 'max_accesses' => 999999],
        ];

        // Group by (ip, section)
        $groups = [];
        foreach ($log as $row) {
            $ip   = (string)($row['ip_address'] ?? '');
            $sect = $this->sectionCode($row);
            if ($ip === '' || $sect === '') {
                continue;
            }
            $t = strtotime((string)($row['timestamp'] ?? ''));
            if ($t < $cutoff) {
                continue;
            }
            $key = $ip . '|' . $sect;
            $groups[$key] ??= [
                'ip' => $ip, 'section' => $sect, 'count' => 0,
                'first_ts' => $t, 'last_ts' => $t,
                'usernames' => [],
            ];
            $groups[$key]['count']++;
            $groups[$key]['first_ts'] = min($groups[$key]['first_ts'], $t);
            $groups[$key]['last_ts']  = max($groups[$key]['last_ts'], $t);
            $u = (string)($row['username'] ?? '');
            if ($u !== '' && !in_array($u, $groups[$key]['usernames'], true)) {
                $groups[$key]['usernames'][] = $u;
            }
        }

        $out = [];
        foreach ($groups as $g) {
            if ($g['count'] < $thresh) {
                continue;
            }
            $out[] = [
                'type'        => 'excessive_access',
                'risk_level'  => $this->classifyRisk($g['count'], $riskLvls, 'min_accesses', 'max_accesses'),
                'ip'          => $g['ip'],
                'section'     => $g['section'],
                'usernames'   => $g['usernames'],
                'count'       => $g['count'],
                'first_seen'  => date('Y-m-d H:i:s', $g['first_ts']),
                'last_seen'   => date('Y-m-d H:i:s', $g['last_ts']),
                'blocked'     => $this->isIpBlockedForSection($g['ip'], $g['section'], $blocked['ips']),
                'fingerprint' => 'ea:' . hash('sha256', $g['ip'] . '|' . $g['section']),
            ];
        }
        return $out;
    }

    // ──────────── Credential sharing (stesso user, N IP distinti) ────────────

    /** @return list<array> */
    private function detectCredentialSharing(array $log, array $cfg, array $blocked): array
    {
        $sec = $cfg['security_alerts']['credential_sharing'] ?? [];
        if (!($sec['enabled'] ?? true)) {
            return [];
        }
        $windowH       = (int)($sec['time_window_hours'] ?? 24);
        $cutoff        = time() - $windowH * 3600;
        $minIps        = (int)($sec['min_ips_required']  ?? 5);
        $minPerIp      = (int)($sec['min_accesses_per_ip'] ?? 2);
        $riskLvls      = $sec['risk_levels'] ?? [
            'low'    => ['min_ips' => 5,  'max_ips' => 7],
            'medium' => ['min_ips' => 8,  'max_ips' => 10],
            'high'   => ['min_ips' => 11, 'max_ips' => 999999],
        ];

        // Group by username → ip → count
        $byUser = [];
        foreach ($log as $row) {
            $u  = (string)($row['username'] ?? '');
            $ip = (string)($row['ip_address'] ?? '');
            if ($u === '' || $u === 'guest' || $ip === '') {
                continue;
            }
            $t = strtotime((string)($row['timestamp'] ?? ''));
            if ($t < $cutoff) {
                continue;
            }
            $byUser[$u] ??= ['ips' => [], 'first_ts' => $t, 'last_ts' => $t];
            $byUser[$u]['ips'][$ip] = ($byUser[$u]['ips'][$ip] ?? 0) + 1;
            $byUser[$u]['first_ts'] = min($byUser[$u]['first_ts'], $t);
            $byUser[$u]['last_ts']  = max($byUser[$u]['last_ts'], $t);
        }

        $out = [];
        foreach ($byUser as $u => $d) {
            // IPs that have >= minPerIp accesses
            $qualifyingIps = array_keys(array_filter($d['ips'], fn($n) => $n >= $minPerIp));
            $nIps = count($qualifyingIps);
            if ($nIps < $minIps) {
                continue;
            }
            $out[] = [
                'type'        => 'credential_sharing',
                'risk_level'  => $this->classifyRisk($nIps, $riskLvls, 'min_ips', 'max_ips'),
                'username'    => $u,
                'ips'         => $qualifyingIps,
                'count'       => array_sum($d['ips']),
                'first_seen'  => date('Y-m-d H:i:s', $d['first_ts']),
                'last_seen'   => date('Y-m-d H:i:s', $d['last_ts']),
                'blocked'     => in_array($u, $blocked['credentials'], true),
                'fingerprint' => 'cs:' . hash('sha256', $u),
            ];
        }
        return $out;
    }

    // ──────────── Helpers ────────────

    private function classifyRisk(int $value, array $levels, string $minKey, string $maxKey): string
    {
        foreach (['high', 'medium', 'low'] as $lvl) {
            $r = $levels[$lvl] ?? null;
            if (!$r) {
                continue;
            }
            if ($value >= ($r[$minKey] ?? 0) && $value <= ($r[$maxKey] ?? PHP_INT_MAX)) {
                return $lvl;
            }
        }
        return 'low';
    }

    private function sectionCode(array $row): string
    {
        $i = (string)($row['institute_code'] ?? '');
        $c = (string)($row['class_code']     ?? '');
        if ($i === '' && $c === '') {
            return '';
        }
        return $i . $c;
    }

    /** @return list<string> */
    private function blockedUsernames(): array
    {
        $rows = $this->readJson($this->blockedCredsPath) ?? [];
        return array_values(array_filter(array_map(
            static fn($r) => is_array($r) ? (string)($r['username'] ?? '') : '',
            $rows,
        )));
    }

    /** @return list<array{ip:string,section?:string}> */
    private function blockedIps(): array
    {
        $rows = $this->readJson($this->blockedIpsPath) ?? [];
        return array_values(array_filter($rows, 'is_array'));
    }

    private function isIpBlockedForSection(string $ip, string $section, array $blockedIps): bool
    {
        foreach ($blockedIps as $b) {
            if (($b['ip'] ?? '') !== $ip) {
                continue;
            }
            $s = (string)($b['section'] ?? '');
            if ($s === '' || $s === $section) {
                return true;
            }
        }
        return false;
    }

    private function loadConfig(): array
    {
        $data = $this->readJson($this->configPath);
        return is_array($data) ? $data : [];
    }

    private function readJson(string $path): mixed
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        return json_decode($raw, true);
    }
}
