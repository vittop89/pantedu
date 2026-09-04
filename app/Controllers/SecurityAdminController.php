<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\AnomalyDetectionService;
use App\Services\Waf\WafSecurityRepository;
use Throwable;

/**
 * Admin Security panel — Phase 25.F modernization.
 *
 * Storage modernizzato: tabelle DB `waf_blocked_credentials` + `waf_blocked_ips`
 * (via WafSecurityRepository) sono source of truth. I file JSON legacy
 * `log/data/blocked_*.json` restano sincronizzati DB→JSON come read-cache
 * per AuthCode legacy che ancora li legge (back-compat).
 *
 *   GET  /api/admin/security/blocked-credentials   → list (da DB)
 *   GET  /api/admin/security/blocked-ips           → list (da DB)
 *   POST /api/admin/security/credentials/block     → body: { username }
 *   POST /api/admin/security/credentials/unblock   → body: { username }
 *   POST /api/admin/security/ips/block             → body: { ip, section?, reason? }
 *   POST /api/admin/security/ips/unblock           → body: { ip, section? }
 */
final class SecurityAdminController
{
    private string $accessLogPath;
    private string $alertConfigPath;
    private ?WafSecurityRepository $sec;

    public function __construct(
        ?string $accessLogPath = null,
        ?string $alertConfigPath = null,
        ?WafSecurityRepository $sec = null,
    ) {
        $base = (string)Config::get('app.paths.base', dirname(__DIR__, 2));
        $this->accessLogPath   = $accessLogPath   ?? ($base . '/log/data/access_log.json');
        $this->alertConfigPath = $alertConfigPath ?? ($base . '/log/security/alerts/config.json');
        $this->sec             = $sec;
    }

    private function sec(): WafSecurityRepository
    {
        return $this->sec ??= new WafSecurityRepository();
    }

    /** GET /api/admin/security/config — soglie anomaly detection. */
    public function getConfig(Request $req): Response
    {
        $cfg = $this->readConfig();
        return Response::json(['ok' => true, 'config' => $cfg]);
    }

