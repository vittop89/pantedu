<?php
/**
 * Phase 19 — cleanup cron tabella rate_limits.
 * Elimina righe con ts < now - olderThanSeconds (default 1h).
 *
 * Run:
 *   php tools/rate_limit_cleanup.php            # cleanup > 1h
 *   php tools/rate_limit_cleanup.php --older=300  # cleanup > 5 min
 *
 * Cron suggerito: daily 03:15
 *   15 3 * * *  cd /path/to/pantedu && php tools/rate_limit_cleanup.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\RateLimitStore;
use App\Core\Config;

if (!Config::get('database.enabled')) {
    fwrite(STDERR, "DB_ENABLED=false.\n");
    exit(1);
}

$older = 3600;
foreach ($argv as $a) {
    if (\preg_match('/^--older=(\d+)$/', $a, $m)) $older = (int)$m[1];
}

$removed = RateLimitStore::purgeDb($older);
echo "Removed $removed rate_limits rows older than {$older}s.\n";
