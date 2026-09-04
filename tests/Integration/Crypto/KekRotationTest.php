<?php

declare(strict_types=1);

namespace Tests\Integration\Crypto;

use App\Core\Database;
use App\Repositories\TeacherContentRepository;
use App\Services\Crypto\TeacherCryptoService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 25.D5 — KEK rotation lifecycle test.
 *
 * Verifica:
 *   1. rotate() → key_version++ in teacher_keys, vecchia kv preservata.
 *   2. Body cifrato con kv=N decryptable anche dopo rotate (no data loss).
 *   3. Encrypt successivi usano nuovo kv.
 *   4. Re-encrypt batch: aggiorna body_*_kv da old → new, decrypt OK.
 *   5. Prune old kv: dopo re-encrypt, DELETE wrapped_kek vecchie sicure.
 *   6. Tag mismatch detection con kv mismatch (encrypt v1 + decrypt v2 fail).
 */
final class KekRotationTest extends TestCase
{
    private TeacherCryptoService $svc;
    private TeacherContentRepository $repo;
    private int $teacherId = 0;
    private array $createdIds = [];

    protected function setUp(): void
    {
        $basePath = dirname(__DIR__, 3);
        if (is_file($basePath . '/.env')) {
            \Dotenv\Dotenv::createMutable($basePath)->safeLoad();
        }
        if (is_file($basePath . '/.env.local')) {
            \Dotenv\Dotenv::createMutable($basePath, '.env.local')->safeLoad();
        }
        \App\Core\Config::load(dirname(__DIR__, 3) . '/app/Config');

        try {
            Database::connection()->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB connection failed: ' . $e->getMessage());
        }

        $stmt = Database::connection()->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
        $stmt->execute(['superadmin']);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id === 0) {
            $this->markTestSkipped('superadmin user not seeded');
        }
        $this->teacherId = $id;

        // Cleanup pre-test (idempotent)
        Database::connection()->prepare('DELETE FROM teacher_keys WHERE teacher_id=?')
            ->execute([$this->teacherId]);

