<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\PoolController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\TeacherContentRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * «Recupera» dal pool: il contratto si copia dalla cartella dei dati, e una
 * copia senza contratto non si spaccia per riuscita.
 *
 * Due difetti misurati il 20 settembre 2026, che si tenevano per mano.
 *
 * 1. `cloneContentContract()` cercava il contratto d'origine sotto
 *    `dirname(__DIR__, 2) . '/storage/objects'`, la radice del repository.
 *    Dall'8 settembre l'applicazione gira in un container e lì dentro quella
 *    radice è l'immagine: il file non c'era, il metodo lanciava
 *    `source_contract_not_found`, e la copia scriveva comunque in uno strato
 *    che il rilascio successivo butta via.
 *
 * 2. Il chiamante catturava l'eccezione, scriveva in `error_log` e rispondeva
 *    `ok:true`. L'utente vedeva «copia riuscita» e si portava a casa una riga
 *    che — avendo ereditato `metadata` dall'originale — puntava al contratto
 *    del PROPRIETARIO. Non era ancora successo (dall'8/9 nessun esercizio e
 *    nessuna verifica creati), ma sarebbe successo al primo tentativo.
 *
 * E c'è una terza cosa, misurata sul database di sviluppo: dei 105 esercizi
 * con un `contract_key`, 88 stanno sotto `eser/` ma 9 sotto `esercizi/`, 6
 * sotto `bes/` e 2 sotto `lab/`. La convenzione con cui il metodo
 * RICOSTRUIVA il percorso non produce quelle cartelle, quindi per 17 su 105
 * non trovava niente. Adesso la chiave dichiarata dall'originale vince sulla
 * convenzione.
 *
 * Fixture isolata in transazione (rollback in tearDown); la cartella dei dati
 * è una cartella temporanea, rimossa a fine prova anche se la prova fallisce.
 */
final class PoolRecuperoContrattoTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private string $dati = '';
    private mixed $datiPrima = null;
    private int $inst = 0;
    private int $owner = 0;
    private int $actor = 0;
    private int $contentId = 0;
    private int $subjectId = 0;

    protected function setUp(): void
    {
        $basePath = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$basePath/$f")) {
                \Dotenv\Dotenv::createMutable($basePath, $f)->safeLoad();
            }
        }
        Config::load($basePath . '/app/Config');

        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM teacher_content LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migrazioni non disponibili: ' . $e->getMessage());
        }

        $this->dati = sys_get_temp_dir() . '/pantedu-pool-contratto-' . bin2hex(random_bytes(6));
        mkdir($this->dati, 0775, true);
        $this->datiPrima = Config::get('app.paths.data_base');
        Config::set('app.paths.data_base', $this->dati);

        $this->pdo->beginTransaction();
        $this->inTx = true;
        $_SESSION = [];

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZPCON01', 'ISTITUTO CONTRATTI POOL', 'Comune Esempio']);
        $this->inst = (int)$this->pdo->lastInsertId();

        $ins = $this->pdo->prepare(
            'INSERT INTO curriculum_entries
                (kind, institute_id, code, label, active, shared_with_pool)
             VALUES (?, ?, ?, ?, 1, 0)'
        );
        foreach ([['materie', 'ZPK'], ['indirizzi', 'ZPJ'], ['classi', '1Y']] as [$kind, $code]) {
            $ins->execute([$kind, $this->inst, $code, $code]);
        }

        $insUser = $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, created_at)
             VALUES (?, "teacher", ?, "Contratti", ?, "x", "approved", 1, NOW())'
        );
        $insPivot = $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)');
        $insUser->execute(['zzpcon_owner', 'Olga', 'zzpcon_owner@example.invalid']);
        $this->owner = (int)$this->pdo->lastInsertId();
        $insPivot->execute([$this->owner, $this->inst]);
        $insUser->execute(['zzpcon_actor', 'Aldo', 'zzpcon_actor@example.invalid']);
        $this->actor = (int)$this->pdo->lastInsertId();
        $insPivot->execute([$this->actor, $this->inst]);
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        Config::set('app.paths.data_base', $this->datiPrima);
        $_SESSION = [];
        $this->rimuovi($this->dati);
    }

    /** Crea l'esercizio del proprietario, condiviso nel pool. */
    private function esercizio(?string $contractKey): void
    {
        $this->contentId = (new TeacherContentRepository())->create([
            'teacher_id'   => $this->owner,
            'content_type' => 'esercizio',
            'subject_code' => 'ZPK',
            'indirizzo'    => 'ZPJ',
            'classe'       => '1Y',
            'topic'        => 'CON_' . uniqid(),
            'title'        => 'Esercizio con contratto',
            'body_html'    => '<p>x</p>',
            'metadata'     => $contractKey === null ? null : ['contract_key' => $contractKey],
            'visibility'   => 'published',
        ]);
        // Un esercizio del docente (`personal`): senza fonte, o dal libro, il
        // collega non lo recupera (SharedContentPolicy::canReadContent, 24/9/2026).
        $this->pdo->prepare('UPDATE teacher_content_data SET shared_with_pool = 1, source_type = "personal" WHERE id = ?')
            ->execute([$this->contentId]);
        $this->subjectId = (int)$this->pdo
            ->query('SELECT subject_id FROM teacher_content WHERE id = ' . $this->contentId)
            ->fetchColumn();
        // Il collega deve avere la materia fra le sue: `ownMateria()`.
        $this->pdo->prepare(
            'INSERT INTO curriculum_teacher (user_id, curriculum_id, shared_with_pool)
             VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE shared_with_pool = shared_with_pool'
        )->execute([$this->actor, $this->subjectId]);
    }

    /** @return array{0:int,1:array<string,mixed>} codice HTTP e corpo */
    private function recupera(): array
    {
        $_SESSION = [
            'autenticato' => true, 'username' => 'zzpcon_actor',
            'user_id' => $this->actor, 'user_role' => 'teacher',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $res = (new PoolController())->recover(
            new Request((string)json_encode(['target_subject_id' => $this->subjectId])),
            ['id' => (string)$this->contentId],
        );
        return [$res->status, json_decode((string)$res->body, true) ?? []];
    }

    private function chiaveDi(int $contentId): string
    {
        $st = $this->pdo->prepare('SELECT metadata_json FROM teacher_content_data WHERE id = ?');
        $st->execute([$contentId]);
        $meta = json_decode((string)$st->fetchColumn(), true);
        return \is_array($meta) ? (string)($meta['contract_key'] ?? '') : '';
    }

    #[Test]
    public function il_contratto_si_copia_dalla_cartella_dei_dati(): void
    {
        $chiave = sprintf('institutes/%d/private/%d/eser/prova.contract.json', $this->inst, $this->owner);
        $this->scrivi(
            $this->dati . '/storage/objects/' . $chiave,
            (string)json_encode(['scope' => ['teacher_id' => $this->owner], 'groups' => []]),
        );
        $this->esercizio($chiave);

        [$stato, $corpo] = $this->recupera();

        self::assertSame(200, $stato, (string)json_encode($corpo));
        self::assertTrue($corpo['ok'] ?? false);
        $nuova = (int)($corpo['new_id'] ?? 0);
        self::assertGreaterThan(0, $nuova);

        $chiaveNuova = $this->chiaveDi($nuova);
        self::assertNotSame('', $chiaveNuova, 'la copia deve avere un contratto suo');
        self::assertNotSame($chiave, $chiaveNuova, 'e non quello del proprietario');
        self::assertFileExists(
            $this->dati . '/storage/objects/' . $chiaveNuova,
            'il file del contratto sta nella cartella dei dati',
        );
    }

    #[Test]
    public function un_contratto_fuori_dalla_cartella_dei_dati_non_si_trova(): void
    {
        // Controprova: è esattamente dove il contratto finiva prima — la
        // radice del repository, che nel container è l'immagine. Se il
        // servizio cercasse anche lì, la prova sopra passerebbe pure
        // sbagliando.
        $chiave = sprintf('institutes/%d/private/%d/eser/altrove.contract.json', $this->inst, $this->owner);
        $altrove = $this->dati . '-altrove';
        $this->scrivi(
            $altrove . '/storage/objects/' . $chiave,
            (string)json_encode(['scope' => [], 'groups' => []]),
        );
        $this->esercizio($chiave);

        try {
            [$stato, $corpo] = $this->recupera();
            self::assertSame(500, $stato);
            self::assertSame('contract_clone_failed', $corpo['error'] ?? null);
        } finally {
            $this->rimuovi($altrove);
        }
    }

    #[Test]
    public function una_copia_senza_contratto_non_si_spaccia_per_riuscita(): void
    {
        // Il guasto peggiore: prima si rispondeva ok:true e la riga nuova
        // restava agganciata al contratto del proprietario.
        $chiave = sprintf('institutes/%d/private/%d/eser/mancante.contract.json', $this->inst, $this->owner);
        $this->esercizio($chiave); // la chiave c'è, il file no

        $prima = (int)$this->pdo->query('SELECT COUNT(*) FROM teacher_content_data')->fetchColumn();
        [$stato, $corpo] = $this->recupera();

        self::assertSame(500, $stato, 'deve dire che è andata male');
        self::assertSame('contract_clone_failed', $corpo['error'] ?? null);
        self::assertSame(
            $prima,
            (int)$this->pdo->query('SELECT COUNT(*) FROM teacher_content_data')->fetchColumn(),
            'e disfare la riga appena creata, come fa il ramo delle mappe',
        );
    }

    #[Test]
    public function senza_contract_key_il_recupero_riesce_come_prima(): void
    {
        // Il verso opposto, e non è un dettaglio: sul database di sviluppo 3
        // esercizi su 108 non dichiarano nessun contratto. Per loro non c'è
        // niente da copiare, e un 500 sarebbe un falso allarme — cioè una
        // regressione introdotta dalla correzione qui sopra.
        $this->esercizio(null);

        [$stato, $corpo] = $this->recupera();

        self::assertSame(200, $stato, (string)json_encode($corpo));
        self::assertTrue($corpo['ok'] ?? false);
        self::assertSame('', $this->chiaveDi((int)($corpo['new_id'] ?? 0)));
    }

    #[Test]
    public function la_chiave_dichiarata_vince_sulla_convenzione(): void
    {
        // 17 esercizi su 105, nel database di sviluppo, stanno sotto
        // `esercizi/`, `bes/` o `lab/`: cartelle che la ricostruzione per
        // convenzione (`eser/{topic}_{materia}-…`) non produce.
        $chiave = sprintf('institutes/%d/private/%d/bes/fuori-convenzione.contract.json', $this->inst, $this->owner);
        $this->scrivi(
            $this->dati . '/storage/objects/' . $chiave,
            (string)json_encode(['scope' => [], 'groups' => []]),
        );
        $this->esercizio($chiave);

        [$stato, $corpo] = $this->recupera();

        self::assertSame(200, $stato, (string)json_encode($corpo));
        $chiaveNuova = $this->chiaveDi((int)($corpo['new_id'] ?? 0));
        // Non basta che la chiave ci sia: col codice di prima c'era lo stesso,
        // perché la riga nuova EREDITA `metadata` dall'originale. Deve essere
        // una chiave diversa, e il file deve esistere davvero.
        self::assertNotSame($chiave, $chiaveNuova, 'non la chiave del proprietario');
        self::assertFileExists($this->dati . '/storage/objects/' . $chiaveNuova);
    }

    private function scrivi(string $percorso, string $contenuto): void
    {
        @mkdir(\dirname($percorso), 0775, true);
        file_put_contents($percorso, $contenuto);
    }

    private function rimuovi(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
