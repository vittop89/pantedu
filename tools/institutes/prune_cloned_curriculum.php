<?php

declare(strict_types=1);

/**
 * Ripulisce le voci di curriculum clonate su un istituto sbagliato.
 *
 * DA DOVE VIENE IL PROBLEMA
 *   cleanup_curriculum.php aveva uno "Step 4.5" che, a ogni deploy, copiava le
 *   voci dell'istituto piu' ricco su tutti gli altri. Rimosso il 2026-09-02, ma
 *   le copie sono rimaste — e nel frattempo i contenuti del docente ci si sono
 *   agganciati, perche' quando l'istituto corrente era quello sbagliato i
 *   codici si risolvevano sulle righe clonate.
 *
 * NON CANCELLA CIO' CHE NON CAPISCE
 *   "Nessuno la usa" non vuol dire "e' spazzatura". Un vocabolario appena
 *   importato dal MIUR e' senza riferimenti per definizione: sull'istituto 108
 *   in produzione ci sono l'indirizzo MUS e le sezioni 1A-5A del liceo
 *   musicale, arrivate dalle adozioni, e cancellarle toglierebbe alla scuola i
 *   suoi corsi veri. In curriculum_entries non c'e' nessuna colonna che
 *   distingua una riga clonata da una importata — quindi la distinzione la fa
 *   chi legge, non lo script.
 *
 *   Si cancellano da sole soltanto le righe appena svuotate dal ri-puntamento,
 *   perche' di quelle si sa cosa erano: copie di voci che ora vivono
 *   sull'istituto giusto. Tutto il resto si elenca e si lascia stare; per
 *   toglierne qualcuna si passano gli id a mano con --delete-ids.
 *
 * PERCHE' NON BASTA CANCELLARE
 *   Quelle righe non sono orfane: sull'istituto 108 ci puntano 229 contenuti
 *   reali, tutti di materia scientifica e artistica, cioe' materiale del 106.
 *   Cancellarle romperebbe le FK o, peggio, lascerebbe i contenuti senza
 *   categoria. Prima si ri-puntano i riferimenti al gemello sull'istituto
 *   giusto; solo allora quelle righe sono davvero vuote e si possono togliere.
 *
 * IL GEMELLO E' PER DOCENTE
 *   La chiave logica di una voce e' (kind, code, owner_user_id): la riga
 *   "materie MAT" del docente 77 e quella del docente 140 sono voci diverse, e
 *   il contenuto del primo deve finire sulla sua, non su quella del collega.
 *
 * ATTIVARE NON E' INVENTARE
 *   Se il gemello per quel docente non c'e' ma la voce di ISTITUTO si', la si
 *   attiva per lui: e' la stessa operazione che fa l'app quando un docente
 *   sceglie una materia dal catalogo, e la scuola non acquisisce niente di
 *   nuovo. Se invece manca anche la voce di istituto, lo strumento si ferma:
 *   aggiungere all'altra scuola un indirizzo che non ha e' una decisione, e non
 *   spetta a uno script di bonifica prenderla per far posto a un contenuto.
 *
 * USO
 *   php tools/institutes/prune_cloned_curriculum.php --from=108 --to=106 [--apply]
 *   php tools/institutes/prune_cloned_curriculum.php --from=108 --to=106 \n *        --delete-ids=175,176,177 --apply
 *
 *   Senza --apply non tocca nulla. Senza --delete-ids non cancella nulla che
 *   non abbia appena svuotato da se'.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\InstituteMergeService;
use App\Support\CurriculumLookup;

$base = dirname(__DIR__, 2);
foreach (['.env', '.env.local'] as $envFile) {
    if (is_file($base . '/' . $envFile)) {
        Dotenv\Dotenv::createMutable($base, $envFile)->safeLoad();
    }
}
Config::load($base . '/app/Config');

$opts = getopt('', ['from:', 'to:', 'apply', 'delete-ids:', 'help']);
if (isset($opts['help']) || !isset($opts['from']) || !isset($opts['to'])) {
    fwrite(STDERR, "Uso: php tools/institutes/prune_cloned_curriculum.php --from=<id> --to=<id> [--apply]\n");
    fwrite(STDERR, "     [--delete-ids=1,2,3]  cancella SOLO queste voci, dopo averle lette\n");
    exit(isset($opts['help']) ? 0 : 1);
}
$from  = (int)$opts['from'];
$to    = (int)$opts['to'];
$apply = isset($opts['apply']);
$daCancellareEsplicite = [];
foreach (explode(',', (string)($opts['delete-ids'] ?? '')) as $x) {
    $x = (int)trim($x);
    if ($x > 0) {
        $daCancellareEsplicite[$x] = true;
    }
}
if ($from <= 0 || $to <= 0 || $from === $to) {
    fwrite(STDERR, "ABORT: --from e --to devono essere due istituti diversi\n");
    exit(1);
}

$pdo = Database::connection();
$nome = static function (int $id) use ($pdo): string {
    $st = $pdo->prepare('SELECT CONCAT(code, " — ", name) FROM institutes WHERE id = ?');
    $st->execute([$id]);
    return (string)($st->fetchColumn() ?: '(inesistente)');
};
printf("Da:  %d  %s\n", $from, $nome($from));
printf("A:   %d  %s\n\n", $to, $nome($to));

// Le colonne che puntano a curriculum_entries, scoperte dallo schema: 19 su 9
// tabelle. Elencarle a mano e' il modo in cui se ne dimentica una.
$referrers = (new InstituteMergeService())->referrersTo('curriculum_entries');
printf("Colonne che referenziano curriculum_entries: %d\n\n", count($referrers));

/** Quante righe, in tutto lo schema, puntano a questa voce. */
$usiDi = static function (int $entryId) use ($pdo, $referrers): int {
    $n = 0;
    foreach ($referrers as [$t, $c]) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE `$c` = ?");
        $st->execute([$entryId]);
        $n += (int)$st->fetchColumn();
    }
    return $n;
};

