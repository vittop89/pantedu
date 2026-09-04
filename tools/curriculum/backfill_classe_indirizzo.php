<?php

declare(strict_types=1);

/**
 * Rimette l'indirizzo alle classi del docente che l'hanno perso in copia.
 *
 * COSA ERA ROTTO
 *   Quando un docente attiva una classe, CurriculumLookup ne clona la voce di
 *   istituto. Il clone copiava etichetta, gruppo e stato, ma NON `indirizzo` —
 *   il campo che dice a quale corso appartiene la sezione (migration 100).
 *
 *   Per le classi non e' un dettaglio: la copia diventa una sezione
 *   trasversale, che ricompare sotto ogni indirizzo. E siccome i selettori
 *   della sidebar mostrano le righe DEL DOCENTE, il filtro per corso non
 *   poteva funzionare per nessuno — scegliere "Artistico" continuava a
 *   mostrare tutte le classi della scuola.
 *
 *   La copia e' corretta da oggi. Questo rimette a posto quelle gia' fatte.
 *
 * COME
 *   Ogni riga per-docente prende l'indirizzo dalla sua voce di istituto —
 *   stesso kind, stesso codice, stessa scuola. Nient'altro: se la voce di
 *   istituto non ce l'ha, non c'e' niente da copiare e la riga resta com'e'.
 *
 * USO
 *   php tools/curriculum/backfill_classe_indirizzo.php
 *   php tools/curriculum/backfill_classe_indirizzo.php --apply
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

$apply = in_array('--apply', $argv, true);
$pdo = Database::connection();

$sql = 'SELECT c.id, c.code, c.institute_id, c.owner_user_id, a.indirizzo
          FROM curriculum_entries c
          JOIN curriculum_entries a
            ON a.kind = c.kind AND a.code = c.code
           AND a.institute_id = c.institute_id AND a.owner_user_id IS NULL
         WHERE c.kind = "classi"
           AND c.owner_user_id IS NOT NULL
           AND c.indirizzo IS NULL
           AND a.indirizzo IS NOT NULL
         ORDER BY c.institute_id, c.owner_user_id, c.code';

$righe = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if ($righe === []) {
    echo "Nessuna classe del docente da sistemare.\n";
    exit(0);
}

$perDocente = [];
foreach ($righe as $r) {
    $perDocente[$r['institute_id'] . '|' . $r['owner_user_id']][] = $r['code'] . '→' . $r['indirizzo'];
}
printf("%d classi senza indirizzo, recuperabile dalla voce di istituto:\n\n", count($righe));
foreach ($perDocente as $chiave => $codici) {
    [$inst, $doc] = explode('|', $chiave);
    printf("  istituto %-5s docente %-5s  %s\n", $inst, $doc, implode('  ', $codici));
}
echo "\n";

if (!$apply) {
    echo "ANTEPRIMA (nessuna modifica). Per scrivere: --apply\n";
    exit(0);
}

$upd = $pdo->prepare('UPDATE curriculum_entries SET indirizzo = ? WHERE id = ? AND indirizzo IS NULL');
$fatte = 0;
foreach ($righe as $r) {
    $upd->execute([$r['indirizzo'], (int)$r['id']]);
    $fatte += $upd->rowCount();
}
printf("FATTO — %d classi hanno di nuovo il loro indirizzo.\n", $fatte);
