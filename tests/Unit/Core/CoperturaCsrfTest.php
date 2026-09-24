<?php

namespace Tests\Unit\Core;

use App\Core\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ogni rotta che cambia stato verifica il gettone CSRF, oppure è esentata qui
 * per iscritto.
 *
 * Perché un test e non una regola semgrep. In `.semgrep.yml` c'era
 * `php-csrf-token-required-on-post`, che provava a dedurre la copertura
 * guardando i controller. Non ha mai funzionato: la regola era malformata
 * (`pattern-not-inside` messo dove non è ammesso) e semgrep la rifiutava.
 * L'azione che lo lanciava restituiva comunque «riuscito», quindi per mesi il
 * controllo verde non ha misurato niente — e sette rotte scrivevano senza
 * verificare il gettone.
 *
 * Il difetto non era solo l'errore di sintassi: la copertura CSRF **non si
 * legge dai controller**. Il middleware si applica alla rotta, in
 * `routes/web.php`, e nessuna analisi del corpo di un metodo può saperlo.
 * Qui si guarda dove la cosa è davvero decisa: la tabella delle rotte.
 *
 * Il test fallisce in due direzioni. Se una rotta nuova scrive senza gettone,
 * fallisce. Se un'esenzione qui sotto non corrisponde più a nessuna rotta,
 * fallisce lo stesso: un elenco di eccezioni che nessuno ripulisce smette di
 * essere un elenco di eccezioni e diventa un posto dove nascondere le cose.
 */
final class CoperturaCsrfTest extends TestCase
{
    /** I metodi che cambiano stato sul server. */
    private const SCRIVONO = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Rotte che cambiano stato senza gettone, e perché.
     *
     * Chiave: `METODO percorso` come dichiarato in routes/web.php.
     *
     * @var array<string, string>
     */
    private const ESENTI = [
        // ── Segnalazioni del browser: il gettone non esiste per definizione ──
        'POST /api/csp-report' =>
            'Le violazioni CSP le manda il browser da sé, non una nostra pagina: '
            . 'non c’è nessuna sessione da cui prendere un gettone. Protetta dal '
            . 'limite di frequenza (rate:csp,60) e dallo schema del corpo.',

        // ── Telemetria via sendBeacon: non può portare intestazioni ──
        'POST /analytics/nav' =>
            '`navigator.sendBeacon` non permette di aggiungere intestazioni, e il '
            . 'corpo è un blob: nessun modo di allegare il gettone. Scrive solo un '
            . 'contatore di navigazione, senza dati dell’utente.',
        'POST /waf/fingerprint' =>
            'L’impronta del WAF si raccoglie **prima** che esista una sessione: è il '
            . 'passo che decide se far entrare. Chiedere un gettone di sessione qui '
            . 'sarebbe circolare. Limite rate:waf_fp,40.',

        // ── Autenticazione che sta nel collegamento, non nella sessione ──
        'POST /parent-consent/{token}' =>
            'Il consenso del genitore si conferma da un collegamento firmato ricevuto '
            . 'per posta: chi non ha il gettone dell’URL non arriva alla rotta, e chi '
            . 'ce l’ha può chiamarla direttamente. Un gettone di sessione non '
            . 'aggiungerebbe niente — il genitore non ha un account.',
        'POST /segnalazione-contenuti' =>
            'Modulo pubblico di segnalazione, aperto a chi non ha un account. Il '
            . 'gettone CSRF vive in sessione, e aprire una sessione a ogni visitatore '
            . 'anonimo significa un cookie su una pagina pubblica — con quello che '
            . 'comporta per l’informativa. Protetta da rate:takedown,3 e dalla '
            . 'validazione del servizio.',

        // ── Uscita ──
        'POST /logout' =>
            'Senza gettone il peggio che può fare un terzo è farti uscire: una '
            . 'seccatura, non una perdita di dati né un’azione a tuo nome. Metterlo '
            . 'costerebbe un 403 a ogni sessione scaduta, cioè impedire di uscire '
            . 'proprio a chi ne ha più bisogno.',

        // ── Rotte che accettano i verbi di scrittura ma non scrivono ──
        'POST /Elementi_Riservati.html' =>
            'Dichiarata con `any()` per retro-compatibilità, ma l’handler `show` '
            . 'legge e basta. Dietro auth + role:teacher + teacher_subjects.',
        'POST /didattica/{path*}' => self::MOTIVO_LEGACY,
        'POST /drafts/{path*}' => self::MOTIVO_LEGACY,
        'POST /eser/{path*}' => self::MOTIVO_LEGACY,
        'POST /lab/{path*}' => self::MOTIVO_LEGACY,
        'POST /risdoc/{path*}' => self::MOTIVO_LEGACY,
        'POST /strcomp_bes_altro/{path*}' => self::MOTIVO_LEGACY,
        'POST /verifiche/{path*}' => self::MOTIVO_LEGACY,
    ];

