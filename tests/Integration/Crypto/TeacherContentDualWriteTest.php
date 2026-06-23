<?php

declare(strict_types=1);

namespace Tests\Integration\Crypto;

use App\Core\Database;
use App\Repositories\TeacherContentRepository;
use App\Services\Crypto\TeacherCryptoService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 25.D3 — Integration test repository dual-write encryption.
 *
 * Verifica:
 *   1. CRYPTO_DUAL_WRITE=false (default): create/update populate solo
 *      plaintext, ciphertext columns NULL (legacy compatible).
 *   2. CRYPTO_DUAL_WRITE=true: create populate sia plaintext sia ciphertext.
 *      body_pt extracted da metadata, metadata_json plaintext senza body_pt.
 *   3. CRYPTO_READ_FROM=ciphertext: find() decifra e ricostruisce metadata.body_pt.
 *   4. CRYPTO_READ_FROM=plaintext (default): find() ritorna body_html dalla
 *      colonna plaintext (legacy path).
 *   5. Update con dual-write: cipher columns aggiornati coerentemente.
 *   6. JSON_EXTRACT su metadata.stats.* funziona (metadata plaintext preservato).
 */
final class TeacherContentDualWriteTest extends TestCase
{
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

        // Isolamento per-test: i test crypto condividono il teacher seed
        // (superadmin). Azzera ogni suo dato cifrato → il guard
        // kek_regen non scatta (0 righe/blob) e i test non si inquinano a
        // vicenda nell'ordine random. Sicuro: girano su pantedu_test isolato.
        $db = Database::connection();
        $db->prepare('DELETE FROM teacher_content_data WHERE teacher_id=?')->execute([$id]);
        $db->prepare('DELETE FROM teacher_keys WHERE teacher_id=?')->execute([$id]);
        $db->prepare('DELETE FROM crypto_access_log WHERE teacher_id=?')->execute([$id]);
        $blobDir = (string)\App\Core\Config::get('app.paths.storage', dirname(__DIR__, 3) . '/storage')
                 . '/maps_enc/' . $id;
        if (is_dir($blobDir)) {
            foreach (glob($blobDir . '/*.bin') ?: [] as $f) { @unlink($f); }
        }

