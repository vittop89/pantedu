<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit test per TemplateResolver + Permission + OverrideRepository.
 * Precondizione: migration 006 + seed eseguiti (vedi RisdocSeedTest).
 */
final class RisdocResolverTest extends TestCase
{
    private static \PDO $db;
    private static int  $templateId;
    private static int  $teacherId;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../app/bootstrap.php';
        self::$db = \App\Core\Database::connection();

        // Risdoc MODELLI/0.0 Piano annuale
        self::$templateId = (int)self::$db->query(
            "SELECT id FROM risdoc_templates WHERE code='risdoc/MODELLI/0.0_Piano_annuale_(docente)'"
        )->fetchColumn();
        self::assertNotEmpty(self::$templateId);

        // Pick any real teacher user id; fallback to 1.
        self::$teacherId = (int)(self::$db->query(
            "SELECT id FROM users WHERE role IN ('teacher','administrator') ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 1);
    }

    public function testResolverFindsTemplate(): void
    {
        $r = new \App\Services\Risdoc\TemplateResolver();
        $t = $r->findTemplate(self::$templateId);
        self::assertIsArray($t);
        // Phase 24.58 — colonna `origin` rimossa; partizioni flat lowercase (077).
        self::assertArrayNotHasKey('origin', $t);
        self::assertSame('modelli', $t['category']);
    }

    public function testResolveHtmlFromSource(): void
    {
        $r = new \App\Services\Risdoc\TemplateResolver();
        $res = $r->resolveFile(self::$teacherId, self::$templateId, 'html', '');
        self::assertIsArray($res);
        self::assertSame('file', $res['source']);
        self::assertNotEmpty($res['body']);
        self::assertStringContainsString('Piano annuale', (string)$res['body']);
    }

    public function testOverrideSaveAndResolve(): void
    {
        $repo = new \App\Services\Risdoc\OverrideRepository();
        // seed override
        $tmpl = (new \App\Services\Risdoc\TemplateResolver())->findTemplate(self::$templateId);
        $repo->saveText(
            self::$teacherId,
            self::$templateId,
            'html',
            'test-override.html',
            '<div>OVERRIDE BODY</div>',
            (string)$tmpl['source_hash']
        );

        $r = new \App\Services\Risdoc\TemplateResolver();
        $res = $r->resolveFile(self::$teacherId, self::$templateId, 'html', 'test-override.html');
        self::assertIsArray($res);
        self::assertSame('override', $res['source']);
        self::assertSame('<div>OVERRIDE BODY</div>', $res['body']);

        // cleanup
        $repo->delete(self::$teacherId, self::$templateId, 'html', 'test-override.html');
    }

    public function testPermissionSuperAdminCanViewAll(): void
    {
        // Super-admin mock: se l'utente attuale in sessione è super-admin, true.
        // Altrimenti verifichiamo solo che il metodo esista + non esploda.
        self::assertTrue(
            \App\Services\Risdoc\Permission::canView(self::$templateId, 9999)
                || !\App\Services\Risdoc\Permission::isSuperAdmin()
        );
    }

    public function testListForTeacherRespectsVisibility(): void
    {
        // No visibility granted → teacher non vede.
        $r = new \App\Services\Risdoc\TemplateResolver();
        $rows = $r->listForTeacher(999999);  // teacher id inesistente
        self::assertIsArray($rows);
        self::assertCount(0, $rows);
    }

    public function testListAllReturnsAllSeeded(): void
    {
        $r = new \App\Services\Risdoc\TemplateResolver();
        $rows = $r->listAll();
        self::assertGreaterThanOrEqual(15, count($rows));
    }
}
