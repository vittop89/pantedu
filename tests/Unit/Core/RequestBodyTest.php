<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\CorpoNonValido;
use App\Core\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Request::json() / body() — revisione 2026-09, P4: sostituiscono le otto
 * copie di readJsonBody() e le letture dirette di php://input nei controller.
 */
final class RequestBodyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedServer;

    /** @var array<string, mixed> */
    private array $savedPost;

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        $this->savedPost = $_POST;
        unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE'], $_SERVER['CONTENT_LENGTH']);
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $_POST = $this->savedPost;
    }

    #[Test]
    public function json_decodes_object_and_tolerates_garbage(): void
    {
        self::assertSame(['a' => 1, 'b' => ['c']], (new Request('{"a":1,"b":["c"]}'))->json());
        self::assertSame([], (new Request(''))->json());
        self::assertSame([], (new Request('not json'))->json());
        self::assertSame([], (new Request('"scalar"'))->json(), 'uno scalare non e\' un corpo');
        self::assertSame([1, 2], (new Request('[1,2]'))->json());
    }

    #[Test]
    public function body_prefers_json_when_declared(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json; charset=utf-8';
        $_POST = ['form' => 'x'];
        $req = new Request('{"k":"v"}');
        self::assertTrue($req->isJson());
        self::assertSame(['k' => 'v'], $req->body());
    }

    #[Test]
    public function body_falls_back_to_form_fields_then_sniffs_json(): void
    {
        $_POST = ['form' => 'x'];
        self::assertSame(['form' => 'x'], (new Request('{"k":"v"}'))->body(), 'form vince se non e\' dichiarato JSON');

        $_POST = [];
        $req = new Request("  {\"k\":\"v\"}");
        self::assertFalse($req->isJson());
        self::assertSame(['k' => 'v'], $req->body(), 'corpo che sembra JSON');

        self::assertSame([], (new Request('plain=text'))->body());
    }

    #[Test]
    public function raw_body_is_read_once_and_kept(): void
    {
        $req = new Request('{"n":1}');
        self::assertSame('{"n":1}', $req->rawBody());
        self::assertSame(['n' => 1], $req->json());
        self::assertSame(['n' => 1], $req->json(), 'seconda lettura identica');
    }

    // ── 23/9/2026 (A-52): un lettore solo, un limite, 413 sempre ─────────

    /** Lo stato con cui la richiesta risponderebbe, o 0 se non lancia. */
    private static function stato(callable $leggi): int
    {
        try {
            $leggi();
            return 0;
        } catch (CorpoNonValido $e) {
            return $e->stato();
        }
    }

    #[Test]
    public function oltre_il_limite_e_413_da_ogni_lettura(): void
    {
        $grande = '{"testo":"' . str_repeat('x', 100) . '"}';
        self::assertSame(413, self::stato(fn() => (new Request($grande))->json(64)));
        self::assertSame(413, self::stato(fn() => (new Request($grande))->jsonObbligatorio(64)));
        self::assertSame(413, self::stato(fn() => (new Request($grande))->body(64)));
        self::assertSame(413, self::stato(fn() => Request::decodificaJson($grande, 64)));

        $_POST = ['testo' => str_repeat('x', 100)];
        self::assertSame(413, self::stato(fn() => (new Request(http_build_query($_POST)))->body(64)), 'anche il form');

        try {
            (new Request($grande))->jsonObbligatorio(64);
            self::fail('doveva lanciare');
        } catch (CorpoNonValido $e) {
            self::assertSame('payload_too_large', $e->getMessage());
        }
    }

    #[Test]
    public function content_length_oltre_il_limite_e_413_senza_leggere(): void
    {
        $_SERVER['CONTENT_LENGTH'] = '1000';
        self::assertSame(413, self::stato(fn() => (new Request('{}'))->jsonObbligatorio(64)));
        $_SERVER['CONTENT_LENGTH'] = '2';
        self::assertSame(0, self::stato(fn() => (new Request('{}'))->jsonObbligatorio(64)));
    }

    #[Test]
    public function entro_il_limite_restituisce_i_dati(): void
    {
        $corpo = '{"a":1,"b":["c"]}';
        self::assertSame(['a' => 1, 'b' => ['c']], (new Request($corpo))->jsonObbligatorio(\strlen($corpo)));
        self::assertSame(['a' => 1, 'b' => ['c']], (new Request($corpo))->json(\strlen($corpo)));
        self::assertSame([1, 2], (new Request('[1,2]'))->jsonObbligatorio());
    }

    #[Test]
    public function json_obbligatorio_rifiuta_il_json_rotto_con_400(): void
    {
        foreach (['not json', '{"a":', '"scalare"', '42', 'null'] as $rotto) {
            try {
                (new Request($rotto))->jsonObbligatorio();
                self::fail("«{$rotto}» doveva essere rifiutato");
            } catch (CorpoNonValido $e) {
                self::assertSame(400, $e->stato(), $rotto);
                self::assertSame('invalid_json', $e->getMessage(), $rotto);
            }
        }
        foreach (['', "  \n"] as $vuoto) {
            try {
                (new Request($vuoto))->jsonObbligatorio();
                self::fail('un corpo vuoto doveva essere rifiutato');
            } catch (CorpoNonValido $e) {
                self::assertSame(400, $e->stato());
                self::assertSame('empty_payload', $e->getMessage());
            }
        }
    }

    #[Test]
    public function lo_stato_per_un_catch_generico(): void
    {
        self::assertSame(413, CorpoNonValido::statoPer(CorpoNonValido::troppoGrande(), 400));
        self::assertSame(400, CorpoNonValido::statoPer(CorpoNonValido::jsonRotto(), 500));
        self::assertSame(500, CorpoNonValido::statoPer(new \RuntimeException('altro'), 500));
    }
}
