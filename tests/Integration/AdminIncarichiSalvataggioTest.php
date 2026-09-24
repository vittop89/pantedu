<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\Admin\AdminSectionsController;
use App\Core\Database;
use App\Core\Request;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * «Salva incarichi» in /admin/sections (15/9/2026).
 *
 * Il messaggio diceva «Assegnate: SCI 1A, 2A…» senza dire a chi, e il modulo
 * tornava con docente e indirizzo vuoti. Adesso il messaggio nomina il docente e
 * il ritorno porta docente e indirizzo, che la pagina ripresenta scelti.
 *
 * Nei due versi: con il salvataggio riuscito e con quello rifiutato (nessuna
 * classe); la pagina sceglie solo il docente e l'indirizzo del ritorno. Tutto in
 * transazione → rollback.
 */
final class AdminIncarichiSalvataggioTest extends TestCase
{
    private PDO $pdo;
    private int $ist = 0;
    private int $docente = 0;
    private int $altro = 0;
    /** @var array<string, mixed> */
    private array $getPrima = [];
    /** @var array<string, mixed> */
    private array $serverPrima = [];

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
        $this->getPrima = $_GET;
        $this->serverPrima = $_SERVER;
        $_POST = [];

        $this->pdo->prepare("INSERT INTO institutes (code, name, city, active, sezioni_docenti) VALUES ('ZZINCSALV1', 'ISTITUTO DEGLI INCARICHI', 'Comune Esempio', 1, 'solo_incaricati')")->execute();
        $this->ist = (int)$this->pdo->lastInsertId();
        $voce = $this->pdo->prepare("INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, origine) VALUES (?, ?, ?, ?, ?, 1, 'istituto')");
        foreach ([['indirizzi', 'SCI', 'Scientifico', null], ['indirizzi', 'ART', 'Artistico', null], ['classi', '2A', '2A', 'SCI'], ['classi', '3A', '3A', 'SCI']] as [$k, $c, $l, $i]) {
            $voce->execute([$k, $this->ist, $c, $l, $i]);
        }
        $utente = $this->pdo->prepare("INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active) VALUES (?, 'teacher', ?, ?, ?, 'x', 'approved', 1)");
        $utente->execute(['zz_incsalv_prof', 'Zz', 'Incaricato', 'zz_incsalv@example.test']);
        $this->docente = (int)$this->pdo->lastInsertId();
        $utente->execute(['zz_incsalv_altro', 'Zz', 'Altro', 'zz_incsalv_altro@example.test']);
        $this->altro = (int)$this->pdo->lastInsertId();
        $ti = $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)');
        $ti->execute([$this->docente, $this->ist]);
        $ti->execute([$this->altro, $this->ist]);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = $this->getPrima;
        $_SERVER = $this->serverPrima;
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @return array<string, string> la query del ritorno */
    private function ritorno(\App\Core\Response $risposta): array
    {
        $dove = (string)($risposta->headers['Location'] ?? '');
        self::assertStringStartsWith('/admin/sections?institute_id=' . $this->ist, $dove);
        parse_str((string)parse_url($dove, PHP_URL_QUERY), $q);
        return array_map('strval', $q);
    }

    #[Test]
    public function il_salvataggio_dice_a_chi_e_torna_sullo_stesso_docente_e_indirizzo(): void
    {
        $_POST = ['institute_id' => (string)$this->ist, 'user_id' => (string)$this->docente, 'indirizzo' => 'SCI', 'classe' => ['2A', '3A'], 'stato' => ''];

        $q = $this->ritorno((new AdminSectionsController())->assign(new Request()));

        self::assertArrayHasKey('ok', $q, $q['error'] ?? '');
        self::assertStringContainsString('Zz Incaricato', $q['ok'], 'il messaggio nomina il docente');
        self::assertStringContainsString('assegnate 2A, 3A', $q['ok']);
        self::assertSame((string)$this->docente, $q['docente'] ?? null);
        self::assertSame('SCI', $q['indirizzo'] ?? null);
    }

    #[Test]
    public function anche_un_salvataggio_rifiutato_torna_sullo_stesso_docente_e_indirizzo(): void
    {
        $_POST = ['institute_id' => (string)$this->ist, 'user_id' => (string)$this->docente, 'indirizzo' => 'SCI', 'stato' => ''];

        $q = $this->ritorno((new AdminSectionsController())->assign(new Request()));

        self::assertArrayHasKey('error', $q);
        self::assertSame((string)$this->docente, $q['docente'] ?? null);
        self::assertSame('SCI', $q['indirizzo'] ?? null);
    }

    #[Test]
    public function la_pagina_ripresenta_scelti_solo_il_docente_e_l_indirizzo_del_ritorno(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/sections';
        unset($_SERVER['HTTP_X_PARTIAL']);
        $_GET = ['institute_id' => (string)$this->ist, 'docente' => (string)$this->docente, 'indirizzo' => 'SCI'];

        $html = (new AdminSectionsController())->index(new Request())->body;
        $docenti = $this->tendina($html, 'fm-assign-doc');
        $indirizzi = $this->tendina($html, 'fm-assign-ind');

        self::assertMatchesRegularExpression('#<option value="' . $this->docente . '"\s+selected>#', $docenti, 'il docente del ritorno');
        self::assertDoesNotMatchRegularExpression('#<option value="' . $this->altro . '"\s+selected>#', $docenti, 'non un altro');
        self::assertMatchesRegularExpression('#<option value="SCI"\s+selected>#', $indirizzi, "l'indirizzo del ritorno");
        self::assertDoesNotMatchRegularExpression('#<option value="ART"\s+selected>#', $indirizzi);
    }

    #[Test]
    public function senza_ritorno_il_modulo_parte_vuoto(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/sections';
        unset($_SERVER['HTTP_X_PARTIAL']);
        $_GET = ['institute_id' => (string)$this->ist];

        $html = (new AdminSectionsController())->index(new Request())->body;

        self::assertDoesNotMatchRegularExpression('#<option value="' . $this->docente . '"\s+selected>#', $this->tendina($html, 'fm-assign-doc'));
        self::assertDoesNotMatchRegularExpression('#<option value="SCI"\s+selected>#', $this->tendina($html, 'fm-assign-ind'));
    }

    /**
     * Una sola tendina della pagina. Cercando nella pagina intera, l'opzione
     * scelta della tendina degli istituti (`value="<id>" selected`) passava per
     * quella del docente quando i due id coincidevano: in CI il 15/9/2026, dopo
     * che altre prove avevano fatto avanzare i contatori.
     */
    private function tendina(string $html, string $id): string
    {
        self::assertMatchesRegularExpression('#<select[^>]*\bid="' . $id . '"[^>]*>(.*?)</select>#s', $html, "manca la tendina $id");
        preg_match('#<select[^>]*\bid="' . $id . '"[^>]*>(.*?)</select>#s', $html, $m);
        return $m[1];
    }
}
