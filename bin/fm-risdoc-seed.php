<?php
/**
 * bin/fm-risdoc-seed.php — seed risdoc_templates.
 *
 * Scansiona storage/templates/risdoc/{MODELLI,RISORSE}/php/*.php e
 * storage/templates/strcomp/{STRCOMP,ALTRO}/*.php, per ogni file:
 *   - parse filename (num_arg + argomento + category)
 *   - rileva discipline (FIS/MAT dall'argomento)
 *   - trova tex_file, css_file corrispondenti
 *   - scansiona json_deps nel PHP source
 *   - calcola source_hash (sha256 del bundle testi)
 *   - genera logic_spec di default
 *   - INSERT o UPDATE risdoc_templates
 *
 * Idempotente: ri-eseguire è safe. Update source_hash se cambiato.
 *
 * Uso:
 *   php bin/fm-risdoc-seed.php            # seed reale
 *   php bin/fm-risdoc-seed.php --dry-run  # solo report
 */

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$root    = dirname(__DIR__);
$storage = $root . '/storage/templates';
$dry     = in_array('--dry-run', $argv, true);

if (!is_dir($storage)) {
    fwrite(STDERR, "[err] storage/templates/ not found — run copy step first.\n");
    exit(1);
}

$db = Database::connection();

$specs = [
    ['origin' => 'risdoc',  'category' => 'MODELLI', 'dir' => $storage . '/risdoc/MODELLI/php',  'tex_dir' => $storage . '/risdoc/MODELLI/tex', 'css_dir' => $storage . '/risdoc/MODELLI/css', 'prefix' => 'DOC'],
    ['origin' => 'risdoc',  'category' => 'RISORSE', 'dir' => $storage . '/risdoc/RISORSE/php',  'tex_dir' => $storage . '/risdoc/RISORSE/tex', 'css_dir' => $storage . '/risdoc/RISORSE/css', 'prefix' => 'DOC'],
    ['origin' => 'strcomp', 'category' => 'STRCOMP', 'dir' => $storage . '/strcomp/STRCOMP',     'tex_dir' => null, 'css_dir' => null, 'prefix' => 'SBA'],
    ['origin' => 'strcomp', 'category' => 'ALTRO',   'dir' => $storage . '/strcomp/ALTRO',       'tex_dir' => null, 'css_dir' => null, 'prefix' => 'SBA'],
];

