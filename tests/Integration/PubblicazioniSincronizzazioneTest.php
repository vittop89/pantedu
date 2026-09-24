<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\TeacherContentRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-037 — la pubblicazione principale segue la riga, perché la scrive
 * l'applicazione.
 *
 * Fino alla fase 4c-1 lo facevano i trigger della 117 e della 119, per
 * chiunque scrivesse le colonne della riga; la 122 (fase 4c-2) li ha tolti, e
 * indirizzo, classe e materia non stanno più nella riga. Qui ogni prova scrive
 * come l'applicazione — il repository: creare, aggiornare, cancellare — e
 * guarda la tabella; poi la verifica di allineamento, che dice quando qualcuno
 * ha aggirato l'applicazione. Una scrittura SQL diretta sulla riga non sposta
 * più niente: è il motivo per cui gli strumenti passano da PostoPrincipale.
 *
 * Fixture isolata in transazione (rollback in tearDown): un istituto con un
 * corso, l'anno 2 e le sezioni 2A e 2B del corso, una materia; un docente
 * collegato solo a quell'istituto.
 */
final class PubblicazioniSincronizzazioneTest extends TestCase
{
    private PDO $pdo;
    private TeacherContentRepository $repo;
    private bool $inTx = false;
    private int $scuola = 0;
    private int $docente = 0;
    /** @var array<string,int> sigla → id della voce */
    private array $voce = [];

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
            $this->pdo->query('SELECT 1 FROM content_publications LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migrazione 117 non disponibili: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;
        $_SESSION = [];
        \App\Support\CurriculumLookup::resetCache();

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZPUBS01', 'ISTITUTO SINCRONIZZAZIONE', 'Comune Esempio']);
        $this->scuola = (int)$this->pdo->lastInsertId();
        // ADR-041 — la prova non riguarda chi dei docenti può usare le sezioni:
        // l'istituto vale «tutti», come ogni istituto prima della migrazione 126.
        $this->pdo->prepare("UPDATE institutes SET sezioni_docenti = 'tutti' WHERE id = ?")->execute([$this->scuola]);

        $ins = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, shared_with_pool, origine)
             VALUES (?, ?, ?, ?, ?, 1, 0, "istituto")'
        );
        foreach ([
            ['indirizzi', 'ZPS', null], ['classi', '2', 'ZPS'],
            ['classi', '2A', 'ZPS'], ['classi', '2B', 'ZPS'], ['materie', 'ZPM', null],
        ] as [$kind, $code, $corso]) {
            $ins->execute([$kind, $this->scuola, $code, $code, $corso]);
            $this->voce[$code] = (int)$this->pdo->lastInsertId();
        }

        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES ("zzpubsync", "teacher", "Zz", "Sincronizzazione", "zzpubsync@example.invalid", "x", "approved", 1, NOW())'
        )->execute();
        $this->docente = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$this->docente, $this->scuola]);

        $this->repo = new TeacherContentRepository();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $_SESSION = [];
        \App\Support\CurriculumLookup::resetCache();
    }

    /** @param array<string,mixed> $extra */
    private function crea(array $extra = []): int
    {
        return $this->repo->create($extra + [
            'teacher_id'   => $this->docente,
            'content_type' => 'document',
            'subject_code' => 'ZPM',
            'indirizzo'    => 'ZPS',
            'classe'       => '2',
            'topic'        => 'Sincronizzazione',
            'title'        => 'Sincronizzazione ' . uniqid('', true),
            'visibility'   => 'draft',
        ]);
    }

    /** @return list<array{institute_id:int,indirizzo_id:?int,classe_id:?int,subject_id:?int,is_primary:int,visibility:string,archive_visible:int,id:int}> */
    private function pubblicazioni(int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, institute_id, indirizzo_id, classe_id, subject_id, is_primary, visibility, archive_visible
               FROM content_publications WHERE teacher_content_id = ?
              ORDER BY is_primary DESC, classe_id'
        );
        $st->execute([$id]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'              => (int)$r['id'],
                'institute_id'    => (int)$r['institute_id'],
                'indirizzo_id'    => $r['indirizzo_id'] !== null ? (int)$r['indirizzo_id'] : null,
                'classe_id'       => $r['classe_id'] !== null ? (int)$r['classe_id'] : null,
                'subject_id'      => $r['subject_id'] !== null ? (int)$r['subject_id'] : null,
                'is_primary'      => (int)$r['is_primary'],
                'visibility'      => (string)$r['visibility'],
                'archive_visible' => (int)$r['archive_visible'],
            ];
        }
        return $out;
    }

    #[Test]
    public function un_contenuto_nuovo_ha_la_principale_nella_scuola_delle_sue_etichette(): void
    {
        $id = $this->crea(['visibility' => 'published']);
        $p = $this->pubblicazioni($id);
        $this->assertCount(1, $p);
        $this->assertSame(1, $p[0]['is_primary']);
        $this->assertSame($this->scuola, $p[0]['institute_id']);
        $this->assertSame([$this->voce['ZPS'], $this->voce['2'], $this->voce['ZPM']], [$p[0]['indirizzo_id'], $p[0]['classe_id'], $p[0]['subject_id']]);
        $this->assertSame('published', $p[0]['visibility']);
    }

    #[Test]
    public function le_etichette_non_stanno_nella_riga_ma_nella_principale(): void
    {
        $id = $this->crea(['classe' => '2A']);
        $colonne = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_content_data'
                AND COLUMN_NAME IN ('indirizzo_id', 'classe_id', 'subject_id')"
        )->fetchColumn();
        $this->assertSame(0, (int)$colonne, 'la riga non ha più le etichette (fase 4c-3, migrazione 123)');
        $this->assertSame($this->voce['2A'], $this->pubblicazioni($id)[0]['classe_id']);
        $st = $this->pdo->prepare('SELECT classe FROM teacher_content WHERE id = ?');
        $st->execute([$id]);
        $this->assertSame('2A', $st->fetchColumn(), 'e la vista la dice dalla principale');
    }

    #[Test]
    public function senza_etichette_nessuna_pubblicazione_e_perdendole_si_perde_la_pubblicazione(): void
    {
        $senza = $this->crea(['subject_code' => 'ZZNONE', 'indirizzo' => null, 'classe' => null]);
        $this->assertSame([], $this->pubblicazioni($senza), 'nessuna scuola, nessuna pubblicazione');

        $id = $this->crea();
        $this->assertCount(1, $this->pubblicazioni($id), "prima c'è la principale");
        $this->assertTrue($this->repo->update($id, $this->docente, ['subject_code' => null, 'indirizzo' => null, 'classe' => null]));
        $this->assertSame([], $this->pubblicazioni($id));
    }

    #[Test]
    public function spostare_la_classe_sposta_la_principale_e_ne_conserva_l_id(): void
    {
        $id = $this->crea();
        $prima = $this->pubblicazioni($id);
        $this->assertTrue($this->repo->update($id, $this->docente, ['classe' => '2B']));
        $dopo = $this->pubblicazioni($id);
        $this->assertCount(1, $dopo);
        $this->assertSame([$prima[0]['id'], $this->voce['2B'], $this->voce['ZPS'], $this->voce['ZPM']],
            [$dopo[0]['id'], $dopo[0]['classe_id'], $dopo[0]['indirizzo_id'], $dopo[0]['subject_id']],
            'la stessa pubblicazione, nella classe nuova, con il corso e la materia di prima');
    }

    #[Test]
    public function pubblicare_e_archiviare_cambiano_lo_stato_della_principale(): void
    {
        $id = $this->crea();
        $this->repo->update($id, $this->docente, ['visibility' => 'published']);
        $this->assertSame('published', $this->pubblicazioni($id)[0]['visibility']);
        $this->repo->update($id, $this->docente, ['visibility' => 'archived', 'archive_visible' => 1]);
        $p = $this->pubblicazioni($id)[0];
        $this->assertSame(['archived', 1], [$p['visibility'], $p['archive_visible']]);
    }

    #[Test]
    public function cambiare_solo_il_titolo_non_tocca_la_principale(): void
    {
        $id = $this->crea();
        $prima = $this->pubblicazioni($id);
        $this->assertCount(1, $prima);
        $this->repo->update($id, $this->docente, ['title' => 'Titolo nuovo ' . uniqid()]);
        $this->assertSame($prima, $this->pubblicazioni($id), 'nessun campo della principale è cambiato');
        // Il verso opposto: lo stato della riga cambia quello della principale.
        $this->repo->update($id, $this->docente, ['visibility' => 'published']);
        $dopo = $this->pubblicazioni($id);
        $this->assertSame([$prima[0]['id'], 'published'], [$dopo[0]['id'], $dopo[0]['visibility']]);
    }

    #[Test]
    public function per_piu_classi_la_principale_resta_in_bozza_e_torna_allo_stato_della_riga_con_una_classe(): void
    {
        // Dalla fase 2 (migrazione 118) «per più classi» vive solo sui contenuti
        // che lo avevano: la principale in bozza, i posti fra le pubblicazioni
        // del docente. Tornando a una classe la principale prende lo stato.
        $id = $this->crea(['visibility' => 'published']);
        $this->repo->update($id, $this->docente, ['publish_scope' => 'classes']);
        $p = $this->pubblicazioni($id);
        $this->assertCount(1, $p);
        $this->assertSame([1, 'draft', $this->voce['2']], [$p[0]['is_primary'], $p[0]['visibility'], $p[0]['classe_id']], 'la terna della riga non arriva agli studenti');

        $this->repo->update($id, $this->docente, ['publish_scope' => 'class']);
        $p = $this->pubblicazioni($id);
        $this->assertCount(1, $p);
        $this->assertSame('published', $p[0]['visibility']);
    }

    #[Test]
    public function una_pubblicazione_derivata_da_un_bersaglio_e_un_difetto_e_la_verifica_lo_dice(): void
    {
        // La migrazione 118 ha convertito i bersagli in pubblicazioni del
        // docente, e la 120 ha tolto la tabella: una pubblicazione con origine
        // 'bersaglio' non ha più nessuno che la scriva, e se comparisse sarebbe
        // un difetto.
        $id = $this->crea(['visibility' => 'published']);
        $this->pdo->query('CALL pub_verifica_allineamento()')->closeCursor();

        $this->pdo->prepare(
            'INSERT INTO content_publications
                (teacher_content_id, institute_id, indirizzo_id, classe_id, subject_id, is_primary, origine, visibility)
             VALUES (?, ?, ?, ?, ?, 0, "bersaglio", "published")'
        )->execute([$id, $this->scuola, $this->voce['ZPS'], $this->voce['2B'], $this->voce['ZPM']]);
        try {
            $this->pdo->query('CALL pub_verifica_allineamento()');
            $this->fail('la verifica doveva segnalare la pubblicazione derivata da un bersaglio');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('derivate dai bersagli 1', $e->getMessage());
        }
    }

    #[Test]
    public function una_pubblicazione_scelta_dal_docente_non_la_tocca_nessuna_modifica_della_riga(): void
    {
        $id = $this->crea(['visibility' => 'published']);
        $this->pdo->prepare(
            'INSERT INTO content_publications
                (teacher_content_id, institute_id, indirizzo_id, classe_id, subject_id, is_primary, origine, visibility)
             VALUES (?, ?, ?, ?, ?, 0, "docente", "draft")'
        )->execute([$id, $this->scuola, $this->voce['ZPS'], $this->voce['2B'], $this->voce['ZPM']]);

        $this->repo->update($id, $this->docente, ['classe' => '2A', 'visibility' => 'archived']);

        $docente = array_values(array_filter(
            $this->pubblicazioni($id),
            fn(array $p): bool => $p['classe_id'] === $this->voce['2B']
        ));
        $this->assertCount(1, $docente, "la pubblicazione del docente c'è ancora");
        $this->assertSame('draft', $docente[0]['visibility'], 'con il suo stato, non quello della riga');
        $this->pdo->query('CALL pub_verifica_allineamento()')->closeCursor();

        // Il vincolo: una pubblicazione del docente non può essere la principale.
        $this->expectException(\PDOException::class);
        $this->pdo->prepare('UPDATE content_publications SET is_primary = 1 WHERE teacher_content_id = ? AND origine = "docente"')
            ->execute([$id]);
    }

    #[Test]
    public function cancellare_il_contenuto_cancella_le_pubblicazioni(): void
    {
        $id = $this->crea(['visibility' => 'published']);
        $this->assertCount(1, $this->pubblicazioni($id));
        $this->repo->delete($id, $this->docente);
        $this->assertSame([], $this->pubblicazioni($id));
    }

    #[Test]
    public function la_verifica_di_allineamento_regge_e_scatta_sullo_stato_e_sulla_scuola(): void
    {
        $id = $this->crea(['visibility' => 'published']);
        $this->pdo->query('CALL pub_verifica_allineamento()')->closeCursor();

        // Una scrittura che aggira l'applicazione: lo stato della riga cambia, la
        // principale no.
        $this->pdo->prepare('UPDATE teacher_content_data SET visibility = "draft" WHERE id = ?')->execute([$id]);
        try {
            $this->pdo->query('CALL pub_verifica_allineamento()');
            $this->fail('la verifica doveva fallire su una principale con uno stato diverso dalla riga');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('ADR-037', $e->getMessage());
            $this->assertStringContainsString('stato diverso dalla riga 1', $e->getMessage());
            $this->assertStringNotContainsString('Duplicate', $e->getMessage(), 'il migratore non deve scambiarla per «già applicato»');
        }
        \App\Support\PostoPrincipale::statoDelContenuto($this->pdo, $id);
        $this->pdo->query('CALL pub_verifica_allineamento()')->closeCursor();

        // La scuola: una pubblicazione spostata in un'altra scuola, con le voci di questa.
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES ("ZZPUBS02", "ALTRA", "Comune Esempio", 1)')->execute();
        $altra = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE content_publications SET institute_id = ? WHERE primary_of_tc = ?')->execute([$altra, $id]);
        try {
            $this->pdo->query('CALL pub_verifica_allineamento()');
            $this->fail('la verifica doveva fallire su una pubblicazione nella scuola sbagliata');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('in una scuola diversa da quella delle loro voci 1', $e->getMessage());
        }
    }
}