    private const MOTIVO_LEGACY =
        'Percorso vecchio: `LegacyGoneMiddleware` risponde 410/302 senza mai '
        . 'invocare l’handler, che è una chiusura che non fa niente. Non c’è uno '
        . 'stato da proteggere perché non si arriva a toccarlo.';

    /** I metodi che `CsrfMiddleware` lascia passare senza guardare il gettone. */
    private const NON_CONTROLLATI = ['GET', 'HEAD'];

    /**
     * Le letture che arrivano a un gestore protetto dal gettone, una per una,
     * e perché lì la mancanza del gettone non fa danni.
     *
     * 23/9/2026 (A-77). `CsrfMiddleware` controlla solo i verbi di scrittura:
     * una rotta del gruppo `csrf` che accetta GET o HEAD ha `csrf` fra i
     * middleware, e alla prova sulle scritture risulta coperta, ma in GET il
     * gestore gira senza gettone. `/files/clear-temp` era dichiarata con
     * `any()` e cancellava i temporanei anche in GET: bastava un'immagine su
     * un altro sito aperto da un amministratore.
     *
     * Dalla tabella delle rotte non si vede se un gestore scrive, ma si
     * vedono le due vie da cui una lettura ci arriva, e tutte e due passano
     * di qui (vedi `lettureSottoIlGettone()`):
     *
     *  1. una rotta del gruppo `csrf` che accetta GET o HEAD, da sola
     *     (`get()`) o insieme ai verbi di scrittura (`any()`): stare sotto il
     *     gettone è già una dichiarazione che la cosa scrive;
     *  2. una rotta che accetta GET o HEAD, nel gruppo o fuori, con lo stesso
     *     gestore di una rotta che scrive sotto il gettone.
     *
     * Raggruppare per percorso non serve: più di quaranta percorsi hanno la
     * pagina in GET e l'invio in POST, con due gestori diversi, e sarebbero
     * tutti eccezioni da scrivere qui sotto senza che nessuna dica niente.
     *
     * Chiave: `GET percorso` (o `HEAD percorso`, se la rotta non accetta GET).
     *
     * @var array<string, string>
     */
    private const LETTURA_AMMESSA = [
        // ── Dichiarate con `any()` ──
        'GET /check/password' =>
            'Dichiarata con `any()`. `CheckController::password` legge la password '
            . 'dal corpo del modulo, che in GET è vuoto: `CheckService` risponde '
            . 'falso senza guardare niente, e nessuno dei due scrive.',
        'GET /delete_temp.php' =>
            'Scrive anche in GET, e il gettone non la protegge: la ferma la guardia '
            . 'di `CronController::deleteTemp` (CLI, oppure REMOTE_ADDR 127.0.0.1 o '
            . '::1; nel container REMOTE_ADDR è il client ricostruito da '
            . 'docker/nginx.conf). Un browser guidato da un altro sito non la passa. '
            . 'I chiamanti dichiarati sono cron esterni, non misurati: restringerla '
            . 'a un verbo è una decisione a parte (23/9/2026, A-77).',

        // ── `get()` dentro un gruppo `csrf`: il gettone viene dal gruppo ──
        'GET /api/verifica/{id}/tex-files' =>
            'Lettura e scrittura sono due dichiarazioni con due gestori: la GET va '
            . 'a `VerificaController::getTexFiles`, che legge il manifesto dei file '
            . 'TeX della verifica del docente e non scrive, la POST a '
            . '`updateTexFiles`, che scrive col gettone. Il `csrf` sulla GET viene '
            . 'dal gruppo ed è soltanto superfluo.',
        'GET /api/verifica/jobs/{jobId}' =>
            '`VerificaCompileController::getJob` legge lo stato di una compilazione '
            . 'del docente (la cerca col suo id). Se è ancora in coda la esegue '
            . 'subito invece di aspettare il processo di fondo: l’unica scrittura è '
            . 'portare avanti un lavoro che il docente ha già chiesto con una POST '
            . 'col gettone (`compile-async`).',
        'GET /api/teacher/content/{id}/provenance' =>
            '`ContentExportController::provenance` risale la catena delle copie di '
            . 'un contenuto con una SELECT sola e restituisce id e titoli: non '
            . 'scrive niente.',
        'GET /admin/waf/api/cti' =>
            '`WafAdminController::apiCti` chiede a CrowdSec che cosa sa di un '
            . 'indirizzo e ne conserva la risposta per ventiquattr’ore nella cache '
            . 'CTI: scrive solo una copia di ciò che dice il servizio esterno, '
            . 'dietro super_admin_required. È nel gruppo delle scritture del '
            . 'pannello WAF dall’8/9/2026 (#5), senza un motivo scritto.',
    ];

