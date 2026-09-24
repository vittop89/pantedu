<?php

declare(strict_types=1);

namespace Tests\Unit;

use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il `.env` versionato non spegne il limitatore, non accende il debug e non
 * indica il servizio TeX.
 *
 * ── Il difetto, dalla revisione architetturale del 23/9/2026 (A-4) ────────
 *
 * Il `.env` versionato conteneva `RATE_LIMIT_DISABLED=1`, e il commento
 * accanto diceva «Production: leave unset». Ma quel file il rilascio lo
 * riporta alla versione del repository e lo monta nel container di
 * produzione (tools/webhook/deploy-container.sh). Il limitatore per utente e
 * per rotta restava acceso solo se `.env.local` del server ridefiniva la
 * chiave, e nessun controllo lo verificava (misurato il 23/9/2026 in
 * produzione, senza stampare valori: .env.local ridefinisce la chiave e il
 * limitatore è acceso). In sviluppo, invece, lo spegneva proprio il file
 * versionato.
 *
 * Ora `.env` dice 0. Dove vale 1: wiki/environment-variables.md.
 *
 * ── Che cosa guarda questa prova ──────────────────────────────────────────
 *
 * Non il testo della riga, che si può scrivere in molti modi (`export`, le
 * virgolette, una seconda riga più in basso che vince sulla prima), ma il
 * valore che ne ricaverebbe l'applicazione: il file passa da Dotenv, come in
 * `app/bootstrap.php`, e le due voci si leggono dai file di configurazione
 * veri. Senza `.env.local` e senza `phpunit.xml`: è la difesa del file da
 * solo, cioè di un server che non ridefinisce la chiave.
 *
 * Si guardano `.env`, che il rilascio monta, e `.env.example`, da cui parte
 * un'installazione nuova (`docs/INSTALL.md`: `cp .env.example .env`). Nel
 * lavoro «PHP: prove d'integrazione (MariaDB)» `.env` è riscritto da
 * `.env.example`: lì la prima prova guarda quello, e il file versionato lo
 * guarda il lavoro obbligatorio «PHP: analisi statica e test», che lo usa
 * così com'è nel commit.
 *
 * `.env`, se c'è, si guarda sempre, senza chiedersi in quale copia si è: un
 * `.env` presente è quello che l'installazione carica. Nel repository di
 * sviluppo è versionato e c'è sempre. Nella copia pubblica no: il sanitizer
 * lo toglie (`$deleteFiles` in tools/publish/sanitize-for-publication.php), e
 * ci torna quando chi installa fa `cp .env.example .env` (docs/INSTALL.md) o
 * quando lo fa il lavoro d'integrazione della CI. Da lì si guarda anche
 * quello, e le eccezioni di sviluppo vanno in `.env.local`, come qui. Dove
 * manca, la prova sul `.env` si salta e lo dice: non c'è niente che il
 * rilascio possa montare.
 *
 * La prima versione di questa prova guardava `.env` solo se c'era il
 * sanitizer: spostarlo o rinominarlo bastava perché smettesse di guardarlo in
 * silenzio, anche con il bypass dentro.
 *
 * Provata nei due versi: le ultime prove rimettono il bypass e il debug in
 * copie di `.env.example`, che c'è in tutte e due le copie, in più forme, e
 * la lettura li deve vedere. Senza di loro una lettura che rispondesse sempre
 * «spento no» passerebbe la prima.
 */
final class EnvTracciatoTest extends TestCase
{
    private static function radice(): string
    {
        return \dirname(__DIR__, 2);
    }

    private static function contenuto(string $file): string
    {
        $percorso = self::radice() . '/' . $file;
        self::assertFileExists($percorso, "$file manca: la prova non guarderebbe niente");

        return (string)file_get_contents($percorso);
    }