// Gemelli sull'istituto giusto, per chiave logica (kind|code|owner).
$gemelli = [];
$st = $pdo->prepare(
    'SELECT id, kind, code, COALESCE(owner_user_id, 0) ok, active
       FROM curriculum_entries WHERE institute_id = ?'
);
$st->execute([$to]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $gemelli[$r['kind'] . '|' . $r['code'] . '|' . $r['ok']] = ['id' => (int)$r['id'], 'active' => (int)$r['active']];
}

$st->execute([$from]);
$righe = $st->fetchAll(PDO::FETCH_ASSOC);
if ($righe === []) {
    echo "Nessuna voce sull'istituto di partenza: niente da fare.\n";
    exit(0);
}

$daRipuntare = $daCancellare = $bloccate = $daRiattivare = $daAttivare = [];
foreach ($righe as $r) {
    $id  = (int)$r['id'];
    $key = $r['kind'] . '|' . $r['code'] . '|' . $r['ok'];
    $usi = $usiDi($id);
    $etichetta = sprintf(
        '%-9s %-8s %s',
        $r['kind'],
        $r['code'],
        (int)$r['ok'] === 0 ? 'istituto' : 'docente ' . $r['ok']
    );

    if ($usi === 0) {
        $daCancellare[] = [$id, $etichetta];
        continue;
    }
    if (isset($gemelli[$key])) {
        $daRipuntare[] = [$id, $gemelli[$key]['id'], $etichetta, $usi];
        if ($gemelli[$key]['active'] === 0) {
            $daRiattivare[$gemelli[$key]['id']] = $etichetta;
        }
        continue;
    }
    // Nessun gemello per QUESTO docente, ma la voce di istituto c'e': allora
    // non si sta inventando niente, si sta attivando per lui una voce che la
    // scuola ha gia'. E' la stessa operazione che fa l'app quando un docente
    // sceglie una materia dal catalogo.
    $ancora = $gemelli[$r['kind'] . '|' . $r['code'] . '|0'] ?? null;
    if ((int)$r['ok'] > 0 && $ancora !== null) {
        $daAttivare[] = [$id, (int)$r['ok'], (string)$r['kind'], (string)$r['code'], $etichetta, $usi];
        continue;
    }
    $bloccate[] = [$id, $etichetta, $usi];
}

if ($daRipuntare !== []) {
    echo "── riferimenti da spostare sul gemello ──\n";
    foreach ($daRipuntare as [$da, $a, $et, $usi]) {
        printf("   %s  id %d → %d   (%d riferimenti)\n", $et, $da, $a, $usi);
    }
    echo "\n";
}
if ($daRiattivare !== []) {
    echo "── gemelli spenti che verrebbero riaccesi ──\n";
    echo "   (ricevono contenuti: lasciarli spenti li renderebbe invisibili)\n";
    foreach ($daRiattivare as $id => $et) {
        printf("   %s  id %d\n", $et, $id);
    }
    echo "\n";
}
if ($daAttivare !== []) {
    echo "── voci che la scuola di arrivo ha, ma non ancora attive per il docente ──\n";
    echo "   (si clona la voce di istituto: e' la stessa operazione che fa l'app quando\n";
    echo "    un docente sceglie una materia dal catalogo, non una voce nuova per la scuola)\n";
    foreach ($daAttivare as [$idA, , , , $etA, $usiA]) {
        printf("   %s  id %d   (%d riferimenti)\n", $etA, $idA, $usiA);
    }
    echo "\n";
}
if ($daCancellare !== []) {
    printf("── voci senza riferimenti sull'istituto %d: %d ──\n", $from, count($daCancellare));
    echo "   NON vengono cancellate. \"Nessuno le usa\" non vuol dire \"sono spazzatura\":\n";
    echo "   un vocabolario appena importato dal MIUR e' senza riferimenti per\n";
    echo "   definizione, e cancellarlo toglierebbe alla scuola i suoi indirizzi e le\n";
    echo "   sue sezioni vere. Nella tabella non c'e' nulla che distingua una riga\n";
    echo "   clonata da una importata, quindi la distinzione la fa chi legge.\n";
    echo "   Per cancellarne alcune: rilancia con --delete-ids=<elenco> --apply\n\n";
    foreach ($daCancellare as [$id, $et]) {
        printf("   %s  id %-7d %s\n", $et, $id, isset($daCancellareEsplicite[$id]) ? '← indicata' : '');
    }
    echo "\n";
}

