<?php

declare(strict_types=1);

/**
 * Riallinea le etichette scritte nel database con quelle decise in
 * docs/curriculum/miur_alias.json.
 *
 * PERCHE' SERVE
 *   L'import non riscrive mai le etichette delle voci esistenti: una scuola
 *   puo' averle cambiate apposta, e sovrascriverle di nascosto sarebbe peggio
 *   del disallineamento. Ma quando il file dice una cosa e il database
 *   un'altra, serve un modo esplicito per far vincere il file.
 *
 * IL CASO CHE L'HA MOTIVATO
 *   Su un istituto l'etichetta era "Artistico ù architettura e ambiente": un
 *   trattino lungo diventato "u con accento". L'origine e' stata riprodotta —
 *   quella riga era stata scritta con la riga di comando `mysql -e`, che su
 *   Windows manda la query nella codepage della console senza dichiararla, e i
 *   tre byte UTF-8 del trattino atterrano come uno solo, sbagliato. Da PDO gli
 *   stessi byte arrivano intatti.
 *
 *   Quindi la correzione passa DA QUI e non dal client mysql: rifarla di la'
 *   la riprodurrebbe identica.
 *
 * COSA FA
 *   Elenca le voci la cui etichetta nel database differisce da quella decisa
 *   nel file alias, e non ne cambia nessuna finche' non le si indica per id:
 *   fra le divergenze ce ne sono di legittime — "Educazione Motoria" contro "Scienze motorie e
 *   sportive" e' il nome vecchio contro quello attuale, ed e' una scelta, non
 *   un errore.
 *
 * USO
 *   php tools/curriculum/realign_labels.php
 *   php tools/curriculum/realign_labels.php --ids=37091 --apply
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Support\MiurCurriculumAlias;

$base = dirname(__DIR__, 2);
foreach (['.env', '.env.local'] as $envFile) {
    if (is_file($base . '/' . $envFile)) {
        Dotenv\Dotenv::createMutable($base, $envFile)->safeLoad();
    }
}
Config::load($base . '/app/Config');

$opts = getopt('', ['ids:', 'apply', 'help']);
if (isset($opts['help'])) {
    fwrite(STDERR, "Uso: php tools/curriculum/realign_labels.php [--ids=1,2,3] [--apply]\n");
    exit(0);
}
$apply = isset($opts['apply']);
$scelti = [];
foreach (explode(',', (string)($opts['ids'] ?? '')) as $x) {
    $x = (int)trim($x);
    if ($x > 0) {
        $scelti[$x] = true;
    }
}

$pdo = Database::connection();

// code → etichetta decisa, per kind.
$decise = [];
foreach (MiurCurriculumAlias::KINDS as $kind) {
    foreach (MiurCurriculumAlias::fromFile($kind)->all() as $v) {
        if ($v['label'] !== null) {
            $decise[$kind][strtoupper($v['code'])] = $v['label'];
        }
    }
}
if ($decise === []) {
    fwrite(STDERR, "ABORT: il file alias non propone nessuna etichetta.\n");
    exit(1);
}

$rows = $pdo->query(
    'SELECT id, kind, code, institute_id, owner_user_id, label
       FROM curriculum_entries ORDER BY kind, institute_id, code, id'
)->fetchAll(PDO::FETCH_ASSOC);

$diverse = [];
foreach ($rows as $r) {
    $attesa = $decise[(string)$r['kind']][strtoupper((string)$r['code'])] ?? null;
    if ($attesa === null || (string)$r['label'] === $attesa) {
        continue;
    }
    $diverse[] = $r + ['attesa' => $attesa];
}

if ($diverse === []) {
    echo "Tutte le etichette del database coincidono con il file alias.\n";
    exit(0);
}

/**
 * Distingue un carattere DEFORMATO da una scelta diversa.
 *
 * Il primo tentativo segnava come sospetto tutto cio' che aveva byte non ASCII
 * dove il file alias non ne ha, e sbagliava due volte su quattro: un trattino
 * lungo scritto correttamente e' non-ASCII, ma non e' rotto. Il criterio giusto
 * e' un altro — nella corruzione osservata i tre byte del trattino diventano
 * una LETTERA accentata, restando una stringa della stessa lunghezza:
 *
 *     Artistico ù architettura   ← una lettera dove il file alias ha un segno
 *     Artistico — architettura   ← un segno dove il file alias ha un segno
 *
 * Quindi: stessa lunghezza, poche posizioni diverse, e in quelle posizioni una
 * lettera al posto di una punteggiatura. Un nome semplicemente diverso
 * ("Educazione Motoria" contro "Scienze motorie e sportive") non passa il
 * primo controllo e resta quello che e': una scelta, non un errore.
 */
$deformata = static function (string $db, string $reg): bool {
    if (mb_strlen($db) !== mb_strlen($reg)) {
        return false;
    }
    $diff = 0;
    for ($i = 0, $n = mb_strlen($db); $i < $n; $i++) {
        $a = mb_substr($db, $i, 1);
        $b = mb_substr($reg, $i, 1);
        if ($a === $b) {
            continue;
        }
        $diff++;
        // Lettera dove il file alias ha un segno: e' la firma del danno.
        if (!preg_match('/\p{L}/u', $a) || preg_match('/\p{L}/u', $b)) {
            return false;
        }
    }
    return $diff > 0 && $diff <= 2;
};

// "a registro" era ambiguo: in questo progetto indica sia le voci a catalogo
// sia il registro degli alias. Qui si confrontano due cose precise — cosa c'e'
// scritto nel database e cosa dice il file — e vanno chiamate per nome.
printf("%d etichette del database diverse da quelle decise nel file alias:\n\n", count($diverse));
foreach ($diverse as $r) {
    $storto = $deformata((string)$r['label'], (string)$r['attesa']);
    printf(
        "  id %-7d %-9s %-6s istituto %-5d %s\n",
        $r['id'],
        $r['kind'],
        $r['code'],
        $r['institute_id'],
        isset($scelti[(int)$r['id']]) ? '← indicata' : ($storto ? '← caratteri sospetti' : '')
    );
    printf("      nel database:   %s\n", $r['label']);
    printf("      nel file alias: %s\n", $r['attesa']);
}
echo "\n";

$daFare = array_values(array_filter(
    $diverse,
    static fn(array $r): bool => isset($scelti[(int)$r['id']])
));
$ignorati = array_diff(array_keys($scelti), array_map(static fn(array $r): int => (int)$r['id'], $diverse));
if ($ignorati !== []) {
    printf("ATTENZIONE: id indicati ma non divergenti (o inesistenti): %s\n\n", implode(', ', $ignorati));
}

if (!$apply || $daFare === []) {
    if ($daFare === []) {
        echo "Nessun id indicato: non cambio niente. Scegli con --ids=<elenco> --apply\n";
    } else {
        printf("ANTEPRIMA — con --apply verrebbero riscritte %d etichette.\n", count($daFare));
    }
    exit(0);
}

$upd = $pdo->prepare('UPDATE curriculum_entries SET label = ? WHERE id = ?');
foreach ($daFare as $r) {
    $upd->execute([$r['attesa'], (int)$r['id']]);
    // Rilettura: la scrittura e' il punto in cui il problema originale si era
    // manifestato, quindi non ci si fida del rowCount.
    $chk = $pdo->prepare('SELECT label FROM curriculum_entries WHERE id = ?');
    $chk->execute([(int)$r['id']]);
    $ora = (string)$chk->fetchColumn();
    printf(
        "  %s id %d → %s\n",
        $ora === $r['attesa'] ? 'FATTO  ' : 'FALLITO',
        $r['id'],
        $ora
    );
}
