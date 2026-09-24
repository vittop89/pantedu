<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Support\PostoPrincipale;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il posto principale lo scrive l'applicazione (ADR-037, fase 4c).
 *
 * Le righe nascono senza etichette, e la principale la scrive solo
 * PostoPrincipale: i trigger della 117 e della 119 li ha tolti la 122, e le
 * colonne della riga la 123 (della verifica resta la materia, chiave del suo
 * indice unico). Le viste teacher_content e verifica_documents (121) danno le
 * sigle della principale.
 *
 * In più la guardia delle voci (trg_curriculum_no_orphan, ricreata dalla 121):
 * una voce usata solo da una pubblicazione non si cancella.
 *
 * DB-gated, tutto in transazione → rollback in tearDown.
 */
final class PostoPrincipaleTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private int $scuola = 0;
    private int $altraScuola = 0;
    private int $prof = 0;
    /** @var array<string,int> "scuola:codice" → id della voce */
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
        $this->pdo->beginTransaction();
        $this->inTx = true;

        $this->scuola = $this->istituto('ZZPOSTO01');
        $this->altraScuola = $this->istituto('ZZPOSTO02');
        foreach ([$this->scuola => 'a', $this->altraScuola => 'b'] as $istituto => $sigla) {
            foreach ([['indirizzi', 'SCI'], ['classi', '1A'], ['classi', '2A'], ['materie', 'MAT']] as [$kind, $code]) {
                $this->pdo->prepare(
                    'INSERT INTO curriculum_entries (kind, institute_id, code, label, active, origine) VALUES (?, ?, ?, ?, 1, "istituto")'
                )->execute([$kind, $istituto, $code, $code]);
                $this->voce["$sigla:$code"] = (int)$this->pdo->lastInsertId();
            }
        }
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES ("zzposto", "teacher", "Zz", "Prof", "zzposto@example.invalid", "x", "approved", 1, NOW())'
        )->execute();
        $this->prof = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function istituto(string $code): int
    {
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, "Comune Esempio", 1)')->execute([$code, $code]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Un contenuto senza etichette: il trigger non gli crea nessuna principale. */
    private function contenuto(string $visibilita = 'published', string $scope = 'class'): int
    {
        $this->pdo->prepare(
            'INSERT INTO teacher_content_data (teacher_id, content_subtype, title, visibility, publish_scope)
             VALUES (?, "esercizio", "posto principale", ?, ?)'
        )->execute([$this->prof, $visibilita, $scope]);
        return (int)$this->pdo->lastInsertId();
    }

    private function verifica(): int
    {
        $this->pdo->prepare('INSERT INTO verifica_documents_data (teacher_id, title) VALUES (?, "Verifica — A_SOL")')->execute([$this->prof]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return list<string> le colonne di etichetta che la tabella ha ancora */
    private function etichetteNellaRiga(string $tabella): array
    {
        $st = $this->pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND COLUMN_NAME IN ('indirizzo_id', 'classe_id', 'subject_id', 'materia_id')
              ORDER BY COLUMN_NAME"
        );
        $st->execute([$tabella]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string,mixed>|null */
    private function principale(string $colonna, int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT id, institute_id, indirizzo_id, classe_id, subject_id, visibility, archive_visible FROM content_publications WHERE $colonna = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    #[Test]
    public function la_principale_la_scrive_l_applicazione_e_la_vista_prende_le_sigle_da_li(): void
    {
        $id = $this->contenuto();
        $this->assertNull($this->principale('primary_of_tc', $id), 'senza etichette nessuna principale: il trigger non ha scritto niente');

        PostoPrincipale::contenuto($this->pdo, $id, $this->voce['a:SCI'], $this->voce['a:1A'], $this->voce['a:MAT']);

        $p = $this->principale('primary_of_tc', $id);
        $this->assertNotNull($p);
        $this->assertSame(
            [$this->scuola, $this->voce['a:SCI'], $this->voce['a:1A'], $this->voce['a:MAT'], 'published', 0],
            [(int)$p['institute_id'], (int)$p['indirizzo_id'], (int)$p['classe_id'], (int)$p['subject_id'], $p['visibility'], (int)$p['archive_visible']]
        );
        $this->assertSame([], $this->etichetteNellaRiga('teacher_content_data'), 'la riga non ha più le etichette (migrazione 123)');
        $st = $this->pdo->prepare('SELECT indirizzo_id, classe_id, subject_id, indirizzo, classe, subject_code FROM teacher_content WHERE id = ?');
        $st->execute([$id]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['SCI', '1A', 'MAT'], [$v['indirizzo'], $v['classe'], $v['subject_code']], 'la vista dice il posto della principale');
        $this->assertSame($this->voce['a:1A'], (int)$v['classe_id']);
    }

    #[Test]
    public function spostare_conserva_la_pubblicazione_e_senza_etichette_la_principale_se_ne_va(): void
    {
        $id = $this->contenuto();
        PostoPrincipale::contenuto($this->pdo, $id, $this->voce['a:SCI'], $this->voce['a:1A'], $this->voce['a:MAT']);
        $prima = $this->principale('primary_of_tc', $id);

        PostoPrincipale::contenuto($this->pdo, $id, $this->voce['b:SCI'], $this->voce['b:2A'], $this->voce['b:MAT']);
        $dopo = $this->principale('primary_of_tc', $id);
        $this->assertNotNull($prima);
        $this->assertNotNull($dopo);
        $this->assertSame((int)$prima['id'], (int)$dopo['id'], 'la stessa pubblicazione, spostata');
        $this->assertSame([$this->altraScuola, $this->voce['b:2A']], [(int)$dopo['institute_id'], (int)$dopo['classe_id']], "la scuola viene dalle voci dell'altra scuola");
        $this->assertSame(['indirizzo' => $this->voce['b:SCI'], 'classe' => $this->voce['b:2A'], 'materia' => $this->voce['b:MAT']],
            PostoPrincipale::dove($this->pdo, PostoPrincipale::CONTENUTO, $id));

        PostoPrincipale::contenuto($this->pdo, $id, null, null, 0);
        $this->assertNull($this->principale('primary_of_tc', $id), 'senza etichette non sta in nessuna scuola');
        $this->assertSame(['indirizzo' => null, 'classe' => null, 'materia' => null], PostoPrincipale::dove($this->pdo, PostoPrincipale::CONTENUTO, $id));
    }

    /** Come le procedure della 118 e della 119: la scuola dalla materia, poi dalla classe, poi dall'indirizzo. */
    #[Test]
    public function con_etichette_di_due_scuole_decide_la_materia(): void
    {
        $id = $this->contenuto();
        PostoPrincipale::contenuto($this->pdo, $id, $this->voce['a:SCI'], $this->voce['a:1A'], $this->voce['b:MAT']);
        $this->assertSame($this->altraScuola, (int)($this->principale('primary_of_tc', $id)['institute_id'] ?? 0));
        PostoPrincipale::contenuto($this->pdo, $id, $this->voce['b:SCI'], $this->voce['a:1A'], null);
        $this->assertSame($this->scuola, (int)($this->principale('primary_of_tc', $id)['institute_id'] ?? 0), 'senza materia, la classe');
    }

    #[Test]
    public function lo_stato_della_principale_di_un_contenuto_viene_dalla_riga(): void
    {
        $perPiuClassi = $this->contenuto('published', 'classes');
        PostoPrincipale::contenuto($this->pdo, $perPiuClassi, null, $this->voce['a:1A'], null);
        $this->assertSame('draft', $this->principale('primary_of_tc', $perPiuClassi)['visibility'] ?? null, '«per più classi»: la principale è in bozza');

        $id = $this->contenuto('published');
        PostoPrincipale::contenuto($this->pdo, $id, null, $this->voce['a:1A'], null);
        // Lo stato scritto a mano sulla pubblicazione (nessun trigger scatta): lo rimette la riga.
        $this->pdo->prepare('UPDATE content_publications SET visibility = "archived", archive_visible = 1 WHERE primary_of_tc = ?')->execute([$id]);
        PostoPrincipale::statoDelContenuto($this->pdo, $id);
        $p = $this->principale('primary_of_tc', $id);
        $this->assertSame(['published', 0], [$p['visibility'] ?? null, (int)($p['archive_visible'] ?? -1)]);
    }

    #[Test]
    public function la_principale_di_una_verifica_nasce_in_bozza_e_spostandola_tiene_lo_stato(): void
    {
        $id = $this->verifica();
        $this->assertNull($this->principale('primary_of_vd', $id));

        PostoPrincipale::verifica($this->pdo, $id, null, $this->voce['a:1A'], $this->voce['a:MAT']);
        $p = $this->principale('primary_of_vd', $id);
        $this->assertSame('draft', $p['visibility'] ?? null, 'nasce in bozza');
        $this->pdo->prepare('UPDATE content_publications SET visibility = "published" WHERE primary_of_vd = ?')->execute([$id]);

        PostoPrincipale::verifica($this->pdo, $id, $this->voce['a:SCI'], $this->voce['a:2A'], $this->voce['a:MAT']);
        $dopo = $this->principale('primary_of_vd', $id);
        $this->assertSame([(int)($p['id'] ?? 0), 'published', $this->voce['a:2A']], [(int)($dopo['id'] ?? -1), $dopo['visibility'] ?? null, (int)($dopo['classe_id'] ?? 0)],
            'la stessa pubblicazione, nel posto nuovo, con lo stato scelto dal docente');

        $this->assertSame(['materia_id'], $this->etichetteNellaRiga('verifica_documents_data'),
            'della verifica resta nella riga solo la materia, chiave del suo indice unico');
        $st = $this->pdo->prepare('SELECT materia_id, classe, materia FROM verifica_documents WHERE id = ?');
        $st->execute([$id]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['2A', 'MAT', $this->voce['a:MAT']], [$v['classe'], $v['materia'], (int)$v['materia_id']],
            'la vista delle verifiche prende le sigle dalla principale');

        PostoPrincipale::verifica($this->pdo, $id, null, null, null);
        $this->assertNull($this->principale('primary_of_vd', $id));
    }

    #[Test]
    public function una_voce_usata_solo_da_una_pubblicazione_non_si_cancella(): void
    {
        $id = $this->contenuto();
        PostoPrincipale::contenuto($this->pdo, $id, null, $this->voce['a:2A'], null);
        try {
            $this->pdo->prepare('DELETE FROM curriculum_entries WHERE id = ?')->execute([$this->voce['a:2A']]);
            $this->fail('la guardia doveva fermare la cancellazione: la classe è il posto di un contenuto');
        } catch (PDOException $e) {
            $this->assertStringContainsString('voce di curriculum ancora usata', $e->getMessage());
        }
        $this->pdo->prepare('DELETE FROM curriculum_entries WHERE id = ?')->execute([$this->voce['b:2A']]);
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM curriculum_entries WHERE id = ?');
        $st->execute([$this->voce['b:2A']]);
        $this->assertSame(0, (int)$st->fetchColumn(), 'una voce che nessuno usa si cancella');
    }
}
