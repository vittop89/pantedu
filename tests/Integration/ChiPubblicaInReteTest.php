<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\Admin\AdminSidebarConfigController;
use App\Core\Database;
use App\Core\Request;
use App\Services\Study\PublicContentPolicy;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Chi pubblica in rete (migrazione 129, 15/9/2026).
 *
 * Fino a quel giorno i contenuti visibili senza login erano per regola quelli
 * del super-amministratore docente; in produzione dal 4/9 il super-amministratore
 * non è docente, e la home pubblica mostrava sezioni vuote. Adesso il docente si
 * sceglie; senza scelta non c'è niente in rete, e la barra dei visitatori mostra
 * solo l'accesso (scelta dell'utente). La migrazione trasforma la regola di
 * prima in una scelta, dove c'era un super-amministratore docente.
 *
 * Nei due versi: la scelta vince quando c'è ed è un docente attivo, e senza
 * scelta (mancante, tolta, docente disattivato) non c'è nessuno; le sezioni
 * pubbliche ci sono solo con un docente scelto; il database ne ammette uno solo;
 * chi non è docente non si accetta. Tutto in transazione → rollback.
 */
final class ChiPubblicaInReteTest extends TestCase
{
    private PDO $pdo;
    /** @var array<string, mixed> */
    private array $serverPrima = [];
    /** @var array<string, mixed> */
    private array $postPrima = [];

    protected function setUp(): void
    {
        if (!Database::isAvailable()) {
            self::markTestSkipped('database non disponibile');
        }
        $this->pdo = Database::connection();
        try {
            $this->pdo->query('SELECT pubblica_in_rete_unica FROM users LIMIT 0');
        } catch (\Throwable $e) {
            self::fail('manca la migrazione 129: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        // Si parte senza scelta, qualunque sia lo stato del database di prova.
        $this->pdo->exec('UPDATE users SET pubblica_in_rete = 0 WHERE pubblica_in_rete = 1');
        $this->serverPrima = $_SERVER;
        $this->postPrima = $_POST;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverPrima;
        $_POST = $this->postPrima;
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function utente(string $ruolo, bool $attivo = true): int
    {
        $nome = 'zzpir' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, ?, "Zz", "Rete", ?, "x", "approved", ?, NOW())'
        )->execute([$nome, $ruolo, "$nome@example.invalid", $attivo ? 1 : 0]);
        return (int)$this->pdo->lastInsertId();
    }

    private function scelti(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM users WHERE pubblica_in_rete = 1')->fetchColumn();
    }

    /** Una sezione del modello globale marcata «Pubblica in rete», dentro la transazione. */
    private function sezionePubblica(): string
    {
        $chiave = (string)$this->pdo->query('SELECT section_key FROM sidebar_sections WHERE institute_id = 0 ORDER BY position LIMIT 1')->fetchColumn();
        if ($chiave === '') {
            self::markTestSkipped('nessuna sezione nel modello globale del database di prova');
        }
        $this->pdo->prepare('UPDATE sidebar_sections SET publish_public = 1, active = 1 WHERE institute_id = 0 AND section_key = ?')->execute([$chiave]);
        return $chiave;
    }

    /** L'ultima istruzione della migrazione 129: la regola di prima resa una scelta. */
    private function sceltaDellaMigrazione(): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/129_chi_pubblica_in_rete.sql');
        $inizio = strrpos($sql, 'UPDATE users');
        $this->assertNotFalse($inizio, 'la migrazione ha la scelta iniziale');
        $this->pdo->exec(rtrim(substr($sql, (int)$inizio), "; \n"));
    }

    #[Test]
    public function senza_scelta_non_c_e_nessuno_in_rete(): void
    {
        $this->assertNull(PublicContentPolicy::sceltoPerLaRete());
        $this->assertSame(0, PublicContentPolicy::proprietarioPubblico());
        $this->assertTrue(PublicContentPolicy::isDeny(PublicContentPolicy::scopedFilters(['subj' => 'MAT'], 'mappa')));
    }

    #[Test]
    public function le_sezioni_pubbliche_ci_sono_solo_con_un_docente_scelto(): void
    {
        $chiave = $this->sezionePubblica();
        $sezione = static function (string $k): \App\Core\Response {
            return (new \App\Controllers\PublicSidebarController())->section(new Request(), ['key' => $k]);
        };

        $this->assertSame([], PublicContentPolicy::sezioniPubbliche(), 'senza docente, al visitatore resta solo l\'accesso');
        $this->assertSame(404, $sezione($chiave)->status);

        PublicContentPolicy::scegliPerLaRete($this->utente('teacher'));

        $chiavi = array_column(PublicContentPolicy::sezioniPubbliche(), 'section_key');
        $this->assertContains($chiave, $chiavi);
        $risposta = $sezione($chiave);
        $this->assertSame(200, $risposta->status);
        $this->assertStringContainsString('data-sidepage="' . $chiave . '"', $risposta->body);
    }

    #[Test]
    public function la_migrazione_sceglie_il_super_amministratore_docente_dove_c_era(): void
    {
        // Solo il super-amministratore docente di questa prova, qualunque sia il database.
        $this->pdo->exec('UPDATE users SET is_super_admin = 0 WHERE is_super_admin = 1');
        $superDocente = $this->utente('teacher');
        $this->pdo->prepare('UPDATE users SET is_super_admin = 1 WHERE id = ?')->execute([$superDocente]);

        $this->sceltaDellaMigrazione();

        $this->assertSame($superDocente, PublicContentPolicy::sceltoPerLaRete());
    }

