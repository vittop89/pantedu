<?php
declare(strict_types=1);
/**
 * tikz_prewarm_cache.php — pre-rendering offline dei blocchi TikZ via VPS,
 * con throttling per evitare il rate-limit nginx (20 req/min/IP).
 *
 * Per ogni blocco TikZ nei contracts:
 *   1. Normalize + sha256 → hash key
 *   2. Lookup cache locale (storage/cache/tikz/teacher_77/...)
 *   3. HIT → skip
 *   4. MISS → POST VPS (con throttling 1 req/3s), salva in cache cifrata
 *   5. 503 → retry-after-delay (60s); abort se 5 retry consecutivi
 *   6. 422 → log compile error, skip questo blocco
 *
 * Usage:
 *   php tools/tikz_prewarm_cache.php [--teacher=77] [--limit=N] [--delay=3] [--scope=teacher|public]
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Services\Tikz\TikzRenderService;

$opts = [
    'teacher' => 77,
    'limit'   => null,
    'delay'   => 3,
    'scope'   => 'teacher',
];
foreach ($argv as $a) {
    if (preg_match('/^--(\w+)=(.+)$/', $a, $m)) {
        $opts[$m[1]] = is_numeric($m[2]) ? (int)$m[2] : $m[2];
    }
}

$svc = TikzRenderService::createDefault();
if (!$svc) {
    fwrite(STDERR, "TEX_COMPILE not configured (.env vuoto)\n");
    exit(1);
}

$teacherId = (int)$opts['teacher'];
$delay     = (int)$opts['delay'];
$scope     = (string)$opts['scope'];
$limit     = $opts['limit'] !== null ? (int)$opts['limit'] : null;

// Phase S2 Fase 2 — usa il data_base configurato (PANTEDU_DATA_PATH in prod).
// Fallback al repo per dev locale XAMPP dove i dati vivono dentro storage/.
$dataBase = \App\Core\Config::get('app.paths.data_base', __DIR__ . '/..');
$institutesBase = $dataBase . "/storage/objects/institutes/106/private/{$teacherId}";

// Tutti i tipi di documenti che possono contenere blocchi TikZ:
//   eser/        — esercizi legacy
//   esercizi/    — esercizi modello nuovo (post-G27.contract)
//   verifiche/   — verifiche scolastiche
//   bes/         — moduli BES/DSA
//   lab/         — schede laboratorio
$dirs = array_filter([
    "$institutesBase/eser",
    "$institutesBase/esercizi",
    "$institutesBase/verifiche",
    "$institutesBase/bes",
    "$institutesBase/lab",
], 'is_dir');

if (empty($dirs)) {
    fwrite(STDERR, "[prewarm] no data dirs found under $institutesBase\n");
    exit(0);
}

$stats = [
    'total'      => 0,
    'hit'        => 0,
    'compiled'   => 0,
    'failed_422' => 0,
    'failed_503' => 0,
    'failed_other'=> 0,
    'skipped'    => 0,
];
$errors = [];
$consec503 = 0;
$processed = 0;

// Helper: recursive glob — *.contract.json puo' annidarsi dentro
// sottocartelle materia (MAT, FIS, SCI, ...) o sottosezione (sc1s, sc2s, ...).
function findContracts(string $dir): array {
    $out = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.contract.json')) {
            $out[] = $f->getPathname();
        }
    }
    return $out;
}

foreach ($dirs as $dir) {
    foreach (findContracts($dir) as $path) {
        $j = json_decode(file_get_contents($path), true);
        if (!is_array($j) || empty($j['groups'])) continue;
        foreach ($j['groups'] as $g) {
            if (!isset($g['items'])) continue;
            foreach ($g['items'] as $it) {
                foreach (['question','options','solution','justification'] as $k) {
                    if (!isset($it[$k])) continue;
                    foreach ($it[$k] as $b) {
                        if (($b['type'] ?? '') !== 'tikz') continue;
                        $stats['total']++;
                        if ($limit !== null && $processed >= $limit) {
                            $stats['skipped']++;
                            continue;
                        }
                        $script = (string)($b['script'] ?? '');
                        if ($script === '') continue;

                        $normalized = TikzRenderService::normalize($script);
                        $hash = hash('sha256', $normalized);

                        // Quick cache lookup
                        $svg = $svc->lookup($scope, $teacherId, $hash);
                        if ($svg !== null) {
                            $stats['hit']++;
                            continue;
                        }

                        // Cache miss → render con throttling
                        $processed++;
                        $libs = parseLibs($b['tikz_libs'] ?? '');
                        $extras = parseExtras($b['tex_packages'] ?? '');

                        echo sprintf("[%d/%s] %s ... ",
                            $processed,
                            $limit !== null ? $limit : '∞',
                            substr($hash, 0, 12)
                        );

                        try {
                            $r = $svc->getOrRender(
                                $script,
                                $scope,
                                $teacherId,
                                ['libraries' => $libs, 'extra_packages' => $extras]
                            );
                            $stats['compiled']++;
                            $consec503 = 0;
                            echo sprintf("OK (%dms, %dB svg)\n",
                                $r['duration_ms'] ?? 0, strlen($r['svg'])
                            );
                        } catch (\Throwable $e) {
                            $msg = $e->getMessage();
                            $httpStatus = $e instanceof \App\Services\Tikz\TikzRenderException ? $e->httpStatus() : 0;
                            if ($httpStatus === 503 || str_contains($msg, '503')) {
                                $stats['failed_503']++;
                                $consec503++;
                                echo "503 (consec=$consec503)\n";
                                if ($consec503 >= 5) {
                                    fwrite(STDERR, "\n5 consecutive 503s — aborting (VPS down/rate-limited).\nRetry later: php tools/tikz_prewarm_cache.php\n");
                                    printStats($stats, $errors);
                                    exit(2);
                                }
                                $waitSec = min(60, 10 * $consec503);
                                echo "  → wait {$waitSec}s\n";
                                sleep($waitSec);
                                continue;
                            }
                            // Tutto il resto = compile error reale (422 o log pdflatex)
                            $stats['failed_422']++;
                            $errors[] = [
                                'hash' => $hash,
                                'file' => basename($path),
                                'http' => $httpStatus,
                                'log' => $msg, // intero log per analisi
                                'script_first_300' => substr($script, 0, 300),
                            ];
                            echo "COMPILE FAIL (http=$httpStatus)\n";
                            $consec503 = 0;
                        }

                        // Throttle: aspetta tra le compile per stare sotto rate-limit
                        if ($delay > 0) sleep($delay);
                    }
                }
            }
        }
    }
}

printStats($stats, $errors);

function parseLibs($raw): array {
    if (is_array($raw)) return $raw;
    if ($raw === '') return [];
    return array_filter(array_map('trim', preg_split('/\s*,\s*/', (string)$raw)));
}

