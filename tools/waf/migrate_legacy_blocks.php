<?php

declare(strict_types=1);

/**
 * tools/waf/migrate_legacy_blocks.php — Phase 25.F one-shot import.
 *
 * Importa contenuto JSON legacy in tabelle WAF DB:
 *   log/data/blocked_credentials.json → waf_blocked_credentials
 *   log/data/blocked_ips.json         → waf_blocked_ips (con `section`)
 *
 * Usage:
 *   php tools/waf/migrate_legacy_blocks.php           # DRY-RUN
 *   php tools/waf/migrate_legacy_blocks.php --apply   # esegui INSERT
 *
 * Idempotente: usa INSERT IGNORE su UNIQUE keys (username / ip+section).
 * Side-effect: dopo --apply, WafSecurityRepository riscriverà i JSON
 * dalla DB al primo accesso admin (DB → JSON sync).
 */

require_once __DIR__ . '/../../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: solo CLI.\n");
    exit(1);
}

$apply = in_array('--apply', $argv, true);
$base  = (string)\App\Core\Config::get('app.paths.base', $baseDir);

$pdo = \App\Core\Database::connection();

$credPath = $base . '/log/data/blocked_credentials.json';
$ipsPath  = $base . '/log/data/blocked_ips.json';

printf("=== WAF legacy blocks migration (mode: %s) ===\n", $apply ? 'APPLY' : 'DRY-RUN');
printf("DB:          %s\n", \App\Core\Config::get('db.database', '?'));
printf("Cred JSON:   %s (%s)\n", $credPath, is_file($credPath) ? 'EXISTS' : 'MISSING');
printf("IPs JSON:    %s (%s)\n\n", $ipsPath, is_file($ipsPath) ? 'EXISTS' : 'MISSING');

// === Credentials ===
$credRows = is_file($credPath) ? (json_decode((string)file_get_contents($credPath), true) ?: []) : [];
$credExisting = (int)$pdo->query("SELECT COUNT(*) FROM waf_blocked_credentials")->fetchColumn();
printf("[creds] JSON rows: %d  |  DB rows: %d\n", count($credRows), $credExisting);

$credImported = 0;
foreach ($credRows as $r) {
    $u = trim((string)($r['username'] ?? ''));
    if ($u === '') continue;
    $reason = (string)($r['reason'] ?? 'legacy_import');
    $blockedAt = $r['blocked_at'] ?? null;
    $blockedBy = (string)($r['blocked_by'] ?? 'system');

    if ($apply) {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO waf_blocked_credentials
                (username, reason, blocked_at, blocked_by, source)
             VALUES (?, ?, COALESCE(?, NOW()), ?, 'legacy_json')"
        );
        $t = is_string($blockedAt) ? strtotime($blockedAt) : 0;
        $stmt->execute([
            $u,
            $reason,
            $t > 0 ? date('Y-m-d H:i:s', $t) : null,
            $blockedBy,
        ]);
        if ($stmt->rowCount() > 0) $credImported++;
    } else {
        printf("  [DRY] cred: %s (reason=%s, by=%s)\n", $u, $reason, $blockedBy);
        $credImported++;
    }
}
printf("[creds] %s: %d\n\n", $apply ? 'imported' : 'would import', $credImported);

// === IPs ===
$ipRows = is_file($ipsPath) ? (json_decode((string)file_get_contents($ipsPath), true) ?: []) : [];
$ipExisting = (int)$pdo->query("SELECT COUNT(*) FROM waf_blocked_ips WHERE source = 'legacy_json'")->fetchColumn();
printf("[ips]   JSON rows: %d  |  DB rows (legacy_json source): %d\n", count($ipRows), $ipExisting);

$ipImported = 0;
foreach ($ipRows as $r) {
    $ip = trim((string)($r['ip'] ?? ''));
    if ($ip === '') continue;
    $section = (string)($r['section'] ?? '');
    $reason = (string)($r['reason'] ?? 'legacy_import');
    $blockedAt = $r['blocked_at'] ?? null;

    if ($apply) {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO waf_blocked_ips
                (ip_or_cidr, section, reason, created_at, source)
             VALUES (?, ?, ?, COALESCE(?, NOW()), 'legacy_json')"
        );
        $t = is_string($blockedAt) ? strtotime($blockedAt) : 0;
        $stmt->execute([
            $ip,
            $section !== '' ? $section : null,
            $reason,
            $t > 0 ? date('Y-m-d H:i:s', $t) : null,
        ]);
        if ($stmt->rowCount() > 0) $ipImported++;
    } else {
        printf("  [DRY] ip:   %s (section=%s, reason=%s)\n", $ip, $section ?: '(none)', $reason);
        $ipImported++;
    }
}
printf("[ips]   %s: %d\n\n", $apply ? 'imported' : 'would import', $ipImported);

if (!$apply) {
    echo "Nessuna modifica al DB. Esegui con --apply per importare.\n";
} else {
    echo "Import completato. WafSecurityRepository ri-sincronizzerà i JSON\n";
    echo "al primo accesso a /admin/waf/blocks#credentials (DB → JSON, AuthCode back-compat).\n";
}
