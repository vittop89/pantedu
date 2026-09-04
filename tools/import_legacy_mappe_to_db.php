<?php
/**
 * Phase 18 — Import mappe legacy (JSON di link Google Drawio) in
 * teacher_content.
 *
 * Sorgenti:
 *   storage/objects/institutes/{iid}/private/{tid}/mappe/{ind}/
 *     mappe_{ind}{cls}/{SUBJ}/{SUBJ}_mappe-links_{ind}{cls}.json
 *
 * Ogni entry del JSON diventa una riga teacher_content:
 *   content_type = 'mappa'
 *   subject_code = SUBJ  (MAT / FIS / GEO / ...)
 *   indirizzo    = ind
 *   classe       = cls
 *   topic        = NumArg  (es. "1.0", "2.1")
 *   title        = argomento
 *   metadata_json = { mappa: { href, href_hide, drawio_id, display } }
 *   visibility   = 'published'  (le mappe pre-Phase 18 erano pubbliche)
 *
 * Idempotente: skip row se (teacher_id, content_type, subject_code,
 * indirizzo, classe, topic) già esiste.
 *
 * Run:
 *   php tools/import_legacy_mappe_to_db.php             # dry-run
 *   php tools/import_legacy_mappe_to_db.php --apply     # commit
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

if (!Config::get('database.enabled')) {
    fwrite(STDERR, "DB_ENABLED=false.\n");
    exit(1);
}

$apply = in_array('--apply', $argv, true);
$root  = dirname(__DIR__);
$pdo   = Database::connection();

$pattern = $root . '/storage/objects/institutes/*/private/*/mappe/*/mappe_*/*/'
         . '*_mappe-links_*.json';
$files = glob($pattern) ?: [];

echo "Trovati " . count($files) . " JSON mappe.\n";
if (!$files) exit(0);

$toInsert = [];
$skipped  = 0;
$existStmt = $pdo->prepare(
    'SELECT id FROM teacher_content
     WHERE teacher_id = :t AND content_type = "mappa"
       AND subject_code = :s AND indirizzo = :ind AND classe = :cls AND topic = :topic
     LIMIT 1'
);

foreach ($files as $f) {
    $rel = str_replace('\\', '/', substr($f, strlen($root) + 1));
    // parse: storage/objects/institutes/{iid}/private/{tid}/mappe/{ind}/mappe_{ind}{cls}/{SUBJ}/{SUBJ}_mappe-links_{ind}{cls}.json
    if (!preg_match(
        '#^storage/objects/institutes/(\d+)/private/(\d+)/mappe/([a-z]+)/mappe_([a-z]+)(\d+[sb]?)(?:[a-z]+)?/([A-Z]+)/[A-Z]+_mappe-links_[a-z]+\d+[sb]?(?:[a-z]+)?\.json$#i',
        $rel,
        $m
    )) {
        echo "[skip] path non parsato: $rel\n";
        continue;
    }
    [$_, $iid, $tid, $ind, $indCheck, $cls, $subj] = $m;
    if ($ind !== $indCheck) {
        echo "[skip] ind mismatch ($ind vs $indCheck): $rel\n";
        continue;
    }

    $raw = @file_get_contents($f);
    $links = $raw ? (json_decode($raw, true) ?: []) : [];
    if (!is_array($links)) continue;

    foreach ($links as $lnk) {
        $numArg = trim((string)($lnk['NumArg']    ?? ''));
        $arg    = trim((string)($lnk['argomento'] ?? ''));
        $href   = trim((string)($lnk['href']      ?? ''));
        if ($numArg === '' || $arg === '' || $href === '') continue;

        $existStmt->execute([
            ':t'     => (int)$tid,
            ':s'     => $subj,
            ':ind'   => $ind,
            ':cls'   => $cls,
            ':topic' => $numArg,
        ]);
        if ($existStmt->fetchColumn()) { $skipped++; continue; }

        $toInsert[] = [
            'teacher_id' => (int)$tid,
            'iid'        => (int)$iid,
            'subject'    => $subj,
            'indirizzo'  => $ind,
            'classe'     => $cls,
            'topic'      => $numArg,
            'title'      => $arg,
            'metadata'   => [
                'mappa' => [
                    'href'      => $href,
                    'href_hide' => (string)($lnk['href-hide'] ?? ''),
                    'drawio_id' => (string)($lnk['id']        ?? ''),
                    'display'   => (string)($lnk['display']   ?? 'show'),
                ],
            ],
        ];
    }
}

echo "Da inserire: " . count($toInsert) . "\n";
echo "Già esistenti (skipped): $skipped\n";

if (!$apply) {
    echo "\nDRY-RUN. --apply per committare.\n";
    exit(0);
}

$ins = $pdo->prepare(
    'INSERT INTO teacher_content
       (teacher_id, content_type, subject_code, indirizzo, classe,
        topic, title, metadata_json, visibility)
     VALUES (?, "mappa", ?, ?, ?, ?, ?, ?, "published")'
);

$pdo->beginTransaction();
try {
    $n = 0;
    foreach ($toInsert as $r) {
        $ins->execute([
            $r['teacher_id'],
            $r['subject'],
            $r['indirizzo'],
            $r['classe'],
            $r['topic'],
            $r['title'],
            json_encode($r['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $n++;
    }
    $pdo->commit();
    echo "\nInserite $n mappe in teacher_content.\n";
} catch (\Throwable $t) {
    $pdo->rollBack();
    fwrite(STDERR, "ERRORE: " . $t->getMessage() . "\n");
    exit(1);
}
