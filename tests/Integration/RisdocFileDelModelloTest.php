<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\Risdoc\TemplateController;
use App\Core\Request;
use App\Core\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GET /api/risdoc/templates/{id}/file da capo a fondo, con un docente in
 * sessione e un modello pubblico (23/9/2026, revisione Risdoc A1, 0.1).
 *
 * Prima con `kind=schema&path=composer.json` la rotta restituiva il corpo di
 * `composer.json`, e con `kind=json&path=../../../composer.json` lo stesso: un
 * docente qualsiasi leggeva i file dell'applicazione. La prova unitaria
 * (TemplateResolverConfinatoTest) guarda il resolver; questa guarda che la
 * rotta usi il filtro sui kind e che nessun corpo esca, e che le richieste del
 * client (json dei modelli, immagini) continuino a funzionare.
 *
 * Il docente e il modello li crea la prova, con un nome unico, e li cancella a
 * fine classe anche se fallisce.
 */
final class RisdocFileDelModelloTest extends TestCase
{
    private static \PDO $db;
    private static int $templateId = 0;
    private static int $teacherId = 0;
    private static string $username = '';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../app/bootstrap.php';
        try {
            self::$db = \App\Core\Database::connection();
            self::$db->query('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }

        self::$username = 'zz-risdoc-file-' . bin2hex(random_bytes(6));
        try {
            self::$db->prepare(
                "INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active)
                 VALUES (?, 'teacher', 'Prova', 'File', ?, 'x', 'approved', 1)"
            )->execute([self::$username, self::$username . '@example.test']);
            self::$teacherId = (int)self::$db->lastInsertId();

            self::$db->prepare(
                "INSERT INTO risdoc_templates (code, category, num_arg, argomento, source_dir, html_file, source_hash, visibility_scope)
                 VALUES (?, 'modelli', '0.0', 'Modello di prova dei file', ?, ?, ?, 'public')"
            )->execute([
                'prova/' . self::$username, 'storage/templates/risdoc/competenze_DM2007',
                'competenze_DM2007.json', str_repeat('0', 64),
            ]);
            self::$templateId = (int)self::$db->lastInsertId();
        } catch (\Throwable $e) {
            self::tearDownAfterClass();
            throw $e;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$templateId > 0) {
            self::$db->prepare('DELETE FROM risdoc_templates WHERE id = ?')->execute([self::$templateId]);
        }
        if (self::$teacherId > 0) {
            self::$db->prepare('DELETE FROM users WHERE id = ?')->execute([self::$teacherId]);
        }
        $_SESSION = [];
        $_GET = [];
    }

    /** @param array<string, string> $query */
    private function chiedi(array $query): Response
    {
        $_SESSION = [
            'autenticato' => true,
            'username'    => self::$username,
            'user_role'   => 'teacher',
            'user_id'     => self::$teacherId,
        ];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/api/risdoc/templates/' . self::$templateId . '/file?' . http_build_query($query);
        $_GET = $query;
        return (new TemplateController())->file(new Request(), ['id' => (string)self::$templateId]);
    }

    private function contenutoDelBersaglio(): string
    {
        // Un pezzo che sta solo in composer.json.
        return '"require"';
    }

    #[Test]
    public function kind_schema_dalla_query_si_rifiuta(): void
    {
        $r = $this->chiedi(['kind' => 'schema', 'path' => 'composer.json']);
        self::assertSame(400, $r->status);
        self::assertStringContainsString('kind_not_allowed', $r->body);
        self::assertStringNotContainsString($this->contenutoDelBersaglio(), $r->body);
    }

    #[Test]
    public function un_json_che_risale_non_esce(): void
    {
        $r = $this->chiedi(['kind' => 'json', 'path' => '../../../composer.json']);
        self::assertSame(404, $r->status);
        self::assertStringNotContainsString($this->contenutoDelBersaglio(), $r->body);
    }

    #[Test]
    public function un_html_che_risale_non_esce(): void
    {
        $r = $this->chiedi(['kind' => 'html', 'path' => '../../../../composer.json']);
        self::assertSame(404, $r->status);
        self::assertStringNotContainsString($this->contenutoDelBersaglio(), $r->body);
    }

    #[Test]
    public function un_immagine_con_percorso_assoluto_non_esce(): void
    {
        $r = $this->chiedi(['kind' => 'image', 'path' => '/etc/hostname']);
        self::assertSame(404, $r->status);
    }

    #[Test]
    public function il_json_di_un_modello_si_legge(): void
    {
        $r = $this->chiedi(['kind' => 'json', 'path' => 'competenze_DM2007/competenze_DM2007.json']);
        self::assertSame(200, $r->status, $r->body);
        $j = json_decode($r->body, true);
        self::assertIsArray($j);
        self::assertSame('file', $j['source'] ?? null);
        self::assertNotSame('', (string)($j['body'] ?? ''));
    }

    #[Test]
    public function l_immagine_istituzionale_si_legge(): void
    {
        $r = $this->chiedi(['kind' => 'image', 'path' => 'images/stemma_REP.png']);
        self::assertSame(200, $r->status);
        self::assertSame('image/png', $r->headers['Content-Type'] ?? null);
        self::assertStringStartsWith("\x89PNG", $r->body);
    }
}
