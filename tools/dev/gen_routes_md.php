<?php
/**
 * Genera docs/ROUTES.md da routes/web.php — inventario route per prefix.
 * Uso: php tools/dev/gen_routes_md.php > docs/ROUTES.md
 *
 * Scope onesto: estrae verbo, path letterale, handler (Controller::method) e
 * il middleware *route-local* (->middleware(...) sulla singola route), con line#.
 * NON deduce il middleware/prefix ereditato dai group() (auth/role/log): quello
 * vive sul wrapper $router->group([...]) e va letto in routes/web.php. Una sola
 * eccezione prefix nel file (group '/api' per /copilot/*), segnalata sotto.
 * Gestisce route multiline (handler sulla riga successiva). Ri-eseguibile.
 */
$root  = dirname(__DIR__, 2);
$src   = $root . '/routes/web.php';
$lines = file($src, FILE_IGNORE_NEW_LINES);
$verbs = '(get|post|put|patch|delete|any)';

$rows = [];
foreach ($lines as $i => $line) {
    if (!preg_match("/->{$verbs}\\s*\\(\\s*'([^']*)'\\s*,\\s*(.*)$/", $line, $m)) {
        continue;
    }
    $verb = strtoupper($m[1]);
    $path = $m[2];
    $rest = $m[3];

    // route multiline: handler / ->middleware() sulle righe successive
    $look = $i;
    while (strpos($rest, ';') === false && $look < $i + 4 && isset($lines[$look + 1])) {
        $look++;
        $rest .= ' ' . $lines[$look];
    }

    if (preg_match("/[\\w\\\\]*\\\\(\\w+)::class\\s*,\\s*'(\\w+)'/", $rest, $h)
        || preg_match("/\\b(\\w+Controller)::class\\s*,\\s*'(\\w+)'/", $rest, $h)) {
        $handler = $h[1] . '::' . $h[2];
    } elseif (strpos($rest, 'function') !== false || strpos($rest, 'fn(') !== false || strpos($rest, 'fn (') !== false) {
        $handler = '(closure)';
    } else {
        $handler = '(?)';
    }

    $rmw = [];
    if (preg_match("/->middleware\\(([^)]*)\\)/", $rest, $w)) {
        foreach (explode(',', $w[1]) as $a) {
            $a = trim($a, " '\"");
            if ($a !== '') $rmw[] = $a;
        }
    }

    $seg = array_values(array_filter(explode('/', $path)));
    $group = (($seg[0] ?? '') === 'api' && isset($seg[1])) ? '/api/' . $seg[1] : '/' . ($seg[0] ?? '');

    $rows[$group][] = [$verb, $path, $handler, implode(' ', $rmw), $i + 1];
}

ksort($rows);
$total = array_sum(array_map('count', $rows));

echo "# Route inventory\n\n";
echo "> Generato da `routes/web.php` con `php tools/dev/gen_routes_md.php > docs/ROUTES.md`. **Non editare a mano** — rigenera dopo ogni modifica alle route.\n";
echo ">\n";
echo "> **Cosa mostra**: verbo, path letterale, handler `Controller::method`, middleware *route-local*, riga in `routes/web.php`.\n";
echo "> **Cosa NON mostra**: il middleware ereditato dai `group()` (es. `auth`, `role:teacher`, `log`) — è sul wrapper del gruppo, non sulla singola route. Per il middleware effettivo di una route apri `routes/web.php` alla riga indicata e risali al `group()` che la contiene.\n";
echo "> **Eccezione prefix**: le route `/copilot/*` (handler `CopilotController`) sono dentro un `group(['prefix'=>'/api'])` → il path reale è `/api/copilot/*`.\n\n";
echo "Totale: **$total** route in " . count($rows) . " gruppi (per prefix di path). Flusso: route → controller in `app/Controllers/` → service in `app/Services/` (vedi `docs/SERVICES.md`).\n\n";

echo "## Indice gruppi\n\n";
foreach ($rows as $grp => $r) {
    $anchor = preg_replace('/[^a-z0-9]+/', '', strtolower($grp));
    echo "- [`$grp`](#$anchor) — " . count($r) . "\n";
}
echo "\n";

foreach ($rows as $grp => $r) {
    echo "## $grp\n\n";
    echo "| Metodo | Path | Handler | Mw (route-local) | L# |\n";
    echo "|--------|------|---------|------------------|----|\n";
    usort($r, fn($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
    foreach ($r as $row) {
        [$verb, $path, $handler, $mw, $ln] = $row;
        $mw = $mw === '' ? '—' : "`$mw`";
        echo "| $verb | `$path` | `$handler` | $mw | $ln |\n";
    }
    echo "\n";
}
