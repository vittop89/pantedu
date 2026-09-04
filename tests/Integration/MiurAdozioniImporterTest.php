<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\MiurAdozioniImporter;
use App\Support\MiurCurriculumAlias;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Import degli indirizzi e delle sezioni dal dataset MIUR delle adozioni.
 *
 * Il punto delicato non e' il parsing del CSV ma la separazione fra le due
 * fasi: scan() deve poter essere guardato senza che nulla sia gia' successo,
 * altrimenti la conferma umana in mezzo non serve a niente.
 *
 * DB-gated: skip se il DB non e' disponibile. Tutto in transazione → rollback
 * in tearDown, nessuna scoria nel DB.
 */
final class MiurAdozioniImporterTest extends TestCase
{
    private PDO $pdo;
    private int $instId = 0;
    private string $csv = '';
    private bool $inTx = false;

    private const CODICE = 'ZZTEST0001';

    protected function setUp(): void
    {
        $basePath = dirname(__DIR__, 2);
        if (is_file($basePath . '/.env')) {
            \Dotenv\Dotenv::createMutable($basePath)->safeLoad();
        }
        if (is_file($basePath . '/.env.local')) {
            \Dotenv\Dotenv::createMutable($basePath, '.env.local')->safeLoad();
        }
        \App\Core\Config::load($basePath . '/app/Config');

        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB connection failed: ' . $e->getMessage());
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;

        $ins = $this->pdo->prepare(
            'INSERT INTO institutes (code, name, city, region, active) VALUES (?, ?, ?, ?, 1)'
        );
        $ins->execute([self::CODICE, 'ISTITUTO DI PROVA ADOZIONI', 'Comune Esempio', 'PIEMONTE']);
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

    /**
     * Il tracciato reale ha una trentina di colonne (dati del libro): qui
     * bastano quelle che l'importatore legge davvero, nell'ordine in cui non
     * capitano mai — cosi' se qualcuno si mettesse a leggere per indice
     * invece che per nome, il test lo prende.
     */
    private function scriviCsv(string $righe): string
    {
        $this->csv = (string)tempnam(sys_get_temp_dir(), 'adoz') . '.csv';
        file_put_contents(
            $this->csv,
            "DISCIPLINA;SEZIONEANNO;CODICEISBN;CODICESCUOLA;TIPOGRADOSCUOLA;COMBINAZIONE;ANNOCORSO\n" . $righe
        );
        return $this->csv;
    }

    private function riga(string $sez, string $comb, string $anno, string $grado = 'NO', string $scuola = self::CODICE): string
    {
        return "MATEMATICA;$sez;9788800000000;$scuola;$grado;$comb;$anno\n";
    }

    private function importer(): MiurAdozioniImporter
    {
        // Registro alias vuoto: qui si prova il derivatore, non le decisioni
        // prese a mano su descrizioni specifiche.
        return new MiurAdozioniImporter($this->pdo, new MiurCurriculumAlias([]));
    }

    /** @return array{indirizzi:int,classi:int} */
    private function conta(): array
    {
        $st = $this->pdo->prepare(
            'SELECT kind, COUNT(*) AS n FROM curriculum_entries
              WHERE institute_id = ? AND owner_user_id IS NULL GROUP BY kind'
        );
        $st->execute([$this->instId]);
        $out = ['indirizzi' => 0, 'classi' => 0, 'materie' => 0];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['kind']] = (int)$r['n'];
        }
        return $out;
    }

    #[Test]
    public function scan_non_scrive_niente(): void
    {
        $csv = $this->scriviCsv(
            $this->riga('A', 'LICEO SCIENTIFICO', '1')
            . $this->riga('B', 'LICEO SCIENTIFICO', '1')
        );
        $prima = $this->conta();

        $plan = $this->importer()->scan($csv, self::CODICE);

        $this->assertSame(1, $plan['stats']['indirizzi']);
        $this->assertSame(2, $plan['stats']['sezioni']);
        $this->assertSame($prima, $this->conta(), 'scan() ha toccato il database');
    }

    #[Test]
    public function apply_scrive_esattamente_il_piano(): void
    {
        $csv = $this->scriviCsv(
            $this->riga('A', 'LICEO SCIENTIFICO', '1')
            . $this->riga('B', 'LICEO SCIENTIFICO', '1')
            . $this->riga('A', 'LICEO ARTISTICO', '2')
        );
        $imp  = $this->importer();
        $plan = $imp->scan($csv, self::CODICE);
        $fatte = $imp->apply($plan);

        $this->assertSame(2, $fatte['indirizzi']);
        $this->assertSame(3, $fatte['sezioni']);
        // Le materie entrano nello stesso piano: la riga del CSV porta
        // DISCIPLINA accanto alla sezione, e sarebbe un secondo import inutile
        // andarsele a riprendere dopo.
        $this->assertSame(['indirizzi' => 2, 'classi' => 3, 'materie' => 1], $this->conta());

        // La sezione porta l'indirizzo: e' l'accoppiata che rende questo
        // dataset l'unico utile: 2A sta nell'artistico, non nello scientifico.
        $st = $this->pdo->prepare(
            'SELECT code, indirizzo FROM curriculum_entries
              WHERE kind = "classi" AND institute_id = ? AND owner_user_id IS NULL ORDER BY code'
        );
        $st->execute([$this->instId]);
        $classi = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $classi[(string)$r['code']] = (string)$r['indirizzo'];
        }
        $this->assertSame(['1A', '1B', '2A'], array_keys($classi));
        $this->assertSame($classi['1A'], $classi['1B'], '1A e 1B sono lo stesso indirizzo');
        $this->assertNotSame($classi['1A'], $classi['2A'], '2A e\' di un altro indirizzo');
    }

    #[Test]
    public function un_indirizzo_gia_a_registro_non_viene_duplicato(): void
    {
        // A registro c'e' "Scientifico", il MIUR scrive "LICEO SCIENTIFICO":
        // stesso indirizzo. Il confronto e' sulle parole proprio per questo.
        $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, owner_user_id, code, label, active, shared_with_pool)
             VALUES ("indirizzi", ?, NULL, "SCI", "Scientifico", 1, 0)'
        )->execute([$this->instId]);

        $csv = $this->scriviCsv($this->riga('A', 'LICEO SCIENTIFICO', '1'));
        $plan = $this->importer()->scan($csv, self::CODICE);

        $this->assertSame(0, $plan['stats']['indirizzi'], 'ha proposto un doppione');
        $this->assertSame('esistente', $plan['istituti'][0]['indirizzi'][0]['stato']);
        $this->assertSame('SCI', $plan['istituti'][0]['indirizzi'][0]['code']);
        $this->assertSame(['1A'], $plan['istituti'][0]['indirizzi'][0]['sezioni_nuove']);
    }

    #[Test]
    public function una_sezione_senza_indirizzo_viene_agganciata(): void
    {
        // Righe create prima della migration 100: senza indirizzo risultano
        // trasversali e ricompaiono sotto qualunque corso.
        $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, owner_user_id, code, label, indirizzo, active, shared_with_pool)
             VALUES ("classi", ?, NULL, "1A", "Classe 1A", NULL, 1, 0)'
        )->execute([$this->instId]);

        $csv = $this->scriviCsv($this->riga('A', 'LICEO SCIENTIFICO', '1'));
        $imp  = $this->importer();
        $plan = $imp->scan($csv, self::CODICE);

        $this->assertSame(0, $plan['stats']['sezioni']);
        $this->assertSame(1, $plan['stats']['sistemate']);

        $imp->apply($plan);
        $st = $this->pdo->prepare(
            'SELECT indirizzo FROM curriculum_entries
              WHERE kind = "classi" AND institute_id = ? AND code = "1A"'
        );
        $st->execute([$this->instId]);
        $this->assertNotNull($st->fetchColumn(), 'la sezione e\' rimasta senza indirizzo');
    }

    #[Test]
    public function primaria_e_medie_non_producono_indirizzi(): void
    {
        // Per la primaria COMBINAZIONE contiene il tempo scuola, non un
        // indirizzo di studio: importarlo creerebbe indirizzi inventati.
        $csv = $this->scriviCsv(
            $this->riga('A', 'CORSO A ORARIO ORDINARIO', '1', 'EE')
            . $this->riga('B', 'TEMPO PIENO', '2', 'MM')
            . $this->riga('C', 'LICEO SCIENTIFICO', '1')
        );
        $plan = $this->importer()->scan($csv, self::CODICE);

        $this->assertSame(1, $plan['stats']['indirizzi']);
        $this->assertSame(1, $plan['stats']['sezioni']);
        $this->assertCount(1, $plan['istituti'][0]['indirizzi']);
    }

    #[Test]
    public function le_scuole_non_a_tabella_vengono_ignorate(): void
    {
        $csv = $this->scriviCsv(
            $this->riga('A', 'LICEO SCIENTIFICO', '1')
            . $this->riga('Z', 'LICEO CLASSICO', '1', 'NO', 'VBPS99999Z')
        );
        $plan = $this->importer()->scan($csv, self::CODICE);

        $this->assertCount(1, $plan['istituti']);
        $this->assertSame(self::CODICE, $plan['istituti'][0]['code']);
        $this->assertSame(1, $plan['stats']['indirizzi']);
    }

    #[Test]
    public function un_file_di_un_altra_regione_e_un_errore_esplicito(): void
    {
        // Il fallimento piu' probabile in produzione: si scarica il dataset
        // della regione sbagliata e il file non contiene la scuola. Meglio
        // dirlo che rispondere "0 modifiche", che sembra un successo.
        $csv = $this->scriviCsv($this->riga('A', 'LICEO SCIENTIFICO', '1', 'NO', 'RMPS12345A'));
        $this->expectExceptionMessageMatches('/regione/');
        $this->importer()->scan($csv, self::CODICE);
    }

    #[Test]
    public function un_csv_senza_le_colonne_giuste_e_un_errore_esplicito(): void
    {
        $this->csv = (string)tempnam(sys_get_temp_dir(), 'adoz') . '.csv';
        file_put_contents($this->csv, "FOO;BAR\n1;2\n");
        $this->expectExceptionMessageMatches('/CODICESCUOLA/');
        $this->importer()->scan($this->csv, self::CODICE);
    }
}
