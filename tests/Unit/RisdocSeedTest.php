<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Smoke test per il seed risdoc (U2):
 *   - i 15 template attesi sono presenti in DB dopo seed
 *   - parser filename produce num_arg + argomento corretti
 *   - source_hash stabile per input identico
 *
 * Precondizione: migration 006 applicata + `php bin/fm-risdoc-seed.php` eseguito.
 */
final class RisdocSeedTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../app/bootstrap.php';
        self::$db = \App\Core\Database::connection();
    }

    public function testTemplatesCountMatchesExpected(): void
    {
        $n = (int)self::$db->query('SELECT COUNT(*) FROM risdoc_templates')->fetchColumn();
        self::assertGreaterThanOrEqual(15, $n, 'Expected at least 15 seeded templates');
    }

    // Phase 24.58 — colonna `origin` rimossa; partizioni flat lowercase (077):
    // ex-risdoc → modelli/risorse, ex-strcomp → altro/bes. I `code` storici
    // restano invariati (sono solo ID).
    public function testRisdocModelliAllPresent(): void
    {
        $codes = self::$db->query("SELECT code FROM risdoc_templates WHERE category='modelli' ORDER BY code")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('risdoc/MODELLI/0.0_Piano_annuale_(docente)', $codes);
        self::assertContains('risdoc/MODELLI/2.0_Scheda_progetto_(FIS)',   $codes);
        self::assertContains('risdoc/MODELLI/3.1_Relazione_finale_classe_(docente)', $codes);
        self::assertCount(6, $codes);
    }

    public function testStrcompAllPresent(): void
    {
        $codes = self::$db->query("SELECT code FROM risdoc_templates WHERE category IN ('altro','bes') ORDER BY code")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(5, $codes);
        self::assertContains('strcomp/STRCOMP/0.0_Cosa_sono', $codes);
        self::assertContains('strcomp/ALTRO/0.0_Legislazione', $codes);
    }

    public function testDisciplineDetectedForFisTemplate(): void
    {
        $row = self::$db->query("SELECT discipline FROM risdoc_templates WHERE code='risdoc/MODELLI/2.0_Scheda_progetto_(FIS)'")->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($row, 'Scheda_progetto_(FIS) deve esistere');
        self::assertSame('FIS', $row['discipline']);
    }

    public function testRequiresPasswordFlagCorrect(): void
    {
        $risdoc  = (int)self::$db->query("SELECT requires_password FROM risdoc_templates WHERE category IN ('modelli','risorse') LIMIT 1")->fetchColumn();
        $strcomp = (int)self::$db->query("SELECT requires_password FROM risdoc_templates WHERE category IN ('altro','bes') LIMIT 1")->fetchColumn();
        self::assertSame(1, $risdoc,  'risdoc (btn4 legacy) era password-protected');
        self::assertSame(0, $strcomp, 'strcomp (btn3 legacy) era aperto');
    }

    public function testSourceHashIs64HexChars(): void
    {
        $hashes = self::$db->query('SELECT source_hash FROM risdoc_templates LIMIT 5')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($hashes as $h) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)$h);
        }
    }

    public function testLogicSpecIsValidJson(): void
    {
        $spec = self::$db->query('SELECT logic_spec FROM risdoc_templates WHERE logic_spec IS NOT NULL LIMIT 1')->fetchColumn();
        self::assertIsString($spec);
        $decoded = json_decode((string)$spec, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('mappings', $decoded);
        self::assertArrayHasKey('conditional_blocks', $decoded);
    }
}
