<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\Risdoc\RisdocTemplateRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Quello che il pannello dei permessi dei modelli legge dal database.
 *
 * Segnalazione dell'utente (21/9/2026): «fra i docenti c'è anche admin, che
 * dovrebbe poter fare tutto a prescindere». Per dirlo, il pannello deve sapere
 * chi è amministratore: `staffUsers()` non lo diceva, e tre caselle vuote
 * accanto al suo nome raccontavano il contrario di com'è.
 *
 * E per dire in parole chi vede un modello con ambito «istituto» serve il nome
 * dell'Istituto, non il suo numero: `istitutiAttivi()`.
 *
 * Fixture in transazione, rollback in tearDown: la prova non lascia righe.
 */
final class PermessiDeiModelliTest extends TestCase
{
    private PDO $pdo;
    private RisdocTemplateRepository $repo;
    private bool $inTx = false;
    private string $marca = '';

    protected function setUp(): void
    {
        $basePath = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$basePath/$f")) {
                \Dotenv\Dotenv::createMutable($basePath, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($basePath . '/app/Config');
        $this->pdo = Database::connection();
        $this->pdo->beginTransaction();
        $this->inTx = true;
        $this->repo = new RisdocTemplateRepository();
        $this->marca = 'zzperm' . substr((string)microtime(true), -6);
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function creaUtente(string $suffisso, int $superAdmin): int
    {
        $u = $this->marca . $suffisso;
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, is_super_admin)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute([$u, 'teacher', 'Nome', 'Cognome', $u . '@esempio.invalid', 'x', 'approved', $superAdmin]);
        return (int)$this->pdo->lastInsertId();
    }

    #[Test]
    public function l_elenco_dei_docenti_dice_chi_e_amministratore(): void
    {
        $normale = $this->creaUtente('-norm', 0);
        $capo    = $this->creaUtente('-capo', 1);

        $righe = [];
        foreach ($this->repo->staffUsers() as $r) {
            $righe[(int)$r['id']] = $r;
        }

        self::assertArrayHasKey($normale, $righe, 'il docente attivo c\'è');
        self::assertArrayHasKey($capo, $righe);
        // Nei due versi: chi lo è risulta, chi non lo è non risulta.
        self::assertSame(1, (int)$righe[$capo]['is_super_admin']);
        self::assertSame(0, (int)$righe[$normale]['is_super_admin']);
    }

    #[Test]
    public function gli_istituti_attivi_arrivano_col_nome(): void
    {
        $nome = strtoupper($this->marca) . ' ISTITUTO DI PROVA';
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute([strtoupper($this->marca), $nome, 'Bari']);
        $acceso = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 0)')
            ->execute([strtoupper($this->marca) . 'X', $nome . ' (chiuso)', 'Bari']);
        $spento = (int)$this->pdo->lastInsertId();

        $per = [];
        foreach ($this->repo->istitutiAttivi() as $i) {
            $per[$i['id']] = $i['name'];
        }

        self::assertSame($nome, $per[$acceso] ?? null, 'l\'Istituto attivo c\'è, col suo nome');
        self::assertArrayNotHasKey($spento, $per, 'quello disattivato non si propone');
    }
}
