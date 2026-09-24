<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\VerificaDocumentRepository;
use App\Services\Contenuti\CopiaVerifica;
use App\Services\Contenuti\DoveValeVerifica;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-037, fase 3 — «Dove vale» e «Duplica in…» per le verifiche.
 *
 * Due scuole con le stesse sigle (corso ZWS, sezione 2A, materia ZWM). Il
 * docente lavora in tutte e due e ha spuntato le voci; un collega lavora nella
 * A. Una verifica di due varianti nasce nella A.
 *
 * Si prova che un posto vale per tutte le varianti, che lo stato della
 * principale lo sceglie il docente e la principale non si toglie, che una
 * versione salvata dopo si vede «in parte», gli invarianti S1, S2, S3 e il
 * registro (S6); e che la copia riscrive ogni file in blob nuovi (S5), nasce
 * in bozza nel posto scelto e non lascia niente se fallisce.
 *
 * Fixture isolata in transazione (rollback in tearDown); i blob stanno in un
 * deposito in memoria.
 */
final class DoveValeVerificaTest extends TestCase
{
    private PDO $pdo;
    private bool $inTx = false;
    private int $scuolaA = 0;
    private int $scuolaB = 0;
    private int $altraScuola = 0;
    private int $docente = 0;
    private int $collega = 0;
    /** @var array<string,int> */
    private array $voce = [];
    /** @var list<int> */
    private array $varianti = [];
    private string $titolo = '';
    private DoveValeVerifica $doveVale;

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
            // La 119 si riconosce dal registro delle migrazioni: la sua procedura di
            // ricalcolo l'ha tolta la 122 (ADR-037, fase 4c-2), e sondare quella
            // avrebbe fatto saltare queste prove in silenzio.
            $this->pdo->query('SELECT 1 FROM schema_migrations WHERE filename LIKE "119\_%"')->fetchColumn()
                ?: throw new \RuntimeException('migrazione 119 non applicata');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migrazione 119 non disponibili: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;
        $_SESSION = [];
        \App\Support\CurriculumLookup::resetCache();

