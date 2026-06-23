<?php
declare(strict_types=1);
/**
 * extract_inline_badge_to_field.php
 *
 * 69 items hanno il "badge libro/citazione" come blocco LaTeX inline in
 * `question[0]` invece di avere il campo strutturato `badge` con
 * source_key/page/ex_num/bg_color/difficulty. Questo crea due problemi:
 *   1. Edit-mode mostra il LaTeX raw nel textarea anziché i campi origin-selector
 *   2. La pipeline renderer non trova il badge field → niente lookup
 *      sources.registry.json → eventuali aggiornamenti citazione non si propagano
 *
 * Estrae il contenuto dal LaTeX inline:
 *   - \small{\text{<book>}}
 *   - \tiny{\text{<volume>}}
 *   - \tiny{\text{<authors>}}
 *   - \overset{...\bullet\bullet\circ\circ...} → difficulty (count \bullet)
 *   - \underset{\text{P-}<page>} → page
 *   - \large\s+<num> nel \bbox → ex_num
 *   - background:\s+<color> → bg_color
 *
 * Match book+volume → source_key dal registry (sources.registry.json del docente).
 *
 * Idempotente: skip se gia' c'e' il campo `badge`.
 *
 * Usage: php tools/extract_inline_badge_to_field.php [--apply] [--teacher=77]
 */

$apply = in_array('--apply', $argv, true);
$teacherId = 77;
foreach ($argv as $a) if (preg_match('/^--teacher=(\d+)$/', $a, $m)) $teacherId = (int)$m[1];

$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

// Carica registry sources (per match book+volume → source_key)
$registry = loadSourceRegistry($teacherId);
if (!$registry) {
    fwrite(STDERR, "WARN: nessun registry sources trovato per teacher $teacherId. Procedo senza source_key match.\n");
    $registry = [];
}

