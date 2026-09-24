<?php
/**
 * Build JS cache-bust — riscrive import nei file JS aggiungendo ?v=<mtime>.
 *
 * Output: js/modules/bootstrap.dist.js (NON committato — generato al deploy).
 *
 * Rationale (2026-05-24):
 *   - head.php cache-bust bootstrap.js via ?v=<mtime> → fresh fetch del
 *     entry. Browser segue gli ES module import statements che hanno
 *     PATHS STATICI nel source (es. `import x from './core/foo.js'`).
 *   - Browser cache by URL → cookie-consent.js (no query) servito da
 *     disk cache anche post hard-refresh → fix non arriva all'utente.
 *
 * Soluzione: pre-process bootstrap.js sostituendo ogni
 *   import x from "./PATH/file.js";
 * con
 *   import x from "./PATH/file.js?v=<filemtime>";
 *
 * Quando un sub-file cambia, mtime aggiornato → URL ?v=NNN diverso
 * → browser fetch fresh. Una sola riga aggiunta al deploy.sh garantisce
 * propagazione completa modifiche JS senza purge cache manuale.
 *
 * Run:
 *   php tools/build-js-cache-bust.php
 *
 * Integration:
 *   - deploy.sh chiama questo script dopo CSS bundle build
 *   - head.php link a /js/modules/bootstrap.dist.js?v=mtime (preferito)
 *     con fallback a bootstrap.js (dev senza build)
 */

declare(strict_types=1);

const JS_ROOT     = __DIR__ . '/../js';
const ENTRY       = 'modules/bootstrap.js';
const OUTPUT_REL  = 'modules/bootstrap.dist.js';

/**
 * Resolve import path relativo (es. "./core/foo.js" da bootstrap.js)
 * → path filesystem assoluto.
 */
function resolveImport(string $importPath, string $sourceFile): ?string {
    if ($importPath[0] !== '.') return null; // skip esterni (http://, /css/, etc.)
    $dir = dirname($sourceFile);
    $abs = realpath($dir . '/' . $importPath);
    return $abs !== false && is_file($abs) ? $abs : null;
}

$entryPath = JS_ROOT . '/' . ENTRY;
if (!is_file($entryPath)) {
    fwrite(STDERR, "[ERROR] entry JS non trovato: $entryPath\n");
    exit(1);
}

$source = file_get_contents($entryPath);
$cacheBusted = 0;
$skipped = 0;

// Match:
//   import x from "./path/file.js";
//   import { a, b } from './path/file.js';
//   import * as ns from "../path/file.js";
//   export { x } from "./path/file.js";
//   import("./path/file.js")   ← dynamic import
$processed = preg_replace_callback(
    '#(\b(?:import|export)\b[^"\']*?["\']|\bimport\s*\(\s*["\'])(\.[^"\']+?\.js)(["\'])#',
    function (array $m) use ($entryPath, &$cacheBusted, &$skipped): string {
        $prefix = $m[1];
        $path   = $m[2];
        $quote  = $m[3];
        $abs    = resolveImport($path, $entryPath);
        if ($abs === null) {
            $skipped++;
            return $m[0]; // import esterno o file non trovato
        }
        // Skip se path ha già query
        if (str_contains($path, '?')) {
            $skipped++;
            return $m[0];
        }
        $cacheBusted++;
        return $prefix . $path . '?v=' . filemtime($abs) . $quote;
    },
    $source
);

if ($processed === null) {
    fwrite(STDERR, "[ERROR] regex error\n");
    exit(1);
}

$out = JS_ROOT . '/' . OUTPUT_REL;
$header = "/* JS Cache-bust dist — generato da tools/build-js-cache-bust.php\n"
        . " * Build timestamp: " . date('c') . "\n"
        . " * Source: " . ENTRY . "\n"
        . " * Cache-busted imports: $cacheBusted (skipped: $skipped externals)\n"
        . " * Non modificare a mano — rigenerato ad ogni deploy.\n"
        . " */\n\n";

if (file_put_contents($out, $header . $processed, LOCK_EX) === false) {
    fwrite(STDERR, "[ERROR] scrittura dist failed: $out\n");
    exit(1);
}

$size = filesize($out);
echo "[build-js-cache-bust] OK\n";
echo "  output: $out (" . number_format($size / 1024, 1) . " KB)\n";
echo "  imports: $cacheBusted cache-busted, $skipped skipped (esterni / con query)\n";