        $ist = $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)');
        $ist->execute(['ZZDWA001', 'SCUOLA A DOVE VALE VERIFICHE', 'Comune Esempio']);
        $this->scuolaA = (int)$this->pdo->lastInsertId();
        $ist->execute(['ZZDWB001', 'SCUOLA B DOVE VALE VERIFICHE', 'Comune Esempio']);
        $this->scuolaB = (int)$this->pdo->lastInsertId();
        $ist->execute(['ZZDWC001', 'SCUOLA NON SUA VERIFICHE', 'Altro Comune']);
        $this->altraScuola = (int)$this->pdo->lastInsertId();

        $voce = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, shared_with_pool, origine)
             VALUES (?, ?, ?, ?, ?, 1, 0, "istituto")'
        );
        foreach (['A' => $this->scuolaA, 'B' => $this->scuolaB, 'C' => $this->altraScuola] as $s => $iid) {
            foreach ([['indirizzi', 'ZWS', null], ['classi', '2A', 'ZWS'], ['classi', '3A', 'ZWS'], ['materie', 'ZWM', null]] as [$kind, $code, $corso]) {
                $voce->execute([$kind, $iid, $code, $code, $corso]);
                $this->voce["$s:$code"] = (int)$this->pdo->lastInsertId();
            }
        }

        $this->docente = $this->utente('zzdw_doc', [$this->scuolaA, $this->scuolaB]);
        $this->collega = $this->utente('zzdw_coll', [$this->scuolaA]);
        $spunta = $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active) VALUES (?, ?, 1)');
        foreach ($this->voce as $k => $id) {
            if (!str_starts_with($k, 'C:')) {
                $spunta->execute([$id, $this->docente]);
            }
        }

        $this->titolo = 'Equazioni ' . uniqid();
        $pacchetto = strtoupper(substr(bin2hex(random_bytes(13)), 0, 26));
        foreach (['A_SOL', 'A_NOR'] as $variante) {
            $this->varianti[] = (new VerificaDocumentRepository())->create([
                'teacher_id' => $this->docente, 'materia' => 'ZWM', 'indirizzo' => 'ZWS', 'classe' => '2A',
                'title' => "{$this->titolo} — {$variante}", 'batch_id' => $pacchetto, 'variant' => $variante,
                'exercise_ids' => [], 'source_type' => 'book_textbook',
            ]);
        }
        $this->doveVale = new DoveValeVerifica();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $_SESSION = [];
        \App\Core\Config::set('app.deployment_mode', 'single');
        \App\Support\CurriculumLookup::resetCache();
    }

    /** @param list<int> $scuole */
    private function utente(string $username, array $scuole): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", "DoveValeVerifiche", ?, "x", "approved", 1, NOW())'
        )->execute([$username, $username . '@example.invalid']);
        $id = (int)$this->pdo->lastInsertId();
        foreach ($scuole as $s) {
            $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$id, $s]);
        }
        return $id;
    }

    private function nellaB(string $stato = 'published'): int
    {
        return $this->doveVale->aggiungi(
            $this->varianti[0],
            $this->docente,
            $this->scuolaB,
            $this->voce['B:ZWS'],
            $this->voce['B:2A'],
            $this->voce['B:ZWM'],
            $stato
        );
    }

    /** @return array<string,mixed> */
    private function posto(bool $principale, ?int $scuola = null): array
    {
        foreach ($this->doveVale->elenco($this->varianti[1], $this->docente)['posti'] as $p) {
            if ($p['principale'] === $principale && ($scuola === null || $p['scuola_id'] === $scuola)) {
                return $p;
            }
        }
        $this->fail('posto non trovato');
    }

    private function codice(callable $fn): string
    {
        try {
            $fn();
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
        return 'nessun errore';
    }

    // ── Dove vale ────────────────────────────────────────────────────────

    #[Test]
    public function un_posto_aggiunto_vale_per_tutte_le_varianti_e_va_a_registro(): void
    {
        $this->assertSame(2, $this->nellaB(), 'una pubblicazione per variante');
        $elenco = $this->doveVale->elenco($this->varianti[0], $this->docente);
        $this->assertSame(2, $elenco['verifica']['varianti']);
        $this->assertSame($this->titolo, $elenco['verifica']['titolo'], 'il titolo senza il suffisso di variante');
        $this->assertCount(2, $elenco['posti'], 'la principale e il posto nella B');
        $b = $this->posto(false, $this->scuolaB);
        $this->assertSame(2, $b['varianti']);
        $this->assertSame('published', $b['stato']);

        $registro = $this->pdo->prepare(
            'SELECT details_json FROM audit_activity_log
              WHERE action = "pubblicazione_aggiunta" AND subject_type = "verifica_documents" AND subject_id = ?
              ORDER BY id DESC LIMIT 1'
        );
        $registro->execute([(string)$this->varianti[0]]);
        $dettagli = json_decode((string)$registro->fetchColumn(), true);
        $this->assertEqualsCanonicalizing($this->varianti, $dettagli['varianti'] ?? null, 'a registro con gli id (S6)');
        $this->assertStringNotContainsString($this->titolo, json_encode($dettagli), 'mai il contenuto');

        $this->assertSame('gia_pubblicato', $this->codice(fn() => $this->nellaB()));
    }

    #[Test]
    public function solo_il_proprietario_solo_nelle_sue_scuole_solo_con_le_voci_di_quella_scuola(): void
    {
        $this->assertSame('non_trovato', $this->codice(fn() => $this->doveVale->aggiungi(
            $this->varianti[0], $this->collega, $this->scuolaA, $this->voce['A:ZWS'], $this->voce['A:3A'], $this->voce['A:ZWM']
        )), 'S1: per il collega la verifica non esiste');
        $this->assertSame('non_trovato', $this->codice(fn() => $this->doveVale->elenco($this->varianti[0], $this->collega)));
        $this->assertSame('scuola_non_tua', $this->codice(fn() => $this->doveVale->aggiungi(
            $this->varianti[0], $this->docente, $this->altraScuola, $this->voce['C:ZWS'], $this->voce['C:2A'], $this->voce['C:ZWM']
        )), 'S2');
        $this->assertSame('voce_non_valida', $this->codice(fn() => $this->doveVale->aggiungi(
            $this->varianti[0], $this->docente, $this->scuolaB, $this->voce['B:ZWS'], $this->voce['A:3A'], $this->voce['B:ZWM']
        )), 'S3: la classe della A non vale nella B');
        $this->assertSame(2, $this->doveVale->aggiungi(
            $this->varianti[0], $this->docente, $this->scuolaA, $this->voce['A:ZWS'], $this->voce['A:3A'], $this->voce['A:ZWM']
        ), 'e con le voci giuste sì');
    }

    #[Test]
    public function lo_stato_della_principale_lo_sceglie_il_docente_e_la_principale_non_si_toglie(): void
    {
        $principale = $this->posto(true);
        $this->assertSame('draft', $principale['stato']);
        $this->assertSame(2, $this->doveVale->impostaStato($this->varianti[1], $principale['id'], $this->docente, 'published'));
        $this->assertSame('published', $this->posto(true)['stato'], 'per tutte le varianti');
        $this->assertSame('principale_non_si_toglie', $this->codice(fn() => $this->doveVale->togli($this->varianti[0], $principale['id'], $this->docente)));

        $this->nellaB('draft');
        $b = $this->posto(false, $this->scuolaB);
        $this->assertSame(2, $this->doveVale->togli($this->varianti[0], $b['id'], $this->docente), 'tolto da tutte le varianti');
        $this->assertCount(1, $this->doveVale->elenco($this->varianti[0], $this->docente)['posti']);
        $this->assertSame('stato_non_valido', $this->codice(fn() => $this->doveVale->impostaStato($this->varianti[0], $principale['id'], $this->docente, 'pubblico')));
    }

    #[Test]
    public function una_versione_salvata_dopo_si_vede_in_parte_e_una_pubblicazione_di_un_altra_verifica_non_si_tocca(): void
    {
        $principale = $this->posto(true);
        $this->doveVale->impostaStato($this->varianti[0], $principale['id'], $this->docente, 'published');
        $nuova = (new VerificaDocumentRepository())->create([
            'teacher_id' => $this->docente, 'materia' => 'ZWM', 'indirizzo' => 'ZWS', 'classe' => '2A',
            'title' => "{$this->titolo} — B_SOL", 'batch_id' => strtoupper(substr(bin2hex(random_bytes(13)), 0, 26)),
            'variant' => 'B_SOL', 'version_label' => 'v02', 'exercise_ids' => [],
        ]);
        $p = $this->posto(true);
        $this->assertSame(3, $p['varianti'], 'la versione nuova è della stessa verifica');
        $this->assertSame('misto', $p['stato'], 'e nasce in bozza');

        $altra = (new VerificaDocumentRepository())->create([
            'teacher_id' => $this->docente, 'materia' => 'ZWM', 'indirizzo' => 'ZWS', 'classe' => '2A',
            'title' => 'Un\'altra verifica — A_SOL', 'variant' => 'A_SOL', 'exercise_ids' => [],
        ]);
        $st = $this->pdo->prepare('SELECT id FROM content_publications WHERE primary_of_vd = ?');
        $st->execute([$altra]);
        $pubAltra = (int)$st->fetchColumn();
        $this->assertSame('non_trovato', $this->codice(fn() => $this->doveVale->impostaStato($this->varianti[0], $pubAltra, $this->docente, 'published')));
        $this->assertNotContains($altra, $this->doveVale->righe($nuova, $this->docente));
    }

    #[Test]
    public function un_profilo_limitato_in_modalita_istituto_non_aggiunge_posti(): void
    {
        \App\Core\Config::set('app.deployment_mode', 'institute');
        $this->pdo->prepare('INSERT INTO teacher_capability_overrides (user_id, capabilities) VALUES (?, ?)')
            ->execute([$this->docente, json_encode(['max_visibility' => 'class'])]);
        $this->assertSame('non_consentito', $this->codice(fn() => $this->nellaB()));

        // Il verso opposto: lo stesso profilo con «più classi» consentito pubblica.
        $this->pdo->prepare('UPDATE teacher_capability_overrides SET capabilities = ? WHERE user_id = ?')
            ->execute([json_encode(['max_visibility' => 'classes']), $this->docente]);
        $this->doveVale = new DoveValeVerifica();
        $this->assertSame(2, $this->nellaB());
    }

    // ── Duplica in… ──────────────────────────────────────────────────────

    /** Il deposito in memoria, con i file della verifica: un blob condiviso fra le varianti e un PDF. */
    private function deposito(): DepositoInMemoria
    {
        $deposito = new DepositoInMemoria();
        $comune = $deposito->put($this->docente, "\\input{comune}\n");
        $pdf = $deposito->put($this->docente, "%PDF-1.4 soluzioni\n");
        foreach ($this->varianti as $i => $v) {
            $proprio = $deposito->put($this->docente, "\\documentclass{article} variante {$i}\n");
            $manifest = [
                ['path' => 'main.tex', 'blob_path' => $proprio, 'blob_kv' => 1, 'sha256' => str_repeat('a', 64), 'size' => 30],
                ['path' => 'comune.tex', 'blob_path' => $comune, 'blob_kv' => 1, 'sha256' => str_repeat('b', 64), 'size' => 16],
            ];
            $this->pdo->prepare('UPDATE verifica_documents_data SET tex_files = ?, selection_json = ?, shared_with_pool = 1 WHERE id = ?')
                ->execute([json_encode($manifest), json_encode(['iis' => 'ZWS', 'cls' => '2A', 'mater' => 'ZWM', 'problems' => []]), $v]);
        }
        (new VerificaDocumentRepository())->attachPdf($this->varianti[0], $pdf, 1, 19, 'soluzioni.pdf');
        $deposito->scritti = [];
        return $deposito;
    }

    #[Test]
    public function la_copia_riscrive_ogni_file_in_blob_nuovi_e_nasce_in_bozza_nel_posto_scelto(): void
    {
        $deposito = $this->deposito();
        $copia = new CopiaVerifica(store: $deposito);
        $ids = $copia->duplica($this->varianti[1], $this->docente, $this->scuolaB, $this->voce['B:ZWS'], $this->voce['B:2A'], $this->voce['B:ZWM']);
        $this->assertCount(2, $ids, 'tutte le varianti del pacchetto');
        $this->assertCount(4, $deposito->scritti, 'due file propri, il file comune una volta sola, il PDF');

        $repo = new VerificaDocumentRepository();
        $originali = [];
        $vecchi = [];
        foreach ($this->varianti as $v) {
            $o = $repo->find($v);
            $originali[$o['variant']] = $o;
            foreach ($o['tex_files'] as $f) {
                $vecchi[] = $f['blob_path'];
            }
            if ($o['pdf_blob_path'] !== null) {
                $vecchi[] = $o['pdf_blob_path'];
            }
        }
        $pacchetti = [];
        foreach ($ids as $id) {
            $c = $repo->find($id);
            $o = $originali[$c['variant']];
            $pacchetti[] = $c['batch_id'];
            $this->assertSame("{$this->titolo} (copia) — {$o['variant']}", $c['title']);
            $this->assertSame($this->voce['B:ZWM'], (int)$c['materia_id']);
            $this->assertSame($this->voce['B:2A'], (int)$c['classe_id']);
            $this->assertSame('book_textbook', $c['source_type'], 'la classificazione passa alla copia (S5)');
            $this->assertSame(0, (int)$c['shared_with_pool'], 'la copia nasce privata');
            $this->assertNotSame($o['batch_id'], $c['batch_id']);
            $sel = json_decode((string)$c['selection_json'], true);
            $this->assertSame('2A', $sel['cls']);
            foreach ($c['tex_files'] as $k => $f) {
                $this->assertNotContains($f['blob_path'], $vecchi, 'nessun blob condiviso con l\'originale');
                $this->assertSame($deposito->get($this->docente, $o['tex_files'][$k]['blob_path']), $deposito->get($this->docente, $f['blob_path']));
            }
            $st = $this->pdo->prepare('SELECT institute_id, visibility FROM content_publications WHERE primary_of_vd = ?');
            $st->execute([$id]);
            $this->assertSame(['institute_id' => $this->scuolaB, 'visibility' => 'draft'], array_map(
                static fn($v) => is_numeric($v) ? (int)$v : $v,
                $st->fetch(PDO::FETCH_ASSOC)
            ), 'la principale nella B, in bozza');
        }
        $this->assertCount(1, array_unique($pacchetti), 'un pacchetto nuovo, uno solo');
        $c0 = $repo->find($ids[0]);
        $c1 = $repo->find($ids[1]);
        $this->assertSame($c0['tex_files'][1]['blob_path'], $c1['tex_files'][1]['blob_path'], 'il file comune resta comune nella copia');
        $pdfCopia = $c0['pdf_blob_path'] ?: $c1['pdf_blob_path'];
        $this->assertNotContains($pdfCopia, $vecchi);
        $this->assertStringStartsWith('%PDF-', $deposito->get($this->docente, (string)$pdfCopia));

        $registro = $this->pdo->prepare('SELECT details_json FROM audit_activity_log WHERE action = "verifica_duplicata" ORDER BY id DESC LIMIT 1');
        $registro->execute();
        $dettagli = json_decode((string)$registro->fetchColumn(), true);
        $this->assertSame($this->varianti[1], $dettagli['originale'] ?? null, 'a registro con gli id (S6)');
    }

    #[Test]
    public function la_copia_non_parte_per_chi_non_e_il_proprietario_e_se_fallisce_non_lascia_niente(): void
    {
        $deposito = $this->deposito();
        $this->assertSame('non_trovato', $this->codice(fn() => (new CopiaVerifica(store: $deposito))->duplica(
            $this->varianti[0], $this->collega, $this->scuolaA, $this->voce['A:ZWS'], $this->voce['A:2A'], $this->voce['A:ZWM']
        )));
        $this->assertSame('scuola_non_tua', $this->codice(fn() => (new CopiaVerifica(store: $deposito))->duplica(
            $this->varianti[0], $this->docente, $this->altraScuola, $this->voce['C:ZWS'], $this->voce['C:2A'], $this->voce['C:ZWM']
        )), 'S2: non in una scuola che non è sua');
        $this->assertSame([], $deposito->scritti, 'niente scritto');

        $contaRighe = fn(): int => (int)$this->pdo->query('SELECT COUNT(*) FROM verifica_documents_data WHERE teacher_id = ' . $this->docente)->fetchColumn();
        $prima = $contaRighe();
        $deposito->falliscaAlPut = 3;
        $this->assertSame('copia_non_riuscita', $this->codice(fn() => (new CopiaVerifica(store: $deposito))->duplica(
            $this->varianti[0], $this->docente, $this->scuolaB, $this->voce['B:ZWS'], $this->voce['B:2A'], $this->voce['B:ZWM']
        )));
        $this->assertSame($prima, $contaRighe(), 'nessuna variante a metà');
        $this->assertSame([], array_diff($deposito->scritti, $deposito->cancellati), 'i blob scritti prima del guasto sono tolti');
    }
}