    /**
     * Le voci che vedrebbe un'applicazione che ha caricato solo questo
     * testo come `.env`.
     *
     * @return array{limitatore_spento: bool, debug: bool, tex: string}
     */
    private static function configurazioneDa(string $testo): array
    {
        $envPrima = $_ENV;
        try {
            // Solo le chiavi del file: niente di quello che phpunit.xml, i
            // `.env` caricati dal bootstrap delle prove o altre prove hanno
            // lasciato in $_ENV.
            $_ENV = Dotenv::parse($testo);
            $sicurezza = require self::radice() . '/app/Config/security.php';
            $app = require self::radice() . '/app/Config/app.php';
            $tex = require self::radice() . '/app/Config/tex_compile.php';
        } finally {
            $_ENV = $envPrima;
        }
        self::assertIsArray($sicurezza);
        self::assertIsArray($app);
        self::assertIsArray($tex);
        self::assertArrayHasKey('rate_limit_disabled', $sicurezza, 'la voce del bypass ha cambiato nome');
        self::assertArrayHasKey('debug', $app, 'la voce del debug ha cambiato nome');
        self::assertArrayHasKey('endpoint', $tex, 'la voce dell\'indirizzo del servizio TeX ha cambiato nome');

        return [
            'limitatore_spento' => (bool)$sicurezza['rate_limit_disabled'],
            'debug'             => (bool)$app['debug'],
            'tex'               => (string)$tex['endpoint'],
        ];
    }

    /**
     * Il testo del file da guardare. `.env.example` deve esserci; `.env`, se
     * manca, fa saltare la prova dicendolo, perché non c'è niente da montare.
     */
    private static function daGuardare(string $file): string
    {
        if ($file === '.env' && !is_file(self::radice() . '/.env')) {
            self::markTestSkipped(
                '.env non c\'è in questa copia (nella copia pubblica lo toglie il sanitizer): '
                . 'il rilascio non avrebbe niente da montare. Quando c\'è, si guarda sempre.'
            );
        }

        return self::contenuto($file);
    }

    /** @return array<string, array{string}> */
    public static function fileVersionati(): array
    {
        return [
            '.env.example, da cui parte un\'installazione' => ['.env.example'],
            '.env, che il rilascio monta in produzione'  => ['.env'],
        ];
    }

    #[Test]
    #[DataProvider('fileVersionati')]
    public function il_file_versionato_lascia_acceso_il_limitatore(string $file): void
    {
        self::assertFalse(
            self::configurazioneDa(self::daGuardare($file))['limitatore_spento'],
            "$file spegne il limitatore: il bypass va in phpunit.xml o in .env.local di sviluppo, "
            . 'non in un file che arriva in produzione (wiki/environment-variables.md)'
        );
    }

    #[Test]
    #[DataProvider('fileVersionati')]
    public function il_file_versionato_non_accende_il_debug(string $file): void
    {
        self::assertFalse(
            self::configurazioneDa(self::daGuardare($file))['debug'],
            "$file accende APP_DEBUG: in produzione mostrerebbe gli errori a chi visita"
        );
    }

    /**
     * 2026-09-23 — il file versionato non dice dove sta il servizio TeX
     * (revisione del 23/9, A-9). Diceva `http://127.0.0.1:8001`, l'indirizzo
     * dello sviluppo: dal container di produzione è il container stesso, e
     * dall'8 al 19/9/2026 il sito non ha compilato niente finché `.env.local`
     * non l'ha ridefinito. L'indirizzo dipende da dove gira l'applicazione, e
     * lo dà `.env.local`; vuoto, la compilazione dice «non configurata».
     */
    #[Test]
    #[DataProvider('fileVersionati')]
    public function il_file_versionato_non_indica_il_servizio_tex(string $file): void
    {
        self::assertSame(
            '',
            self::configurazioneDa(self::daGuardare($file))['tex'],
            "$file indica un servizio TeX: l'indirizzo dipende da dove gira l'applicazione, va in .env.local "
            . '(wiki/environment-variables.md)'
        );
    }

