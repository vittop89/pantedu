<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\Admin\AdminSectionsController;
use App\Core\Database;
use App\Core\Request;
use App\Support\PostoPrincipale;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-041 — le due azioni di «Sezioni e incarichi» sui materiali rimasti:
 * portarli sull'anno e la data dell'avviso. Nei due versi: quello che riesce
 * cambia i dati e lo dice; quello che si ferma non cambia niente e dice perché.
 *
 * Il servizio lo provano MaterialiSuSezioniNonAmmesseTest; qui il controller,
 * con la sua risposta. Tutto in transazione: anche la riga del registro.
 */
final class AdminSezioniMaterialiTest extends TestCase
{
    private PDO $pdo;
    private int $ist = 0;
    private int $docente = 0;
    /** @var array<string,int> */
    private array $voce = [];

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $_POST = [];

        $this->pdo->prepare("INSERT INTO institutes (code, name, city, active, sezioni_docenti) VALUES ('ZZADMRIM01', 'ISTITUTO DEL PULSANTE', 'Comune Esempio', 1, 'solo_incaricati')")->execute();
        $this->ist = (int)$this->pdo->lastInsertId();
        $ins = $this->pdo->prepare("INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, origine) VALUES (?, ?, ?, ?, ?, 1, 'istituto')");
        foreach ([['indirizzi', 'SCI', 'Sci', null], ['materie', 'MAT', 'Mat', null], ['classi', '2', 'Seconda', 'SCI'], ['classi', '2A', '2A', 'SCI'], ['classi', '4D', '4D', 'SCI']] as [$k, $c, $l, $i]) {
            $ins->execute([$k, $this->ist, $c, $l, $i]);
            $this->voce[$c] = (int)$this->pdo->lastInsertId();
        }
        $this->pdo->prepare("INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active) VALUES ('zz_admrim_prof', 'teacher', 'Zz', 'Pulsante', 'zz_admrim@example.test', 'x', 'approved', 1)")->execute();
        $this->docente = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$this->docente, $this->ist]);
        // ADR-043 — i materiali si portano su un anno che il docente può usare.
        $this->pdo->prepare("INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe) VALUES (?, ?, 'SCI', '2')")->execute([$this->docente, $this->ist]);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function il_pulsante_porta_sull_anno_e_lo_dice(): void
    {
        $id = $this->contenuto('2A');
        $_POST = ['institute_id' => (string)$this->ist, 'user_id' => (string)$this->docente, 'classe_id' => (string)$this->voce['2A']];

        $risposta = (new AdminSectionsController())->materiali(new Request());

        $dove = $this->luogo($risposta->headers['Location'] ?? '');
        self::assertSame('ok', $dove['tipo'], $dove['messaggio']);
        self::assertStringContainsString('«2»: 1 contenuti e 0 verifiche', $dove['messaggio']);
        self::assertSame($this->voce['2'], $this->classe($id));
    }

    /** ADR-043 — senza l'incarico sull'anno, portati lì resterebbero fuori dai suoi menù. */
    #[Test]
    public function senza_l_incarico_sull_anno_si_ferma_e_lo_dice(): void
    {
        $this->pdo->prepare("DELETE FROM teacher_sections WHERE user_id = ? AND institute_id = ? AND classe = '2'")->execute([$this->docente, $this->ist]);
        $id = $this->contenuto('2A');
        $_POST = ['institute_id' => (string)$this->ist, 'user_id' => (string)$this->docente, 'classe_id' => (string)$this->voce['2A']];

        $dove = $this->luogo((new AdminSectionsController())->materiali(new Request())->headers['Location'] ?? '');

        self::assertSame('error', $dove['tipo']);
        self::assertStringContainsString('non ha l\'incarico sull\'anno «2»', $dove['messaggio']);
        self::assertSame($this->voce['2A'], $this->classe($id), 'niente è stato spostato');
    }

    #[Test]
    public function senza_l_anno_nel_catalogo_si_ferma_e_dice_quale(): void
    {
        $id = $this->contenuto('4D');
        $_POST = ['institute_id' => (string)$this->ist, 'user_id' => (string)$this->docente, 'classe_id' => (string)$this->voce['4D']];

        $dove = $this->luogo((new AdminSectionsController())->materiali(new Request())->headers['Location'] ?? '');

        self::assertSame('error', $dove['tipo']);
        self::assertStringContainsString('manca l\'anno «4»', $dove['messaggio']);
        self::assertSame($this->voce['4D'], $this->classe($id), 'niente è stato spostato');
    }

    #[Test]
    public function la_data_si_salva_e_una_sbagliata_no(): void
    {
        $_POST = ['institute_id' => (string)$this->ist, 'scadenza' => '2026-10-01'];
        self::assertSame('ok', $this->luogo((new AdminSectionsController())->scadenza(new Request())->headers['Location'] ?? '')['tipo']);
        self::assertSame('2026-10-01', $this->scadenza());

        $_POST = ['institute_id' => (string)$this->ist, 'scadenza' => '31/10/2026'];
        $dove = $this->luogo((new AdminSectionsController())->scadenza(new Request())->headers['Location'] ?? '');
        self::assertSame('error', $dove['tipo']);
        self::assertSame('Data non valida.', $dove['messaggio']);
        self::assertSame('2026-10-01', $this->scadenza(), 'la data di prima resta');

        $_POST = ['institute_id' => (string)$this->ist, 'scadenza' => ''];
        (new AdminSectionsController())->scadenza(new Request());
        self::assertNull($this->scadenza());
    }

    private function contenuto(string $classe): int
    {
        $this->pdo->prepare('INSERT INTO teacher_content_data (teacher_id, content_subtype, title) VALUES (?, "esercizio", "Rimasto")')->execute([$this->docente]);
        $id = (int)$this->pdo->lastInsertId();
        PostoPrincipale::contenuto($this->pdo, $id, $this->voce['SCI'], $this->voce[$classe], $this->voce['MAT']);
        return $id;
    }

    private function classe(int $id): int
    {
        $st = $this->pdo->prepare('SELECT classe_id FROM teacher_content WHERE id = ?');
        $st->execute([$id]);
        return (int)$st->fetchColumn();
    }

    private function scadenza(): ?string
    {
        $st = $this->pdo->prepare('SELECT sezioni_scadenza FROM institutes WHERE id = ?');
        $st->execute([$this->ist]);
        $d = $st->fetchColumn();
        return $d !== null && $d !== false ? substr((string)$d, 0, 10) : null;
    }

    /** @return array{tipo:string,messaggio:string} */
    private function luogo(string $location): array
    {
        self::assertStringStartsWith('/admin/sections?institute_id=' . $this->ist . '&', $location);
        parse_str((string)parse_url($location, PHP_URL_QUERY), $q);
        foreach (['ok', 'error'] as $tipo) {
            if (isset($q[$tipo])) {
                return ['tipo' => $tipo, 'messaggio' => (string)$q[$tipo]];
            }
        }
        return ['tipo' => '', 'messaggio' => ''];
    }
}
