<?php

declare(strict_types=1);

namespace App\Services\Waf;

use App\Core\Config;
use App\Core\Database;
use PDO;
use Throwable;

/**
 * WAF Threat Intelligence — Phase 25.I.
 *
 * Stack 5-layer di import periodici da fonti pubbliche curate:
 *
 *   Layer 1: ASN bulk (brianhama/bad-asn-list)        → waf_asn_categories
 *   Layer 2: Spamhaus DROP+EDROP (CIDR malware)       → waf_threat_cidrs (block)
 *   Layer 3: X4BNet/lists_vpn (VPN/proxy IPs)         → waf_threat_ips (challenge)
 *   Layer 4: CrowdSec community blocklist (real-time) → waf_threat_ips (block)
 *   Layer 5: Tor exit nodes                           → waf_threat_ips (challenge)
 *
 * Pattern import (idempotente):
 *   1. Crea row sync_log con status='running'
 *   2. Download sorgente (curl)
 *   3. Parse + bulk INSERT IGNORE
 *   4. DELETE righe stesso source con imported_at < (NOW - TTL)
 *      (pulizia automatica entries non più nella sorgente)
 *   5. Update sync_log con status='ok'/'fail'
 *
 * TTL: 7 giorni default (refresh sostituisce entries esistenti
 * aggiornando imported_at via ON DUPLICATE KEY UPDATE).
 *
 * Performance:
 *   - waf_threat_ips: PK(ip,source) → O(1) lookup
 *   - waf_threat_cidrs: ~700 max Spamhaus → linear scan accettabile
 *   - waf_asn_categories: PK(asn,source) → O(1) lookup
 *
 * Usage:
 *   php tools/waf/sync_threat_intel.php [--source=all|asn|spamhaus|x4b|crowdsec|tor]
 *   php tools/waf/sync_threat_intel.php --dry-run
 */
final class WafThreatIntelService
{
    private const DEFAULT_TTL_DAYS = 7;

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    // ========================================================
    // LOOKUP API (called by WafMiddleware on every request)
    // ========================================================