    /** @return list<array{0: string, 1: \App\Core\Route}> */
    private function rotteCheScrivono(): array
    {
        $router = new Router();
        require \dirname(__DIR__, 3) . '/routes/web.php';

        $fuori = [];
        foreach ($router->routes() as $rotta) {
            $scrive = array_values(array_intersect($rotta->methods, self::SCRIVONO));
            if ($scrive === []) {
                continue;
            }
            // Una rotta dichiarata con `any()` compare con tutti i verbi: la
            // chiave usa il primo che scrive, così l'elenco delle esenzioni non
            // deve ripetere quattro volte la stessa riga.
            $fuori[] = [$scrive[0] . ' ' . $rotta->pattern, $rotta];
        }
        return $fuori;
    }

    #[Test]
    public function ogni_rotta_che_scrive_verifica_il_gettone(): void
    {
        $scoperte = [];
        foreach ($this->rotteCheScrivono() as [$chiave, $rotta]) {
            if (\in_array('csrf', $rotta->middleware, true)) {
                continue;
            }
            if (isset(self::ESENTI[$chiave])) {
                continue;
            }
            $scoperte[] = $chiave . '   [' . implode(' ', $rotta->middleware) . ']';
        }

        sort($scoperte);
        $this->assertSame([], $scoperte, sprintf(
            "%d rotte cambiano stato senza verificare il gettone CSRF.\n\n"
            . "Se la rotta scrive davvero, aggiungi ->middleware('csrf') in "
            . "routes/web.php e controlla che il client mandi il gettone (il "
            . "canonico lato JS è `fetchCsrf()` in js/modules/core/dom-utils.js).\n\n"
            . "Se invece l'esenzione è legittima, mettila in ESENTI qui sopra "
            . "**con il motivo scritto**.\n\nScoperte:\n  %s",
            \count($scoperte),
            implode("\n  ", $scoperte)
        ));
    }

    #[Test]
    public function nessuna_esenzione_e_rimasta_orfana(): void
    {
        $vive = array_column($this->rotteCheScrivono(), 0);

        $orfane = [];
        foreach (array_keys(self::ESENTI) as $chiave) {
            if (!\in_array($chiave, $vive, true)) {
                $orfane[] = $chiave;
            }
        }

        sort($orfane);
        $this->assertSame([], $orfane, sprintf(
            "%d esenzioni non corrispondono più a nessuna rotta.\n\n"
            . "La rotta è stata rinominata o tolta: cancella la riga da ESENTI. "
            . "Un elenco di eccezioni che nessuno ripulisce smette di dire quali "
            . "sono le eccezioni.\n\nOrfane:\n  %s",
            \count($orfane),
            implode("\n  ", $orfane)
        ));
    }

    /**
     * Nome stabile di un gestore, per riconoscerlo in due dichiarazioni.
     * Le chiusure non hanno un nome: tornano null e restano fuori dalla
     * seconda via (nel sito sono solo i percorsi vecchi di `LegacyGone`).
     */
    private static function nomeDelGestore(mixed $gestore): ?string
    {
        if (\is_array($gestore) && \count($gestore) === 2
            && \is_string($gestore[0]) && \is_string($gestore[1])) {
            return ltrim($gestore[0], '\\') . '::' . $gestore[1];
        }
        return \is_string($gestore) ? ltrim($gestore, '\\') : null;
    }

