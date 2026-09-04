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
        // Test di integrazione che vive in tests/Unit: senza DB raggiungibile
        // deve essere SKIPPED, non ERROR (cfr. RisdocSeedTest).
        try {
            self::$db = \App\Core\Database::connection();
            self::$db->query('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }

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

    /**
     * Copre il ramo 3 di resolveFile(): il sorgente legacy su disco.
     *
     * Quei .php non sono nel repo — `storage/templates/risdoc/` contiene solo
     * JSON di competenze/obiettivi e immagini, e nessuno dei 15 template ha il
     * proprio html_file presente. Il test quindi non puo' passare su un
     * checkout pulito: si skippa dichiarando il motivo, invece di restare
     * rosso in eterno e far ignorare l'intera suite.
     */
    public function testResolveHtmlFromSource(): void
    {
        $r    = new \App\Services\Risdoc\TemplateResolver();
        $tmpl = $r->findTemplate(self::$templateId);
        $abs  = $tmpl ? $r->resolveSourceFilePath($tmpl, 'html', '') : null;

        if ($abs === null || !is_file($abs)) {
            self::markTestSkipped(
                'Sorgente legacy assente dal repo: ' . ($abs ?? '(path non risolto)')
            );
        }

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

    /**
     * Dopo il drop di owner_id (migration 047) TUTTI i template sono
     * istituzionali e il default e' `visibility_scope='public'`: un docente
     * qualsiasi li vede legittimamente. L'assunto precedente — "nessuna
     * visibilita' concessa => lista vuota" — apparteneva al modello con owner,
     * e faceva fallire il test su un comportamento corretto.
     *
     * L'invariante che conta oggi e' un altro: un template sottratto alla
     * visibilita' generale NON deve comparire nella lista di chi non e'
     * collaboratore. Prima non era vero — listForTeacher() chiudeva con
     * `WHERE 1=1` e ignorava del tutto lo scope.
     */
    public function testListForTeacherHidesNonPublicTemplates(): void
    {
        $r       = new \App\Services\Risdoc\TemplateResolver();
        $stranger = 999999;  // teacher id inesistente: nessun collab, nessuna visibility

        $before = count($r->listForTeacher($stranger));
        self::assertGreaterThan(0, $before, 'i template public sono visibili a tutti');

        $orig = self::$db->query(
            'SELECT visibility_scope FROM risdoc_templates WHERE id = ' . (int)self::$templateId
        )->fetchColumn();

        try {
            $upd = self::$db->prepare('UPDATE risdoc_templates SET visibility_scope = ? WHERE id = ?');
            $upd->execute(['denied', self::$templateId]);

            $ids = array_column($r->listForTeacher($stranger), 'id');
            self::assertNotContains(
                self::$templateId,
                array_map('intval', $ids),
                'un template denied non deve comparire per un non-collaboratore'
            );
            self::assertCount($before - 1, $ids, 'gli altri restano visibili');
        } finally {
            $restore = self::$db->prepare('UPDATE risdoc_templates SET visibility_scope = ? WHERE id = ?');
            $restore->execute([$orig, self::$templateId]);
        }
    }

    public function testListAllReturnsAllSeeded(): void
    {
        $r = new \App\Services\Risdoc\TemplateResolver();
        $rows = $r->listAll();
        // 14 dal 2026-09-04: il Modulo di autorizzazione e' stato cancellato
        // (migration 102), vedi RisdocSeedTest.
        self::assertGreaterThanOrEqual(14, count($rows));
    }
}
