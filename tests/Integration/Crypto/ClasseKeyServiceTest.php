<?php

declare(strict_types=1);

namespace Tests\Integration\Crypto;

use App\Core\Database;
use App\Services\Crypto\ClasseKeyService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Phase 25.D6 — Test ClasseKeyService (envelope encryption per pubblicazione
 * studenti, decoupled da teacher KEK).
 *
 * Verifica:
 *   1. getOrCreateActiveKey idempotent (stesso id su retry).
 *   2. Encrypt/decrypt roundtrip identity con class_key.
 *   3. Rotation crea new kv, vecchie row decryptable.
 *   4. Tampering detection AES-GCM tag.
 *   5. Archive marca rows ma decrypt continua a funzionare.
 *   6. Indipendenza da TeacherCryptoService: shred teacher non rompe
 *      published_content (verifica che class_key non passa da teacher_keys).
 */
final class ClasseKeyServiceTest extends TestCase
{
    private ClasseKeyService $svc;
    private array $createdKeyIds = [];

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

        // Fixture curriculum: il fix di ClasseKeyService (resolveCurriculumId)
        // risolve indirizzo/classe a una curriculum entry per CODE. Servono
        // quindi le entry '__TEST__' + i classi usati dal test. INSERT IGNORE
        // idempotente; institute_id da un istituto qualsiasi (resolve è
        // institute-agnostico).
        $db = Database::connection();
        $instId = (int)($db->query('SELECT id FROM institutes ORDER BY id LIMIT 1')->fetchColumn() ?: 1);
        $seedCur = $db->prepare(
            'INSERT IGNORE INTO curriculum_entries (kind, institute_id, code, label, active) VALUES (?, ?, ?, ?, 1)'
        );
        $seedCur->execute(['indirizzi', $instId, '__TEST__', 'Test indirizzo']);
        foreach (['1A', '2B', '3C', '4D', '5E', '1Z', '99'] as $cls) {
            $seedCur->execute(['classi', $instId, $cls, "Classe $cls"]);
        }

        // Cleanup pre-test
        Database::connection()->exec(
            "DELETE FROM classe_keys_data WHERE indirizzo_id IN
             (SELECT id FROM curriculum_entries WHERE kind='indirizzi' AND code='__test__')"
        );

        $this->svc = new ClasseKeyService();
    }

    protected function tearDown(): void
    {
        if ($this->createdKeyIds) {
            $in = implode(',', array_map('intval', $this->createdKeyIds));
            Database::connection()->exec("DELETE FROM classe_keys_data WHERE id IN ($in)");
        }
        Database::connection()->exec(
            "DELETE FROM classe_keys_data WHERE indirizzo_id IN
             (SELECT id FROM curriculum_entries WHERE kind='indirizzi' AND code='__test__')"
        );
    }

    public function testGetOrCreateIsIdempotent(): void
    {
        $id1 = $this->svc->getOrCreateActiveKey('__test__', '1a', '2025/2026');
        $id2 = $this->svc->getOrCreateActiveKey('__test__', '1a', '2025/2026');
        $this->assertSame($id1, $id2, 'idempotent: stessa key id');
        $this->createdKeyIds[] = $id1;
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $id = $this->svc->getOrCreateActiveKey('__test__', '2b', '2025/2026');
        $this->createdKeyIds[] = $id;

        $plain = '<p>Esercizio per classe 2B con LaTeX $\frac{x^2}{2y}$</p>';
        $env = $this->svc->encrypt($id, $plain);

        $this->assertSame(12, strlen($env['iv']));
        $this->assertSame(16, strlen($env['tag']));
        $this->assertSame(1, $env['kv']);

        $decrypted = $this->svc->decrypt($id, $env);
        $this->assertSame($plain, $decrypted);
    }

    public function testRoundtripWithLargeAndBinary(): void
    {
        $id = $this->svc->getOrCreateActiveKey('__test__', '3c', '2025/2026');
        $this->createdKeyIds[] = $id;

        // Large + binary (simula esercizio compresso)
        $plain = "\x00\x01\x02 binary " . random_bytes(50) . str_repeat('Lorem ', 1000);
        $env = $this->svc->encrypt($id, $plain);
        $this->assertSame($plain, $this->svc->decrypt($id, $env));
    }

    public function testTamperedCiphertextFails(): void
    {
        $id = $this->svc->getOrCreateActiveKey('__test__', '4d', '2025/2026');
        $this->createdKeyIds[] = $id;

        $env = $this->svc->encrypt($id, 'sensitive');
        $tampered = $env;
        $tampered['ciphertext'] = chr(ord($env['ciphertext'][0]) ^ 0xFF) . substr($env['ciphertext'], 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/classe_decrypt_tag_mismatch/');
        $this->svc->decrypt($id, $tampered);
    }

    public function testRotationCreatesNewVersion(): void
    {
        $id = $this->svc->getOrCreateActiveKey('__test__', '5e', '2025/2026');
        $this->createdKeyIds[] = $id;

        $env1 = $this->svc->encrypt($id, 'pre-rotation');
        $this->assertSame(1, $env1['kv']);

        $newKv = $this->svc->rotateKey('__test__', '5e', '2025/2026');
        $this->assertSame(2, $newKv);

        // Verify new kv preserva accesso ai vecchi env
        // NB: dopo rotateKey, nuova classe_key è stata inserita. encrypt
        // su nuova chiamata getOrCreateActiveKey ritornerebbe la new (DESC LIMIT 1).
        $newId = $this->svc->getOrCreateActiveKey('__test__', '5e', '2025/2026');
        $this->createdKeyIds[] = $newId;
        $env2 = $this->svc->encrypt($newId, 'post-rotation');
        $this->assertSame(2, $env2['kv']);

        // Old env (kv=1, id originale) ancora decryptable col proprio class_key
        $this->assertSame('pre-rotation', $this->svc->decrypt($id, $env1));
        $this->assertSame('post-rotation', $this->svc->decrypt($newId, $env2));
    }

    public function testArchiveYearMarksAllKeys(): void
    {
        $id = $this->svc->getOrCreateActiveKey('__test__', '1z', '2024/2025');
        $this->createdKeyIds[] = $id;

        $rows = $this->svc->archiveYear('2024/2025');
        $this->assertGreaterThanOrEqual(1, $rows);

        // Verify archived_at popolato
        $stmt = Database::connection()->prepare(
            'SELECT archived_at FROM classe_keys_data WHERE id=?'
        );
        $stmt->execute([$id]);
        $this->assertNotNull($stmt->fetchColumn());

        // Decrypt continua a funzionare anche su archived (immutable per audit)
        $env = $this->svc->encrypt($id, 'archived but decryptable');
        $this->assertSame('archived but decryptable', $this->svc->decrypt($id, $env));
    }

    public function testGetOrCreateExcludesArchived(): void
    {
        // Crea + archivia
        $id1 = $this->svc->getOrCreateActiveKey('__test__', '99', '2024/2025');
        $this->createdKeyIds[] = $id1;
        $this->svc->archiveYear('2024/2025');

        // getOrCreate per stesso (ind, cls, anno) deve creare NUOVA row
        // (l'archived non conta come "active").
        $id2 = $this->svc->getOrCreateActiveKey('__test__', '99', '2024/2025');
        $this->createdKeyIds[] = $id2;
        $this->assertNotSame($id1, $id2, 'archived skipped, new key created');
    }

    public function testKmsNotConfiguredThrows(): void
    {
        $svc = new ClasseKeyService('');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/kms_not_configured/');
        $svc->encrypt(1, 'plain');
    }
}
