<?php
/**
 * Pre-warm TikZ cache by invoking TikzRenderService directly per script.
 * Bypasses VPS rate limit by going through local PHP code (which uses
 * the cache layer — only misses go to VPS).
 *
 * Usage: php tools/prewarm_tikz_via_php.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\Tikz\TikzRenderService;

$roots = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

// Collect unique scripts by sha256 of raw source
$seen = [];
$scripts = [];

function walkTikz($node, &$found): void {
    if (is_array($node)) {
        if (isset($node['type']) && $node['type'] === 'tikz' && isset($node['script'])) {
            $found[] = $node;
        }
        foreach ($node as $v) {
            if (is_array($v)) walkTikz($v, $found);
        }
    }
}

function iterFiles($dir): \Generator {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.contract.json')) {
            yield $f->getPathname();
        }
    }
}

foreach ($roots as $root) {
    if (!is_dir($root)) continue;
    foreach (iterFiles($root) as $path) {
        $json = file_get_contents($path);
        $data = json_decode($json, true);
        if (!is_array($data)) continue;
        $found = [];
        walkTikz($data, $found);
        foreach ($found as $blk) {
            $script = (string)$blk['script'];
            if (trim($script) === '') continue;
            $h = sha1($script);
            if (isset($seen[$h])) continue;
            $seen[$h] = true;
            $scripts[] = ['script' => $script, 'libs' => $blk['tikz_libs'] ?? '', 'pkgs' => $blk['tex_packages'] ?? ''];
        }
    }
}

echo "Unique TikZ scripts: " . count($scripts) . "\n";
@ob_end_flush();
@ob_implicit_flush();

$service = TikzRenderService::createDefault();
if ($service === null) {
    fwrite(STDERR, "TIKZ service not configured (TEX_COMPILE_ENDPOINT/SECRET missing)\n");
    exit(1);
}
$ok = 0;
$cached = 0;
$failed = 0;
$start = microtime(true);

foreach ($scripts as $i => $entry) {
    $libs = [];
    if (is_string($entry['libs'])) {
        foreach (explode(',', $entry['libs']) as $l) {
            $l = trim($l);
            if ($l !== '') $libs[] = $l;
        }
    }
    $extras = [];
    if (is_string($entry['pkgs']) && $entry['pkgs'] !== '') {
        $pkgsData = json_decode($entry['pkgs'], true);
        if (is_array($pkgsData)) $extras = array_keys($pkgsData);
    }
    $attempts = 0;
    $maxAttempts = 5;
    $success = false;
    while ($attempts < $maxAttempts) {
        try {
            $result = $service->getOrRender(
                tikzSource: $entry['script'],
                scope: 'public',
                teacherId: 0,
                opts: ['libraries' => $libs, 'extra_packages' => $extras],
            );
            $success = true;
            if (($result['source'] ?? '') === 'cache') {
                $cached++;
            } else {
                $ok++;
                sleep(1);  // 1s between successful compiles (1 req/sec)
            }
            break;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $is503 = str_contains($msg, '503') || str_contains($msg, 'Temporarily Unavailable');
            if ($is503 && $attempts < $maxAttempts - 1) {
                $wait = 30 * ($attempts + 1);  // 30s, 60s, 90s, 120s
                echo "  rate-limited (#$i), wait {$wait}s...\n";
                flush();
                sleep($wait);
                $attempts++;
                continue;
            }
            $failed++;
            $msg2 = substr($msg, 0, 120);
            echo "FAIL #$i: $msg2\n";
            flush();
            sleep(5);
            break;
        }
    }
    if (($i + 1) % 25 === 0) {
        $elapsed = microtime(true) - $start;
        printf("  %d/%d  elapsed=%.0fs  cached=%d compiled=%d failed=%d\n",
            $i + 1, count($scripts), $elapsed, $cached, $ok, $failed);
        flush();
    }
}

$elapsed = microtime(true) - $start;
printf("\nDone in %.0fs.  cached=%d compiled=%d failed=%d\n", $elapsed, $cached, $ok, $failed);
