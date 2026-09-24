<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\RateLimitMiddleware;
use App\Services\RateLimitStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ServizioFinto;

/**
 * La pulizia giornaliera di `rate_limits` che registro e DPIA dichiarano la fa
 * davvero qualcuno (23/9/2026).
 *
 * ── Il difetto ────────────────────────────────────────────────────────────
 *
 * `rate_limits` conserva l'IP in chiaro di chi passa dal limitatore, compresi
 * gli studenti con la credenziale di classe. Il registro dei trattamenti (B.6)
 * e la DPIA dichiarano una «pulizia giornaliera». Lo script c'era,
 * `tools/rate_limit_cleanup.php`, e l'unico chiamante di
 * `RateLimitStore::purgeDb`; ma nessuna unità, nessun workflow e nessun crontab
 * del repository lo lanciava. E senza database diceva «Removed 0 rows» ed
 * usciva 0: anche lanciato, un guasto sarebbe sembrato una pulizia riuscita
 * (A-84 e DOC-30 della revisione architetturale del 23/9/2026).
 *
 * ── Che cosa difende ──────────────────────────────────────────────────────
 *
 * - la dichiarazione dei due documenti ha un timer giornaliero che lancia lo
 *   script, con l'avviso di guasto;
 * - lo script esce con errore quando non ha potuto pulire;
 * - la pulizia non toglie colpi ancora dentro la finestra di una rotta.
 *
 * Che le righe vecchie spariscano davvero, e le recenti no, lo prova
 * tests/Integration/PuliziaDiRateLimitsTest.php sul database.
 */
final class PuliziaDelLimitatoreTest extends TestCase
{
    private static function radice(): string
    {
        return \dirname(__DIR__, 2);
    }

    /** @return array<string, string> nome del file => testo, per le unità di tools/systemd */
    private static function unita(string $estensione): array
    {
        $trovate = [];
        foreach (glob(self::radice() . '/tools/systemd/*.' . $estensione) ?: [] as $f) {
            $trovate[basename($f)] = (string)file_get_contents($f);
        }
        return $trovate;
    }

    #[Test]
    public function registro_e_dpia_dichiarano_la_pulizia_giornaliera(): void
    {
        // Il controllo positivo: se la frase cambiasse, la prova qui sotto
        // continuerebbe a passare difendendo una promessa che non c'è più.
        foreach (['docs/privacy/registro-trattamenti.md', 'docs/privacy/dpia.md'] as $doc) {
            self::assertStringContainsString(
                '`rate_limits` (pulizia giornaliera)',
                (string)file_get_contents(self::radice() . '/' . $doc),
                "$doc non dichiara più la pulizia giornaliera di rate_limits: si riallinea questa prova",
            );
        }
    }

    #[Test]
    public function un_timer_giornaliero_lancia_lo_script_e_avvisa_se_fallisce(): void
    {
        $servizi = array_filter(
            self::unita('service'),
            static fn(string $t): bool => (bool)preg_match(
                '#^ExecStart=\S*php\s+\S*/tools/rate_limit_cleanup\.php(\s|$)#m',
                $t,
            ),
        );
        self::assertCount(1, $servizi, 'nessuna unità (o più d\'una) lancia tools/rate_limit_cleanup.php');
        $servizio = (string)array_key_first($servizi);
        self::assertMatchesRegularExpression(
            '/^OnFailure=pantedu-avviso@%n\.service$/m',
            $servizi[$servizio],
            'senza OnFailure= una pulizia che fallisce resta nel giornale, che nessuno legge',
        );

        $timer = array_filter(
            self::unita('timer'),
            static fn(string $t): bool => (bool)preg_match('/^Unit=' . preg_quote($servizio, '/') . '$/m', $t),
        );
        self::assertCount(1, $timer, "nessun timer lancia $servizio");
        $testo = (string)reset($timer);
        self::assertMatchesRegularExpression(
            '/^OnCalendar=(daily|\*-\*-\* \d{2}:\d{2}(:\d{2})?|\d{2}:\d{2}(:\d{2})?)$/m',
            $testo,
            'la pulizia dichiarata è giornaliera',
        );
        self::assertMatchesRegularExpression(
            '/^WantedBy=timers\.target$/m',
            $testo,
            'un timer che non si accende non parte',
        );
    }

