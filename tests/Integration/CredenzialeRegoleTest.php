<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\TeacherCredentialRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le regole delle credenziali di classe sul database vero (ADR-044,
 * migrazione 134): username unico su tutta la piattaforma, scadenza non
 * passata, password entro il massimo.
 *
 * Misurato sul server di sviluppo il 19 settembre 2026, prima della
 * correzione: lo stesso username per lo stesso docente dava un 500 col testo
 * SQL; per un altro docente veniva accettato, e lo studente entrava nella
 * credenziale del primo; una scadenza del 2020 era accettata; una password di
 * 73 caratteri pure, troncata da bcrypt.
 *
 * Fixture isolata in transazione (rollback in tearDown): un istituto, due
 * docenti collegati.
 */
final class CredenzialeRegoleTest extends TestCase
{
    private PDO $pdo;
    private TeacherCredentialRepository $repo;
    private int $scuola = 0;
    private int $uno = 0;
    private int $due = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $basePath = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$basePath/$f")) {
                \Dotenv\Dotenv::createMutable($basePath, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($basePath . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT materie, aggiunta FROM teacher_access_credentials LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migrazione 134 non disponibili: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;
        \App\Support\CurriculumLookup::resetCache();

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZREGO01', 'SCUOLA DELLE REGOLE', 'Comune Esempio']);
        $this->scuola = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("UPDATE institutes SET sezioni_docenti = 'tutti' WHERE id = ?")->execute([$this->scuola]);
        $this->uno = $this->docente('zzregole_uno');
        $this->due = $this->docente('zzregole_due');
        $this->repo = new TeacherCredentialRepository();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        \App\Support\CurriculumLookup::resetCache();
    }

    private function docente(string $username): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", ?, ?, "x", "approved", 1, NOW())'
        )->execute([$username, $username, $username . '@example.invalid']);
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$id, $this->scuola]);
        return $id;
    }

    /** @param array<string,mixed> $extra */
    private function crea(int $docente, string $username, array $extra = []): int
    {
        return $this->repo->create($docente, $extra + [
            'username' => $username, 'password' => 'Pa55!regole', 'institute_id' => $this->scuola,
        ]);
    }

    private function codiceDi(callable $azione): string
    {
        try {
            $azione();
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
        return '(nessun rifiuto)';
    }

    #[Test]
    public function lo_stesso_username_non_si_ripete_nemmeno_cambiando_maiuscole(): void
    {
        $u = 'zz-regole-' . substr(uniqid(), -6);
        $this->crea($this->uno, $u);
        $this->assertSame('username_in_uso', $this->codiceDi(fn() => $this->crea($this->uno, $u, ['aggiunta' => 'B'])), 'stesso docente');
        $this->assertSame('username_in_uso', $this->codiceDi(fn() => $this->crea($this->uno, strtoupper($u), ['aggiunta' => 'C'])), 'stesso docente, in maiuscolo');
        $this->assertSame('username_in_uso', $this->codiceDi(fn() => $this->crea($this->due, $u)), 'un altro docente: prima era accettato');
    }

    #[Test]
    public function username_diversi_per_due_docenti_si_creano_entrambi(): void
    {
        // Verso opposto: il controllo non rifiuta tutto.
        $a = $this->crea($this->uno, 'zz-regole-a-' . substr(uniqid(), -6));
        $b = $this->crea($this->due, 'zz-regole-b-' . substr(uniqid(), -6));
        $this->assertGreaterThan(0, $a);
        $this->assertGreaterThan(0, $b);
    }

    #[Test]
    public function l_indice_unico_ferma_anche_chi_passasse_il_controllo(): void
    {
        // Due creazioni simultanee passano entrambe il SELECT: le ferma
        // l'indice della migrazione 134, senza distinguere maiuscole.
        $u = 'zz-regole-i-' . substr(uniqid(), -6);
        $this->crea($this->uno, $u);
        try {
            $this->pdo->prepare(
                'INSERT INTO teacher_access_credentials_data (teacher_id, label, access_username, password_hash, active)
                 VALUES (?, "x", ?, "x", 1)'
            )->execute([$this->due, strtoupper($u)]);
            $this->fail('l\'indice unico doveva rifiutare il doppione');
        } catch (\PDOException $e) {
            $this->assertSame('23000', (string)$e->getCode());
            $this->assertStringContainsString('uq_tac_access_username', $e->getMessage());
        }
    }

    #[Test]
    public function all_accesso_maiuscole_e_minuscole_si_equivalgono(): void
    {
        $u = 'zz-regole-m-' . substr(uniqid(), -6);
        $id = $this->crea($this->uno, $u);
        $row = $this->repo->verify(strtoupper($u), 'Pa55!regole');
        $this->assertNotNull($row);
        $this->assertSame($id, (int)$row['id']);
        $this->assertNull($this->repo->verify($u, 'pa55!regole'), 'la password invece distingue');
    }

    #[Test]
    public function una_scadenza_passata_e_rifiutata_oggi_no(): void
    {
        $this->assertSame('scadenza_passata', $this->codiceDi(fn() => $this->crea($this->uno, 'zz-regole-s-' . substr(uniqid(), -6), ['expires_at' => '2020-01-01'])));
        $this->assertSame('invalid_expiry', $this->codiceDi(fn() => $this->crea($this->uno, 'zz-regole-f-' . substr(uniqid(), -6), ['expires_at' => '31/08/2027'])));

        $id = $this->crea($this->uno, 'zz-regole-o-' . substr(uniqid(), -6), ['expires_at' => date('Y-m-d')]);
        $this->assertSame(date('Y-m-d'), $this->repo->find($this->uno, $id)['expires_at'], 'oggi vale: la credenziale apre fino a stasera');

        $this->assertSame('scadenza_passata', $this->codiceDi(fn() => $this->repo->setExpiry($this->uno, $id, '2020-01-01')));
        $this->assertTrue($this->repo->setExpiry($this->uno, $id, date('Y-m-d', strtotime('+10 days'))));
        $this->assertTrue($this->repo->setExpiry($this->uno, $id, null), 'senza scadenza resta possibile');
    }

    #[Test]
    public function la_password_ha_un_massimo_anche_nella_rotazione(): void
    {
        $lunga = str_repeat('a', 72) . 'Y';
        $this->assertSame('password_troppo_lunga', $this->codiceDi(fn() => $this->crea($this->uno, 'zz-regole-p-' . substr(uniqid(), -6), ['password' => $lunga])));
        $this->assertSame('password_non_valida', $this->codiceDi(fn() => $this->crea($this->uno, 'zz-regole-e-' . substr(uniqid(), -6), ['password' => '🙂🙂🙂🙂🙂🙂'])));

        $id = $this->crea($this->uno, 'zz-regole-r-' . substr(uniqid(), -6));
        $this->assertSame('password_troppo_lunga', $this->codiceDi(fn() => $this->repo->setPassword($this->uno, $id, $lunga)));
        $this->assertSame('weak_password', $this->codiceDi(fn() => $this->repo->setPassword($this->uno, $id, '12345')));
        $this->assertTrue($this->repo->setPassword($this->uno, $id, str_repeat('b', 64)), 'il massimo esatto passa');
    }
}
