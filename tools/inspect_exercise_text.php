<?php
declare(strict_types=1);

$path = __DIR__ . '/../storage/objects/' . ($argv[1] ?? '');
if (!is_file($path)) {
    fwrite(STDERR, "Usage: php tools/inspect_exercise_text.php <relative_path_to_contract.json>\n");
    exit(1);
}
$j = json_decode(file_get_contents($path), true);
if (!is_array($j) || empty($j['groups'])) { exit(0); }

foreach ($j['groups'] as $gi => $g) {
    echo "=== GROUP $gi: " . ($g['title'] ?? '(no title)') . " (type=" . ($g['type'] ?? '?') . ") ===\n";
    foreach (($g['items'] ?? []) as $ii => $it) {
        echo "--- item[$ii] id=" . substr((string)($it['id'] ?? ''), 0, 8) . " ---\n";
        foreach (['question', 'justification', 'solution'] as $k) {
            $blocks = $it[$k] ?? [];
            if (!is_array($blocks) || empty($blocks)) continue;
            echo "  [$k] " . count($blocks) . " block(s):\n";
            foreach ($blocks as $bi => $blk) {
                $type = $blk['type'] ?? '?';
                $content = (string)($blk['content'] ?? '');
                $preview = substr($content, 0, 200);
                $hasMultiSpaces = preg_match('/  +/', $content);
                $hasMultiNewlines = preg_match('/\n\n\n+/', $content);
                $hasLeadingWs = preg_match('/^[\s\n]+/', $content);
                $hasTrailingWs = preg_match('/[\s\n]+$/', $content);
                $hasTabs = strpos($content, "\t") !== false;
                $issues = [];
                if ($hasMultiSpaces) $issues[] = "multi_spaces";
                if ($hasMultiNewlines) $issues[] = "triple_newlines";
                if ($hasLeadingWs) $issues[] = "leading_ws";
                if ($hasTrailingWs) $issues[] = "trailing_ws";
                if ($hasTabs) $issues[] = "tabs";
                $issuesStr = $issues ? ' [' . implode(',', $issues) . ']' : '';
                echo "    block[$bi] type=$type len=" . strlen($content) . $issuesStr . "\n";
                if ($issues) {
                    // Mostra il content raw con \n e spazi visualizzati
                    $vis = str_replace(["\r", "\n", "\t"], ['\r', "\n      |", '\t'], $preview);
                    echo "      |$vis\n";
                }
            }
        }
    }
}
