<?php

declare(strict_types=1);

/**
 * Dice al sistema che un'anomalia è stata guardata.
 *
 * ── Perché esiste ─────────────────────────────────────────────────────────
 *
 * La diagnostica guarda le ultime ventiquattr'ore del registro delle
 * anomalie. Quindi dopo **qualunque** anomalia vera resta rossa per un giorno
 * intero e manda una mail a ogni giro — alle 07:00 e alle 19:00 — anche
 * quando il problema è già stato guardato e risolto mezz'ora dopo.
 *
 * Non è un fastidio, è un difetto. Un allarme che continua a suonare dopo la
 * riparazione insegna a spegnere l'allarme, e questa è precisamente la forma
 * di guasto contro cui è stato costruito tutto il resto: il 9 settembre 2026
 * abbiamo trovato un controllo d'integrità che diceva «completato» da
 * centoundici notti senza aver mai esaminato un file, e nessuno se n'era
 * accorto perché nessuno leggeva più quel registro.
 *
 * Il caso concreto che l'ha reso necessario: `unattended-upgrades` è attivo
 * sul VPS, ogni pacchetto aggiornato cambia file sotto `/usr` — che AIDE
 * sorveglia — e quindi circa una volta a settimana arriva un
 * `aide_differenze` **vero e atteso**. Senza un modo di dire «visto», in un
 * mese quelle mail smettono di essere aperte.
 *
 * ── Perché non è un modo di zittire ───────────────────────────────────────
 *
 * Si segna un **livello d'acqua** per codice: l'istante dell'ultima
 * occorrenza guardata. Una nuova occorrenza dello stesso codice, successiva a
 * quell'istante, torna a suonare.
 *
 * Niente viene cancellato: le righe restano tutte nel registro, e la
 * diagnostica scrive nel suo resoconto quante ne ha considerate già viste. Un
 * resoconto che dice «zero nuove, tre già viste il 9/9 alle 13:40» è
 * un'informazione; farle sparire e basta sarebbe un'altra cosa.
 *
 * Zittire vuol dire non vedere più. Questo vuol dire aver visto.
 *
 * ── Uso ───────────────────────────────────────────────────────────────────
 *
 *   PANTEDU_DATA_PATH=<dati> php tools/ops/visto.php                  # cosa c'è di nuovo
 *   PANTEDU_DATA_PATH=<dati> php tools/ops/visto.php --stato          # i livelli d'acqua attuali
 *
 *   PANTEDU_DATA_PATH=<dati> php tools/ops/visto.php aide_differenze \
 *       --perche="le otto differenze sono l'aggiornamento di unattended-upgrades del 21/9"
 *   PANTEDU_DATA_PATH=<dati> php tools/ops/visto.php --tutto \
 *       --perche="..."
 *
 * ── La motivazione è obbligatoria (2026-09-22) ───────────────────────────
 *
 * Fino a quel giorno bastava il codice. Restavano scritti il codice, l'istante
 * e il nome di chi segnava: si sapeva *che* qualcuno aveva guardato, non *che
 * cosa aveva visto*. Sei mesi dopo, davanti a un livello d'acqua, non c'è modo
 * di sapere se sotto c'era un aggiornamento di pacchetti o un ingresso che
 * nessuno sa spiegare — e la regola di progetto dice che marcare un'anomalia
 * come vista è legittimo solo dopo aver guardato ogni differenza una per una,
 * **e va scritto perché**.
 *
 * Senza `--perche` non si scrive niente e l'anomalia continua a suonare.
 * L'attrito è voluto: scrivere una frase costa meno che guardare, e chi ha
 * davvero guardato quella frase ce l'ha già.
 *
 * `<dati>` è la cartella dei dati d'istanza: la diagnostica la scrive nel suo
 * messaggio, con il comando già pronto. Senza `.env.local` accanto (una copia
 * di sviluppo senza segreti) la variabile si può omettere: i dati stanno nel
 * repository, come per l'applicazione.
 *
 * Uscita: 0 sempre, tranne quando non riesce a scrivere (1) o quando non sa
 * dove sta il registro (2). Elencare non è un errore nemmeno quando non c'è
 * niente da elencare.
 *
 * ── Niente segreti (2026-09-14) ──────────────────────────────────────────
 *
 * Fino al 14 settembre 2026 lo script partiva da `app/bootstrap.php`, che
 * carica `.env` e `.env.local`. Sul VPS auditd annota ogni lettura di
 * `.env.local` fatta da una sessione interattiva, e il giro di AIDE della notte
 * dopo la trasforma in un'anomalia `audit_segreti_letti`: segnare
 * un'anomalia come vista ne produceva un'altra, e il resoconto non tornava mai
 * pulito. Misurato quel giorno: `php tools/ops/visto.php audit_segreti_letti`
 * compare nel registro di auditd fra le letture da segnalare.
 *
 * Per fare il suo lavoro basta il registro delle anomalie. Quindi l'autoload,
 * la configurazione con la cartella dei dati presa dall'ambiente del processo,
 * e nient'altro: nessun file d'ambiente, nessun database. Se la variabile
 * manca e accanto c'è un `.env.local`, la cartella giusta la conosce solo quel
 * file: ci si ferma e si dice come lanciarlo, invece di leggerlo o di lavorare
 * in silenzio sul registro sbagliato (in produzione i dati non stanno nel
 * repository, e un registro vuoto risponderebbe «niente di nuovo»).
 */