    /**
     * Le letture che arrivano a un gestore protetto dal gettone, con la
     * chiave di LETTURA_AMMESSA e la via da cui ci arrivano (vedi il commento
     * di LETTURA_AMMESSA).
     *
     * @param list<\App\Core\Route>|null $rotte null = le rotte del sito
     * @return array<string, string> chiave => perché è stata presa
     */
    private function lettureSottoIlGettone(?array $rotte = null): array
    {
        if ($rotte === null) {
            $router = new Router();
            require \dirname(__DIR__, 3) . '/routes/web.php';
            $rotte = $router->routes();
        }

        // I gestori che scrivono sotto il gettone, con la rotta da cui scrivono.
        $scriveColGettone = [];
        foreach ($rotte as $rotta) {
            $nome = self::nomeDelGestore($rotta->handler);
            if ($nome === null || !\in_array('csrf', $rotta->middleware, true)) {
                continue;
            }
            $scrive = array_values(array_intersect($rotta->methods, self::SCRIVONO));
            if ($scrive !== []) {
                $scriveColGettone[$nome] ??= $scrive[0] . ' ' . $rotta->pattern;
            }
        }

        $fuori = [];
        foreach ($rotte as $rotta) {
            $leggono = array_values(array_intersect($rotta->methods, self::NON_CONTROLLATI));
            if ($leggono === []) {
                continue;
            }
            $chiave = $leggono[0] . ' ' . $rotta->pattern;
            $nome = self::nomeDelGestore($rotta->handler);
            if (\in_array('csrf', $rotta->middleware, true)) {
                $fuori[$chiave] = 'sotto il gettone accetta ' . implode(',', $rotta->methods);
            } elseif ($nome !== null && isset($scriveColGettone[$nome])) {
                $fuori[$chiave] = "stesso gestore ($nome) di " . $scriveColGettone[$nome];
            }
        }
        ksort($fuori);
        return $fuori;
    }

    /** @param array<string, string> $letture */
    private static function nonAmmesse(array $letture): array
    {
        $aperte = [];
        foreach ($letture as $chiave => $perche) {
            if (!isset(self::LETTURA_AMMESSA[$chiave])) {
                $aperte[] = "$chiave   ($perche)";
            }
        }
        return $aperte;
    }

    #[Test]
    public function il_gettone_non_si_aggira_con_una_get(): void
    {
        $aperte = self::nonAmmesse($this->lettureSottoIlGettone());

        $this->assertSame([], $aperte, sprintf(
            "%d letture arrivano a un gestore protetto dal gettone CSRF, e "
            . "CsrfMiddleware in GET e HEAD non lo controlla.\n\n"
            . "Se il gestore scrive, dichiara la rotta solo col verbo che il client "
            . "usa davvero (->post(), non ->get() né ->any()): in GET girerebbe "
            . "senza gettone, anche da una pagina di un altro sito.\n\n"
            . "Se in GET non fa niente di male, mettila in LETTURA_AMMESSA qui "
            . "sopra **con il motivo scritto**.\n\nAperte:\n  %s",
            \count($aperte),
            implode("\n  ", $aperte)
        ));
    }