    #[Test]
    public function la_migrazione_non_sceglie_nessuno_se_il_super_amministratore_non_e_docente_o_se_c_e_gia_una_scelta(): void
    {
        $this->pdo->exec('UPDATE users SET is_super_admin = 0 WHERE is_super_admin = 1');
        $amministratore = $this->utente('administrator');
        $this->pdo->prepare('UPDATE users SET is_super_admin = 1 WHERE id = ?')->execute([$amministratore]);

        $this->sceltaDellaMigrazione();
        $this->assertNull(PublicContentPolicy::sceltoPerLaRete(), 'come in produzione: nessuno');

        $scelto = $this->utente('teacher');
        PublicContentPolicy::scegliPerLaRete($scelto);
        $superDocente = $this->utente('teacher');
        $this->pdo->prepare('UPDATE users SET is_super_admin = 1 WHERE id = ?')->execute([$superDocente]);

        $this->sceltaDellaMigrazione();
        $this->assertSame($scelto, PublicContentPolicy::sceltoPerLaRete(), 'una scelta già fatta non si tocca');
    }

    #[Test]
    public function il_docente_scelto_vince_e_i_filtri_pubblici_sono_i_suoi(): void
    {
        $docente = $this->utente('teacher');

        PublicContentPolicy::scegliPerLaRete($docente);

        $this->assertSame($docente, PublicContentPolicy::sceltoPerLaRete());
        $this->assertSame($docente, PublicContentPolicy::proprietarioPubblico());
        $filtri = PublicContentPolicy::scopedFilters(['subj' => 'MAT'], 'mappa');
        if (!PublicContentPolicy::isDeny($filtri)) {
            $this->assertSame($docente, $filtri['teacher_id']);
        }
        $this->assertFalse(PublicContentPolicy::isPublic([
            'teacher_id' => $docente, 'visibility' => 'draft', 'content_type' => 'mappa',
        ]), 'le bozze restano private anche per il docente scelto');
    }

    #[Test]
    public function scegliere_un_altro_toglie_il_primo(): void
    {
        $primo = $this->utente('teacher');
        $secondo = $this->utente('teacher');

        PublicContentPolicy::scegliPerLaRete($primo);
        PublicContentPolicy::scegliPerLaRete($secondo);

        $this->assertSame($secondo, PublicContentPolicy::sceltoPerLaRete());
        $this->assertSame(1, $this->scelti());
    }

    #[Test]
    public function il_database_ne_ammette_uno_solo(): void
    {
        $primo = $this->utente('teacher');
        $secondo = $this->utente('teacher');
        $this->pdo->prepare('UPDATE users SET pubblica_in_rete = 1 WHERE id = ?')->execute([$primo]);

        $this->expectException(\PDOException::class);
        $this->pdo->prepare('UPDATE users SET pubblica_in_rete = 1 WHERE id = ?')->execute([$secondo]);
    }

    #[Test]
    public function chi_non_e_un_docente_attivo_non_si_accetta_e_la_scelta_di_prima_resta(): void
    {
        $docente = $this->utente('teacher');
        PublicContentPolicy::scegliPerLaRete($docente);

        foreach ([$this->utente('administrator'), $this->utente('student'), $this->utente('teacher', false), 999999999] as $nonValido) {
            try {
                PublicContentPolicy::scegliPerLaRete($nonValido);
                $this->fail("l'utente $nonValido non doveva essere accettato");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('docente_non_valido', $e->getMessage());
            }
        }
        $this->assertSame($docente, PublicContentPolicy::sceltoPerLaRete(), 'la scelta di prima resta');
        $this->assertSame(1, $this->scelti());
    }

    #[Test]
    public function togliere_la_scelta_o_disattivare_il_docente_toglie_tutto_dalla_rete(): void
    {
        $docente = $this->utente('teacher');
        PublicContentPolicy::scegliPerLaRete($docente);
        PublicContentPolicy::scegliPerLaRete(null);
        $this->assertNull(PublicContentPolicy::sceltoPerLaRete());
        $this->assertSame(0, PublicContentPolicy::proprietarioPubblico());

        PublicContentPolicy::scegliPerLaRete($docente);
        $this->pdo->prepare('UPDATE users SET active = 0 WHERE id = ?')->execute([$docente]);
        $this->assertNull(PublicContentPolicy::sceltoPerLaRete(), 'un docente disattivato non pubblica più');
        $this->assertSame(0, PublicContentPolicy::proprietarioPubblico());
    }

    private function invia(string $valore): \App\Core\Response
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/admin/sidebar-config/pubblica-in-rete';
        $_POST = ['docente' => $valore, '_audit_reason' => 'prova automatica della scelta'];
        return (new AdminSidebarConfigController())->pubblicaInRete(new Request());
    }

    #[Test]
    public function la_pagina_salva_la_scelta_e_rifiuta_quello_che_non_e_un_id(): void
    {
        $docente = $this->utente('teacher');

        $salvata = $this->invia((string)$docente);
        $this->assertSame(302, $salvata->status);
        $this->assertStringContainsString('kind=success', (string)($salvata->headers['Location'] ?? ''));
        $this->assertSame($docente, PublicContentPolicy::sceltoPerLaRete());

        $rifiutata = $this->invia('1 OR 1=1');
        $this->assertStringContainsString('kind=error', (string)($rifiutata->headers['Location'] ?? ''));
        $this->assertSame($docente, PublicContentPolicy::sceltoPerLaRete(), 'la scelta di prima resta');
    }
}