    #[Test]
    public function la_lettura_vede_un_servizio_tex_indicato(): void
    {
        // L'altro verso: la lettura vede l'indirizzo quando c'è. Senza, una
        // lettura che desse sempre «vuoto» passerebbe la prova qui sopra.
        $env = self::contenuto('.env.example');
        self::assertMatchesRegularExpression(
            '/^TEX_COMPILE_ENDPOINT=/m',
            $env,
            'nel .env.example non c\'è più una riga TEX_COMPILE_ENDPOINT da cambiare'
        );
        $conTex = (string)preg_replace('/^TEX_COMPILE_ENDPOINT=.*$/m', 'TEX_COMPILE_ENDPOINT=http://127.0.0.1:8001', $env);

        self::assertSame('http://127.0.0.1:8001', self::configurazioneDa($conTex)['tex']);
    }

    /**
     * Il verso che fa diventare rossa la prova: il bypass rimesso, in ogni
     * forma che Dotenv accetta. Si parte da `.env.example` vero, così la
     * prova resta legata a come sono scritti oggi i file.
     *
     * @return array<string, array{string}>
     */
    public static function bypassRimesso(): array
    {
        $env = (string)file_get_contents(self::radice() . '/.env.example');

        return [
            'la riga riportata a 1' => [
                (string)preg_replace('/^RATE_LIMIT_DISABLED=.*$/m', 'RATE_LIMIT_DISABLED=1', $env),
            ],
            'fra virgolette' => [
                (string)preg_replace('/^RATE_LIMIT_DISABLED=.*$/m', 'RATE_LIMIT_DISABLED="1"', $env),
            ],
            'con export davanti' => [
                (string)preg_replace('/^RATE_LIMIT_DISABLED=.*$/m', 'export RATE_LIMIT_DISABLED=1', $env),
            ],
            'una seconda riga in fondo, che vince sulla prima' => [
                $env . "\nRATE_LIMIT_DISABLED=1\n",
            ],
        ];
    }

    #[Test]
    #[DataProvider('bypassRimesso')]
    public function la_lettura_vede_il_bypass_rimesso(string $testo): void
    {
        self::assertTrue(
            self::configurazioneDa($testo)['limitatore_spento'],
            'il bypass è nel testo e la lettura non lo vede: la prova sul file vero non misurerebbe niente'
        );
    }

    #[Test]
    public function la_lettura_vede_il_debug_acceso(): void
    {
        $env = self::contenuto('.env.example');
        $conDebug = (string)preg_replace('/^APP_DEBUG=.*$/m', 'APP_DEBUG=true', $env);

        self::assertMatchesRegularExpression(
            '/^APP_DEBUG=/m',
            $env,
            'nel .env.example non c\'è più una riga APP_DEBUG da cambiare'
        );
        self::assertTrue(
            self::configurazioneDa($conDebug)['debug'],
            'APP_DEBUG=true è nel testo e la lettura non lo vede'
        );
    }

    #[Test]
    public function un_bypass_commentato_o_a_zero_non_conta(): void
    {
        // Il secondo verso della lettura: non scatta per una riga che
        // l'applicazione non vede. Senza, una lettura che desse sempre
        // «spento» passerebbe le prove qui sopra.
        $env = self::contenuto('.env.example');

        foreach (
            [
                'commentato' => (string)preg_replace('/^RATE_LIMIT_DISABLED=.*$/m', '# RATE_LIMIT_DISABLED=1', $env),
                'tolto'      => (string)preg_replace('/^RATE_LIMIT_DISABLED=.*\n/m', '', $env),
                'a zero'     => (string)preg_replace('/^RATE_LIMIT_DISABLED=.*$/m', 'RATE_LIMIT_DISABLED=0', $env),
            ] as $caso => $testo
        ) {
            self::assertFalse(self::configurazioneDa($testo)['limitatore_spento'], "bypass $caso: non deve contare");
        }
    }
}
