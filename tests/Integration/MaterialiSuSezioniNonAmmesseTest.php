<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\Contenuti\MaterialiSuSezioniNonAmmesse;
use App\Services\Contenuti\SpostamentoDiClasse;
use App\Services\SezioniDeiDocenti;
use App\Support\PostoPrincipale;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-041 — i materiali rimasti su una sezione che il docente non può più usare.
 *
 * Misurato il 14/9/2026 prima di questa prova: tolto l'incarico, la sezione
 * spariva anche da «Sposta di classe» e lo spostamento era rifiutato
 * (`classe_non_spuntata`). Qui, nei due versi: il docente parte dalla sezione
 * sospesa e porta sull'anno anche i contenuti che lì hanno solo un posto in più;
 * non arriva mai in una sezione sospesa; l'amministratore porta sull'anno quelli
 * di chi non lo fa, e si ferma se l'anno manca o se la sezione è ammessa.
 *
 * Istituto «solo incaricati»: il docente ha l'incarico della 2B e non più della
 * 2A (spunta sospesa), la 4D senza l'anno 4 nel catalogo; il collega nessun
 * incarico e nessuna spunta sull'anno. Tutto in transazione, annullata alla fine.
 */
final class MaterialiSuSezioniNonAmmesseTest extends TestCase
{
    private PDO $pdo;
    private int $ist = 0;
    private int $prof = 0;
    private int $collega = 0;
    /** @var array<string,int> */
    private array $voce = [];

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
        if ($this->pdo->query("SHOW COLUMNS FROM institutes LIKE 'sezioni_scadenza'")->fetch() === false) {
            self::fail('manca la migrazione 127 sul database di prova: APP_ENV=testing php tools/migrate.php');
        }
        $this->pdo->beginTransaction();

        $this->pdo->prepare("INSERT INTO institutes (code, name, city, active, sezioni_docenti) VALUES (?, ?, ?, 1, 'solo_incaricati')")
            ->execute(['ZZRIMASTI1', 'ISTITUTO DEI MATERIALI RIMASTI', 'Comune Esempio']);
        $this->ist = (int)$this->pdo->lastInsertId();
        $ins = $this->pdo->prepare(
            "INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, origine)
             VALUES (?, ?, ?, ?, ?, 1, 'istituto')"
        );
        foreach ([
            ['indirizzi', 'SCI', 'Scientifico', null],
            ['materie', 'MAT', 'Matematica', null],
            ['classi', '2', 'Seconda', 'SCI'],
            ['classi', '2A', '2A', 'SCI'],
            ['classi', '2B', '2B', 'SCI'],
            ['classi', '4D', '4D', 'SCI'],
        ] as [$kind, $code, $label, $ind]) {
            $ins->execute([$kind, $this->ist, $code, $label, $ind]);
            $this->voce[$code] = (int)$this->pdo->lastInsertId();
        }

