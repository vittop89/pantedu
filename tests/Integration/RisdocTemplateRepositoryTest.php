<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\Risdoc\RisdocTemplateRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RisdocTemplateRepository (P6, 2026-09-04) — il SQL estratto da
 * Admin\RisdocAdminController deve dare gli stessi fatti sul catalogo dei
 * template: creazione amministrativa, metadati, ambito di visibilita',
 * visibilita' per docente, collaboratori, staff.
 *
 * Fixture isolata in transazione (rollback in tearDown).
 */
final class RisdocTemplateRepositoryTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private RisdocTemplateRepository $repo;
    private int $teacher = 0;
    private int $inst = 0;

    protected function setUp(): void
    {
        $basePath = dirname(__DIR__, 2);
        if (is_file($basePath . '/.env')) {
            \Dotenv\Dotenv::createMutable($basePath)->safeLoad();
        }
        if (is_file($basePath . '/.env.local')) {
            \Dotenv\Dotenv::createMutable($basePath, '.env.local')->safeLoad();
        }
        \App\Core\Config::load($basePath . '/app/Config');

        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM risdoc_template_collaborators LIMIT 1');
            $this->pdo->query('SELECT 1 FROM risdoc_template_pending_changes LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migrazioni risdoc non disponibili: ' . $e->getMessage());
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZRISD01', 'ISTITUTO RISDOC', 'Comune Esempio']);
        $this->inst = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, created_at)
             VALUES (?, "teacher", "Rita", "Risdoc", ?, "x", "approved", 1, NOW())'
        )->execute(['zzrisdoc_t', 'zzrisdoc_t@example.invalid']);
        $this->teacher = (int)$this->pdo->lastInsertId();

        $this->repo = new RisdocTemplateRepository();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function admin_template_life_cycle(): void
    {
        $code = 'ZZTEST/1_Prova_' . uniqid();
        self::assertFalse($this->repo->codeExists($code));
        $id = $this->repo->createAdminTemplate($code, 'ZZTEST', '1', 'Prova', '[]', hash('sha256', '[]'));
        self::assertGreaterThan(0, $id);
        self::assertTrue($this->repo->exists($id));
        self::assertTrue($this->repo->codeExists($code));
        self::assertFalse($this->repo->exists(999999999));

        $t = $this->repo->find($id);
        self::assertNotNull($t);
        self::assertSame('db://admin', $t['source_dir']);
        self::assertSame($code . '.pt', $t['html_file']);
        self::assertSame('public', $t['visibility_scope']);
        self::assertNull($this->repo->find(999999999));

        $this->repo->updateFields($id, ['argomento' => 'Prova bis', 'num_arg' => '2']);
        self::assertSame('Prova bis', $this->repo->find($id)['argomento']);
        self::assertSame('2', (string)$this->repo->find($id)['num_arg']);
        $this->repo->updateFields($id, []);
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->updateFields($id, ['code' => 'x']);
    }

    #[Test]
    public function una_copia_conta_come_da_riallineare_solo_se_il_modello_e_cambiato(): void
    {
        // È il numero che nel catalogo compare accanto alle copie dei docenti.
        // Il confronto è fra l'impronta del modello e quella registrata sulla
        // copia: finché coincidono la copia è allineata. Il 21/9/2026 il
        // riquadro TEX/PDF ci scriveva `manual-<data>`, che non coincide con
        // nessuna impronta — ogni copia nasceva «da riallineare» e ci restava.
        $id = $this->repo->createAdminTemplate('ZZDRIFT/1_A_' . uniqid(), 'ZZDRIFT', '1', 'A', '[]', 'impronta-prima');
        $override = new \App\Repositories\Risdoc\OverrideRepository();

        $conta = function () use ($id): int {
            foreach ($this->repo->listWithCounts() as $r) {
                if ((int)$r['id'] === $id) {
                    return (int)$r['drift_count'];
                }
            }
            self::fail('il modello di prova non è nel catalogo');
        };

        // Una copia fatta adesso, dalla versione corrente: allineata.
        $override->saveText($this->teacher, $id, 'texCommon', 'risdoc.sty', '% copia', 'impronta-prima');
        self::assertSame(0, $conta(), 'una copia appena fatta non è da riallineare');

        // Il modello cambia: la stessa copia adesso viene da una versione vecchia.
        $this->pdo->prepare('UPDATE risdoc_templates SET source_hash = ? WHERE id = ?')
            ->execute(['impronta-dopo', $id]);
        self::assertSame(1, $conta(), 'cambiato il modello, la copia è da riallineare');

        // E rifacendola dalla versione nuova torna allineata.
        $override->saveText($this->teacher, $id, 'texCommon', 'risdoc.sty', '% copia rifatta', 'impronta-dopo');
        self::assertSame(0, $conta(), 'rifatta dalla versione nuova, non lo è più');
    }

    #[Test]
    public function category_rename_scope_visibility_and_collaborators(): void
    {
        $id = $this->repo->createAdminTemplate('ZZCAT/1_A_' . uniqid(), 'ZZCAT', '1', 'A', '[]', 'h');
        self::assertSame(1, $this->repo->renameCategory('ZZCAT', 'ZZCAT2'));
        self::assertSame('ZZCAT2', $this->repo->find($id)['category']);
        self::assertSame(0, $this->repo->renameCategory('ZZNOPE', 'ZZNOPE2'));

        self::assertTrue($this->repo->instituteExists($this->inst));
        self::assertFalse($this->repo->instituteExists(999999999));
        $this->repo->setVisibilityScope($id, 'institute', $this->inst, 'SC', null);
        $t = $this->repo->find($id);
        self::assertSame('institute', $t['visibility_scope']);
        self::assertSame($this->inst, (int)$t['scope_institute_id']);
        self::assertSame('SC', $t['scope_indirizzo']);
        self::assertNull($t['scope_classe']);

        self::assertSame(1, $this->repo->setVisibility($id, [$this->teacher], 1, null));
        $vis = $this->repo->visibilityOf($id);
        self::assertCount(1, $vis);
        self::assertSame('zzrisdoc_t', $vis[0]['username']);
        self::assertSame(1, (int)$vis[0]['visible']);
        $this->repo->setVisibility($id, [$this->teacher], 0, $this->teacher);
        self::assertSame(0, (int)$this->repo->visibilityOf($id)[0]['visible'], 'upsert, non doppione');

        $this->repo->addCollaborator($id, $this->teacher, null, 1);
        $c = $this->repo->collaboratorsOf($id);
        self::assertCount(1, $c);
        self::assertSame(1, (int)$c[0]['requires_review']);
        $this->repo->setCollaboratorReview($id, $this->teacher, 0);
        self::assertSame(0, (int)$this->repo->collaboratorsOf($id)[0]['requires_review']);
        $this->repo->removeCollaborator($id, $this->teacher);
        self::assertSame([], $this->repo->collaboratorsOf($id));

        $row = null;
        foreach ($this->repo->listWithCounts() as $r) {
            if ((int)$r['id'] === $id) {
                $row = $r;
            }
        }
        self::assertNotNull($row, 'il catalogo include il template');
        // 21/9/2026 — l'elenco porta anche l'ambito: la pastiglia «chi lo
        // vede» accanto a ogni modello lo legge da qui, e senza queste colonne
        // mostrerebbe «tutti» per ogni riga, ambito stretto compreso.
        self::assertSame('institute', (string)$row['visibility_scope'], 'l\'ambito arriva nell\'elenco');
        self::assertSame('SC', (string)$row['scope_indirizzo'], 'e con il suo dettaglio');
        self::assertSame(0, (int)$row['visible_count'], 'visibile=0 non conta');
        self::assertSame(0, (int)$row['collab_count']);
        self::assertSame(0, (int)$row['pending_count']);

        self::assertContains($this->teacher, array_map(static fn(array $u) => (int)$u['id'], $this->repo->staffUsers()));
        // `driftedOverrides()` dichiara già di restituire un array, quindi
        // asserirlo non verifica niente. Quello che questa riga controlla è
        // che la query regga sul database di prova: se sollevasse, il test
        // fallirebbe qui. Non si asserisce il contenuto perché questo caso non
        // lo prepara, e inventarne uno atteso sarebbe una supposizione.
        $this->repo->driftedOverrides();
    }
}