$total = 0;
$converted = 0;
$noMatch = 0;
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
                $total++;
                if (isset($it['badge']) && !empty($it['badge'])) continue;
                $q = $it['question'] ?? [];
                if (empty($q)) continue;

                // Trova il primo blocco latex con il pattern badge
                $idxBadge = -1;
                for ($i = 0; $i < count($q); $i++) {
                    $b = $q[$i];
                    if (($b['type'] ?? '') !== 'latex') continue;
                    $c = (string)($b['content'] ?? '');
                    if (str_contains($c, 'begin{array}') && (
                        str_contains($c, 'ZANICHELLI') ||
                        str_contains($c, 'Bergamini') ||
                        str_contains($c, 'Sasso') ||
                        str_contains($c, 'DEASCUOLA') ||
                        str_contains($c, 'SEI') ||
                        str_contains($c, '\\small{\\text{') && str_contains($c, '\\tiny{\\text{')
                    )) {
                        $idxBadge = $i;
                        break;
                    }
                }
                if ($idxBadge < 0) continue;

                $latex = (string)$q[$idxBadge]['content'];
                $extracted = parseBadgeLatex($latex);
                if (!$extracted) continue;

                // Match source_key dal registry
                $extracted['source_key'] = matchSourceKey($registry, $extracted);

                // Build badge field formato canonico
                $badge = [
                    'source_key' => $extracted['source_key'] ?? '',
                    'page'       => $extracted['page'] ?? '',
                    'ex_num'     => $extracted['ex_num'] ?? '',
                    'bg_color'   => $extracted['bg_color'] ?? 'blue',
                    'difficulty' => $extracted['difficulty'] ?? 1,
                    'difficulty_max' => $extracted['difficulty_max'] ?? 4,
                ];
                if (empty($badge['source_key'])) $noMatch++;

                // Rimuovi il blocco inline e aggiungi badge field
                array_splice($q, $idxBadge, 1);
                $it['question'] = $q;
                $it['badge'] = $badge;
                if (isset($it['body_html'])) unset($it['body_html']);
                $converted++;
                $fileChanged = true;
                if (count($samples) < 3) {
                    $samples[] = ['file' => basename($path), 'badge' => $badge, 'extracted' => $extracted];
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
echo "Items scanned:    $total\n";
echo "Badges extracted: $converted\n";
echo "No source_key match (orfani): $noMatch\n";
echo "Files changed:    $filesChanged\n";
if ($samples) {
    echo "\n--- Sample extractions ---\n";
    foreach ($samples as $s) {
        echo "FILE: " . $s['file'] . "\n";
        echo "  badge: " . json_encode($s['badge']) . "\n";
        if (!empty($s['extracted']['book'])) echo "  matched book: " . $s['extracted']['book'] . "\n";
    }
}

// ─────────────────────── implementazione ──────────────────────────────────

function loadSourceRegistry(int $teacherId): array {
    foreach ([106, 108, 109] as $iid) {
        $p = __DIR__ . "/../storage/objects/institutes/$iid/private/$teacherId/sources.registry.json";
        if (!is_file($p)) continue;
        $j = json_decode(file_get_contents($p), true);
        if (!empty($j['sources'])) return $j['sources'];
    }
    return [];
}

function parseBadgeLatex(string $tex): ?array {
    $out = [];

    // Estrai \small{\text{<book>}}
    if (preg_match('/\\\\small\\s*\\{\\s*\\\\text\\s*\\{([^}]+)\\}\\s*\\}/u', $tex, $m)) {
        $out['book'] = trim($m[1]);
    }
    // Estrai \tiny{\text{...}} multipli (volume + authors)
    if (preg_match_all('/\\\\tiny\\s*\\{\\s*\\\\text\\s*\\{([^}]+)\\}\\s*\\}/u', $tex, $m)) {
        if (isset($m[1][0])) $out['volume']  = trim($m[1][0]);
        if (isset($m[1][1])) $out['authors'] = trim($m[1][1]);
    }
    // Page: \text{P-}<num> o \text{P-}<num>
    if (preg_match('/\\\\text\\s*\\{\\s*P-\\s*\\}\\s*(\\d+)/u', $tex, $m)) {
        $out['page'] = $m[1];
    } elseif (preg_match('/P-(\\d+)/u', $tex, $m)) {
        $out['page'] = $m[1];
    }
    // ex_num: \large\s+<num>
    if (preg_match('/\\\\large\\s+(\\d+)/u', $tex, $m)) {
        $out['ex_num'] = $m[1];
    } elseif (preg_match('/\\\\Large\\s+(\\d+)/u', $tex, $m)) {
        $out['ex_num'] = $m[1];
    }
    // bg_color: background:\s+<word>
    if (preg_match('/background\\s*:\\s*([a-zA-Z]+)/u', $tex, $m)) {
        $out['bg_color'] = strtolower($m[1]);
    }
    // difficulty: count \bullet in \overset{...} (e \circ per il max)
    if (preg_match('/\\\\overset\\s*\\{[^}]*?\\\\(?:huge|Huge|large|Large)?\\s*((?:\\\\bullet|\\\\circ)+)\\s*\\}/u', $tex, $m)) {
        $bullets = substr_count($m[1], '\\bullet');
        $circles = substr_count($m[1], '\\circ');
        $out['difficulty'] = $bullets;
        $out['difficulty_max'] = $bullets + $circles;
    } else {
        // Conta \bullet ovunque (fallback)
        $bullets = substr_count($tex, '\\bullet');
        $circles = substr_count($tex, '\\circ');
        if ($bullets + $circles > 0) {
            $out['difficulty'] = $bullets;
            $out['difficulty_max'] = $bullets + $circles;
        }
    }

    if (empty($out['book']) && empty($out['page']) && empty($out['ex_num'])) return null;
    return $out;
}

function matchSourceKey(array $sources, array $extracted): ?string {
    if (empty($extracted['book']) && empty($extracted['volume'])) return null;
    $normBook = normalizeForMatch($extracted['book'] ?? '');
    $normVol  = normalizeForMatch($extracted['volume'] ?? '');
    foreach ($sources as $s) {
        $sBook = normalizeForMatch($s['book'] ?? '');
        $sVol  = normalizeForMatch($s['volume'] ?? '');
        if ($sBook === $normBook && $sVol === $normVol) {
            return $s['key'] ?? null;
        }
    }
    // Match solo per book se il volume non e' estratto (fallback debole)
    if ($normBook !== '' && $normVol === '') {
        foreach ($sources as $s) {
            if (normalizeForMatch($s['book'] ?? '') === $normBook) return $s['key'] ?? null;
        }
    }
    return null;
}

function normalizeForMatch(string $s): string {
    $s = preg_replace('/\\s+/u', ' ', $s) ?? $s;
    return trim(strtolower($s));
}
