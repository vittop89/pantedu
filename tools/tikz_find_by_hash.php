<?php
declare(strict_types=1);
/**
 * Trova il blocco TikZ avente l'hash sha256 specificato (o prefisso).
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Services\Tikz\TikzRenderService;

$prefix = $argv[1] ?? '';
if ($prefix === '') { fwrite(STDERR, "usage: php tikz_find_by_hash.php <hash_prefix>\n"); exit(1); }

$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];
foreach ($dirs as $d) {
    foreach (glob($d . '/*.contract.json') as $f) {
        $j = json_decode(file_get_contents($f), true);
        if (!is_array($j) || empty($j['groups'])) continue;
        foreach ($j['groups'] as $gi => $g) {
            if (!isset($g['items'])) continue;
            foreach ($g['items'] as $ii => $it) {
                foreach (['question','options','solution','justification'] as $k) {
                    if (!isset($it[$k])) continue;
                    foreach ($it[$k] as $bi => $b) {
                        if (($b['type'] ?? '') !== 'tikz') continue;
                        $script = (string)($b['script'] ?? '');
                        $h = hash('sha256', TikzRenderService::normalize($script));
                        if (str_starts_with($h, $prefix)) {
                            echo "FOUND: " . basename($f) . " G$gi I$ii $k[$bi]\n";
                            echo "Hash: $h\n";
                            echo "Script len: " . strlen($script) . "\n";
                            echo "---\n$script\n---\n";
                            return;
                        }
                    }
                }
            }
        }
    }
}
echo "Not found.\n";
