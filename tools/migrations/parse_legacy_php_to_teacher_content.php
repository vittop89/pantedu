<?php
/**
 * parse_legacy_php_to_teacher_content.php — Phase 15.
 *
 * I 95 file .php legacy residui in eser/, verifiche/, strcomp_bes_altro/
 * sono template scheletri (header MathJax/Quill + <div class="fm-pagestyle">
 * con titolo). Il content reale è caricato client-side via JSON links.
 *
 * Questo script NON prova ad estrarre body HTML (inutile, è scheletro):
 * registra ogni PHP come riga in `teacher_content` (content_type,
 * subject_code, indirizzo, classe, topic, title) con teacher_id=77
 * (superadmin), visibility=published, metadata con legacy_href.
 *
 * Idempotente: upsert su (teacher_id, content_type, subject_code,
 * indirizzo, classe, topic).
 *
 * Filename pattern per categoria:
 *   eser/{ind}/eser_{ind}{cls}/{subj}/{numArg}_{subj}-{argomento}-{ind}{cls}.php
 *   verifiche/php/{subj}/{subj}-{argomento}-ver.php
 *   strcomp_bes_altro/...  (variabile; skip in questa pass)
 *   mappe/... (nessun PHP significativo dopo cleanup)
 *
 * Uso:
 *   php tools/migrations/parse_legacy_php_to_teacher_content.php
 *   php tools/migrations/parse_legacy_php_to_teacher_content.php --apply
 *   --teacher=<username>  (default: superadmin)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$opts = [
    'apply'   => false,
    'teacher' => 'superadmin',
    'quiet'   => false,
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') { $opts['apply'] = true; continue; }
    if ($arg === '--quiet') { $opts['quiet'] = true; continue; }
    if (str_starts_with($arg, '--teacher=')) { $opts['teacher'] = substr($arg, 10); }
}
$log = fn(string $s) => $opts['quiet'] ? null : print($s . "\n");

if (!Database::isAvailable()) { fwrite(STDERR, "DB non disponibile.\n"); exit(1); }
$pdo = Database::connection();

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$opts['teacher']]);
$teacherId = (int)($stmt->fetchColumn() ?: 0);
if ($teacherId === 0) { fwrite(STDERR, "Teacher '{$opts['teacher']}' non trovato.\n"); exit(1); }

$base = (string)Config::get('app.paths.base');
$stats = ['scanned'=>0, 'parsed'=>0, 'skipped'=>0, 'errors'=>0, 'inserted'=>0, 'updated'=>0];

$log(($opts['apply'] ? '[APPLY]' : '[DRY-RUN]') . " teacher_id=$teacherId");

// ──────────────────────────────────────────────────────────
// Parser eser/{ind}/eser_{ind}{cls}/{subj}/{numArg}_{subj}-{argomento}-{ind}{cls}.php
// ──────────────────────────────────────────────────────────
$parseEser = function (string $fullPath) use ($base): ?array {
    $rel = str_replace('\\', '/', substr($fullPath, strlen($base) + 1));
    if (!preg_match('#^eser/([a-z]+)/eser_([a-z]+)(\d+[a-z]?)/([A-Z]+)/(.+)\.php$#', $rel, $m)) return null;
    $ind = $m[1]; $cls = $m[3]; $subj = $m[4]; $filename = $m[5];
    // {numArg}_{subj}-{argomento}-{ind}{cls}
    if (!preg_match('#^([\d.]+)_' . preg_quote($subj) . '-(.+?)-' . preg_quote($ind.$cls) . '$#', $filename, $fn)) return null;
    $numArg = $fn[1];
    $argomento = str_replace('_', ' ', $fn[2]);
    return [
        'content_type' => 'esercizio',
        'subject_code' => $subj,
        'indirizzo'    => $ind,
        'classe'       => $cls,
        'topic'        => $numArg,
        'title'        => trim($argomento),
        'metadata'     => ['legacy_href' => '/' . $rel, 'numArg' => $numArg],
    ];
};

// verifiche/php/{subj}/{subj}-{argomento}-ver.php
$parseVer = function (string $fullPath) use ($base): ?array {
    $rel = str_replace('\\', '/', substr($fullPath, strlen($base) + 1));
    if (!preg_match('#^verifiche/php/([A-Z]+)/(.+)\.php$#', $rel, $m)) return null;
    $subj = $m[1]; $filename = $m[2];
    if (!preg_match('#^' . preg_quote($subj) . '-(.+?)-ver( copy)?$#', $filename, $fn)) return null;
    $argomento = str_replace('_', ' ', $fn[1]);
    $suffix = !empty($fn[2]) ? ' (copy)' : '';
    $title = trim($argomento) . $suffix;
    return [
        'content_type' => 'verifica',
        'subject_code' => $subj,
        'indirizzo'    => null,
        'classe'       => null,
        'topic'        => $title, // topic = titolo leggibile (era md5 hash)
        'title'        => $title,
        'metadata'     => ['legacy_href' => '/' . $rel],
    ];
};

// strcomp_bes_altro/{...}/*.php → content_type='esercizio' pseudo, subject='BES'
$parseBes = function (string $fullPath) use ($base): ?array {
    $rel = str_replace('\\', '/', substr($fullPath, strlen($base) + 1));
    if (!preg_match('#^strcomp_bes_altro/(.+)\.php$#', $rel, $m)) return null;
    $name = basename($m[1]);
    $title = str_replace('_', ' ', $name);
    return [
        'content_type' => 'esercizio',
        'subject_code' => 'BES',
        'indirizzo'    => null,
        'classe'       => null,
        'topic'        => $title,
        'title'        => $title,
        'metadata'     => ['legacy_href' => '/' . $rel],
    ];
};

$dispatchers = [
    'eser'              => $parseEser,
    'verifiche'         => $parseVer,
    'strcomp_bes_altro' => $parseBes,
];

$upsert = $pdo->prepare(
    'INSERT INTO teacher_content
       (teacher_id, content_type, subject_code, indirizzo, classe, topic, title, body_html, metadata_json, visibility)
     VALUES (?,?,?,?,?,?,?,NULL,?,"published")
     ON DUPLICATE KEY UPDATE
       title=VALUES(title),
       metadata_json=VALUES(metadata_json),
       updated_at=CURRENT_TIMESTAMP'
);
// Nota: la tabella non ha UNIQUE su (teacher_id, content_type, subject_code, indirizzo, classe, topic).
// Implementiamo l'idempotenza manualmente via SELECT prima di INSERT.

$checkExisting = $pdo->prepare(
    'SELECT id FROM teacher_content
     WHERE teacher_id=? AND content_type=? AND subject_code=?
       AND ((indirizzo IS NULL AND ?="") OR indirizzo=?)
       AND ((classe IS NULL AND ?="") OR classe=?)
       AND topic=?'
);
$update = $pdo->prepare(
    'UPDATE teacher_content SET title=?, metadata_json=?, updated_at=CURRENT_TIMESTAMP WHERE id=?'
);
$insert = $pdo->prepare(
    'INSERT INTO teacher_content
       (teacher_id, content_type, subject_code, indirizzo, classe, topic, title, metadata_json, visibility)
     VALUES (?,?,?,?,?,?,?,?, "published")'
);

foreach ($dispatchers as $root => $parser) {
    if (!is_dir($base . '/' . $root)) continue;
    $log("→ scan $root");
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base . '/' . $root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        if (strtolower($f->getExtension()) !== 'php') continue;
        $stats['scanned']++;
        try {
            $data = $parser($f->getPathname());
            if (!$data) { $stats['skipped']++; continue; }
            $stats['parsed']++;
            if (!$opts['apply']) continue;

            $ind = $data['indirizzo'] ?? '';
            $cls = $data['classe']    ?? '';
            $checkExisting->execute([
                $teacherId, $data['content_type'], $data['subject_code'],
                $ind, $ind, $cls, $cls, $data['topic'],
            ]);
            $existingId = (int)($checkExisting->fetchColumn() ?: 0);
            $metaJson = json_encode($data['metadata'], JSON_UNESCAPED_UNICODE);
            if ($existingId > 0) {
                $update->execute([$data['title'], $metaJson, $existingId]);
                $stats['updated']++;
            } else {
                $insert->execute([
                    $teacherId, $data['content_type'], $data['subject_code'],
                    $ind !== '' ? $ind : null,
                    $cls !== '' ? $cls : null,
                    $data['topic'], $data['title'], $metaJson,
                ]);
                $stats['inserted']++;
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            fwrite(STDERR, "  [ERR] " . $f->getPathname() . " — " . $e->getMessage() . "\n");
        }
    }
}

$log('');
$log('─── report ───');
foreach ($stats as $k => $v) $log(sprintf('  %-9s %d', $k, $v));
if (!$opts['apply']) $log("\n(dry-run) riesegui con --apply per scrivere");
exit($stats['errors'] > 0 ? 2 : 0);
