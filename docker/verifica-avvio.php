<?php

declare(strict_types=1);

/**
 * I controlli che l'applicazione deve superare prima che il container si
 * dichiari in piedi.
 *
 * Perché un file e non `php -r` dentro l'entrypoint. Il primo tentativo era
 * proprio così, e l'escape si è mangiato il codice: `App\Core\Config` scritto
 * dentro una stringa fra virgolette in bash arrivava a PHP come
 * `App\\Core\\Config`, che fuori dalle stringhe è un errore di sintassi. Il
 * container si rifiutava di partire dicendo «non riesco a caricare la
 * configurazione» — vero, ma per il motivo sbagliato, e il messaggio mandava a
 * cercare un montaggio che c'era. Due giri di CI persi.
 *
 * Un file si legge, si prova da solo, e non ha livelli di virgolette.
 *
 * Uso:
 *   php docker/verifica-avvio.php            tutti i controlli
 *   php docker/verifica-avvio.php --senza-db  salta il database
 *   php docker/verifica-avvio.php --solo-configurazione
 *       solo la cartella dei dati (che esista) e le guardie della produzione
 *       (sezione 6): non scrive niente e non tocca il database. Serve a
 *       controllare `.env.local` dall'host prima di un riavvio
 *       (docs/ops/runbook-sito-irraggiungibile.md, § 4.7)
 *
 * Esce 0 se tutto va, 1 al primo che non va, con una riga che dice quale e
 * cosa guardare.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Support\DeploymentMode;
use App\Support\DeploymentScenario;

function guasto(string $cosa, string $dove): never
{
    fwrite(STDERR, "[avvio] ERRORE: $cosa\n");
    fwrite(STDERR, "[avvio]         guarda: $dove\n");
    exit(1);
}

$senzaDb = in_array('--senza-db', $argv, true);
$soloConfigurazione = in_array('--solo-configurazione', $argv, true);

// ── 1. La configurazione si carica e dice dove stanno i dati ──────────────
$storage = Config::get('app.paths.storage');

if (!is_string($storage) || $storage === '') {
    guasto(
        'la configurazione non dice dove sta storage',
        'il montaggio di .env e .env.local dentro /var/www/pantedu',
    );
}

if (!is_dir($storage)) {
    guasto(
        "la configurazione dice che storage sta in «{$storage}», ma quella cartella non esiste",
        'PANTEDU_DATA_PATH nel .env montato: deve descrivere il mondo DEL CONTAINER, '
            . 'non quello della macchina che l\'ha preparato',
    );
}

// ── 1a. Solo la configurazione, per controllare `.env.local` dall'host ────
// 23/9/2026. Le guardie della sezione 6 girano solo all'avvio: una riga
// cambiata in `.env.local` vale subito per il container che serve, `/health`
// risponde 200, e la guardia la vede al primo riavvio, quando il sito resta
// giù. Per guardarla PRIMA si lancia questo file dall'host, sulla copia del
// sorgente che il container monta (runbook del sito irraggiungibile, § 4.7).
//
// Dall'host, però, i controlli 2-5 direbbero il falso: si gira come un altro
// utente (le sessioni sono di www-data, 0700) e senza l'immagine. Qui si
// tengono solo quelli che non scrivono: la cartella dei dati esiste (sopra,
// altrimenti lo scenario si leggerebbe da un'altra `storage/config`), il file
// `.env.local` si legge, e le guardie.
if ($soloConfigurazione) {
    // Un `.env.local` che c'è ma non si legge Dotenv lo salta in silenzio
    // (`safeLoad`): la verifica guarderebbe il solo `.env` e direbbe di una
    // riga che non è quella sbagliata.
    $envLocale = dirname(__DIR__) . '/.env.local';
    if (is_file($envLocale) && !is_readable($envLocale)) {
        guasto(
            '[configurazione] .env.local c\'è ma chi fa girare la verifica non lo legge (giro come '
                . (function_exists('posix_geteuid') ? (string) posix_geteuid() : 'utente ignoto')
                . '): guarderei il solo .env',
            'l\'utente con cui la si lancia: lo stesso delle unità a orario dell\'host '
                . '(docs/ops/runbook-sito-irraggiungibile.md, § 4.7)',
        );
    }
    guardieDellaProduzione();
    echo "[avvio] solo la configurazione (--solo-configurazione): scrittura, sessioni, database, GeoIP "
        . "e documenti legali non guardati\n";
    exit(0);
}

// ── 2. Ci si può scrivere DA QUI ──────────────────────────────────────────
// Non «esiste» ma «ci scrivo io». Questo script gira come www-data, cioè come
// chi serve le pagine: è la lezione dei due disservizi dell'8 settembre 2026,
// dove la verifica era stata fatta come un utente che legge tutto.
$prova = $storage . '/.prova-avvio-' . getmypid();
if (@file_put_contents($prova, "x") === false) {
    guasto(
        // `posix_*` non è garantita in ogni immagine PHP: se manca, si dice
        // comunque l'essenziale invece di morire nel controllo che doveva
        // spiegare un errore.
        'non riesco a scrivere in «' . $storage . '» (giro come '
            . (function_exists('posix_geteuid') ? (string) posix_geteuid() : 'utente ignoto') . ')',
        'proprietario e permessi della cartella sull\'host: dev\'essere scrivibile dall\'utente del container',
    );
}
@unlink($prova);

// ── 2a. Ogni radice di scrittura dichiarata sta sotto storage, e ci si scrive ─
// Provare la sola `storage/` non basta: il 20/9/2026 si è misurato che le
// cartelle di `app/Config/filesystem.php` potevano nascere FUORI di lì —
// `<dati>/temp`, `<dati>/tex_pdf` — dove in produzione www-data non scrive,
// perché la procedura di ripristino dà a www-data il solo `storage/`. La
// scrittura falliva e l'applicazione rispondeva «salvato».
//
// Si controllano le radici come le legge l'applicazione, non un elenco
// copiato qui: un elenco a parte invecchia. Le radici di sola lettura
// (`img/`, che è versionata e sta nell'immagine) restano fuori.
$solaLettura = (array) (Config::get('filesystem.roots_sola_lettura') ?? []);
$daScrivere = [];
foreach ((array) (Config::get('filesystem.roots') ?? []) as $etichetta => $percorso) {
    if (in_array($etichetta, $solaLettura, true) || !is_string($percorso) || $percorso === '') {
        continue;
    }
    $daScrivere[(string) $etichetta] = $percorso;
}
// Le soglie degli allarmi e la cache dei blocchi: file, non cartelle.
foreach (['auth.paths.blocked_ips' => 'blocchi'] as $chiave => $etichetta) {
    $file = Config::get($chiave);
    if (is_string($file) && $file !== '') {
        $daScrivere[$etichetta] = dirname($file);
    }
}

foreach ($daScrivere as $etichetta => $percorso) {
    if (!str_starts_with($percorso, $storage . '/') && $percorso !== $storage) {
        guasto(
            "la radice di scrittura «{$etichetta}» sta in «{$percorso}», fuori da «{$storage}»",
            'app/Config/filesystem.php: quello che si scrive sta sotto storage/, perché è '
                . 'l\'unico albero che il ripristino dà a www-data, che il salvataggio '
                . 'notturno archivia e che questo controllo guarda',
        );
    }
    if (!is_dir($percorso) && !@mkdir($percorso, 0775, true) && !is_dir($percorso)) {
        guasto(
            "non riesco a creare «{$percorso}» (radice «{$etichetta}»)",
            'proprietario e permessi di ' . $storage . ' sull\'host',
        );
    }
    $prova = $percorso . '/.prova-avvio-' . getmypid();
    if (@file_put_contents($prova, 'x') === false) {
        guasto(
            "non riesco a scrivere in «{$percorso}» (radice «{$etichetta}»)",
            'proprietario e permessi della cartella sull\'host: dev\'essere scrivibile dall\'utente del container',
        );
    }
    @unlink($prova);
}

// ── 2b. Le sessioni hanno un posto, e ci scrive chi serve le pagine ───────
// Il 14 settembre 2026 si è misurato che in produzione le sessioni stavano
// nella /tmp del container: la configurazione diceva «database», la tabella
// non c'era, e l'applicazione ripiegava in silenzio. Ogni rilascio buttava
// fuori tutti. Ora il modo si sceglie in configurazione (ADR-039), e un modo
// che non può funzionare ferma il container prima che prenda traffico.
try {
    $sessioni = Config::get('session', []);
    $sessioni = is_array($sessioni) ? $sessioni : [];
    $modo = \App\Core\Session::driver($sessioni);
} catch (Throwable $e) {
    guasto($e->getMessage(), 'SESSION_DRIVER nel .env montato');
}
if ($modo === \App\Core\Session::DRIVER_FILE) {
    $cartella = \App\Core\Session::impostazioni($sessioni, $storage)['session.save_path'];
    if (!\App\Core\Session::cartellaPronta($cartella, false)) {
        guasto(
            "la cartella delle sessioni «{$cartella}» non esiste o non è scrivibile da chi serve le pagine",
            'l\'entrypoint la crea in storage/sessions; con SESSION_SAVE_PATH va preparata a mano, di www-data',
        );
    }
    $provaSessione = $cartella . '/.prova-avvio-' . getmypid();
    if (@file_put_contents($provaSessione, "x") === false) {
        guasto(
            "non riesco a scrivere nella cartella delle sessioni «{$cartella}»",
            'proprietario e permessi: www-data, 0700',
        );
    }
    @unlink($provaSessione);
    if ((fileperms($cartella) & 0077) !== 0) {
        guasto(
            "la cartella delle sessioni «{$cartella}» è leggibile da altri: i nomi dei file sono gli id di sessione",
            'chmod 0700',
        );
    }
}

// ── 3. Il database risponde ───────────────────────────────────────────────
// Senza, l'applicazione risponde 500 a tutto. Un container che risponde 500
// non deve mai arrivare a prendere traffico.
if (!$senzaDb) {
    try {
        Database::connection()->query('SELECT 1');
    } catch (Throwable $e) {
        guasto(
            'il database non risponde: ' . $e->getMessage(),
            'DB_HOST/DB_NAME/DB_USER nel .env montato, e che il database sia raggiungibile da dentro il container',
        );
    }
}

// ── 4. I file che il WAF si aspetta di trovare ────────────────────────────
// Se un percorso GeoIP è **configurato** ma il file non c'è, non è una scelta:
// è una configurazione rotta. E il modo in cui si rompe è pessimo —
// `GeoIpService` non trova il database, `country` diventa `null`, e il blocco
// «solo Italia» smette di bloccare senza dire niente a nessuno.
//
// È successo davvero l'8 settembre 2026, passando ai container: le due chiavi
// puntavano dentro il repository (`storage/geoip/`), che nell'immagine non c'è
// perché i dati stanno altrove. Quattro minuti di geoblocco spento, e nessun
// errore da nessuna parte — l'ho trovato confrontando le righe del registro
// del WAF prima e dopo lo scambio, non da un allarme.
//
// Un percorso non configurato affatto resta una scelta legittima: il WAF sa
// cavarsela con l'intestazione di Cloudflare. Uno configurato e assente no.
foreach (['waf.geoip_db' => 'country', 'waf.geoip_asn_db' => 'ASN'] as $chiave => $che) {
    $percorso = Config::get($chiave);
    if (!is_string($percorso) || $percorso === '') {
        continue;
    }
    if (!is_file($percorso)) {
        guasto(
            "il database GeoIP $che è configurato su «{$percorso}» ma il file non c'è",
            'i database GeoIP sono DATI: vanno sotto PANTEDU_DATA_PATH, non dentro il repository — '
                . 'altrimenti nel container non esistono e il geoblocco smette di bloccare in silenzio',
        );
    }
}

// ── 5. I documenti legali di TUTTI gli scenari stanno nell'immagine ──────
// `.dockerignore` esclude `docs/` e ogni `*.md`, e riammette a mano i file che
// l'applicazione legge mentre gira. L'informativa privacy è uno di quelli, ed
// è **una per scenario** (`DeploymentScenario::informativaFile`): chi aggiunge
// uno scenario, o cambia il file di uno, deve riammettere il file nuovo.
//
// Il 12 settembre 2026 il ramo delle classi ha portato la terza informativa
// (`informativa-personale.md`, quella dello scenario 1 — cioè della
// produzione) senza la riga nel `.dockerignore`: in sviluppo e nella suite
// tutto funzionava, e nel container `/privacy/informativa` avrebbe risposto
// «Documento non disponibile». `immagine.yml` sulle pull request parte solo
// se si toccano `docker/` o `.dockerignore`, quindi nessuno se ne sarebbe
// accorto prima del rilascio.
//
// Si controllano tutti e tre gli scenari, non solo quello attivo: cambiare
// scenario dal pannello non ricostruisce l'immagine, e un file che manca deve
// fermare il container adesso, non il giorno in cui qualcuno passa allo
// scenario 2.
foreach (\App\Support\DeploymentScenario::ALL as $scenario) {
    $file = \App\Support\DeploymentScenario::informativaFile($scenario);
    if (!is_file($file) || filesize($file) === 0) {
        // Graffe obbligatorie: «$file» senza graffe fa entrare il «»» nel
        // nome della variabile (i byte alti sono lettere per PHP), e il
        // messaggio esce con il nome vuoto. Misurato il 13 settembre 2026.
        guasto(
            "manca l'informativa privacy dello scenario «{$scenario}»: «{$file}»",
            'la riga `!docs/privacy/<file>` nel .dockerignore: senza, il file non entra '
                . 'nell\'immagine e /privacy/informativa risponde «Documento non disponibile»',
        );
    }
    // 23/9/2026 (DOC-19) — i consensi registrano il `versione:` del testo
    // dello scenario attivo, letto da questo file: senza, non si
    // registrerebbero (DeploymentScenario::versioneInformativa lancia).
    try {
        \App\Support\DeploymentScenario::versioneInformativa($scenario);
    } catch (\RuntimeException) {
        guasto(
            "l'informativa privacy dello scenario «{$scenario}» («{$file}») non dichiara `versione:` nel "
                . 'frontmatter: i consensi di quello scenario non si registrerebbero',
            'il frontmatter del file in docs/privacy (lo controlla anche tools/ci/check-legal-versions.mjs)',
        );
    }
}

// ── 6. In produzione, niente configurazioni che spengono una difesa ──────
// 23/9/2026 — scheda R-4 della revisione architetturale del 23/9 (A-4, A-8,
// A-9, A-37, A-39, A-58). Il container monta il `.env` versionato e sopra
// `.env.local` del server; ogni difesa qui sotto dipendeva da una riga di uno
// dei due, e nessuno la guardava. Il caso che l'ha fatta nascere: il `.env`
// versionato spegneva il limitatore, e in produzione restava acceso solo
// perché `.env.local` ridefiniva la chiave (A-4).
//
// Qui e non nel bootstrap. Il bootstrap gira a ogni richiesta: un errore lì
// è un 500 su ogni pagina, del container che sta già servendo. Qui ferma il
// container NUOVO prima che prenda traffico, e nel rilascio a scambio il
// vecchio continua a servire (tools/webhook/deploy-container.sh, «fermo il
// nuovo»). Vuol dire anche che una riga cambiata in `.env.local` mentre il
// container gira vale subito (i `.env` si rileggono a ogni richiesta) e la
// guardia la vede solo al rilascio successivo, che si ferma, o al primo
// riavvio del container: lì non c'è un container vecchio che continua a
// servire, e il sito resta giù finché la riga non si corregge. Per questo le
// guardie stanno in una funzione: `--solo-configurazione` (sezione 1a) le
// lancia da sole, dall'host, prima di un riavvio.
//
// Si leggono i valori come li legge l'applicazione — la configurazione e le
// classi che la interpretano — non il testo dei file, che si può scrivere in
// molti modi. Nei messaggi non entra mai un segreto, né la sua lunghezza.
//
// Valgono solo con APP_ENV=production. La CI avvia l'immagine con
// APP_ENV=ci e il limitatore spento (i `sed` di e2e.yml e immagine.yml sul
// `.env` copiato da `.env.example`), e lì le guardie non si applicano. Non
// c'è un'altra variabile che le spenga: in produzione APP_ENV arriva solo dal
// `.env` versionato. Contratto dei file e guardie: wiki/environment-variables.md.
function guardieDellaProduzione(): void
{
    $ambiente = (string) Config::get('app.env', '');

    // Un ambiente che il codice non conosce spegnerebbe tutte le guardie per un
    // errore di battitura («prod»), e per l'applicazione non sarebbe produzione:
    // fuori da `production`, per esempio, SelfServiceController mette nella
    // risposta il gettone che conferma la cancellazione dell'account, che
    // altrimenti arriva solo per posta.
    $ambientiNoti = ['production', 'ci', 'testing', 'development', 'local'];
    if (!in_array($ambiente, $ambientiNoti, true)) {
        guasto(
            "[ambiente] APP_ENV vale «{$ambiente}», che il codice non conosce: le guardie della produzione "
                . 'non si applicherebbero, e per l\'applicazione non sarebbe produzione',
            'APP_ENV nel .env montato e in .env.local: uno fra ' . implode(', ', $ambientiNoti)
                . ' (wiki/environment-variables.md)',
        );
    }

    if ($ambiente === 'production') {
        // Il limitatore delle richieste per utente e per rotta (A-4).
        if ((bool) Config::get('security.rate_limit_disabled', false)) {
            guasto(
                '[limitatore] produzione con il limitatore delle richieste spento (RATE_LIMIT_DISABLED=1): '
                    . 'import con i modelli linguistici, moduli che mandano posta e segnalazioni senza limite',
                'RATE_LIMIT_DISABLED in .env.local del server: 0, o nessuna riga. Dove può valere 1: '
                    . 'wiki/environment-variables.md',
            );
        }

        // Il gettone che conferma la cancellazione dell'account, nella risposta
        // (23/9/2026, A-35). Fuori dalla produzione c'è comunque, per le prove;
        // in produzione lo mette solo EXPOSE_DELETION_DEBUG_TOKEN. Fino a quel
        // giorno valeva solo la stringa '1': dalla lettura comune degli
        // interruttori (Config::booleanoDallAmbiente) valgono anche true, yes e
        // on, e un rilascio non deve accenderlo per una riga scritta così.
        if ((bool) Config::get('security.expose_deletion_debug_token', false)) {
            guasto(
                '[gettone] produzione con EXPOSE_DELETION_DEBUG_TOKEN acceso: la risposta alla richiesta di '
                    . 'cancellazione dell\'account conterrebbe il gettone che la conferma',
                'EXPOSE_DELETION_DEBUG_TOKEN in .env.local del server: 0, o nessuna riga (serve solo alle prove)',
            );
        }

        // La motivazione degli interventi amministrativi (ADR-008, A-37).
        $motivazione = (string) Config::get('audit.reason_mode', '');
        if ($motivazione !== 'enforce') {
            guasto(
                "[motivazione] produzione con AUDIT_REASON_MODE «{$motivazione}», non «enforce»: le mutazioni "
                    . 'dei super-admin passerebbero senza motivazione',
                'AUDIT_REASON_MODE in .env.local e nel .env montato: enforce, o nessuna riga (ADR-008). Un percorso '
                    . 'rimasto scoperto si corregge nel client, non spegnendo la regola',
            );
        }

        if ((bool) Config::get('app.debug', false)) {
            guasto(
                '[debug] produzione con APP_DEBUG acceso: le pagine di errore mostrerebbero a chi visita '
                    . 'lo stack e i percorsi',
                'APP_DEBUG in .env.local e nel .env montato: false',
            );
        }

        if (trim((string) Config::get('app.url', '')) === '') {
            guasto(
                '[indirizzo] produzione senza APP_URL: i collegamenti nella posta (reimpostazione della password, '
                    . 'credenziali) e i reindirizzamenti non avrebbero un indirizzo',
                'APP_URL in .env.local del server (wiki/environment-variables.md, «Quale file vale dove»)',
            );
        }

        if (trim((string) Config::get('mail.from', '')) === '') {
            guasto(
                '[posta] produzione senza APP_MAIL_FROM: registrazione, reimpostazione della password, secondo '
                    . 'fattore via email e segnalazioni non manderebbero posta',
                'APP_MAIL_FROM nel .env versionato, o in .env.local',
            );
        }

        // Il provider S3 è uno stub: lancia a ogni scrittura e dice «non c'è» a
        // ogni lettura (A-58). Un nome sconosciuto fa lanciare StorageFactory.
        $provider = (string) Config::get('storage.default_provider', 'local');
        if ($provider !== 'local') {
            guasto(
                $provider === 's3'
                    ? '[archivio] produzione con STORAGE_PROVIDER=s3: il provider S3 non è implementato, '
                        . 'lancia a ogni scrittura e dà «non c\'è» a ogni lettura'
                    : "[archivio] produzione con STORAGE_PROVIDER «{$provider}», che non esiste: "
                        . 'StorageFactory lancia a ogni file',
                'STORAGE_PROVIDER in .env.local: local, o nessuna riga',
            );
        }

        // La chiave HMAC del WAF (A-39): firma il cookie di sessione del WAF e le
        // sfide. Senza WAF_HMAC_SECRET di almeno 32 byte, app/Config/waf.php la
        // genera e la scrive in storage/keys a ogni richiesta che non la trova,
        // con gli errori silenziati; se non riesce a generarla usa una chiave
        // fissa. In produzione la chiave viene dall'ambiente: si legge come la
        // legge waf.php, e si controlla che la configurazione usi proprio quella.
        // Né la chiave né la sua lunghezza finiscono nel messaggio.
        $chiaveWaf = (string) ($_ENV['WAF_HMAC_SECRET'] ?? (getenv('WAF_HMAC_SECRET') ?: ''));
        $chiaveUsata = Config::get('waf.hmac_secret');
        if (strlen($chiaveWaf) < 32 || !is_string($chiaveUsata) || !hash_equals($chiaveWaf, $chiaveUsata)) {
            guasto(
                '[waf] produzione senza una chiave HMAC del WAF stabile: WAF_HMAC_SECRET manca o è più corta '
                    . 'di 32 byte, e app/Config/waf.php ripiegherebbe su una chiave generata e scritta sul posto',
                'WAF_HMAC_SECRET in .env.local del server (openssl rand -hex 32)',
            );
        }

        // Scenario e modo (ADR-032, A-8). Lo scenario allinea il modo legacy di
        // ADR-017 solo quando lo cambia il pannello (DeploymentScenario::persist).
        // Scritti a mano, nel .env o nei file di storage/config, possono dire due
        // cose diverse: l'informativa segue lo scenario, il contatto del DPO e il
        // nome del Titolare il modo. E lo scenario 3 scritto a mano salta la
        // dichiarazione di infrastruttura qualificata ACN che il pannello chiede.
        $scenarioAttivo = DeploymentScenario::current();
        $modoLegacy = DeploymentMode::current();
        // Da dove arriva ciascuno, per dire dove guardare. Per il modo «env»
        // comprende il predefinito `single`, quando nessuno lo scrive.
        $fonteScenario = [
            'runtime_override' => 'storage/config',
            'env'              => '.env',
            'legacy_mode'      => 'dedotto dal modo',
        ][DeploymentScenario::snapshot()['source']] ?? '?';
        $fonteModo = [
            'runtime_override' => 'storage/config',
            'env'              => '.env o predefinito',
        ][DeploymentMode::snapshot()['source']] ?? '?';
        if (($scenarioAttivo === DeploymentScenario::INSTITUTE) !== ($modoLegacy === DeploymentMode::INSTITUTE)) {
            guasto(
                "[scenario] lo scenario «{$scenarioAttivo}» ({$fonteScenario}) e il modo «{$modoLegacy}» "
                    . "({$fonteModo}) non combaciano: l'informativa seguirebbe uno, il contatto del DPO e il "
                    . "Titolare l'altro",
                'DEPLOYMENT_SCENARIO e DEPLOYMENT_MODE in .env.local, e storage/config/deployment_scenario.json e '
                    . 'deployment.json: lo scenario si cambia dal pannello /admin/system/deployment, che allinea '
                    . 'il modo (ADR-032)',
            );
        }
        if (
            $scenarioAttivo === DeploymentScenario::INSTITUTE
            && !DeploymentScenario::instanceAcnQualified()
        ) {
            guasto(
                "[scenario] scenario 3 (istituto, {$fonteScenario}) su un'istanza non dichiarata qualificata ACN",
                'INSTANCE_ACN_QUALIFIED in .env.local: lo dichiara chi conduce l\'istanza (ADR-032); '
                    . 'altrimenti lo scenario va riportato a 1 o 2 dal pannello',
            );
        }

        echo "[avvio] produzione: limitatore, motivazione, gettone, debug, indirizzo, posta, archivio, WAF e scenario "
            . "a posto\n";
    } else {
        echo "[avvio] APP_ENV «{$ambiente}»: le guardie della produzione non si applicano\n";
    }
}

guardieDellaProduzione();

echo "[avvio] configurazione, dati, database, GeoIP e documenti legali: a posto\n";
exit(0);
