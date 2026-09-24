<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Il registro delle cose che non dovrebbero succedere.
 *
 * Non è un altro log applicativo: quelli ci sono già (`AccessLogger` per le
 * visite, i registri del WAF per i blocchi).
 * Questo raccoglie una categoria sola, quella che finora non lasciava traccia:
 * **le incoerenze interne**. Un pezzo del sistema si accorge che un altro
 * pezzo si sta comportando in un modo che, se il codice fosse giusto, non
 * potrebbe verificarsi.
 *
 * Perché serviva. Il 9 settembre 2026 sono venuti fuori insieme:
 *
 *   - quattro funzioni che rispondevano 403 da mesi perché il client mandava
 *     un gettone CSRF vuoto — leggeva un `<meta>` che nessuna vista emette;
 *   - un controllo di sicurezza in CI verde da mesi che non scansionava
 *     niente, perché una regola malformata faceva uscire semgrep e l'azione
 *     restituiva «riuscito» lo stesso;
 *   - percorsi GeoIP configurati verso file inesistenti, con il blocco
 *     geografico spento in silenzio.
 *
 * Tre guasti diversi, una sola forma: **qualcosa non funzionava e nessuno
 * poteva accorgersene**, perché il fallimento assomigliava al normale. Un 403
 * è indistinguibile da un tentativo di attacco; un file mancante è
 * indistinguibile da una regola che non scatta; un controllo verde è
 * indistinguibile da un controllo che passa.
 *
 * Qui si scrive quando la differenza si può fare. `csrf_gettone_vuoto` non è
 * un attacco: chi attacca non manda una stringa vuota, manda un gettone
 * plausibile. È un nostro difetto, e va detto con quel nome.
 *
 * Formato: una riga JSON per anomalia, in `{logs}/anomalie.jsonl`. Lo legge
 * `tools/ops/diagnostica.php`, che gira a timer e manda una mail se trova
 * qualcosa (via `OnFailure=` e `avvisa_guasto.php`).
 *
 * Due regole di condotta:
 *
 *   1. **Non può far fallire chi la chiama.** Se scrivere non riesce, si
 *      rinuncia, e lo si dice in `error_log` (nel container finisce in
 *      `docker logs`). Un registro che rompe la richiesta che stava
 *      osservando è peggio del problema che segnala; ma un registro che perde
 *      righe senza dirlo è il guasto che esiste per combattere. Fino al 19
 *      settembre 2026 qui c'era «si rinuncia in silenzio», e dal container
 *      (www-data, con il file 0640 dell'host) nessuna riga arrivava.
 *   2. **Non allaga.** Lo stesso codice viene scritto al massimo una volta
 *      ogni `FINESTRA_SECONDI` per chiave; le occorrenze saltate si contano e
 *      finiscono nella riga successiva (`saltate`). Un client rotto fa
 *      migliaia di richieste all'ora: senza questo, il registro diventa
 *      illeggibile proprio quando serve. Contare invece di buttare è la
 *      differenza fra limitare il rumore e nascondere il segnale.
 */
final class Anomalia
{
    /** Una riga per chiave ogni cinque minuti; il resto si conta. */
    private const FINESTRA_SECONDI = 300;

    /** Oltre questa dimensione il file viene ruotato in `.1`. */
    private const BYTE_MASSIMI = 5 * 1024 * 1024;

    /**
     * Quante righe finali contano come «coda» per segnalare le righe rotte.
     *
     * Una riga illeggibile piu' vecchia di queste e' archeologia: il registro
     * ruota per dimensione, quindi se ne andra' da sola, e un allarme che non
     * si puo' spegnere insegna a ignorare gli allarmi.
     */
    private const RIGHE_IN_CODA = 200;

    /**
     * Registra un'anomalia.
     *
     * @param string               $codice   identificatore stabile, minuscolo con
     *                                       trattini bassi: si cerca nei registri e
     *                                       si conta. Es. `csrf_gettone_vuoto`.
     * @param string               $cosa     una frase in italiano per chi legge.
     * @param array<string, mixed> $dettagli contesto. **Niente dati personali e
     *                                       niente segreti**: il gettone no, la
     *                                       rotta sì.
     * @param string|null          $chiave   per raggruppare il contenimento del
     *                                       rumore (default: il codice).
     */
    public static function registra(
        string $codice,
        string $cosa,
        array $dettagli = [],
        ?string $chiave = null,
    ): void {
        try {
            $chiave ??= $codice;
            $saltate = self::contieniRumore($codice, $chiave);
            if ($saltate === null) {
                return; // dentro la finestra: contata, non scritta.
            }

            $riga = [
                'quando'  => date('c'),
                'codice'  => $codice,
                'cosa'    => $cosa,
                'dettagli' => $dettagli,
            ];
            if ($saltate > 0) {
                $riga['saltate'] = $saltate;
            }

            // Prima la riga, poi lo stato (19/9/2026). Nell'ordine opposto,
            // com'era, una riga che non si scriveva lasciava lo stato avanzato:
            // per cinque minuti ogni altra occorrenza veniva «contata» e
            // buttata, e il conto spariva con lei. Misurato in produzione: il
            // rilascio del 16/9 aveva aggiornato lo stato dal container e nel
            // registro non c'era niente.
            $file = self::percorso();
            self::ruotaSeGrande($file);
            $nuovo = !is_file($file);
            $scritta = @file_put_contents(
                $file,
                json_encode($riga, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
                FILE_APPEND | LOCK_EX,
            );
            if ($scritta === false) {
                // La riga non c'è. Si dice — ma **una volta per finestra**, non
                // a ogni occorrenza (20/9/2026).
                //
                // Il 19 settembre qui si usciva senza toccare lo stato: giusto
                // per il registro (lo stato non deve dire «scritta» per una
                // riga che non c'è), sbagliato per tutto il resto. Senza stato
                // il file `.stato` non nasceva, ogni occorrenza ripassava di
                // qui e `error_log` prendeva una riga per richiesta. Un client
                // rotto ne fa migliaia all'ora: il rimedio contro il rumore
                // diventava la sorgente del rumore, e proprio quando il
                // registro è rotto, cioè quando quei messaggi servono. Ed è lo
                // stato in cui la produzione resta finché `anomalie.jsonl` non
                // viene riaperto al gruppo (docs/ops/diagnostica.md).
                //
                // Adesso la finestra si segna lo stesso, ma **senza azzerare il
                // conto**: le occorrenze rimaste senza riga restano contate, e
                // la prima riga che riuscirà a scriversi se le porterà dietro
                // in `saltate`. Lo stato non dice mai «scritta»: dice
                // «tentata», e la differenza la fa il conto che non riparte.
                error_log("[anomalia] non riesco a scrivere {$file}: {$codice} — " . mb_substr($cosa, 0, 300));
                self::segnaTentativo($codice, $chiave, $saltate + 1);
                return;
            }
            if ($nuovo) {
                self::permessiCondivisi($file);
            }
            self::segnaScritta($codice, $chiave);
        } catch (\Throwable $e) {
            // Vedi la regola 1 nel commento della classe.
            error_log("[anomalia] {$codice} non registrata: " . $e->getMessage());
        }
    }

    /** Il file del registro. */
    public static function percorso(): string
    {
        $dir = (string)Config::get('app.paths.logs', \dirname(__DIR__, 2) . '/storage/logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/anomalie.jsonl';
    }

    /**
     * Decide se scrivere. Ritorna il numero di occorrenze saltate dall'ultima
     * scrittura (0 se è la prima), oppure `null` se questa va saltata — e in
     * quel caso la conta. Se decide di scrivere **non** tocca lo stato: lo fa
     * `segnaScritta()`, e solo dopo che la riga c'è.
     */
    private static function contieniRumore(string $codice, string $chiave): ?int
    {
        $id = $codice . '|' . $chiave;
        $tutti = self::leggiStato();

        $ultima = (int)($tutti[$id]['ultima'] ?? 0);
        $saltate = (int)($tutti[$id]['saltate'] ?? 0);

        if (time() - $ultima < self::FINESTRA_SECONDI) {
            $tutti[$id] = ['ultima' => $ultima, 'saltate' => $saltate + 1];
            self::scriviStato($tutti);
            return null;
        }
        return $saltate;
    }

    /**
     * La riga **non** è nel registro: la finestra riparte lo stesso — o
     * `error_log` prenderebbe una riga per occorrenza — ma il conto continua.
     *
     * `$saltate` comprende questa occorrenza: anche lei non ha avuto la sua
     * riga.
     */
    private static function segnaTentativo(string $codice, string $chiave, int $saltate): void
    {
        $tutti = self::leggiStato();
        $tutti[$codice . '|' . $chiave] = ['ultima' => time(), 'saltate' => $saltate];
        self::scriviStato($tutti);
    }

    /** La riga è nel registro: da adesso parte la finestra, e il conto riparte da zero. */
    private static function segnaScritta(string $codice, string $chiave): void
    {
        $adesso = time();
        $tutti = self::leggiStato();
        $tutti[$codice . '|' . $chiave] = ['ultima' => $adesso, 'saltate' => 0];
        // Le voci vecchie non servono a nessuno e il file crescerebbe.
        foreach ($tutti as $k => $v) {
            if ($adesso - (int)($v['ultima'] ?? 0) > 86400) {
                unset($tutti[$k]);
            }
        }
        self::scriviStato($tutti);
    }

    /** @return array<string, array{ultima?: int, saltate?: int}> */
    private static function leggiStato(): array
    {
        $stato = self::percorso() . '.stato';
        if (!is_file($stato)) {
            return [];
        }
        $letto = json_decode((string)@file_get_contents($stato), true);
        return is_array($letto) ? $letto : [];
    }

    /** @param array<string, array{ultima?: int, saltate?: int}> $tutti */
    private static function scriviStato(array $tutti): void
    {
        $stato = self::percorso() . '.stato';
        $nuovo = !is_file($stato);
        if (@file_put_contents($stato, json_encode($tutti), LOCK_EX) === false) {
            // Il conto delle occorrenze saltate si perde: lo si dice. Il
            // registro invece resta giusto — al peggio più righe, non meno.
            error_log("[anomalia] non riesco a scrivere {$stato}: il contenimento del rumore non tiene il conto");
            return;
        }
        if ($nuovo) {
            self::permessiCondivisi($stato);
        }
    }

    /**
     * Un file del registro appena creato si apre al gruppo, e prende il gruppo
     * della cartella (19/9/2026).
     *
     * Il registro lo scrivono utenti diversi: `pantedu` dall'host (diagnostica,
     * lavori a orario), `www-data` dal container, root dagli strumenti notturni.
     * Stanno tutti nel gruppo `www-data`, che è il gruppo della cartella dei
     * registri in produzione (misurato: `pantedu:www-data` 0770, senza setgid).
     * Con la umask comune un file nasceva 0644 o 0640, e con il gruppo
     * primario di chi lo creava: il primo che lo creava escludeva gli altri.
     * `anomalie.jsonl` era 0640 e il container non ci scriveva.
     */
    private static function permessiCondivisi(string $file): void
    {
        @chmod($file, 0o660);
        $gruppo = @filegroup(\dirname($file));
        if ($gruppo !== false && @filegroup($file) !== $gruppo) {
            @chgrp($file, $gruppo);
        }
    }

    private static function ruotaSeGrande(string $file): void
    {
        if (is_file($file) && filesize($file) > self::BYTE_MASSIMI) {
            @rename($file, $file . '.1');
        }
    }

    /**
     * Le anomalie più recenti, per chi legge (diagnostica, pannello admin).
     *
     * @return list<array<string, mixed>>
     */
    public static function recenti(int $entroSecondi = 86400, int $massimo = 500): array
    {
        $file = self::percorso();
        if (!is_file($file)) {
            return [];
        }
        $limite = time() - $entroSecondi;
        $fuori = [];

        // Una riga che non si riesce a leggere è un messaggio perso, e va detto.
        //
        // Prima qui c'era `continue` e basta. Il 9 settembre 2026 il controllo
        // d'integrità ha scritto in questo registro un allarme vero — «AIDE è
        // uscito 17», cioè non è nemmeno partito — ma lo ha scritto con dentro
        // un numero calcolato male, che ha spezzato la riga JSON in tre. La
        // diagnostica le ha scartate tutte e tre in silenzio e ha detto
        // «nessuna incoerenza».
        //
        // L'allarme era stato dato e nessuno l'ha sentito: la stessa forma di
        // guasto che questo file esiste per combattere, un piano più sotto.
        //
        // Contano solo le righe illeggibili **in coda**. Una riga rotta di
        // mesi fa è archeologia, e un allarme che non si può più spegnere
        // insegna a ignorare gli allarmi; il registro ruota per dimensione,
        // quindi prima o poi se ne va da sola.
        $righe = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $sogliaCoda = max(0, \count($righe) - self::RIGHE_IN_CODA);
        $illeggibili = 0;

        foreach ($righe as $i => $riga) {
            $voce = json_decode($riga, true);
            if (!is_array($voce) || !isset($voce['quando'])) {
                if ($i >= $sogliaCoda) {
                    $illeggibili++;
                }
                continue;
            }
            if (strtotime((string)$voce['quando']) < $limite) {
                continue;
            }
            $fuori[] = $voce;
        }

        if ($illeggibili > 0) {
            $fuori[] = [
                'quando'  => date('c'),
                'codice'  => 'registro_riga_illeggibile',
                'cosa'    => "$illeggibili righe in coda a questo registro non sono JSON "
                    . 'valido: qualcosa ha provato a segnalare qualcosa e il messaggio '
                    . 'è andato perso. Vanno lette a mano e riparate.',
                'saltate' => $illeggibili - 1,
            ];
        }

        return \array_slice($fuori, -$massimo);
    }

    // ── «Visto» ───────────────────────────────────────────────────────────
    //
    // Il problema che risolvono i tre metodi qui sotto.
    //
    // La diagnostica guarda le ultime ventiquattr'ore. Quindi dopo **qualunque**
    // anomalia vera resta rossa per un giorno intero, mandando una mail a ogni
    // giro — alle 07:00 e alle 19:00 — anche quando il problema è già stato
    // guardato e risolto mezz'ora dopo.
    //
    // Questo è un problema serio, non un fastidio. Un allarme che continua a
    // suonare dopo la riparazione insegna a spegnere l'allarme, e la giornata
    // del 9 settembre 2026 è cominciata proprio scoprendo controlli che
    // nessuno leggeva più.
    //
    // Il caso concreto che l'ha reso necessario: `unattended-upgrades` è
    // attivo, e ogni pacchetto aggiornato cambia file sotto `/usr`, che AIDE
    // sorveglia. Circa una volta a settimana arriva quindi un
    // `aide_differenze` **vero e atteso**. Senza un modo di dire «visto», in un
    // mese quelle mail smettono di essere lette.
    //
    // Come funziona, e perché non è un modo di zittire:
    //
    //   - si segna un **livello d'acqua** per codice: l'istante dell'ultima
    //     occorrenza che si è guardata;
    //   - una nuova occorrenza dello stesso codice, con un istante successivo,
    //     suona di nuovo;
    //   - niente viene cancellato dal registro: le righe restano tutte, e
    //     `recenti()` continua a restituirle. È la diagnostica che decide cosa
    //     conta come «nuovo», e lo dice apertamente nel suo resoconto.
    //
    // Zittire vuol dire non vedere più; questo vuol dire aver visto.

    /** Il file dei livelli d'acqua. */
    public static function percorsoVisti(): string
    {
        return self::percorso() . '.visti';
    }

    /**
     * I livelli d'acqua per codice: `['aide_differenze' => '2026-09-09T11:04:00+00:00']`.
     *
     * @return array<string, string>
     */
    public static function vistiFinoA(): array
    {
        $file = self::percorsoVisti();
        if (!is_file($file)) {
            return [];
        }
        $letto = json_decode((string)@file_get_contents($file), true);
        if (!is_array($letto)) {
            // Un file illeggibile qui non deve **nascondere** niente: si
            // riparte da zero, cioè tutto torna a suonare. L'errore, se c'è,
            // sbaglia dalla parte del rumore e non da quella del silenzio.
            return [];
        }
        $fuori = [];
        foreach ($letto as $codice => $voce) {
            $quando = \is_array($voce) ? ($voce['fino_a'] ?? null) : $voce;
            if (\is_string($codice) && \is_string($quando) && $quando !== '') {
                $fuori[$codice] = $quando;
            }
        }
        return $fuori;
    }

    /**
     * Come `vistiFinoA()`, ma con tutto quello che è stato registrato: chi ha
     * segnato, quando, **e perché**. Serve a chi deve rileggere una decisione
     * presa mesi prima, che è l'unico motivo per cui la motivazione si scrive.
     *
     * Una voce scritta prima del 22/9/2026 non ha la motivazione: esce con
     * `perche` vuoto, e chi legge deve poterlo distinguere da una motivazione
     * scritta male.
     *
     * @return array<string, array{fino_a: string, segnato: string, da: string, perche: string, storico: list<array<string,string>>}>
     */
    public static function vistiPerEsteso(): array
    {
        $file = self::percorsoVisti();
        if (!is_file($file)) {
            return [];
        }
        $letto = json_decode((string)@file_get_contents($file), true);
        if (!\is_array($letto)) {
            return [];
        }
        $fuori = [];
        foreach ($letto as $codice => $voce) {
            if (!\is_string($codice)) {
                continue;
            }
            // Formato antico: il valore era la sola data.
            if (\is_string($voce)) {
                $fuori[$codice] = ['fino_a' => $voce, 'segnato' => '', 'da' => '', 'perche' => '', 'storico' => []];
                continue;
            }
            if (!\is_array($voce) || !\is_string($voce['fino_a'] ?? null)) {
                continue;
            }
            $storico = [];
            foreach (\is_array($voce['storico'] ?? null) ? $voce['storico'] : [] as $vecchia) {
                if (\is_array($vecchia)) {
                    $storico[] = array_map(static fn($v): string => \is_string($v) ? $v : '', $vecchia);
                }
            }
            $fuori[$codice] = [
                'fino_a'  => $voce['fino_a'],
                'segnato' => \is_string($voce['segnato'] ?? null) ? $voce['segnato'] : '',
                'da'      => \is_string($voce['da'] ?? null) ? $voce['da'] : '',
                'perche'  => \is_string($voce['perche'] ?? null) ? $voce['perche'] : '',
                'storico' => $storico,
            ];
        }
        return $fuori;
    }

    /** Sotto questa lunghezza una motivazione non dice niente: si rifiuta. */
    public const MOTIVO_MINIMO = 15;

    /**
     * Segna un codice come visto fino a un certo istante.
     *
     * `$finoA` è la data della **occorrenza più recente guardata**, non
     * `adesso`: usare l'ora corrente rischierebbe di inghiottire una riga
     * scritta nel frattempo, cioè proprio quella che nessuno ha ancora visto.
     *
     * `$perche` è obbligatorio dal 22/9/2026, e il motivo è una regola di
     * progetto: «marcare un'anomalia come vista è legittimo solo dopo aver
     * guardato ogni differenza una per una, **e va scritto perché**»
     * (CLAUDE.md). Fino a quel giorno si registravano il codice, l'istante e
     * il nome di chi segnava: bastava a sapere *che* qualcuno aveva guardato,
     * non *che cosa aveva visto*. Sei mesi dopo, davanti a un livello d'acqua,
     * non resta modo di sapere se sotto c'era un aggiornamento di pacchetti o
     * un ingresso che nessuno sa spiegare.
     *
     * Le motivazioni precedenti non si perdono: restano in `storico`, perché
     * un codice che viene chiuso ogni settimana con la stessa frase è esso
     * stesso un'informazione — e perché qui non si cancella niente.
     *
     * Restituisce `false` anche quando la motivazione manca o è troppo corta,
     * e in quel caso **non scrive nulla**: l'anomalia continua a suonare.
     */
    public static function segnaVisto(
        string $codice,
        string $finoA,
        string $chi,
        string $perche,
    ): bool {
        if (mb_strlen(trim($perche)) < self::MOTIVO_MINIMO) {
            return false;
        }
        $file = self::percorsoVisti();
        $tutti = [];
        if (is_file($file)) {
            $letto = json_decode((string)@file_get_contents($file), true);
            if (is_array($letto)) {
                $tutti = $letto;
            }
        }
        $precedente = $tutti[$codice] ?? null;
        $storico    = \is_array($precedente) && \is_array($precedente['storico'] ?? null)
            ? $precedente['storico']
            : [];
        if (\is_array($precedente) && \is_string($precedente['perche'] ?? null)) {
            array_unshift($storico, [
                'fino_a'  => (string)($precedente['fino_a'] ?? ''),
                'segnato' => (string)($precedente['segnato'] ?? ''),
                'da'      => (string)($precedente['da'] ?? ''),
                'perche'  => $precedente['perche'],
            ]);
            $storico = \array_slice($storico, 0, 20);
        }

        $tutti[$codice] = [
            'fino_a'  => $finoA,
            'segnato' => date('c'),
            'da'      => $chi,
            'perche'  => trim($perche),
            'storico' => $storico,
        ];
        $scritto = @file_put_contents(
            $file,
            json_encode($tutti, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX,
        ) !== false;

        // Chi scrive e chi legge non sono lo stesso utente.
        //
        // `visto.php` si lancia dall'host come `pantedu`; la diagnostica gira
        // **dentro il container come `www-data`**. Con la umask predefinita
        // questo file usciva `pantedu:pantedu` e `www-data` non lo apriva: i
        // livelli d'acqua li avrebbe visti solo chi li scrive, e la
        // diagnostica avrebbe continuato a suonare per cose già guardate.
        //
        // Trovato provandolo sul server invece di fidarsi: segnate tre
        // anomalie, il container le rivedeva tutte e tre.
        //
        // Gruppo e permessi si copiano dal registro delle anomalie che sta
        // accanto — che quel giro lo fa già bene — invece di scriverli qui a
        // mano: così la regola vale anche dove quei valori sono altri, e non
        // c'è un secondo posto da ricordarsi di aggiornare.
        if ($scritto) {
            $accanto = self::percorso();
            if (is_file($accanto)) {
                $stato = @stat($accanto);
                if ($stato !== false) {
                    @chgrp($file, $stato['gid']);
                    @chmod($file, $stato['mode'] & 0o777);
                }
            }
        }

        return $scritto;
    }

    /**
     * Un elenco di occorrenze riassunto per codice, con la data dell'ultima:
     * «aide_differenze ×2 (ultima 13/9 06:06)».
     *
     * La data serve quando lo stesso codice compare fra le nuove **e** fra le
     * già viste. Il 13 settembre 2026 la diagnostica scriveva
     * «aide_differenze ×1 … Già segnate come viste: aide_differenze ×1», e una
     * sessione di lavoro l'ha letta come «quella di stanotte è già vista». Era
     * il contrario: vista era quella del giorno prima, e la diagnostica era
     * rossa proprio per quella di stanotte.
     *
     * Le occorrenze saltate dal limitatore contano: sono successe anche loro.
     *
     * @param list<array<string, mixed>> $voci
     */
    public static function riassumiPerCodice(array $voci): string
    {
        $quante = [];
        $ultima = [];
        foreach ($voci as $voce) {
            $codice = (string)($voce['codice'] ?? '?');
            $quante[$codice] = ($quante[$codice] ?? 0) + 1 + (int)($voce['saltate'] ?? 0);
            $quando = strtotime((string)($voce['quando'] ?? '')) ?: 0;
            $ultima[$codice] = max($ultima[$codice] ?? 0, $quando);
        }
        arsort($quante);

        $pezzi = [];
        foreach ($quante as $codice => $n) {
            $pezzi[] = $ultima[$codice] > 0
                ? \sprintf('%s ×%d (ultima %s)', $codice, $n, date('j/n H:i', $ultima[$codice]))
                : \sprintf('%s ×%d', $codice, $n);
        }

        return implode(', ', $pezzi);
    }
}
