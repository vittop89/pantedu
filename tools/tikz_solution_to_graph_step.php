<?php
declare(strict_types=1);
/**
 * tikz_solution_to_graph_step.php — sposta il blocco TikZ dalla testa
 * della `solution` alla fine, SOLO quando il problema chiede esplicitamente
 * un grafico come passo finale.
 *
 * Trigger (content-aware, non deterministico):
 *   - question text contiene una formulazione "graph-step":
 *       "rappresentane il grafico"
 *       "disegna(re)? il grafico"
 *       "traccia(re)? il grafico"
 *       "grafico (probabile|della funzione)"
 *   - solution[0] e' tikz
 *   - solution ha almeno un altro blocco prose (text/latex) dopo
 *
 * Action:
 *   - Sposta tikz da [0] alla fine della solution
 *
 * Idempotente. Conservativo: ignora solution dove il primo tikz non e'
 * il "grafico finale" (es. figura geometrica iniziale).
 *
 * Usage: php tools/tikz_solution_to_graph_step.php [--apply]
 */

$apply = in_array('--apply', $argv, true);

$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

$totalScanned = 0;
$candidates = 0;
$movedSolutions = 0;
$filesChanged = 0;
$samples = [];

foreach ($dirs as $dir) {
    foreach (glob($dir . '/*.contract.json') as $path) {
        $j = json_decode(file_get_contents($path), true);
        if (!is_array($j) || empty($j['groups'])) continue;
        $fileChanged = false;
        foreach ($j['groups'] as &$g) {
            if (!isset($g['items']) || !is_array($g['items'])) continue;
            foreach ($g['items'] as &$it) {
                $totalScanned++;
                if (!isQuestionGraphStep($it)) continue;
                $sol = $it['solution'] ?? null;
                if (!is_array($sol) || count($sol) < 2) continue;
                if (($sol[0]['type'] ?? '') !== 'tikz') continue;
                // Verifica che ci sia prose dopo
                $hasProseAfter = false;
                for ($i = 1; $i < count($sol); $i++) {
                    $t = $sol[$i]['type'] ?? '';
                    if ($t === 'text' || $t === 'latex') { $hasProseAfter = true; break; }
                }
                if (!$hasProseAfter) continue;
                $candidates++;

                // Move tikz [0] -> end
                $tikzBlock = array_shift($sol);
                $sol[] = $tikzBlock;
                $it['solution'] = $sol;
                if (isset($it['body_html'])) unset($it['body_html']);
                $movedSolutions++;
                $fileChanged = true;
                if (count($samples) < 3) {
                    $samples[] = basename($path);
                }
            }
            unset($it);
        }
        unset($g);
        if ($fileChanged) {
            $filesChanged++;
            if ($apply) {
                file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            }
        }
    }
}

echo "=== " . ($apply ? "APPLY" : "DRY-RUN") . " ===\n";
echo "Items scanned:        $totalScanned\n";
echo "Graph-step candidates: $candidates\n";
echo "Solutions moved:      $movedSolutions\n";
echo "Files changed:        $filesChanged\n";
if ($samples) echo "Sample files: " . implode(", ", $samples) . "\n";

/** Determina se la question dell'item richiede un grafico come step esplicito. */
function isQuestionGraphStep(array $it): bool {
    $qfull = collectProseRecursive($it['question'] ?? []);
    // Pattern espliciti — content-aware
    $patterns = [
        '/rappresenta(?:re|ne)?\s+il\s+grafico/iu',
        '/disegna(?:re)?\s+il\s+grafico/iu',
        '/traccia(?:re)?\s+il\s+grafico/iu',
        '/grafico\s+(?:probabile|della\s+funzione)/iu',
        '/(?:rappresenta|disegna|traccia)(?:re|ne|le)?\s+nel\s+piano/iu',
        // Pattern numerato esplicito: "3. rappresentane il grafico" / "3. il grafico" / "3. grafico"
        '/\b\d+\s*[.)]\s*(?:rappresenta|disegna|traccia|grafico)/iu',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $qfull)) return true;
    }
    return false;
}

/**
 * Estrae TUTTO il prose da un array di blocchi, ricorsivamente.
 * Gestisce text/latex/list (con items annidati). Ignora tikz/geogebra/img.
 */
function collectProseRecursive(array $blocks): string {
    $out = '';
    foreach ($blocks as $b) {
        $t = $b['type'] ?? '';
        if ($t === 'text' || $t === 'latex') {
            $out .= ' ' . (string)($b['content'] ?? '');
        } elseif ($t === 'list') {
            foreach ($b['items'] ?? [] as $item) {
                if (is_array($item)) {
                    $out .= ' ' . collectProseRecursive($item);
                }
            }
        }
    }
    return $out;
}