        $this->prof    = $this->docente('zz_rimasti_prof');
        $this->collega = $this->docente('zz_rimasti_coll');
        $this->pdo->prepare("INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe) VALUES (?, ?, 'SCI', '2B')")
            ->execute([$this->prof, $this->ist]);
        // ADR-043 — l'anno «2» si usa con l'incarico, per tutti e due: qui si provano le sezioni.
        foreach ([$this->prof, $this->collega] as $chi) {
            $this->pdo->prepare("INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe) VALUES (?, ?, 'SCI', '2')")
                ->execute([$chi, $this->ist]);
        }
        foreach (['SCI', 'MAT', '2', '2B'] as $code) {
            $this->spunta($this->prof, $code);
        }
        // Le spunte sulla 2A e sulla 4D sospese dalla scuola, come dopo un incarico tolto.
        foreach (['2A', '4D'] as $code) {
            $this->spunta($this->prof, $code, false);
        }
        foreach (['SCI', 'MAT'] as $code) {
            $this->spunta($this->collega, $code);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * ADR-043 — anche un anno senza incarico è una classe che il docente non
     * può usare: i suoi materiali lì si elencano, come «già un anno» (non si
     * portano sull'anno), e il docente parte da lì in «Sposta di classe».
     */
    #[Test]
    public function si_elencano_anche_gli_anni_senza_incarico(): void
    {
        $this->pdo->prepare("DELETE FROM teacher_sections WHERE user_id = ? AND institute_id = ? AND classe = '2'")->execute([$this->prof, $this->ist]);
        // Come dopo un incarico tolto dal pannello: la spunta sull'anno si sospende.
        (new SezioniDeiDocenti($this->pdo))->riallinea($this->ist, $this->prof);
        $this->contenuto($this->prof, '2', 'SCI', 'Sull\'anno senza incarico');

        $righe = (new MaterialiSuSezioniNonAmmesse($this->pdo))->righe($this->prof);
        self::assertSame(['2'], array_column($righe, 'code'));
        $pannello = (new MaterialiSuSezioniNonAmmesse($this->pdo))->perIstituto($this->ist);
        self::assertTrue($pannello[0]['e_anno'], 'nel pannello: è già un anno');
        $partenze = (new SpostamentoDiClasse($this->pdo))->classiDiPartenza($this->prof);
        $anno = array_values(array_filter($partenze, fn(array $c): bool => $c['id'] === $this->voce['2']));
        self::assertTrue($anno[0]['sospesa'] ?? false, 'in «Sposta di classe» si parte dall\'anno senza incarico');

        // Nell'altro verso: ridato l'incarico sull'anno, non si elenca più.
        $this->pdo->prepare("INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe) VALUES (?, ?, 'SCI', '2')")->execute([$this->prof, $this->ist]);
        self::assertSame([], (new MaterialiSuSezioniNonAmmesse($this->pdo))->righe($this->prof));
    }

    #[Test]
    public function si_elencano_solo_le_sezioni_che_il_docente_non_puo_usare(): void
    {
        $this->contenuto($this->prof, '2A', 'SCI', 'Sulla 2A');
        $b = $this->contenuto($this->prof, '2', null, 'Sull\'anno, con un posto in 2A');
        $this->posto('teacher_content_id', $b, 'SCI', '2A', 'published');
        $this->verifica($this->prof, '2A', 'Verifica della 2A');
        $this->contenuto($this->prof, '2B', 'SCI', 'Sulla 2B, con incarico');
        $this->contenuto($this->prof, '4D', 'SCI', 'Sulla 4D');

        $righe = (new MaterialiSuSezioniNonAmmesse($this->pdo))->righe($this->prof);
        self::assertSame(['2A', '4D'], array_column($righe, 'code'), 'la 2B ha l\'incarico: non si elenca');
        self::assertSame(2, $righe[0]['contenuti'], 'quello con la principale lì e quello con un posto in più');
        self::assertSame(1, $righe[0]['verifiche']);
        self::assertSame(3, $righe[0]['posti']);

        // Controprova: con l'incarico della 2A la 2A non si elenca più.
        $this->pdo->prepare("INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe) VALUES (?, ?, 'SCI', '2A')")
            ->execute([$this->prof, $this->ist]);
        self::assertSame(['4D'], array_column((new MaterialiSuSezioniNonAmmesse($this->pdo))->righe($this->prof), 'code'));

        // E con «tutti» nessuna sezione è da spostare.
        $this->pdo->prepare("UPDATE institutes SET sezioni_docenti = 'tutti' WHERE id = ?")->execute([$this->ist]);
        self::assertSame([], (new MaterialiSuSezioniNonAmmesse($this->pdo))->righe($this->prof));
    }

    #[Test]
    public function il_docente_parte_dalla_sezione_sospesa_e_porta_sull_anno_anche_i_posti(): void
    {
        $a = $this->contenuto($this->prof, '2A', 'SCI', 'Sulla 2A');
        $b = $this->contenuto($this->prof, '2', null, 'Sull\'anno, con un posto in 2A');
        $this->posto('teacher_content_id', $b, 'SCI', '2A', 'published');
        $v = $this->verifica($this->prof, '2A', 'Verifica della 2A');
        $c = $this->contenuto($this->collega, '2A', 'SCI', 'Del collega');
        $svc = new SpostamentoDiClasse($this->pdo);

        $partenze = [];
        foreach ($svc->classiDiPartenza($this->prof) as $cl) {
            $partenze[$cl['code']] = $cl['sospesa'];
        }
        self::assertSame(['2' => false, '2A' => true, '2B' => false], $partenze, 'la 2A c\'è, sospesa; la 4D no: è vuota');
        self::assertNotContains('2A', array_column($svc->classiDelDocente($this->prof), 'code'), 'come arrivo la 2A non c\'è');

        // Dal 15/9/2026 l'elenco ha sempre anche i posti in più, da qualunque classe
        // di partenza (SpostamentoDiClasseTest::da_una_classe_spuntata_…).
        $conPosti = $svc->elenco($this->prof, $this->voce['2A']);
        // L'elenco è in ordine di titolo: si confronta per id.
        self::assertEquals([$a => null, $b => '2'], array_column($conPosti['contenuti'], 'principale_in', 'id'));
        self::assertSame([$v], array_column($conPosti['verifiche'], 'id'));

        $esito = $svc->sposta($this->prof, [$a, $b, $c], [$v], $this->voce['2A'], $this->voce['2']);
        self::assertSame(2, $esito['contenuti'], 'i due del docente; quello del collega no');
        self::assertSame(1, $esito['verifiche']);
        self::assertSame($this->voce['2'], $this->classe('teacher_content', $a));
        self::assertSame($this->voce['2'], $this->classe('teacher_content', $b), 'la principale di B resta sull\'anno');
        self::assertSame($this->voce['2'], $this->classe('verifica_documents', $v));
        self::assertSame(0, $this->postiIn($b, '2A'), 'e il suo posto in 2A non c\'è più');
        self::assertSame($this->voce['2A'], $this->classe('teacher_content', $c), 'il collega non è stato toccato');
        self::assertSame([], (new MaterialiSuSezioniNonAmmesse($this->pdo))->righe($this->prof), 'niente più da spostare');
    }

    #[Test]
    public function il_docente_non_arriva_in_una_sezione_che_non_puo_usare(): void
    {
        $a = $this->contenuto($this->prof, '2A', 'SCI', 'Sulla 2A');
        $d = $this->contenuto($this->prof, '4D', 'SCI', 'Sulla 4D');
        $e = $this->contenuto($this->prof, '2B', 'SCI', 'Sulla 2B');
        $svc = new SpostamentoDiClasse($this->pdo);

        foreach ([[$e, '2B', '2A'], [$a, '2A', '4D']] as [$id, $da, $a2]) {
            try {
                $svc->sposta($this->prof, [$id], [], $this->voce[$da], $this->voce[$a2]);
                self::fail("spostato da $da a $a2, sezione senza incarico");
            } catch (InvalidArgumentException $ex) {
                self::assertSame('classe_non_spuntata', $ex->getMessage());
            }
        }
        self::assertSame($this->voce['2A'], $this->classe('teacher_content', $a));
        self::assertSame($this->voce['4D'], $this->classe('teacher_content', $d));
        self::assertSame($this->voce['2B'], $this->classe('teacher_content', $e));

        // Verso la sezione di cui ha l'incarico sì.
        $esito = $svc->sposta($this->prof, [$a], [], $this->voce['2A'], $this->voce['2B']);
        self::assertSame(1, $esito['contenuti']);
        self::assertSame($this->voce['2B'], $this->classe('teacher_content', $a));
    }

    #[Test]
    public function l_amministratore_porta_sull_anno_i_materiali_di_chi_non_li_sposta(): void
    {
        $c = $this->contenuto($this->collega, '2A', 'SCI', 'Del collega');
        $servizio = new MaterialiSuSezioniNonAmmesse($this->pdo);

        $righe = $servizio->perIstituto($this->ist);
        self::assertCount(1, $righe);
        self::assertSame($this->collega, $righe[0]['docente']);
        self::assertSame('2A', $righe[0]['code']);
        self::assertSame($this->voce['2'], $righe[0]['anno_id']);
        self::assertSame(0, $righe[0]['credenziali']);

        $esito = $servizio->portaSullAnno($this->ist, $this->collega, $this->voce['2A']);
        self::assertSame(1, $esito['contenuti']);
        self::assertTrue($esito['anno_spuntato'], 'il collega non aveva l\'anno: ora ce l\'ha');
        self::assertSame($this->voce['2'], $this->classe('teacher_content', $c));
        $st = $this->pdo->prepare('SELECT active FROM curriculum_teacher WHERE curriculum_id = ? AND user_id = ?');
        $st->execute([$this->voce['2'], $this->collega]);
        self::assertSame(1, (int)$st->fetchColumn());
        self::assertSame([], $servizio->perIstituto($this->ist));
    }

    #[Test]
    public function l_amministratore_si_ferma_senza_anno_su_una_sezione_ammessa_e_senza_materiali(): void
    {
        $d = $this->contenuto($this->prof, '4D', 'SCI', 'Sulla 4D');
        $servizio = new MaterialiSuSezioniNonAmmesse($this->pdo);

        foreach ([
            [$this->prof, '4D', 'anno_mancante:4'],
            [$this->prof, '2B', 'sezione_ammessa'],
            [$this->collega, '2B', 'niente_da_spostare'],
            [$this->prof, '2', 'sezione_non_valida'],
        ] as [$chi, $sezione, $atteso]) {
            try {
                $servizio->portaSullAnno($this->ist, $chi, $this->voce[$sezione]);
                self::fail("doveva fermarsi: $atteso");
            } catch (InvalidArgumentException $ex) {
                self::assertSame($atteso, $ex->getMessage());
            }
        }
        self::assertSame($this->voce['4D'], $this->classe('teacher_content', $d), 'niente è cambiato');
    }

    #[Test]
    public function la_data_accompagna_l_avviso_e_si_toglie(): void
    {
        $this->contenuto($this->prof, '2A', 'SCI', 'Sulla 2A');
        $this->verifica($this->prof, '2A', 'Verifica della 2A');
        $servizio = new MaterialiSuSezioniNonAmmesse($this->pdo);

        $avviso = $servizio->avviso($this->prof);
        self::assertCount(1, $avviso);
        self::assertNull($avviso[0]['scadenza']);
        self::assertSame([['id' => $this->voce['2A'], 'code' => '2A', 'materiali' => 2]], $avviso[0]['sezioni']);
        self::assertSame([], $servizio->avviso($this->collega), 'chi non ha niente da spostare non riceve l\'avviso');

        $servizio->impostaScadenza($this->ist, '2026-10-01');
        self::assertSame('2026-10-01', $servizio->avviso($this->prof)[0]['scadenza']);
        $servizio->impostaScadenza($this->ist, '');
        self::assertNull($servizio->scadenza($this->ist));

        foreach (['31/10/2026', '2026-02-30'] as $sbagliata) {
            try {
                $servizio->impostaScadenza($this->ist, $sbagliata);
                self::fail("accettata la data $sbagliata");
            } catch (InvalidArgumentException $ex) {
                self::assertSame('data_non_valida', $ex->getMessage());
            }
        }
    }

    private function docente(string $username): int
    {
        $this->pdo->prepare(
            "INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active)
             VALUES (?, 'teacher', 'Zz', 'Rimasti', ?, 'x', 'approved', 1)"
        )->execute([$username, $username . '@example.test']);
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$id, $this->ist]);
        return $id;
    }

