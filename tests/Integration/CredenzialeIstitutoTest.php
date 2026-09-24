<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\TeacherCredentialRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La scuola di una credenziale di classe è una del docente (ADR-037, S2), e
 * la classe è una del suo catalogo (S3). Prima del 13 settembre 2026
 * l'`institute_id` arrivava dal client e si scriveva com'era (rilettura
 * degli scenari, §4.1), e una classe che la scuola non aveva lasciava la
 * credenziale valida per tutte le classi, senza dirlo.
 *
 * Fixture isolata in transazione (rollback in tearDown): due istituti, il
 * docente collegato al primo; il secondo ha una 2A sua.
 */
final class CredenzialeIstitutoTest extends TestCase
{
    private PDO $pdo;
    private TeacherCredentialRepository $repo;
    private int $docente = 0;
    private int $mia = 0;
    private int $altrui = 0;
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
            $this->pdo->query('SELECT qr_token FROM teacher_access_credentials LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migrazione 105 non disponibili: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;
        \App\Support\CurriculumLookup::resetCache();

        $ins = $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)');
        $ins->execute(['ZZCRED01', 'LA MIA SCUOLA', 'Comune Esempio']);
        $this->mia = (int)$this->pdo->lastInsertId();
        $ins->execute(['ZZCRED02', 'UNA SCUOLA ALTRUI', 'Comune Esempio']);
        $this->altrui = (int)$this->pdo->lastInsertId();
        // ADR-041 — la prova non riguarda chi dei docenti può usare le sezioni:
        // l'istituto vale «tutti», come ogni istituto prima della migrazione 126.
        $this->pdo->exec("UPDATE institutes SET sezioni_docenti = 'tutti' WHERE code IN ('ZZCRED01', 'ZZCRED02')");

        $voce = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, shared_with_pool, origine)
             VALUES (?, ?, ?, ?, ?, 1, 0, "istituto")'
        );
        $voce->execute(['indirizzi', $this->mia, 'ZCS', 'Scientifico', null]);
        $voce->execute(['classi', $this->mia, '3A', 'Terza A', 'ZCS']);
        $voce->execute(['indirizzi', $this->altrui, 'ZCS', 'Scientifico', null]);
        $voce->execute(['classi', $this->altrui, '2A', 'Seconda A', 'ZCS']);

        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, created_at)
             VALUES (?, "teacher", "Zz", "Credenziale", ?, "x", "approved", 1, NOW())'
        )->execute(['zzcredenziale', 'zzcredenziale@example.invalid']);
        $this->docente = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$this->docente, $this->mia]);

        $this->repo = new TeacherCredentialRepository();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        \App\Support\CurriculumLookup::resetCache();
    }

    /** @return array<string,mixed> */
    private function riga(int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT institute_id, indirizzo_id, classe_id FROM teacher_access_credentials_data WHERE id = ?'
        );
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($r);
        return $r;
    }

    /** @param array<string,mixed> $extra */
    private function crea(array $extra): int
    {
        static $n = 0;
        $n++;
        return $this->repo->create($this->docente, [
            'username' => 'zzcred_' . $n . '_' . substr(uniqid(), -6),
            'password' => 'Pa55!credenziale',
        ] + $extra);
    }

    #[Test]
    public function una_scuola_che_non_e_del_docente_e_rifiutata(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_institute');
        $this->crea(['institute_id' => $this->altrui, 'indirizzo' => 'ZCS', 'classe' => '2A']);
    }

    #[Test]
    public function la_propria_scuola_passa_e_la_classe_si_risolve_nel_suo_catalogo(): void
    {
        $id = $this->crea(['institute_id' => $this->mia, 'indirizzo' => 'ZCS', 'classe' => '3A']);
        $r = $this->riga($id);
        $this->assertSame($this->mia, (int)$r['institute_id']);
        $this->assertNotNull($r['classe_id'], 'la 3A della mia scuola delimita la credenziale');
        $this->assertNotNull($r['indirizzo_id']);
    }

    #[Test]
    public function senza_istituto_vale_la_scuola_del_docente(): void
    {
        $id = $this->crea(['indirizzo' => 'ZCS', 'classe' => '3A']);
        $r = $this->riga($id);
        $this->assertSame($this->mia, (int)$r['institute_id'], 'prima restava NULL e le sigle non si risolvevano');
        $this->assertNotNull($r['classe_id']);
    }

    #[Test]
    public function una_classe_che_la_scuola_non_ha_e_rifiutata_invece_di_allargare(): void
    {
        // La 2A esiste solo nella scuola altrui: nella mia non delimita niente.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_classe');
        $this->crea(['institute_id' => $this->mia, 'indirizzo' => 'ZCS', 'classe' => '2A']);
    }

    #[Test]
    public function un_indirizzo_che_la_scuola_non_ha_e_rifiutato(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_indirizzo');
        $this->crea(['institute_id' => $this->mia, 'indirizzo' => 'ZZZ', 'classe' => '']);
    }

    #[Test]
    public function la_credenziale_per_tutte_le_classi_resta_possibile(): void
    {
        $id = $this->crea([]);
        $r = $this->riga($id);
        $this->assertSame($this->mia, (int)$r['institute_id']);
        $this->assertNull($r['classe_id']);
        $this->assertNull($r['indirizzo_id']);
    }
}