// Solo gli id indicati a mano, e solo se sono davvero sull'istituto di partenza
// e senza riferimenti: il resto sopravvive.
$idCancellabili = array_column($daCancellare, 0);
$cancellaOra = array_values(array_intersect($idCancellabili, array_keys($daCancellareEsplicite)));
$ignorate = array_diff(array_keys($daCancellareEsplicite), $idCancellabili);
if ($ignorate !== []) {
    printf(
        "ATTENZIONE: id indicati ma non cancellabili (non sull'istituto %d, o ancora usati): %s\n\n",
        $from,
        implode(', ', $ignorate)
    );
}
if ($bloccate !== []) {
    echo "── BLOCCANTI: usate, e senza gemello sull'istituto di arrivo ──\n";
    foreach ($bloccate as [$id, $et, $usi]) {
        printf("   %s  id %d   (%d riferimenti)\n", $et, $id, $usi);
    }
    fwrite(STDERR, "\nABORT: non invento voci sull'istituto di arrivo per fare posto a questi\n");
    fwrite(STDERR, "       contenuti. Decidere a mano: o la voce va creata li' davvero,\n");
    fwrite(STDERR, "       o i contenuti vanno spostati altrove.\n");
    exit(1);
}

if (!$apply) {
    printf(
        "ANTEPRIMA (nessuna modifica) — %d da ri-puntare, %d da attivare per il docente, "
        . "%d gemelli da riaccendere, %d da cancellare\n",
        count($daRipuntare),
        count($daAttivare),
        count($daRiattivare),
        count($daCancellare)
    );
    echo "Per scrivere: rilancia con --apply\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    // Prima si attivano le voci mancanti per il docente, cosi' la fase di
    // ri-puntamento ha sempre un bersaglio.
    foreach ($daAttivare as [$da, $tid, $kind, $code, $et, $usi]) {
        $nuovo = CurriculumLookup::ensureEntryForTeacher($kind, $tid, $code, $to);
        if ($nuovo === null) {
            throw new RuntimeException("attivazione fallita per $et (voce di istituto sparita?)");
        }
        $pdo->prepare('UPDATE curriculum_entries SET active = 1 WHERE id = ?')->execute([$nuovo]);
        $daRipuntare[] = [$da, $nuovo, $et, $usi];
    }

    $spostati = 0;
    foreach ($daRipuntare as [$da, $a, , ]) {
        foreach ($referrers as [$t, $c]) {
            $u = $pdo->prepare("UPDATE `$t` SET `$c` = ? WHERE `$c` = ?");
            $u->execute([$a, $da]);
            $spostati += $u->rowCount();
        }
    }
    foreach (array_keys($daRiattivare) as $id) {
        $pdo->prepare('UPDATE curriculum_entries SET active = 1 WHERE id = ?')->execute([$id]);
    }
    // Le righe appena svuotate: erano copie di voci che ora vivono
    // sull'istituto giusto, e restare le renderebbe scegliibili di nuovo.
    // Queste si cancellano sempre, perche' sappiamo COSA erano: il gemello
    // dove sono finiti i loro contenuti.
    $cancellate = 0;
    foreach (array_map(static fn(array $x): int => $x[0], $daRipuntare) as $id) {
        $d = $pdo->prepare('DELETE FROM curriculum_entries WHERE id = ?');
        $d->execute([$id]);
        $cancellate += $d->rowCount();
    }
    // Le altre solo se indicate a mano, una per una, dopo averle lette.
    foreach ($cancellaOra as $id) {
        $d = $pdo->prepare('DELETE FROM curriculum_entries WHERE id = ? AND institute_id = ?');
        $d->execute([$id, $from]);
        $cancellate += $d->rowCount();
    }
    $pdo->commit();
    printf(
        "FATTO — %d riferimenti spostati, %d gemelli riaccesi, %d voci cancellate dall'istituto %d\n",
        $spostati,
        count($daRiattivare),
        $cancellate,
        $from
    );
    $restano = count($daCancellare) - count($cancellaOra);
    if ($restano > 0) {
        printf("Restano %d voci senza riferimenti: intatte, da valutare a mano.\n", $restano);
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'ABORT in scrittura (rollback eseguito): ' . $e->getMessage() . "\n");
    exit(1);
}
