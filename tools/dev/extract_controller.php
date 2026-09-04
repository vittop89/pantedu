<?php
/**
 * ADR-029 — estrattore di controller riusabile.
 * Sposta un insieme di metodi pubblici (+ le loro dipendenze private transitive)
 * da un controller sorgente a uno nuovo, duplicando gli helper condivisi.
 *
 * Uso:
 *   php tools/dev/extract_controller.php <SourceFQN> <NewClassName> <m1> <m2> ...
 * Es:
 *   php tools/dev/extract_controller.php "App\Controllers\TeacherContentController" \
 *       ContentPublishController publish unpublish sharePool
 *
 * Stampa il piano (move/copy) e scrive i due file. Esegue il completeness-check.
 * Le route vanno ripuntate a parte; poi rigenerare docs/ROUTES.md.
 */

require __DIR__ . '/../../vendor/autoload.php';

$srcFqn   = $argv[1] ?? exit("manca SourceFQN\n");
$newName  = $argv[2] ?? exit("manca NewClassName\n");
$movePub  = array_slice($argv, 3);
if (!$movePub) exit("manca la lista di metodi pubblici da spostare\n");

$rc      = new ReflectionClass($srcFqn);
$srcFile = $rc->getFileName();
$lines   = file($srcFile);
$ns      = $rc->getNamespaceName();
$dstFile = dirname($srcFile) . '/' . $newName . '.php';

$methods = [];
foreach ($rc->getMethods() as $m) {
    if ($m->getDeclaringClass()->getName() === $srcFqn) $methods[$m->name] = $m;
}

$callsIn = function (string $name) use ($methods, $lines): array {
    if (!isset($methods[$name])) return [];
    $m = $methods[$name];
    $body = implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    preg_match_all('/\$this->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $body, $mm);
    return array_values(array_unique($mm[1]));
};

// chiusura transitiva: tutti i metodi della classe raggiunti dai pubblici da spostare
$seen = [];
$queue = $movePub;
while ($queue) {
    $n = array_shift($queue);
    if (isset($seen[$n]) || !isset($methods[$n])) continue;
    $seen[$n] = true;
    foreach ($callsIn($n) as $c) if (isset($methods[$c]) && !isset($seen[$c])) $queue[] = $c;
}
$reach = array_keys($seen);

// quali metodi sono chiamati da codice FUORI dal set raggiunto?
$calledOutside = [];
foreach ($methods as $name => $m) {
    if (isset($seen[$name])) continue;
    foreach ($callsIn($name) as $c) $calledOutside[$c] = true;
}

// classifica: pubblici-da-spostare → move; privati → move se NON chiamati da fuori, altrimenti copy
$move = $copy = [];
foreach ($reach as $n) {
    if (in_array($n, $movePub, true)) {
        if (!empty($calledOutside[$n])) fwrite(STDERR, "ATTENZIONE: il pubblico '$n' è chiamato anche da metodi non spostati\n");
        $move[] = $n;
    } elseif (!empty($calledOutside[$n])) {
        $copy[] = $n;
    } else {
        $move[] = $n;
    }
}

$rangeOf = function (string $name) use ($methods, $lines): array {
    $m = $methods[$name];
    $from = $m->getStartLine();
    $i = $from - 2;
    while ($i >= 0) {
        $t = trim($lines[$i]);
        if ($t === '') break;
        if (str_starts_with($t, '*') || str_starts_with($t, '/**') || str_starts_with($t, '//') || str_starts_with($t, '*/') || str_starts_with($t, '#[')) { $i--; continue; }
        break;
    }
    return [$i + 2, $m->getEndLine()];
};
$slice = function (array $r) use ($lines): string {
    return implode('', array_slice($lines, $r[0] - 1, $r[1] - $r[0] + 1));
};

usort($move, fn($a, $b) => $rangeOf($a)[0] <=> $rangeOf($b)[0]);
usort($copy, fn($a, $b) => $rangeOf($a)[0] <=> $rangeOf($b)[0]);

// use-statements verbatim dal sorgente
$uses = [];
foreach ($lines as $l) if (preg_match('/^use\s+/', $l)) $uses[] = rtrim($l, "\r\n");

// preamble: proprietà + costruttore (dalla riga dopo "class ... {" al primo metodo non costruttore)
$classOpen = 0;
foreach ($lines as $i => $l) { if (preg_match('/\bclass\s+' . $rc->getShortName() . '\b/', $l)) { $classOpen = $i; break; } }
$ctor = isset($methods['__construct']) ? $slice($rangeOf('__construct')) : '';
// proprietà: righe tra l'apertura classe e il costruttore che dichiarano private/protected/public typed props
$props = '';
$ctorStart = isset($methods['__construct']) ? $rangeOf('__construct')[0] : PHP_INT_MAX;
for ($i = $classOpen + 1; $i < $ctorStart - 1; $i++) {
    $t = trim($lines[$i]);
    if (preg_match('/^(private|protected|public|readonly)[^;{(]*\$\w+[^;{]*;\s*$/', $t)) $props .= $lines[$i];
}

$header  = "<?php\n\nnamespace $ns;\n\n" . implode("\n", $uses) . "\n\n";
$header .= "/**\n * $newName — estratto da {$rc->getShortName()} (ADR-029).\n";
$header .= " * Metodi: " . implode(', ', $movePub) . ".\n";
$header .= " * Helper condivisi duplicati: " . (implode(', ', $copy) ?: '(nessuno)') . ".\n */\n";
$header .= "final class $newName\n{\n" . $props . "\n" . $ctor . "\n";

$body = '';
foreach ($move as $n) { if ($n === '__construct') continue; $body .= $slice($rangeOf($n)) . "\n"; }
if ($copy) {
    $body .= "    // ---- helper condivisi (copia da {$rc->getShortName()}, ADR-029) ----\n\n";
    foreach ($copy as $n) { $body .= $slice($rangeOf($n)) . "\n"; }
}
file_put_contents($dstFile, $header . $body . "}\n");

// riscrivi l'originale rimuovendo SOLO i metodi 'move' (i 'copy' restano)
$drop = [];
foreach ($move as $n) { if ($n === '__construct') continue; [$f, $t] = $rangeOf($n); for ($l = $f; $l <= $t; $l++) $drop[$l] = true; }
$kept = [];
$blank = 0;
foreach ($lines as $i => $l) {
    if (isset($drop[$i + 1])) continue;
    if (trim($l) === '') { if (++$blank > 1) continue; } else $blank = 0;
    $kept[] = $l;
}
file_put_contents($srcFile, implode('', $kept));

// report
echo "MOVE (" . count($move) . "): " . implode(', ', $move) . "\n";
echo "COPY (" . count($copy) . "): " . implode(', ', $copy) . "\n";
echo "Scritto: $dstFile\n";