$logicSpecDefault = json_encode([
    'mappings' => [
        ['html' => "input.field[data-key='<name>']", 'tex' => '[field-<name>]', 'desc' => 'Campo form auto-sostituito in TeX'],
        ['html' => "div.fm-testo",                       'tex' => '%[BeginTesto]...%[EndTesto]', 'desc' => 'Blocco testo con checkbox condizionali'],
        ['html' => "div.list-show",                   'tex' => '%[BeginList-show]...%[EndList-show]', 'desc' => 'Lista che mostra checkbox spuntati + non'],
        ['html' => "div.list-hide",                   'tex' => '%[BeginList-hide]...%[EndList-hide]', 'desc' => 'Lista che include solo checkbox spuntati'],
        ['html' => "div.textarea",                    'tex' => '%[BeginTextArea]...%[EndTextArea]', 'desc' => 'Textarea → TeX (newline → \\\\)'],
        ['html' => "div.selector",                    'tex' => '%[selection]',                    'desc' => 'Selettore JSON → LaTeX itemize'],
        ['html' => "table.dynamic-actions-table",     'tex' => '%[RowsInTabular]',                'desc' => 'Tabella righe dinamiche'],
        ['html' => "div.testo_noTEX",                 'tex' => '(non esportato)',                 'desc' => 'Testo solo HTML, omesso in TeX'],
    ],
    'conditional_blocks' => [
        ['tex' => '%[BeginMat]...%[EndMat]',     'desc' => 'Visibile solo se disciplina=MAT'],
        ['tex' => '%[BeginFis]...%[EndFis]',     'desc' => 'Visibile solo se disciplina=FIS'],
        ['tex' => '%[BeginOpzione2]...%[EndOpzione2]', 'desc' => 'Asse dei Linguaggi'],
        ['tex' => '%[BeginOpzione3]...%[EndOpzione3]', 'desc' => 'Asse Matematico'],
        ['tex' => '%[BeginOpzione4]...%[EndOpzione4]', 'desc' => 'Asse Scientifico-Tecnologico'],
        ['tex' => '%[BeginOpzione5]...%[EndOpzione5]', 'desc' => 'Asse Storico-Sociale'],
    ],
    'placeholders' => [
        ['tex' => '\\schoolyear',           'desc' => 'Anno scolastico auto (YYYY/YYYY+1)'],
        ['tex' => '\\thisyear',             'desc' => 'Anno corrente'],
        ['tex' => '\\checkbox',             'desc' => 'Checkbox vuota ☐'],
        ['tex' => '\\xcheckbox',            'desc' => 'Checkbox spuntata ☒'],
        ['tex' => '\\simplefield{L}{V}',    'desc' => 'Campo "Label: valore"'],
        ['tex' => '\\fillfield{text}',      'desc' => 'Dotted underline handwriting'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$total = 0; $inserted = 0; $updated = 0;

foreach ($specs as $sp) {
    if (!is_dir($sp['dir'])) { fwrite(STDERR, "[skip] {$sp['dir']} not found\n"); continue; }
    $files = glob($sp['dir'] . '/*.php');
    sort($files);
    foreach ($files as $phpPath) {
        $total++;
        $fname = basename($phpPath);
        $parsed = parseFilename($fname, $sp['prefix'], $sp['category']);
        if (!$parsed) { fwrite(STDERR, "[warn] cannot parse {$fname}\n"); continue; }

        $code = sprintf('%s/%s/%s_%s', $sp['origin'], $sp['category'], $parsed['num_arg'], $parsed['argomento']);
        $texFile = null;
        if ($sp['tex_dir']) {
            $candidate = $sp['tex_dir'] . '/' . preg_replace('/\.php$/', '.tex', $fname);
            if (is_file($candidate)) $texFile = basename($candidate);
        }
        $cssFile = null;
        if ($sp['css_dir']) {
            $cssCandidate = $sp['css_dir'] . '/' . $parsed['argomento'] . '-' . $sp['category'] . '.css';
            if (is_file($cssCandidate)) $cssFile = basename($cssCandidate);
        }

        $phpBody = file_get_contents($phpPath) ?: '';
        $jsonDeps = detectJsonDeps($phpBody);

        $hashParts = [$phpBody];
        if ($texFile) $hashParts[] = file_get_contents($sp['tex_dir'] . '/' . $texFile) ?: '';
        if ($cssFile) $hashParts[] = file_get_contents($sp['css_dir'] . '/' . $cssFile) ?: '';
        $sourceHash = hash('sha256', implode("\x1e", $hashParts));

        $disciplina = null;
        if (preg_match('/\((FIS|MAT|GEO)\)/i', $parsed['argomento'], $m)) $disciplina = strtoupper($m[1]);

        $requiresPassword = $sp['origin'] === 'risdoc' ? 1 : 0;

        $existing = $db->prepare('SELECT id, source_hash FROM risdoc_templates WHERE code = ? LIMIT 1');
        $existing->execute([$code]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        // Normalizza path relativo alla project root (posix, separator '/')
        $abs = str_replace('\\', '/', $sp['dir']);
        $rootPosix = str_replace('\\', '/', $root);
        $srcDir = (str_starts_with($abs, $rootPosix . '/'))
            ? substr($abs, strlen($rootPosix) + 1)
            : $abs;

        if ($row) {
            if ($row['source_hash'] === $sourceHash) {
                echo "[skip] {$code} (unchanged)\n";
                continue;
            }
            if ($dry) { echo "[dry] UPDATE {$code}\n"; continue; }
            $upd = $db->prepare('UPDATE risdoc_templates SET origin=?, category=?, num_arg=?, argomento=?, discipline=?, source_dir=?, html_file=?, tex_file=?, css_file=?, json_deps=?, source_hash=?, logic_spec=?, requires_password=? WHERE id=?');
            $upd->execute([
                $sp['origin'], $sp['category'], $parsed['num_arg'], $parsed['argomento'],
                $disciplina, $srcDir, $fname, $texFile, $cssFile,
                $jsonDeps ? json_encode($jsonDeps) : null,
                $sourceHash, $logicSpecDefault, $requiresPassword,
                $row['id'],
            ]);
            $updated++;
            echo "[upd] {$code}\n";
        } else {
            if ($dry) { echo "[dry] INSERT {$code}\n"; continue; }
            $ins = $db->prepare('INSERT INTO risdoc_templates (code, origin, category, num_arg, argomento, discipline, source_dir, html_file, tex_file, css_file, json_deps, source_hash, logic_spec, owner_id, requires_password) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,?)');
            $ins->execute([
                $code, $sp['origin'], $sp['category'], $parsed['num_arg'], $parsed['argomento'],
                $disciplina, $srcDir, $fname, $texFile, $cssFile,
                $jsonDeps ? json_encode($jsonDeps) : null,
                $sourceHash, $logicSpecDefault, $requiresPassword,
            ]);
            $inserted++;
            echo "[ins] {$code} (hash " . substr($sourceHash, 0, 8) . ")\n";
        }
    }
}

echo "\n=== summary ===\n";
echo "total files scanned: {$total}\n";
echo "inserted: {$inserted}\n";
echo "updated:  {$updated}\n";
if ($dry) echo "(dry-run — no DB changes)\n";

// ─────────────────────────────────────────────────────────────

function parseFilename(string $fname, string $prefix, string $category): ?array
{
    // Pattern: <num_arg>_<prefix>-<argomento>-<category>.php
    // es: "0.0_DOC-Piano_annuale_(docente)-MODELLI.php"
    //     "0_DOC-Obiettivi_disciplinari_(LG2010)-RISORSE.php"
    //     "0.0_SBA-Cosa_sono-STRCOMP.php"
    $pattern = '/^([\d.]+)_' . preg_quote($prefix, '/') . '-(.+)-' . preg_quote($category, '/') . '\.php$/';
    if (!preg_match($pattern, $fname, $m)) return null;
    return ['num_arg' => $m[1], 'argomento' => $m[2]];
}

function detectJsonDeps(string $phpBody): array
{
    $deps = [];
    // Scansiona riferimenti path-style a file .json nel body PHP.
    if (preg_match_all('#[\'"]([A-Za-z_][A-Za-z0-9_/\.%-]*\.json)[\'"]#', $phpBody, $matches)) {
        foreach ($matches[1] as $ref) {
            if ($ref === '') continue;
            if (!in_array($ref, $deps, true)) $deps[] = $ref;
        }
    }
    // Riferimenti qualitativi: menzioni di folder categories
    foreach (['competenze_DM2007', 'competenze_PECUP', 'obiettivi_disciplinari_dipartimento',
             'obiettivi_disciplinari_LG2010', 'obiettivi_disciplinari_dipartimento_minimi',
             'programmi_svolti'] as $cat) {
        if (str_contains($phpBody, $cat) && !in_array($cat . '/*.json', $deps, true)) {
            $deps[] = $cat . '/*.json';
        }
    }
    sort($deps);
    return $deps;
}