$base = \dirname(__DIR__, 2);
require $base . '/vendor/autoload.php';

$dati = getenv('PANTEDU_DATA_PATH');
if ($dati === false || $dati === '') {
    if (file_exists($base . '/.env.local')) {
        fwrite(STDERR, "visto.php non legge .env.local: da una sessione interattiva, sul VPS quella lettura\n"
            . "diventa l'allarme della notte dopo. Dagli la cartella dei dati, quella che la\n"
            . "diagnostica scrive nel suo messaggio:\n\n"
            . "  PANTEDU_DATA_PATH=<cartella dei dati> php tools/ops/visto.php ...\n");
        exit(2);
    }
} else {
    if (!is_dir($dati . '/storage/logs')) {
        fwrite(STDERR, "In {$dati}/storage/logs non c'è niente: la cartella dei dati è sbagliata?\n"
            . "Non ne creo una nuova: un registro vuoto risponderebbe «niente di nuovo».\n");
        exit(2);
    }
    $_ENV['PANTEDU_DATA_PATH'] = $dati;
}
\App\Core\Config::load($base . '/app/Config');

use App\Support\Anomalia;

$argomenti = \array_slice($argv, 1);
$tutto  = \in_array('--tutto', $argomenti, true);
$stato  = \in_array('--stato', $argomenti, true);
$codici = array_values(array_filter(
    $argomenti,
    static fn(string $a): bool => !str_starts_with($a, '--'),
));

$perche = '';
foreach ($argomenti as $a) {
    if (str_starts_with($a, '--perche=')) {
        $perche = trim(substr($a, \strlen('--perche=')));
    }
}

$visti = Anomalia::vistiFinoA();

if ($stato) {
    if ($visti === []) {
        echo "Nessun livello d'acqua segnato: tutto quello che c'è nel registro conta come nuovo.\n";
        exit(0);
    }
    echo "Livelli d'acqua (una nuova occorrenza dopo questi istanti torna a suonare):\n\n";
    $perEsteso = Anomalia::vistiPerEsteso();
    foreach ($visti as $codice => $finoA) {
        printf("  %-30s fino a %s\n", $codice, $finoA);
        $voce = $perEsteso[$codice] ?? null;
        if ($voce === null) {
            continue;
        }
        if ($voce['da'] !== '') {
            printf("  %-30s da %s il %s\n", '', $voce['da'], $voce['segnato']);
        }
        // Una voce senza motivazione è anteriore al 22/9/2026: si dice, invece
        // di lasciare una riga vuota che sembra una motivazione scritta male.
        printf(
            "  %-30s perché: %s\n",
            '',
            $voce['perche'] !== '' ? $voce['perche'] : '(non registrata: segnata prima del 22/9/2026)',
        );
        foreach ($voce['storico'] as $vecchia) {
            printf("  %-30s   prima: %s — %s\n", '', $vecchia['segnato'] ?? '', $vecchia['perche'] ?? '');
        }
    }
    echo "\nIl file: " . Anomalia::percorsoVisti() . "\n";
    exit(0);
}

// Le anomalie delle ultime ventiquattr'ore, con le stesse due regole della
// diagnostica: fuori quelle che scrive la diagnostica stessa (le rivaluta a
// ogni giro), e fuori quelle già segnate come viste.
$recenti = array_values(array_filter(
    Anomalia::recenti(86400),
    static fn(array $v): bool => !str_starts_with((string)($v['codice'] ?? ''), 'diagnostica_'),
));

