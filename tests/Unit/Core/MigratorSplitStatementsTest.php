<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Migrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Come si spezza un file .sql negli statement da eseguire.
 *
 * Non e' pignoleria: il vecchio splitter faceva `preg_split` su `;` dopo aver
 * tolto le righe che iniziano per `--`, e sbagliava in modi che non danno
 * errore — produceva statement diversi da quelli scritti e li eseguiva.
 * Ognuno di questi test e' un modo di sbagliare che era raggiungibile
 * scrivendo una migration normale.
 */
final class MigratorSplitStatementsTest extends TestCase
{
    #[Test]
    public function separa_gli_statement_semplici(): void
    {
        $this->assertSame(
            ['SELECT 1', 'SELECT 2'],
            Migrator::splitStatements("SELECT 1;\nSELECT 2;\n")
        );
    }

    #[Test]
    public function il_punto_e_virgola_finale_non_e_obbligatorio(): void
    {
        $this->assertSame(['SELECT 1'], Migrator::splitStatements('SELECT 1'));
    }

    #[Test]
    public function un_punto_e_virgola_dentro_una_stringa_non_spezza(): void
    {
        // Il caso piu' insidioso: lo statement veniva tagliato a meta' e le due
        // parti erano entrambe SQL non valido.
        $sql = "INSERT INTO t (msg) VALUES ('primo; secondo');\nSELECT 1;";
        $this->assertSame(
            ["INSERT INTO t (msg) VALUES ('primo; secondo')", 'SELECT 1'],
            Migrator::splitStatements($sql)
        );
    }

    #[Test]
    public function una_riga_che_inizia_per_trattini_dentro_una_stringa_resta(): void
    {
        // Il vecchio splitter toglieva la riga PRIMA di guardare le stringhe:
        // cancellava contenuto di un dato, non un commento.
        $sql = "INSERT INTO t (msg) VALUES ('riga1\n-- non e un commento\nriga3');";
        $out = Migrator::splitStatements($sql);
        $this->assertCount(1, $out);
        $this->assertStringContainsString('-- non e un commento', $out[0]);
    }

    #[Test]
    public function i_commenti_veri_spariscono(): void
    {
        $sql = "-- intestazione\nSELECT 1;\n# altro commento\nSELECT 2;\n/* blocco\n   su piu righe */\nSELECT 3;";
        $this->assertSame(['SELECT 1', 'SELECT 2', 'SELECT 3'], Migrator::splitStatements($sql));
    }

    #[Test]
    public function un_apostrofo_escapato_non_chiude_la_stringa(): void
    {
        foreach ([
            "SELECT 'l\\'apostrofo; qui';",   // escape con backslash
            "SELECT 'l''apostrofo; qui';",    // apostrofo raddoppiato
        ] as $sql) {
            $this->assertCount(1, Migrator::splitStatements($sql), $sql);
        }
    }

    #[Test]
    public function delimiter_tiene_insieme_il_corpo_di_un_trigger(): void
    {
        // La ragione per cui tutto questo esiste. Con lo split ingenuo questo
        // file diventava sei pezzi, e il primo era "DELIMITER //" — che non e'
        // nemmeno SQL.
        $sql = <<<'SQL'
        DELIMITER //
        CREATE TRIGGER t_prova BEFORE DELETE ON tab
        FOR EACH ROW
        BEGIN
            DECLARE n INT DEFAULT 0;
            SELECT COUNT(*) INTO n FROM altra WHERE id = OLD.id;
            IF n > 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'in uso';
            END IF;
        END//
        DELIMITER ;
        SELECT 1;
        SQL;
        $out = Migrator::splitStatements($sql);
        $this->assertCount(2, $out, 'il trigger deve restare un pezzo solo');
        $this->assertStringStartsWith('CREATE TRIGGER', $out[0]);
        $this->assertStringContainsString('END', $out[0]);
        $this->assertStringContainsString('SIGNAL', $out[0]);
        $this->assertSame('SELECT 1', $out[1]);
    }

    #[Test]
    public function i_commenti_eseguibili_di_mysql_non_si_toccano(): void
    {
        // /*! ... */ non e' un commento: e' codice che MySQL esegue e gli altri
        // ignorano. Toglierlo cambia il significato di un dump.
        $sql = "/*!40101 SET NAMES utf8mb4 */;\nSELECT 1;";
        $out = Migrator::splitStatements($sql);
        $this->assertStringContainsString('40101', $out[0]);
        $this->assertSame('SELECT 1', $out[1]);
    }

    #[Test]
    public function gli_identificatori_fra_backtick_sono_opachi(): void
    {
        $sql = 'CREATE TABLE `strana;tabella` (id INT);';
        $this->assertCount(1, Migrator::splitStatements($sql));
    }

    #[Test]
    public function le_righe_vuote_e_i_soli_commenti_non_producono_statement(): void
    {
        $this->assertSame([], Migrator::splitStatements("-- solo commenti\n\n/* e basta */\n"));
    }

    #[Test]
    public function la_038_torna_un_file_sensato(): void
    {
        // Prova sul file vero: 14 trigger, ognuno con il suo DROP davanti.
        // Con lo splitter vecchio erano 77 frammenti, il primo dei quali
        // "DELIMITER //".
        $path = dirname(__DIR__, 3) . '/database/migrations/038_indirizzo_classe_materia_triggers.sql';
        if (!is_file($path)) {
            $this->markTestSkipped('migration 038 non presente');
        }
        $out = Migrator::splitStatements((string)file_get_contents($path));
        $this->assertSame(28, count($out), '14 DROP + 14 CREATE');
        foreach ($out as $s) {
            $this->assertMatchesRegularExpression('/^(DROP TRIGGER|CREATE TRIGGER)/', $s);
            $this->assertStringNotContainsString('DELIMITER', $s);
        }
    }
}
