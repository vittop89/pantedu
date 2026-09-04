<?php
/**
 * Phase 17 — Worker CLI per la `jobs` queue.
 *
 * Uso:
 *   php bin/worker.php               # loop continuo su queue=default
 *   php bin/worker.php --once        # esegue tutti i pending ora + esce
 *   php bin/worker.php --queue=mail  # lavora solo sulla queue "mail"
 *   php bin/worker.php --max-jobs=10 # esce dopo N job (per tenere RAM bassa)
 *
 * Production: lancia via cron ogni minuto:
 *   * * * * * cd /path && php bin/worker.php --once --max-jobs=50 >> logs/worker.log 2>&1
 *
 * Handler naming: `$handler` nel DB è una classe FQN che implementa
 * `App\Jobs\Job`. Il worker la instanzia con `new $handler()` (no DI per ora).
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Jobs\JobRepository;

Config::load(__DIR__ . '/../app/Config');

// Args parsing basic
$args = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--') && str_contains($a, '=')) {
        [$k, $v] = explode('=', substr($a, 2), 2);
        $args[$k] = $v;
    } elseif (str_starts_with($a, '--')) {
        $args[substr($a, 2)] = true;
    }
}
$queue    = (string)($args['queue']    ?? 'default');
$once     = (bool)  ($args['once']     ?? false);
$maxJobs  = (int)   ($args['max-jobs'] ?? 0);
$sleep    = (int)   ($args['sleep']    ?? 2);

$workerId = gethostname() . ':' . getmypid();
$repo = new JobRepository();

fprintf(STDERR, "[worker %s] start queue=%s once=%d max=%d\n",
    $workerId, $queue, $once ? 1 : 0, $maxJobs);

$processed = 0;
$running = true;
pcntl_async_signals(true);
foreach ([SIGINT, SIGTERM] as $sig) {
    pcntl_signal($sig, function () use (&$running) {
        fwrite(STDERR, "[worker] shutdown signal received\n");
        $running = false;
    });
}

while ($running) {
    try {
        $job = $repo->reserve($queue, $workerId);
    } catch (\Throwable $e) {
        fprintf(STDERR, "[worker] reserve fail: %s\n", $e->getMessage());
        sleep($sleep);
        continue;
    }
    if (!$job) {
        if ($once) break;
        sleep($sleep);
        continue;
    }
    $id = (int)$job['id'];
    $handler = (string)$job['handler'];
    $payload = json_decode((string)$job['payload'], true) ?: [];
    $t0 = microtime(true);
    try {
        if (!class_exists($handler)) {
            throw new \RuntimeException("handler_class_not_found:$handler");
        }
        $impl = new $handler();
        if (!($impl instanceof \App\Jobs\Job)) {
            throw new \RuntimeException("handler_not_implements_Job:$handler");
        }
        $impl->handle($payload);
        $repo->complete($id);
        $dt = round((microtime(true) - $t0) * 1000);
        fprintf(STDERR, "[worker] job=%d handler=%s done %dms\n", $id, $handler, $dt);
    } catch (\Throwable $e) {
        $repo->fail($id, substr($e->getMessage(), 0, 1000));
        fprintf(STDERR, "[worker] job=%d handler=%s FAIL %s\n", $id, $handler, $e->getMessage());
    }
    $processed++;
    if ($maxJobs > 0 && $processed >= $maxJobs) {
        fprintf(STDERR, "[worker] max-jobs reached, exit\n");
        break;
    }
}

fprintf(STDERR, "[worker %s] stop processed=%d\n", $workerId, $processed);
