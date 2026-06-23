<?php
/**
 * Phase 25.D10 — Audit report su crypto_access_log.
 *
 * Genera 4 report per monitoring/SOC review:
 *
 *   1. **Cross-teacher access**: super_admin che decifra body di altri
 *      docenti (legitimate solo con `reason` valido — flag NULL/empty).
 *   2. **High-volume accessors**: ranking accessor_id per #operations in
 *      un intervallo (24h default). Spike = potenziale incident.
 *   3. **Failed operations**: outcome='error' o 'denied' (decrypt mismatch,
 *      kek missing, tag tampering).
 *   4. **Shred operations**: log Art. 17 GDPR — chi ha cancellato cosa,
 *      con motivazione.
 *
 * Output: tabella console + opzionale --json per integrazione monitoring
 * (Grafana/Datadog/Splunk via webhook).
 *
 * Usage:
 *   php tools/crypto/audit_report.php
 *   php tools/crypto/audit_report.php --since=24h --json > audit-$(date +%F).json
 *   php tools/crypto/audit_report.php --since=7d --teacher=77
 *
 * Cron suggested:
 *   0 6 * * * php tools/crypto/audit_report.php --since=24h --json | curl -X POST \
 *     -H 'Content-Type: application/json' -d @- $SOC_WEBHOOK_URL
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

if (PHP_SAPI !== 'cli') exit(1);

$since = '24h';
$teacher = null;
$jsonOut = false;
foreach ($argv as $arg) {
    if (preg_match('/^--since=(\d+)([hdw])$/', $arg, $m)) {
        $n = (int)$m[1];
        $u = $m[2];
        $since = ($u === 'h') ? "$n HOUR" : (($u === 'd') ? "$n DAY" : "$n WEEK");
    }
    if (preg_match('/^--teacher=(\d+)$/', $arg, $m)) $teacher = (int)$m[1];
    if ($arg === '--json') $jsonOut = true;
}

$db = Database::connection();
$teacherFilter = $teacher !== null ? "AND teacher_id=$teacher" : "";

// 1. Cross-teacher access (accessor != teacher, super_admin reading altrui)
$crossAccess = $db->query("
    SELECT cal.accessor_id, ua.username AS accessor_name,
           cal.teacher_id, ut.username AS teacher_name,
           cal.operation, cal.reason, cal.outcome, cal.accessed_at
    FROM crypto_access_log cal
    LEFT JOIN users ua ON ua.id = cal.accessor_id
    LEFT JOIN users ut ON ut.id = cal.teacher_id
    WHERE cal.accessor_id != cal.teacher_id
      AND cal.accessed_at > DATE_SUB(NOW(), INTERVAL $since)
      $teacherFilter
    ORDER BY cal.accessed_at DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// 2. High-volume accessors (top 10)
$highVolume = $db->query("
    SELECT accessor_id, u.username,
           COUNT(*) as ops_count,
           SUM(CASE WHEN operation='encrypt' THEN 1 ELSE 0 END) as enc_count,
           SUM(CASE WHEN operation='decrypt' THEN 1 ELSE 0 END) as dec_count,
           SUM(CASE WHEN operation='shred'   THEN 1 ELSE 0 END) as shred_count
    FROM crypto_access_log cal
    LEFT JOIN users u ON u.id = cal.accessor_id
    WHERE cal.accessed_at > DATE_SUB(NOW(), INTERVAL $since)
      $teacherFilter
    GROUP BY accessor_id, u.username
    ORDER BY ops_count DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Failed operations
$failures = $db->query("
    SELECT accessor_id, teacher_id, operation, reason, outcome, accessed_at,
           u.username AS accessor_name
    FROM crypto_access_log cal
    LEFT JOIN users u ON u.id = cal.accessor_id
    WHERE outcome IN ('error', 'denied')
      AND cal.accessed_at > DATE_SUB(NOW(), INTERVAL $since)
      $teacherFilter
    ORDER BY cal.accessed_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// 4. Shred operations (Art. 17 GDPR)
$shreds = $db->query("
    SELECT accessor_id, ua.username AS accessor_name,
           teacher_id, ut.username AS teacher_name,
           reason, accessed_at
    FROM crypto_access_log cal
    LEFT JOIN users ua ON ua.id = cal.accessor_id
    LEFT JOIN users ut ON ut.id = cal.teacher_id
    WHERE operation = 'shred'
      AND cal.accessed_at > DATE_SUB(NOW(), INTERVAL $since)
      $teacherFilter
    ORDER BY cal.accessed_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

if ($jsonOut) {
    echo json_encode([
        'since'         => $since,
        'teacher'       => $teacher,
        'generated_at'  => date('c'),
        'cross_access'  => $crossAccess,
        'high_volume'   => $highVolume,
        'failures'      => $failures,
        'shreds'        => $shreds,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(0);
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  CRYPTO AUDIT REPORT (Phase 25.D10)\n";
echo "  Period: last $since" . ($teacher !== null ? "  · teacher_id=$teacher" : "") . "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "\n";

// ─────── 1. Cross-teacher access ───────
echo "▌ 1. Cross-teacher access (super_admin reading altrui content)\n";
if (empty($crossAccess)) {
    echo "    ✓ Nessuno (no super_admin reading altrui in window).\n";
} else {
    echo "    " . count($crossAccess) . " entry(es). Top 10:\n";
    foreach (array_slice($crossAccess, 0, 10) as $r) {
        $reasonStatus = empty($r['reason']) ? '⚠️ NO REASON' : trim($r['reason']);
        printf("    %s | %s → tid=%s (%s) | %s | %s\n",
            $r['accessed_at'], ($r['accessor_name'] ?? "id={$r['accessor_id']}"),
            $r['teacher_id'], ($r['teacher_name'] ?? '?'),
            $r['operation'], substr($reasonStatus, 0, 50));
    }
}
echo "\n";

// ─────── 2. High-volume accessors ───────
echo "▌ 2. High-volume accessors (top 10)\n";
foreach ($highVolume as $r) {
    printf("    %5d ops  %-30s  enc=%d dec=%d shred=%d\n",
        $r['ops_count'], ($r['username'] ?? "id={$r['accessor_id']}"),
        $r['enc_count'], $r['dec_count'], $r['shred_count']);
}
echo "\n";

// ─────── 3. Failures ───────
echo "▌ 3. Failed operations (errors / denied)\n";
if (empty($failures)) {
    echo "    ✓ Nessuno.\n";
} else {
    foreach (array_slice($failures, 0, 20) as $r) {
        printf("    %s | %s | tid=%s | %s | %s\n",
            $r['accessed_at'], $r['outcome'], $r['teacher_id'],
            $r['operation'], substr($r['reason'] ?? '?', 0, 60));
    }
    if (count($failures) > 20) {
        echo "    ... +" . (count($failures) - 20) . " more (use --json for full)\n";
    }
}
echo "\n";

// ─────── 4. Shreds (Art. 17 GDPR) ───────
echo "▌ 4. Crypto-shredding operations (Art. 17 GDPR)\n";
if (empty($shreds)) {
    echo "    ✓ Nessuno.\n";
} else {
    foreach ($shreds as $r) {
        printf("    %s | accessor=%s | teacher=%s | reason: %s\n",
            $r['accessed_at'], ($r['accessor_name'] ?? "id={$r['accessor_id']}"),
            ($r['teacher_name'] ?? "id={$r['teacher_id']}"),
            substr($r['reason'] ?? '(no reason)', 0, 60));
    }
}
echo "\n";

// ─────── Summary ───────
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Summary:\n";
echo "    Cross-teacher access:  " . count($crossAccess) . "\n";
echo "    Failed operations:     " . count($failures) . "\n";
echo "    Shreds (Art. 17):      " . count($shreds) . "\n";

// Alert flag
$alerts = 0;
$crossNoReason = array_filter($crossAccess, fn($r) => empty($r['reason']));
if (count($crossNoReason) > 0) {
    echo "\n    🚨 ALERT: " . count($crossNoReason) . " cross-teacher access SENZA reason\n";
    echo "       (super_admin che ha decifrato body altrui senza giustificare).\n";
    echo "       Switch AUDIT_REASON_MODE=enforce in produzione per blocco.\n";
    $alerts++;
}
if (count($failures) > 10) {
    echo "\n    ⚠️ WARNING: $alerts+ failures in window — possibile tampering o KEK corrotta.\n";
    $alerts++;
}
if ($alerts === 0) {
    echo "\n    ✓ Nessuna anomalia nel periodo.\n";
}
