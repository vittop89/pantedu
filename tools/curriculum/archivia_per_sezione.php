<?php

declare(strict_types=1);

/**
 * Sposta l'archiviazione dei contenuti dall'ANNO alla SEZIONE.
 *
 * PERCHE'
 *   I contenuti erano archiviati su un anno ("Classe I") perche' il sito
 *   vecchio non conosceva le sezioni. Finche' un docente e' solo, i due modi
 *   si equivalgono; quando sulle prime ce ne sono tre, no: lo studente di 1A
 *   si vedrebbe tre versioni dello stesso argomento. La sezione e' l'unita'
 *   giusta, e l'archiviazione deve dirlo.
 *
 * QUANDO SI PUO' FARE
 *   `classe_id` e' una casella sola: si puo' spostare solo se per ogni coppia
 *   corso-anno il docente ha UNA sezione. Se ne ha due, un contenuto dell'anno
 *   non sa in quale delle due finire, e quel gruppo resta com'e' — elencato,
 *   non indovinato. Per quei casi la visibilita' multipla resta la strada
 *   giusta (porta_contenuti_sulle_sezioni.php).
 *
 * IL BIENNIO COMUNE
 *   All'artistico ART esiste solo al primo biennio; dal terzo anno le sezioni
 *   stanno sotto AAA o AFI. Un contenuto "ART anno 4" quindi non cambia solo
 *   classe ma anche indirizzo, e la corrispondenza va dichiarata:
 *
 *     --rimappa=ART:3=AAA,ART:4=AAA,ART:5=AAA
 *
 *   Senza dichiarazione il gruppo resta intatto: scegliere fra architettura e
 *   arti figurative non e' un lavoro da strumento.
 *
 * REVERSIBILE
 *   Prima di scrivere salva id, classe_id e indirizzo_id di ogni riga toccata
 *   nel file indicato da --dump. Senza quel file non parte.
 *
 * USO
 *   php tools/curriculum/archivia_per_sezione.php --institute=106 \
 *       --teacher=superadmin --dump=/var/tmp/prima.json \
 *       --rimappa=ART:3=AAA,ART:4=AAA,ART:5=AAA
 *   ... --apply   per scrivere
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
$dump     = '';
$rimappa  = [];
foreach ($argv as $a) {
    if (preg_match('/^--institute=(\d+)$/', $a, $m)) {
        $istituto = (int)$m[1];
    } elseif (preg_match('/^--teacher=(.+)$/', $a, $m)) {
        $docente = $m[1];
    } elseif (preg_match('/^--dump=(.+)$/', $a, $m)) {
        $dump = $m[1];
    } elseif (preg_match('/^--rimappa=(.+)$/', $a, $m)) {
        foreach (explode(',', $m[1]) as $coppia) {
            if (preg_match('/^([A-Z]{2,6}):([1-9])=([A-Z]{2,6})$/', trim($coppia), $c)) {
                $rimappa[$c[1] . ':' . $c[2]] = $c[3];
            }
        }
    }
}
if ($istituto <= 0 || $docente === '' || $dump === '') {
    fwrite(STDERR, "Servono --institute=<id>, --teacher=<username> e --dump=<file.json>\n");
    exit(1);
}
if (is_file($dump)) {
    fwrite(STDERR, "Il file $dump esiste gia': scegline un altro, non lo sovrascrivo.\n");
    exit(1);
}

$pdo = Database::connection();
$st  = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$st->execute([$docente]);
$uid = (int)$st->fetchColumn();
if ($uid <= 0) {
    fwrite(STDERR, "Docente '$docente' inesistente.\n");
    exit(1);
}

// Gli incarichi su sezioni: indirizzo → anno → sezioni.
$st = $pdo->prepare('SELECT indirizzo, classe FROM teacher_sections WHERE user_id = ? AND institute_id = ?');
$st->execute([$uid, $istituto]);
$incarichi = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (preg_match('/^[1-9]$/', (string)$r['classe'])) {
        continue;
    }
    $incarichi[(string)$r['indirizzo']][substr((string)$r['classe'], 0, 1)][] = (string)$r['classe'];
}
if ($incarichi === []) {
    fwrite(STDERR, "Nessun incarico su sezioni: sono loro a dire dove archiviare.\n");
    exit(1);
}

// Le righe DEL DOCENTE: i contenuti puntano a quelle, non alle voci di istituto.
$st = $pdo->prepare(
    'SELECT id, kind, code FROM curriculum_entries
      WHERE institute_id = ? AND owner_user_id = ? AND active = 1'
);
$st->execute([$istituto, $uid]);
$voce = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $voce[$r['kind'] . ':' . $r['code']] = (int)$r['id'];
}

// I contenuti archiviati su un anno.
$st = $pdo->prepare(
    "SELECT d.id, d.classe_id, d.indirizzo_id, ci.code AS ind, cc.code AS anno
       FROM teacher_content_data d
       JOIN curriculum_entries cc ON cc.id = d.classe_id
       LEFT JOIN curriculum_entries ci ON ci.id = d.indirizzo_id
      WHERE d.teacher_id = ? AND cc.code REGEXP '^[1-9]$' AND d.indirizzo_id IS NOT NULL
      ORDER BY ci.code, cc.code, d.id"
);
$st->execute([$uid]);
$righe = $st->fetchAll(PDO::FETCH_ASSOC);
if ($righe === []) {
    echo "Nessun contenuto archiviato per anno.\n";
    exit(0);
}

$daScrivere = [];
$scoperti   = [];
foreach ($righe as $r) {
    $chiave = $r['ind'] . ':' . $r['anno'];
    $indDest = $rimappa[$chiave] ?? (string)$r['ind'];
    $sezioni = $incarichi[$indDest][$r['anno']] ?? [];

    if (count($sezioni) !== 1) {
        $scoperti[$chiave] = [
            'quanti' => ($scoperti[$chiave]['quanti'] ?? 0) + 1,
            'perche' => $sezioni === []
                ? "nessun incarico su sezioni di $indDest a quell'anno"
                : 'piu\' di una sezione (' . implode(' ', $sezioni) . '): la casella e\' una sola',
        ];
        continue;
    }
    $sez = $sezioni[0];
    $idClasse = $voce['classi:' . $sez] ?? null;
    $idInd    = $voce['indirizzi:' . $indDest] ?? null;
    if ($idClasse === null || $idInd === null) {
        $scoperti[$chiave] = [
            'quanti' => ($scoperti[$chiave]['quanti'] ?? 0) + 1,
            'perche' => "il docente non ha una voce attiva per $indDest / $sez",
        ];
        continue;
    }
    $daScrivere[] = [
        'id'          => (int)$r['id'],
        'da'          => $r['ind'] . ' ' . $r['anno'],
        'a'           => $indDest . ' ' . $sez,
        'classe_id'   => $idClasse,
        'indirizzo_id' => $idInd,
        'prima'       => ['classe_id' => (int)$r['classe_id'], 'indirizzo_id' => (int)$r['indirizzo_id']],
    ];
}

$perGruppo = [];
foreach ($daScrivere as $d) {
    $perGruppo[$d['da'] . ' → ' . $d['a']] = ($perGruppo[$d['da'] . ' → ' . $d['a']] ?? 0) + 1;
}
ksort($perGruppo);

printf("%d contenuti archiviati per anno.\n\n", count($righe));
if ($perGruppo !== []) {
    echo "Da spostare sulla sezione:\n";
    foreach ($perGruppo as $etichetta => $n) {
        printf("  %-22s x%d\n", $etichetta, $n);
    }
    printf("  ---- %d contenuti\n", count($daScrivere));
}
if ($scoperti !== []) {
    echo "\nGruppi lasciati com'erano:\n";
    foreach ($scoperti as $chiave => $s) {
        printf("  %-10s x%-4d — %s\n", $chiave, $s['quanti'], $s['perche']);
    }
}

if ($daScrivere === []) {
    exit(0);
}
if (!$apply) {
    echo "\nANTEPRIMA (nessuna modifica). Per scrivere: --apply\n";
    exit(0);
}

$scritti = file_put_contents($dump, json_encode([
    'salvato_il' => date('c'),
    'docente'    => $docente,
    'istituto'   => $istituto,
    'righe'      => array_map(static fn(array $d): array => [
        'id' => $d['id'], 'prima' => $d['prima'],
    ], $daScrivere),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if ($scritti === false || $scritti === 0) {
    fwrite(STDERR, "Non sono riuscito a scrivere la copia in $dump: non tocco niente.\n");
    exit(1);
}
printf("\nCopia salvata in %s (%d byte).\n", $dump, $scritti);

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare(
        'UPDATE teacher_content_data SET classe_id = ?, indirizzo_id = ?
          WHERE id = ? AND teacher_id = ?'
    );
    $fatti = 0;
    foreach ($daScrivere as $d) {
        $upd->execute([$d['classe_id'], $d['indirizzo_id'], $d['id'], $uid]);
        $fatti += $upd->rowCount();
    }
    $pdo->commit();
    printf("FATTO — %d contenuti ora archiviati sulla loro sezione.\n", $fatti);
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Niente scritto: ' . $e->getMessage() . "\n");
    exit(1);
}
