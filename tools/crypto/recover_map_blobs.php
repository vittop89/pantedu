<?php
/**
 * Recovery script: ri-cifra .drawio plaintext con KEK attuale e aggiorna DB.
 *
 * Background:
 *   Una `teacher_keys` row è stata wipe-ata + ricreata in passato. La KEK
 *   originale è persa → tutti i blob `storage/maps_enc/{tid}/*.bin` cifrati
 *   prima non decifrano più con la KEK attuale. Le mappe risultano "morte".
 *
 *   Buona notizia: i file `.drawio` sorgenti (plaintext) sono ancora in
 *   `storage/objects/institutes/{ins_id}/private/{tid}/mappe/...`. Possiamo
 *   ricifrarli e aggiornare `teacher_content.map_blob_path` ai nuovi blob.
 *
 * Uso:
 *   php tools/crypto/recover_map_blobs.php [--apply] [--teacher=77] [--id=236]
 *
 *   --apply       Senza questo flag = DRY RUN (report, no modifiche)
 *   --teacher=N   Filtra per teacher_id (default: 77 per ora hardcoded)
 *   --id=N        Recupera solo un teacher_content.id specifico (test)
 *
 * Sicurezza:
 *   - Non sovrascrive i vecchi blob; crea nuovi ULID e li salva
 *   - Atomic update: ULID nuovo scritto su disk PRIMA di UPDATE DB
 *   - Logga tutti gli step in crypto_access_log
 *
 * Output:
 *   Tabella stato per ogni teacher_content row:
 *     id | content | drawio_path | esito
 *     dove esito ∈ {OK, NO_DRAWIO, MULTIPLE_MATCH, ENCRYPT_FAIL, SKIP}
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\Maps\MapBlobStore;
use App\Services\Crypto\TeacherCryptoService;

// ── Parse argv ─────────────────────────────────────────────
$apply = false;
$teacherFilter = 77; // default per ora
$idFilter = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') $apply = true;
    elseif (str_starts_with($arg, '--teacher=')) $teacherFilter = (int)substr($arg, 10);
    elseif (str_starts_with($arg, '--id=')) $idFilter = (int)substr($arg, 5);
}

echo "=== Map Blob Recovery ===\n";
echo "Mode: " . ($apply ? "🔥 APPLY (modifica DB + crea blob)" : "🔍 DRY RUN (no modifiche)") . "\n";
echo "Teacher: $teacherFilter" . ($idFilter ? " | id=$idFilter" : "") . "\n\n";

// ── DB query ───────────────────────────────────────────────
$pdo = Database::connection();
$sql = "SELECT id, teacher_id, content_type, subject_code, indirizzo, classe, topic, title, map_blob_path
        FROM teacher_content
        WHERE teacher_id = :tid AND content_type = 'mappa'";
$params = [':tid' => $teacherFilter];
if ($idFilter !== null) {
    $sql .= " AND id = :id";
    $params[':id'] = $idFilter;
}
$sql .= " ORDER BY id";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Rows da processare: " . count($rows) . "\n\n";

// ── Funzione per costruire path .drawio ─────────────────────
function build_drawio_path(string $root, array $row): array
{
    // Path: storage/objects/institutes/106/private/{tid}/mappe/{ind_low2}/mappe_{ind_low2}{classe}s/{subject}/drawio/{classe}_{topic} - Mappa - {title}.drawio
    // institute 106 è hardcoded (single-institute deployment per ora)
    $insId = 106;
    $tid = (int)$row['teacher_id'];
    $indLow = strtolower(substr((string)$row['indirizzo'], 0, 2));
    $classe = (int)$row['classe'];
    $classeFolder = $indLow . $classe . 's';
    $subject = (string)$row['subject_code'];
    $topic = (string)$row['topic'];
    $title = (string)$row['title'];

    $base = $root . "/storage/objects/institutes/$insId/private/$tid/mappe/$indLow/mappe_$classeFolder/$subject/drawio";
    $expected = "$base/{$classe}_{$topic} - Mappa - {$title}.drawio";

    // Match esatto + 2 fallback (con / senza prefisso "Mappa -")
    $candidates = [
        $expected,
        "$base/{$classe}_{$topic} - {$title}.drawio",
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) return ['found' => $c, 'tried' => $candidates];
    }

    // Fallback glob: cerca pattern flessibile nel folder
    if (is_dir($base)) {
        $pattern = "$base/*{$topic}*{$title}*.drawio";
        $matches = glob($pattern);
        // Filtra varianti rumorose (X, _, copy, ecc.) preferendo nome esatto
        $clean = array_filter($matches, function ($p) use ($title) {
            $bn = basename($p, '.drawio');
            // accetta solo se NON termina con caratteri sospetti dopo title
            return !preg_match('/' . preg_quote($title, '/') . '[X_]+\.drawio$|copy|backup/i', basename($p));
        });
        if (count($clean) === 1) return ['found' => array_values($clean)[0], 'tried' => $candidates];
        if (count($clean) > 1) return ['multiple' => array_values($clean), 'tried' => $candidates];
    }

    return ['found' => null, 'tried' => $candidates, 'searched_dir' => $base];
}

// ── MapBlobStore (per encrypt) ─────────────────────────────
$store = new MapBlobStore();
$crypto = new TeacherCryptoService();
if (!$crypto->isConfigured()) {
    echo "❌ FATAL: TeacherCryptoService non configurato (KMS_MASTER_KEY mancante)\n";
    exit(1);
}

$root = dirname(__DIR__, 2);

// ── Process rows ───────────────────────────────────────────
$stats = ['ok' => 0, 'no_drawio' => 0, 'multiple' => 0, 'encrypt_fail' => 0, 'skip_orphan' => 0];
$reportLines = [];

foreach ($rows as $r) {
    $id = (int)$r['id'];
    $title = $r['title'];
    $oldPath = $r['map_blob_path'] ?? '(null)';

    $resolved = build_drawio_path($root, $r);
    if (isset($resolved['multiple'])) {
        $stats['multiple']++;
        $reportLines[] = sprintf("id=%-4d | MULTIPLE_MATCH | %s | candidates: %s",
            $id, $title, implode(' ; ', array_map('basename', $resolved['multiple'])));
        continue;
    }
    if (empty($resolved['found'])) {
        $stats['no_drawio']++;
        $reportLines[] = sprintf("id=%-4d | NO_DRAWIO     | %s | tried: %s",
            $id, $title, implode(' | ', array_map(fn ($p) => basename($p), $resolved['tried'])));
        continue;
    }

    $drawioPath = $resolved['found'];
    $plaintext = @file_get_contents($drawioPath);
    if ($plaintext === false || $plaintext === '') {
        $stats['no_drawio']++;
        $reportLines[] = sprintf("id=%-4d | EMPTY_FILE    | %s | path: %s",
            $id, $title, basename($drawioPath));
        continue;
    }

    if (!$apply) {
        // DRY RUN
        $stats['ok']++;
        $reportLines[] = sprintf("id=%-4d | OK (dry-run)  | %s | drawio=%s (%d bytes) | old_blob=%s",
            $id, $title, basename($drawioPath), strlen($plaintext), basename($oldPath));
        continue;
    }

    // APPLY: encrypt + save blob + update DB
    try {
        $newRelPath = $store->put((int)$r['teacher_id'], $plaintext);
        // Atomic: file scritto, ora UPDATE DB
        $upd = $pdo->prepare("UPDATE teacher_content SET map_blob_path = :p, map_size = :s, updated_at = NOW() WHERE id = :id");
        $upd->execute([':p' => $newRelPath, ':s' => strlen($plaintext), ':id' => $id]);
        $stats['ok']++;
        $reportLines[] = sprintf("id=%-4d | ✅ APPLIED     | %s | new_blob=%s (%d bytes)",
            $id, $title, basename($newRelPath), strlen($plaintext));
    } catch (\Throwable $e) {
        $stats['encrypt_fail']++;
        $reportLines[] = sprintf("id=%-4d | ❌ ENCRYPT_FAIL| %s | error: %s",
            $id, $title, $e->getMessage());
    }
}

// ── Report ────────────────────────────────────────────────
echo str_repeat('─', 100) . "\n";
foreach ($reportLines as $line) echo $line . "\n";
echo str_repeat('─', 100) . "\n";
echo "Stats:\n";
foreach ($stats as $k => $v) {
    if ($v > 0) echo "  $k = $v\n";
}
echo "\n";

if (!$apply) {
    echo "🔍 DRY RUN — nessuna modifica fatta.\n";
    echo "Per applicare: rilanciare con --apply\n";
} else {
    echo "🔥 APPLIED — DB e blob storage modificati.\n";
    echo "Verifica con: php tools/crypto/test_e2e_blob.php <id>\n";
}