    /**
     * Le finestre dichiarate dalle rotte (`rate:<secchio>,<quanti>,<secondi>`).
     *
     * @return array<string, int> dichiarazione => secondi
     */
    private static function finestre(string $testo): array
    {
        preg_match_all("/'rate:([a-z_]+),(\d+),(\d+)'/", $testo, $m, PREG_SET_ORDER);
        $finestre = [];
        foreach ($m as $d) {
            $finestre[$d[0]] = RateLimitMiddleware::finestraSecondi($d[3]);
        }
        return $finestre;
    }

    #[Test]
    public function il_lettore_delle_finestre_trova_una_finestra_troppo_lunga(): void
    {
        // Il verso che scatta: senza, un lettore che non trova niente farebbe
        // passare la prova qui sotto qualunque cosa dichiarino le rotte.
        $finestre = self::finestre("->middleware('rate:prova,3,7200');");

        self::assertSame(["'rate:prova,3,7200'" => 7200], $finestre);
        self::assertGreaterThan(RateLimitStore::CONSERVAZIONE_SECONDI, $finestre["'rate:prova,3,7200'"]);
    }

    #[Test]
    public function nessuna_finestra_dichiarata_supera_quello_che_la_pulizia_conserva(): void
    {
        $finestre = [];
        foreach (glob(self::radice() . '/routes/*.php') ?: [] as $f) {
            $finestre += self::finestre((string)file_get_contents($f));
        }
        // Oggi sono due, `dpo` e `takedown`, un'ora ciascuna.
        self::assertGreaterThanOrEqual(2, \count($finestre), 'le finestre dichiarate non si trovano più');

        $troppoLunghe = array_filter($finestre, static fn(int $s): bool => $s > RateLimitStore::CONSERVAZIONE_SECONDI);
        self::assertSame(
            [],
            $troppoLunghe,
            'queste finestre sono più lunghe di quello che la pulizia conserva: la pulizia toglierebbe '
            . 'colpi ancora dentro la finestra e il limite non opererebbe. Si alza '
            . 'RateLimitStore::CONSERVAZIONE_SECONDI (e si guarda il registro dei trattamenti).',
        );
    }

    #[Test]
    public function senza_database_lo_script_esce_con_errore(): void
    {
        $cartella = sys_get_temp_dir() . '/pantedu-pulizia-limitatore-' . bin2hex(random_bytes(6));
        mkdir($cartella, 0700, true);
        // Il database si fa puntare a una porta su cui nessuno ascolta, dopo il
        // bootstrap: `.env.local`, caricato mutabile, scavalcherebbe l'ambiente.
        file_put_contents($cartella . '/lancia.php', <<<'PHP'
<?php
$repo = (string)getenv('PROVA_REPO');
require_once $repo . '/app/bootstrap.php';
\App\Core\Config::set('database.enabled', true);
\App\Core\Config::set('database.socket', '');
\App\Core\Config::set('database.host', '127.0.0.1');
\App\Core\Config::set('database.port', (int)getenv('PROVA_PORTA'));
ini_set('display_errors', 'stderr');
$argv = ['rate_limit_cleanup.php'];
require $repo . '/tools/rate_limit_cleanup.php';
PHP);
        $proc = proc_open(
            [PHP_BINARY, $cartella . '/lancia.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tubi,
            $cartella,
            array_merge(getenv(), [
                'PROVA_REPO'  => self::radice(),
                'PROVA_PORTA' => (string)ServizioFinto::portaChiusa(),
            ]),
        );
        self::assertIsResource($proc);
        $uscita = (string)stream_get_contents($tubi[1]);
        $errori = (string)stream_get_contents($tubi[2]);
        $esito = proc_close($proc);
        @unlink($cartella . '/lancia.php');
        @rmdir($cartella);

        self::assertSame(1, $esito, "una pulizia che non ha potuto guardare non è riuscita: {$uscita}{$errori}");
        self::assertStringContainsString('non è riuscita', $errori);
        self::assertStringNotContainsString('Removed', $uscita);
    }
}
