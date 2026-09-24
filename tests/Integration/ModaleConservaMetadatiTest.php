<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\TeacherContentController;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\TeacherContentRepository;
use App\Services\Maps\PuliziaMetadatiMappe;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il modale ✎ non cancella e non inventa metadati (19/9/2026, analisi
 * C-export-bodypt e F-metadati-modifica-mappa), e la barra sa quando c'è
 * qualcosa da scaricare.
 *
 * Il server, prima:
 *   - sostituiva i metadati per intero con quelli che il modale ricostruiva:
 *     una mappa perdeva `mappa.href_hide` e `mappa.drawio_id`, un esercizio
 *     `contract_key`;
 *   - accettava in una mappa `layout` e `body_pt`, cioè il seme d'esempio
 *     che faceva comparire il 📥;
 *   - non diceva `has_body_pt` in /api/teacher/content, da cui leggono il
 *     pannello Verifiche per categoria e le sidepage Risorse docente e BES/DSA.
 *
 * Adesso il modale manda `metadata_patch` (le sole chiavi cambiate) e il
 * repository la fonde; una mappa non accetta layout, body_pt né doc_roles da
 * nessuna delle due strade; `has_body_pt` è una regola sola
 * (App\Support\RigheDellaBarra).
 *
 * Il controller si chiama direttamente con la sessione costruita a mano, come
 * in SezioneSuIstitutoAttivoTest. Tutto in una transazione annullata alla fine.
 */
final class ModaleConservaMetadatiTest extends TestCase
{
    private PDO $pdo;
    private int $docente = 0;
    private int $istituto = 0;
    private bool $inTx = false;
    /** Il registro delle anomalie del test: una cartella sua, non quella vera. */
    private string $cartellaLog = '';
    private mixed $logDiPrima = null;

    private const MAPPA_CON_LINK = [
        'mappa' => [
            'href'      => 'https://example.org/mappa-originale',
            'href_hide' => 'https://example.org/nascosto',
            'drawio_id' => 'abc123',
            'display'   => 'hide',
        ],
    ];

