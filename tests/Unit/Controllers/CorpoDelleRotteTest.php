<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\Admin\VerificaPreambleAdminController;
use App\Controllers\PrintInfoController;
use App\Controllers\VerificaSharedHelpersTrait;
use App\Core\Config;
use App\Core\CorpoNonValido;
use App\Core\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Le rotte che avevano una copia propria di `readJsonBody()` (23/9/2026,
 * revisione architetturale A-52): adesso leggono il corpo da `Request`, e un
 * corpo troppo grande è 413 in tutte.
 *
 * Misurato prima della correzione: `PrintInfoController` rispondeva 413 al
 * corpo troppo grande e 422 (`empty_payload`) a un JSON rotto; il trait delle
 * verifiche (e con lui l'import PDF) lanciava `payload_too_large`, che il suo
 * `statusFor()` mandava a 400. `TeacherPrintController` risponde 413 anche lui:
 * lo prova `tests/Integration/TeacherPrintControllerTest.php`, che ha già il
 * suo ambiente.
 *
 * E una lettura fatta a mano che faceva danno: `VerificaPreambleAdminController`
 * decodificava il corpo senza guardare l'esito, e un corpo troncato dava un
 * `content` vuoto, cioè «ripristina il predefinito»: l'override del
 * preambolo si cancellava. Adesso il corpo rotto è un 400 e il file resta.
 *
 * Controprove (23/9/2026): con la vecchia `readJsonBody()` in PrintInfo il
 * caso del JSON rotto dà 422; con la vecchia copia nel trait il caso del
 * corpo grande dà 400; con la vecchia lettura del preambolo il file sparisce
 * e la risposta è 200 `reset`.
 */
final class CorpoDelleRotteTest extends TestCase
{
    private string $cartella = '';

    /** @var array<string, mixed> */
    private array $serverPrima = [];

    private mixed $datiPrima = null;

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-corpo-rotte-' . bin2hex(random_bytes(6));
        mkdir($this->cartella . '/storage/data', 0700, true);
        $this->serverPrima = $_SERVER;
        $this->datiPrima = Config::get('app.paths.data_base');
        Config::set('app.paths.data_base', $this->cartella);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        unset($_SERVER['CONTENT_LENGTH']);
        $_POST = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverPrima;
        $_SESSION = [];
        $_POST = [];
        Config::set('app.paths.data_base', $this->datiPrima);
        foreach (['/storage/data/verifica_preamble.tex'] as $f) {
            @unlink($this->cartella . $f);
        }
        @rmdir($this->cartella . '/storage/data');
        @rmdir($this->cartella . '/storage');
        @rmdir($this->cartella);
    }

    private function comeDocente(): void
    {
        $_SESSION = [
            'autenticato' => true,
            'username'    => 'zz_docente_prova',
            'user_id'     => 0,
            'user_role'   => 'teacher',
            'claims_at'   => time(),
        ];
    }

    private function comeSuperAdmin(): void
    {
        $_SESSION = [
            'autenticato'    => true,
            'username'       => 'zz_super_admin_prova',
            'user_id'        => 0,
            'user_role'      => 'admin',
            'is_super_admin' => true,
            'claims_at'      => time(),
        ];
    }

    /** @return array{0: int, 1: mixed} */
    private static function risposta(\App\Core\Response $r): array
    {
        return [$r->status, json_decode((string)$r->body, true)];
    }

    #[Test]
    public function informazioni_di_stampa_corpo_grande_413_e_json_rotto_400(): void
    {
        $this->comeDocente();
        $controller = new PrintInfoController();

        [$stato, $json] = self::risposta($controller->save(new Request(str_repeat('x', 64 * 1024 + 1))));
        self::assertSame(413, $stato);
        self::assertSame('payload_too_large', $json['error'] ?? null);

        [$stato, $json] = self::risposta($controller->save(new Request('{"indirizzo":')));
        self::assertSame(400, $stato, 'un JSON rotto è 400 (prima: 422 empty_payload)');
        self::assertSame('invalid_json', $json['error'] ?? null);
    }

    #[Test]
    public function verifiche_e_import_pdf_corpo_grande_413(): void
    {
        $rotta = new class {
            use VerificaSharedHelpersTrait;

            /** @return array<mixed>|int i dati, o lo stato della risposta */
            public function leggi(Request $req): array|int
            {
                try {
                    return $this->corpoJson($req);
                } catch (Throwable $e) {
                    return $this->statusFor($e);
                }
            }
        };

        self::assertSame(413, $rotta->leggi(new Request('{"t":"' . str_repeat('x', 2 * 1024 * 1024) . '"}')));
        self::assertSame(400, $rotta->leggi(new Request('{"t":')));
        self::assertSame(400, $rotta->leggi(new Request('')));
        self::assertSame(['t' => 1], $rotta->leggi(new Request('{"t":1}')));
    }

    #[Test]
    public function un_corpo_troncato_non_cancella_il_preambolo(): void
    {
        $this->comeSuperAdmin();
        $file = $this->cartella . '/storage/data/verifica_preamble.tex';
        file_put_contents($file, "\\documentclass{article}\n% preambolo dell'istanza\n");

        try {
            (new VerificaPreambleAdminController())->save(new Request('{"content":"\\\\documentcl'));
            self::fail('un corpo troncato doveva essere rifiutato');
        } catch (CorpoNonValido $e) {
            self::assertSame(400, $e->stato());
        }
        self::assertFileExists($file, 'il preambolo dell\'istanza resta');

        // L'altro verso: un corpo valido con `content` vuoto ripristina ancora.
        $risposta = (new VerificaPreambleAdminController())->save(new Request('{"content":""}'));
        self::assertSame(200, $risposta->status);
        self::assertFileDoesNotExist($file);
    }
}
