<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TexCompile;

use App\Services\TexCompile\ControlloTex;
use App\Services\TexCompile\TexCompileClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ServizioFinto;

/**
 * Il controllo del TeX guarda dal posto giusto (19/9/2026).
 *
 * Sull'host la sonda diretta passa anche con il container rotto: il servizio
 * TeX sta sull'host, e l'host lo raggiunge sempre. È esattamente il guasto
 * dell'8 settembre 2026, che per undici giorni nessun controllo fatto
 * dall'host avrebbe visto. Per questo la diagnostica dell'host chiede anche
 * all'applicazione, attraverso nginx, se **lei** ci arriva: /health/tex.
 *
 * Qui nginx lo fa un servizio finto che risponde come /health/tex nei suoi
 * casi, compresa la pagina di sfida del WAF (un 200 che non è la risposta).
 */
final class ControlloTexTest extends TestCase
{
    private ?ServizioFinto $finto = null;

    protected function tearDown(): void
    {
        $this->finto?->ferma();
    }

    /** @return array{esito: string, prova: string} */
    private function attraversoNginx(int $codice, string $corpo): array
    {
        $this->finto?->ferma();
        $this->finto = ServizioFinto::avvia(['/health/tex' => [$codice, $corpo]]);
        return ControlloTex::attraversoNginx($this->finto->url(), 'pantedu.example');
    }

    #[Test]
    public function il_container_che_raggiunge_il_tex_regge_e_la_domanda_arriva_come_da_fuori(): void
    {
        $esito = $this->attraversoNginx(200, '{"tex":true}');

        self::assertSame('regge', $esito['esito'], $esito['prova']);
        $richieste = $this->finto?->richieste() ?? [];
        self::assertCount(1, $richieste);
        self::assertSame('/health/tex', $richieste[0]['percorso']);
        self::assertSame('pantedu.example', $richieste[0]['host'], 'il vhost giusto, non 127.0.0.1');
        self::assertSame('127.0.0.1', $richieste[0]['inoltrato'], 'come il passo 8 del rilascio');
    }

    #[Test]
    public function il_container_che_non_lo_raggiunge_e_un_guasto(): void
    {
        $esito = $this->attraversoNginx(503, '{"tex":false,"errore":"connessione rifiutata"}');

        self::assertSame('guasto', $esito['esito']);
        self::assertStringContainsString('connessione rifiutata', $esito['prova']);
    }

    #[Test]
    public function il_container_senza_configurazione_e_un_guasto_se_l_host_ce_l_ha(): void
    {
        $esito = $this->attraversoNginx(200, '{"tex":"non_configurato"}');

        self::assertSame('guasto', $esito['esito']);
        self::assertStringContainsString('configurazione', $esito['prova']);
    }

    #[Test]
    public function una_pagina_che_non_e_la_risposta_e_un_guasto(): void
    {
        // La sfida del WAF risponde 200 con una pagina HTML: un controllo che
        // guardasse solo il codice sarebbe verde.
        $esito = $this->attraversoNginx(200, '<!doctype html><title>Verifica…</title>');
        self::assertSame('guasto', $esito['esito']);

        $esito = $this->attraversoNginx(404, '{"detail":"Not Found"}');
        self::assertSame('guasto', $esito['esito']);
        self::assertStringContainsString('404', $esito['prova']);
    }

    #[Test]
    public function nginx_che_non_risponde_e_un_guasto(): void
    {
        $porta = ServizioFinto::portaChiusa();
        $esito = ControlloTex::attraversoNginx("http://127.0.0.1:{$porta}", 'pantedu.example');

        self::assertSame('guasto', $esito['esito']);
        self::assertStringContainsString('connessione rifiutata', $esito['prova']);
    }

    #[Test]
    public function la_sonda_diretta_nei_due_versi(): void
    {
        $porta = ServizioFinto::portaChiusa();
        $esito = ControlloTex::diretto(new TexCompileClient("http://127.0.0.1:{$porta}", 's'), 'da qui');
        self::assertSame('guasto', $esito['esito']);
        self::assertStringContainsString('connessione rifiutata', $esito['prova']);
        self::assertStringContainsString("127.0.0.1:{$porta}", $esito['prova'], 'nella diagnostica l’indirizzo serve, ed è interna');

        $this->finto = ServizioFinto::avvia(['/health' => [200, '{"status":"ok"}']]);
        $esito = ControlloTex::diretto(new TexCompileClient($this->finto->url(), 's'), 'da qui');
        self::assertSame('regge', $esito['esito'], $esito['prova']);
    }
}