    protected function setUp(): void
    {
        $basePath = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$basePath/$f")) {
                \Dotenv\Dotenv::createMutable($basePath, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($basePath . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT metadata_json FROM teacher_content_data LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;
        $this->logDiPrima = \App\Core\Config::get('app.paths.logs');
        $this->cartellaLog = sys_get_temp_dir() . '/pantedu-modale-' . bin2hex(random_bytes(6));
        mkdir($this->cartellaLog, 0775, true);
        \App\Core\Config::set('app.paths.logs', $this->cartellaLog);
        \App\Support\CurriculumLookup::resetCache();

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZMOD01', 'SCUOLA DEL MODALE', 'Comune Esempio']);
        $this->istituto = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, active, shared_with_pool, origine)
             VALUES ("materie", ?, "ZMO", "Materia", 1, 0, "istituto")'
        )->execute([$this->istituto]);
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash,
                                status, active, created_at)
             VALUES (?, "teacher", "Zz", "Modale", ?, "x", "approved", 1, NOW())'
        )->execute(['zzmodale', 'zzmodale@example.invalid']);
        $this->docente = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$this->docente, $this->istituto]);

        $_SESSION = [
            'autenticato'          => true,
            'username'             => 'zzmodale',
            'user_id'              => $this->docente,
            'user_role'            => 'teacher',
            'current_institute_id' => $this->istituto,
        ];
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        \App\Core\Config::set('crypto.dual_write', false);
        \App\Core\Config::set('crypto.read_from', 'plaintext');
        if ($this->cartellaLog !== '') {
            foreach (glob($this->cartellaLog . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->cartellaLog);
            \App\Core\Config::set('app.paths.logs', $this->logDiPrima);
        }
        \App\Support\CurriculumLookup::resetCache();
    }

    /**
     * Le anomalie registrate durante il test.
     *
     * @return list<array<string,mixed>>
     */
    private function anomalie(): array
    {
        $file = \App\Support\Anomalia::percorso();
        if (!is_file($file)) {
            return [];
        }
        $righe = [];
        foreach (explode("\n", trim((string)file_get_contents($file))) as $riga) {
            if ($riga !== '') {
                $righe[] = (array)json_decode($riga, true);
            }
        }
        return $righe;
    }

    /** Un contenuto con i metadati scritti così come sono, senza passare dalle regole. */
    private function contenuto(string $tipo, string $metadatiJson): int
    {
        $id = (new TeacherContentRepository())->create([
            'teacher_id'   => $this->docente,
            'content_type' => $tipo,
            'subject_code' => 'ZMO',
            'topic'        => '9.9',
            'title'        => "zz-modale-$tipo-" . uniqid(),
            'visibility'   => 'draft',
        ]);
        $this->pdo->prepare('UPDATE teacher_content_data SET metadata_json = ? WHERE id = ?')
            ->execute([$metadatiJson, $id]);
        return $id;
    }

    private function metadatiGrezzi(int $id): ?string
    {
        $st = $this->pdo->prepare('SELECT metadata_json FROM teacher_content_data WHERE id = ?');
        $st->execute([$id]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (string)$v;
    }

    /** @return array<string,mixed> */
    private function metadati(int $id): array
    {
        $grezzi = $this->metadatiGrezzi($id);
        return $grezzi === null ? [] : (array)json_decode($grezzi, true);
    }

    /**
     * @param array<string,string> $campi
     * @return array{0:int,1:array<string,mixed>}
     */
    private function aggiorna(int $id, array $campi): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI']    = "/api/teacher/content/$id/update";
        $_POST = $campi;
        $res = (new TeacherContentController())->update(new Request(), ['id' => (string)$id]);
        return [$res->status, (array)json_decode($res->body, true)];
    }

    /** @return list<array<string,mixed>> */
    private function elenco(bool $conMetadati): array
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/api/teacher/content';
        $_POST = [];
        $_GET = $conMetadati ? ['with_metadata' => '1', 'limit' => '500'] : ['limit' => '500'];
        $res = (new TeacherContentController())->index(new Request());
        $body = json_decode($res->body, true);
        $this->assertIsArray($body);
        $this->assertTrue($body['ok'] ?? false, 'elenco: ' . $res->body);
        return $body['rows'];
    }

    // ─────── la patch ───────

    #[Test]
    public function la_patch_cambia_solo_le_sue_chiavi_e_conserva_il_resto_della_mappa(): void
    {
        $id = $this->contenuto('mappa', '{"mappa":{"href":"https://example.org/mappa-originale","href_hide":"https://example.org/nascosto","drawio_id":"abc123","display":"hide"},"stats":{}}');
        $nuovaMappa = self::MAPPA_CON_LINK['mappa'];
        $nuovaMappa['href'] = 'https://example.org/mappa-nuova';

        [$stato, $corpo] = $this->aggiorna($id, [
            'title'          => 'zz-modale-titolo-nuovo-' . uniqid(),
            'metadata_patch' => (string)json_encode(['mappa' => $nuovaMappa]),
        ]);

        $this->assertSame(200, $stato, (string)json_encode($corpo));
        $this->assertSame(['mappa' => $nuovaMappa, 'stats' => []], $this->metadati($id));
        $this->assertStringContainsString('"stats":{}', (string)$this->metadatiGrezzi($id), 'un {} resta {}, non diventa []');
    }

    #[Test]
    public function null_nella_patch_toglie_la_chiave_e_il_corpo_resta(): void
    {
        $corpo = [['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => 'Testo vero', 'marks' => []]]]];
        $id = $this->contenuto('document', (string)json_encode(['layout' => 'custom', 'body_pt' => $corpo, 'doc_roles' => ['D']]));

        [$stato] = $this->aggiorna($id, ['metadata_patch' => '{"doc_roles":null}']);

        $this->assertSame(200, $stato);
        $this->assertSame(['layout' => 'custom', 'body_pt' => $corpo], $this->metadati($id));
    }

    #[Test]
    public function il_contratto_di_un_esercizio_resta_quando_cambia_un_altra_chiave(): void
    {
        $chiave = 'institutes/1/private/2/esercizi/ZMO/9_9.contract.json';
        $id = $this->contenuto('esercizio', (string)json_encode([
            'contract_key' => $chiave, 'layout' => 'exercises', 'body_pt' => PuliziaMetadatiMappe::SEME,
        ]));

        [$stato] = $this->aggiorna($id, ['metadata_patch' => '{"doc_roles":["C"]}']);

        $this->assertSame(200, $stato);
        $meta = $this->metadati($id);
        $this->assertSame($chiave, $meta['contract_key'] ?? null, 'prima: la sostituzione integrale toglieva contract_key');
        $this->assertSame(['C'], $meta['doc_roles'] ?? null, 'controprova: in un esercizio doc_roles si scrive');
        $this->assertSame('exercises', $meta['layout'] ?? null);
    }

    #[Test]
    public function una_mappa_ignora_layout_body_pt_e_doc_roles_nella_patch(): void
    {
        $id = $this->contenuto('mappa', (string)json_encode(self::MAPPA_CON_LINK));

        [$stato] = $this->aggiorna($id, ['metadata_patch' => (string)json_encode([
            'layout' => 'exercises', 'body_pt' => PuliziaMetadatiMappe::SEME, 'doc_roles' => ['D'],
        ])]);

        $this->assertSame(200, $stato);
        $this->assertSame(self::MAPPA_CON_LINK, $this->metadati($id));
    }

    /**
     * 20/9/2026, revisione della PR #145 — con il dual-write acceso una patch
     * che tocca il corpo passava dalla strada dei metadati interi
     * (`json_decode($fusi, true)`), e lì ogni `{}` annidato diventava `[]`:
     * la garanzia che `MetadatiDelContenuto` dichiara valeva su una strada
     * sola. Adesso il corpo si sfila dall'oggetto, senza passare da un array.
     */
    #[Test]
    public function con_il_corpo_cifrato_la_patch_conserva_gli_oggetti_vuoti_e_il_resto(): void
    {
        $id = $this->contenuto('document', '{"layout":"custom","opzioni":{},"stats":{"has_tikz":false},"body_pt":[]}');
        $corpo = [['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => 'Testo vero', 'marks' => []]]]];
        \App\Core\Config::set('crypto.dual_write', true);

        [$stato] = $this->aggiorna($id, ['metadata_patch' => (string)json_encode(['body_pt' => $corpo])]);

        $this->assertSame(200, $stato);
        $grezzi = (string)$this->metadatiGrezzi($id);
        $this->assertStringContainsString('"opzioni":{}', $grezzi, 'prima: un {} diventava [] su questa strada');
        $this->assertSame(
            ['layout' => 'custom', 'opzioni' => [], 'stats' => ['has_tikz' => false]],
            $this->metadati($id),
            'il corpo esce dai metadati in chiaro, il resto resta'
        );

        $st = $this->pdo->prepare('SELECT body_pt_ct, body_pt_kv FROM teacher_content_data WHERE id = ?');
        $st->execute([$id]);
        $riga = (array)$st->fetch(PDO::FETCH_ASSOC);
        $this->assertNotNull($riga['body_pt_ct'], 'il corpo è finito nelle colonne cifrate');

        \App\Core\Config::set('crypto.read_from', 'ciphertext');
        $letta = (new TeacherContentRepository())->find($id);
        $this->assertSame($corpo, $letta['metadata']['body_pt'] ?? null, 'e si rilegge intero');
    }

    // ─────── i metadati interi ───────

    #[Test]
    public function una_mappa_non_riceve_layout_ne_body_pt_neanche_dai_metadati_interi(): void
    {
        $id = $this->contenuto('mappa', (string)json_encode(self::MAPPA_CON_LINK));

        [$stato] = $this->aggiorna($id, ['metadata' => (string)json_encode(self::MAPPA_CON_LINK + [
            'layout' => 'exercises', 'body_pt' => PuliziaMetadatiMappe::SEME, 'doc_roles' => ['D'],
        ])]);

        $this->assertSame(200, $stato);
        $this->assertSame(self::MAPPA_CON_LINK, $this->metadati($id));
    }

    #[Test]
    public function un_esercizio_riceve_layout_e_body_pt_dai_metadati_interi(): void
    {
        $id = $this->contenuto('esercizio', '{"contract_key":"x.contract.json"}');
        $interi = ['contract_key' => 'x.contract.json', 'layout' => 'exercises', 'body_pt' => PuliziaMetadatiMappe::SEME];

        [$stato] = $this->aggiorna($id, ['metadata' => (string)json_encode($interi)]);

        $this->assertSame(200, $stato);
        $this->assertSame($interi, $this->metadati($id), 'controprova: la difesa vale solo per le mappe');
    }

    /**
     * 20/9/2026, revisione della PR #145 — togliere a una mappa le chiavi che
     * non le appartengono è la regola; farlo in silenzio no. Lo strumento di
     * pulizia risparmia apposta le mappe il cui `body_pt` non è il seme del
     * modale, perché lì potrebbe esserci del testo scritto da qualcuno: sulla
     * strada dei metadati interi lo stesso corpo spariva senza lasciare
     * traccia. Adesso lascia una riga nel registro delle anomalie.
     */
    #[Test]
    public function un_corpo_scritto_a_mano_tolto_a_una_mappa_si_registra(): void
    {
        $vero = [['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => 'Testo vero', 'marks' => []]]]];
        $id = $this->contenuto('mappa', (string)json_encode(self::MAPPA_CON_LINK));

        [$stato] = $this->aggiorna($id, ['metadata' => (string)json_encode(self::MAPPA_CON_LINK + ['body_pt' => $vero])]);

        $this->assertSame(200, $stato);
        $this->assertSame(self::MAPPA_CON_LINK, $this->metadati($id), 'il corpo non entra: la regola resta');
        $righe = $this->anomalie();
        $this->assertCount(1, $righe, 'prima: nessun errore, nessuna riga di registro');
        $this->assertSame('metadati_corpo_tolto_al_tipo', $righe[0]['codice'] ?? null);
        $this->assertSame(
            ['tipo' => 'mappa', 'contenuto' => $id, 'blocchi' => 1],
            $righe[0]['dettagli'] ?? null,
            'si registra quanto era grande, non che cosa diceva'
        );
    }

    #[Test]
    public function il_seme_del_modale_tolto_a_una_mappa_non_si_registra(): void
    {
        $id = $this->contenuto('mappa', (string)json_encode(self::MAPPA_CON_LINK));

        [$stato] = $this->aggiorna($id, ['metadata' => (string)json_encode(self::MAPPA_CON_LINK + [
            'layout' => 'exercises', 'body_pt' => PuliziaMetadatiMappe::SEME, 'doc_roles' => ['D'],
        ])]);

        $this->assertSame(200, $stato);
        $this->assertSame(self::MAPPA_CON_LINK, $this->metadati($id));
        $this->assertSame(
            [],
            $this->anomalie(),
            'controprova: il seme è il difetto che si sta togliendo, non una notizia — un registro che non è mai pulito non si apre più'
        );
    }

    #[Test]
    public function patch_e_metadati_interi_insieme_o_una_patch_che_non_e_un_oggetto_si_rifiutano(): void
    {
        $id = $this->contenuto('document', '{"layout":"custom"}');

        [$insieme, $c1] = $this->aggiorna($id, ['metadata' => '{}', 'metadata_patch' => '{}']);
        [$lista, $c2] = $this->aggiorna($id, ['metadata_patch' => '[1,2]']);
        [$testo, $c3] = $this->aggiorna($id, ['metadata_patch' => 'non json']);

        $this->assertSame([400, 'metadata_e_patch_insieme'], [$insieme, $c1['error'] ?? null]);
        $this->assertSame([400, 'metadata_patch_non_valida'], [$lista, $c2['error'] ?? null]);
        $this->assertSame([400, 'metadata_patch_non_valida'], [$testo, $c3['error'] ?? null]);
        $this->assertSame(['layout' => 'custom'], $this->metadati($id), 'niente è stato scritto');
    }

    // ─────── il 📥 ───────

    #[Test]
    public function l_elenco_con_i_metadati_dice_quando_c_e_un_corpo_da_scaricare(): void
    {
        $vero = [['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => 'Testo vero', 'marks' => []]]]];
        $documento = $this->contenuto('document', (string)json_encode(['layout' => 'custom', 'body_pt' => $vero, 'doc_roles' => ['C', 'D']]));
        $esercizio = $this->contenuto('esercizio', (string)json_encode(['layout' => 'exercises', 'body_pt' => PuliziaMetadatiMappe::SEME]));
        $mappaSeme = $this->contenuto('mappa', (string)json_encode(['layout' => 'exercises', 'body_pt' => PuliziaMetadatiMappe::SEME]));
        $mappaCorpo = $this->contenuto('mappa', (string)json_encode(['body_pt' => $vero]));

        $perId = [];
        foreach ($this->elenco(true) as $riga) {
            $perId[(int)$riga['id']] = $riga;
        }
        foreach ([$documento, $esercizio, $mappaSeme, $mappaCorpo] as $id) {
            $this->assertArrayHasKey($id, $perId, "il contenuto $id è nell'elenco");
            $this->assertArrayHasKey('has_body_pt', $perId[$id], 'prima: /api/teacher/content non lo diceva');
        }
        $this->assertTrue($perId[$documento]['has_body_pt'], 'un documento con testo si scarica');
        $this->assertSame('DC', $perId[$documento]['doc_roles']);
        $this->assertFalse($perId[$esercizio]['has_body_pt'], 'il seme è un segnaposto: il contenuto sta nel contratto');
        $this->assertFalse($perId[$mappaSeme]['has_body_pt'], 'una mappa con il seme non si scarica');
        $this->assertFalse($perId[$mappaCorpo]['has_body_pt'], 'una mappa non si scarica nemmeno con un corpo');

        $senza = [];
        foreach ($this->elenco(false) as $riga) {
            $senza[(int)$riga['id']] = $riga;
        }
        $this->assertArrayNotHasKey('has_body_pt', $senza[$documento] ?? [], 'senza metadati l\'elenco resta leggero');
    }

    #[Test]
    public function la_risposta_del_salvataggio_dice_il_corpo_e_i_ruoli_della_riga_salvata(): void
    {
        $vero = [['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => 'Testo', 'marks' => []]]]];
        $documento = $this->contenuto('document', (string)json_encode(['layout' => 'custom', 'body_pt' => $vero]));
        $mappa = $this->contenuto('mappa', (string)json_encode(self::MAPPA_CON_LINK));

        [, $d] = $this->aggiorna($documento, ['metadata_patch' => '{"doc_roles":["R"]}']);
        [, $m] = $this->aggiorna($mappa, ['title' => 'zz-modale-m-' . uniqid()]);

        $this->assertSame([true, 'R'], [$d['has_body_pt'] ?? null, $d['doc_roles'] ?? null]);
        $this->assertSame([false, ''], [$m['has_body_pt'] ?? null, $m['doc_roles'] ?? null]);
    }
}
