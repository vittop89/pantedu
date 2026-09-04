<?php

declare(strict_types=1);

/**
 * Le migration registrate come eseguite hanno prodotto davvero qualcosa?
 *
 * PERCHE' ESISTE
 *   schema_migrations dice cosa e' stato ESEGUITO, non cosa ha FUNZIONATO. La
 *   038 risulta eseguita dal 16 maggio, ma i suoi dieci trigger non esistono in
 *   nessun database. Nessuno se n'era accorto perche' non c'era modo di
 *   accorgersene: il registro delle migration e' una lista di nomi, non di
 *   effetti.
 *
 *   Il caso della 038 e' anche istruttivo sul come: quel file usa DELIMITER,
 *   che e' una direttiva del client mysql e non SQL. Il Migrator spezza i file
 *   sui ";" e non la conosce, quindi il corpo dei trigger gli si sbriciola in
 *   77 frammenti. Chi scrive una migration con dentro un trigger scritto in
 *   quel modo oggi ottiene lo stesso risultato.
 *
 * COSA CONTROLLA
 *   Legge cosa ogni migration DICHIARA di creare e verifica che ci sia:
 *   tabelle, viste, trigger, colonne aggiunte, indici. Non e' un parser SQL
 *   completo e non finge di esserlo — quello che non riesce a interpretare lo
 *   dice, invece di tacere e sembrare verde.
 *
 * COSA NON CONTROLLA
 *   Le migration che spostano DATI (UPDATE, INSERT, DELETE): il loro effetto
 *   non e' deducibile dallo schema. Vengono contate a parte.
 *
 * USO
 *   php tools/dev/check_migrations_applied.php            solo i problemi
 *   php tools/dev/check_migrations_applied.php --tutte    anche quelle a posto
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

$tutte = in_array('--tutte', $argv, true);
$pdo   = Database::connection();
$dir   = $base . '/database/migrations';

// ── cosa c'e' davvero nel database ────────────────────────────────────────
$q = static fn(string $sql): array => array_map(
    'strtolower',
    $GLOBALS['pdo']->query($sql)->fetchAll(PDO::FETCH_COLUMN)
);
$GLOBALS['pdo'] = $pdo;

$tabelle  = array_flip($q("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"));
$trigger  = array_flip($q("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()"));
$colonne  = [];
foreach ($pdo->query(
    "SELECT LOWER(TABLE_NAME), LOWER(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
)->fetchAll(PDO::FETCH_NUM) as [$t, $c]) {
    $colonne[$t . '.' . $c] = true;
}
$indici = [];
foreach ($pdo->query(
    "SELECT LOWER(TABLE_NAME), LOWER(INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()"
)->fetchAll(PDO::FETCH_NUM) as [$t, $i]) {
    $indici[$t . '.' . $i] = true;
}

// ── quali migration risultano eseguite ────────────────────────────────────
$eseguite = [];
try {
    foreach ($pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN) as $f) {
        $eseguite[(string)$f] = true;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "ABORT: non leggo schema_migrations: " . $e->getMessage() . "\n");
    exit(1);
}

$files = glob($dir . '/*.sql') ?: [];
sort($files);

// ── cio' che una migration SUCCESSIVA ha eliminato ────────────────────────
// Senza questo il controllo grida al lupo: la 021 crea verifica_templates, la
// 032 la elimina, e cercarla oggi la fa sembrare mai creata. Sono tre casi su
// quattro nel primo giro — un controllo cosi' non lo guarda piu' nessuno.
$eliminatoDopo = [];
foreach ($files as $path) {
    $chiave = basename($path);
    $puro = (string)preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($path));
    foreach ([
        '/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?/i',
        '/DROP\s+VIEW\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?/i',
        '/DROP\s+TRIGGER\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?/i',
        '/DROP\s+INDEX\s+`?(\w+)`?/i',
        '/DROP\s+(?:COLUMN\s+)?`?(\w+)`?/i',
        '/RENAME\s+TABLE\s+`?(\w+)`?/i',
    ] as $re) {
        if (preg_match_all($re, $puro, $m)) {
            foreach ($m[1] as $nomeOggetto) {
                $eliminatoDopo[strtolower($nomeOggetto)][] = $chiave;
            }
        }
    }
}
/** Un oggetto dichiarato da $da e' stato eliminato da una migration successiva? */
$eliminatoDopoDi = static function (string $oggetto, string $da) use ($eliminatoDopo): bool {
    foreach ($eliminatoDopo[strtolower($oggetto)] ?? [] as $quale) {
        if (strcmp($quale, $da) > 0) {
            return true;
        }
    }
    return false;
};

// Dopo il refactor `_data` + vista, indici e colonne vivono sulla tabella
// `x_data` mentre `x` e' diventata una vista: cercarli su `x` non li trova.
$tabellaReale = static function (string $t) use ($tabelle): string {
    return isset($tabelle[strtolower($t) . '_data']) ? strtolower($t) . '_data' : strtolower($t);
};