function parseExtras($raw): array {
    if (is_array($raw)) return array_keys($raw);
    if (!is_string($raw) || $raw === '' || $raw === '[]') return [];
    $j = json_decode($raw, true);
    if (is_array($j)) return array_keys($j);
    return [];
}

function printStats(array $s, array $errors): void {
    echo "\n=== STATS ===\n";
    foreach ($s as $k => $v) echo "  $k: $v\n";
    if ($errors) {
        echo "\n=== COMPILE ERRORS (first 10) ===\n";
        foreach (array_slice($errors, 0, 10) as $e) {
            echo "\n--- {$e['file']} (hash {$e['hash']}) ---\n";
            echo $e['log'] . "\n";
        }
        // Phase S2 Fase 2 — usa PANTEDU_DATA_PATH (data_base) per il log
        // errori, NON il repo path (che potrebbe essere read-only sotto
        // systemd hardening). mkdir -p del parent se manca.
        $logBase = \App\Core\Config::get('app.paths.data_base', __DIR__ . '/..');
        $errDir = $logBase . '/storage/_tmp';
        if (!is_dir($errDir)) {
            @mkdir($errDir, 0775, true);
        }
        $errPath = $errDir . '/tikz_prewarm_errors.json';
        $written = @file_put_contents($errPath, json_encode($errors, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        if ($written === false) {
            echo "\n[WARN] could not write error log to $errPath (perm/dir?)\n";
        } else {
            echo "\nFull error log: $errPath (" . count($errors) . " entries, {$written} bytes)\n";
        }
    }
}
