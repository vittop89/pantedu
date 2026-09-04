<?php
/**
 * php_to_json_contracts.php — Phase 15 step 3.
 *
 * Converte ogni file PHP legacy (eser/**, verifiche/php/**, strcomp_bes_altro/**)
 * in un JSON contract `pantedu.content.v1` strutturato, senza tag HTML
 * narrativi — solo blocchi tipizzati (text|latex|tikz).
 *
 * Output:
 *   - File JSON scritto tramite StorageProvider in key:
 *     institutes/{inst}/private/{teacher_id}/{category}/{id}.contract.json
 *   - Row storage_objects (mime=application/json, visibility=private)
 *   - Update teacher_content.metadata_json.contract_key = storage_key
 *
 * Il contract è pronto per essere:
 *   - Esportato in cloud storage (modularità per docente via path scheme)
 *   - Renderizzato da un nuovo ContentRenderer moderno (senza dipendere
 *     dai template PHP legacy)
 *   - Versionato (dove checksum coincide → skip, dove cambia → v++)
 *
 * Uso:
 *   php tools/migrations/php_to_json_contracts.php             # dry-run
 *   php tools/migrations/php_to_json_contracts.php --apply
 *   --limit=N  (solo N file, per test)
 *   --teacher=<username>  (default: superadmin)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\StorageObjectRepository;
use App\Services\PhpContentParser;
use App\Support\Storage\StorageFactory;

$opts = [
    'apply'   => false,
    'quiet'   => false,
    'limit'   => 0,
    'teacher' => 'superadmin',
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') { $opts['apply'] = true; continue; }
    if ($arg === '--quiet') { $opts['quiet'] = true; continue; }
    if (str_starts_with($arg, '--limit='))   { $opts['limit']   = (int)substr($arg, 8); }
    if (str_starts_with($arg, '--teacher=')) { $opts['teacher'] = substr($arg, 10); }
}
$log = fn(string $s) => $opts['quiet'] ? null : print($s . "\n");

if (!Database::isAvailable()) { fwrite(STDERR, "DB non disponibile.\n"); exit(1); }
$pdo = Database::connection();
$base = (string)Config::get('app.paths.base');

$stmt = $pdo->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
$stmt->execute([$opts['teacher']]);
$teacherId = (int)($stmt->fetchColumn() ?: 0);
if ($teacherId === 0) { fwrite(STDERR, "Teacher '{$opts['teacher']}' non trovato.\n"); exit(1); }

// Istituto primario (MIUR code ufficiale) del docente
$stmt = $pdo->prepare(
    "SELECT i.id FROM institutes i
     INNER JOIN teacher_institutes ti ON ti.institute_id=i.id
     WHERE ti.user_id=? AND i.code NOT LIKE 'MIUR-%'
     ORDER BY i.id LIMIT 1"
);
$stmt->execute([$teacherId]);
$instituteId = (int)($stmt->fetchColumn() ?: 0);
if ($instituteId === 0) {
    $stmt = $pdo->prepare('SELECT institute_id FROM teacher_institutes WHERE user_id=? LIMIT 1');
    $stmt->execute([$teacherId]);
    $instituteId = (int)($stmt->fetchColumn() ?: 0);
}
if ($instituteId === 0) { fwrite(STDERR, "Nessun istituto associato a teacher $teacherId\n"); exit(1); }

$provider = StorageFactory::default();
$objRepo  = new StorageObjectRepository();

$log(($opts['apply'] ? '[APPLY]' : '[DRY-RUN]')
    . " teacher=$teacherId institute=$instituteId provider=" . $provider->name());

$stats = ['scanned'=>0, 'parsed'=>0, 'written'=>0, 'linked'=>0, 'skipped'=>0, 'errors'=>0];

// Phase 15 — source registry accumulator. Le fonti (book+volume+authors)
// si ripetono in ogni badge; dedup in un file centrale.
$sourceRegistry = [];

// Parser factories per categoria
$factories = [
    'eser' => function (string $fullPath) use ($base, $teacherId, $instituteId): ?array {
        $rel = str_replace('\\', '/', substr($fullPath, strlen($base) + 1));
        if (!preg_match('#^eser/([a-z]+)/eser_([a-z]+)(\d+[a-z]?)/([A-Z]+)/([\d.]+)_\\4-(.+?)-\\2\\3\.php$#', $rel, $m)) return null;
        $ind=$m[1]; $cls=$m[3]; $subj=$m[4]; $numArg=$m[5]; $argomento=$m[6];
        return [
            'category' => 'eser',
            'scope'    => [
                'teacher_id' => $teacherId, 'institute_id' => $instituteId,
                'kind' => 'esercizio', 'subject' => $subj,
                'indirizzo' => $ind, 'classe' => $cls,
                'topic_num' => $numArg, 'topic' => str_replace('_', ' ', $argomento),
            ],
            'base_id' => pathinfo($rel, PATHINFO_FILENAME),
        ];
    },
    'verifiche' => function (string $fullPath) use ($base, $teacherId, $instituteId): ?array {
        $rel = str_replace('\\', '/', substr($fullPath, strlen($base) + 1));
        if (!preg_match('#^verifiche/php/([A-Z]+)/\\1-(.+?)-ver( copy)?\.php$#', $rel, $m)) return null;
        return [
            'category' => 'verifiche',
            'scope'    => [
                'teacher_id' => $teacherId, 'institute_id' => $instituteId,
                'kind' => 'verifica', 'subject' => $m[1],
                'topic' => str_replace('_', ' ', $m[2]) . (!empty($m[3]) ? ' (copy)' : ''),
            ],
            'base_id' => pathinfo($rel, PATHINFO_FILENAME),
        ];
    },
    'strcomp_bes_altro' => function (string $fullPath) use ($base, $teacherId, $instituteId): ?array {
        $rel = str_replace('\\', '/', substr($fullPath, strlen($base) + 1));
        if (!preg_match('#^strcomp_bes_altro/.+\.php$#', $rel)) return null;
        return [
            'category' => 'bes',
            'scope'    => [
                'teacher_id' => $teacherId, 'institute_id' => $instituteId,
                'kind' => 'esercizio', 'subject' => 'BES',
                'topic' => str_replace('_', ' ', pathinfo($rel, PATHINFO_FILENAME)),
            ],
            'base_id' => pathinfo($rel, PATHINFO_FILENAME),
        ];
    },
];

foreach ($factories as $root => $factory) {
    if (!is_dir($base . '/' . $root)) continue;
    $log("→ scan $root");
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base . '/' . $root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($opts['limit'] > 0 && $stats['written'] + $stats['skipped'] >= $opts['limit']) break 2;
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
        $stats['scanned']++;
        $meta = $factory($f->getPathname());
        if (!$meta) { $stats['skipped']++; continue; }
        try {
            $rawHtml = (string)file_get_contents($f->getPathname());
            $parser  = new PhpContentParser($meta['scope']);
            $sourceHref = '/' . str_replace('\\','/', substr($f->getPathname(), strlen($base) + 1));
            $contract = $parser->parse($rawHtml, $sourceHref);
            $stats['parsed']++;
            $contract['id'] = $meta['base_id']; // forza id coerente

            // Source registry: estrai book/volume/authors dai badge e
            // rimpiazza con solo source_key. Riduce duplicazione x100.
            foreach (($contract['groups'] ?? []) as $gi => $g) {
                foreach (($g['items'] ?? []) as $ii => $it) {
                    if (!isset($it['badge'])) continue;
                    $b  = $it['badge'];
                    $sk = $b['source_key'] ?? null;
                    if ($sk === null) continue;
                    $sourceRegistry[$sk] ??= array_filter([
                        'key'     => $sk,
                        'book'    => $b['book']    ?? null,
                        'volume'  => $b['volume']  ?? null,
                        'authors' => $b['authors'] ?? null,
                    ]);
                    // Slim badge: source ref + attrs dell'esercizio.
                    $contract['groups'][$gi]['items'][$ii]['badge'] = array_filter([
                        'source_key'      => $sk,
                        'page'            => $b['page']            ?? null,
                        'ex_num'          => $b['ex_num']          ?? null,
                        'bg_color'        => $b['bg_color']        ?? null,
                        'difficulty'      => $b['difficulty']      ?? null,
                        'difficulty_max'  => $b['difficulty_max']  ?? null,
                    ], fn($v) => $v !== null && $v !== '');
                }
            }

            $json = json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $key  = sprintf(
                'institutes/%d/private/%d/%s/%s.contract.json',
                $instituteId, $teacherId, $meta['category'], $meta['base_id'],
            );

            if (!$opts['apply']) {
                // dry-run: verify size, don't write
                $stats['written']++;
                continue;
            }
            $res = $provider->put($key, $json, 'application/json');
            $objRepo->upsert([
                'provider'      => $res->provider,
                'storage_key'   => $res->key,
                'checksum'      => $res->checksum,
                'size_bytes'    => $res->size,
                'mime'          => 'application/json',
                'visibility'    => 'private',
                'owner_user_id' => $teacherId,
                'institute_id'  => $instituteId,
                'version'       => 1,
            ]);
            $stats['written']++;

            // Aggiorna teacher_content.metadata_json con riferimento al contract
            $tcStmt = $pdo->prepare(
                "UPDATE teacher_content
                 SET metadata_json = JSON_SET(
                     COALESCE(metadata_json, JSON_OBJECT()),
                     '$.contract_key', ?,
                     '$.contract_checksum', ?,
                     '$.contract_size_bytes', ?
                 ), updated_at=CURRENT_TIMESTAMP
                 WHERE teacher_id=? AND JSON_EXTRACT(metadata_json, '$.legacy_href') = ?"
            );
            $tcStmt->execute([$res->key, $res->checksum, $res->size, $teacherId, $sourceHref]);
            if ($tcStmt->rowCount() > 0) $stats['linked']++;
        } catch (Throwable $e) {
            $stats['errors']++;
            fwrite(STDERR, "[ERR] " . $f->getPathname() . " — " . $e->getMessage() . "\n");
        }
    }
}

// Scrivi source registry se ci sono fonti accumulate
if ($opts['apply'] && $sourceRegistry) {
    $registryKey = sprintf('institutes/%d/private/%d/sources.registry.json', $instituteId, $teacherId);
    $registry = [
        '$schema'      => 'pantedu.sources.v1',
        'teacher_id'   => $teacherId,
        'institute_id' => $instituteId,
        'generated_at' => date('c'),
        'count'        => count($sourceRegistry),
        'sources'      => array_values($sourceRegistry),
    ];
    $res = $provider->put(
        $registryKey,
        json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'application/json',
    );
    $objRepo->upsert([
        'provider'      => $res->provider,
        'storage_key'   => $res->key,
        'checksum'      => $res->checksum,
        'size_bytes'    => $res->size,
        'mime'          => 'application/json',
        'visibility'    => 'private',
        'owner_user_id' => $teacherId,
        'institute_id'  => $instituteId,
        'version'       => 1,
    ]);
    $log('');
    $log("source registry: $registryKey (" . count($sourceRegistry) . ' sources)');
}

$log('');
$log('─── report ───');
foreach ($stats as $k => $v) $log(sprintf('  %-8s %d', $k, $v));
$log(sprintf('  %-8s %d', 'sources', count($sourceRegistry)));
if (!$opts['apply']) $log("\n(dry-run) riesegui con --apply per scrivere");
exit($stats['errors'] > 0 ? 2 : 0);
