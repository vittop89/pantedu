<?php
/**
 * Phase 20 — modernize contract legacy: strippa dall'intro di ogni
 * group.intro i `<span class="fm-giustifica">...</span>` + l'HTML residuo,
 * lasciando plain text. Il ContractRenderer re-aggiunge lo span
 * giustifica conditionalmente per type VF/RM, quindi il DB diventa
 * coerente con il modello "intro = plain text".
 *
 * Run:
 *   php tools/modernize_contract_intros.php           # dry-run
 *   php tools/modernize_contract_intros.php --apply   # esegui + save
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Support\Storage\StorageFactory;

$apply = in_array('--apply', $argv, true);

/** Plain-text + rimozione della clausola "Giustifica adeguatamente /
 *  algebricamente ..." dai gruppi VF/RM (ora emessa dal renderer come
 *  span separato). Gruppi Collect non perdono testo utile: qui la
 *  parola "Giustifica" se presente è parte integrante della consegna. */
function cleanIntro(string $html, string $type): string
{
    $hasHtml = strpos($html, '<') !== false || strpos($html, '&') !== false;

    $stripped = (string)preg_replace(
        '/<span\s+class=["\']giustifica["\'][^>]*>.*?<\/span>/is',
        '',
        $html,
    );
    $plain = strip_tags($stripped);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = trim((string)preg_replace('/\s+/', ' ', $plain));

    $isVForRM = (bool)preg_match('/^(type_)?(VF|RM)/i', $type);
    if ($isVForRM) {
        // Taglia da "Giustifica {adeguatamente|algebricamente}..." in poi.
        $parts = preg_split(
            '/\s*\bGiustifica\b\s+(adeguatamente|algebricamente)[^.]*\.?/iu',
            $plain,
            2,
        );
        if (is_array($parts) && count($parts) >= 1) {
            $plain = trim($parts[0]);
        }
    }

    // No-op: se identico all'originale (case ideale per plain intro privi
    // di span e clausola giustifica) evita di marcare dirty.
    if (!$hasHtml && !$isVForRM) {
        return $html;
    }
    return $plain;
}

$storage = StorageFactory::default();
$baseDir = dirname(__DIR__) . '/storage/objects';

$scanned = 0;
$changed = 0;
$errors  = 0;

$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($iter as $f) {
    if (!$f->isFile()) continue;
    if (!str_ends_with($f->getFilename(), '.contract.json')) continue;
    $scanned++;

    $path = $f->getPathname();
    $relKey = str_replace('\\', '/', substr($path, strlen($baseDir) + 1));
    $raw = @file_get_contents($path);
    if ($raw === false) { $errors++; continue; }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['groups']) || !is_array($data['groups'])) continue;

    $dirty = false;
    foreach ($data['groups'] as $gi => $g) {
        $intro = (string)($g['intro'] ?? '');
        if ($intro === '') continue;
        $type = (string)($g['type'] ?? '');
        $clean = cleanIntro($intro, $type);
        if ($clean === $intro) continue;
        $data['groups'][$gi]['intro'] = $clean;
        $dirty = true;
        echo sprintf(
            "[%s] group %d: \"%s\" → \"%s\"\n",
            $relKey, $gi,
            mb_substr($intro, 0, 70),
            mb_substr($clean, 0, 70),
        );
    }
    if (!$dirty) continue;
    $changed++;

    if ($apply) {
        $data['version'] = (int)($data['version'] ?? 0) + 1;
        $bytes = (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            $storage->put($relKey, $bytes);
        } catch (\Throwable $e) {
            fwrite(STDERR, "put($relKey) failed: {$e->getMessage()}\n");
            $errors++;
        }
    }
}

echo "\n── Summary ──\n";
echo "Scanned: $scanned\n";
echo "Changed: $changed\n";
echo "Errors:  $errors\n";
echo $apply ? "MODE: --apply (written)\n" : "MODE: dry-run (no write). Run --apply to persist.\n";
