<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Middleware\SecurityHeadersMiddleware;
use App\Support\PaginaConGettone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le pagine con un gettone nell'indirizzo non lo mandano come Referer
 * (24/9/2026).
 *
 * ── Il difetto ────────────────────────────────────────────────────────────
 *
 * La pagina del collegamento della cancellazione (`/me/confirm-deletion?
 * token=…`) mandava il suo indirizzo intero, gettone compreso, come Referer al
 * POST del pulsante e al caricamento di fogli di stile e script: la politica
 * era `strict-origin-when-cross-origin`, che fra pagine dello stesso sito
 * manda tutto, e SecurityHeadersMiddleware la scriveva sopra a qualunque
 * intestazione la risposta avesse chiesto. `rel="noreferrer"` sul modulo non
 * bastava (Chromium lo ignora sui moduli, misurato).
 *
 * ── Le due direzioni ──────────────────────────────────────────────────────
 *
 *   - una pagina fatta con PaginaConGettone esce dal middleware con
 *     `Referrer-Policy: no-referrer`, e ha il meta `no-referrer` prima di ogni
 *     foglio di stile e script (il meta serve in produzione, dove nginx
 *     aggiunge la sua intestazione dopo quella dell'applicazione: vedi
 *     PaginaConGettone);
 *   - le altre pagine, e il JSON, escono con la politica di sempre e senza il
 *     meta;
 *   - una risposta non può allargare la politica: `unsafe-url` diventa quella
 *     di sempre, e resta un'intestazione sola anche se il nome era in
 *     minuscolo.
 *
 * Le pagine vere (cancellazione, cambio email, ripristino della password) le
 * prova tests/Integration/Security/ReferrerPolicyDellePagineConGettoneTest.php,
 * che ha bisogno del database.
 */
final class SecurityHeadersReferrerPolicyTest extends TestCase
{
    private const DI_SEMPRE = 'strict-origin-when-cross-origin';

    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/me/confirm-deletion?token=' . str_repeat('ab', 32);
        $_GET = [];
        $_POST = [];
    }

    #[Test]
    public function una_pagina_con_il_gettone_non_manda_il_referer_ne_con_l_intestazione_ne_con_il_meta(): void
    {
        $risposta = $this->attraverso(static fn(): Response => PaginaConGettone::html([
            'title' => 'Prova — Pantedu',
            'body'  => '<p>corpo</p>',
            'modal' => true,
        ]));

        self::assertSame(['no-referrer'], $this->politiche($risposta), 'il middleware la lascia com\'è');

        $meta = strpos($risposta->body, '<meta name="referrer" content="no-referrer">');
        self::assertNotFalse($meta, 'il meta c\'è');
        $foglio = strpos($risposta->body, '<link rel="stylesheet"');
        $script = strpos($risposta->body, '<script');
        self::assertNotFalse($foglio, 'precondizione: la pagina carica un foglio di stile');
        self::assertNotFalse($script, 'precondizione: e uno script');
        self::assertLessThan($foglio, $meta, 'il meta viene prima dei fogli di stile');
        self::assertLessThan($script, $meta, 'e prima degli script');
    }

    #[Test]
    public function le_altre_pagine_e_il_json_hanno_la_politica_di_sempre_e_nessun_meta(): void
    {
        $pagina = $this->attraverso(static fn(): Response => Response::html(
            View::default()->render('layout/shell', ['title' => 'Prova — Pantedu', 'body' => '<p>corpo</p>'])
        ));
        self::assertSame([self::DI_SEMPRE], $this->politiche($pagina));
        self::assertStringNotContainsString('name="referrer"', $pagina->body);

        $json = $this->attraverso(static fn(): Response => Response::json(['ok' => true]));
        self::assertSame([self::DI_SEMPRE], $this->politiche($json));
    }

    #[Test]
    public function una_risposta_non_puo_allargare_la_politica(): void
    {
        $larga = $this->attraverso(static fn(): Response => new Response('', 200, ['referrer-policy' => 'unsafe-url']));
        self::assertSame([self::DI_SEMPRE], $this->politiche($larga), 'unsafe-url non passa, e resta una intestazione');
        self::assertArrayHasKey('Referrer-Policy', $larga->headers);

        $stretta = $this->attraverso(static fn(): Response => new Response('', 200, ['referrer-policy' => ' No-Referrer ']));
        self::assertSame(['no-referrer'], $this->politiche($stretta), 'no-referrer scritta in un altro modo resta');
    }

    // ── Appoggi ─────────────────────────────────────────────────────────────

    /** @param callable(): Response $pagina */
    private function attraverso(callable $pagina): Response
    {
        return (new SecurityHeadersMiddleware('relaxed'))->handle(new Request(''), static fn(): Response => $pagina());
    }

    /** @return list<string> i valori di tutte le intestazioni Referrer-Policy, comunque scritte */
    private function politiche(Response $risposta): array
    {
        $valori = [];
        foreach ($risposta->headers as $nome => $valore) {
            if (strcasecmp((string)$nome, 'Referrer-Policy') === 0) {
                $valori[] = (string)$valore;
            }
        }
        return $valori;
    }
}
