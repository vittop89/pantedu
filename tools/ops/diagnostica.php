<?php

declare(strict_types=1);

/**
 * Chiede al sistema di dimostrare che sta funzionando davvero.
 *
 * Perché esiste. I guasti dell'8 e 9 settembre 2026 avevano tutti la stessa
 * forma: **qualcosa non funzionava e il fallimento assomigliava al normale**.
 *
 *   - Un controllo di sicurezza in CI verde da mesi che non scansionava niente
 *     (una regola malformata faceva uscire semgrep; l'azione restituiva
 *     «riuscito» lo stesso).
 *   - Quattro funzioni che rispondevano 403 da mesi perché il client mandava
 *     un gettone CSRF vuoto — leggeva un `<meta>` che nessuna vista emette. Un
 *     403 sembra un blocco riuscito, non un difetto.
 *   - Percorsi GeoIP configurati verso file inesistenti: il blocco geografico
 *     spento per quattro minuti senza un rigo di registro.
 *   - `/version` che dichiarava un commit e ne serviva un altro.
 *   - Un `trap` che leggeva il codice d'uscita di `date` invece che del
 *     comando: «FINE, tutto bene» mentre l'unità era in `failed`.
 *   - `git reset --hard` che toglieva a `.env` i permessi di www-data:
 *     l'applicazione partiva e diceva «database disabilitato da configurazione».
 *
 * Nessuno di questi si vede guardando se il sito risponde. Si vedono solo
 * chiedendo a ogni pezzo di **dimostrare** che è nello stato che dichiara.
 *
 * Ogni controllo è un invariante con un nome, e la risposta porta la prova:
 * non «geoip ok» ma «geoip: il file configurato esiste, 6.4 MB, letto il ...».
 * Una diagnosi senza prova è la stessa cosa di un verde che non misura niente.
 *
 * Uso:
 *   php tools/ops/diagnostica.php              # tutti i controlli
 *   php tools/ops/diagnostica.php --json       # per un'altra macchina
 *   php tools/ops/diagnostica.php --solo=csrf,geoip
 *   php tools/ops/diagnostica.php --solo=unita --unita-installate=<cartella>
 *
 * Uscita: 0 se tutto regge, 1 se almeno un controllo fallisce, 2 se non è
 * riuscito nemmeno a partire. Agganciato a systemd con
 * `OnFailure=pantedu-avviso@%n.service`, un'uscita diversa da zero diventa
 * una mail (tools/ops/avvisa_guasto.php).
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Router;
use App\Services\TexCompile\ControlloTex;
use App\Services\TexCompile\TexCompileClient;
use App\Support\Anomalia;

$soloRichiesti = [];
$comeJson = false;
// Dove guardare le unità installate (controllo `unita`). Senza, è
// /etc/systemd/system; la cartella si passa per le prove, che non hanno un
// systemd da confrontare. Un'opzione e non una variabile d'ambiente: `.env.local`
// si carica mutabile e scavalcherebbe l'ambiente del processo.
$unitaInstallate = null;
foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $comeJson = true;
    } elseif (str_starts_with($arg, '--solo=')) {
        $soloRichiesti = array_filter(explode(',', substr($arg, 7)));
    } elseif (str_starts_with($arg, '--unita-installate=')) {
        $unitaInstallate = substr($arg, \strlen('--unita-installate='));
    }
}

/** @var list<array{nome: string, esito: string, prova: string}> $esiti */
$esiti = [];

/**
 * La diagnostica non può morire in silenzio.
 *
 * Provata sul VPS il 9 settembre 2026 prima che il ramo fosse unito, si è
 * fermata con un errore fatale — `App\Support\Anomalia` non ancora presente —
 * e ha stampato **niente**: nessun controllo, nessun messaggio, solo un codice
 * 255. Lo strumento il cui mestiere è rendere visibili i guasti silenziosi era
 * diventato lui stesso un guasto silenzioso.
 *
 * Questa rete stampa quello che si è raccolto fino a quel punto e dice cosa ha
 * interrotto il giro. Un resoconto parziale con la causa scritta vale molto più
 * di uno schermo vuoto: dice quali invarianti sono stati verificati e quale
 * riga ha fermato tutto.
 */
