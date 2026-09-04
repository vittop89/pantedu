<?php

declare(strict_types=1);

/**
 * Impedisce di cancellare una voce di curriculum ancora usata dai contenuti.
 *
 * IL DANNO CHE PREVIENE
 *   Le FK verso curriculum_entries sono ON DELETE SET NULL: cancellare una
 *   voce non fallisce e non avvisa, azzera la categoria dei contenuti che ci
 *   puntavano. Il 2026-09-02 la cancellazione di due righe clonate
 *   sull'istituto 108 ha scategorizzato 184 mappe ed esercizi di un docente.
 *   Si e' visto solo giorni dopo, quando lui e' andato a cercarle.
 *
 * PERCHE' NON SI CAMBIA LA FK IN RESTRICT
 *   Sembra la cura ovvia e non lo e'. Provato sui tre percorsi:
 *
 *     cancellare la voce in uso   → bloccata   VOLUTO
 *     cancellare l'UTENTE         → bloccata   NO: e' l'Art. 17 GDPR, promesso
 *                                   al DPO come "cancellazione completa"
 *     cancellare l'ISTITUTO       → bloccata   NO: serve al merge dei duplicati
 *
 *   RESTRICT scatta durante il cascade, prima che i contenuti figli vengano
 *   rimossi. Metterlo romperebbe due garanzie per chiuderne una.
 *
 * PERCHE' UN TRIGGER
 *   In MySQL/MariaDB i trigger NON vengono attivati dalle azioni di foreign
 *   key. Qui quella stranezza e' esattamente cio' che serve: il trigger scatta
 *   sulla DELETE diretta — quella di un umano, di un pannello, di uno script —
 *   e tace quando la cancellazione arriva come cascade da users o institutes.
 *   Verificato sugli stessi tre percorsi:
 *
 *     cancellare la voce in uso   → bloccata
 *     cancellare l'UTENTE         → riuscita
 *     cancellare l'ISTITUTO       → riuscita
 *
 * PERCHE' UNO STRUMENTO E NON UNA MIGRATION
 *   Il Migrator spezza i file .sql sui `;` e non conosce DELIMITER: un corpo
 *   di trigger multi-statement gli si sbriciola fra le mani. E' gia' successo
 *   — la migration 038 risulta eseguita ma i suoi due trigger non esistono in
 *   nessun database. I trigger append-only sui log, che invece esistono, sono
 *   creati da uno strumento PHP come questo. Si segue quello che funziona.
 *
 * USO
 *   php tools/curriculum/apply_no_orphan_guard.php            (sola lettura)
 *   php tools/curriculum/apply_no_orphan_guard.php --apply
 *   php tools/curriculum/apply_no_orphan_guard.php --remove --apply
 *
 * Idempotente: rilanciarlo ricrea lo stesso trigger.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

$base = dirname(__DIR__, 2);
foreach (['.env', '.env.local'] as $envFile) {
    if (is_file($base . '/' . $envFile)) {
        Dotenv\Dotenv::createMutable($base, $envFile)->safeLoad();
    }
}
Config::load($base . '/app/Config');

$apply  = in_array('--apply', $argv, true);
$remove = in_array('--remove', $argv, true);
$nome   = 'trg_curriculum_no_orphan';

// Le tabelle che categorizzano contenuti tramite curriculum_entries, con le
// colonne da controllare. Non sono tutte quelle con una FK: qui stanno quelle
// il cui contenuto e' lavoro di qualcuno e la cui perdita si nota.
$sorveglia = [
    'teacher_content_data'     => ['indirizzo_id', 'classe_id', 'subject_id'],
    'exercises_data'           => ['indirizzo_id', 'classe_id', 'materia_id'],
    'verifica_documents_data'  => ['indirizzo_id', 'classe_id', 'materia_id'],
    'risdoc_compilations_data' => ['indirizzo_id', 'classe_id'],
];

// Serve la connessione con i privilegi DDL: l'utente del sito non ha TRIGGER,
// per scelta (non deve poter togliere le protezioni append-only sui log).
$pdo = Database::migrationConnection();

$esistenti = $pdo->query(
    "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
      WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '$nome'"
)->fetchAll(PDO::FETCH_COLUMN);
printf("Trigger %s: %s\n\n", $nome, $esistenti ? 'presente' : 'assente');

if ($remove) {
    if (!$apply) {
        echo "Con --apply verrebbe rimosso.\n";
        exit(0);
    }
    $pdo->exec("DROP TRIGGER IF EXISTS `$nome`");
    echo "Rimosso.\n";
    exit(0);
}

// Corpo: una catena di controlli che si ferma al primo uso trovato. Scritto
// tutto in una stringa e passato a exec() in un colpo solo — e' il modo in cui
// un trigger multi-statement arriva al database senza DELIMITER.
$controlli = '';
foreach ($sorveglia as $tabella => $colonne) {
    $where = implode(' OR ', array_map(
        static fn(string $c): string => "`$c` = OLD.id",
        $colonne
    ));
    $controlli .= "    IF usi = 0 THEN SELECT COUNT(*) INTO usi FROM `$tabella` WHERE $where; END IF;\n";
}

$messaggio = 'voce di curriculum ancora usata: sposta i riferimenti prima di cancellarla '
    . '(tools/institutes/prune_cloned_curriculum.php)';

$sql = "CREATE TRIGGER `$nome` BEFORE DELETE ON `curriculum_entries`
FOR EACH ROW
BEGIN
    DECLARE usi INT DEFAULT 0;
$controlli    IF usi > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = " . $pdo->quote($messaggio) . ";
    END IF;
END";

echo "Tabelle sorvegliate:\n";
foreach ($sorveglia as $tabella => $colonne) {
    printf("  %-26s %s\n", $tabella, implode(', ', $colonne));
}
echo "\n";
echo "Non blocca le cancellazioni a cascata da users e institutes: i trigger\n";
echo "non vengono attivati dalle azioni di foreign key, quindi la cancellazione\n";
echo "dell'account (Art. 17) e il merge degli istituti continuano a funzionare.\n\n";

if (!$apply) {
    echo "Sola lettura. Per applicare: --apply\n";
    exit(0);
}

$pdo->exec("DROP TRIGGER IF EXISTS `$nome`");
$pdo->exec($sql);

// Verifica dal database, non dal fatto che exec() non abbia protestato: e'
// esattamente l'errore per cui la migration 038 risulta eseguita a vuoto.
$ok = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TRIGGERS
      WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '$nome'"
)->fetchColumn();
if ((int)$ok !== 1) {
    fwrite(STDERR, "ABORT: il trigger non risulta creato.\n");
    exit(1);
}
echo "Applicato e verificato.\n";
