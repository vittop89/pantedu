<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\Tikz\TikzRenderService;

$path = __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche/MAT-Rette_fasci_di_rette_e_piani-ver.contract.json';
$data = json_decode(file_get_contents($path), true);
$script = $data['groups'][5]['items'][0]['solution'][2]['script'];

// Compute hash using same logic as TikzRenderService
$normalized = TikzRenderService::normalize($script);
// Apply preamble strip if not standalone
$isStandalone = (bool)preg_match('#\\\\begin\s*\{\s*document\s*\}|\\\\documentclass#', $normalized);
if (!$isStandalone) {
    $normalized = preg_replace('#\\\\usepackage(?:\[[^\]]*\])?\{[^}]+\}\s*#', '', $normalized);
    $normalized = preg_replace('#\\\\usetikzlibrary\{[^}]+\}\s*#', '', $normalized);
    $normalized = preg_replace('#\\\\pagestyle\{[^}]+\}\s*#', '', $normalized);
    $normalized = trim($normalized);
}
$hash = hash('sha256', $normalized);
$cachePath = __DIR__ . '/../storage/cache/tikz/public/' . substr($hash, 0, 2) . '/' . $hash . '.svg';

echo "Hash: $hash\n";
echo "Cache path: $cachePath\n";
echo "Cache exists: " . (file_exists($cachePath) ? 'YES' : 'NO') . "\n";

if (file_exists($cachePath)) {
    $svg = file_get_contents($cachePath);
    echo "Size: " . strlen($svg) . " bytes\n";
    $n_text = substr_count($svg, '<text');
    $n_font = substr_count($svg, 'font-family');
    $n_path = substr_count($svg, '<path');
    echo "  <text>: $n_text\n";
    echo "  font-family: $n_font\n";
    echo "  <path>: $n_path\n";
    // First 200 chars
    echo "Preview: " . substr($svg, 0, 200) . "...\n";
}