$conProblemi = 0;
$soloDati = 0;
$nonInterpretabili = [];

foreach ($files as $path) {
    $nome = basename($path);
    if (!isset($eseguite[$nome])) {
        continue; // non ancora eseguita: non e' un problema
    }
    $sql = (string)file_get_contents($path);
    // Via i commenti, o si scambiano gli esempi per dichiarazioni.
    $puro = (string)preg_replace('/^\s*--.*$/m', '', str_replace("\r\n", "\n", $sql));

    $mancano = [];
    $dichiarati = 0;

    // Tabelle e viste
    if (preg_match_all('/CREATE\s+(?:OR\s+REPLACE\s+)?(?:TABLE|VIEW)\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $puro, $m)) {
        foreach ($m[1] as $t) {
            $dichiarati++;
            if (!isset($tabelle[strtolower($t)]) && !$eliminatoDopoDi($t, $nome)) {
                $mancano[] = "tabella/vista $t";
            }
        }
    }
    // Trigger
    if (preg_match_all('/CREATE\s+TRIGGER\s+`?(\w+)`?/i', $puro, $m)) {
        foreach ($m[1] as $t) {
            $dichiarati++;
            if (!isset($trigger[strtolower($t)]) && !$eliminatoDopoDi($t, $nome)) {
                $mancano[] = "trigger $t";
            }
        }
    }
    // Colonne aggiunte: ALTER TABLE x ADD COLUMN y
    if (preg_match_all('/ALTER\s+TABLE\s+`?(\w+)`?\s+(?:[^;]*?)ADD\s+(?:COLUMN\s+)?`?(\w+)`?\s+(?:INT|BIGINT|VARCHAR|TEXT|DATETIME|TIMESTAMP|TINYINT|DECIMAL|ENUM|JSON|CHAR|DATE|SMALLINT|MEDIUMTEXT|LONGTEXT|BLOB|FLOAT|DOUBLE)/i', $puro, $m, PREG_SET_ORDER)) {
        foreach ($m as $set) {
            $dichiarati++;
            $reale  = $tabellaReale($set[1]);
            $chiave = $reale . '.' . strtolower($set[2]);
            // Se la tabella non esiste piu' non ha senso lamentarsi della colonna.
            if (isset($tabelle[$reale]) && !isset($colonne[$chiave]) && !$eliminatoDopoDi($set[2], $nome)) {
                $mancano[] = "colonna {$set[1]}.{$set[2]}";
            }
        }
    }
    // Indici con nome esplicito
    if (preg_match_all('/CREATE\s+(?:UNIQUE\s+)?INDEX\s+`?(\w+)`?\s+ON\s+`?(\w+)`?/i', $puro, $m, PREG_SET_ORDER)) {
        foreach ($m as $set) {
            $dichiarati++;
            $reale  = $tabellaReale($set[2]);
            $chiave = $reale . '.' . strtolower($set[1]);
            if (isset($tabelle[$reale]) && !isset($indici[$chiave]) && !$eliminatoDopoDi($set[1], $nome)) {
                $mancano[] = "indice {$set[2]}.{$set[1]}";
            }
        }
    }

    // DELIMITER: il Migrator non lo conosce e il file gli si sbriciola.
    $conDelimiter = (bool)preg_match('/^\s*DELIMITER\b/mi', $puro);

    if ($dichiarati === 0) {
        $soloDati++;
        if ($tutte) {
            printf("  ~ %-52s nessun oggetto di schema da verificare\n", $nome);
        }
        continue;
    }
    if ($mancano === []) {
        if ($tutte) {
            printf("  ✓ %-52s %d oggetti, tutti presenti\n", $nome, $dichiarati);
        }
        continue;
    }

    $conProblemi++;
    printf("\n  ✗ %s\n", $nome);
    printf("      dichiara %d oggetti, ne mancano %d:\n", $dichiarati, count($mancano));
    foreach (array_slice($mancano, 0, 12) as $x) {
        printf("        · %s\n", $x);
    }
    if (count($mancano) > 12) {
        printf("        · … e altri %d\n", count($mancano) - 12);
    }
    if ($conDelimiter) {
        echo "      NB: il file usa DELIMITER, che il Migrator non conosce — il\n";
        echo "          contenuto viene spezzato sui \";\" e non arriva mai al database.\n";
        echo "          I trigger vanno creati da uno strumento PHP (vedi\n";
        echo "          tools/curriculum/apply_no_orphan_guard.php).\n";
    }
}

printf("\n%s\n", str_repeat('─', 60));
printf("Migration eseguite esaminate: %d\n", count($eseguite));
printf("  con oggetti mancanti:       %d\n", $conProblemi);
printf("  senza oggetti di schema:    %d  (spostano dati: non verificabile qui)\n", $soloDati);
if ($conProblemi === 0) {
    echo "\nOgni migration eseguita ha lasciato dietro di se' cio' che dichiarava.\n";
}
