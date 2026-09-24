<?php
/**
 * Phase 25.C12 — Data breach drill semestrale.
 *
 * Simula uno scenario di breach senza toccare i dati reali. Verifica:
 *   1. Tempistica risposta entro 72h (Art. 33 GDPR)
 *   2. Audit trail completo: log query disponibili
 *   3. Snapshot DB+files OK (verify storage/backups/ scritto)
 *   4. KMS recovery testabile (se Phase 25.D11 documentato)
 *   5. Comunicazione utenti email pre-formulata pronta
 *
 * Frequenza: 2 volte/anno (gennaio + luglio). Calendar invite obbligatorio.
 *
 * Output: report `storage/gdpr/drills/drill-{YYYY-MM-DD}.md` con:
 *   - Timestamp inizio/fine drill
 *   - Step eseguiti + tempo per ognuno
 *   - Anomalie trovate (es. log mancante, snapshot fallito)
 *   - Action items per remediation
 *
 * Usage:
 *   php tools/gdpr/breach_drill.php                       # full drill (15-30 min)
 *   php tools/gdpr/breach_drill.php --quick               # smoke check (2-5 min)
 *   php tools/gdpr/breach_drill.php --scenario=alto       # scenario alto (full notify simulation)
 *
 * NB: questo script NON tocca dati reali. Genera report markdown.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

if (PHP_SAPI !== 'cli') exit(1);

$quick = in_array('--quick', $argv, true);
$scenario = 'medio';
foreach ($argv as $a) {
    if (preg_match('/^--scenario=(basso|medio|alto)$/', $a, $m)) $scenario = $m[1];
}

$startTime = microtime(true);
$today = date('Y-m-d');
// 2026-09-22 — i dati d'istanza, non il repository. In produzione la copia
// del codice sta dentro il container: un rapporto scritto li' sparisce al
// primo rilascio, ed e' una delle ragioni per cui nessuno ne ha mai visto uno.
$reportDir = ((string)\App\Core\Config::get('app.paths.storage', dirname(__DIR__, 2) . '/storage'))
    . '/gdpr/drills';
if (!is_dir($reportDir)) mkdir($reportDir, 0755, true);
$reportFile = "$reportDir/drill-$today.md";

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  DATA BREACH DRILL (Phase 25.C12)\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Scenario:  $scenario\n";
echo "  Modo:      " . ($quick ? "quick (smoke)" : "full") . "\n";
echo "  Started:   " . date('H:i:s') . "\n";
echo "  Report:    $reportFile\n";
echo "\n";

$report = "# Data Breach Drill — $today\n\n";
$report .= "**Scenario simulato:** $scenario\n";
$report .= "**Modo:** " . ($quick ? "quick" : "full") . "\n";
$report .= "**Operatore:** " . get_current_user() . "\n";
$report .= "**Inizio drill:** " . date('Y-m-d H:i:s T') . "\n\n";

$findings = [];
$actionItems = [];
$stepTimes = [];

// ─────── STEP 1 — Verifica accessibilità log ───────
$t0 = microtime(true);
echo "▶ Step 1 — Verifica accessibilità audit log\n";
try {
    $count = (int)Database::connection()->query(
        "SELECT COUNT(*) FROM privileged_access_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    )->fetchColumn();
    echo "    ✓ privileged_access_log: $count entries last 24h\n";
    $report .= "## Step 1 — Audit log\n- privileged_access_log accessibile: ✅ ($count entries last 24h)\n";
} catch (\Throwable $e) {
    echo "    ✗ FAIL: " . $e->getMessage() . "\n";
    $findings[] = "Audit log non accessibile: " . $e->getMessage();
    $report .= "## Step 1 — Audit log\n- ❌ FAIL: " . $e->getMessage() . "\n";
}

try {
    $count = (int)Database::connection()->query(
        "SELECT COUNT(*) FROM crypto_access_log WHERE accessed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    )->fetchColumn();
    echo "    ✓ crypto_access_log: $count entries last 24h\n";
    $report .= "- crypto_access_log accessibile: ✅ ($count entries last 24h)\n";
} catch (\Throwable $e) {
    $findings[] = "crypto_access_log non accessibile (Phase 25.D)";
    $report .= "- ❌ crypto_access_log non accessibile\n";
}
$stepTimes[1] = microtime(true) - $t0;

// ─────── STEP 2 — Verifica directory backup ───────
$t0 = microtime(true);
echo "▶ Step 2 — Copia di sicurezza recente\n";
// 2026-09-22 — controllava che `storage/backups/db` e `storage/backups/files`
// fossero scrivibili: due cartelle di un disegno abbandonato, che non esistono
// piu'. Il rapporto le segnalava a ogni giro, e un rapporto che non e' mai
// pulito dopo un mese non si apre piu'.
//
// E controllava la cosa sbagliata: che si POSSA scrivere un salvataggio, non
// che ce ne SIA uno. Davanti a una violazione serve la seconda.
//
// Il salvataggio vero lo scrive `tools/backup/encrypted_backup.sh` in
// $BACKUP_DIR (predefinito /var/backups/pantedu, anche se la variabile e'
// vuota: app/Config/backup.php, dal 23/9/2026); il controllo quotidiano e'
// l'invariante «lavori» della diagnostica, che legge la stessa chiave. Qui
// si rifa' al momento dell'esercitazione, perche' un piano che non verifica da
// se' di avere una copia non e' un piano.
$backupDir = rtrim((string)\App\Core\Config::get('backup.dir', '/var/backups/pantedu'), '/');
$backupOk  = true;
$report .= "\n## Step 2 — Copia di sicurezza recente\n";
$piuRecente = 0;
foreach (glob($backupDir . '/pantedu-backup-*.tar.gpg') ?: [] as $f) {
    $piuRecente = max($piuRecente, (int)@filemtime($f));
}
$giorni = $piuRecente > 0 ? (int)floor((time() - $piuRecente) / 86400) : -1;
if ($piuRecente > 0 && $giorni <= 2) {
    $eta = $giorni === 0 ? 'di oggi' : "di {$giorni} giorni fa";
    echo "    ✓ copia cifrata {$eta} in {$backupDir}\n";
    $report .= "- ✅ copia cifrata {$eta} in `{$backupDir}`\n";
} else {
    $quando = $giorni < 0 ? 'nessuna copia' : "la più recente è di {$giorni} giorni fa";
    echo "    ⚠ {$quando} in {$backupDir}\n";
    $report .= "- ⚠ `{$backupDir}`: {$quando}\n";
    $findings[] = "nessuna copia di sicurezza recente in {$backupDir} ({$quando})";
    $actionItems[] = 'Controllare pantedu-backup-encrypted.timer e il registro di tools/backup/encrypted_backup.sh';
    $backupOk = false;
}
$stepTimes[2] = microtime(true) - $t0;

// ─────── STEP 3 — Verifica KMS_MASTER_KEY ───────
$t0 = microtime(true);
echo "▶ Step 3 — KMS_MASTER_KEY config + recovery runbook\n";
$report .= "\n## Step 3 — KMS recovery readiness\n";
// 23/9/2026 (A-36) — la stessa regola dell'app (ChiaveMadre): prima bastavano
// 64 caratteri qualsiasi, e una chiave che la cifratura rifiuta passava il drill.
$chiave = \App\Services\Crypto\ChiaveMadre::dallAmbiente();
if (!$chiave->valida()) {
    echo "    ⚠ KMS_MASTER_KEY " . $chiave->stato() . " (servono 64 caratteri esadecimali)\n";
    $report .= "- ⚠ KMS_MASTER_KEY " . $chiave->stato() . " → encryption Phase 25.D inattiva\n";
    $findings[] = "KMS_MASTER_KEY missing in production";
    $actionItems[] = "Run php tools/crypto/generate_kms_key.php e configura .env.local";
} else {
    echo "    ✓ KMS_MASTER_KEY configurato (64 hex char)\n";
    $report .= "- ✅ KMS_MASTER_KEY configurato\n";
}

$recoveryDoc = dirname(__DIR__, 2) . '/docs/security/operations/kms-recovery.md';
if (is_file($recoveryDoc)) {
    echo "    ✓ docs/security/operations/kms-recovery.md presente\n";
    $report .= "- ✅ KMS recovery runbook presente\n";
} else {
    $findings[] = "KMS recovery runbook missing";
    $report .= "- ❌ docs/security/operations/kms-recovery.md missing\n";
}
$stepTimes[3] = microtime(true) - $t0;

// ─────── STEP 4 — Test query forensic ───────
if (!$quick) {
    $t0 = microtime(true);
    echo "▶ Step 4 — Test query forensic (estraibili in <30s)\n";
    $report .= "\n## Step 4 — Forensic query timings\n";
    $queries = [
        // 2026-09-22 — questa riga si chiamava `login_attempts_24h` e
        // contava gli UTENTI CREATI nelle ultime 24 ore: una misura che
        // dichiarava una cosa e ne restituiva un'altra, dentro lo strumento
        // che serve a dimostrare di poter ricostruire un incidente. La fonte
        // vera dei tentativi falliti e' `waf_login_failures` (ip, username,
        // created_at). Il conteggio degli utenti creati resta, con il suo nome.
        'tentativi_di_accesso_falliti_24h' => "SELECT COUNT(*) FROM waf_login_failures WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
        'utenti_creati_24h'  => "SELECT COUNT(*) FROM users WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
        'super_admin_actions' => "SELECT COUNT(*) FROM privileged_access_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
        'failed_decrypts'    => "SELECT COUNT(*) FROM crypto_access_log WHERE outcome='error' AND accessed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
    ];
    foreach ($queries as $name => $sql) {
        $qStart = microtime(true);
        try {
            $r = (int)Database::connection()->query($sql)->fetchColumn();
            $qTime = round((microtime(true) - $qStart) * 1000);
            echo "    ✓ $name: $r ({$qTime}ms)\n";
            $report .= "- ✅ `$name`: $r row, {$qTime}ms\n";
        } catch (\Throwable $e) {
            echo "    ✗ $name: " . $e->getMessage() . "\n";
            $report .= "- ❌ `$name`: " . $e->getMessage() . "\n";
        }
    }
    $stepTimes[4] = microtime(true) - $t0;
}

// ─────── STEP 5 — Verifica template comunicazione ───────
$t0 = microtime(true);
echo "▶ Step 5 — Template comunicazione utenti\n";
$report .= "\n## Step 5 — Comunicazione utenti pre-formulata\n";
$tplFile = dirname(__DIR__, 2) . '/docs/privacy/breach_notification_template.md';
if (!is_file($tplFile)) {
    $findings[] = "Template notifica utenti mancante";
    $actionItems[] = "Creare docs/privacy/breach_notification_template.md con bozza email Art. 34";
    $report .= "- ⚠ Template notifica mancante (Art. 34 GDPR — comunicazione tempestiva)\n";
    echo "    ⚠ docs/privacy/breach_notification_template.md missing\n";
} else {
    $report .= "- ✅ Template notifica presente\n";
    echo "    ✓ template breach_notification_template.md presente\n";
}
$stepTimes[5] = microtime(true) - $t0;

// ─────── STEP 6 — Test executeOverdue self-service ───────
if (!$quick) {
    $t0 = microtime(true);
    echo "▶ Step 6 — Self-service oblio Art. 17 (DeletionRequestService)\n";
    $report .= "\n## Step 6 — Self-service oblio Art. 17\n";
    try {
        // Verifica solo che il service sia callable, no execute reale
        $ds = new \App\Services\Gdpr\DeletionRequestService();
        $report .= "- ✅ DeletionRequestService instantiable\n";
        echo "    ✓ DeletionRequestService instanziabile\n";

        // Conta richieste pending
        $pending = (int)Database::connection()->query(
            "SELECT COUNT(*) FROM deletion_requests WHERE status IN ('pending_confirm', 'cooling_off')"
        )->fetchColumn();
        $report .= "- Pending deletion requests: $pending\n";
        echo "    Pending deletion requests: $pending\n";

        // Verifica executeOverdue NON-destructive (no execute, solo count)
        $overdue = (int)Database::connection()->query(
            "SELECT COUNT(*) FROM deletion_requests WHERE status='cooling_off' AND execute_after <= NOW()"
        )->fetchColumn();
        if ($overdue > 0) {
            $findings[] = "$overdue deletion requests overdue (cron non eseguito)";
            $actionItems[] = "Verifica il timer pantedu-gdpr-deletions: php tools/gdpr/execute_deletions.php --apply";
            $report .= "- ⚠ $overdue requests overdue → cron mancante o stallato\n";
        }
    } catch (\Throwable $e) {
        $findings[] = "DeletionRequestService non funzionante: " . $e->getMessage();
        $report .= "- ❌ FAIL: " . $e->getMessage() . "\n";
    }
    $stepTimes[6] = microtime(true) - $t0;
}

// ─────── Conclusione ───────
$elapsed = microtime(true) - $startTime;
echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  RISULTATO\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Durata totale:  " . round($elapsed, 1) . "s\n";
echo "  Findings:       " . count($findings) . "\n";
echo "  Action items:   " . count($actionItems) . "\n";

$report .= "\n## Conclusione\n\n";
$report .= "**Durata drill:** " . round($elapsed, 1) . "s\n";
$report .= "**Findings:** " . count($findings) . "\n";
$report .= "**Action items:** " . count($actionItems) . "\n";

if ($findings) {
    echo "\n  Findings:\n";
    foreach ($findings as $f) echo "    - $f\n";
    $report .= "\n### Findings\n";
    foreach ($findings as $f) $report .= "- $f\n";
}

if ($actionItems) {
    echo "\n  Action items:\n";
    foreach ($actionItems as $ai) echo "    □ $ai\n";
    $report .= "\n### Action items\n";
    foreach ($actionItems as $ai) $report .= "- [ ] $ai\n";
}

if (empty($findings) && empty($actionItems)) {
    echo "\n  ✅ Nessun finding. Sistema breach-ready.\n";
    $report .= "\n✅ **Sistema breach-ready.**\n";
}

$report .= "\n**Fine drill:** " . date('Y-m-d H:i:s T') . "\n";
file_put_contents($reportFile, $report);

echo "\n  Report salvato: $reportFile\n";
exit(count($findings) > 0 ? 1 : 0);
