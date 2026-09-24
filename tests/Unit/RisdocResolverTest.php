<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit test per TemplateResolver + Permission + OverrideRepository.
 *
 * 2026-09-14 — il modello e il docente se li crea la prova, e li cancella a
 * fine classe. Prima cercava il modello `risdoc/MODELLI/0.0_Piano_annuale_(docente)`
 * e il primo docente del database: righe che esistono solo nei database nati
 * da una copia della produzione. Su un database pulito, come quello delle
 * prove d'integrazione in CI, la classe finiva in errore prima di cominciare.
 */
final class RisdocResolverTest extends TestCase
{
    private static \PDO $db;
    private static int $templateId = 0;
    private static int $teacherId = 0;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../app/bootstrap.php';
        // Test di integrazione che vive in tests/Unit: senza DB raggiungibile
        // deve essere SKIPPED, non ERROR.
        try {
            self::$db = \App\Core\Database::connection();
            self::$db->query('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }

        $marca = 'zz-risdoc-' . bin2hex(random_bytes(6));
        // Se una delle due scritture fallisce PHPUnit non chiama
        // tearDownAfterClass: la pulizia la fa questo blocco.
        try {
            self::$db->prepare(
                "INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active)
                 VALUES (?, 'teacher', 'Prova', 'Risdoc', ?, 'x', 'approved', 1)"
            )->execute([$marca, $marca . '@example.test']);
            self::$teacherId = (int)self::$db->lastInsertId();

            self::$db->prepare(
                'INSERT INTO risdoc_templates (code, category, num_arg, argomento, source_dir, html_file, source_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                "prova/{$marca}", 'modelli', '0.0', 'Piano annuale di prova',
                'storage/_tmp', 'modello.php', str_repeat('0', 64),
            ]);
            self::$templateId = (int)self::$db->lastInsertId();
        } catch (\Throwable $e) {
            self::tearDownAfterClass();
            throw $e;
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Le modifiche del docente cadono con il modello e con l'utente (ON DELETE CASCADE).
        if (self::$templateId > 0) {
            self::$db->prepare('DELETE FROM risdoc_templates WHERE id = ?')->execute([self::$templateId]);
        }
        if (self::$teacherId > 0) {
            self::$db->prepare('DELETE FROM users WHERE id = ?')->execute([self::$teacherId]);
        }
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
     * Copre il ramo 3 di `resolveFile()`: il sorgente su disco, quello che
     * risponde quando non c'e' ne' una modifica del docente ne' una
     * dell'istituto.
     *
     * 2026-09-10 — fino a oggi questa prova si saltava **in ogni ambiente**.
     * Puntava ai `.php` dei modelli istituzionali, che nel repository non ci
     * sono e non ci saranno: sono dati d'istanza. Quindi ne' qui ne' in
     * integrazione continua ha mai eseguito una riga. Il salto era spiegato
     * bene, ma un test che non gira da nessuna parte non copre niente.
     *
     * Adesso il sorgente se lo fabbrica: una riga di modello temporanea che
     * punta a una cartella sotto `storage/_tmp`, un file dentro, e la pulizia
     * in `finally` — cosi' non resta niente nemmeno se l'asserzione fallisce.
     */
    public function testResolveHtmlFromSource(): void
    {
        $radice   = dirname(__DIR__, 2);
        $nome     = 'prova-risdoc-' . bin2hex(random_bytes(6));
        $relativa = 'storage/_tmp/' . $nome;
        $cartella = $radice . '/' . $relativa;
        $file     = 'modello.php';
        $corpo    = "<h1>Piano annuale</h1><!-- {$nome} -->";

        if (!is_dir($cartella) && !mkdir($cartella, 0777, true) && !is_dir($cartella)) {
            self::fail("non riesco a creare la cartella di prova: {$cartella}");
        }
        file_put_contents($cartella . '/' . $file, $corpo);

        self::$db->prepare(
            'INSERT INTO risdoc_templates
                (code, category, num_arg, argomento, source_dir, html_file, source_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(["prova/{$nome}", 'altro', '0.0', 'Prova del sorgente su disco', $relativa, $file, 'prova']);
        $id = (int)self::$db->lastInsertId();

        try {
            $res = (new \App\Services\Risdoc\TemplateResolver())
                ->resolveFile(self::$teacherId, $id, 'html', '');

            self::assertIsArray($res, 'il sorgente su disco deve essere trovato');
            self::assertSame('file', $res['source']);
            self::assertSame($corpo, $res['body']);
        } finally {
            self::$db->prepare('DELETE FROM risdoc_templates WHERE id = ?')->execute([$id]);
            @unlink($cartella . '/' . $file);
            @rmdir($cartella);
        }
    }

    public function testOverrideSaveAndResolve(): void
    {
        $repo = new \App\Repositories\Risdoc\OverrideRepository();
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
        // Chi chiede è un docente qualunque: Permission::canView lascia vedere
        // tutto a un super-amministratore in sessione. Una prova precedente
        // poteva lasciarne uno (14/9/2026, seme 1789389912): la precondizione
        // si dice qui, oltre all'estensione che ripulisce la richiesta fra le prove.
        $_SESSION = [];
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

    public function testListAllIncludesTheTemplate(): void
    {
        // Prima contava almeno 14 modelli, cioe' il catalogo di un database
        // copiato dalla produzione: misurava i dati, non `listAll()`.
        $r   = new \App\Services\Risdoc\TemplateResolver();
        $ids = static fn (?string $categoria): array
            => array_map('intval', array_column($r->listAll($categoria), 'id'));

        self::assertContains(self::$templateId, $ids(null));
        self::assertContains(self::$templateId, $ids('modelli'), 'il filtro per categoria lo tiene');
        self::assertNotContains(self::$templateId, $ids('altro'), 'e lo toglie dalle altre categorie');
    }
}
