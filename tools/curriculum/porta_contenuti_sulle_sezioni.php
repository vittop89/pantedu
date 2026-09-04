<?php

declare(strict_types=1);

/**
 * Porta i contenuti archiviati per ANNO a essere visibili per SEZIONE.
 *
 * IL PROBLEMA
 *   I contenuti sono archiviati su un anno ("Classe I"), gli studenti sono
 *   iscritti a una sezione ("1A"). Un contenuto pubblicato con scope 'class'
 *   raggiunge lo studente solo se indirizzo e classe coincidono stringa per
 *   stringa: "1A" non e' "1", quindi oggi non raggiunge nessuno.
 *
 * COSA FA (e cosa NON fa)
 *   NON riscrive l'archiviazione. `classe_id` resta sull'anno: e' come il
 *   docente ha ordinato il proprio lavoro, e cambiarlo su 350 righe per una
 *   questione di visibilita' sarebbe rischio speso male. Un contenuto vale
 *   spesso per PIU' sezioni, e un `classe_id` solo non saprebbe dirlo: e'
 *   esattamente perche' esiste `content_target_classes`.
 *
 *   Quindi: `publish_scope` passa da 'class' a 'classes', e nascono le coppie
 *   (indirizzo, sezione) verso cui il contenuto e' visibile.
 *
 * DA DOVE VENGONO LE SEZIONI
 *   Dagli incarichi del docente, non da un elenco scritto qui. "Insegno in 2A
 *   e 2B" e' un'informazione che sta gia' in `teacher_sections` e che serve
 *   comunque al filtro: usarla due volte evita di chiederla due volte, e di
 *   avere due risposte diverse.
 *
 *   L'indirizzo scritto nella coppia e' quello della SEZIONE, non quello del
 *   contenuto: lo studente viene confrontato con il proprio, e un artistico di
 *   quarta e' iscritto ad AAA anche se il contenuto e' archiviato sotto ART.
 *
 * IL BIENNIO COMUNE
 *   All'artistico ART esiste solo al primo biennio; dal terzo anno le sezioni
 *   stanno sotto AAA (architettura) o AFI (arti figurative). Un contenuto
 *   "ART anno 4" non ha sezioni proprie, e la corrispondenza va dichiarata:
 *
 *     --rimappa=ART:3=AAA,ART:4=AAA,ART:5=AAA
 *
 *   Piu' indirizzi si sommano con "+": la matematica di prima e' la stessa
 *   per lo scientifico e per lo sportivo, che sono due indirizzi diversi.
 *
 *     --rimappa=SCI:1=SCI+SCS
 *
 *   Senza dichiarazione quel gruppo resta intatto e viene elencato: indovinare
 *   fra architettura e arti figurative non e' un lavoro da strumento.
 *
 * USO
 *   php tools/curriculum/porta_contenuti_sulle_sezioni.php --institute=106 --teacher=superadmin
 *   php tools/curriculum/porta_contenuti_sulle_sezioni.php --institute=106 --teacher=superadmin \
 *       --rimappa=ART:3=AAA,ART:4=AAA,ART:5=AAA --apply
 *
 * REVERSIBILE
 *   Ripristino: DELETE dalle coppie create e publish_scope di nuovo a 'class'.
 *   Nessun contenuto viene modificato nel merito, nessuna riga cancellata.
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

$apply    = in_array('--apply', $argv, true);
$istituto = 0;
$docente  = '';
$rimappa  = [];
foreach ($argv as $a) {
    if (preg_match('/^--institute=(\d+)$/', $a, $m)) {
        $istituto = (int)$m[1];
    } elseif (preg_match('/^--teacher=(.+)$/', $a, $m)) {
        $docente = $m[1];
    } elseif (preg_match('/^--rimappa=(.+)$/', $a, $m)) {
        foreach (explode(',', $m[1]) as $coppia) {
            // Piu' indirizzi con "+": la matematica di prima e' la stessa per
            // lo scientifico e per lo sportivo, e sono due indirizzi diversi.
            if (preg_match('/^([A-Z]{2,6}):([1-9])=([A-Z+]{2,30})$/', trim($coppia), $c)) {
                $rimappa[$c[1] . ':' . $c[2]] = array_values(array_filter(explode('+', $c[3])));
            }
        }
    }
}
if ($istituto <= 0 || $docente === '') {
    fwrite(STDERR, "Servono --institute=<id> e --teacher=<username>.\n");
    exit(1);
}

$pdo = Database::connection();

$st = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$st->execute([$docente]);
$uid = (int)$st->fetchColumn();
if ($uid <= 0) {
    fwrite(STDERR, "Docente '$docente' inesistente.\n");
    exit(1);
}

// Gli incarichi: indirizzo → anno → sezioni.
$st = $pdo->prepare('SELECT indirizzo, classe FROM teacher_sections WHERE user_id = ? AND institute_id = ?');
$st->execute([$uid, $istituto]);
$incarichi = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $classe = (string)$r['classe'];
    if (preg_match('/^[1-9]$/', $classe)) {
        continue; // un incarico sull'anno non indica una sezione
    }
    $incarichi[(string)$r['indirizzo']][substr($classe, 0, 1)][] = $classe;
}
if ($incarichi === []) {
    fwrite(STDERR, "Nessun incarico su sezioni per $docente nell'istituto $istituto.\n"
                 . "Assegnali da /admin/sections: sono loro a dire quali sezioni raggiungere.\n");
    exit(1);
}

echo "Incarichi da cui si parte:\n";
foreach ($incarichi as $ind => $anni) {
    ksort($anni);
    foreach ($anni as $anno => $sez) {
        printf("  %-5s anno %s : %s\n", $ind, $anno, implode(' ', $sez));
    }
}
echo "\n";

// L'indirizzo vero di ogni sezione: e' quello che vedra' lo studente.
$st = $pdo->prepare("SELECT code, indirizzo FROM curriculum_entries
                      WHERE kind = 'classi' AND owner_user_id IS NULL AND institute_id = ?");
$st->execute([$istituto]);
$indDellaSezione = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $indDellaSezione[(string)$r['code']] = (string)($r['indirizzo'] ?? '');
}

// I contenuti da spostare, raggruppati come li si ragiona.
$st = $pdo->prepare("SELECT id, indirizzo, classe FROM teacher_content
                      WHERE teacher_id = ? AND publish_scope = 'class'
                        AND classe REGEXP '^[1-9]$' AND indirizzo IS NOT NULL");
$st->execute([$uid]);
$gruppi = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $gruppi[$r['indirizzo'] . ':' . $r['classe']][] = (int)$r['id'];
}
ksort($gruppi);

if ($gruppi === []) {
    echo "Nessun contenuto archiviato per anno da portare sulle sezioni.\n";
    exit(0);
}

$daScrivere = [];
$scoperti   = [];
foreach ($gruppi as $chiave => $ids) {
    [$ind, $anno] = explode(':', $chiave);
    $indSezioni = $rimappa[$chiave] ?? [$ind];
    $coppie = [];
    foreach ($indSezioni as $indCercato) {
        foreach ($incarichi[$indCercato][$anno] ?? [] as $s) {
            $coppie[] = [($indDellaSezione[$s] ?? $indCercato), $s];
        }
    }

    if ($coppie === []) {
        $scoperti[] = [
            'gruppo'  => $chiave,
            'quanti'  => count($ids),
            'cercato' => implode(' + ', $indSezioni),
        ];
        continue;
    }
    $daScrivere[] = ['ids' => $ids, 'gruppo' => $chiave, 'coppie' => $coppie];
}

$totale = 0;
if ($daScrivere !== []) {
    echo "Contenuti da rendere visibili per sezione:\n";
    foreach ($daScrivere as $d) {
        $etichette = array_map(static fn(array $c): string => $c[0] . ' ' . $c[1], $d['coppie']);
        printf("  %-10s x%-4d →  %s\n", $d['gruppo'], count($d['ids']), implode(' · ', $etichette));
        $totale += count($d['ids']);
    }
    printf("  ---- %d contenuti\n", $totale);
}

if ($scoperti !== []) {
    echo "\nGruppi senza sezioni corrispondenti (non toccati):\n";
    foreach ($scoperti as $s) {
        printf("  %-10s x%-4d — nessun incarico su sezioni di %s a quell'anno\n",
            $s['gruppo'], $s['quanti'], $s['cercato']);
    }
    echo "  Se e' il biennio comune dell'artistico, dichiara la corrispondenza:\n";
    echo "    --rimappa=ART:3=AAA,ART:4=AAA,ART:5=AAA\n";
}

if ($daScrivere === []) {
    exit(0);
}

if (!$apply) {
    echo "\nANTEPRIMA (nessuna modifica). Per scrivere: --apply\n";
    exit(0);
}

$insCoppia = $pdo->prepare(
    'INSERT IGNORE INTO content_target_classes (content_id, indirizzo, classe) VALUES (?, ?, ?)'
);
$updScope = $pdo->prepare(
    "UPDATE teacher_content_data SET publish_scope = 'classes' WHERE id = ? AND publish_scope = 'class'"
);

$pdo->beginTransaction();
try {
    $coppieScritte = 0;
    $scopeCambiati = 0;
    foreach ($daScrivere as $d) {
        foreach ($d['ids'] as $id) {
            foreach ($d['coppie'] as [$indSez, $sez]) {
                $insCoppia->execute([$id, $indSez, $sez]);
                $coppieScritte += $insCoppia->rowCount();
            }
            $updScope->execute([$id]);
            $scopeCambiati += $updScope->rowCount();
        }
    }
    $pdo->commit();
    printf("\nFATTO — %d contenuti ora pubblicati per sezione, %d coppie scritte.\n",
        $scopeCambiati, $coppieScritte);
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Niente scritto: " . $e->getMessage() . "\n");
    exit(1);
}
