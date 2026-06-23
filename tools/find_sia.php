<?php
declare(strict_types=1);
$j = json_decode(file_get_contents(__DIR__ . '/../storage/objects/institutes/106/private/77/verifiche/MAT-Numeri_naturali_e_interi-ver.contract.json'), true);
foreach ($j['groups'] as $gi => $g) {
    foreach ($g['items'] as $ii => $it) {
        foreach ($it['question'] ?? [] as $bi => $b) {
            $c = $b['content'] ?? '';
            if (str_contains($c, 'mathbb{Z}') && str_contains($c, 'Sia')) {
                echo "FOUND g=$gi (" . ($g['title'] ?? '') . " type=" . ($g['type'] ?? '?') . ") i=$ii\n";
                echo "page=" . ($it['page'] ?? '-') . " ex=" . ($it['ex_num'] ?? '-') . " bg=" . ($it['bg_color'] ?? '-') . " diff=" . ($it['difficulty'] ?? '-') . "\n";
                echo "[question]:\n";
                foreach ($it['question'] as $qbi => $qb) {
                    echo "  q$qbi (" . $qb['type'] . "): [" . str_replace("\n", '\\n', $qb['content'] ?? '') . "]\n";
                }
                echo "[options]:\n";
                foreach ($it['options'] ?? [] as $oi => $opt) {
                    echo "  opt$oi correct=" . var_export($opt['correct'] ?? null, true) . " html: [" . str_replace("\n", '\\n', $opt['html'] ?? $opt['content'] ?? '') . "]\n";
                }
                echo "[justification]:\n";
                foreach ($it['justification'] ?? [] as $jbi => $jb) {
                    echo "  j$jbi (" . $jb['type'] . "): [" . str_replace("\n", '\\n', $jb['content'] ?? '') . "]\n";
                }
                exit;
            }
        }
    }
}
