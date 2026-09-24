<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\Contenuti\SpostamentoDiClasse;
use App\Support\PostoPrincipale;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Spostare i materiali di una classe in un'altra, dal sito.
 *
 * Nei due versi: sposta contenuti e verifiche del docente (e il corso, se la
 * classe di arrivo ne ha uno), e si ferma — senza toccare niente — quando la
 * classe di arrivo non e' spuntata o il suo corso non lo e'. I materiali di
 * un collega non si muovono nemmeno se il loro id e' nell'elenco.
 *
 * Con ADR-037: i posti in piu' nella classe di partenza seguono; due posti
 * nella stessa classe diventano uno solo quando non cambia chi vede che cosa
 * (e l'anno «per piu' classi» con il suo bersaglio nella sezione torna un
 * posto solo, pubblicato), altrimenti restano e si contano; la verifica si
 * sposta intera. La principale la scrive l'applicazione (PostoPrincipale):
 * le fixture fanno lo stesso.
 *
 * DB-gated, tutto in transazione → rollback in tearDown.
 */
final class SpostamentoDiClasseTest extends TestCase
{
    private PDO $pdo;
    private SpostamentoDiClasse $svc;
    private int $instId = 0;
    private int $prof = 0;
    private int $collega = 0;
    /** @var array<string,int> codice → id della voce */
    private array $voce = [];
    private bool $inTx = false;

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZSPOST001', 'ISTITUTO SPOSTAMENTI', 'Comune Esempio']);
        $this->instId = (int)$this->pdo->lastInsertId();