    private function spunta(int $docente, string $code, bool $attiva = true): void
    {
        $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active, sospesa_dalla_scuola) VALUES (?, ?, ?, ?)')
            ->execute([$this->voce[$code], $docente, $attiva ? 1 : 0, $attiva ? 0 : 1]);
    }

    private function contenuto(int $docente, string $classe, ?string $indirizzo, string $titolo): int
    {
        $this->pdo->prepare('INSERT INTO teacher_content_data (teacher_id, content_subtype, title) VALUES (?, "esercizio", ?)')
            ->execute([$docente, $titolo]);
        $id = (int)$this->pdo->lastInsertId();
        PostoPrincipale::contenuto($this->pdo, $id, $indirizzo !== null ? $this->voce[$indirizzo] : null, $this->voce[$classe], $this->voce['MAT']);
        return $id;
    }

    private function verifica(int $docente, string $classe, string $titolo): int
    {
        $this->pdo->prepare('INSERT INTO verifica_documents_data (teacher_id, title) VALUES (?, ?)')->execute([$docente, $titolo]);
        $id = (int)$this->pdo->lastInsertId();
        PostoPrincipale::verifica($this->pdo, $id, $this->voce['SCI'], $this->voce[$classe], $this->voce['MAT']);
        return $id;
    }

    /** @param 'teacher_content_id'|'verifica_document_id' $colonna */
    private function posto(string $colonna, int $documento, ?string $indirizzo, string $classe, string $stato): void
    {
        $this->pdo->prepare(
            "INSERT INTO content_publications
                ($colonna, institute_id, indirizzo_id, classe_id, subject_id, is_primary, origine, visibility, archive_visible)
             VALUES (?, ?, ?, ?, ?, 0, 'docente', ?, 0)"
        )->execute([$documento, $this->ist, $indirizzo !== null ? $this->voce[$indirizzo] : null, $this->voce[$classe], $this->voce['MAT'], $stato]);
    }

    /** @param 'teacher_content'|'verifica_documents' $vista */
    private function classe(string $vista, int $id): ?int
    {
        $st = $this->pdo->prepare("SELECT classe_id FROM `$vista` WHERE id = ?");
        $st->execute([$id]);
        $c = $st->fetchColumn();
        return $c !== false && $c !== null ? (int)$c : null;
    }

    private function postiIn(int $contenuto, string $classe): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM content_publications WHERE teacher_content_id = ? AND classe_id = ?');
        $st->execute([$contenuto, $this->voce[$classe]]);
        return (int)$st->fetchColumn();
    }
}
