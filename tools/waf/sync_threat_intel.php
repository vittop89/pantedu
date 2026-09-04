<?php
/**
 * Phase 25.I — WAF Threat Intelligence master sync.
 *
 * Importa liste pubbliche curate in DB locale. Idempotente:
 *   - INSERT ON DUPLICATE KEY UPDATE (aggiorna imported_at + expires_at)
 *   - pruneSource cancella entries non viste in ultimo import
 *
 * Usage:
 *   php tools/waf/sync_threat_intel.php                  # all sources
 *   php tools/waf/sync_threat_intel.php --source=asn     # solo bad-asn-list
 *   php tools/waf/sync_threat_intel.php --source=spamhaus
 *   php tools/waf/sync_threat_intel.php --source=x4b
 *   php tools/waf/sync_threat_intel.php --source=crowdsec
 *   php tools/waf/sync_threat_intel.php --source=tor
 *
 * Cron consigliato (vedi tools/systemd/waf-threat-intel-*.timer):
 *   - bad-asn-list: settimanale
 *   - spamhaus:     giornaliero
 *   - x4b_vpn:      settimanale
 *   - crowdsec:     ogni 2 ore
 *   - tor:          giornaliero
 *
 * Exit code:
 *   0 = tutto OK
 *   1 = qualcosa è fallito (vedi waf_threat_sync_log per dettagli)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: CLI only.\n");
    exit(2);
}

$source = 'all';
foreach ($argv as $arg) {
    if (preg_match('/^--source=(\w+)$/', $arg, $m)) {
        $source = $m[1];
    }
}

$ti = new \App\Services\Waf\WafThreatIntelService();
$jobs = [
    'asn'      => ['label' => 'brianhama/bad-asn-list', 'method' => 'importBadAsnList'],
    'spamhaus' => ['label' => 'Spamhaus DROP+EDROP',    'method' => 'importSpamhaus'],
    'x4b'      => ['label' => 'X4BNet VPN/proxy',       'method' => 'importX4bVpn'],
    'crowdsec' => ['label' => 'CrowdSec community',     'method' => 'importCrowdSec'],
    'tor'      => ['label' => 'Tor exit nodes',         'method' => 'importTor'],
];

$run = $source === 'all' ? array_keys($jobs) : [$source];
$globalOk = true;

foreach ($run as $key) {
    if (!isset($jobs[$key])) {
        fwrite(STDERR, "Unknown source: $key (valid: all/" . implode('/', array_keys($jobs)) . ")\n");
        exit(2);
    }
    $j = $jobs[$key];
    printf("[%s] %s — fetching…\n", date('H:i:s'), $j['label']);
    $t0 = microtime(true);
    $res = $ti->{$j['method']}();
    $dt = round(microtime(true) - $t0, 2);
    if (!empty($res['ok'])) {
        printf("[%s] ✓ %s — imported=%d pruned=%d (%.2fs)\n",
            date('H:i:s'), $j['label'], $res['imported'] ?? 0, $res['pruned'] ?? 0, $dt);
    } else {
        printf("[%s] ✗ %s — FAILED: %s (%.2fs)\n",
            date('H:i:s'), $j['label'], $res['error'] ?? 'unknown', $dt);
        $globalOk = false;
    }
}

echo "\n=== Stats ===\n";
foreach ($ti->stats() as $s) {
    printf("  %-15s  count=%-7d  last_sync=%s  status=%s\n",
        $s['source'], $s['count'], $s['last_sync'] ?? '—', $s['status'] ?? '—');
}

exit($globalOk ? 0 : 1);
