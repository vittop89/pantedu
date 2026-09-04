<?php
/**
 * anonymize_expired.php — Phase 14 GDPR retention job (dry-run di default).
 *
 * Applica le retention configurate in app/Config/retention.php:
 *   - account inattivi oltre N giorni → anonimizza (email, name, hash azzerati)
 *   - registrazioni pending oltre N giorni → rimosse
 *   - privileged_access_log oltre N giorni → purge
 *
 * Uso:
 *   php tools/gdpr/anonymize_expired.php              # dry-run
 *   GDPR_RETENTION_ENABLED=1 php tools/gdpr/anonymize_expired.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\PrivilegedAccessLogger;

// 2026-09-02 — in produzione `display_errors` e' 0: un'eccezione non gestita
// usciva con codice 255 e NESSUN messaggio, ne' su stdout ne' su stderr. E'
// il motivo per cui il difetto nella riscrittura del COUNT e' rimasto
// invisibile per mesi. Ora che il job e' pianificato in un timer un
// fallimento muto sarebbe peggio: un'esecuzione fallita ogni mese, e nessuno
// che se ne accorge.
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, '[anonymize_expired] ERRORE: ' . $e->getMessage()
        . ' (' . $e->getFile() . ':' . $e->getLine() . ")
");
    exit(1);
});

if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile.\n");
    exit(1);
}
$cfg = (array)Config::get('retention');
$dry = !(bool)($cfg['retention_enabled'] ?? false);

// Connessione di manutenzione: e' l'unica che conserva il permesso di
// cancellare dalle tabelle di audit. L'utente del sito non ce l'ha piu'
// (vedi Database::maintenanceConnection). Senza DB_MAINT_USER ricade
// sulla connessione ordinaria e nulla cambia.
$pdo = Database::maintenanceConnection();
$now = new DateTimeImmutable('now');

$report = [];
$apply = function (string $sql, array $args, string $label) use ($pdo, $dry, &$report) {
    if ($dry) {
        // Conta soltanto, riscrivendo la query in un COUNT.
        //
        // 2026-09-02 — la riscrittura precedente era un'unica regex per DELETE
        // e UPDATE, con 'SELECT COUNT(*) FROM $2' dove $2 conteneva gia' il
        // FROM: su ogni DELETE usciva "SELECT COUNT(*) FROM FROM tabella", e
        // PDO sollevava un errore di sintassi non gestito. Siccome il dry-run
        // e' il comportamento predefinito, il job moriva al primo DELETE — cioe'
        // non ha mai portato a termine una sola esecuzione. Il difetto e' rimasto
        // invisibile perche' lo script non era pianificato da nessuna parte.
        //
        // Due forme distinte, che e' quello che erano fin dall'inizio.
        $countSql = null;
        if (preg_match('/^\s*DELETE\s+FROM\s+(.+?)\s+WHERE\s+(.+)$/is', $sql, $m)) {
            $countSql = 'SELECT COUNT(*) FROM ' . $m[1] . ' WHERE ' . $m[2];
        } elseif (preg_match('/^\s*UPDATE\s+(.+?)\s+SET\s+.+?\s+WHERE\s+(.+)$/is', $sql, $m)) {
            $countSql = 'SELECT COUNT(*) FROM ' . $m[1] . ' WHERE ' . $m[2];
        }
        if ($countSql === null) {
            $report[] = "[DRY] $label — skip (count parse failed)";
            return;
        }
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($args);
        $n = (int)$stmt->fetchColumn();
        $report[] = "[DRY] $label — match $n row(e)";
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $report[] = "[APPLY] $label — affected " . $stmt->rowCount();
    }
};

// 1. Account inattivi: nessun approved_at o last_access_at (se esistesse) oltre N giorni
$inactive = (int)($cfg['inactive_account_days'] ?? 730);
$cutoff = $now->sub(new DateInterval('P' . $inactive . 'D'))->format('Y-m-d H:i:s');
$apply(
    "UPDATE users
       SET email = CONCAT('anon-', id, '@invalid.local'),
           first_name = '',
           last_name  = '',
           password_hash = '',
           active = 0,
           status = 'anonymized'
     WHERE status <> 'anonymized'
       AND (approved_at IS NULL OR approved_at < ?)
       AND created_at < ?",
    [$cutoff, $cutoff],
    "users inattivi > $inactive gg → anonimizzati",
);

// 2. Registrazioni pending scadute
$pending = (int)($cfg['pending_registration_days'] ?? 30);
$cutoffP = $now->sub(new DateInterval('P' . $pending . 'D'))->format('Y-m-d H:i:s');
$apply(
    "DELETE FROM registrations WHERE status = 'pending' AND requested_at < ?",
    [$cutoffP],
    "registrations pending > $pending gg → rimosse",
);

// 3. Purge privileged_access_log oltre retention
$palDays = (int)($cfg['privileged_log_days'] ?? 1825);
$cutoffL = $now->sub(new DateInterval('P' . $palDays . 'D'))->format('Y-m-d H:i:s');
$apply(
    "DELETE FROM privileged_access_log WHERE created_at < ?",
    [$cutoffL],
    "privileged_access_log > $palDays gg → purge",
);

foreach ($report as $line) {
    echo $line, PHP_EOL;
}

// Tracciamento accesso al tool
try {
    PrivilegedAccessLogger::log('retention_run', 'gdpr_job', null,
        $dry ? 'dry_run' : 'apply', 'ok');
} catch (Throwable) { /* best-effort */ }

echo ($dry ? '[DRY-RUN]' : '[APPLIED]') . " completato.\n";
