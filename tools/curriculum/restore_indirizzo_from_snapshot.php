<?php

declare(strict_types=1);

/**
 * Rimette l'indirizzo ai contenuti che l'hanno perso, leggendolo da un backup.
 *
 * COSA E' SUCCESSO
 *   Le FK di teacher_content_data verso curriculum_entries sono ON DELETE SET
 *   NULL. Cancellare una voce di curriculum non fallisce e non avvisa: azzera
 *   la categoria di tutti i contenuti che ci puntavano. Il 2026-09-02 sono
 *   state cancellate due righe clonate sull'istituto 108 — indirizzi SCI (id
 *   8381) e ART (id 8379), di proprieta' del docente 77 — e 184 contenuti sono
 *   rimasti senza indirizzo. Nessun errore, nessuna traccia: il danno si e'
 *   visto solo quando il docente e' andato a cercare le sue mappe.
 *
 * PERCHE' SI PUO' RIPRISTINARE
 *   L'informazione non e' andata persa davvero: e' nei backup. Il contenuto ha
 *   conservato classe e materia, e il backup dice quale indirizzo aveva. Serve
 *   solo rimettere il collegamento.
 *
 * COME RISOLVE IL BERSAGLIO
 *   NON rimette il vecchio id: quella riga e' stata cancellata, e comunque
 *   stava sull'istituto sbagliato. Prende il CODICE che il contenuto aveva
 *   (SCI, ART) e cerca la riga equivalente sull'istituto indicato, di proprieta'
 *   dello stesso docente — clonandola dalla voce di istituto se non c'e'
 *   ancora, che e' la stessa operazione che fa l'app quando un docente sceglie
 *   una materia dal catalogo.
 *
 * LA MAPPA E' UN FILE, NON UN DATABASE
 *   Prima leggeva direttamente il database di appoggio col backup dentro. Ma
 *   l'utente applicativo non lo vede — e dargli il permesso sarebbe stato un
 *   cambio di privilegi in produzione per un'operazione di mezz'ora. Quindi la
 *   mappa si estrae a parte e si passa come file CSV: due colonne,
 *   id_contenuto,codice.
 *
 *   Il vantaggio non e' solo tecnico. Un file lo si apre, lo si conta, lo si
 *   confronta con l'anteprima. Prima di riscrivere 184 righe e' esattamente
 *   cio' che si vuole poter fare.
 *
 * PREPARAZIONE
 *   Ricostruire il backup in un database di appoggio, poi estrarre la mappa:
 *     mysql -N -e "SELECT d.id, ci.code FROM snap.teacher_content_data d
 *                    JOIN snap.curriculum_entries ci ON ci.id = d.indirizzo_id
 *                   WHERE d.teacher_id = 77" | tr '\t' ',' > mappa.csv
 *
 * USO
 *   php tools/curriculum/restore_indirizzo_from_snapshot.php \
 *       --map=mappa.csv --teacher=77 --institute=106 [--apply]
 *
 *   Senza --apply non tocca nulla.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Support\CurriculumLookup;

$base = dirname(__DIR__, 2);
foreach (['.env', '.env.local'] as $envFile) {
    if (is_file($base . '/' . $envFile)) {
        Dotenv\Dotenv::createMutable($base, $envFile)->safeLoad();
    }
}
Config::load($base . '/app/Config');

$opts = getopt('', ['map:', 'teacher:', 'institute:', 'apply', 'help']);
if (isset($opts['help']) || !isset($opts['map'], $opts['teacher'], $opts['institute'])) {
    fwrite(STDERR, "Uso: php tools/curriculum/restore_indirizzo_from_snapshot.php \\\n");
    fwrite(STDERR, "       --map=<file.csv> --teacher=<id> --institute=<id> [--apply]\n");
    fwrite(STDERR, "  Il CSV ha due colonne: id_contenuto,codice_indirizzo\n");
    exit(isset($opts['help']) ? 0 : 1);
}
$mapFile = (string)$opts['map'];
$teacher = (int)$opts['teacher'];
$inst    = (int)$opts['institute'];
$apply   = isset($opts['apply']);
if ($teacher <= 0 || $inst <= 0) {
    fwrite(STDERR, "ABORT: --teacher e --institute devono essere id positivi\n");
    exit(1);
}
if (!is_readable($mapFile)) {
    fwrite(STDERR, "ABORT: mappa non leggibile: $mapFile\n");
    exit(1);
}

$pdo = Database::connection();

// La mappa dice cosa aveva ciascun contenuto. Si applica SOLO a quelli che
// oggi l'hanno perso davvero: chi e' a posto non si tocca, e rilanciare lo
// strumento non fa danni.
$voluti = [];
$fh = fopen($mapFile, 'rb');
$righeMappa = 0;
while (($r = fgetcsv($fh, 0, ',')) !== false) {
    if (count($r) < 2) {
        continue;
    }
    $id = (int)trim((string)$r[0]);
    $code = strtoupper(trim((string)$r[1]));
    if ($id > 0 && preg_match('/^[A-Z]{3,6}$/', $code)) {
        $voluti[$id] = $code;
        $righeMappa++;
    }
}
fclose($fh);
if ($voluti === []) {
    fwrite(STDERR, "ABORT: la mappa non contiene righe valide (attese: id,CODICE)\n");
    exit(1);
}

$place = implode(',', array_fill(0, count($voluti), '?'));
$st = $pdo->prepare(
    "SELECT id FROM teacher_content_data
      WHERE teacher_id = ? AND indirizzo_id IS NULL AND id IN ($place)"
);
$st->execute(array_merge([$teacher], array_keys($voluti)));
$persi = [];
foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $persi[] = ['id' => (int)$id, 'code' => $voluti[(int)$id]];
}

printf("Mappa: %d righe lette da %s\n", $righeMappa, basename($mapFile));
if ($persi === []) {
    echo "Nessun contenuto da ripristinare: quelli in mappa hanno gia' un indirizzo.\n";
    exit(0);
}
printf("Di questi, %d hanno oggi l'indirizzo vuoto e verrebbero ricategorizzati.\n\n", count($persi));

$perCodice = [];
foreach ($persi as $r) {
    $perCodice[(string)$r['code']][] = (int)$r['id'];
}

foreach ($perCodice as $code => $ids) {
    printf("  %-6s %4d contenuti → istituto %d\n", $code, count($ids), $inst);
}
echo "\n";

// Bersagli: la riga del docente sull'istituto giusto, clonata dall'anchor se serve.
$bersagli = [];
$mancanti = [];
foreach (array_keys($perCodice) as $code) {
    $st = $pdo->prepare(
        'SELECT id FROM curriculum_entries
          WHERE kind = "indirizzi" AND institute_id = ? AND code = ? AND owner_user_id = ? LIMIT 1'
    );
    $st->execute([$inst, $code, $teacher]);
    $id = $st->fetchColumn();
    if ($id !== false) {
        $bersagli[$code] = (int)$id;
        continue;
    }
    // Non c'e' la riga del docente: c'e' almeno la voce di istituto?
    $st = $pdo->prepare(
        'SELECT id FROM curriculum_entries
          WHERE kind = "indirizzi" AND institute_id = ? AND code = ? AND owner_user_id IS NULL LIMIT 1'
    );
    $st->execute([$inst, $code]);
    if ($st->fetchColumn() === false) {
        $mancanti[] = $code;
    } else {
        $bersagli[$code] = 0; // da clonare in fase di --apply
    }
}

if ($mancanti !== []) {
    fwrite(STDERR, 'ABORT: sull\'istituto ' . $inst . " non esistono gli indirizzi: " . implode(', ', $mancanti) . "\n");
    fwrite(STDERR, "       Importa il curriculum di quella scuola prima di ripristinare.\n");
    exit(1);
}

foreach ($bersagli as $code => $id) {
    printf(
        "  %-6s → %s\n",
        $code,
        $id > 0 ? "riga esistente id $id" : 'da attivare per il docente (clone della voce di istituto)'
    );
}
echo "\n";

if (!$apply) {
    printf("ANTEPRIMA (nessuna modifica) — verrebbero ricategorizzati %d contenuti.\n", count($persi));
    echo "Per scrivere: rilancia con --apply\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    foreach ($bersagli as $code => $id) {
        if ($id === 0) {
            $nuovo = CurriculumLookup::ensureEntryForTeacher('indirizzi', $teacher, (string)$code, $inst);
            if ($nuovo === null) {
                throw new RuntimeException("attivazione fallita per l'indirizzo $code");
            }
            $bersagli[$code] = $nuovo;
        }
        // Una riga spenta non comparirebbe nelle tendine: accesa, visto che
        // sta per ricevere dei contenuti.
        $pdo->prepare('UPDATE curriculum_entries SET active = 1 WHERE id = ?')->execute([$bersagli[$code]]);
    }

    $fatti = 0;
    $upd = $pdo->prepare(
        'UPDATE teacher_content_data SET indirizzo_id = ? WHERE id = ? AND indirizzo_id IS NULL'
    );
    foreach ($persi as $r) {
        $upd->execute([$bersagli[(string)$r['code']], (int)$r['id']]);
        $fatti += $upd->rowCount();
    }
    $pdo->commit();
    printf("FATTO — %d contenuti hanno di nuovo il loro indirizzo.\n", $fatti);
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'ABORT in scrittura (rollback eseguito): ' . $e->getMessage() . "\n");
    exit(1);
}