register_shutdown_function(static function (): void {
    $ultimo = error_get_last();
    if ($ultimo === null || !\in_array($ultimo['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    global $esiti;
    fwrite(STDERR, "\nLa diagnostica si è interrotta prima di finire.\n\n");
    foreach ($esiti as $e) {
        fwrite(STDERR, sprintf("  %-6s %-12s %s\n", $e['esito'], $e['nome'], $e['prova']));
    }
    fwrite(STDERR, sprintf(
        "\n  INTERROTTA da: %s\n  in %s:%d\n\n",
        $ultimo['message'],
        $ultimo['file'],
        $ultimo['line'],
    ));
});

/**
 * Registra l'esito di un controllo.
 *
 * `esito` vale 'regge', 'guasto' o 'non_applicabile'. Il terzo non è una via
 * di fuga: si usa solo quando la cosa da controllare **non esiste in questo
 * assetto** (es. GeoIP non configurato), e la prova deve dirlo.
 */
function annota(string $nome, string $esito, string $prova): void
{
    global $esiti;
    $esiti[] = ['nome' => $nome, 'esito' => $esito, 'prova' => $prova];
}

function daFare(string $nome): bool
{
    global $soloRichiesti;
    return $soloRichiesti === [] || \in_array($nome, $soloRichiesti, true);
}

/**
 * Vero se questa è un'installazione vera, falso su una macchina di sviluppo.
 *
 * Non si guarda `APP_ENV`, che vale 'production' anche in locale perché è il
 * valore predefinito e nessuno lo cambia mai: un controllo che si fida di una
 * dichiarazione si fa ingannare da una dichiarazione sbagliata. Si guarda
 * **dove stanno i dati**. In produzione `PANTEDU_DATA_PATH` li porta fuori dal
 * repository; in locale restano dentro. È una differenza di struttura, e per
 * questo non si può sbagliare per distrazione.
 */
function installazioneVera(): bool
{
    static $esito = null;
    if ($esito === null) {
        $esito = !str_starts_with(
            (string)Config::get('app.paths.data_base'),
            rtrim((string)Config::get('app.paths.base'), '/\\'),
        );
    }
    return $esito;
}

/**
 * Chi sta girando, per le prove che dipendono dall'utente: «scrivibile» da
 * `pantedu` sull'host non vuol dire scrivibile da `www-data` nel container.
 */
function chiGira(): string
{
    if (\function_exists('posix_geteuid')) {
        $utente = posix_getpwuid(posix_geteuid());
        if (\is_array($utente)) {
            return (string)$utente['name'];
        }
    }
    return (string)(getenv('USER') ?: 'questo processo');
}

// ── versione ─────────────────────────────────────────────────────────────
// Il commit che l'applicazione dichiara deve essere quello che sta servendo.
// `/version` ha già mentito una volta: nell'immagine il file non veniva
// scritto al build, e l'endpoint leggeva un valore vecchio.
if (daFare('versione')) {
    $repo = (string)Config::get('app.paths.base');

    // `app.paths.base . '/storage'`, non `app.paths.storage`.
    //
    // Sono due alberature diverse: la seconda è quella dei dati
    // (`PANTEDU_DATA_PATH`), la prima è dentro il repository — ed è lì che
    // `HealthController::shaFromFile()` legge, e lì che il build
    // dell'immagine scrive. Guardare nell'altra vuol dire non trovare mai
    // niente e gridare al guasto ogni dodici ore.
    //
    // Scritto sbagliato al primo giro, trovato provando la diagnostica
    // dentro il container vero il 9 settembre 2026: diceva «/version ha
    // mentito» mentre `/version` rispondeva correttamente. Un controllo che
    // sbaglia posto è peggio di un controllo che non c'è: insegna a non
    // credere ai controlli.
    $fileVersione = $repo . '/storage/version.txt';
    $dichiarato = is_file($fileVersione) ? trim((string)file_get_contents($fileVersione)) : '';

    if ($dichiarato === '' && !installazioneVera()) {
        annota('versione', 'non_applicabile', 'version.txt lo scrive il rilascio; '
            . 'qui i dati stanno dentro il repository, quindi è una macchina di '
            . 'sviluppo e il file non c’è. Normale.');
    } elseif ($dichiarato === '') {
        annota('versione', 'guasto', "$fileVersione non esiste o è vuoto: "
            . "l'applicazione non sa quale commit sta servendo, e nemmeno chi la osserva. "
            . 'È così che /version ha mentito il 9 settembre 2026.');
    } elseif (is_dir($repo . '/.git')) {
        $vero = trim((string)@shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse HEAD 2>/dev/null'));
        if ($vero === '') {
            annota('versione', 'regge', "dichiarato $dichiarato; git non interrogabile da qui, "
                . 'confronto saltato.');
        } elseif (!str_starts_with($vero, $dichiarato) && !str_starts_with($dichiarato, $vero)) {
            annota('versione', 'guasto', "version.txt dice $dichiarato, il repository è a "
                . substr($vero, 0, 12) . '. Il codice servito non è quello che si crede.');
        } else {
            annota('versione', 'regge', 'dichiarato e servito coincidono: ' . substr($dichiarato, 0, 12));
        }
    } else {
        // Nel container il repository non c'è: `version.txt` è scritto al build.
        annota('versione', 'regge', "dichiarato $dichiarato (nessun repository qui: "
            . 'assetto a container, il file lo scrive il build).');
    }
}

// ── geoip ────────────────────────────────────────────────────────────────
// Configurato-ma-mancante è il caso peggiore: il codice crede di filtrare e
// non filtra. Configurato-e-assente è invece una scelta legittima.
if (daFare('geoip')) {
    $trovatiGuasti = [];
    $descrizioni = [];
    foreach (['geoip_db' => 'paese', 'geoip_asn_db' => 'ASN'] as $chiave => $etichetta) {
        $percorso = Config::get("waf.$chiave");
        if (!is_string($percorso) || trim($percorso) === '') {
            $descrizioni[] = "$etichetta: non configurato (filtro spento di proposito)";
            continue;
        }
        if (!is_file($percorso)) {
            $trovatiGuasti[] = "$etichetta: configurato su `$percorso`, che non esiste. "
                . 'Il filtro è spento e nessuno lo sa.';
            continue;
        }
        if (!is_readable($percorso)) {
            $trovatiGuasti[] = "$etichetta: `$percorso` esiste ma non è leggibile "
                . 'dall’utente del web.';
            continue;
        }
        $eta = (int)round((time() - (int)filemtime($percorso)) / 86400);
        $descrizioni[] = sprintf(
            '%s: %s, %.1f MB, aggiornato %d giorni fa',
            $etichetta,
            basename($percorso),
            filesize($percorso) / 1048576,
            $eta,
        );
        // Quarantacinque giorni, non novanta. L'aggiornamento è **mensile**
        // (`/etc/cron.d/dbip-geoip-update`): con novanta si sarebbero dovuti
        // saltare tre giri prima di accorgersene. Con quarantacinque ne basta
        // uno mancato più qualche giorno.
        //
        // Non è un limite teorico: fra il 22 maggio e il 9 settembre 2026 il
        // filtro geografico ha lavorato su dati vecchi di centodieci giorni,
        // perché lo script scaricava in una cartella col nome vecchio del
        // progetto — uscendo zero, senza un errore.
        if ($eta > 45) {
            $trovatiGuasti[] = "$etichetta: il database ha $eta giorni, e "
                . "l'aggiornamento è mensile. Gli intervalli di indirizzi cambiano: "
                . 'su dati così vecchi le risposte sono indovinelli. Controlla '
                . '/etc/cron.d/dbip-geoip-update e dove scrive davvero.';
        }
    }
    $trovatiGuasti === []
        ? annota('geoip', 'regge', implode('; ', $descrizioni))
        : annota('geoip', 'guasto', implode(' | ', $trovatiGuasti));
}

// ── permessi ─────────────────────────────────────────────────────────────
// `git reset --hard` sul VPS ha già tolto a `.env` i permessi del gruppo del
// web: l'applicazione partiva e dichiarava «database disabilitato».
if (daFare('permessi')) {
    $guasti = [];
    $prove = [];

    $base = (string)Config::get('app.paths.base');
    foreach (['.env', '.env.local'] as $nome) {
        $f = $base . '/' . $nome;
        if (!is_file($f)) {
            continue;
        }
        if (!is_readable($f)) {
            $guasti[] = "$nome esiste ma questo processo non lo legge. Se succede "
                . 'anche al processo del web, la configurazione non si carica e '
                . "l'applicazione parte monca invece di fermarsi.";
        } else {
            $prove[] = "$nome leggibile (" . substr(sprintf('%o', fileperms($f)), -4) . ')';
        }
    }

    foreach (['storage' => 'scrivibile', 'logs' => 'scrivibile'] as $chiave => $_) {
        $d = (string)Config::get("app.paths.$chiave");
        if (!is_dir($d)) {
            $guasti[] = "la cartella `$chiave` ($d) non esiste.";
        } elseif (!is_writable($d)) {
            $guasti[] = "la cartella `$chiave` ($d) non è scrivibile: gli errori "
                . 'finiranno nel vuoto proprio quando servono.';
        } else {
            $prove[] = "$chiave scrivibile";
        }
    }

    $guasti === []
        ? annota('permessi', 'regge', implode(', ', $prove))
        : annota('permessi', 'guasto', implode(' | ', $guasti));
}

// ── database ─────────────────────────────────────────────────────────────
if (daFare('database')) {
    try {
        $pdo = Database::connection();
        $pdo->query('SELECT 1')->fetchColumn();

        // Si usano `discoverAll()` e `executedFilenames()` del Migrator vero —
        // non un confronto reinventato qui — così la diagnostica e il rilascio
        // non possono essere in disaccordo su cosa sia «applicata».
        //
        // Non `pending()`, che chiama `ensureTrackingTable()` e quindi vuole i
        // diritti per creare tabelle: questo controllo gira come `www-data`,
        // che quei diritti non ce li ha e non deve averli. Qui si legge e
        // basta.
        //
        // 2026-09-09 — quel «non ce li ha» descriveva lo stato voluto, non
        // quello reale: `pantedu_app` aveva `CREATE`, `DROP` e `ALTER` sul
        // database perché il pannello delle migrazioni eseguiva con la
        // connessione dell'applicazione. Adesso non piu', e il confronto qui
        // sotto usa `pendingSenzaCreare()` invece di rifarlo a mano — due
        // definizioni di «in sospeso» sono una di troppo.
        $migrator = new \App\Core\Migrator(
            $pdo,
            (string)Config::get('app.paths.base') . '/database/migrations',
        );
        $applicate = $migrator->executedFilenames();
        $inSospeso = $migrator->pendingSenzaCreare();

        if ($inSospeso === []) {
            annota('database', 'regge', 'risponde, ' . \count($applicate)
                . ' migrazioni applicate, nessuna in sospeso.');
        } else {
            annota('database', 'guasto', \count($inSospeso) . ' migrazioni non applicate ('
                . implode(', ', \array_slice($inSospeso, 0, 3))
                . (\count($inSospeso) > 3 ? ', …' : '')
                . '). Il codice si aspetta uno schema che il database non ha.');
        }
    } catch (\Throwable $e) {
        annota('database', 'guasto', 'non risponde: ' . $e->getMessage());
    }
}

// ── contratti ────────────────────────────────────────────────────────────
// Nel contratto ci sta la sorgente, non la pagina che il browser ne disegna.
// Il 18 settembre 2026 due esercizi della verifica 75 si sono ritrovati al
// posto delle figure il riquadro rosso di un render fallito e uno
// `<script type="text/tikz">` come testo: la sorgente TikZ è perduta e non si
// ricostruisce. Il difetto del serializzatore è corretto e il salvataggio ora
// rifiuta queste scritture; questo controllo guarda il risultato, non il
// codice — se un contratto ne porta, qualcuno l'ha scritto lo stesso.
if (daFare('contratti')) {
    try {
        $pdo = Database::connection();
        $storage = \App\Support\Storage\StorageFactory::default();
        $righe = $pdo->query(
            "SELECT id, metadata_json FROM teacher_content_data
              WHERE metadata_json LIKE '%contract_key%'"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $letti = 0;
        $illeggibili = 0;
        /** @var array<int, string> $rovinati id → primo marcatore trovato */
        $rovinati = [];
        foreach ($righe as $riga) {
            $meta = json_decode((string)($riga['metadata_json'] ?? '{}'), true);
            $chiave = \is_array($meta) ? (string)($meta['contract_key'] ?? '') : '';
            if ($chiave === '') {
                continue;
            }
            try {
                $dati = json_decode($storage->get($chiave), true);
            } catch (\Throwable) {
                $illeggibili++;
                continue;
            }
            if (!\is_array($dati)) {
                $illeggibili++;
                continue;
            }
            $letti++;
            $trovati = \App\Services\Contract\TestoDiPaginaResa::trova($dati);
            if ($trovati !== []) {
                $rovinati[(int)$riga['id']] = $trovati[0]['marcatore']
                . ' (' . count($trovati) . (count($trovati) === 1 ? ' punto' : ' punti') . ')';
            }
        }

        $coda = $illeggibili > 0 ? ", $illeggibili non leggibili" : '';
        if ($letti === 0) {
            annota('contratti', 'non_applicabile',
                'nessun contratto leggibile da qui (' . count($righe) . " righe con contract_key$coda).");
        } elseif ($rovinati !== []) {
            $elenco = [];
            foreach ($rovinati as $id => $che) {
                $elenco[] = "#$id: $che";
            }
            $n = count($rovinati);
            annota('contratti', 'guasto', ($n === 1 ? "1 contratto su $letti porta" : "$n contratti su $letti portano")
                . ' testo di pagina resa al posto della sorgente — ' . implode(', ', \array_slice($elenco, 0, 10))
                . (count($elenco) > 10 ? ', …' : '') . '. '
                . 'La sorgente di quelle figure è persa: si recupera da content_versions '
                . '(docs/ops/diagnostica.md, voce «contratti»).');
        } else {
            annota('contratti', 'regge', "$letti contratti letti$coda, nessuno porta il riquadro d'errore TikZ, "
                . 'uno <script type="text/tikz"> come testo, un <svg> reso o gli attributi data-tikz-* del client.');
        }
    } catch (\Throwable $e) {
        annota('contratti', 'guasto', $e->getMessage());
    }
}

// ── pubblicazioni ────────────────────────────────────────────────────────
// ADR-037. Dalla fase 1 alla 4c-1 le pubblicazioni principali le scrivevano i
// trigger dalle colonne della riga; dalla 4c-2 (migrazione 122) le scrive
// l'applicazione (App\Support\PostoPrincipale) e i trigger non ci sono più.
// Se una scrittura aggirasse l'applicazione, gli studenti vedrebbero cose
// diverse da quelle che il docente ha scelto, e nessun errore lo direbbe: la
// procedura con cui le migrazioni si verificano, richiamata qui ogni giorno,
// fallisce con i conteggi se una sola pubblicazione non torna. L'utente
// dell'applicazione ha EXECUTE sul database (misurato il 13/9/2026).
if (daFare('pubblicazioni')) {
    try {
        $pdo = Database::connection();
        $presenti = array_map('strval', $pdo->query(
            "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg\\_pub\\_%'"
        )->fetchAll(\PDO::FETCH_COLUMN));
        $esiste = static function (string $sql) use ($pdo): bool {
            return (int)$pdo->query($sql)->fetchColumn() > 0;
        };
        // I trigger che servono dipendono dagli oggetti che esistono, e si
        // attendono per nome, così il controllo vale prima e dopo ogni
        // migrazione: quelli della riga e delle verifiche finché c'è la loro
        // procedura di ricalcolo (117 e 119; le toglie la 122, ADR-037 fase
        // 4c-2); quelli dei bersagli finché c'è content_target_classes (la
        // toglie la 120). Senza la procedura, invece, non devono esserci: un
        // trigger rimasto chiamerebbe una procedura che non esiste, e ogni
        // scrittura della riga fallirebbe.
        $procedura = static fn(string $nome): bool => $esiste(
            "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = '$nome'"
        );
        $attesi = [];
        $vietati = [];
        foreach (['pub_ricalcola_contenuto' => ['trg_pub_tc_ai', 'trg_pub_tc_au'],
                  'pub_ricalcola_verifica'  => ['trg_pub_vd_ai', 'trg_pub_vd_au']] as $nome => $trigger) {
            if ($procedura($nome)) {
                array_push($attesi, ...$trigger);
            } else {
                array_push($vietati, ...$trigger);
            }
        }
        if ($esiste("SELECT COUNT(*) FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_target_classes'")) {
            array_push($attesi, 'trg_pub_ctc_ai', 'trg_pub_ctc_au', 'trg_pub_ctc_ad');
        }
        $mancanti = array_values(array_diff($attesi, $presenti));
        $rimasti = array_values(array_intersect($vietati, $presenti));
        if (!$procedura('pub_verifica_allineamento')) {
            annota('pubblicazioni', 'non_applicabile', 'la migrazione 117 non è applicata: nessuna pubblicazione da verificare.');
        } elseif ($mancanti !== []) {
            annota('pubblicazioni', 'guasto', 'trigger delle pubblicazioni mancanti: ' . implode(', ', $mancanti)
                . ' (presenti ' . count($presenti) . ' su ' . count($attesi) . '). '
                . 'Senza, le pubblicazioni smettono di seguire le righe in silenzio.');
        } elseif ($rimasti !== []) {
            annota('pubblicazioni', 'guasto', 'trigger rimasti senza la loro procedura: ' . implode(', ', $rimasti)
                . '. Ogni scrittura della riga fallirebbe: vanno tolti (migrazione 122).');
        } else {
            $st = $pdo->query('CALL pub_verifica_allineamento()');
            if ($st instanceof \PDOStatement) {
                $st->closeCursor();
            }
            $n = (int)$pdo->query('SELECT COUNT(*) FROM content_publications')->fetchColumn();
            annota('pubblicazioni', 'regge', "$n pubblicazioni, allineate; "
                . ($attesi !== [] ? count($attesi) . ' trigger presenti.' : "le scrive l'applicazione, nessun trigger (ADR-037, fase 4c)."));
        }
    } catch (\Throwable $e) {
        annota('pubblicazioni', 'guasto', $e->getMessage());
    }
}

// ── sezioni ──────────────────────────────────────────────────────────────
// Un contenuto che nessun pannello della barra può chiedere è, per chi lo ha
// scritto, un contenuto che non esiste: nessun errore, nessuna traccia, solo
// un elenco vuoto. Il 20/9/2026 erano nove, fermi da aprile — fra questi la
// verifica che l'utente cercava, e che alla fine ha chiesto di cancellare.
// Il perché sta in App\Services\Ops\ContenutiRaggiungibili.
if (daFare('sezioni')) {
    try {
        $fuori = \App\Services\Ops\ContenutiRaggiungibili::nelDatabase(Database::connection());
        if ($fuori === []) {
            $n = (int)Database::connection()->query(
                "SELECT COUNT(*) FROM teacher_content WHERE visibility <> 'archived'"
            )->fetchColumn();
            annota('sezioni', 'regge', "$n contenuti, ognuno raggiungibile da almeno una domanda della barra.");
        } else {
            $primi = array_slice($fuori, 0, 5);
            $elenco = implode(', ', array_map(
                static fn(array $r): string => $r['id'] . ' (' . $r['content_type'] . ')',
                $primi
            ));
            annota('sezioni', 'guasto', count($fuori) . ' contenuti che nessun pannello può chiedere: '
                . $elenco . (count($fuori) > 5 ? ', …' : '') . '. '
                . 'Primo motivo: ' . $fuori[0]['perche'] . '. '
                . 'Per chi li ha scritti non esistono: vanno agganciati a una sezione o cancellati.');
        }
    } catch (\Throwable $e) {
        annota('sezioni', 'guasto', $e->getMessage());
    }
}

// ── conservazione ────────────────────────────────────────────────────────
// Un Istituto che dichiara «le compilazioni dei modelli istituzionali non
// restano sul server» (`compilation_storage = 0`, dal pannello degli Istituti)
// e ha ancora nel database quelle di prima sta dichiarando una misura che non
// ha applicato. L'interruttore vale da quando lo si gira: `InstituteRepository`
// esegue solo `UPDATE institutes SET compilation_storage = ?`, e niente tocca
// le righe già scritte.
//
// Verso il DPO che ha chiesto quella configurazione è la differenza fra una
// misura applicata e una misura annunciata, e non si vede provando
// l'applicazione: il salvataggio viene rifiutato come promesso, e intanto il
// pregresso resta dov'è. Il perché sta in
// App\Services\Ops\ConservazioneDichiarata, che misura per docente e non per
// Istituto, con lo stesso criterio della politica che nega il salvataggio.
if (daFare('conservazione')) {
    try {
        $pdo = Database::connection();
        if (!\App\Services\Ops\ConservazioneDichiarata::interruttoreEsiste($pdo)) {
            annota('conservazione', 'non_applicabile',
                'La colonna institutes.compilation_storage non c\'è (migration 103 non applicata): '
                . 'nessun Istituto può dichiarare che le compilazioni non restano sul server.');
        } else {
            $misura = \App\Services\Ops\ConservazioneDichiarata::nelDatabase($pdo);
            if ($misura['incoerenti'] === []) {
                annota('conservazione', 'regge', $misura['istitutiSpenti'] === 0
                    ? sprintf(
                        'Nessun Istituto attivo dichiara che le compilazioni non restano sul server. '
                        . '%d compilazioni di modelli istituzionali salvate, tutte da docenti a cui il salvataggio è consentito.',
                        $misura['istituzionaliSalvate'],
                    )
                    : sprintf(
                        '%d Istituti dichiarano che le compilazioni non restano sul server (%d docenti coinvolti): '
                        . 'nessuna compilazione di modelli istituzionali salvata a loro nome, su %d in tutto.',
                        $misura['istitutiSpenti'],
                        $misura['docentiNegati'],
                        $misura['istituzionaliSalvate'],
                    ));
            } else {
                $righe = [];
                foreach ($misura['incoerenti'] as $docente => $quante) {
                    $righe[] = "docente $docente: $quante";
                }
                annota('conservazione', 'guasto', sprintf(
                    '%d compilazioni di modelli istituzionali salvate da docenti a cui oggi il server '
                    . 'rifiuterebbe di salvarle (%s). %d Istituti dichiarano che non restano sul server, '
                    . 'ma l\'interruttore vale da quando lo si gira e non ha tolto le righe di prima. '
                    . 'Le chiude il docente: esporta il PDF e cancella la compilazione.',
                    array_sum($misura['incoerenti']),
                    implode(', ', $righe),
                    $misura['istitutiSpenti'],
                ));
            }
        }
    } catch (\Throwable $e) {
        annota('conservazione', 'guasto', $e->getMessage());
    }
}

// ── bozze ────────────────────────────────────────────────────────────────
// La cancellazione a tempo delle bozze (ADR-046) e' una conservazione
// dichiarata: sta nell'informativa e nel registro art. 30. Una conservazione
// dichiarata che nessun lavoro applica e' una dichiarazione falsa, e il modo
// in cui smette di essere vera non fa rumore — il timer non installato, la
// migrazione non applicata, il DELETE negato, la posta spenta.
//
// Questo invariante non guarda se il lavoro e' partito: guarda il suo
// RISULTATO, cioe' se sono rimaste righe che avrebbero gia' dovuto sparire.
// E' la stessa scelta dell'invariante `conservazione` qui sopra, e per lo
// stesso motivo: se si controllasse l'esecuzione, un lavoro che gira e non
// cancella niente risulterebbe a posto.
//
// La soglia di tolleranza esiste perche' la cancellazione aspetta tre giorni
// dall'avviso: fino a quel momento una riga scaduta e' normale, non e' un
// guasto. Oltre, e' una promessa non mantenuta.
if (daFare('bozze')) {
    try {
        $spazzata = new \App\Services\Risdoc\SpazzataDelleBozze();
        if (!$spazzata->pronta()) {
            annota('bozze', 'non_applicabile',
                'La colonna risdoc_compilations_data.exported_at non c\'è (migrazione 138 non '
                . 'applicata): nessuna bozza ha una scadenza, e non c\'è niente da spazzare.');
        } else {
            $censimento = $spazzata->censimento();
            $righe  = $censimento['righe'];
            $adesso = $censimento['adesso'];
            $mature = \App\Services\Risdoc\SpazzataDelleBozze::maturePerLaCancellazione($righe, $adesso);
            $attesa = \App\Services\Risdoc\SpazzataDelleBozze::scaduteInAttesa($righe, $adesso);
            // Le bloccate sono il secondo modo, meno ovvio, in cui la
            // conservazione dichiarata smette di applicarsi: la posta rotta.
            // Senza avviso non si cancella, e una riga mai avvisata non entra
            // mai fra le mature — quindi guardare le sole mature lascerebbe
            // verde un'istanza che non cancella piu' niente.
            $bloccate = \App\Services\Risdoc\SpazzataDelleBozze::bloccate($righe, $adesso);

            $daAvvisare = 0;
            foreach ($righe as $r) {
                if ($r['stato'] === \App\Services\Risdoc\ScadenzaDelleBozze::DA_AVVISARE) {
                    $daAvvisare++;
                }
            }

            if ($bloccate !== []) {
                annota('bozze', 'guasto', sprintf(
                    '%d bozze sono scadute da oltre %d giorni e l\'avviso al docente non e\' mai partito: '
                    . 'senza avviso non si cancellano, quindi restano li\' per sempre. Il giro avvisa ogni notte '
                    . 'anche le bozze scadute senza avviso, e segna comunque quelle dei docenti senza indirizzo: '
                    . 'se l\'avviso resta muto, e\' l\'invio che fallisce. Guardare '
                    . '`php tools/gdpr/spazza_bozze_scadute.php`: l\'esito degli avvisi sara\' `non_partita`.',
                    \count($bloccate),
                    \App\Services\Risdoc\ScadenzaDelleBozze::PREAVVISO_GIORNI,
                ));
            } elseif ($mature === []) {
                annota('bozze', 'regge', sprintf(
                    '%d bozze salvate; nessuna e\' scaduta oltre l\'attesa dopo l\'avviso. '
                    . 'In scadenza entro %d giorni: %d. Scadute e in attesa dei %d giorni dall\'avviso: %d. '
                    . 'Regola: %d giorni dall\'ultima fra scaricamento e modifica, e comunque il %d/%d '
                    . 'per cio\' che e\' fermo dal %d/%d.',
                    \count($righe),
                    \App\Services\Risdoc\ScadenzaDelleBozze::PREAVVISO_GIORNI,
                    $daAvvisare,
                    \App\Services\Risdoc\SpazzataDelleBozze::ATTESA_DOPO_AVVISO_GIORNI,
                    \count($attesa),
                    \App\Services\Risdoc\ScadenzaDelleBozze::GRAZIA_GIORNI,
                    \App\Services\Risdoc\ScadenzaDelleBozze::RETE_GIORNO,
                    \App\Services\Risdoc\ScadenzaDelleBozze::RETE_MESE,
                    \App\Services\Risdoc\ScadenzaDelleBozze::SOGLIA_GIORNO,
                    \App\Services\Risdoc\ScadenzaDelleBozze::SOGLIA_MESE,
                ));
            } else {
                $piuVecchia = 0;
                foreach ($mature as $r) {
                    $piuVecchia = max($piuVecchia, -(int)$r['rimasti']);
                }
                annota('bozze', 'guasto', sprintf(
                    '%d bozze sono scadute, avvisate da piu\' di %d giorni, e sono ancora qui '
                    . '(la piu\' vecchia da %d giorni oltre la scadenza). La conservazione dichiarata '
                    . 'nell\'informativa non viene applicata. Guardare: il timer '
                    . 'pantedu-risdoc-scadenze e\' installato e attivo? '
                    . '`systemctl list-timers pantedu-risdoc-scadenze` — e poi '
                    . '`php tools/gdpr/spazza_bozze_scadute.php` per vedere che cosa farebbe.',
                    \count($mature),
                    \App\Services\Risdoc\SpazzataDelleBozze::ATTESA_DOPO_AVVISO_GIORNI,
                    $piuVecchia,
                ));
            }
        }
    } catch (\Throwable $e) {
        annota('bozze', 'guasto', $e->getMessage());
    }
}

// ── segnalazioni ─────────────────────────────────────────────────────────
// Una segnalazione di violazione che nessuno ha valutato.
//
// Il piano per le violazioni dichiarava questo come il proprio punto più
// debole: una segnalazione arrivata dai moduli pubblici non apre da sola un
// incidente nel registro, quindi è un passaggio che si può dimenticare. E le
// due strade pubbliche — `/dpo-contact` con oggetto `breach_report`,
// `/segnalazione-contenuti` con categoria `gdpr_art9` — sono le sole che
// possano rilevare il rischio R20, che nessun controllo automatico vede.
//
// L'incidente non si apre da solo di proposito: quei moduli sono pubblici e
// senza autenticazione, e dalla migrazione 136 una riga del registro non si
// cancella mai. Lo apre una persona, e questo controllo si accorge se non lo
// fa: sei ore, quindi l'avviso parte entro dodici e ne restano sessanta delle
// settantadue. Il perché sta in App\Services\Ops\SegnalazioniDiViolazione.
if (daFare('segnalazioni')) {
    try {
        $pdo = Database::connection();
        if (!\App\Services\Ops\SegnalazioniDiViolazione::legameEsiste($pdo)) {
            annota('segnalazioni', 'non_applicabile',
                'La colonna incident_id non c\'è (migration 137 non applicata): non si può sapere '
                . 'quali segnalazioni di violazione siano state valutate.');
        } else {
            $aperte = \App\Services\Ops\SegnalazioniDiViolazione::apertaSenzaIncidente($pdo);
            $tardi  = \App\Services\Ops\SegnalazioniDiViolazione::inRitardo($aperte);
            if ($tardi === []) {
                annota('segnalazioni', 'regge', $aperte === []
                    ? 'Nessuna segnalazione di violazione in attesa di valutazione.'
                    : sprintf(
                        '%d segnalazioni di violazione arrivate da meno di %d ore, nessuna in ritardo: '
                        . 'l\'incidente si apre dal pannello e il legame resta scritto.',
                        \count($aperte),
                        \App\Services\Ops\SegnalazioniDiViolazione::ORE,
                    ));
            } else {
                $righe = [];
                foreach ($tardi as $s) {
                    $quante = $s['ore'] < 0 ? 'data illeggibile' : "{$s['ore']} ore fa";
                    $righe[] = \App\Services\Ops\SegnalazioniDiViolazione::dove($s['fonte'], $s['id'])
                        . " ({$quante})";
                }
                annota('segnalazioni', 'guasto', sprintf(
                    '%d segnalazioni di violazione senza un incidente aperto: %s. Le settantadue ore '
                    . 'dell\'art. 33 decorrono da quando se ne è venuti a conoscenza, non da quando '
                    . 'qualcuno guarda. Valutarle e, se sono violazioni, aprire l\'incidente da '
                    . '/admin/data-breach; se non lo sono, chiudere la segnalazione con una nota — '
                    . 'anche quella valutazione va documentata (art. 33 §5).',
                    \count($tardi),
                    implode(', ', $righe),
                ));
            }
        }
    } catch (\Throwable $e) {
        annota('segnalazioni', 'guasto', $e->getMessage());
    }
}

// ── csrf ─────────────────────────────────────────────────────────────────
// Lo stesso invariante di tests/Unit/Core/CoperturaCsrfTest.php, verificato
// però sul codice **davvero installato**: fra quello che CI ha provato e
// quello che sta sulla macchina c'è un rilascio, e un rilascio può fallire a
// metà.
if (daFare('csrf')) {
    try {
        $router = new Router();
        require (string)Config::get('app.paths.routes') . '/web.php';

        $scrivono = ['POST', 'PUT', 'PATCH', 'DELETE'];
        $conGettone = 0;
        $senzaGettone = [];
        foreach ($router->routes() as $rotta) {
            $verbi = array_values(array_intersect($rotta->methods, $scrivono));
            if ($verbi === []) {
                continue;
            }
            if (\in_array('csrf', $rotta->middleware, true)) {
                $conGettone++;
            } else {
                $senzaGettone[] = $verbi[0] . ' ' . $rotta->pattern;
            }
        }
        // Il numero delle esenzioni è fissato nel test; qui interessa che non
        // esploda, non l'elenco puntuale (quello lo tiene il test).
        $tetto = 15;
        \count($senzaGettone) > $tetto
            ? annota('csrf', 'guasto', \count($senzaGettone) . " rotte scrivono senza "
                . "verificare il gettone (attese al massimo $tetto). Nuove: "
                . implode(', ', \array_slice($senzaGettone, $tetto)))
            : annota('csrf', 'regge', "$conGettone rotte verificano il gettone, "
                . \count($senzaGettone) . ' esentate per iscritto.');
    } catch (\Throwable $e) {
        annota('csrf', 'guasto', 'tabella delle rotte non caricabile: ' . $e->getMessage());
    }
}

// ── tex ──────────────────────────────────────────────────────────────────
// Il servizio TeX risponde, a chi lo usa? (19/9/2026)
//
// Dall'8 settembre 2026 l'applicazione gira nel container e il TeX è rimasto
// sull'host, in ascolto su 127.0.0.1: il container lo cercava sul **proprio**
// 127.0.0.1, dove non c'è niente. Undici giorni senza PDF, anteprime, TikZ non
// in cache — e questa diagnostica girava due volte al giorno senza guardare.
//
// Due domande, ognuna dal posto in cui vale (vedi ControlloTex):
//
//   - nel container (passo 8-bis del rilascio, come www-data): la sonda
//     diretta, cioè esattamente la strada delle compilazioni del sito;
//   - sull'host (il timer): la sonda diretta vale per i lavori a orario, che
//     girano qui, ma **passerebbe anche con il container rotto** — era il caso
//     del guasto. Quindi si chiede anche all'applicazione in servizio,
//     attraverso nginx, con /health/tex.
//
// Non configurato è una scelta (sviluppo senza TeX, CI), come per geoip.
if (daFare('tex')) {
    try {
        $client = TexCompileClient::tryDefault();
        if ($client === null) {
            annota('tex', 'non_applicabile', 'TEX_COMPILE_ENDPOINT o TEX_COMPILE_SECRET vuoti: '
                . 'le compilazioni sono spente di proposito (sviluppo senza TeX, CI).');
        } else {
            $nelContainer = is_file('/.dockerenv');
            $daDove = $nelContainer ? 'dal container'
                : (installazioneVera() ? 'dall’host, per i lavori a orario' : 'da qui');
            $risposte = [ControlloTex::diretto($client, $daDove)];
            if (installazioneVera() && !$nelContainer) {
                $sito = (string)(parse_url((string)Config::get('app.url', ''), PHP_URL_HOST) ?: 'localhost');
                $risposte[] = ControlloTex::attraversoNginx('https://127.0.0.1', $sito);
            }
            $rotte = array_filter($risposte, static fn(array $r): bool => $r['esito'] === 'guasto');
            annota('tex', $rotte === [] ? 'regge' : 'guasto', implode(' | ', array_column($risposte, 'prova')));
        }
    } catch (\Throwable $e) {
        annota('tex', 'guasto', 'il controllo non è riuscito a partire: ' . $e->getMessage());
    }
}

// ── crowdsec ─────────────────────────────────────────────────────────────
// Il bouncer CrowdSec parla con la LAPI, o con qualcos'altro? (23/9/2026)
//
// Fino a oggi il suo URL aveva un predefinito, `http://127.0.0.1:8080`, che
// nel container è il nginx dell'applicazione: con la sola chiave impostata il
// bouncer avrebbe interrogato il sito e, fail-open, non bloccato niente, con il
// pannello del WAF che lo dava «raggiungibile» (A-15). Il perché delle risposte
// sta in App\Services\Waf\ControlloCrowdSec.
//
// Sull'host serve sapere se l'applicazione la serve un container: lì la
// loopback dell'host non è quella del bouncer. Lo dice il file dell'upstream
// che `deploy-container.sh` scrive, e che nell'assetto di ripiego non c'è.
if (daFare('crowdsec')) {
    try {
        $bouncer = \App\Services\Waf\WafCrowdSecBouncerService::default();
        $nelContainer = is_file('/.dockerenv');
        $sullHostDiUnContainer = !$nelContainer && installazioneVera()
            && is_file('/etc/nginx/conf.d/pantedu-upstream.conf');
        $daDove = $nelContainer ? 'dal container' : (installazioneVera() ? 'dall’host' : 'da qui');
        $r = \App\Services\Waf\ControlloCrowdSec::esito(
            $bouncer->status(),
            (string)Config::get('waf.crowdsec_lapi_url', ''),
            $daDove,
            $sullHostDiUnContainer,
        );
        annota('crowdsec', $r['esito'], $r['prova']);
    } catch (\Throwable $e) {
        annota('crowdsec', 'guasto', 'il controllo non è riuscito a partire: ' . $e->getMessage());
    }
}

// ── lavori ───────────────────────────────────────────────────────────────
//
// I lavori periodici si controllano da quello che **producono**, non dal
// codice con cui escono.
//
// Il 9 settembre 2026 tutte le unità systemd risultavano `success` con uscita
// zero, e sembrava tutto a posto. Ma il salvataggio delle chiavi di cifratura
// dei docenti non è un'unità systemd: è una riga di `crontab`, e falliva ogni
// notte da tre settimane con «Permission denied» dentro un file di registro
// che non legge nessuno. Un cron che fallisce non lascia **nessuna** traccia
// di stato: non va in `failed`, non ha `OnFailure=`, non compare in
// `systemctl`. L'unica prova che esiste è il file che avrebbe dovuto scrivere.
//
// Guardare il prodotto è più severo che guardare l'uscita, e non meno: coglie
// anche il caso in cui il programma esce zero senza aver fatto niente.
if (daFare('lavori') && !installazioneVera()) {
    annota('lavori', 'non_applicabile', 'i lavori periodici (cron e timer) '
        . 'girano solo su un\'installazione vera: qui i dati stanno dentro il '
        . 'repository, quindi non c\'è niente che li produca.');
} elseif (daFare('lavori')) {
    $repoBase   = (string)Config::get('app.paths.base');
    $datiBase   = (string)Config::get('app.paths.storage');

    // `backup_teacher_keys.sh` scrive sotto il **repository**, non sotto
    // l'albero dei dati: sono due alberature diverse e vanno tenute distinte,
    // altrimenti si cerca nel posto sbagliato e si conclude che manca tutto.
    $lavori = [
        'chiavi dei docenti' => [
            'schema' => $repoBase . '/storage/backups/teacher_keys/teacher_keys_*.sql.gz',
            'giorni' => 2,
            'chi'    => 'pantedu-backup-chiavi.timer (era un cron di root fino al 9/9/2026)',
        ],
        'catena di audit' => [
            'schema' => $datiBase . '/audit-chain/chain-*.json',
            'giorni' => 2,
            'chi'    => 'pantedu-audit-chain.timer',
        ],
        'salvataggio cifrato' => [
            // La cartella di `tools/backup/encrypted_backup.sh`, con la sua
            // regola: BACKUP_DIR, o il predefinito se è vuota
            // (app/Config/backup.php). Fino al 23/9/2026 qui c'era `??`, e con
            // `BACKUP_DIR=` vuota si cercava `/pantedu-backup-*`: allarme fisso.
            'schema' => rtrim((string)Config::get('backup.dir', '/var/backups/pantedu'), '/')
                . '/pantedu-backup-*.tar.gpg',
            'giorni' => 2,
            'chi'    => 'pantedu-backup-encrypted.timer',
        ],
        // Il battito del controllo d'integrità. Non contiene l'esito — quello
        // arriva per la strada delle anomalie — ma dimostra che il giro è
        // avvenuto.
        //
        // È la voce nata dal caso peggiore di tutti: fra il 20 maggio e il 9
        // settembre 2026 il controllo d'integrità ha scritto «completato»
        // centoundici volte senza mai esaminare un file. Nessuno se n'è
        // accorto perché nessuno si chiedeva se avesse prodotto qualcosa: si
        // guardava solo che non si lamentasse. Un controllo silenzioso e un
        // controllo morto si assomigliano troppo.
        // Il rapporto mensile sulle versioni. Trentacinque giorni di tolleranza
        // perché gira il primo del mese con mezz'ora di dispersione: due
        // esecuzioni consecutive possono distare trentuno giorni e qualcosa, e
        // un limite più stretto segnalerebbe un guasto che non c'è.
        //
        // Come per l'integrità: quello che si controlla qui non è l'esito — se
        // c'è qualcosa da dire lo dice il registro delle anomalie — ma che il
        // giro sia **avvenuto**. Un rapporto che smette di essere prodotto non
        // si distingue da un rapporto che non ha niente da dire.
        'versioni indietro' => [
            'schema' => $datiBase . '/logs/versioni-ultimo-giro.json',
            'giorni' => 35,
            'chi'    => 'pantedu-versioni.timer, il primo del mese',
        ],
        'integrità dei file' => [
            'schema' => $datiBase . '/logs/aide-ultimo-giro.json',
            'giorni' => 2,
            'chi'    => 'cron delle 04:00, /usr/local/sbin/aide-check-pantedu.sh',
        ],
    ];

    // Dentro il container questi file non si vedono: i lavori girano
    // sull'host, su filesystem che il container non monta. Dirlo, invece di
    // annunciare tre guasti che non esistono.
    $nelContainer = is_file('/.dockerenv');

    $guasti = [];
    $prove  = [];
    $saltati = [];

    foreach ($lavori as $nome => $l) {
        // «Non ci sono file» e «non posso guardare» sono due cose diverse, e
        // `glob()` risponde uguale a tutte e due: array vuoto.
        //
        // Contano davvero: `storage/audit-chain` è `pantedu:pantedu` 0750, e
        // la diagnostica lanciata come `www-data` dopo il rilascio non ci
        // entra. Al primo giro ha annunciato «catena di audit: nessun file
        // prodotto» mentre i file c'erano tutti. Un allarme che scatta perché
        // non ha i permessi di guardare insegna a ignorare gli allarmi.
        $cartella = \dirname($l['schema']);
        if (!is_dir($cartella) || !is_readable($cartella)) {
            $saltati[] = $nome . (is_dir($cartella) ? ' (non leggibile da qui)' : '');
            continue;
        }

        $trovati = glob($l['schema']) ?: [];
        if ($trovati === []) {
            if ($nelContainer) {
                $saltati[] = $nome;
                continue;
            }
            $guasti[] = "$nome: nessun file prodotto ({$l['schema']}). "
                . "Lo scrive {$l['chi']}.";
            continue;
        }
        // Il più recente per data di modifica, non per nome: un nome può
        // mentire, la data no.
        $recente = null;
        $quando = 0;
        foreach ($trovati as $f) {
            $m = (int)@filemtime($f);
            if ($m > $quando) {
                $quando = $m;
                $recente = $f;
            }
        }
        $giorni = (int)floor((time() - $quando) / 86400);
        if ($giorni > $l['giorni']) {
            $guasti[] = sprintf(
                '%s: l\'ultimo è di %d giorni fa (%s), il limite è %d. Lo scrive %s.',
                $nome,
                $giorni,
                basename((string)$recente),
                $l['giorni'],
                $l['chi'],
            );
        } else {
            $prove[] = sprintf(
                '%s: %s, %.1f KB, %s',
                $nome,
                basename((string)$recente),
                (int)@filesize((string)$recente) / 1024,
                $giorni === 0 ? 'di oggi' : "di $giorni giorni fa",
            );
        }
    }

    if ($guasti !== []) {
        annota('lavori', 'guasto', implode(' | ', $guasti));
    } elseif ($prove === [] && $saltati !== []) {
        annota('lavori', 'non_applicabile', 'i lavori periodici girano sull\'host e '
            . 'scrivono su filesystem che questo container non monta ('
            . implode(', ', $saltati) . '). Li controlla la diagnostica dell\'host.');
    } else {
        $coda = $saltati === [] ? '' : ' (non visibili da qui: ' . implode(', ', $saltati) . ')';
        annota('lavori', 'regge', implode('; ', $prove) . $coda);
    }
}

// ── unita ────────────────────────────────────────────────────────────────
// Le unità systemd installate sono quelle del repository? (23/9/2026)
//
// Dall'8 settembre 2026 il rilascio non le installa più (`deploy.sh` lo faceva
// al passo 7, `deploy-container.sh` no), e nessun controllo confrontava il
// server con il repository: una correzione a un timer, unita e verde, restava
// dov'era finché qualcuno non la copiava a mano. Il rilascio continua a non
// installarle (ADR-048); questo controllo dice che cosa manca. Il confronto e
// il perché delle sue risposte stanno in App\Services\Ops\UnitaInstallate.
//
// Dove non ha oggetto lo dice: nel container le unità non ci sono (sono
// dell'host), e su una macchina di sviluppo non c'è niente di installato.
// Con `--unita-installate` si confronta comunque: chi indica la cartella vuole
// la risposta. «Non ho potuto confrontare» è un guasto, non un «regge».
if (daFare('unita')) {
    $cartellaUnita = $unitaInstallate ?? '/etc/systemd/system';
    if ($unitaInstallate === null && is_file('/.dockerenv')) {
        annota('unita', 'non_applicabile', 'nel container le unità systemd non ci sono: sono '
            . 'dell’host, e le confronta la diagnostica dell’host (pantedu-diagnostica.timer).');
    } elseif ($unitaInstallate === null && !installazioneVera()) {
        annota('unita', 'non_applicabile', 'qui i dati stanno dentro il repository, quindi è '
            . 'una macchina di sviluppo: nessuna unità di Pantedu installata da confrontare.');
    } else {
        $sorgenteUnita = (string)Config::get('app.paths.base') . '/tools/systemd';
        $u = \App\Services\Ops\UnitaInstallate::confronta($sorgenteUnita, $cartellaUnita);
        // Diverse e non installate si installano, spente si accendono, orfane
        // si tolgono: il rilascio non fa niente di tutto questo (ADR-048).
        $come = 'si allineano a mano (docs/dev/ci-cd.md, «Che cosa il rilascio non installa»).';
        if (!$u['confrontabile']) {
            annota('unita', 'guasto', "non ho potuto confrontare: {$u['perche']} (come " . chiGira() . '). '
                . 'Non è un «regge»: senza confronto non si sa se le correzioni alle unità sono '
                . 'arrivate sul server.');
        } else {
            $elenca = static fn(array $nomi): string => implode(', ', \array_slice($nomi, 0, 8))
                . (\count($nomi) > 8 ? ', … (' . \count($nomi) . ' in tutto)' : '');
            $parti = [];
            if ($u['diverse'] !== []) {
                $parti[] = 'diverse dal repository: ' . $elenca($u['diverse']);
            }
            if ($u['mancanti'] !== []) {
                $parti[] = 'non installate: ' . $elenca($u['mancanti']);
            }
            if ($u['spente'] !== []) {
                $parti[] = 'installate ma spente (nessun collegamento in <bersaglio>.wants): '
                    . $elenca($u['spente']);
            }
            if ($u['orfane'] !== []) {
                $parti[] = 'installate e non più nel repository: ' . $elenca($u['orfane']);
            }
            if ($u['illeggibili'] !== []) {
                $parti[] = 'non ho potuto confrontare (non leggibili da ' . chiGira() . '): '
                    . $elenca($u['illeggibili']);
            }
            $parti === []
                ? annota('unita', 'regge', \count($u['uguali']) . " file di tools/systemd uguali a quelli in "
                    . "$cartellaUnita; {$u['accese']} fra timer e path accesi.")
                : annota('unita', 'guasto', implode(' | ', $parti) . '. Il rilascio non installa le unità, '
                    . $come);
        }
    }
}

// ── registro ─────────────────────────────────────────────────────────────
// Il registro delle anomalie è scrivibile da chi sta girando? (19/9/2026)
//
// Misurato in produzione: `anomalie.jsonl` era `pantedu:www-data` 0640. Il PHP
// del container (`www-data`, solo nel gruppo) lo leggeva e non ci scriveva, e
// nessuna riga dell'applicazione è mai arrivata — compresa quella del passo
// 8-bis del rilascio, che il suo commento prometteva. Un registro che accetta
// righe solo da alcuni scrittori è un registro che tace a metà.
//
// Gira dove girano gli scrittori: dopo il rilascio come www-data nel
// container, dal timer come pantedu sull'host.
if (daFare('registro')) {
    $fileRegistro = Anomalia::percorso();
    $cartellaRegistro = \dirname($fileRegistro);
    $chi = chiGira();
    $guasti = [];
    $prove = [];
    foreach ([$fileRegistro, $fileRegistro . '.stato'] as $f) {
        $nome = basename($f);
        if (is_file($f)) {
            $permessi = substr(sprintf('%o', fileperms($f)), -4);
            is_writable($f)
                ? $prove[] = "$nome scrivibile ($permessi)"
                : $guasti[] = "$nome ($permessi) non è scrivibile da $chi";
        } elseif (is_dir($cartellaRegistro) && is_writable($cartellaRegistro)) {
            $prove[] = "$nome non c’è ancora, e la cartella permette di crearlo";
        } else {
            $guasti[] = "$nome non c’è, e $chi non può crearlo in $cartellaRegistro";
        }
    }
    $guasti === []
        ? annota('registro', 'regge', implode(', ', $prove) . " (come $chi)")
        : annota('registro', 'guasto', implode(' | ', $guasti) . ". Le anomalie scritte da $chi "
            . 'si perdono: nel registro compare solo quello che scrivono gli altri. I due file '
            . 'devono essere del gruppo www-data e 0660 (docs/ops/diagnostica.md).');
}

// ── anomalie ─────────────────────────────────────────────────────────────
// Il registro delle incoerenze interne (app/Support/Anomalia.php). Se c'è
// dentro qualcosa, qualcosa sta andando storto in silenzio adesso.
if (daFare('anomalie')) {
    try {
        // `Anomalia` è l'unica dipendenza di questo file che potrebbe non
        // esserci: durante un rilascio a metà, o su una copia vecchia del
        // sorgente. Un controllo che non riesce a girare deve dirlo, non
        // portarsi via gli altri sei.
        $recenti = Anomalia::recenti(86400);

        // Le anomalie scritte dalla diagnostica stessa non contano qui.
        //
        // Ogni guasto trovato finisce nel registro — serve, perché un guasto
        // delle tre di notte dev'essere ancora leggibile la mattina dopo. Ma
        // quelle righe restano ventiquattr'ore, e i controlli qui sopra
        // rivalutano la stessa cosa a ogni giro: contarle due volte vuol dire
        // che dopo **qualunque** guasto la diagnostica resta rossa per un
        // giorno intero, mandando una mail a ogni esecuzione anche quando il
        // problema è già stato risolto.
        //
        // Visto succedere alla prima esecuzione automatica, il 9 settembre
        // 2026: corretto `version.txt`, il controllo `versione` è tornato
        // verde e `anomalie` è rimasto rosso per la riga che il giro
        // precedente aveva appena scritto. Un allarme che continua a suonare
        // dopo la riparazione insegna a spegnere l'allarme.
        //
        // Restano nel registro, e restano leggibili: semplicemente non sono
        // loro a dire se il sistema sta bene *adesso*. A dirlo sono i sei
        // controlli qui sopra, che girano ogni volta da capo.
        $recenti = array_values(array_filter(
            $recenti,
            static fn(array $v): bool => !str_starts_with((string)($v['codice'] ?? ''), 'diagnostica_'),
        ));

        // Le anomalie già guardate da una persona non contano come nuove.
        //
        // Non è zittire: le righe restano tutte nel registro, e una **nuova**
        // occorrenza dello stesso codice — cioè successiva al livello d'acqua
        // segnato — torna a suonare. Quello che cambia è che dopo aver
        // guardato un guasto non si continua a essere svegliati dallo stesso
        // guasto per ventiquattr'ore.
        //
        // Il caso che l'ha reso necessario: `unattended-upgrades` aggiorna
        // pacchetti circa una volta a settimana, ogni aggiornamento cambia
        // file sotto `/usr`, e AIDE lo segnala. È vero, è atteso, e senza un
        // modo di dire «visto» in un mese quelle mail non le apre più nessuno.
        //
        // Quante ne sono state messe a tacere si scrive comunque nel
        // resoconto: una riga che dice «zero nuove, tre già viste il 9/9 alle
        // 13:40» è un'informazione; farle sparire e basta no.
        $visti = Anomalia::vistiFinoA();
        $giaViste = [];
        $recenti = array_values(array_filter(
            $recenti,
            static function (array $v) use ($visti, &$giaViste): bool {
                $codice = (string)($v['codice'] ?? '');
                $soglia = $visti[$codice] ?? null;
                if ($soglia === null) {
                    return true;
                }
                $quando = strtotime((string)($v['quando'] ?? '')) ?: 0;
                if ($quando > (strtotime($soglia) ?: 0)) {
                    return true;
                }
                $giaViste[] = $v;
                return false;
            },
        ));

        // Nuove e già viste escono con la data dell'ultima occorrenza: senza,
        // lo stesso codice compariva identico nelle due liste, e il 13 settembre
        // 2026 è stato letto al contrario (vedi Anomalia::riassumiPerCodice).
        // visto.php non legge i file d'ambiente (vedi il suo commento): la
        // cartella dei dati gliela si passa, e qui la si conosce. Il comando
        // esce già pronto, così chi legge la mail non deve cercarla.
        $visto = 'PANTEDU_DATA_PATH=' . (string)Config::get('app.paths.data_base') . ' php tools/ops/visto.php';
        $coda = $giaViste === [] ? '' : ' Già segnate come viste: '
            . Anomalia::riassumiPerCodice($giaViste) . " (con `{$visto}`).";

    if ($recenti === []) {
        annota('anomalie', 'regge', 'nessuna incoerenza interna nuova nelle ultime 24 ore '
            . '(le righe scritte dalla diagnostica stessa non contano: le rivalutano '
            . 'i controlli qui sopra).' . $coda);
    } else {
        annota('anomalie', 'guasto', 'nuove: ' . Anomalia::riassumiPerCodice($recenti)
            . '. Dettagli in ' . Anomalia::percorso()
            . ". Dopo averle guardate: {$visto} --tutto" . $coda);
    }
  } catch (\Throwable $e) {
        annota('anomalie', 'guasto', 'il registro delle anomalie non è leggibile: '
            . $e->getMessage()
            . '. Finché resta così, le incoerenze interne non le raccoglie nessuno.');
    }
}

// ── Un nome chiesto che nessun controllo porta ───────────────────────────
// 23/9/2026: `--solo=unita` lanciato con la diagnostica di prima, che quel
// controllo non l'aveva, stampava «0 controlli, tutti reggono» e usciva 0. Un
// nome sbagliato, o una copia vecchia dello strumento, sembravano un verde. Un
// controllo chiesto e non fatto non regge.
foreach ($soloRichiesti as $chiesto) {
    if (!\in_array($chiesto, array_column($esiti, 'nome'), true)) {
        annota((string)$chiesto, 'guasto', 'nessun controllo si chiama così: non è stato fatto niente. '
            . 'I nomi stanno in docs/ops/diagnostica.md; se il nome è giusto, questa copia della '
            . 'diagnostica è più vecchia del controllo.');
    }
}

// ── Il resoconto ─────────────────────────────────────────────────────────
$guasti = array_values(array_filter($esiti, static fn($e) => $e['esito'] === 'guasto'));

if ($comeJson) {
    echo json_encode([
        'quando'   => date('c'),
        'esito'    => $guasti === [] ? 'regge' : 'guasto',
        'controlli' => $esiti,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} else {
    $simboli = ['regge' => 'OK  ', 'guasto' => 'ROTTO', 'non_applicabile' => '--  '];
    echo "Diagnostica di Pantedu — ", date('Y-m-d H:i:s'), "\n\n";
    foreach ($esiti as $e) {
        printf("  %-6s %-12s %s\n", $simboli[$e['esito']] ?? '?', $e['nome'], $e['prova']);
    }
    echo "\n";
    echo $guasti === []
        ? \count($esiti) . " controlli, tutti reggono.\n"
        : \count($guasti) . ' controlli su ' . \count($esiti) . " non reggono.\n";
}

// Quello che la diagnostica trova finisce nello stesso registro che legge:
// così un guasto trovato di notte è ancora lì la mattina dopo.
foreach ($guasti as $g) {
    try {
        Anomalia::registra(
            'diagnostica_' . $g['nome'],
            $g['prova'],
            ['controllo' => $g['nome']],
        );
    } catch (\Throwable $e) {
        // Non riuscire a scrivere il registro non deve cancellare il
        // resoconto appena stampato: quello vale comunque.
        fwrite(STDERR, "  (non sono riuscito a registrare l'anomalia: {$e->getMessage()})\n");
    }
}

exit($guasti === [] ? 0 : 1);
