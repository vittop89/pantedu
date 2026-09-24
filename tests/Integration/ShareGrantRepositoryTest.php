<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\Sharing\ShareGrantRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ShareGrantRepository (P6, 2026-09-04) — il SQL estratto da
 * ShareGrantsController deve dare gli stessi fatti: colleghi = docenti
 * attivi con almeno un istituto in comune; grants e membri si sostituiscono
 * per intero; cancellare un gruppo toglie anche i grants verso quel gruppo.
 *
 * Fixture isolata in transazione (rollback in tearDown): due istituti, tre
 * docenti (A e B nel primo, C nel secondo).
 */
final class ShareGrantRepositoryTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private ShareGrantRepository $repo;
    private int $a = 0;
    private int $b = 0;
    private int $c = 0;
    private int $inst1 = 0;

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
            $this->pdo->query('SELECT 1 FROM content_shares LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migration 046 non disponibili: ' . $e->getMessage());
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;

        $insInst = $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)');
        $insInst->execute(['ZZSHARE01', 'ISTITUTO SHARE UNO', 'Comune Esempio']);
        $this->inst1 = (int)$this->pdo->lastInsertId();
        $insInst->execute(['ZZSHARE02', 'ISTITUTO SHARE DUE', 'Comune Esempio']);
        $inst2 = (int)$this->pdo->lastInsertId();

        $insUser = $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, created_at)
             VALUES (?, "teacher", ?, "Share", ?, "x", "approved", 1, NOW())'
        );
        $insPivot = $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)');
        foreach ([['zzshare_a', 'Anna', $this->inst1], ['zzshare_b', 'Bruno', $this->inst1], ['zzshare_c', 'Carla', $inst2]] as [$u, $n, $iid]) {
            $insUser->execute([$u, $n, $u . '@example.invalid']);
            $id = (int)$this->pdo->lastInsertId();
            $insPivot->execute([$id, $iid]);
            if ($u === 'zzshare_a') {
                $this->a = $id;
            } elseif ($u === 'zzshare_b') {
                $this->b = $id;
            } else {
                $this->c = $id;
            }
        }
        $this->repo = new ShareGrantRepository();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function colleagues_share_an_institute_and_exclude_the_actor(): void
    {
        $ids = array_map(static fn(array $r) => (int)$r['id'], $this->repo->colleaguesOf($this->a));
        self::assertContains($this->b, $ids);
        self::assertNotContains($this->a, $ids, 'l\'attore non e\' collega di se stesso');
        self::assertNotContains($this->c, $ids, 'altro istituto');

        self::assertSame([$this->b], $this->repo->onlyColleagues($this->a, [$this->b, $this->c, 999999999]));
        self::assertSame([], $this->repo->onlyColleagues($this->a, []));
    }

    #[Test]
    public function groups_members_and_grants_round_trip(): void
    {
        $gid = $this->repo->createGroup($this->a, 'Dipartimento', 'prova');
        self::assertGreaterThan(0, $gid);
        self::assertSame($this->a, $this->repo->groupOwner($gid));
        self::assertSame(0, $this->repo->groupOwner(999999999));

        $groups = $this->repo->groupsOf($this->a);
        self::assertCount(1, $groups);
        self::assertSame('Dipartimento', $groups[0]['name']);
        self::assertSame(0, (int)$groups[0]['members_count']);

        $this->repo->replaceMembers($gid, [$this->b]);
        $members = $this->repo->membersOf($gid);
        self::assertSame([$this->b], array_map(static fn(array $m) => (int)$m['id'], $members));
        self::assertSame('Bruno Share', $members[0]['display_name']);
        $this->repo->replaceMembers($gid, []);
        self::assertSame([], $this->repo->membersOf($gid));

        $this->repo->replaceGrants($this->a, 'teacher_content', 123456, [
            ['target_type' => 'group', 'target_id' => $gid],
            ['target_type' => 'teacher', 'target_id' => $this->b],
        ]);
        $grants = $this->repo->grantsFor($this->a, 'teacher_content', 123456);
        // ORDER BY target_type su una colonna ENUM ordina per posizione
        // nell'enum ('institute','teacher','group'), non alfabeticamente.
        self::assertSame(['teacher', 'group'], array_column($grants, 'target_type'), 'ordinati per tipo (enum)');
        self::assertSame('Dipartimento', $this->repo->targetLabel('group', $gid));
        self::assertSame('Bruno Share', $this->repo->targetLabel('teacher', $this->b));
        self::assertSame('ISTITUTO SHARE UNO', $this->repo->targetLabel('institute', $this->inst1));
        self::assertSame('group#999999999', $this->repo->targetLabel('group', 999999999));

        $this->repo->replaceGrants($this->a, 'teacher_content', 123456, [['target_type' => 'teacher', 'target_id' => $this->b]]);
        self::assertCount(1, $this->repo->grantsFor($this->a, 'teacher_content', 123456), 'sostituzione per intero');

        $this->repo->replaceGrants($this->a, 'teacher_content', 123456, [['target_type' => 'group', 'target_id' => $gid]]);
        $this->repo->deleteGroup($this->a, $gid);
        self::assertSame(0, $this->repo->groupOwner($gid));
        self::assertSame([], $this->repo->grantsFor($this->a, 'teacher_content', 123456), 'i grants verso il gruppo cadono');
    }

    #[Test]
    public function duplicate_group_name_for_the_same_owner_is_rejected_by_the_database(): void
    {
        $this->repo->createGroup($this->a, 'Doppione', null);
        $this->expectException(\PDOException::class);
        $this->repo->createGroup($this->a, 'Doppione', null);
    }
}