    /**
     * Le due vie della prova qui sopra, su tabelle di rotte scritte apposta:
     * ognuna scatta da sola, e una rotta che legge con un altro gestore, fuori
     * dal gruppo, non scatta. Senza questa, un aiutante che non trovasse più
     * niente farebbe verde la prova sul sito.
     */
    #[Test]
    public function le_due_vie_scattano_quando_devono_e_tacciono_quando_non_devono(): void
    {
        $svuota = ['App\Controllers\FileController', 'clearTemp'];
        $pagina = ['App\Controllers\FileController', 'mostra'];
        $rotta = static fn (array $verbi, string $percorso, array $gestore, array $mw): \App\Core\Route
            => new \App\Core\Route($verbi, $percorso, $gestore, $mw);

        // Via 1: una get() sotto il gettone, al posto della post().
        $this->assertSame(
            ['GET /zz/svuota' => 'sotto il gettone accetta GET,HEAD'],
            $this->lettureSottoIlGettone([$rotta(['GET', 'HEAD'], '/zz/svuota', $svuota, ['auth', 'csrf'])])
        );
        // Via 1 con any().
        $this->assertArrayHasKey('GET /zz/svuota', $this->lettureSottoIlGettone([
            $rotta(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], '/zz/svuota', $svuota, ['csrf']),
        ]));
        // Via 2: la post() resta, e una get() fuori dal gruppo porta allo stesso gestore.
        $this->assertSame(
            ['GET /zz/altro' => 'stesso gestore (App\Controllers\FileController::clearTemp) di POST /zz/svuota'],
            $this->lettureSottoIlGettone([
                $rotta(['POST'], '/zz/svuota', $svuota, ['auth', 'csrf']),
                $rotta(['GET', 'HEAD'], '/zz/altro', $svuota, ['auth']),
            ])
        );
        // Non scatta: pagina in GET e invio in POST, stesso percorso, due gestori.
        $this->assertSame([], $this->lettureSottoIlGettone([
            $rotta(['GET', 'HEAD'], '/zz/svuota', $pagina, ['auth']),
            $rotta(['POST'], '/zz/svuota', $svuota, ['auth', 'csrf']),
        ]));
        // Non scatta: lo stesso gestore in GET, ma la scrittura non ha il gettone
        // (quella la prende la prova sulle scritture, non questa).
        $this->assertSame([], $this->lettureSottoIlGettone([
            $rotta(['POST'], '/zz/svuota', $svuota, ['auth']),
            $rotta(['GET', 'HEAD'], '/zz/altro', $svuota, ['auth']),
        ]));
    }

    #[Test]
    public function nessuna_lettura_ammessa_e_rimasta_orfana(): void
    {
        // Vale anche da controllo positivo: se l'aiutante non vedesse più le
        // letture sotto il gettone, queste righe diventerebbero orfane e la
        // prova di sopra un verde che non misura.
        $vive = array_keys($this->lettureSottoIlGettone());

        $orfane = array_values(array_diff(array_keys(self::LETTURA_AMMESSA), $vive));
        sort($orfane);
        $this->assertSame([], $orfane, sprintf(
            "%d righe di LETTURA_AMMESSA non corrispondono più a nessuna lettura "
            . "sotto il gettone: cancellale.\n\nOrfane:\n  %s",
            \count($orfane),
            implode("\n  ", $orfane)
        ));
    }

    /**
     * Il caso di A-77 detto per nome: con le rotte del sito, GET e HEAD su
     * `/files/clear-temp` non trovano nessuna rotta, POST sì (ed è quella col
     * gettone). Il verso POST dice che la ricerca funziona.
     */
    #[Test]
    public function una_get_su_clear_temp_non_trova_nessuna_rotta(): void
    {
        $router = new Router();
        require \dirname(__DIR__, 3) . '/routes/web.php';

        $serverPrima = $_SERVER;
        try {
            $_SERVER['REQUEST_URI'] = '/files/clear-temp';
            foreach (['GET', 'HEAD'] as $metodo) {
                $_SERVER['REQUEST_METHOD'] = $metodo;
                $lettura = new \App\Core\Request('');
                $this->assertNull(
                    $router->match($lettura),
                    "$metodo /files/clear-temp non deve arrivare a nessun gestore"
                );
            }
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $scrittura = new \App\Core\Request('');
            $rotta = $router->match($scrittura);
            $this->assertNotNull($rotta, 'POST /files/clear-temp esiste');
            $this->assertSame('App\Controllers\FileController::clearTemp', self::nomeDelGestore($rotta->handler));
            $this->assertContains('csrf', $rotta->middleware);
        } finally {
            $_SERVER = $serverPrima;
        }
    }

    #[Test]
    public function ogni_esenzione_porta_un_motivo_scritto(): void
    {
        foreach (self::ESENTI + self::LETTURA_AMMESSA as $chiave => $motivo) {
            $this->assertGreaterThan(
                60,
                mb_strlen(trim($motivo)),
                "L'esenzione '$chiave' non spiega perché. Una riga di motivo che "
                . "non si può leggere fra sei mesi non è un motivo."
            );
        }
    }
}