        $this->repo = new TeacherContentRepository();
    }

    protected function tearDown(): void
    {
        // Cleanup: rimuovi i row creati + KEK + access log per teacher
        if ($this->createdIds) {
            $in = implode(',', array_map('intval', $this->createdIds));
            Database::connection()->exec("DELETE FROM teacher_content_data WHERE id IN ($in)");
        }
        Database::connection()->prepare('DELETE FROM teacher_keys WHERE teacher_id=?')->execute([$this->teacherId]);
        Database::connection()->prepare('DELETE FROM crypto_access_log WHERE teacher_id=?')->execute([$this->teacherId]);
        // Reset env flags
        unset($_ENV['CRYPTO_DUAL_WRITE'], $_ENV['CRYPTO_READ_FROM']);
    }

    public function testCreateLegacyNoDualWriteOnlyPlaintext(): void
    {
        unset($_ENV['CRYPTO_DUAL_WRITE']);  // default false
        $id = $this->createSampleContent();

        $row = Database::connection()->query(
            "SELECT body_html, body_html_ct, body_pt_ct, metadata_json FROM teacher_content WHERE id=$id"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('<p>plaintext body</p>', $row['body_html']);
        $this->assertNull($row['body_html_ct'], 'no ciphertext senza dual-write');
        $this->assertNull($row['body_pt_ct'], 'no body_pt_ct senza dual-write');
        // metadata.body_pt resta nel metadata_json plaintext (no extraction)
        $meta = json_decode($row['metadata_json'], true);
        $this->assertArrayHasKey('body_pt', $meta, 'body_pt in metadata legacy');
    }

    public function testCreateDualWritePopulatesCiphertextAndExtractsBodyPt(): void
    {
        $_ENV['CRYPTO_DUAL_WRITE'] = '1';
        $id = $this->createSampleContent();

        $row = Database::connection()->query(
            "SELECT body_html, body_html_ct, body_html_kv, body_pt_ct, body_pt_kv, metadata_json
             FROM teacher_content WHERE id=$id"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('<p>plaintext body</p>', $row['body_html'], 'plaintext preserved');
        $this->assertNotNull($row['body_html_ct'], 'body_html_ct populated');
        $this->assertSame(1, (int)$row['body_html_kv']);
        $this->assertNotNull($row['body_pt_ct'], 'body_pt_ct populated');
        $this->assertSame(1, (int)$row['body_pt_kv']);

        // metadata_json NON deve contenere body_pt (extracted)
        $meta = json_decode($row['metadata_json'], true);
        $this->assertArrayNotHasKey('body_pt', $meta, 'body_pt extracted from metadata');
        $this->assertSame('TEST', $meta['category'], 'category preserved');
        $this->assertTrue($meta['stats']['has_tikz'], 'stats preserved (per JSON_EXTRACT)');
    }

    public function testReadFromCiphertextRoundtrip(): void
    {
        $_ENV['CRYPTO_DUAL_WRITE'] = '1';
        $id = $this->createSampleContent();

        // Switch read mode
        $_ENV['CRYPTO_READ_FROM'] = 'ciphertext';
        $repo = new TeacherContentRepository();
        $found = $repo->find($id);

        $this->assertNotNull($found);
        $this->assertSame('<p>plaintext body</p>', $found['body_html']);
        $this->assertArrayHasKey('body_pt', $found['metadata']);
        $this->assertSame('block', $found['metadata']['body_pt'][0]['_type']);
        $this->assertSame('Sensitive content', $found['metadata']['body_pt'][0]['children'][0]['text']);
    }

    public function testReadFromPlaintextLegacy(): void
    {
        unset($_ENV['CRYPTO_DUAL_WRITE'], $_ENV['CRYPTO_READ_FROM']);  // legacy path
        $id = $this->createSampleContent();

        $found = $this->repo->find($id);
        $this->assertSame('<p>plaintext body</p>', $found['body_html']);
        // metadata.body_pt resta da metadata_json plaintext
        $this->assertArrayHasKey('body_pt', $found['metadata']);
    }

    public function testUpdateDualWriteRefreshesCiphertext(): void
    {
        $_ENV['CRYPTO_DUAL_WRITE'] = '1';
        $id = $this->createSampleContent();

        $ok = $this->repo->update($id, $this->teacherId, [
            'body_html' => '<p>updated body</p>',
            'metadata' => ['category' => 'TEST', 'body_pt' => [['_type' => 'block', 'children' => [['text' => 'updated pt']]]]],
        ]);
        $this->assertTrue($ok);

        $_ENV['CRYPTO_READ_FROM'] = 'ciphertext';
        $repo = new TeacherContentRepository();
        $found = $repo->find($id);
        $this->assertSame('<p>updated body</p>', $found['body_html']);
        $this->assertSame('updated pt', $found['metadata']['body_pt'][0]['children'][0]['text']);
    }

    public function testJsonExtractStatsWorksOnPlaintextMetadata(): void
    {
        $_ENV['CRYPTO_DUAL_WRITE'] = '1';
        $id = $this->createSampleContent();

        // Verifica che JSON_EXTRACT funzioni anche con metadata cifrato body_pt
        // (perché metadata_json plaintext mantiene stats.* per filtro SQL).
        $stmt = Database::connection()->prepare(
            "SELECT JSON_EXTRACT(metadata_json, '$.stats.has_tikz') AS h,
                    JSON_EXTRACT(metadata_json, '$.stats.difficulty_max') AS d
             FROM teacher_content WHERE id=?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('true', $row['h']);
        $this->assertSame('3', $row['d']);
    }

    public function testCryptoShreddingMakesBodyUnreadable(): void
    {
        $_ENV['CRYPTO_DUAL_WRITE'] = '1';
        $_ENV['CRYPTO_READ_FROM'] = 'ciphertext';
        $id = $this->createSampleContent();

        // Verify decryption works
        $found = $this->repo->find($id);
        $this->assertSame('<p>plaintext body</p>', $found['body_html']);

        // Crypto-shredding (Art. 17 GDPR)
        (new TeacherCryptoService())->shred($this->teacherId);

        // Re-read: body_html ora NULL (decrypt failed) + _crypto_error settato
        $repo = new TeacherContentRepository();
        $found2 = $repo->find($id);
        $this->assertNull($found2['body_html'], 'body_html unreadable post-shred');
        $this->assertArrayHasKey('_crypto_error', $found2);
        $this->assertStringContainsString('teacher_key_missing', $found2['_crypto_error']);
    }

    private function createSampleContent(): int
    {
        $id = $this->repo->create([
            'teacher_id'   => $this->teacherId,
            'content_type' => 'esercizio',
            'subject_code' => 'MAT',
            'indirizzo'    => 'sc',
            'classe'       => '2s',
            'topic'        => 'TEST_DW_' . uniqid(),
            'title'        => 'Test dual-write',
            'body_html'    => '<p>plaintext body</p>',
            'metadata'     => [
                'category' => 'TEST',
                'stats'    => ['has_tikz' => true, 'difficulty_max' => 3],
                'body_pt'  => [['_type' => 'block', 'children' => [['text' => 'Sensitive content']]]],
            ],
            'visibility'   => 'draft',
        ]);
        $this->createdIds[] = $id;
        return $id;
    }
}
