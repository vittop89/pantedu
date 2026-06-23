<?php

declare(strict_types=1);

/**
 * Seed/reset delle fixture per i test E2E in LOCALE (XAMPP).
 *
 * Cosa fa (idempotente, solo DB locale di sviluppo):
 *   1. Reimposta la password dell'utente admin reale (role=administrator) a un
 *      valore noto, così `loginAdmin` (helpers.js, FM_E2E_ADMIN_*) funziona.
 *      La password reale non sta nel repo per sicurezza → qui la si resetta solo
 *      in locale. Valore: env FM_E2E_ADMIN_PASSWORD (default 'Test!Admin_2026.').
 *   2. Azzera lo stato del WAF (login_failures / blocked_credentials /
 *      blocked_ips): i test fanno molte login e, senza reset, il bruteforce-guard
 *      accumula ban → timeout a catena nei run successivi.
 *   3. Verifica i contenuti `risdoc_templates` attesi dai test sidebar btn3/btn4
 *      (STRCOMP/ALTRO/MODELLI/RISORSE) e avvisa se sotto soglia.
 *
 * Uso:  php tools/dev/seed_e2e_fixtures.php
 *
 * GUARDIA: rifiuta di girare se non è chiaramente un ambiente locale/dev.
 */

require __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$adminUser = (string)($_ENV['FM_E2E_ADMIN_USERNAME'] ?? 'admin');
$adminPass = (string)($_ENV['FM_E2E_ADMIN_PASSWORD'] ?? 'Test!Admin_2026.');

$dbName = (string)($_ENV['DB_NAME'] ?? '');
$dbHost = (string)($_ENV['DB_HOST'] ?? '');
$appEnv = (string)($_ENV['APP_ENV'] ?? '');

// ── Guardia anti-produzione ───────────────────────────────────────────────
$looksLocal = in_array($dbHost, ['127.0.0.1', 'localhost', '::1'], true)
    || str_contains($dbName, 'dev')
    || str_contains($dbName, 'local')
    || str_contains($dbName, 'test');
if ($appEnv === 'production' || !$looksLocal) {
    fwrite(STDERR, "RIFIUTO: ambiente non locale (DB_NAME='$dbName', DB_HOST='$dbHost', APP_ENV='$appEnv').\n");
    fwrite(STDERR, "Questo script reimposta una password admin: NON eseguire in produzione.\n");
    exit(2);
}

$pdo = Database::connection();
echo "DB: $dbName @ $dbHost\n\n";

// ── 1. Reset password admin (role=administrator) ──────────────────────────
$hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
$stmt = $pdo->prepare(
    "UPDATE users SET password_hash = :h, must_change_password = 0, status = 'approved'
     WHERE username = :u AND role = 'administrator'"
);
$stmt->execute([':h' => $hash, ':u' => $adminUser]);
$n = $stmt->rowCount();
if ($n > 0) {
    echo "[1] Password admin '$adminUser' reimpostata (role=administrator). ✓\n";
} else {
    echo "[1] ⚠ Nessun utente '$adminUser' con role=administrator trovato (niente reset).\n";
}

// ── 2. Reset stato WAF (idempotente, tabelle opzionali) ───────────────────
$cleared = [];
foreach (['waf_login_failures', 'waf_blocked_credentials', 'waf_blocked_ips'] as $t) {
    try {
        $c = $pdo->exec("DELETE FROM `$t`");
        $cleared[] = "$t (-" . (int)$c . ")";
    } catch (\Throwable $e) {
        // tabella assente in locale: skip
    }
}
echo "[2] WAF state azzerato: " . ($cleared ? implode(', ', $cleared) : 'nessuna tabella') . " ✓\n";

// ── 2b. Pubblica esercizi SCI/2/MAT (sidebar studio + openExercise upbar test) ──
//     La lista /api/study/content.json legge teacher_content.visibility; il body
//     resta cifrato (non serve ai test, che verificano solo lista/link/upbar).
try {
    $pub = $pdo->exec(
        "UPDATE teacher_content SET visibility='published'
         WHERE content_type='esercizio' AND indirizzo='SCI' AND classe='2' AND subject_code='MAT'"
    );
    echo "[2b] Esercizi SCI/2/MAT pubblicati: " . (int)$pub . " ✓\n";
} catch (\Throwable $e) {
    echo "[2b] ⚠ pubblicazione esercizi SCI/2/MAT non riuscita: {$e->getMessage()}\n";
}

// ── 3. Verifica contenuti sidebar (risdoc_templates) ──────────────────────
//      Mappa categoria DB → label test + soglia minima attesa.
$want = ['bes' => 1, 'altro' => 4, 'modelli' => 6, 'risorse' => 4];
echo "[3] Contenuti risdoc_templates (atteso dai test sidebar btn3/btn4):\n";
$ok = true;
try {
    $rows = $pdo->query("SELECT LOWER(category) cat, COUNT(*) n FROM risdoc_templates GROUP BY LOWER(category)")
        ->fetchAll(\PDO::FETCH_KEY_PAIR);
    foreach ($want as $cat => $min) {
        $have = (int)($rows[$cat] ?? 0);
        $mark = $have >= $min ? '✓' : '✗ SOTTO SOGLIA';
        if ($have < $min) { $ok = false; }
        echo sprintf("    - %-8s ha %d (min %d) %s\n", $cat, $have, $min, $mark);
    }
} catch (\Throwable $e) {
    echo "    ⚠ tabella risdoc_templates non leggibile: {$e->getMessage()}\n";
    $ok = false;
}
echo $ok
    ? "    → contenuti sufficienti per i test sidebar. ✓\n"
    : "    → contenuti insufficienti: importare/seedare i template mancanti.\n";

echo "\nFatto. Imposta in .env.local: FM_E2E_ADMIN_USERNAME=$adminUser  FM_E2E_ADMIN_PASSWORD=<password usata qui>\n";
