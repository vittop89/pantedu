<?php

declare(strict_types=1);

/**
 * Rende le tabelle di audit append-only a livello di database.
 *
 * PERCHE' NON BASTA UN REVOKE (2026-09-02)
 *
 * Informativa, registro art. 30 e DPIA dichiaravano da tempo l'immutabilita'
 * dei log di audit, indicando come misura `REVOKE UPDATE, DELETE` sull'utente
 * applicativo. Quel REVOKE non e' mai stato eseguito, e non poteva esserlo
 * cosi' com'era scritto: `pantedu_app` ha una concessione a livello di
 * DATABASE, e MySQL/MariaDB non permette di sottrarre un permesso su singole
 * tabelle da una concessione piu' ampia — non esiste un DENY. Revocare a
 * livello di database avrebbe tolto UPDATE e DELETE su tutte le 81 tabelle,
 * rompendo fra l'altro la cancellazione GDPR; rifarli tabella per tabella
 * avrebbe significato che ogni tabella nuova nasce senza permessi, con
 * rotture silenziose a ogni migration.
 *
 * La misura corretta e' un trigger BEFORE UPDATE / BEFORE DELETE che rifiuta
 * l'operazione. Vale per QUALSIASI utente, non dipende dalle concessioni, e
 * non va rivista quando si aggiungono tabelle.
 *
 * COSA RESTA POSSIBILE
 *
 * La purga dei log scaduti e' un obbligo (art. 5(1)(e)): i due job di
 * manutenzione devono poter cancellare. Il trigger sulle due tabelle purgabili
 * lascia passare il solo utente di manutenzione, riconosciuto da `USER()` —
 * che dentro un trigger restituisce chi ha aperto la connessione, non il
 * definer. `consent_audit` e `crypto_custody_events` non si purgano mai
 * (l'informativa dice "Permanente"): li' il rifiuto e' incondizionato.
 *
 * Idempotente: si puo' rilanciare. Con --status mostra soltanto lo stato.
 *
 * Uso:
 *   php tools/security/apply_audit_append_only.php --status
 *   php tools/security/apply_audit_append_only.php --apply
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$apply  = in_array('--apply', $argv, true);
$status = in_array('--status', $argv, true) || !$apply;

/** Tabelle protette. `purgeable` = il job di manutenzione puo' cancellare. */
$tables = [
    'privileged_access_log'  => ['purgeable' => true],
    'crypto_access_log'      => ['purgeable' => true],
    'consent_audit'          => ['purgeable' => false],
    'crypto_custody_events'  => ['purgeable' => false],
    // 2026-09-02: mancavano. `content_action_log` era l'unica tabella di
    // audit senza protezione, ed e' quella che racconta cosa ha fatto un
    // docente sui propri contenuti; `audit_activity_log` (migration 098) e'
    // il registro delle operazioni di tutti i ruoli.
    'content_action_log'     => ['purgeable' => true],
    'audit_activity_log'     => ['purgeable' => true],
    // Uso delle chiavi di recupero: chi si porta via una chiave non deve
    // poter cancellare la riga che lo dice.
    'teacher_recovery_audit' => ['purgeable' => true],
];

$maintUser = (string)($_ENV['DB_MAINT_USER'] ?? '');

// I trigger si creano con la connessione delle MIGRATION, non con quella del
// sito. MariaDB verifica il privilegio TRIGGER del *definer* — chi ha creato
// l'oggetto — a OGNI scatto, non solo alla creazione. Creandoli con
// `pantedu_app`, il giorno in cui a quell'utente si toglie il privilegio (ed
// e' esattamente cio' che va fatto, perche' i trigger non proteggono se
// stessi) tutti i trigger diventano inservibili e bloccano OGNI update e
// delete sulle tabelle protette, purga di manutenzione compresa. Successo
// davvero il 2026-09-02: la retention si e' fermata.
//
// Il definer deve quindi essere un'utenza che conserva TRIGGER a lungo, e per
// definizione e' quella delle migration.
$pdo = Database::migrationConnection();
$db  = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

echo "Database: $db\n";
echo 'Definer dei trigger: ' . $pdo->query('SELECT CURRENT_USER()')->fetchColumn() . PHP_EOL;
echo 'Utente di manutenzione: ' . ($maintUser !== '' ? $maintUser : '(NON CONFIGURATO)') . "\n\n";

if ($apply && $maintUser === '') {
    fwrite(STDERR, "[ERRORE] DB_MAINT_USER non configurato.\n"
        . "Senza, i trigger bloccherebbero anche la purga dei log, che e' obbligatoria.\n"
        . "Configura prima l'utente di manutenzione in .env.local.\n");
    exit(1);
}

$existing = [];
foreach ($pdo->query(
    "SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION
       FROM information_schema.TRIGGERS
      WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg_append_only_%'"
) as $r) {
    $existing[$r['EVENT_OBJECT_TABLE'] . ':' . $r['EVENT_MANIPULATION']] = $r['TRIGGER_NAME'];
}

$changed = 0;
foreach ($tables as $table => $cfg) {
    // Tabella assente (installazione parziale): si salta senza fallire.
    $n = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table)
    )->fetchColumn();
    if ($n === 0) {
        printf("  %-24s tabella assente, salto\n", $table);
        continue;
    }

    foreach (['UPDATE', 'DELETE'] as $event) {
        $name = 'trg_append_only_' . strtolower($table) . '_' . strtolower($event);
        $have = isset($existing[$table . ':' . $event]);

        if ($status && !$apply) {
            printf("  %-24s %-6s %s\n", $table, $event, $have ? 'protetto' : 'NON protetto');
            continue;
        }

        $pdo->exec("DROP TRIGGER IF EXISTS `$name`");

        if ($event === 'DELETE' && $cfg['purgeable']) {
            // Solo il job di manutenzione puo' purgare. USER() dentro un
            // trigger e' l'utente della connessione, non il definer.
            $like = $pdo->quote($maintUser . '@%');
            $sql = "CREATE TRIGGER `$name` BEFORE DELETE ON `$table`
                    FOR EACH ROW
                    BEGIN
                        IF USER() NOT LIKE $like THEN
                            SIGNAL SQLSTATE '45000'
                              SET MESSAGE_TEXT = 'append-only: solo il job di manutenzione puo purgare questa tabella';
                        END IF;
                    END";
        } else {
            $msg = $pdo->quote("append-only: $table non ammette " . ($event === 'UPDATE' ? 'modifiche' : 'cancellazioni'));
            $sql = "CREATE TRIGGER `$name` BEFORE $event ON `$table`
                    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = $msg";
        }

        $pdo->exec($sql);
        printf("  %-24s %-6s %s\n", $table, $event,
            $event === 'DELETE' && $cfg['purgeable'] ? 'protetto (purga consentita al manutentore)' : 'protetto');
        $changed++;
    }
}

echo "\n";
if ($apply) {
    echo "Applicati/aggiornati $changed trigger.\n";
    echo "Verifica: php tools/security/apply_audit_append_only.php --status\n";
} else {
    echo "Sola lettura. Per applicare: --apply\n";
}
