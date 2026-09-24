<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Repositories\Curriculum\AdozioniRepository;
use App\Services\MiurAdozioniImporter;
use App\Support\MiurCurriculumAlias;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-036 — il catalogo dei libri in adozione è dell'istituto.
 *
 * Tre cose da provare nei due versi: l'importatore mette a catalogo i libri
 * del dataset e non li riscrive al secondo giro; il filtro per classi e
 * materie segue la regola dei contenuti («2» copre 2A, «2A» non copre «2B»);
 * la proposta di fonte ha la forma che il registro delle fonti capisce.
 *
 * DB-gated, tutto in transazione → rollback in tearDown.
 */
final class AdozioniLibriTest extends TestCase
{
    private PDO $pdo;
    private int $instId = 0;
    private string $csv = '';
    private bool $inTx = false;

    private const CODICE = 'ZZADOZ0001';

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
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, region, active) VALUES (?, ?, ?, ?, 1)')
            ->execute([self::CODICE, 'ISTITUTO ADOZIONI LIBRI', 'Comune Esempio', 'LOMBARDIA']);
        $this->instId = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->csv !== '' && is_file($this->csv)) {
            @unlink($this->csv);
        }
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** Il tracciato con le colonne del libro, come nel dataset vero. */
    private function csvConLibri(): string
    {
        $this->csv = (string)tempnam(sys_get_temp_dir(), 'adozlib') . '.csv';
        $testa = "ANNOSCOLASTICO;CODICESCUOLA;TIPOGRADOSCUOLA;ANNOCORSO;SEZIONEANNO;COMBINAZIONE;DISCIPLINA;CODICEISBN;AUTORI;TITOLO;SOTTOTITOLO;VOLUME;EDITORE;PREZZO;NUOVAADOZ;DAACQUIST;CONSIGLIATO\n";
        $r = static fn(string $anno, string $sez, string $disc, string $isbn, string $titolo, string $vol, string $ed): string =>
            "2026/2027;" . self::CODICE . ";NO;$anno;$sez;LICEO SCIENTIFICO;$disc;$isbn;ROSSI M.;$titolo;;$vol;$ed;29,90;No;Si;No\n";
        file_put_contents($this->csv, $testa
            . $r('2', 'A', 'MATEMATICA', '9788800000001', 'MATEMATICA BLU', '2', 'ZANICHELLI')
            . $r('2', 'A', 'FISICA',     '9788800000002', 'FISICA LEZIONE', '1', 'PEARSON')
            . $r('2', 'B', 'MATEMATICA', '9788800000001', 'MATEMATICA BLU', '2', 'ZANICHELLI')
            . $r('2', 'A', 'MATEMATICA', '9788800000001', 'MATEMATICA BLU', '2', 'ZANICHELLI'));
        return $this->csv;
    }

    private function importer(): MiurAdozioniImporter
    {
        return new MiurAdozioniImporter($this->pdo, new MiurCurriculumAlias([]), new MiurCurriculumAlias([]));
    }

    #[Test]
    public function l_import_mette_a_catalogo_i_libri_e_non_li_riscrive(): void
    {
        $imp = $this->importer();
        $plan = $imp->scan($this->csvConLibri(), self::CODICE);
        $this->assertSame(3, $plan['stats']['libri'], 'tre adozioni distinte: la quarta riga e\' una ripetizione');
        $this->assertSame(0, (new AdozioniRepository($this->pdo))->conta($this->instId), 'scan non scrive');

        $fatte = $imp->apply($plan);
        $this->assertSame(3, $fatte['libri']);
        $this->assertSame(3, (new AdozioniRepository($this->pdo))->conta($this->instId));

        $piano2 = $imp->scan($this->csv, self::CODICE);
        $this->assertSame(0, $piano2['stats']['libri'], 'al secondo giro niente da scrivere');
        $this->assertSame(3, $piano2['istituti'][0]['libri']['gia_a_catalogo']);
        $this->assertSame(0, $imp->apply($piano2)['libri']);

        // Le voci del vocabolario scritte dall'import dichiarano da dove vengono.
        $st = $this->pdo->prepare('SELECT DISTINCT origine FROM curriculum_entries WHERE institute_id = ?');
        $st->execute([$this->instId]);
        $this->assertSame(['miur'], $st->fetchAll(PDO::FETCH_COLUMN));

        // Il libro porta le sigle proposte nello stesso piano.
        $libri = (new AdozioniRepository($this->pdo))->perClassiEMaterie($this->instId, []);
        $mat = array_values(array_filter($libri, static fn(array $l): bool => $l['disciplina'] === 'MATEMATICA'));
        $this->assertNotEmpty($mat);
        $this->assertSame('MAT', $mat[0]['materia']);
        $this->assertSame('SCI', $mat[0]['indirizzo']);
    }

    #[Test]
    public function il_filtro_per_classi_e_materie_segue_la_regola_dei_contenuti(): void
    {
        $repo = new AdozioniRepository($this->pdo);
        $riga = static fn(string $classe, string $materia, string $isbn): array => [
            'anno_scolastico' => '2026/2027', 'classe' => $classe, 'materia' => $materia,
            'disciplina' => $materia, 'isbn' => $isbn, 'titolo' => "Libro $classe $materia",
        ];
        $repo->inserisci($this->instId, $riga('2A', 'MAT', '9788800000010'), 'istituto');
        $repo->inserisci($this->instId, $riga('2B', 'MAT', '9788800000011'), 'istituto');
        $repo->inserisci($this->instId, $riga('3A', 'MAT', '9788800000012'), 'istituto');
        $repo->inserisci($this->instId, $riga('2A', 'FIS', '9788800000013'), 'istituto');

        $classi = static fn(array $libri): array => array_values(array_unique(array_column($libri, 'classe')));

        $this->assertSame(['2A', '2B'], $classi($repo->perClassiEMaterie($this->instId, ['2'], ['MAT'])), '«2» copre le sue sezioni');
        $this->assertSame(['2A'], $classi($repo->perClassiEMaterie($this->instId, ['2A'], ['MAT'])), '«2A» solo se stessa');
        $this->assertSame(['2A'], $classi($repo->perClassiEMaterie($this->instId, ['2A'])), 'senza materie, tutte le discipline della classe');
        $this->assertCount(2, $repo->perClassiEMaterie($this->instId, ['2A']));
        $this->assertSame([], $repo->perClassiEMaterie($this->instId, ['2A'], ['ITA']), 'una materia che non ha libri');
        $this->assertCount(4, $repo->perClassiEMaterie($this->instId, []), 'senza classi, tutto l\'istituto');

        $this->assertSame(0, $repo->inserisci($this->instId, $riga('2A', 'MAT', '9788800000010'), 'istituto'), 'un doppione non entra');
    }

    #[Test]
    public function la_proposta_di_fonte_ha_la_forma_del_registro(): void
    {
        $p = AdozioniRepository::propostaDiFonte([
            'id' => 7, 'titolo' => 'Matematica multimediale.blu', 'sottotitolo' => 'Con Tutor',
            'volume' => '2', 'editore' => 'Zanichelli', 'autori' => 'Bergamini, Barozzi', 'isbn' => '9788808123456',
        ]);
        $this->assertSame('Matematica multimediale.blu — Con Tutor', $p['book']);
        $this->assertSame('Vol.2 - ZANICHELLI', $p['volume'], 'editore in coda: e\' cosi\' che SourcesRegistry lo separa');
        $this->assertSame('Bergamini, Barozzi', $p['authors']);
        $this->assertSame('9788808123456', $p['isbn']);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,64}$/', $p['key']);
        $this->assertStringContainsString('matematica_multimediale', $p['key']);

        $legacy = \App\Support\Sources\SourcesRegistry::toLegacyDict([$p]);
        $this->assertSame('ZANICHELLI', $legacy[$p['key']]['publisher']);
        $this->assertSame('Vol.2', $legacy[$p['key']]['volume']);
    }
}