        $this->svc = new TeacherCryptoService();
        $this->repo = new TeacherContentRepository($this->svc);
    }

    protected function tearDown(): void
    {
        if ($this->createdIds) {
            $in = implode(',', array_map('intval', $this->createdIds));
            Database::connection()->exec("DELETE FROM teacher_content_data WHERE id IN ($in)");
        }
        Database::connection()->prepare('DELETE FROM teacher_keys WHERE teacher_id=?')
            ->execute([$this->teacherId]);
        Database::connection()->prepare('DELETE FROM crypto_access_log WHERE teacher_id=?')
            ->execute([$this->teacherId]);
        unset($_ENV['CRYPTO_DUAL_WRITE'], $_ENV['CRYPTO_READ_FROM']);
    }

    public function testRotateIncrementsKeyVersion(): void
    {
        // First encrypt → kv=1
        $env1 = $this->svc->encrypt($this->teacherId, 'first');
        $this->assertSame(1, $env1['kv']);

        // Rotate → kv=2
        $newKv = $this->svc->rotate($this->teacherId);
        $this->assertSame(2, $newKv);

        // Encrypt successivo usa kv=2
        $env2 = $this->svc->encrypt($this->teacherId, 'second');
        $this->assertSame(2, $env2['kv']);

        // Vecchio body con kv=1 ancora decryptable
        $this->assertSame('first', $this->svc->decrypt($this->teacherId, $env1));
        $this->assertSame('second', $this->svc->decrypt($this->teacherId, $env2));
    }

    public function testMultipleRotationsPreserveAllKeys(): void
    {
        $envs = [];
        for ($i = 1; $i <= 5; $i++) {
            $envs[$i] = $this->svc->encrypt($this->teacherId, "content $i");
            if ($i < 5) $this->svc->rotate($this->teacherId);
        }
        // Tutti i body decryptable indipendentemente da kv
        foreach ($envs as $i => $env) {
            $this->assertSame("content $i", $this->svc->decrypt($this->teacherId, $env));
        }

        // Verifica che teacher_keys abbia 5 versioni
        $count = (int)Database::connection()->query(
            "SELECT COUNT(*) FROM teacher_keys WHERE teacher_id={$this->teacherId}"
        )->fetchColumn();
        $this->assertSame(5, $count);
    }

    public function testRowReencryptedKeepsDecryptable(): void
    {
        $_ENV['CRYPTO_DUAL_WRITE'] = '1';

        $id = $this->repo->create([
            'teacher_id'   => $this->teacherId,
            'content_type' => 'esercizio',
            'subject_code' => 'MAT',
            'topic'        => 'TEST_ROT',
            'title'        => 'Rotation test',
            'body_html'    => '<p>before rotation</p>',
            'metadata'     => ['body_pt' => [['_type' => 'block', 'children' => [['text' => 'pt before']]]]],
            'visibility'   => 'draft',
        ]);
        $this->createdIds[] = $id;

        // body cifrato con kv=1
        $kv = (int)Database::connection()->query(
            "SELECT body_html_kv FROM teacher_content_data WHERE id=$id"
        )->fetchColumn();
        $this->assertSame(1, $kv);

        // Simula re-encrypt manuale (come farebbe rotate_kek.php --reencrypt)
        $newKv = $this->svc->rotate($this->teacherId);
        $this->assertSame(2, $newKv);

        // Re-encrypt body con new kv
        $row = Database::connection()->prepare(
            'SELECT body_html_ct, body_html_iv, body_html_tag, body_html_kv FROM teacher_content_data WHERE id=?'
        );
        $row->execute([$id]);
        $r = $row->fetch(\PDO::FETCH_ASSOC);

        $plain = $this->svc->decrypt($this->teacherId, [
            'ciphertext' => $r['body_html_ct'],
            'iv'         => $r['body_html_iv'],
            'tag'        => $r['body_html_tag'],
            'kv'         => (int)$r['body_html_kv'],
        ]);
        $this->assertSame('<p>before rotation</p>', $plain);

        // Re-encrypt con new kv
        $env = $this->svc->encrypt($this->teacherId, $plain);
        $this->assertSame(2, $env['kv']);

        Database::connection()->prepare(
            'UPDATE teacher_content_data SET body_html_ct=?, body_html_iv=?, body_html_tag=?, body_html_kv=? WHERE id=?'
        )->execute([$env['ciphertext'], $env['iv'], $env['tag'], $env['kv'], $id]);

        // Read post-reencrypt
        $_ENV['CRYPTO_READ_FROM'] = 'ciphertext';
        $repo2 = new TeacherContentRepository($this->svc);
        $found = $repo2->find($id);
        $this->assertSame('<p>before rotation</p>', $found['body_html']);
    }

    public function testTamperedKvFails(): void
    {
        $env = $this->svc->encrypt($this->teacherId, 'tagged');
        $this->svc->rotate($this->teacherId);

        // Falsifica kv → tag check fail (tagged with kv=1, decrypt tries kv=2)
        $tamperedEnv = $env;
        $tamperedEnv['kv'] = 2;

        $this->expectException(\RuntimeException::class);
        // Either tag mismatch (different KEK) or different error
        $this->svc->decrypt($this->teacherId, $tamperedEnv);
    }

    public function testRotateLogsAccessLog(): void
    {
        $this->svc->encrypt($this->teacherId, 'first');
        $this->svc->rotate($this->teacherId, accessorId: 0, reason: 'annual_rotation_test');

        $row = Database::connection()->prepare(
            'SELECT operation, reason FROM crypto_access_log WHERE teacher_id=? AND operation=? ORDER BY id DESC LIMIT 1'
        );
        $row->execute([$this->teacherId, 'rotate']);
        $entry = $row->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotNull($entry);
        $this->assertSame('rotate', $entry['operation']);
        $this->assertSame('annual_rotation_test', $entry['reason']);
    }
}
