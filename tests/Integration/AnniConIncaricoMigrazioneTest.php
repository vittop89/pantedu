<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La migrazione 133 (ADR-043), sui dati: con «solo incaricati» le spunte sugli
 * anni senza incarico si sospendono; quelle con l'incarico, quelle delle
 * sezioni e quelle degli istituti «tutti» e «nessuno» restano come sono.
 * Rilanciata non cambia niente. Tutto in transazione, annullato alla fine.
 */
final class AnniConIncaricoMigrazioneTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function migrazione(): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/133_anni_con_incarico.sql');
        foreach (Migrator::splitStatements($sql) as $istruzione) {
            $this->pdo->exec($istruzione);
        }
    }

    /** @return array{0:int, 1:array<string,int>} l'istituto e le sue voci */
    private function scuola(string $codice, string $modalita): array
    {
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active, sezioni_docenti) VALUES (?, ?, ?, 1, ?)')
            ->execute([$codice, 'ISTITUTO DELLA 133 ' . $codice, 'Comune Esempio', $modalita]);
        $ist = (int)$this->pdo->lastInsertId();
        $voci = [];
        $ins = $this->pdo->prepare("INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, origine) VALUES (?, ?, ?, ?, ?, 1, 'istituto')");
        foreach ([['indirizzi', 'ZNS', null], ['classi', '1', 'ZNS'], ['classi', '2', 'ZNS'], ['classi', '1N', 'ZNS']] as [$k, $c, $i]) {
            $ins->execute([$k, $ist, $c, $c, $i]);
            $voci[$c] = (int)$this->pdo->lastInsertId();
        }
        return [$ist, $voci];
    }

    private function docente(string $nome, int $ist): int
    {
        $this->pdo->prepare("INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active) VALUES (?, 'teacher', 'Zz', 'Centotrentatré', ?, 'x', 'approved', 1)")
            ->execute([$nome, "$nome@example.test"]);
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$id, $ist]);
        return $id;
    }

    private function spunta(int $voce, int $docente): void
    {
        $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active) VALUES (?, ?, 1)')->execute([$voce, $docente]);
    }

    /** @return array{0:int,1:int} active e sospesa */
    private function stato(int $voce, int $docente): array
    {
        $st = $this->pdo->prepare('SELECT active, sospesa_dalla_scuola FROM curriculum_teacher WHERE curriculum_id = ? AND user_id = ?');
        $st->execute([$voce, $docente]);
        return array_map('intval', $st->fetch(PDO::FETCH_NUM));
    }

    #[Test]
    public function con_solo_incaricati_si_sospendono_gli_anni_senza_incarico_e_basta(): void
    {
        [$ist, $v] = $this->scuola('ZZMIG133A', 'solo_incaricati');
        [$tutti, $vt] = $this->scuola('ZZMIG133B', 'tutti');
        [$nessuno, $vn] = $this->scuola('ZZMIG133C', 'nessuno');
        $d = $this->docente('zz_mig133_doc', $ist);
        foreach ([$v['ZNS'], $v['1'], $v['2'], $v['1N'], $vt['1'], $vn['1']] as $voce) {
            $this->spunta($voce, $d);
        }
        $this->pdo->prepare("INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe) VALUES (?, ?, 'ZNS', '1'), (?, ?, 'ZNS', '1N')")
            ->execute([$d, $ist, $d, $ist]);

        $this->migrazione();

        self::assertSame([1, 0], $this->stato($v['1'], $d), 'l\'anno con l\'incarico resta');
        self::assertSame([0, 1], $this->stato($v['2'], $d), 'l\'anno senza incarico si sospende');
        self::assertSame([1, 0], $this->stato($v['1N'], $d), 'le sezioni non si toccano qui');
        self::assertSame([1, 0], $this->stato($v['ZNS'], $d), 'gli indirizzi nemmeno');
        self::assertSame([1, 0], $this->stato($vt['1'], $d), 'con «tutti» gli anni restano');
        self::assertSame([1, 0], $this->stato($vn['1'], $d), 'con «nessuno» gli anni restano');

        $this->migrazione();
        self::assertSame([0, 1], $this->stato($v['2'], $d), 'rilanciata non cambia niente');
        self::assertSame([1, 0], $this->stato($v['1'], $d));
    }
}
