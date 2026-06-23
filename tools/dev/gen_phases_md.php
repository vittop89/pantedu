<?php
/**
 * Genera docs/PHASES.md — indice dei marker "Phase N" sparsi nel codice.
 * Uso: php tools/dev/gen_phases_md.php > docs/PHASES.md
 * Per ogni phase: descrizione (estratta dal codice, dove presente "Phase N — ..."),
 * n. occorrenze, file in cui compare. Narrativa estesa: wiki/changelog/. Ri-eseguibile.
 */
$root = dirname(__DIR__, 2);
$dirs = ['app', 'js', 'views'];
$exts = ['php', 'js'];

$rii = function ($dir) use ($root) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    return $it;
};

$data = []; // phase => ['count'=>int,'files'=>set,'desc'=>string]
foreach ($dirs as $d) {
    if (!is_dir("$root/$d")) continue;
    foreach ($rii($d) as $f) {
        if (!$f->isFile() || !in_array($f->getExtension(), $exts, true)) continue;
        $rel = str_replace('\\', '/', $f->getPathname());
        $rel = str_replace(str_replace('\\', '/', $root) . '/', '', $rel);
        $lines = file($f->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            if (!preg_match_all('/Phase ([0-9]+(?:\.[0-9A-Za-z]+)*)\s*([—:-]\s*(.{4,90}))?/u', $line, $ms, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($ms as $m) {
                $p = $m[1];
                $data[$p] ??= ['count' => 0, 'files' => [], 'desc' => ''];
                $data[$p]['count']++;
                $data[$p]['files'][$rel] = true;
                if (isset($m[3])) {
                    $desc = trim(preg_replace('/[\s*\/]+$/', '', $m[3]));
                    if (mb_strlen($desc) > mb_strlen($data[$p]['desc'])) {
                        $data[$p]['desc'] = $desc;
                    }
                }
            }
        }
    }
}

// natural sort delle chiavi phase (per segmenti, numerico dove possibile)
uksort($data, function ($a, $b) {
    $pa = explode('.', $a);
    $pb = explode('.', $b);
    for ($i = 0; $i < max(count($pa), count($pb)); $i++) {
        $x = $pa[$i] ?? '';
        $y = $pb[$i] ?? '';
        if (is_numeric($x) && is_numeric($y)) {
            if ((int)$x !== (int)$y) return (int)$x <=> (int)$y;
        } else {
            $c = strcmp($x, $y);
            if ($c !== 0) return $c;
        }
    }
    return 0;
});

$tot = count($data);
$occ = array_sum(array_column($data, 'count'));

echo "# Phase index\n\n";
echo "> Generato da `tools/dev/gen_phases_md.php`. **Non editare a mano** — rigenera.\n";
echo ">\n";
echo "> Il codice usa marker `Phase N` (es. `// Phase 14 — ...`) per tracciare le iterazioni di lavoro. Questo indice li raccoglie: snippet rappresentativo (best-effort dal codice), n. occorrenze, file. **Attenzione**: lo stesso numero di phase è talvolta riusato per lavori diversi → lo snippet è solo uno dei contesti; usa la colonna *File* + `git log` + `wiki/changelog/` per la storia completa.\n\n";
echo "Totale: **$tot** phase distinte, **$occ** occorrenze in `app/` + `js/` + `views/`.\n\n";
echo "| Phase | Descrizione (dal codice) | # | File principali |\n";
echo "|-------|--------------------------|---|------------------|\n";
foreach ($data as $p => $info) {
    $files = array_keys($info['files']);
    $shown = array_slice($files, 0, 3);
    $more = count($files) - count($shown);
    $fstr = implode(', ', array_map(fn($x) => "`$x`", $shown)) . ($more > 0 ? " +$more" : '');
    $desc = $info['desc'] !== '' ? str_replace('|', '\\|', $info['desc']) : '—';
    echo "| **$p** | $desc | {$info['count']} | $fstr |\n";
}
