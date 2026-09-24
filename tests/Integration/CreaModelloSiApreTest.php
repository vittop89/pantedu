<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\Admin\RisdocAdminController;
use App\Controllers\Risdoc\TemplateViewController;
use App\Core\Request;
use App\Core\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * «Crea modello» apre un modello che si vede (23/9/2026).
 *
 * Revisione architetturale del 23/9/2026, A-23 (voce 127 del debito):
 * `RisdocAdminController::createTemplate` crea il modello con il solo
 * `body_pt` e senza `schema_path`. `TemplateViewController::show()` guardava
 * soltanto `schema_path`: senza, cadeva nel ripiego legacy, che cerca un file
 * HTML che per un modello nato nel database non esiste, e rispondeva 404. Il
 * pannello apre il modello appena creato in `/risdoc/view/{id}?admin_edit=1`:
 * l'amministratore vedeva «File HTML non trovato».
 *
 * La prova fa quello che fa il pannello: crea il modello con il controller
 * dell'amministrazione, da super-amministratore, e lo apre con la stessa
 * pagina, in modifica e da docente. Il modello e l'utente hanno un nome unico
 * e si cancellano a fine prova, anche se fallisce.
 */
final class CreaModelloSiApreTest extends TestCase
{
    private \PDO $db;
    private int $utente = 0;
    private string $nome = '';
    /** @var list<int> */
    private array $modelli = [];

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../app/bootstrap.php';
        try {
            $this->db = \App\Core\Database::connection();
            $this->db->query('SELECT 1 FROM risdoc_templates LIMIT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }

        $this->nome = 'zz-crea-modello-' . bin2hex(random_bytes(6));
        $this->db->prepare(
            "INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, is_super_admin)
             VALUES (?, 'teacher', 'Prova', 'Modello', ?, 'x', 'approved', 1, 1)"
        )->execute([$this->nome, $this->nome . '@example.test']);
        $this->utente = (int)$this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->modelli as $id) {
            $this->db->prepare('DELETE FROM risdoc_templates WHERE id = ?')->execute([$id]);
        }
        if ($this->utente > 0) {
            $this->db->prepare('DELETE FROM users WHERE id = ?')->execute([$this->utente]);
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        unset($_SERVER['HTTP_X_PARTIAL']);
    }

    private function entra(): void
    {
        // I claims (ruolo, attivo, super-amministratore) li rilegge Auth dal
        // database alla prima domanda, come dopo un login.
        $_SESSION = [
            'autenticato' => true,
            'username'    => $this->nome,
            'user_id'     => $this->utente,
            'user_role'   => 'teacher',
        ];
    }

    /** Come il pannello: POST /api/admin/risdoc/templates/create. */
    private function creaModello(): int
    {
        $this->entra();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'category'  => 'zz prova ' . substr($this->nome, -6),
            'num_arg'   => '9.9',
            'argomento' => 'Modello creato dalla prova ' . $this->nome,
        ];
        $r = (new RisdocAdminController())->createTemplate(new Request());
        self::assertSame(200, $r->status, $r->body);
        $j = json_decode($r->body, true);
        self::assertIsArray($j);
        $id = (int)($j['id'] ?? 0);
        self::assertGreaterThan(0, $id, 'il modello non è stato creato: ' . $r->body);
        $this->modelli[] = $id;
        return $id;
    }

    /** @param array<string, string> $query */
    private function apri(int $id, array $query): Response
    {
        $this->entra();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/risdoc/view/' . $id . ($query ? '?' . http_build_query($query) : '');
        $_GET = $query;
        $_POST = [];
        return (new TemplateViewController())->show(new Request(), ['id' => (string)$id]);
    }

    #[Test]
    public function il_modello_appena_creato_si_apre_in_modifica(): void
    {
        $id = $this->creaModello();

        $riga = $this->db->prepare('SELECT schema_path, body_pt FROM risdoc_templates WHERE id = ?');
        $riga->execute([$id]);
        $modello = $riga->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($modello);
        // La premessa del difetto: il modello nasce senza schema, con il body_pt.
        self::assertSame('', (string)($modello['schema_path'] ?? ''));
        self::assertNotSame('', (string)($modello['body_pt'] ?? ''));

        $r = $this->apri($id, ['admin_edit' => '1']);

        self::assertSame(200, $r->status, 'aperto dal pannello dopo «crea modello»: ' . substr($r->body, 0, 200));
        self::assertStringContainsString('<fm-pt-document source="risdoc-template" template-id="' . $id . '"', $r->body);
        self::assertStringContainsString('admin-edit="1"', $r->body);
    }

    #[Test]
    public function il_modello_appena_creato_si_apre_anche_senza_modifica(): void
    {
        $id = $this->creaModello();

        $r = $this->apri($id, []);

        self::assertSame(200, $r->status, substr($r->body, 0, 200));
        self::assertStringContainsString('<fm-pt-document source="risdoc-template" template-id="' . $id . '"', $r->body);
        self::assertStringNotContainsString('admin-edit="1"', $r->body);
    }
}
