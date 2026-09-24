<?php
/**
 * Phase 25.R.1.1 — Cleanup institutes table from pentest/fuzz pollution.
 *
 * Background:
 *   Tabella `institutes` contiene ~68 entries malformi inseriti da OWASP ZAP
 *   fuzzing / pentest non documentato: path traversal, SQL injection payload,
 *   PowerShell payload (`;start-sleep -s N`, `;get-help`), XSS, ecc.
 *
 *   La validazione stretta in `InstituteRepository::upsert` impedisce nuovi
 *   inserimenti malformi; questo tool ripulisce le righe già presenti.
 *
 * Uso:
 *   php tools/admin/cleanup_institutes.php             # DRY RUN
 *   php tools/admin/cleanup_institutes.php --apply     # esegue DELETE
 *   php tools/admin/cleanup_institutes.php --keep=106,108,109,110  # whitelist esplicita
 *
 * Sicurezza:
 *   - DELETE solo righe SENZA teacher_institutes / users.admin_institute_id
 *     che le referenziano (per non rompere FK).
 *   - Le righe legittime (106 Esempio Sci, 108 Musicale, 109 Sportivo,
 *     110 IS Esempio) sono nella whitelist di default.
 *   - Logga audit in `log/audit/institutes_cleanup_YYYYMMDD.json`.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Database;
use App\Repositories\InstituteRepository;

// ── Parse argv ─────────────────────────────────────────────
$apply = false;
$keepIds = [106, 108, 109, 110]; // istituti legittimi confermati
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (str_starts_with($arg, '--keep=')) {
        $keepIds = array_map('intval', explode(',', substr($arg, 7)));
    }
}

echo "=== Institutes Cleanup (Phase 25.R.1.1) ===\n";
echo 'Mode: ' . ($apply ? "APPLY (DELETE rows)" : "DRY RUN (no changes)") . "\n";
echo 'Whitelist (keep): ' . implode(',', $keepIds) . "\n\n";

if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile\n");
    exit(1);
}

$pdo = Database::connection();

// ── Fetch all institutes ───────────────────────────────────
$rows = $pdo->query('SELECT id, code, name, city, region, active FROM institutes ORDER BY id')
    ->fetchAll(PDO::FETCH_ASSOC);

echo "Total institutes in DB: " . count($rows) . "\n\n";

$repo = new InstituteRepository();

// Reflection per riusare i validator privati
$ref = new ReflectionClass($repo);
$validateCode = $ref->getMethod('assertValidCode');
$validateCode->setAccessible(true);
$validateName = $ref->getMethod('assertValidName');
$validateName->setAccessible(true);
$validateLoc = $ref->getMethod('assertValidLocation');
$validateLoc->setAccessible(true);

$legit = [];
$malformed = [];

foreach ($rows as $r) {
    $id = (int)$r['id'];
    if (in_array($id, $keepIds, true)) {
        $legit[] = $r + ['reason' => 'whitelist'];
        continue;
    }

    $errors = [];
    try {
        $validateCode->invoke($repo, (string)$r['code']);
    } catch (\InvalidArgumentException $e) {
        $errors[] = 'code: ' . $e->getMessage();
    }
    try {
        $validateName->invoke($repo, (string)$r['name']);
    } catch (\InvalidArgumentException $e) {
        $errors[] = 'name: ' . $e->getMessage();
    }
    if (!empty($r['city'])) {
        try {
            $validateLoc->invoke($repo, (string)$r['city'], 'city');
        } catch (\InvalidArgumentException $e) {
            $errors[] = 'city: ' . $e->getMessage();
        }
    }
    if (!empty($r['region'])) {
        try {
            $validateLoc->invoke($repo, (string)$r['region'], 'region');
        } catch (\InvalidArgumentException $e) {
            $errors[] = 'region: ' . $e->getMessage();
        }
    }

    if ($errors) {
        $malformed[] = $r + ['errors' => $errors];
    } else {
        $legit[] = $r + ['reason' => 'passes_validation'];
    }
}

// ── Report legit ───────────────────────────────────────────
echo "── LEGITIMATE (" . count($legit) . ") ──\n";
foreach ($legit as $r) {
    printf(
        "  [%4d] code=%-32s name=%-60s reason=%s\n",
        $r['id'],
        substr((string)$r['code'], 0, 32),
        substr((string)$r['name'], 0, 60),
        $r['reason']
    );
}

echo "\n── MALFORMED (" . count($malformed) . ") ──\n";

// ── Check FK references prima di DELETE ────────────────────
$idsToDelete = [];
$idsBlocked = [];

foreach ($malformed as $r) {
    $id = (int)$r['id'];
    $code = (string)$r['code'];
    $name = (string)$r['name'];

    // FK check
    $tiCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM teacher_institutes WHERE institute_id = ' . $id
    )->fetchColumn();
    $usrCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM users WHERE admin_institute_id = ' . $id
    )->fetchColumn();
    // studenti via users.institute_id se esiste
    $stuCount = 0;
    try {
        $stuCount = (int)$pdo->query(
            'SELECT COUNT(*) FROM users WHERE institute_id = ' . $id
        )->fetchColumn();
    } catch (\PDOException $e) {
        // colonna institute_id può non esistere
    }

    $blocked = ($tiCount + $usrCount + $stuCount) > 0;
    $marker = $blocked ? 'BLOCKED' : 'DELETE';
    printf(
        "  [%4d] %s code=%-30s name=%-50s refs=ti:%d/admin:%d/stu:%d errs=%s\n",
        $id,
        $marker,
        substr($code, 0, 30),
        substr($name, 0, 50),
        $tiCount,
        $usrCount,
        $stuCount,
        implode('|', $r['errors'])
    );
    if ($blocked) {
        $idsBlocked[] = $id;
    } else {
        $idsToDelete[] = $id;
    }
}

echo "\nSummary:\n";
echo "  legit: " . count($legit) . "\n";
echo "  malformed total: " . count($malformed) . "\n";
echo "  → deletable (no FK refs): " . count($idsToDelete) . "\n";
echo "  → blocked (has FK refs): " . count($idsBlocked) . "\n";

// ── Audit log ──────────────────────────────────────────────
$auditDir = dirname(__DIR__, 2) . '/log/audit';
if (!is_dir($auditDir)) {
    mkdir($auditDir, 0775, true);
}
$auditFile = $auditDir . '/institutes_cleanup_' . date('Ymd_His') . '.json';
file_put_contents($auditFile, json_encode([
    'timestamp' => date(DATE_ATOM),
    'mode'      => $apply ? 'apply' : 'dry-run',
    'whitelist' => $keepIds,
    'legit'     => $legit,
    'malformed' => $malformed,
    'to_delete' => $idsToDelete,
    'blocked'   => $idsBlocked,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nAudit log: $auditFile\n";

// ── Apply DELETE ────────────────────────────────────────────
if (!$apply) {
    echo "\nDRY RUN — nessuna modifica. Rilancia con --apply per cancellare.\n";
    exit(0);
}

if (empty($idsToDelete)) {
    echo "\nNothing to delete.\n";
    exit(0);
}

echo "\nDELETING " . count($idsToDelete) . " rows...\n";
$pdo->beginTransaction();
try {
    $in = implode(',', $idsToDelete);
    $deleted = $pdo->exec("DELETE FROM institutes WHERE id IN ($in)");
    $pdo->commit();
    echo "Deleted: $deleted rows.\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "DELETE failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone.\n";