        $ins = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, origine)
             VALUES (?, ?, ?, ?, ?, 1, "istituto")'
        );
        foreach ([
            ['indirizzi', 'SCI', 'Scientifico', null],
            ['indirizzi', 'ART', 'Artistico', null],
            ['classi', '2', 'Seconda', 'SCI'],
            ['classi', '2A', 'Seconda A', 'SCI'],
            ['classi', '3B', 'Terza B', 'ART'],
            ['materie', 'MAT', 'Matematica', null],
        ] as [$kind, $code, $label, $ind]) {
            $ins->execute([$kind, $this->instId, $code, $label, $ind]);
            $this->voce[$code] = (int)$this->pdo->lastInsertId();
        }

        $this->prof    = $this->docente('zzspost');
        $this->collega = $this->docente('zzspostcoll');
        // Il docente ha spuntato SCI, le tre classi e MAT, non ART.
        $spunta = $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active) VALUES (?, ?, 1)');
        foreach (['SCI', '2', '2A', '3B', 'MAT'] as $code) {
            $spunta->execute([$this->voce[$code], $this->prof]);
        }
        $spunta->execute([$this->voce['2'], $this->collega]);

        $this->svc = new SpostamentoDiClasse($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function docente(string $username): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "Prof", ?, "x", "approved", 1, NOW())'
        )->execute([$username, $username . '@example.invalid']);
        $uid = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$uid, $this->instId]);
        return $uid;
    }

    private function contenuto(int $teacher, string $classe, ?string $indirizzo, string $title): int
    {
        // ADR-037, fase 4c-2: il posto è la principale, scritta come la scrive
        // l'applicazione.
        $this->pdo->prepare('INSERT INTO teacher_content_data (teacher_id, content_subtype, title) VALUES (?, "esercizio", ?)')
            ->execute([$teacher, $title]);
        $id = (int)$this->pdo->lastInsertId();
        PostoPrincipale::contenuto($this->pdo, $id, $indirizzo !== null ? $this->voce[$indirizzo] : null, $this->voce[$classe], $this->voce['MAT']);
        return $id;
    }

    private function verifica(int $teacher, string $classe, string $title): int
    {
        $this->pdo->prepare('INSERT INTO verifica_documents_data (teacher_id, title) VALUES (?, ?)')
            ->execute([$teacher, $title]);
        $id = (int)$this->pdo->lastInsertId();
        PostoPrincipale::verifica($this->pdo, $id, null, $this->voce[$classe], $this->voce['MAT']);
        return $id;
    }

    /** @return array{classe:?int,indirizzo:?int} */
    private function dove(string $tabella, int $id): array
    {
        // Il posto si legge dalla vista, che lo prende dalla principale.
        $vista = $tabella === 'verifica_documents_data' ? 'verifica_documents' : 'teacher_content';
        $st = $this->pdo->prepare("SELECT classe_id, indirizzo_id FROM `$vista` WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return ['classe' => $r['classe_id'] !== null ? (int)$r['classe_id'] : null,
                'indirizzo' => $r['indirizzo_id'] !== null ? (int)$r['indirizzo_id'] : null];
    }

    #[Test]
    public function l_elenco_ha_i_materiali_del_docente_in_quella_classe_e_basta(): void
    {
        $mio   = $this->contenuto($this->prof, '2', null, 'Equazioni');
        $altro = $this->contenuto($this->prof, '2A', 'SCI', 'Disequazioni');
        $suo   = $this->contenuto($this->collega, '2', null, 'Del collega');
        $ver   = $this->verifica($this->prof, '2', 'Verifica di febbraio');

        $e = $this->svc->elenco($this->prof, $this->voce['2']);
        $this->assertSame([$mio], array_column($e['contenuti'], 'id'), 'solo i miei, solo di quella classe');
        $this->assertSame([$ver], array_column($e['verifiche'], 'id'));
        $this->assertSame('MAT', $e['contenuti'][0]['materia']);
        $this->assertNotContains($altro, array_column($e['contenuti'], 'id'));
        $this->assertNotContains($suo, array_column($e['contenuti'], 'id'));

        $classi = array_column($this->svc->classiDelDocente($this->prof), 'code');
        $this->assertSame(['2', '2A', '3B'], $classi, 'le classi fra cui scegliere sono quelle spuntate');
    }

    /**
     * 15/9/2026, segnalato dall'utente: in produzione le sue principali stanno
     * tutte sugli anni e 187 posti in più di «Dove vale» sulle sezioni. Scelta
     * la 2A, che è una sua classe con incarico, la pagina diceva «Nessun
     * materiale»: i posti in più si elencavano solo da una sezione senza incarico.
     */
    #[Test]
    public function da_una_classe_spuntata_si_vedono_e_si_spostano_anche_i_posti_in_piu(): void
    {
        $a = $this->contenuto($this->prof, '2', null, 'Equazioni');
        $this->pdo->prepare('UPDATE teacher_content_data SET visibility = "published" WHERE id = ?')->execute([$a]);
        PostoPrincipale::statoDelContenuto($this->pdo, $a);
        $this->posto('teacher_content_id', $a, 'SCI', '2A', 'published');
        $ver = $this->verifica($this->prof, '2', 'Verifica di maggio');
        $this->posto('verifica_document_id', $ver, 'SCI', '2A', 'published');
        // Controprova: il posto in più di un collega in 2A, e un mio posto in più in 3B.
        $suo = $this->contenuto($this->collega, '2', null, 'Del collega');
        $this->posto('teacher_content_id', $suo, 'SCI', '2A', 'published');
        $altrove = $this->contenuto($this->prof, '2', null, 'Sistemi');
        $this->posto('teacher_content_id', $altrove, 'ART', '3B', 'published');

        $e = $this->svc->elenco($this->prof, $this->voce['2A']);
        $this->assertSame([$a], array_column($e['contenuti'], 'id'), 'il mio posto in più in 2A, non quello del collega né quello in 3B');
        $this->assertSame('2', $e['contenuti'][0]['principale_in']);
        $this->assertSame([$ver], array_column($e['verifiche'], 'id'));
        $this->assertSame('2', $e['verifiche'][0]['principale_in']);

        $esito = $this->svc->sposta($this->prof, [$a], [$ver], $this->voce['2A'], $this->voce['2']);

        $this->assertSame(1, $esito['contenuti']);
        $this->assertSame(1, $esito['verifiche']);
        $voce2A = $this->voce['2A'];
        $in2A = static fn(array $posti): array => array_values(array_filter($posti, static fn(array $p): bool => $p['classe'] === $voce2A));
        $this->assertSame([], $in2A($this->posti('teacher_content_id', $a)), 'in 2A non resta il posto in più del contenuto');
        $this->assertSame([], $in2A($this->posti('verifica_document_id', $ver)), 'né quello della verifica');
        $this->assertSame($this->voce['2'], $this->dove('teacher_content_data', $a)['classe'], 'la principale resta sull\'anno');
        $this->assertCount(1, $in2A($this->posti('teacher_content_id', $suo)), 'il posto del collega non si tocca');
    }

    #[Test]
    public function sposta_contenuti_e_verifiche_e_il_corso_segue_la_sezione(): void
    {
        $a   = $this->contenuto($this->prof, '2', null, 'Equazioni');
        $b   = $this->contenuto($this->prof, '2', null, 'Sistemi');
        $suo = $this->contenuto($this->collega, '2', null, 'Del collega');
        $ver = $this->verifica($this->prof, '2', 'Verifica');

        $esito = $this->svc->sposta($this->prof, [$a, $b, $suo, 'x', 0], [$ver], $this->voce['2'], $this->voce['2A']);

        $this->assertSame(2, $esito['contenuti'], 'due miei: quello del collega e gli id finti non contano');
        $this->assertSame(1, $esito['verifiche']);
        $this->assertSame('2A', $esito['classe']);
        $this->assertSame('SCI', $esito['indirizzo']);
        // Da dove e quali, per il messaggio e per il registro (15/9/2026): senza,
        // uno spostamento sbagliato si annullava ritrovando gli id dall'ora di modifica.
        $this->assertSame('2', $esito['da_classe']);
        $this->assertSame('SCI', $esito['da_indirizzo'], 'la «2» dello scientifico (ADR-042)');
        $atteso = [$a, $b];
        sort($atteso);
        $this->assertSame(['contenuti' => $atteso, 'verifiche' => [$ver]], $esito['ids'], 'solo i miei: né il collega né gli id finti');
        foreach ([$a, $b] as $id) {
            $this->assertSame(['classe' => $this->voce['2A'], 'indirizzo' => $this->voce['SCI']], $this->dove('teacher_content_data', $id));
        }
        $this->assertSame(['classe' => $this->voce['2A'], 'indirizzo' => $this->voce['SCI']], $this->dove('verifica_documents_data', $ver));
        $this->assertSame(['classe' => $this->voce['2'], 'indirizzo' => null], $this->dove('teacher_content_data', $suo), 'il collega non e\' stato toccato');
    }

    #[Test]
    public function verso_un_anno_il_corso_e_quello_dell_anno(): void
    {
        // ADR-042 — un anno ha il suo corso: la «2» dello scientifico. Prima
        // l'anno non ne aveva, e il corso restava quello che era.
        $a = $this->contenuto($this->prof, '2A', 'SCI', 'Equazioni');
        $esito = $this->svc->sposta($this->prof, [$a], [], $this->voce['2A'], $this->voce['2']);
        $this->assertSame(1, $esito['contenuti']);
        $this->assertSame('SCI', $esito['indirizzo']);
        $this->assertSame(['classe' => $this->voce['2'], 'indirizzo' => $this->voce['SCI']], $this->dove('teacher_content_data', $a));
    }

    #[Test]
    public function si_ferma_se_il_corso_della_sezione_di_arrivo_non_e_spuntato(): void
    {
        $a = $this->contenuto($this->prof, '2', null, 'Equazioni');
        try {
            $this->svc->sposta($this->prof, [$a], [], $this->voce['2'], $this->voce['3B']);
            $this->fail('doveva fermarsi: ART non e\' spuntato');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('indirizzo_non_spuntato:ART', $e->getMessage());
        }
        $this->assertSame(['classe' => $this->voce['2'], 'indirizzo' => null], $this->dove('teacher_content_data', $a), 'niente e\' cambiato');
    }

    #[Test]
    public function si_ferma_se_la_classe_di_arrivo_non_e_spuntata_o_non_c_e_niente_da_spostare(): void
    {
        $a = $this->contenuto($this->prof, '2', null, 'Equazioni');
        try {
            $this->svc->sposta($this->collega, [$a], [], $this->voce['2'], $this->voce['2A']);
            $this->fail('il collega non ha spuntato 2A');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('classe_non_spuntata', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('niente_da_spostare');
        $this->svc->sposta($this->prof, [], ['', '0'], $this->voce['2'], $this->voce['2A']);
    }
    /**
     * Un posto in più di «Dove vale» (ADR-037), come lo scrive DoveVale::aggiungi.
     *
     * @param 'teacher_content_id'|'verifica_document_id' $colonna
     */
    private function posto(string $colonna, int $documento, ?string $indirizzo, string $classe, string $stato, int $archivio = 0): int
    {
        $this->pdo->prepare(
            "INSERT INTO content_publications
                ($colonna, institute_id, indirizzo_id, classe_id, subject_id, is_primary, origine, visibility, archive_visible)
             VALUES (?, ?, ?, ?, ?, 0, 'docente', ?, ?)"
        )->execute([$documento, $this->instId, $indirizzo !== null ? $this->voce[$indirizzo] : null,
                    $this->voce[$classe], $this->voce['MAT'], $stato, $archivio]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * I posti di un documento, la principale per prima.
     *
     * @param 'teacher_content_id'|'verifica_document_id' $colonna
     * @return list<array{id:int,principale:bool,indirizzo:?int,classe:?int,stato:string}>
     */
    private function posti(string $colonna, int $documento): array
    {
        $st = $this->pdo->prepare(
            "SELECT id, is_primary, indirizzo_id, classe_id, visibility FROM content_publications
              WHERE $colonna = ? ORDER BY is_primary DESC, classe_id, indirizzo_id, id"
        );
        $st->execute([$documento]);
        return array_map(static fn(array $r): array => [
            'id'         => (int)$r['id'],
            'principale' => (int)$r['is_primary'] === 1,
            'indirizzo'  => $r['indirizzo_id'] !== null ? (int)$r['indirizzo_id'] : null,
            'classe'     => $r['classe_id'] !== null ? (int)$r['classe_id'] : null,
            'stato'      => (string)$r['visibility'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Il caso di tutti i giorni: l'anno «per più classi» con il suo bersaglio nella sezione. */
    #[Test]
    public function l_anno_con_il_suo_bersaglio_diventa_un_posto_solo_pubblicato_nella_sezione(): void
    {
        $a = $this->contenuto($this->prof, '2', null, 'Equazioni');
        $this->pdo->prepare('UPDATE teacher_content_data SET publish_scope = "classes", visibility = "published" WHERE id = ?')->execute([$a]);
        PostoPrincipale::statoDelContenuto($this->pdo, $a);
        $bersaglio = $this->posto('teacher_content_id', $a, 'SCI', '2A', 'published');
        $this->assertSame('draft', $this->posti('teacher_content_id', $a)[0]['stato'], 'prima: la principale di un «per più classi» è in bozza');

        $esito = $this->svc->sposta($this->prof, [$a], [], $this->voce['2'], $this->voce['2A']);

        $this->assertSame(0, $esito['doppi']);
        $this->assertSame([$bersaglio], $esito['posti']['uniti']);
        $this->assertSame([$a], $esito['posti']['scope_classe']);
        $posti = $this->posti('teacher_content_id', $a);
        $this->assertCount(1, $posti, 'un posto solo nella sezione');
        $this->assertTrue($posti[0]['principale']);
        $this->assertSame([$this->voce['SCI'], $this->voce['2A'], 'published'], [$posti[0]['indirizzo'], $posti[0]['classe'], $posti[0]['stato']],
            'la principale è nella sezione, pubblicata: chi vedeva il contenuto in 2A lo vede ancora');
        $st = $this->pdo->prepare('SELECT publish_scope FROM teacher_content_data WHERE id = ?');
        $st->execute([$a]);
        $this->assertSame('class', $st->fetchColumn());
        $this->pdo->query('CALL pub_verifica_allineamento()');
    }

    #[Test]
    public function i_posti_in_piu_nella_classe_di_partenza_seguono_e_quelli_altrove_restano(): void
    {
        $a = $this->contenuto($this->prof, '2A', 'SCI', 'Prospettiva');
        $this->pdo->prepare('UPDATE teacher_content_data SET visibility = "published" WHERE id = ?')->execute([$a]);
        PostoPrincipale::statoDelContenuto($this->pdo, $a);
        // Il posto in 2A è visibile anche negli anni passati: detto diverso dalla
        // principale, non si unisce e resta un posto a sé.
        $qui    = $this->posto('teacher_content_id', $a, 'SCI', '2A', 'published', 1);
        $altrove = $this->posto('teacher_content_id', $a, 'ART', '3B', 'draft');

        $esito = $this->svc->sposta($this->prof, [$a], [], $this->voce['2A'], $this->voce['2']);

        $this->assertSame([$qui], $esito['posti']['spostati']);
        $this->assertSame([], $esito['posti']['uniti']);
        $posti = $this->posti('teacher_content_id', $a);
        $this->assertSame(
            [
                [true, $this->voce['SCI'], $this->voce['2'], 'published'],
                [false, $this->voce['SCI'], $this->voce['2'], 'published'],
                [false, $this->voce['ART'], $this->voce['3B'], 'draft'],
            ],
            array_map(static fn(array $p): array => [$p['principale'], $p['indirizzo'], $p['classe'], $p['stato']], $posti),
            "la principale e il posto in 2A vanno nella «2» dello scientifico, il corso dell'anno (ADR-042); quello in 3B resta"
        );
        $this->assertSame($altrove, $posti[2]['id']);
    }

    #[Test]
    public function due_posti_che_dicono_cose_diverse_restano_tutti_e_due_e_si_contano(): void
    {
        $a = $this->contenuto($this->prof, '2', null, 'Equazioni');
        $b = $this->contenuto($this->prof, '2', null, 'Sistemi');
        $this->pdo->prepare('UPDATE teacher_content_data SET visibility = "published" WHERE id IN (?, ?)')->execute([$a, $b]);
        PostoPrincipale::statoDelContenuto($this->pdo, $a);
        PostoPrincipale::statoDelContenuto($this->pdo, $b);
        // Un archivio diverso, e un posto archiviato: toglierli cambierebbe chi vede che cosa.
        $this->posto('teacher_content_id', $a, 'SCI', '2A', 'published', 1);
        $this->posto('teacher_content_id', $b, 'SCI', '2A', 'archived');

        $esito = $this->svc->sposta($this->prof, [$a, $b], [], $this->voce['2'], $this->voce['2A']);

        $this->assertSame(2, $esito['doppi']);
        $this->assertSame([], $esito['posti']['uniti']);
        $this->assertCount(2, $this->posti('teacher_content_id', $a));
        $this->assertCount(2, $this->posti('teacher_content_id', $b));
    }

    #[Test]
    public function la_verifica_si_sposta_intera_anche_se_se_ne_sceglie_una_variante(): void
    {
        $sol   = $this->verifica($this->prof, '2', 'Verifica di marzo — A_SOL');
        $nor   = $this->verifica($this->prof, '2', 'Verifica di marzo — A_NOR');
        $altra = $this->verifica($this->prof, '2', 'Verifica di aprile — A_SOL');

        $verifiche = $this->svc->elenco($this->prof, $this->voce['2'])['verifiche'];
        $marzo = array_values(array_filter($verifiche, static fn(array $v): bool => $v['title'] === 'Verifica di marzo'));
        $this->assertCount(2, $verifiche, "l'elenco ha una voce per verifica");
        $this->assertCount(1, $marzo);
        $this->assertSame(2, $marzo[0]['varianti']);
        $this->assertSame([$sol, $nor], $marzo[0]['ids']);

        $esito = $this->svc->sposta($this->prof, [], [$sol], $this->voce['2'], $this->voce['2A']);

        $this->assertSame([1, 2], [$esito['verifiche'], $esito['varianti']]);
        foreach ([$sol, $nor] as $id) {
            $this->assertSame(['classe' => $this->voce['2A'], 'indirizzo' => $this->voce['SCI']], $this->dove('verifica_documents_data', $id));
        }
        $this->assertSame(['classe' => $this->voce['2'], 'indirizzo' => null], $this->dove('verifica_documents_data', $altra), "l'altra verifica resta");
    }

    #[Test]
    public function nella_verifica_il_posto_pubblicato_che_si_unisce_pubblica_la_principale(): void
    {
        $v = $this->verifica($this->prof, '2', 'Verifica');
        $principale = $this->posti('verifica_document_id', $v)[0];
        $this->assertSame('draft', $principale['stato'], 'la principale di una verifica nasce in bozza');
        $pubblicato = $this->posto('verifica_document_id', $v, 'SCI', '2A', 'published');

        $esito = $this->svc->sposta($this->prof, [], [$v], $this->voce['2'], $this->voce['2A']);

        $this->assertSame([$pubblicato], $esito['posti']['uniti']);
        $this->assertSame([$principale['id']], $esito['posti']['stati']);
        $posti = $this->posti('verifica_document_id', $v);
        $this->assertCount(1, $posti);
        $this->assertSame([true, $this->voce['2A'], 'published'], [$posti[0]['principale'], $posti[0]['classe'], $posti[0]['stato']],
            'la classe di arrivo continua a vederla');
    }

    #[Test]
    public function si_muove_solo_cio_che_sta_nella_classe_di_partenza(): void
    {
        $in2  = $this->contenuto($this->prof, '2', null, 'Equazioni');
        $in3b = $this->contenuto($this->prof, '3B', 'ART', 'Prospettiva');
        $esito = $this->svc->sposta($this->prof, [$in2, $in3b], [], $this->voce['2'], $this->voce['2A']);
        $this->assertSame(1, $esito['contenuti'], "l'id di un contenuto di un'altra classe non conta");
        $this->assertSame(['classe' => $this->voce['3B'], 'indirizzo' => $this->voce['ART']], $this->dove('teacher_content_data', $in3b));
    }

    #[Test]
    public function fra_due_scuole_diverse_si_ferma(): void
    {
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZSPOST002', 'ALTRA SCUOLA', 'Comune Esempio']);
        $altra = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$this->prof, $altra]);
        $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, origine)
             VALUES ("classi", ?, "2A", "Seconda A", NULL, 1, "istituto")'
        )->execute([$altra]);
        $altra2A = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active) VALUES (?, ?, 1)')->execute([$altra2A, $this->prof]);
        $a = $this->contenuto($this->prof, '2', null, 'Equazioni');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('istituti_diversi');
        $this->svc->sposta($this->prof, [$a], [], $this->voce['2'], $altra2A);
    }
}
