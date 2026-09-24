<?php

declare(strict_types=1);

/**
 * In locale: dal censimento delle mappe e dagli originali del Drive, la patch
 * per tools/maps/ripristina_caratteri.php e l'elenco da rivedere
 * (App\Services\Maps\PreparazioneRipristino, dove stanno le regole; la storia
 * in wiki/domains/mappe/mappe-overview.md, «Caratteri persi»).
 *
 * Gli originali sono i drawio di «Risorse web/MAPPE - My WebSite» del Drive del
 * docente, dalla copia che Google Drive tiene sul computer (in WSL:
 * /mnt/g/..., dopo `sudo mount -t drvfs G: /mnt/g`), oppure da una loro copia.
 * Non serve nessun permesso Drive nuovo, e sul server non va niente in chiaro.
 *
 *   php tools/maps/prepara_ripristino_caratteri.php \
 *       --censimento=CENSIMENTO.json --originali=CARTELLA [--originali=ALTRA] --uscita=CARTELLA
 *
 * Scrive nella cartella d'uscita:
 *
 *   ripristino-caratteri.json            la patch: sostituzioni da originale esatto e da
 *                                        contesto univoco, pronte per --patch
 *   ripristino-caratteri-revisione.csv   tutto il resto, una riga per corsa (le copie
 *                                        con lo stesso contenuto stanno su una riga):
 *                                        proposte delle regole, corse senza fonte e corse
 *                                        legittime. Si apre con un foglio di calcolo
 *                                        (separatore «;»), si scrive «si» nella colonna
 *                                        «approvata» e, se serve, si corregge «testo».
 *                                        Lo spazio non separabile si scrive \u00A0.
 *
 * Poi, con l'elenco rivisto, si rifà la patch con le righe approvate:
 *
 *   php tools/maps/prepara_ripristino_caratteri.php --censimento=... --originali=... \
 *       --uscita=CARTELLA --approvate=CARTELLA/ripristino-caratteri-revisione.csv
 *
 * Patch ed elenco contengono frammenti di testo didattico: la cartella d'uscita
 * non può stare dentro il repository (lo strumento si rifiuta), e i file si
 * cancellano dopo l'applicazione.
 *
 * Uscita: 0 fatto, 1 errore di lettura o di scrittura, 2 uso sbagliato.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "cli_only";
    exit(2);
}

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Services\Maps\PreparazioneRipristino;

const USO_PREPARA = "Uso: php tools/maps/prepara_ripristino_caratteri.php --censimento=FILE --originali=CARTELLA"
    . " [--originali=ALTRA] --uscita=CARTELLA [--approvate=CSV]\n";
const PATCH = 'ripristino-caratteri.json';
const REVISIONE = 'ripristino-caratteri-revisione.csv';
const COLONNE = ['chiave', 'mappe', 'titolo', 'corsa', 'prima', 'dopo', 'fonte', 'regola', 'candidati', 'approvata', 'testo'];

$censimento = null;
$cartelle = [];
$uscita = null;
$approvate = null;
foreach (array_slice($argv, 1) as $argomento) {
    if (preg_match('/^--censimento=(.+)$/', $argomento, $m) === 1) {
        $censimento = $m[1];
    } elseif (preg_match('/^--originali=(.+)$/', $argomento, $m) === 1) {
        $cartelle[] = $m[1];
    } elseif (preg_match('/^--uscita=(.+)$/', $argomento, $m) === 1) {
        $uscita = $m[1];
    } elseif (preg_match('/^--approvate=(.+)$/', $argomento, $m) === 1) {
        $approvate = $m[1];
    } else {
        fwrite(STDERR, USO_PREPARA);
        exit(2);
    }
}
if ($censimento === null || $cartelle === [] || $uscita === null) {
    fwrite(STDERR, USO_PREPARA);
    exit(2);
}
ini_set('memory_limit', '-1');
set_time_limit(0);

// Frammenti di testo didattico: mai dentro il repository. Il controllo si fa
// prima di creare la cartella, risalendo fino a una che esiste già.
$repository = (string)realpath(__DIR__ . '/../..');
$esistente = $uscita;
$resto = '';
while (!is_dir($esistente) && dirname($esistente) !== $esistente) {
    $resto = '/' . basename($esistente) . $resto;
    $esistente = dirname($esistente);
}
$uscitaVera = rtrim((string)realpath($esistente), '/') . $resto;
if ($uscitaVera === $repository || str_starts_with($uscitaVera . '/', $repository . '/')) {
    fwrite(STDERR, "[prepara] la cartella d'uscita è dentro il repository: patch ed elenco contengono testo didattico, scegline una fuori.\n");
    exit(2);
}
if (!is_dir($uscitaVera) && !@mkdir($uscitaVera, 0o700, true)) {
    fwrite(STDERR, "[prepara] non riesco a creare $uscitaVera\n");
    exit(1);
}

$dati = json_decode((string)@file_get_contents($censimento), true);
if (!is_array($dati)) {
    fwrite(STDERR, "[prepara] censimento non leggibile: $censimento\n");
    exit(1);
}

/** @return \Generator<string, string> */
function originali(array $cartelle): \Generator
{
    foreach ($cartelle as $cartella) {
        if (!is_dir($cartella)) {
            throw new \RuntimeException("cartella degli originali assente: $cartella");
        }
        $voci = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cartella, \FilesystemIterator::SKIP_DOTS));
        foreach ($voci as $voce) {
            if ($voce instanceof \SplFileInfo && $voce->isFile() && strtolower($voce->getExtension()) === 'drawio') {
                $contenuto = file_get_contents($voce->getPathname());
                if ($contenuto === false) {
                    throw new \RuntimeException('originale non leggibile: ' . $voce->getPathname());
                }
                yield $voce->getPathname() => $contenuto;
            }
        }
    }
}

