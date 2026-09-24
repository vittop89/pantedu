<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\HealthController;
use App\Core\Config;
use App\Core\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ServizioFinto;

/**
 * `/health/tex` non sonda il servizio a ogni richiesta (20/9/2026).
 *
 * È pubblico e fuori dal WAF — deve esserlo, altrimenti la sfida PoW
 * risponderebbe 200 con la sua pagina e il rilascio non leggerebbe mai
 * `"tex":`. Ma così una richiesta qualunque faceva partire una chiamata
 * sincrona al servizio TeX, con tre secondi di tetto: con i pacchetti scartati,
 * sessanta richieste da un indirizzo tengono occupati i dieci processi di
 * php-fpm per una ventina di secondi, e il sito non risponde più a nessuno.
 *
 * Adesso l'esito della sonda vale qualche decina di secondi, scritto dove il
 * container può scrivere (`PANTEDU_DATA_PATH`), e mentre un processo sonda gli
 * altri si accontentano della risposta di prima invece di sondare anche loro.
 *
 * Il rilascio, che la risposta la vuole fresca, cancella il file prima di
 * chiedere (passo 8 di `deploy-container.sh`).
 */
final class HealthTexCacheTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $prima = [];
    private string $cartella = '';
    private ?ServizioFinto $finto = null;

    protected function setUp(): void
    {
        foreach (['tex_compile.endpoint', 'tex_compile.secret', 'app.paths.storage'] as $chiave) {
            $this->prima[$chiave] = Config::get($chiave);
        }
        $this->cartella = sys_get_temp_dir() . '/pantedu-health-tex-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);
        Config::set('app.paths.storage', $this->cartella);
    }

    protected function tearDown(): void
    {
        foreach ($this->prima as $chiave => $valore) {
            Config::set($chiave, $valore);
        }
        $this->finto?->ferma();
        foreach (glob($this->cartella . '/cache/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cartella . '/cache');
        @rmdir($this->cartella);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function chiedi(string $endpoint): array
    {
        Config::set('tex_compile.endpoint', $endpoint);
        Config::set('tex_compile.secret', 'segreto-di-prova');
        $risposta = (new HealthController())->tex(new Request(''));
        $dati = json_decode($risposta->body, true);
        self::assertIsArray($dati, "risposta JSON: {$risposta->body}");
        return [$risposta->status, $dati];
    }

    private function fileDiCache(): string
    {
        return $this->cartella . '/cache/health-tex.json';
    }

    #[Test]
    public function due_richieste_di_fila_sondano_il_servizio_una_volta_sola(): void
    {
        $this->finto = ServizioFinto::avvia([
            '/health' => [200, '{"status":"ok","service":"tex-compile-vps","version":"1.4.1"}'],
        ]);

        [$primo, $datiPrimo] = $this->chiedi($this->finto->url());
        [$secondo, $datiSecondo] = $this->chiedi($this->finto->url());

        self::assertSame(200, $primo);
        self::assertSame(200, $secondo);
        self::assertSame(['tex' => true], $datiPrimo);
        self::assertSame(['tex' => true], $datiSecondo, 'la risposta in cache è la stessa, non una diversa');
        self::assertCount(
            1,
            $this->finto->richieste(),
            'la seconda richiesta non deve arrivare al servizio TeX',
        );
    }

    #[Test]
    public function scaduta_la_cache_si_torna_a_sondare(): void
    {
        // Controprova della prova qui sopra: la cache è breve, non eterna. Un
        // servizio che muore dev'essere visto entro qualche decina di secondi.
        $this->finto = ServizioFinto::avvia([
            '/health' => [200, '{"status":"ok"}'],
        ]);
        $this->chiedi($this->finto->url());
        self::assertCount(1, $this->finto->richieste());

        $voce = json_decode((string)file_get_contents($this->fileDiCache()), true);
        self::assertIsArray($voce);
        $voce['quando'] = time() - 3600;
        file_put_contents($this->fileDiCache(), json_encode($voce));

        $this->chiedi($this->finto->url());
        self::assertCount(2, $this->finto->richieste(), 'scaduta, la sonda riparte');
    }

    #[Test]
    public function la_risposta_di_un_endpoint_non_vale_per_un_altro(): void
    {
        // In produzione i due container, il vecchio e il nuovo, scrivono nello
        // stesso `PANTEDU_DATA_PATH`. Se l'indirizzo del servizio cambia con un
        // rilascio, la risposta di prima non vale più.
        $this->finto = ServizioFinto::avvia(['/health' => [200, '{"status":"ok"}']]);
        [$stato] = $this->chiedi($this->finto->url());
        self::assertSame(200, $stato);

        $porta = ServizioFinto::portaChiusa();
        [$stato, $dati] = $this->chiedi("http://127.0.0.1:{$porta}");
        self::assertSame(503, $stato, 'un endpoint diverso non eredita il «sì» dell\'altro');
        self::assertSame(['tex' => false, 'errore' => 'connessione rifiutata'], $dati);
    }

    #[Test]
    public function mentre_un_altro_processo_sonda_si_serve_la_risposta_di_prima(): void
    {
        $this->finto = ServizioFinto::avvia(['/health' => [200, '{"status":"ok"}']]);
        $url = $this->finto->url();
        $this->chiedi($url);
        self::assertCount(1, $this->finto->richieste());

        // La risposta è scaduta, quindi andrebbe rifatta; ma qualcun altro sta
        // già sondando, e il lucchetto è suo. Un minuto: fuori dalla finestra
        // buona, dentro quella che si accetta come ripiego.
        $voce = json_decode((string)file_get_contents($this->fileDiCache()), true);
        self::assertIsArray($voce);
        $voce['quando'] = time() - 60;
        file_put_contents($this->fileDiCache(), json_encode($voce));

        $lucchetto = fopen($this->fileDiCache() . '.lock', 'c');
        self::assertIsResource($lucchetto);
        self::assertTrue(flock($lucchetto, LOCK_EX | LOCK_NB), 'il lucchetto dev\'essere libero');

        [$stato, $dati] = $this->chiedi($url);
        self::assertSame(200, $stato);
        self::assertSame(['tex' => true], $dati);
        self::assertCount(1, $this->finto->richieste(), 'una sonda per volta: le altre richieste non sondano');

        flock($lucchetto, LOCK_UN);
        fclose($lucchetto);
    }

    #[Test]
    public function senza_niente_in_cache_si_sonda_anche_col_lucchetto_preso(): void
    {
        // Controprova: il lucchetto non deve trasformarsi in una risposta
        // inventata. Se non c'è proprio niente da servire, si guarda.
        $this->finto = ServizioFinto::avvia(['/health' => [200, '{"status":"ok"}']]);

        mkdir($this->cartella . '/cache', 0775, true);
        $lucchetto = fopen($this->cartella . '/cache/health-tex.json.lock', 'c');
        self::assertIsResource($lucchetto);
        self::assertTrue(flock($lucchetto, LOCK_EX | LOCK_NB));

        [$stato, $dati] = $this->chiedi($this->finto->url());
        self::assertSame(200, $stato);
        self::assertSame(['tex' => true], $dati);
        self::assertCount(1, $this->finto->richieste());

        // E nemmeno una risposta di un'ora fa vale come ripiego: il lucchetto
        // limita il carico, non zittisce la domanda per sempre.
        $voce = json_decode((string)file_get_contents($this->fileDiCache()), true);
        self::assertIsArray($voce);
        $voce['quando'] = time() - 3600;
        file_put_contents($this->fileDiCache(), json_encode($voce));

        $this->chiedi($this->finto->url());
        self::assertCount(2, $this->finto->richieste());

        flock($lucchetto, LOCK_UN);
        fclose($lucchetto);
    }

    #[Test]
    public function la_configurazione_mancante_non_scrive_niente_in_cache(): void
    {
        Config::set('tex_compile.endpoint', '');
        Config::set('tex_compile.secret', '');
        $risposta = (new HealthController())->tex(new Request(''));

        self::assertSame(200, $risposta->status);
        self::assertSame(['tex' => 'non_configurato'], json_decode($risposta->body, true));
        self::assertFileDoesNotExist($this->fileDiCache());
    }
}
