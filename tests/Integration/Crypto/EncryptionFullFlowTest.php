<?php

declare(strict_types=1);

namespace Tests\Integration\Crypto;

use App\Core\Database;
use App\Repositories\TeacherContentRepository;
use App\Services\Crypto\TeacherCryptoService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 25.D8 — Full encryption flow integration test.
 *
 * Simula il workflow production sequence (Phase 25.D3+D4+D5+D13 sequence):
 *
 *   Step 1 (D3 deploy): CRYPTO_DUAL_WRITE=1, READ_FROM=plaintext
 *     - Nuovi write cifrano → ciphertext popolato
 *     - Read legge plaintext (legacy)
 *
 *   Step 2 (D4 backfill): row legacy plaintext → cifrate in-place
 *     - Verify byte-byte: encrypt + re-decrypt match
 *
 *   Step 3 (D13 deploy): READ_FROM=ciphertext
 *     - Read decifra dai *_ct columns
 *     - Plaintext columns ancora popolate ma ignorate
 *
 *   Step 4 (post-D13): DROP plaintext columns
 *     - Solo ciphertext path attivo
 *     - Crypto-shredding O(1) verificato
 *
 * Test simula la sequenza in 1 spec, verificando la consistenza
 * end-to-end del data path.
 */
final class EncryptionFullFlowTest extends TestCase
{
    private TeacherContentRepository $repo;
    private TeacherCryptoService $svc;
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
            $this->markTestSkipped('DB not available');
        }

        $stmt = Database::connection()->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
        $stmt->execute(['superadmin']);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id === 0) $this->markTestSkipped('superadmin not seeded');
        $this->teacherId = $id;

        // Cleanup any pre-existing keys
        Database::connection()->prepare('DELETE FROM teacher_keys WHERE teacher_id=?')->execute([$this->teacherId]);

        $this->svc  = new TeacherCryptoService();
        $this->repo = new TeacherContentRepository($this->svc);
    }

    protected function tearDown(): void
    {
        if ($this->createdIds) {
            $in = implode(',', array_map('intval', $this->createdIds));
            Database::connection()->exec("DELETE FROM teacher_content_data WHERE id IN ($in)");
        }
        Database::connection()->prepare('DELETE FROM teacher_keys WHERE teacher_id=?')->execute([$this->teacherId]);
        Database::connection()->prepare('DELETE FROM crypto_access_log WHERE teacher_id=?')->execute([$this->teacherId]);
        unset($_ENV['CRYPTO_DUAL_WRITE'], $_ENV['CRYPTO_READ_FROM']);
    }

    /**
     * Test SEQUENCE COMPLETA: simula i 4 deploy step in 1 test.
     */
    public function testFullProductionDeploymentSequence(): void
    {
        // ─── Step 1 (legacy, no encryption) ───
        unset($_ENV['CRYPTO_DUAL_WRITE'], $_ENV['CRYPTO_READ_FROM']);

        $idLegacy = $this->repo->create([
            'teacher_id'   => $this->teacherId,
            'content_type' => 'esercizio',
            'subject_code' => 'MAT',
            'topic'        => 'TEST_LEGACY',
            'title'        => 'Legacy plaintext',
            'body_html'    => '<p>plaintext-only legacy</p>',
            'metadata'     => ['body_pt' => [['_type' => 'block', 'children' => [['text' => 'pt-legacy']]]]],
            'visibility'   => 'draft',
        ]);
        $this->createdIds[] = $idLegacy;

        $row = Database::connection()->query("SELECT body_html, body_html_ct FROM teacher_content_data WHERE id=$idLegacy")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row['body_html']);
        $this->assertNull($row['body_html_ct'], 'Step 1: solo plaintext (no flag)');

        // ─── Step 2 (D3 deploy: dual-write attivo, read legacy) ───
        $_ENV['CRYPTO_DUAL_WRITE'] = '1';

        $idDual = $this->repo->create([
            'teacher_id'   => $this->teacherId,
            'content_type' => 'esercizio',
            'subject_code' => 'MAT',
            'topic'        => 'TEST_DUAL',
            'title'        => 'Dual-write phase',
            'body_html'    => '<p>dual-write content</p>',
            'metadata'     => ['body_pt' => [['_type' => 'block', 'children' => [['text' => 'pt-dual']]]]],
            'visibility'   => 'draft',
        ]);
        $this->createdIds[] = $idDual;

        $row = Database::connection()->query("SELECT body_html, body_html_ct, body_pt_ct FROM teacher_content_data WHERE id=$idDual")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row['body_html'], 'plaintext preservato');
        $this->assertNotNull($row['body_html_ct'], 'Step 2: ciphertext popolato (dual-write)');
        $this->assertNotNull($row['body_pt_ct']);

        // Read ancora legacy (READ_FROM=plaintext default)
        $found = $this->repo->find($idDual);
        $this->assertSame('<p>dual-write content</p>', $found['body_html']);

        // ─── Step 3 (D4 backfill: row legacy → cifrato) ───
        // Simula backfill di idLegacy (chiamando update con dual-write on)
        $this->repo->update($idLegacy, $this->teacherId, [
            'body_html' => '<p>plaintext-only legacy</p>',  // re-write triggers encrypt
            'metadata'  => ['body_pt' => [['_type' => 'block', 'children' => [['text' => 'pt-legacy']]]]],
        ]);

        $row = Database::connection()->query("SELECT body_html_ct, body_pt_ct FROM teacher_content_data WHERE id=$idLegacy")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotNull($row['body_html_ct'], 'Step 3: post-backfill ciphertext popolato');
        $this->assertNotNull($row['body_pt_ct']);

        // ─── Step 4 (D13 deploy: switch read to ciphertext) ───
        $_ENV['CRYPTO_READ_FROM'] = 'ciphertext';
        $repo = new TeacherContentRepository($this->svc);

        // Read di idLegacy (backfilled): decifra ciphertext, deve match plaintext originale
        $found = $repo->find($idLegacy);
        $this->assertSame('<p>plaintext-only legacy</p>', $found['body_html']);
        $this->assertSame('pt-legacy', $found['metadata']['body_pt'][0]['children'][0]['text']);

        // Read di idDual: anch'esso decryptato correttamente
        $found = $repo->find($idDual);
        $this->assertSame('<p>dual-write content</p>', $found['body_html']);
        $this->assertSame('pt-dual', $found['metadata']['body_pt'][0]['children'][0]['text']);
    }

    public function testCryptoShreddingArt17AffectsAllRows(): void
    {
        $_ENV['CRYPTO_DUAL_WRITE'] = '1';
        $_ENV['CRYPTO_READ_FROM'] = 'ciphertext';

        // Crea 3 row con encrypt
        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $ids[] = $this->repo->create([
                'teacher_id'   => $this->teacherId,
                'content_type' => 'esercizio',
                'subject_code' => 'MAT',
                'topic'        => "TEST_SHRED_$i",
                'title'        => "Shred $i",
                'body_html'    => "<p>content $i</p>",
                'metadata'     => ['body_pt' => [['_type' => 'block', 'children' => [['text' => "pt $i"]]]]],
                'visibility'   => 'draft',
            ]);
        }
        $this->createdIds = array_merge($this->createdIds, $ids);

        // Verify all 3 readable
        foreach ($ids as $i => $id) {
            $found = $this->repo->find($id);
            $this->assertStringContainsString('content', $found['body_html']);
        }

        // Crypto-shredding O(1) (Art. 17 GDPR)
        $this->svc->shred($this->teacherId, accessorId: $this->teacherId, reason: 'Art_17_user_request');

        // Verify TUTTI i 3 body now unreadable
        foreach ($ids as $i => $id) {
            $found = $this->repo->find($id);
            $this->assertNull($found['body_html'], "row $i body unreadable post-shred");
            $this->assertArrayHasKey('_crypto_error', $found);
        }
    }

    public function testEncryptionTransparentToFrontendGetSet(): void
    {
        // Simula request HTTP cycle: write via API + read via API.
        // Frontend NON sa nulla di encryption — riceve plaintext via HTTPS.
        $_ENV['CRYPTO_DUAL_WRITE'] = '1';
        $_ENV['CRYPTO_READ_FROM'] = 'ciphertext';

        $sensitiveData = [
            'body_html' => '<h1>Compito in classe — soluzione riservata</h1><p>x = sqrt(7)</p>',
            'metadata'  => [
                'category' => 'COMPITO',
                'stats'    => ['has_tikz' => false, 'difficulty_max' => 4],
                'body_pt'  => [
                    ['_type' => 'block', 'children' => [['text' => 'Risolvi:']]],
                    ['_type' => 'mathBlock', 'tex' => '\\sqrt{7}'],
                ],
            ],
        ];

        $id = $this->repo->create([
            'teacher_id'   => $this->teacherId,
            'content_type' => 'verifica',
            'subject_code' => 'MAT',
            'topic'        => 'TEST_TRANSPARENT',
            'title'        => 'Compito segreto',
            'body_html'    => $sensitiveData['body_html'],
            'metadata'     => $sensitiveData['metadata'],
            'visibility'   => 'draft',
        ]);
        $this->createdIds[] = $id;

        // Read: plaintext identico a quello scritto. Frontend sees full content.
        $found = $this->repo->find($id);
        $this->assertSame($sensitiveData['body_html'], $found['body_html']);
        $this->assertSame($sensitiveData['metadata']['body_pt'], $found['metadata']['body_pt']);
        $this->assertSame('COMPITO', $found['metadata']['category']);
        $this->assertSame(4, $found['metadata']['stats']['difficulty_max']);

        // Verify DB-LEVEL: il body_html plaintext nella row contiene anche
        // il valore (dual-write preserva plaintext durante backfill phase).
        // Dopo Phase D13 (DROP plaintext), questo cambierà.
        $row = Database::connection()->query("
            SELECT LENGTH(body_html_ct) AS ct_len, body_html_iv IS NOT NULL AS iv,
                   body_html_tag IS NOT NULL AS tag
            FROM teacher_content_data WHERE id=$id
        ")->fetch(\PDO::FETCH_ASSOC);
        $this->assertGreaterThan(50, $row['ct_len'], 'ciphertext > 50 bytes');
        $this->assertSame(1, (int)$row['iv']);
        $this->assertSame(1, (int)$row['tag']);
    }
}
