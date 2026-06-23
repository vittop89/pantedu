<?php
/**
 * Build CSS bundle — concat ricorsivo di main.css + tutti @import nested.
 *
 * Output: css/main.bundle.css (NON committato — generato al deploy).
 *
 * Rationale (2026-05-24):
 *   - HTML link <main.css?v=mtime> usa cache-bust via filemtime → CF MISS
 *     al deploy → fresh main.css.
 *   - MA main.css contiene @import url('/css/components.css') SENZA
 *     cache-bust → CF cache nested file per max-age=1week → modifiche
 *     ai 37 moduli NON arrivano ai browser fino a scadenza cache CF.
 *   - Soluzione: pre-generare bundle.css concat ricorsivo all'install.
 *     1 file = 1 URL = 1 cache-bust = tutto risolto. Mantiene @layer.
 *
 * Run:
 *   php tools/build-css-bundle.php
 *
 * Integration:
 *   - deploy.sh chiama questo script dopo git pull
 *   - head.php link a /css/main.bundle.css?v=mtime (preferito)
 *     con fallback a main.css se bundle assente (dev senza build)
 */

declare(strict_types=1);

const CSS_ROOT = __DIR__ . '/../css';
const ENTRY    = 'main.css';
const OUTPUT   = 'main.bundle.css';

/**
 * Resolve URL CSS (/css/X.css) → path filesystem.
 */
function resolveCssPath(string $url): ?string {
    // URLs sono /css/X.css o /css/modules/_X.css ecc.
    if (!preg_match('#^/css/(.+\.css)$#', $url, $m)) return null;
    $path = CSS_ROOT . '/' . $m[1];
    return is_file($path) ? $path : null;
}

/**
 * Expande ricorsivamente @import dichiarazioni nel content CSS.
 *
 * Pattern matchati:
 *   @import url('/css/X.css');
 *   @import url('/css/X.css')                layer(name);
 *   @import url("/css/X.css");
 *
 * @import esterni (es. https://) lasciati invariati (non bundle-able).
 */
function expandImports(string $content, string $sourceFile, array &$visited): string {
    return preg_replace_callback(
        '#@import\s+url\s*\(\s*[\'"]?(/css/[^\'")]+\.css)[\'"]?\s*\)([^;]*);#',
        function (array $m) use ($sourceFile, &$visited): string {
            $url    = $m[1];
            $layer  = trim($m[2]);
            $target = resolveCssPath($url);

            if ($target === null) {
                fwrite(STDERR, "[WARN] $sourceFile: import non risolvibile: $url\n");
                return $m[0]; // lascia invariato
            }
            // Cycle protection
            $real = realpath($target);
            if (isset($visited[$real])) {
                fwrite(STDERR, "[INFO] $sourceFile: $url già incluso, skip\n");
                return ''; // dedupe
            }
            $visited[$real] = true;

            $sub = file_get_contents($target);
            // Recursive expand nested @import
            $sub = expandImports($sub, $target, $visited);

            // Wrap nel layer se specificato (preserva cascade @layer)
            if ($layer !== '' && preg_match('/layer\(([^)]+)\)/', $layer, $lm)) {
                $sub = "/* === $url layer($lm[1]) === */\n@layer $lm[1] {\n$sub\n}\n";
            } else {
                $sub = "/* === $url === */\n$sub\n";
            }
            return $sub;
        },
        $content
    );
}

// Main
$entryPath = CSS_ROOT . '/' . ENTRY;
if (!is_file($entryPath)) {
    fwrite(STDERR, "[ERROR] entry CSS non trovato: $entryPath\n");
    exit(1);
}

$visited = [realpath($entryPath) => true];
$bundle  = expandImports(file_get_contents($entryPath), $entryPath, $visited);

$header = "/* ============================================================\n"
        . " * CSS Bundle — generato da tools/build-css-bundle.php\n"
        . " * Build timestamp: " . date('c') . "\n"
        . " * Source: main.css + " . (count($visited) - 1) . " @import nested\n"
        . " * Non modificare a mano — rigenerato ad ogni deploy.\n"
        . " * ============================================================ */\n\n";

$out = CSS_ROOT . '/' . OUTPUT;
if (file_put_contents($out, $header . $bundle, LOCK_EX) === false) {
    fwrite(STDERR, "[ERROR] scrittura bundle fallita: $out\n");
    exit(1);
}

$size = filesize($out);
$sizeKB = number_format($size / 1024, 1);
echo "[build-css-bundle] OK\n";
echo "  output: $out ($sizeKB KB, " . (count($visited) - 1) . " moduli)\n";
echo "  entry:  $entryPath\n";