    /**
     * POST /api/admin/security/config — update soglie.
     * Body keys (tutti opzionali; null/blank = lascia invariato):
     *   ea_enabled, ea_threshold_per_section, ea_time_window_hours,
     *   ea_low_min, ea_low_max, ea_medium_min, ea_medium_max, ea_high_min,
     *   cs_enabled, cs_min_ips_required, cs_min_accesses_per_ip,
     *   cs_time_window_hours, cs_low_min, cs_low_max, cs_medium_min,
     *   cs_medium_max, cs_high_min
     */
    public function setConfig(Request $req): Response
    {
        $cfg = $this->readConfig();
        $cfg['security_alerts'] ??= [];
        $cfg['security_alerts']['excessive_access'] ??= [];
        $cfg['security_alerts']['credential_sharing'] ??= [];

        $ea = &$cfg['security_alerts']['excessive_access'];
        $cs = &$cfg['security_alerts']['credential_sharing'];

        $this->setBool($ea, 'enabled', $req->post['ea_enabled'] ?? null);
        $this->setInt($ea, 'threshold_per_section', $req->post['ea_threshold_per_section'] ?? null);
        $this->setInt($ea, 'time_window_hours', $req->post['ea_time_window_hours']     ?? null);
        $ea['risk_levels'] ??= [];
        $this->setRiskLevel($ea['risk_levels'], 'low', 'min_accesses', 'max_accesses', $req->post, 'ea_low_min', 'ea_low_max');
        $this->setRiskLevel($ea['risk_levels'], 'medium', 'min_accesses', 'max_accesses', $req->post, 'ea_medium_min', 'ea_medium_max');
        $this->setRiskLevel($ea['risk_levels'], 'high', 'min_accesses', 'max_accesses', $req->post, 'ea_high_min', null);

        $this->setBool($cs, 'enabled', $req->post['cs_enabled'] ?? null);
        $this->setInt($cs, 'min_ips_required', $req->post['cs_min_ips_required']    ?? null);
        $this->setInt($cs, 'min_accesses_per_ip', $req->post['cs_min_accesses_per_ip'] ?? null);
        $this->setInt($cs, 'time_window_hours', $req->post['cs_time_window_hours']   ?? null);
        $cs['risk_levels'] ??= [];
        $this->setRiskLevel($cs['risk_levels'], 'low', 'min_ips', 'max_ips', $req->post, 'cs_low_min', 'cs_low_max');
        $this->setRiskLevel($cs['risk_levels'], 'medium', 'min_ips', 'max_ips', $req->post, 'cs_medium_min', 'cs_medium_max');
        $this->setRiskLevel($cs['risk_levels'], 'high', 'min_ips', 'max_ips', $req->post, 'cs_high_min', null);

        try {
            $dir = dirname($this->alertConfigPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $tmp = $this->alertConfigPath . '.tmp';
            file_put_contents($tmp, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            @rename($tmp, $this->alertConfigPath);
            return Response::json(['ok' => true, 'config' => $cfg]);
        } catch (Throwable $e) {
            return Response::json(['error' => 'persist_failed', 'detail' => $e->getMessage()], 500);
        }
    }

    private function setBool(array &$target, string $key, mixed $val): void
    {
        if ($val === null || $val === '') {
            return;
        }
        $target[$key] = in_array(strtolower((string)$val), ['1', 'true', 'on', 'yes'], true);
    }
    private function setInt(array &$target, string $key, mixed $val): void
    {
        if ($val === null || $val === '') {
            return;
        }
        $target[$key] = max(0, (int)$val);
    }
    private function setRiskLevel(
        array &$risks,
        string $lvl,
        string $minK,
        string $maxK,
        array $post,
        string $minPostKey,
        ?string $maxPostKey,
    ): void {
        $risks[$lvl] ??= [];
        if (isset($post[$minPostKey]) && $post[$minPostKey] !== '') {
            $risks[$lvl][$minK] = (int)$post[$minPostKey];
        }
        if ($maxPostKey !== null && isset($post[$maxPostKey]) && $post[$maxPostKey] !== '') {
            $risks[$lvl][$maxK] = (int)$post[$maxPostKey];
        } elseif ($maxPostKey === null) {
            $risks[$lvl][$maxK] = 999999;
        }
    }
    private function readConfig(): array
    {
        if (!is_file($this->alertConfigPath)) {
            return [];
        }
        $raw = @file_get_contents($this->alertConfigPath);
        $d = json_decode((string)$raw, true);
        return is_array($d) ? $d : [];
    }

    public function listBlockedCredentials(Request $req): Response
    {
        $rows = $this->sec()->listBlockedCredentials();
        // Map alla shape contract pre-existing (username, blocked_at, reason, blocked_by)
        $out = array_map(static fn(array $r) => [
            'username'   => $r['username'],
            'blocked_at' => $r['blocked_at'],
            'reason'     => $r['reason'] ?? '',
            'blocked_by' => $r['blocked_by'] ?? 'system',
            'source'     => $r['source'] ?? 'manual',
        ], $rows);
        return Response::json(['ok' => true, 'rows' => $out]);
    }

    public function listBlockedIps(Request $req): Response
    {
        $rows = $this->sec()->listBlockedIpsAuth();
        $usersByIp = $this->buildIpToUsernamesMap();
        $geoip = new \App\Services\Waf\GeoIpService(
            (string)Config::get('waf.geoip_db', ''),
            (string)Config::get('waf.geoip_asn_db', ''),
        );
        // Enrich rDNS+ASN se ?enrich=1 (admin toggle).
        $enrich = !empty($req->query['enrich']);
        $out = [];
        foreach ($rows as $r) {
            $ip = (string)($r['ip'] ?? '');
            $isIp = filter_var($ip, FILTER_VALIDATE_IP);
            $country = $isIp ? $geoip->lookup($ip) : null;
            $row = [
                'ip'                   => $ip,
                'country'              => $country,
                'country_flag'         => \App\Services\Waf\GeoIpService::countryFlag($country),
                'section'              => $r['section'] ?? null,
                'blocked_at'           => $r['blocked_at'] ?? null,
                'reason'               => $r['reason'] ?? '',
                'blocked_by'           => $r['blocked_by'] ?? 'system',
                'source'               => $r['source'] ?? 'manual',
                'associated_usernames' => $usersByIp[$ip] ?? [],
            ];
            if ($enrich && $isIp) {
                $e = $geoip->enrich($ip);
                $row['rdns'] = $e['rdns'];
                $row['asn']  = $e['asn'];
                $row['org']  = $e['org'];
            }
            $out[] = $row;
        }
        return Response::json(['ok' => true, 'rows' => $out, 'enrich' => $enrich]);
    }

    /** @return array<string, list<string>> */
    private function buildIpToUsernamesMap(): array
    {
        $log = $this->readArray($this->accessLogPath);
        $cutoff = time() - 30 * 86400;
        $byIp = [];
        foreach ($log as $row) {
            $ip = (string)($row['ip_address'] ?? '');
            $u  = (string)($row['username']   ?? '');
            if ($ip === '' || $u === '' || $u === 'guest') {
                continue;
            }
            $t  = strtotime((string)($row['timestamp'] ?? ''));
            if ($t < $cutoff) {
                continue;
            }
            $byIp[$ip] ??= [];
            if (!in_array($u, $byIp[$ip], true)) {
                $byIp[$ip][] = $u;
            }
        }
        return $byIp;
    }

    public function blockCredential(Request $req): Response
    {
        $u = trim((string)($req->post['username'] ?? ''));
        $reason = trim((string)($req->post['reason'] ?? 'manual_block_admin'));
        if ($u === '') {
            return Response::json(['error' => 'missing_username'], 400);
        }
        if ($u === 'admin') {
            return Response::json(['error' => 'cannot_block_admin'], 403);
        }
        if ($this->sec()->isCredentialBlocked($u)) {
            return Response::json(['ok' => true, 'already_blocked' => true]);
        }
        $by = (string)(Auth::user()['username'] ?? 'admin');
        $ok = $this->sec()->blockCredential($u, $reason, $by, null, 'manual');
        return $ok
            ? Response::json(['ok' => true])
            : Response::json(['error' => 'persist_failed'], 500);
    }

    public function blockIp(Request $req): Response
    {
        $ip      = trim((string)($req->post['ip']      ?? ''));
        $section = trim((string)($req->post['section'] ?? ''));
        $reason  = trim((string)($req->post['reason']  ?? 'manual_block_admin'));
        if ($ip === '') {
            return Response::json(['error' => 'missing_ip'], 400);
        }
        if ($this->sec()->isIpBlockedForSection($ip, $section)) {
            return Response::json(['ok' => true, 'already_blocked' => true]);
        }
        $by  = (string)(Auth::user()['username'] ?? 'admin');
        $uid = (int)(Auth::user()['id'] ?? 0) ?: null;
        $ok = $this->sec()->blockIp(
            $ip,
            $section !== '' ? $section : null,
            $reason,
            $uid,
            $by,
            null,
            'manual',
        );
        return $ok
            ? Response::json(['ok' => true])
            : Response::json(['error' => 'persist_failed'], 500);
    }

    public function unblockCredential(Request $req): Response
    {
        $u = trim((string)($req->post['username'] ?? ''));
        if ($u === '') {
            return Response::json(['error' => 'missing_username'], 400);
        }
        $removed = $this->sec()->unblockCredential($u);
        return Response::json(['ok' => true, 'removed' => $removed]);
    }

    /**
     * GET /api/admin/security/anomalies
     * Detection real-time da access_log:
     *   - excessive_access (DoS-like): troppi accessi stesso IP+sezione
     *   - credential_sharing: stesso username da N IP distinti
     */
    public function anomalies(Request $req): Response
    {
        $svc = AnomalyDetectionService::default();
        return Response::json([
            'ok'      => true,
            'rows'    => $svc->detect(),
            'summary' => $svc->summary(),
        ]);
    }

    /**
     * GET /api/admin/security/live-blocks
     *
     * Vista cross-source di IP bloccati nelle ultime N ore. Aggrega da
     * `waf_logs` TUTTI gli outcome `blocked_*` (manual, geo, threat_intel,
     * crowdsec, score, rule) per risolvere la frammentazione tra `/blocks`
     * (solo waf_blacklist + waf_blocked_ips) e `/reports` (visione log).
     *
     * Cross-check con `waf_blocked_ips` per flaggare quali IP sono già in
     * blacklist permanente vs solo bloccati al volo (geo/threat/score).
     *
     * Query: `?hours=24&limit=100` (default 24h, max 500).
     */
    public function liveBlocks(Request $req): Response
    {
        $hours = max(1, min(168, (int)($req->query['hours'] ?? 24)));
        $limit = max(1, min(500, (int)($req->query['limit'] ?? 100)));

        $logSvc = new \App\Services\Waf\WafLogService();
        $rows   = $logSvc->liveBlocks($hours, $limit);

        $rulesSvc = new \App\Services\Waf\WafRulesService();
        $blacklistSet = [];
        foreach ($rulesSvc->listBlacklist() as $b) {
            $blacklistSet[(string)($b['ip_or_cidr'] ?? '')] = true;
        }
        $whitelistSet = [];
        foreach ($rulesSvc->listWhitelist() as $w) {
            $whitelistSet[(string)($w['ip_or_cidr'] ?? '')] = true;
        }

        $geoip = new \App\Services\Waf\GeoIpService(
            (string)Config::get('waf.geoip_db', '') ?: null,
            (string)Config::get('waf.geoip_asn_db', '') ?: null,
        );

        $out = [];
        foreach ($rows as $r) {
            $ip = (string)($r['ip'] ?? '');
            $out[] = [
                'ip'              => $ip,
                'country'         => $r['country'] ?? null,
                'country_flag'    => \App\Services\Waf\GeoIpService::countryFlag($r['country'] ?? null),
                'last_outcome'    => $r['last_outcome'] ?? '',
                'sources'         => $r['sources'] ?? '',
                'count'           => (int)($r['count'] ?? 0),
                'first_seen'      => $r['first_seen'] ?? null,
                'last_seen'       => $r['last_seen'] ?? null,
                'in_blacklist'    => isset($blacklistSet[$ip]),
                'in_whitelist'    => isset($whitelistSet[$ip]),
            ];
        }
        return Response::json([
            'ok'    => true,
            'hours' => $hours,
            'rows'  => $out,
        ]);
    }

    public function unblockIp(Request $req): Response
    {
        $ip      = trim((string)($req->post['ip']      ?? ''));
        $section = trim((string)($req->post['section'] ?? ''));
        if ($ip === '') {
            return Response::json(['error' => 'missing_ip'], 400);
        }
        $removed = $this->sec()->unblockIp($ip, $section !== '' ? $section : null);
        return Response::json(['ok' => true, 'removed' => $removed]);
    }

    /** @return list<array> */
    private function readArray(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        $data = json_decode((string)$raw, true);
        return is_array($data) ? array_values($data) : [];
    }
}
