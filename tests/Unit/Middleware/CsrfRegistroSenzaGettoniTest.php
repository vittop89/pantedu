<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\CsrfMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Un POST che non passa il CSRF non scrive gettoni nel registro delle
 * anomalie (24/9/2026).
 *
 * ── Il difetto ────────────────────────────────────────────────────────────
 *
 * CsrfMiddleware scriveva il Referer intero nel campo `da` dell'anomalia. Dal
 * pulsante della pagina `/me/confirm-deletion?token=…`, premuto a sessione
 * scaduta, il gettone ancora valido della cancellazione finiva in
 * `anomalie.jsonl` (misurato dal revisore). Adesso il Referer passa da
 * PercorsoSenzaGettoni::perIlRegistro (senza query string, gettoni del
 * percorso mascherati), e la rotta da PercorsoSenzaGettoni::mascherati.
 *
 * ── Le due direzioni ──────────────────────────────────────────────────────
 *
 *   - l'anomalia si scrive ancora, con il codice, la rotta e la pagina di
 *     provenienza: un registro vuoto passerebbe la prova per la ragione
 *     sbagliata;
 *   - il gettone non c'è, né quello della query string né quello di un
 *     percorso con gettone (`/parent-consent/{token}`).
 *
 * Il registro in una cartella temporanea (app.paths.logs), cancellata alla
 * fine; niente database.
 */
final class CsrfRegistroSenzaGettoniTest extends TestCase
{
    private const SITO = 'https://istanza.example.test';

    private string $cartella = '';
    private string $gettone = '';

    /** @var array<string, mixed> */
    private array $configPrima = [];

    protected function setUp(): void
    {
        $items = new ReflectionProperty(Config::class, 'items');
        /** @var array<string, mixed> $prima */
        $prima = $items->getValue();
        $this->configPrima = $prima;

        $this->cartella = sys_get_temp_dir() . '/pantedu-csrf-registro-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);
        Config::set('app.paths.logs', $this->cartella);

        $this->gettone = bin2hex(random_bytes(32));
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        foreach (array_keys($_SERVER) as $k) {
            if (str_starts_with((string)$k, 'HTTP_')) {
                unset($_SERVER[$k]);
            }
        }
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Config::class, 'items'))->setValue(null, $this->configPrima);
        foreach (glob($this->cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->cartella);
        $_SESSION = [];
        unset($_SERVER['HTTP_REFERER']);
    }

    #[Test]
    public function il_pulsante_della_pagina_del_collegamento_non_scrive_il_gettone_del_referer(): void
    {
        Csrf::token();
        $risposta = $this->posta(
            '/me/confirm-deletion',
            ['token' => $this->gettone, '_csrf' => 'sbagliato'],
            self::SITO . '/me/confirm-deletion?token=' . $this->gettone . '#pulsante',
        );

        self::assertSame(403, $risposta->status, 'precondizione: il CSRF non passa');
        $riga = $this->anomalia();
        self::assertSame('csrf_gettone_sbagliato', $riga['codice'] ?? null, 'l\'anomalia si scrive ancora');
        self::assertSame('/me/confirm-deletion', $riga['dettagli']['rotta'] ?? null);
        self::assertSame(
            self::SITO . '/me/confirm-deletion',
            $riga['dettagli']['da'] ?? null,
            'con la pagina di provenienza, senza query string né frammento'
        );
        self::assertStringNotContainsString($this->gettone, $this->registro(), 'il gettone non c\'è');
    }

    #[Test]
    public function una_rotta_con_il_gettone_nel_percorso_lo_scrive_mascherato(): void
    {
        $risposta = $this->posta(
            '/parent-consent/' . $this->gettone,
            [],
            self::SITO . '/parent-consent/' . $this->gettone,
        );

        self::assertSame(403, $risposta->status, 'precondizione: senza gettone CSRF non passa');
        $riga = $this->anomalia();
        self::assertSame('csrf_gettone_assente', $riga['codice'] ?? null, 'l\'anomalia si scrive ancora');
        self::assertSame('/parent-consent/<omesso>', $riga['dettagli']['rotta'] ?? null, 'con il gettone mascherato');
        self::assertSame(self::SITO . '/parent-consent/<omesso>', $riga['dettagli']['da'] ?? null);
        self::assertStringNotContainsString($this->gettone, $this->registro(), 'né nella rotta né nella provenienza');
    }

    /** @param array<string, string> $campi */
    private function posta(string $percorso, array $campi, string $referer): Response
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $percorso;
        $_SERVER['HTTP_REFERER'] = $referer;
        $_POST = $campi;
        return (new CsrfMiddleware())->handle(new Request(''), static fn(): Response => Response::json(['ok' => true]));
    }

    /** @return array<string, mixed> l'unica riga del registro */
    private function anomalia(): array
    {
        $righe = array_values(array_filter(explode("\n", $this->registro())));
        self::assertCount(1, $righe, 'una riga nel registro delle anomalie');
        $riga = json_decode($righe[0], true);
        self::assertIsArray($riga);
        return $riga;
    }

    private function registro(): string
    {
        $file = $this->cartella . '/anomalie.jsonl';
        return is_file($file) ? (string)file_get_contents($file) : '';
    }
}
