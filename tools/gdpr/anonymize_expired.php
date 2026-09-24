<?php
/**
 * anonymize_expired.php — Phase 14 GDPR retention job.
 *
 * Applica le retention configurate in app/Config/retention.php:
 *   - account inattivi oltre N giorni → cancellati con la routine dell'art. 17
 *     (App\Services\Gdpr\CancellazioneDellAccount, dal 24/9/2026)
 *   - domande di iscrizione in attesa oltre N giorni → cancellate dal file
 *     registrations.json, con IP e User-Agent e storico delle altre; la copia
 *     degli utenti in users.json → cancellata (ConservazioneDelleIscrizioni)
 *   - privileged_access_log oltre N giorni → purge
 *
 * Uso:
 *   php tools/gdpr/anonymize_expired.php --dry-run    # prova a secco, SEMPRE
 *   GDPR_RETENTION_ENABLED=1 php tools/gdpr/anonymize_expired.php
 *   php tools/gdpr/anonymize_expired.php --apply      # come sopra, a mano
 *
 * Senza flag applica se GDPR_RETENTION_ENABLED è accesa, e in produzione lo è
 * anche in `.env.local`: per provare si usa `--dry-run`, che vince su tutto
 * (App\Services\Gdpr\ModoDelGiroDiConservazione, 24/9/2026).
 *
 * `--apply` c'è dal 24/9/2026: la procedura di ripristino
 * (docs/security/operations/restore-reerasure.md, passo 3) lo scriveva già,
 * e lo script lo ignorava, cioè girava in prova e non cancellava niente.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\PrivilegedAccessLogger;
use App\Services\Gdpr\ConservazioneDelleIscrizioni;

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
$dry = \App\Services\Gdpr\ModoDelGiroDiConservazione::provaASecco(
    (bool)($cfg['retention_enabled'] ?? false),
    $argv,
);

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

// 1. Account inattivi da oltre N giorni.
//
// ── Che cosa vuol dire «inattivo», e cosa voleva dire prima ───────────────
//
// Fino al 9 settembre 2026 la condizione era questa:
//
//     AND (approved_at IS NULL OR approved_at < ?)
//     AND created_at < ?
//
// Nessun riferimento all'uso, perché il dato non esisteva: il commento sopra
// diceva «last_access_at (se esistesse)». Ma `approved_at < cutoff` non
// significa «inattivo da due anni» — significa «approvato più di due anni
// fa», che per chi entra ogni giorno è vero.
//
// Con la retention accesa, quel giro avrebbe anonimizzato l'account di chi
// stava usando il sistema: email sostituita, nome e cognome svuotati,
// password azzerata, `active = 0`. Irreversibile.
//
// Non è successo per due ragioni che non c'entrano niente con la
// correttezza — il timer non era mai partito e `GDPR_RETENTION_ENABLED` non
// era valorizzata, quindi girava in simulazione — ma il conto alla rovescia
// era vero: l'account più vecchio compiva 730 giorni a metà gennaio 2027.
//
// Adesso c'è `last_access_at` (migrazione 108), aggiornata da
// `Auth::establishSession()`, cioè dall'unico punto in cui nasce una sessione
// autenticata.
//
// ── Perché `last_access_at IS NULL` non basta a far anonimizzare ──────────
//
// La colonna è nata vuota per tutti: prima non si registrava niente. Un
// account con `NULL` non è «inattivo da sempre», è «non lo sappiamo».
// Trattare l'ignoto come inattività vorrebbe dire anonimizzare tutti al primo
// giro, cioè ripetere lo stesso difetto con un'altra colonna.
//
// Perciò `NULL` conta come inattività **solo** se anche l'iscrizione è più
// vecchia del limite: un account creato tre anni fa che non ha mai lasciato
// traccia di un accesso è inattivo davvero. Uno creato il mese scorso e non
// ancora usato aspetta il suo turno.
$inactive = (int)($cfg['inactive_account_days'] ?? 730);
// La condizione sta in `App\Support\RetentionSql` e **non** qui, perché il
// test in `tests/Unit/Support/RetentionInattiviTest.php` deve provare la
// stessa stringa che gira in produzione: una copia dentro il test si separa
// dal codice al primo ritocco, e da lì continua a passare difendendo una
// query che non esiste più.
//
// ── Che cosa succede a un account inattivo (24/9/2026) ────────────────────
//
// Fino a oggi qui c'era un solo UPDATE su `users`: email sostituita, nome,
// cognome e password svuotati, `active = 0`, `status = 'anonymized'`. Niente
// distruzione della chiave (l'informativa la prometteva), e restavano il nome
// utente (nome.cognome), scuola, indirizzo e classe, i contenuti e i loro
// file. Adesso ogni account passa dalla stessa routine dell'art. 17,
// `CancellazioneDellAccount`, con la connessione dell'applicazione e non con
// quella di manutenzione: la routine cancella righe di contenuti e scrive nel
// verbale dei Termini, e l'utente di manutenzione ha solo i permessi della
// purga dei registri (tools/security/create_maintenance_db_user.sh).
$inattivi = (new \App\Services\Gdpr\AnonimizzazioneDegliInattivi(Database::connection()))
    ->esegui($now, $inactive, $dry);
$report[] = sprintf(
    '[%s] users inattivi > %d gg → cancellati come per l\'art. 17 — %s %d%s',
    $dry ? 'DRY' : 'APPLY',
    $inactive,
    $dry ? 'match' : 'eseguiti',
    $dry ? count($inattivi['trovati']) : $inattivi['cancellati'],
    $dry ? ' row(e)' : ' su ' . count($inattivi['trovati']),
);
foreach ($inattivi['errori'] as $errore) {
    $report[] = '[ERRORE] ' . $errore;
}
// Gli avvisi prima della cancellazione (24/9/2026): 60, 30 e 7 giorni prima.
$report[] = sprintf(
    '[%s] avvisi di inattività %s: %d',
    $dry ? 'DRY' : 'APPLY',
    $dry ? 'da mandare' : 'mandati',
    count($inattivi['avvisi']) - count($inattivi['avvisi_non_partiti']),
);
foreach ($inattivi['avvisi_non_partiti'] as $problema) {
    // Un'anomalia, non un guasto del giro: l'account resta.
    $report[] = '[AVVISO NON PARTITO] ' . $problema;
}

// 2. Domande di iscrizione in attesa.
//
// Fino al 24/9/2026 qui c'era `DELETE FROM registrations WHERE status =
// 'pending' AND requested_at < ?`: una tabella che nessun codice scrive
// (misurato con git grep: solo database/schema.sql e un conteggio del
// monitor). Il giro rispondeva «0 righe» ogni volta, e le domande vere — nel
// file registrations.json, con IP e User-Agent in chiaro — restavano per
// sempre, con lo storico delle decisioni e la copia degli account approvati in
// users.json. La regola adesso sta in ConservazioneDelleIscrizioni, che la
// applica anche a ogni scrittura del file.
//
// Il percorso si stampa: sull'host i dati stanno dove dice PANTEDU_DATA_PATH,
// e un giro che guarda la cartella sbagliata direbbe «niente da fare» per
// sempre. Un file che non si legge è un problema, e il giro esce con 1
// (l'unità manda l'avviso); gli altri passi girano lo stesso. Le domande
// illeggibili restano dove sono; la vecchia copia degli utenti illeggibile si
// segnala e si cancella lo stesso, così l'avviso arriva una notte sola.
$pending = (int)($cfg['pending_registration_days'] ?? ConservazioneDelleIscrizioni::GIORNI_IN_ATTESA);
$errori = [];
try {
    $percorsoIscrizioni = (string)Config::get('auth.paths.registrations', '');
    $percorsoCopia      = (string)Config::get('auth.paths.registered_users', '');
    $esito = (new ConservazioneDelleIscrizioni($percorsoIscrizioni, $percorsoCopia, $pending))
        ->applica($now, $dry);
    $tag = $dry ? '[DRY]' : '[APPLY]';
    if ($esito['iscrizioni_presenti'] && !$esito['iscrizioni_lette']) {
        // Niente conti: «0 scadute» di un file che non si è letto sarebbe
        // falso. Il perché va negli errori, qui sotto.
        $report[] = "$tag domande di iscrizione ($percorsoIscrizioni): il file non si legge, non l'ho toccato";
    } elseif ($esito['iscrizioni_presenti']) {
        $report[] = sprintf(
            '%s domande di iscrizione (%s): in attesa > %d gg → %s %d, senza data %d; '
            . 'IP e User-Agent tolti da %d; voci di storico %d; ne restano %d',
            $tag,
            $percorsoIscrizioni,
            $pending,
            $dry ? 'da cancellare' : 'cancellate',
            $esito['scadute'],
            $esito['senza_data'],
            $esito['ip_tolti'],
            $esito['storico'],
            $esito['in_attesa'],
        );
    } else {
        $report[] = "$tag domande di iscrizione ($percorsoIscrizioni): il file non c'è, niente da fare";
    }
    if ($esito['copia_presente']) {
        $report[] = sprintf(
            '%s copia degli utenti (%s): %d voci → %s',
            $tag,
            $percorsoCopia,
            $esito['copia_voci'],
            $dry ? 'file da cancellare' : 'file cancellato',
        );
    }
    if ($esito['temporanei'] > 0) {
        $report[] = sprintf(
            '%s temporanei di scritture interrotte accanto alle domande: %d → %s',
            $tag,
            $esito['temporanei'],
            $dry ? 'da cancellare' : 'cancellati',
        );
    }
    foreach ($esito['problemi'] as $problema) {
        $errori[] = 'domande di iscrizione: ' . $problema;
    }
} catch (Throwable $e) {
    $errori[] = 'domande di iscrizione: ' . $e->getMessage();
}

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
foreach ($errori as $errore) {
    fwrite(STDERR, '[anonymize_expired] ERRORE: ' . $errore . PHP_EOL);
}

// Tracciamento accesso al tool
try {
    PrivilegedAccessLogger::log('retention_run', 'gdpr_job', null,
        $dry ? 'dry_run' : 'apply', ($errori === [] && $inattivi['errori'] === []) ? 'ok' : 'error');
} catch (Throwable) { /* best-effort */ }

$falliti = $errori !== [] || $inattivi['errori'] !== [];
echo ($dry ? '[DRY-RUN]' : '[APPLIED]') . ($falliti ? ' completato con errori.' : ' completato.') . "\n";

// Un account che non si è potuto cancellare, o domande che non si sono potute
// pulire, non sono un giro riuscito: il timer deve segnalarlo (OnFailure), e
// il giro successivo li riprende.
if ($inattivi['errori'] !== []) {
    fwrite(STDERR, '[anonymize_expired] ' . count($inattivi['errori']) . " account non cancellati: vedi le righe [ERRORE].\n");
}
exit($falliti ? 1 : 0);