    /**
     * Check IP against threat_ips. O(1) lookup via PK.
     *
     * @return array{action:string, source:string, reason:?string}|null
     */
    public function checkIp(string $ip): ?array
    {
        try {
            $sql = "SELECT action, source, reason FROM waf_threat_ips
                    WHERE ip = ?
                    AND (expires_at IS NULL OR expires_at > NOW())
                    ORDER BY FIELD(action, 'block', 'challenge', 'log_only') LIMIT 1";
            $stmt = $this->db()->prepare($sql);
            $stmt->execute([$ip]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Check IP against threat_cidrs. Linear scan ~few hundred rows.
     *
     * @return array{action:string, source:string, reason:?string, cidr:string}|null
     */
    public function checkCidr(string $ip): ?array
    {
        try {
            $sql = "SELECT cidr, action, source, reason FROM waf_threat_cidrs
                    WHERE (expires_at IS NULL OR expires_at > NOW())";
            $rows = $this->db()->query($sql)?->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                if (GeoIpService::ipInCidr($ip, (string)$r['cidr'])) {
                    return $r;
                }
            }
            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * True se ASN appartiene a categoria specifica (hosting/vpn/tor/cdn/malware).
     */
    public function asnInCategory(string $asnStr, string $category): bool
    {
        $asn = (int)ltrim($asnStr, 'AS');
        if ($asn <= 0) {
            return false;
        }
        try {
            $sql = "SELECT 1 FROM waf_asn_categories
                    WHERE asn = ? AND category = ?
                    AND (expires_at IS NULL OR expires_at > NOW())
                    LIMIT 1";
            $stmt = $this->db()->prepare($sql);
            $stmt->execute([$asn, $category]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    // ========================================================
    // IMPORTERS (called by cron)
    // ========================================================

    /**
     * Layer 1: brianhama/bad-asn-list (CSV: asn,entity)
     */
    public function importBadAsnList(int $ttlDays = self::DEFAULT_TTL_DAYS): array
    {
        $url = 'https://raw.githubusercontent.com/brianhama/bad-asn-list/master/bad-asn-list.csv';
        $logId = $this->startSyncLog('bad_asn_list');
        try {
            $csv = $this->fetch($url);
            $lines = preg_split('/\r?\n/', $csv) ?: [];
            $imported = 0;
            $stmt = $this->db()->prepare(
                'INSERT INTO waf_asn_categories (asn, category, org, source, expires_at)
                 VALUES (?, "hosting", ?, "bad_asn_list", DATE_ADD(NOW(), INTERVAL ? DAY))
                 ON DUPLICATE KEY UPDATE
                    org = VALUES(org),
                    imported_at = CURRENT_TIMESTAMP,
                    expires_at = VALUES(expires_at)'
            );
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, 'ASN') || str_starts_with($line, '#')) {
                    continue;
                }
                // CSV format: ASN,Entity
                if (!preg_match('/^(\d+)\s*,\s*(.*)$/', $line, $m)) {
                    continue;
                }
                $asn = (int)$m[1];
                $org = trim($m[2], '"');
                if ($asn <= 0) {
                    continue;
                }
                // Hetzner AS24940 ESCLUSO: gira il VPS stesso
                if ($asn === 24940) {
                    continue;
                }
                $stmt->execute([$asn, $org, $ttlDays]);
                $imported++;
            }
            $pruned = $this->pruneSource('waf_asn_categories', 'bad_asn_list');
            $this->finishSyncLog($logId, 'ok', $imported, $pruned);
            return ['ok' => true, 'imported' => $imported, 'pruned' => $pruned];
        } catch (Throwable $e) {
            $this->finishSyncLog($logId, 'fail', 0, 0, $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Layer 2: Spamhaus DROP + EDROP (CIDR malware/botnet)
     */
    public function importSpamhaus(int $ttlDays = self::DEFAULT_TTL_DAYS): array
    {
        $urls = [
            'https://www.spamhaus.org/drop/drop.txt',
            'https://www.spamhaus.org/drop/edrop.txt',
        ];
        $logId = $this->startSyncLog('spamhaus_drop');
        try {
            $imported = 0;
            $stmt = $this->db()->prepare(
                'INSERT INTO waf_threat_cidrs (cidr, source, action, reason, expires_at)
                 VALUES (?, "spamhaus_drop", "block", ?, DATE_ADD(NOW(), INTERVAL ? DAY))
                 ON DUPLICATE KEY UPDATE
                    reason = VALUES(reason),
                    imported_at = CURRENT_TIMESTAMP,
                    expires_at = VALUES(expires_at)'
            );
            foreach ($urls as $url) {
                $txt = $this->fetch($url);
                $lines = preg_split('/\r?\n/', $txt) ?: [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                        continue;
                    }
                    // Format: "1.2.3.0/24 ; SBL123456"
                    if (!preg_match('/^(\S+)\s*;\s*(.*)$/', $line, $m)) {
                        continue;
                    }
                    $cidr = $m[1];
                    $reason = trim($m[2]);
                    if (!str_contains($cidr, '/')) {
                        continue;
                    }
                    $stmt->execute([$cidr, $reason, $ttlDays]);
                    $imported++;
                }
            }
            $pruned = $this->pruneSource('waf_threat_cidrs', 'spamhaus_drop');
            $this->finishSyncLog($logId, 'ok', $imported, $pruned);
            return ['ok' => true, 'imported' => $imported, 'pruned' => $pruned];
        } catch (Throwable $e) {
            $this->finishSyncLog($logId, 'fail', 0, 0, $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Layer 3: X4BNet/lists_vpn (VPN provider IPs, challenge mode).
     */
    public function importX4bVpn(int $ttlDays = self::DEFAULT_TTL_DAYS): array
    {
        $url = 'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/output/vpn/ipv4.txt';
        $logId = $this->startSyncLog('x4b_vpn');
        try {
            $txt = $this->fetch($url);
            $lines = preg_split('/\r?\n/', $txt) ?: [];
            $imported = 0;
            // X4B fornisce sia IP singoli che CIDR. Splittiamo i due insert.
            $stmtIp = $this->db()->prepare(
                'INSERT INTO waf_threat_ips (ip, source, action, reason, expires_at)
                 VALUES (?, "x4b_vpn", "challenge", "VPN provider IP", DATE_ADD(NOW(), INTERVAL ? DAY))
                 ON DUPLICATE KEY UPDATE imported_at = CURRENT_TIMESTAMP, expires_at = VALUES(expires_at)'
            );
            $stmtCidr = $this->db()->prepare(
                'INSERT INTO waf_threat_cidrs (cidr, source, action, reason, expires_at)
                 VALUES (?, "x4b_vpn", "challenge", "VPN provider CIDR", DATE_ADD(NOW(), INTERVAL ? DAY))
                 ON DUPLICATE KEY UPDATE imported_at = CURRENT_TIMESTAMP, expires_at = VALUES(expires_at)'
            );
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (str_contains($line, '/')) {
                    $stmtCidr->execute([$line, $ttlDays]);
                } elseif (filter_var($line, FILTER_VALIDATE_IP)) {
                    $stmtIp->execute([$line, $ttlDays]);
                }
                $imported++;
            }
            $p1 = $this->pruneSource('waf_threat_ips', 'x4b_vpn');
            $p2 = $this->pruneSource('waf_threat_cidrs', 'x4b_vpn');
            $this->finishSyncLog($logId, 'ok', $imported, $p1 + $p2);
            return ['ok' => true, 'imported' => $imported, 'pruned' => $p1 + $p2];
        } catch (Throwable $e) {
            $this->finishSyncLog($logId, 'fail', 0, 0, $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Layer 4: CrowdSec community blocklist (real-time, requires API key).
     *
     * Endpoint: https://api.crowdsec.net/v3/decisions/stream
     * Auth: X-Api-Key header (free signup su app.crowdsec.net)
     */
    public function importCrowdSec(int $ttlDays = 2): array
    {
        $logId = $this->startSyncLog('crowdsec');
        try {
            $apiKey = (string)(new WafConfigRepository())->get('crowdsec_api_key', '');
            if ($apiKey === '') {
                throw new \RuntimeException('crowdsec_api_key non configurato (vai a /admin/waf/config)');
            }
            $url = 'https://api.crowdsec.net/v3/decisions/stream?startup=true';
            $resp = $this->fetch($url, ['X-Api-Key: ' . $apiKey, 'User-Agent: pantedu-waf/25.I']);
            $data = json_decode($resp, true);
            if (!is_array($data)) {
                throw new \RuntimeException('CrowdSec API: response non JSON');
            }
            $imported = 0;
            $stmt = $this->db()->prepare(
                'INSERT INTO waf_threat_ips (ip, source, action, reason, expires_at)
                 VALUES (?, "crowdsec", "block", ?, DATE_ADD(NOW(), INTERVAL ? DAY))
                 ON DUPLICATE KEY UPDATE
                    reason = VALUES(reason),
                    imported_at = CURRENT_TIMESTAMP,
                    expires_at = VALUES(expires_at)'
            );
            foreach ($data['new'] ?? [] as $d) {
                $ip = (string)($d['value'] ?? '');
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    continue;
                }
                $scenario = (string)($d['scenario'] ?? 'crowdsec');
                $stmt->execute([$ip, substr($scenario, 0, 255), $ttlDays]);
                $imported++;
            }
            $pruned = $this->pruneSource('waf_threat_ips', 'crowdsec');
            $this->finishSyncLog($logId, 'ok', $imported, $pruned);
            return ['ok' => true, 'imported' => $imported, 'pruned' => $pruned];
        } catch (Throwable $e) {
            $this->finishSyncLog($logId, 'fail', 0, 0, $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Layer 5: Tor exit nodes (challenge mode).
     */
    public function importTor(int $ttlDays = self::DEFAULT_TTL_DAYS): array
    {
        $url = 'https://check.torproject.org/torbulkexitlist';
        $logId = $this->startSyncLog('tor');
        try {
            $txt = $this->fetch($url);
            $lines = preg_split('/\r?\n/', $txt) ?: [];
            $imported = 0;
            $stmt = $this->db()->prepare(
                'INSERT INTO waf_threat_ips (ip, source, action, reason, expires_at)
                 VALUES (?, "tor", "challenge", "Tor exit node", DATE_ADD(NOW(), INTERVAL ? DAY))
                 ON DUPLICATE KEY UPDATE imported_at = CURRENT_TIMESTAMP, expires_at = VALUES(expires_at)'
            );
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || !filter_var($line, FILTER_VALIDATE_IP)) {
                    continue;
                }
                $stmt->execute([$line, $ttlDays]);
                $imported++;
            }
            $pruned = $this->pruneSource('waf_threat_ips', 'tor');
            $this->finishSyncLog($logId, 'ok', $imported, $pruned);
            return ['ok' => true, 'imported' => $imported, 'pruned' => $pruned];
        } catch (Throwable $e) {
            $this->finishSyncLog($logId, 'fail', 0, 0, $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ========================================================
    // STATS (per admin UI)
    // ========================================================

    /**
     * @return list<array{source:string,tables:string,count:int,last_sync:?string,status:?string,error:?string}>
     */
    public function stats(): array
    {
        try {
            $out = [];
            // Source può popolare multiple tabelle (es. X4BNet sia IP che CIDR).
            $sources = [
                ['source' => 'bad_asn_list',   'tables' => ['waf_asn_categories']],
                ['source' => 'spamhaus_drop',  'tables' => ['waf_threat_cidrs']],
                ['source' => 'x4b_vpn',        'tables' => ['waf_threat_ips', 'waf_threat_cidrs']],
                ['source' => 'crowdsec',       'tables' => ['waf_threat_ips']],
                ['source' => 'tor',            'tables' => ['waf_threat_ips']],
            ];
            foreach ($sources as $s) {
                $count = 0;
                foreach ($s['tables'] as $table) {
                    $cntStmt = $this->db()->prepare(
                        "SELECT COUNT(*) FROM {$table}
                         WHERE source = ? AND (expires_at IS NULL OR expires_at > NOW())"
                    );
                    $cntStmt->execute([$s['source']]);
                    $count += (int)$cntStmt->fetchColumn();
                }

                $logStmt = $this->db()->prepare(
                    "SELECT finished_at, status, error FROM waf_threat_sync_log
                     WHERE source = ? ORDER BY started_at DESC LIMIT 1"
                );
                $logStmt->execute([$s['source']]);
                $log = $logStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $out[] = [
                    'source'    => $s['source'],
                    'tables'    => implode('+', $s['tables']),
                    'count'     => $count,
                    'last_sync' => $log['finished_at'] ?? null,
                    'status'    => $log['status'] ?? null,
                    'error'     => $log['error'] ?? null,
                ];
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    // ========================================================
    // INTERNAL
    // ========================================================

    /**
     * @param list<string> $headers
     */
    private function fetch(string $url, array $headers = []): string
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'pantedu-waf/25.I (+https://beta.pantedu.eu)',
            CURLOPT_HTTPHEADER     => $headers,
        ];
        // Windows CLI fix: PHP non ha CA bundle di default. Usa cacert.pem
        // bundled da Composer (vendor/composer/ca-bundle) se presente.
        $caBundle = $this->detectCaBundle();
        if ($caBundle !== null) {
            $opts[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false || $http >= 400) {
            throw new \RuntimeException("fetch $url failed: HTTP $http $err");
        }
        return (string)$body;
    }

    private function detectCaBundle(): ?string
    {
        // 1. composer/ca-bundle helper (preferred — auto-aggiornato)
        if (class_exists('\\Composer\\CaBundle\\CaBundle')) {
            try {
                /** @psalm-suppress UndefinedClass */
                return \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
            } catch (Throwable) {
            }
        }
        // 2. php.ini openssl.cafile / curl.cainfo
        $iniCa = (string)ini_get('openssl.cafile') ?: (string)ini_get('curl.cainfo');
        if ($iniCa !== '' && is_file($iniCa)) {
            return $iniCa;
        }
        // 3. Path tipici Linux
        foreach (
            [
            '/etc/ssl/certs/ca-certificates.crt',          // Debian/Ubuntu
            '/etc/pki/tls/certs/ca-bundle.crt',            // RHEL/CentOS
            '/etc/ssl/cert.pem',                            // Alpine/macOS
            ] as $p
        ) {
            if (is_file($p)) {
                return $p;
            }
        }
        return null;
    }

    private function pruneSource(string $table, string $source): int
    {
        try {
            // Cancella righe stesso source non più viste in ultimo import
            // (imported_at < ~3 minuti fa = vecchie, sostituite o sparite).
            $stmt = $this->db()->prepare(
                "DELETE FROM $table WHERE source = ? AND imported_at < NOW() - INTERVAL 3 MINUTE"
            );
            $stmt->execute([$source]);
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    private function startSyncLog(string $source): int
    {
        try {
            $stmt = $this->db()->prepare(
                'INSERT INTO waf_threat_sync_log (source, status) VALUES (?, "running")'
            );
            $stmt->execute([$source]);
            return (int)$this->db()->lastInsertId();
        } catch (Throwable) {
            return 0;
        }
    }

    private function finishSyncLog(int $id, string $status, int $imported, int $pruned, ?string $error = null): void
    {
        if ($id === 0) {
            return;
        }
        try {
            $stmt = $this->db()->prepare(
                'UPDATE waf_threat_sync_log
                 SET finished_at = CURRENT_TIMESTAMP,
                     status = ?, rows_imported = ?, rows_pruned = ?, error = ?
                 WHERE id = ?'
            );
            $stmt->execute([$status, $imported, $pruned, $error, $id]);
        } catch (Throwable) {
        }
    }
}