$file = 0;
try {
    $conta = static function (iterable $sorgente) use (&$file): \Generator {
        foreach ($sorgente as $nome => $contenuto) {
            $file++;
            yield $nome => $contenuto;
        }
    };
    $preparazione = new PreparazioneRipristino($conta(originali($cartelle)));
} catch (\RuntimeException $e) {
    fwrite(STDERR, '[prepara] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
fwrite(STDERR, "[prepara] originali letti: $file file, " . $preparazione->quantiTesti() . " testi distinti (con le pagine aperte)\n");

$esito = $preparazione->prepara($dati);
$patch = $esito['patch'];

if ($approvate !== null) {
    $righe = leggiCsv($approvate);
    if ($righe === null) {
        fwrite(STDERR, "[prepara] elenco approvato non leggibile: $approvate\n");
        exit(1);
    }
    $unite = PreparazioneRipristino::conApprovate($dati, $righe, $patch);
    $patch = $unite['patch'];
    fwrite(STDERR, "[prepara] righe approvate aggiunte alla patch: {$unite['aggiunte']}\n");
    foreach ($unite['scartate'] as $s) {
        fwrite(STDERR, "[prepara]   SCARTATA {$s['chiave']}: {$s['motivo']}\n");
    }
}

$json = json_encode($patch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($json === false || file_put_contents("$uscitaVera/" . PATCH, $json . "\n") === false) {
    fwrite(STDERR, "[prepara] patch non scritta\n");
    exit(1);
}
if ($approvate === null) {
    $h = fopen("$uscitaVera/" . REVISIONE, 'wb');
    if ($h === false) {
        fwrite(STDERR, "[prepara] elenco non scritto\n");
        exit(1);
    }
    fwrite($h, "\xEF\xBB\xBF"); // BOM: il foglio di calcolo riconosce l'UTF-8
    fputcsv($h, COLONNE, ';', '"', '');
    foreach ($esito['revisione'] as $riga) {
        fputcsv($h, array_map(static fn(string $c): string => (string)($riga[$c] ?? ''), COLONNE), ';', '"', '');
    }
    fclose($h);
}

$sostituzioni = 0;
$fonti = [];
foreach ($patch['mappe'] as $m) {
    foreach ($m['sostituzioni'] as $s) {
        $sostituzioni++;
        $fonti[$s['fonte']] = ($fonti[$s['fonte']] ?? 0) + 1;
    }
}
ksort($fonti);
$c = $esito['conteggi'];
echo "[prepara] mappe nel censimento: {$c['mappe']} ({$c['contenuti']} contenuti distinti), in patch: " . count($patch['mappe'])
    . ", utf8 non valido: {$c['mappe_utf8_non_valido']}\n";
echo "[prepara] sostituzioni in patch: $sostituzioni " . json_encode($fonti, JSON_UNESCAPED_UNICODE) . "\n";
foreach ($c['per_docente'] as $tid => $d) {
    echo "[prepara] docente $tid: " . json_encode($d) . "\n";
}
echo "[prepara] righe da rivedere: " . count($esito['revisione']) . ' ' . json_encode(array_count_values(array_column($esito['revisione'], 'fonte'))) . "\n";
echo "[prepara] regole misurate sulle corse di testo noto: " . json_encode($c['validazione_regole']) . "\n";
echo "[prepara] scritti in $uscitaVera: " . PATCH . ($approvate === null ? ', ' . REVISIONE : '') . "\n";
exit(0);

/** @return list<array<string,string>>|null */
function leggiCsv(string $percorso): ?array
{
    $h = @fopen($percorso, 'rb');
    if ($h === false) {
        return null;
    }
    $intestazione = fgetcsv($h, null, ';', '"', '');
    if (!is_array($intestazione)) {
        fclose($h);
        return null;
    }
    $intestazione = array_map(static fn($x): string => (string)preg_replace('/^\xEF\xBB\xBF/', '', (string)$x), $intestazione);
    $righe = [];
    while (($r = fgetcsv($h, null, ';', '"', '')) !== false) {
        if ($r === [null]) {
            continue;
        }
        $riga = [];
        foreach ($intestazione as $i => $nome) {
            $riga[$nome] = (string)($r[$i] ?? '');
        }
        $righe[] = $riga;
    }
    fclose($h);
    return $righe;
}