/** @var array<string, array{quante: int, ultima: string}> $perCodice */
$perCodice = [];
$giaViste  = [];
foreach ($recenti as $voce) {
    $codice = (string)($voce['codice'] ?? '?');
    $quando = (string)($voce['quando'] ?? '');
    $soglia = $visti[$codice] ?? null;
    if ($soglia !== null && (strtotime($quando) ?: 0) <= (strtotime($soglia) ?: 0)) {
        $giaViste[$codice] = ($giaViste[$codice] ?? 0) + 1;
        continue;
    }
    if (!isset($perCodice[$codice])) {
        $perCodice[$codice] = ['quante' => 0, 'ultima' => $quando];
    }
    $perCodice[$codice]['quante'] += 1 + (int)($voce['saltate'] ?? 0);
    if ((strtotime($quando) ?: 0) > (strtotime($perCodice[$codice]['ultima']) ?: 0)) {
        $perCodice[$codice]['ultima'] = $quando;
    }
}

// ── Solo elenco ───────────────────────────────────────────────────────────

if ($codici === [] && !$tutto) {
    if ($perCodice === []) {
        echo "Niente di nuovo nelle ultime 24 ore.\n";
        if ($giaViste !== []) {
            echo "\nGià segnate come viste:\n";
            foreach ($giaViste as $c => $n) {
                printf("  %-30s ×%d\n", $c, $n);
            }
        }
        exit(0);
    }
    echo "Anomalie non ancora guardate (ultime 24 ore):\n\n";
    foreach ($perCodice as $codice => $d) {
        printf("  %-30s ×%-4d ultima: %s\n", $codice, $d['quante'], $d['ultima']);
    }
    echo "\nDettagli: " . Anomalia::percorso() . "\n";
    $comando = ($dati === false || $dati === '' ? '' : "PANTEDU_DATA_PATH={$dati} ") . 'php tools/ops/visto.php';
    echo "Per segnarle come viste, dopo averle guardate una per una:\n";
    echo "  {$comando} --tutto --perche=\"<che cosa hai visto>\"\n";
    echo "Oppure una alla volta:\n";
    echo "  {$comando} <codice> --perche=\"<che cosa hai visto>\"\n";
    exit(0);
}

// ── Segna ─────────────────────────────────────────────────────────────────

$daSegnare = $tutto ? array_keys($perCodice) : $codici;

if ($daSegnare === []) {
    echo "Niente da segnare: non ci sono anomalie nuove nelle ultime 24 ore.\n";
    exit(0);
}

// La motivazione si controlla PRIMA di scrivere qualunque cosa: un comando che
// segnasse metà dei codici e poi si fermasse lascerebbe uno stato peggiore di
// quello di partenza, perché la metà segnata smette di suonare.
if (mb_strlen($perche) < Anomalia::MOTIVO_MINIMO) {
    fwrite(STDERR, "Manca il perché, e senza non segno niente.\n\n"
        . "Segnare un'anomalia come vista è legittimo solo dopo aver guardato ogni\n"
        . "differenza una per una, e quello che hai visto va scritto: fra sei mesi,\n"
        . "davanti a questo livello d'acqua, è l'unica cosa che resterà.\n\n"
        . "  php tools/ops/visto.php " . implode(' ', $daSegnare)
        . " --perche=\"le otto differenze sono l'aggiornamento di unattended-upgrades\"\n\n"
        . 'Almeno ' . Anomalia::MOTIVO_MINIMO . " caratteri.\n");
    exit(1);
}

$chi = trim((string)(getenv('SUDO_USER') ?: getenv('USER') ?: 'cli'));
$errori = 0;

foreach ($daSegnare as $codice) {
    if (!isset($perCodice[$codice])) {
        // Segnare un codice che non è presente non è un errore — si può
        // volerlo fare in anticipo — ma va detto, perché il caso normale in
        // cui succede è un codice scritto male.
        fwrite(STDERR, "  attenzione: «{$codice}» non compare fra le anomalie nuove delle ultime 24 ore.\n");
        continue;
    }
    $finoA = $perCodice[$codice]['ultima'];
    if (Anomalia::segnaVisto($codice, $finoA, $chi, $perche)) {
        printf("  segnato: %-30s fino a %s\n", $codice, $finoA);
    } else {
        fwrite(STDERR, "  NON riuscito a scrivere per «{$codice}»: controllare i permessi di "
            . Anomalia::percorsoVisti() . "\n");
        $errori++;
    }
}

if ($errori > 0) {
    exit(1);
}

echo "\nUna nuova occorrenza di questi codici, dopo gli istanti qui sopra, tornerà a suonare.\n";
exit(0);
