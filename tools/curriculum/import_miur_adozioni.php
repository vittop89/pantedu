<?php

declare(strict_types=1);

/**
 * Importa indirizzi e SEZIONI di una scuola dal dataset MIUR delle adozioni.
 *
 * La logica vive in App\Services\MiurAdozioniImporter, condivisa con il
 * pannello /admin/institutes: qui c'e' solo il vestito da riga di comando.
 * Due strade che scrivono le stesse righe devono farlo con lo stesso codice,
 * altrimenti prima o poi divergono e nessuno se ne accorge.
 *
 * SORGENTE
 *   dati.istruzione.it → ambito "Adozioni libri di testo", un dataset per
 *   regione. Piemonte: DS0712ALTPIEMONTE → ALTPIEMONTE<data>.csv (~50 MB).
 *   Licenza IODL 2.0.
 *
 * USO
 *   php tools/curriculum/import_miur_adozioni.php --csv=<file> [--institute=CODE] [--apply]
 *
 *   Senza --apply non tocca nulla. I codici degli indirizzi sono PROPOSTI: la
 *   mappa descrizione → sigla vincola poi tutti i contenuti, quindi la
 *   conferma resta umana.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\MiurAdozioniImporter;
use App\Support\MiurCurriculumAlias;

$base = dirname(__DIR__, 2);
foreach (['.env', '.env.local'] as $envFile) {
    if (is_file($base . '/' . $envFile)) {
        Dotenv\Dotenv::createMutable($base, $envFile)->safeLoad();
    }
}
Config::load($base . '/app/Config');

$opts = getopt('', ['csv:', 'institute::', 'apply', 'help']);
if (isset($opts['help']) || !isset($opts['csv'])) {
    fwrite(STDERR, "Uso: php tools/curriculum/import_miur_adozioni.php --csv=<file> [--institute=CODE] [--apply]\n");
    exit(isset($opts['help']) ? 0 : 1);
}
$csvPath  = (string)$opts['csv'];
$onlyCode = isset($opts['institute']) ? strtoupper(trim((string)$opts['institute'])) : null;
$apply    = isset($opts['apply']);

// Decisioni gia' prese su come leggere certe descrizioni MIUR: quali indicano
// lo stesso indirizzo, e quali hanno una sigla scelta a mano perche' quella
// derivata sarebbe illeggibile. Vedi docs/curriculum/miur_alias.json.
$alias        = MiurCurriculumAlias::fromFile('indirizzi');
$aliasMaterie = MiurCurriculumAlias::fromFile('materie');
foreach (['indirizzi' => $alias, 'materie' => $aliasMaterie] as $kind => $reg) {
    if ($reg->count() === 0) {
        continue;
    }
    printf("Alias %s: %d\n", $kind, $reg->count());
    foreach ($reg->unificazioni() as $codice => $descrizioni) {
        printf("  %s unifica: %s\n", $codice, implode(' · ', $descrizioni));
    }
}

$importer = new MiurAdozioniImporter(Database::connection(), $alias, $aliasMaterie);
try {
    $plan = $importer->scan($csvPath, $onlyCode);
} catch (Throwable $e) {
    fwrite(STDERR, 'ABORT: ' . $e->getMessage() . "\n");
    exit(1);
}

printf(
    "Righe lette: %s — scuole con corrispondenza: %d\n\n",
    number_format($plan['righe'], 0, ',', '.'),
    count($plan['istituti'])
);

$segno = static fn(string $stato): string => match ($stato) {
    'esistente', 'alias-unificato' => '=',
    'non-derivabile'               => '!',
    default                        => $apply ? '+' : '»',
};
$nota = static fn(string $stato): string => match ($stato) {
    'esistente'       => "(gia' a registro)",
    'alias-unificato' => '(alias, unificato con una voce esistente)',
    'alias-nuovo'     => '(alias)',
    'non-derivabile'  => 'codice non derivabile — inserire a mano',
    default           => '(nuovo)',
};

foreach ($plan['istituti'] as $inst) {
    printf("── %s  %s (id %d)\n", $inst['code'], $inst['name'], $inst['id']);
    foreach ($inst['indirizzi'] as $ind) {
        printf(
            "   %s %-46s %-6s %s\n",
            $segno((string)$ind['stato']),
            substr((string)$ind['descrizione'], 0, 46),
            (string)($ind['code'] ?? ''),
            $nota((string)$ind['stato'])
        );
        if ($ind['sezioni_nuove'] !== []) {
            printf("        sezioni nuove:    %s\n", implode(' ', $ind['sezioni_nuove']));
        }
        if ($ind['sezioni_sistemate'] !== []) {
            printf("        indirizzo aggiunto a: %s\n", implode(' ', $ind['sezioni_sistemate']));
        }
    }
    if (($inst['materie'] ?? []) !== []) {
        echo "   materie:\n";
        foreach ($inst['materie'] as $m) {
            printf(
                "   %s %-46s %-6s %s\n",
                $segno((string)$m['stato']),
                substr((string)$m['descrizione'], 0, 46),
                (string)($m['code'] ?? ''),
                $nota((string)$m['stato'])
            );
        }
    }
    echo "\n";
}

$s = $plan['stats'];
if ($apply) {
    try {
        $fatte = $importer->apply($plan);
    } catch (Throwable $e) {
        fwrite(STDERR, 'ABORT in scrittura (rollback eseguito): ' . $e->getMessage() . "\n");
        exit(1);
    }
    printf(
        "APPLICATO — indirizzi: %d · sezioni: %d · materie: %d · sezioni sistemate: %d\n",
        $fatte['indirizzi'],
        $fatte['sezioni'],
        $fatte['materie'],
        $fatte['sistemate']
    );
    exit(0);
}

printf(
    "ANTEPRIMA (nessuna modifica) — indirizzi: %d · sezioni: %d · materie: %d · sezioni sistemate: %d\n",
    $s['indirizzi'],
    $s['sezioni'],
    $s['materie'],
    $s['sistemate']
);
if (($s['indirizzi'] + $s['sezioni'] + $s['materie'] + $s['sistemate']) > 0) {
    echo "Per scrivere: rilancia con --apply\n";
    echo "I codici degli indirizzi sono proposte: correggili PRIMA che i docenti\n";
    echo "ci aggancino contenuti, dopo diventa una migrazione.\n";
}
