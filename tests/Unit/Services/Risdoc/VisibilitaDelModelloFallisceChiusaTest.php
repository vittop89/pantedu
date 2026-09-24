<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Risdoc;

use App\Core\Config;
use App\Core\Database;
use App\Services\Risdoc\Permission;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * `Permission::canView` nega il modello se non riesce a leggerne lo scope
 * (23/9/2026, revisione architetturale A-82).
 *
 * Il ramo che legge `visibility_scope` dichiarava «fail-safe: scope
 * sconosciuto → DENY», e così fanno gli altri tre rami dello stesso file
 * quando una query fallisce. Ma se falliva proprio la prima lettura, il
 * `catch` ripiegava su «il modello esiste → visibile»: pensato per un
 * database senza la migrazione 013, apriva ogni modello a ogni docente per
 * qualunque errore di quella query. Sul codice di prima le due prove
 * `..._non_si_vede` fallivano: `canView` rispondeva true.
 *
 * Le prove girano su un SQLite in memoria messo al posto della connessione, e
 * la controprova dice che lo stesso impianto, con le colonne al loro posto,
 * lascia vedere un modello pubblico: il «no» non viene dall'impianto.
 *
 * Gli altri rami dello stesso file negano già, ma nessuna prova lo diceva: uno
 * scope sconosciuto, e una query che fallisce nel confronto con l'istituto,
 * l'indirizzo o la classe del docente. Portarli a «sì» lasciava verde tutta la
 * suite. Qui ciascuno ha la sua prova e la sua controprova (lo stesso scope,
 * con la query che riesce e il docente che combacia, si vede).
 */
final class VisibilitaDelModelloFallisceChiusaTest extends TestCase
{
    private const DOCENTE = 7;
    private const MODELLO = 11;

    private ?bool $dbAbilitatoPrima = null;
    private string $registro = '';
    private string|false $registroPrima = false;

    protected function setUp(): void
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite non disponibile in questo runtime');
        }
        $this->dbAbilitatoPrima = (bool)Config::get('database.enabled');
        Config::set('database.enabled', true);
        $this->registro = sys_get_temp_dir() . '/pantedu-visibilita-' . bin2hex(random_bytes(6)) . '.log';
        $this->registroPrima = ini_get('error_log');
        ini_set('error_log', $this->registro);
    }

    protected function tearDown(): void
    {
        Database::reset();
        Config::set('database.enabled', $this->dbAbilitatoPrima ?? true);
        ini_set('error_log', $this->registroPrima === false ? '' : $this->registroPrima);
        @unlink($this->registro);
    }

    /** Le tabelle che `canView` guarda prima dello scope, vuote: né collaboratore né visibilità. */
    private function schemaDiBase(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE risdoc_template_collaborators
            (template_id INTEGER, teacher_id INTEGER, requires_review INTEGER)');
        $pdo->exec('CREATE TABLE risdoc_template_visibility
            (template_id INTEGER, teacher_id INTEGER, visible INTEGER)');
    }

    private function usa(PDO $pdo): void
    {
        (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $pdo);
    }

    /**
     * Un PDO che lancia su ogni query che contiene `$ago`; le altre vanno a
     * SQLite. Con `$ago` vuoto non lancia mai.
     */
    private function pdoCheLanciaSu(string $ago): PDO
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            public string $ago = '';

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if ($this->ago !== '' && str_contains($query, $this->ago)) {
                    throw new PDOException('SQLSTATE[HY000]: errore simulato su ' . $this->ago);
                }
                return parent::prepare($query, $options);
            }
        };
        $pdo->ago = $ago;
        return $pdo;
    }

    /**
     * Lo scope, la colonna che lo restringe e il valore, la query che lo
     * confronta con il docente e le righe che lo fanno combaciare.
     *
     * @return array<string, array{0:string, 1:string, 2:int|string, 3:string, 4:list<string>}>
     */
    public static function scopeRistretti(): array
    {
        return [
            'istituto'  => ['institute', 'scope_institute_id', 3, 'FROM users',
                ['INSERT INTO users (id, institute_id) VALUES (' . self::DOCENTE . ', 3)']],
            'indirizzo' => ['indirizzo', 'scope_indirizzo', 'SCI', 'AND indirizzo=?',
                ['INSERT INTO teacher_subjects (teacher_id, indirizzo, classe) VALUES (' . self::DOCENTE . ', "SCI", "1A")']],
            'classe'    => ['classe', 'scope_classe', '1A', 'AND classe=?',
                ['INSERT INTO teacher_subjects (teacher_id, indirizzo, classe) VALUES (' . self::DOCENTE . ', "SCI", "1A")']],
        ];
    }

    /** @param list<string> $righe */
    private function modelloRistretto(PDO $pdo, string $scope, string $colonna, int|string $valore, array $righe): void
    {
        $this->schemaDiBase($pdo);
        $pdo->exec('CREATE TABLE risdoc_templates (id INTEGER PRIMARY KEY, visibility_scope TEXT,
            scope_institute_id INTEGER, scope_indirizzo TEXT, scope_classe TEXT)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, institute_id INTEGER)');
        $pdo->exec('CREATE TABLE teacher_subjects (teacher_id INTEGER, indirizzo TEXT, classe TEXT)');
        $pdo->prepare("INSERT INTO risdoc_templates (id, visibility_scope, $colonna) VALUES (?, ?, ?)")
            ->execute([self::MODELLO, $scope, $valore]);
        foreach ($righe as $riga) {
            $pdo->exec($riga);
        }
        $this->usa($pdo);
    }

    #[Test]
    #[DataProvider('scopeRistretti')]
    public function se_il_confronto_dello_scope_col_docente_fallisce_il_modello_non_si_vede(
        string $scope,
        string $colonna,
        int|string $valore,
        string $query,
        array $righe,
    ): void {
        $this->modelloRistretto($this->pdoCheLanciaSu($query), $scope, $colonna, $valore, $righe);

        self::assertFalse(Permission::canView(self::MODELLO, self::DOCENTE));
    }

    #[Test]
    #[DataProvider('scopeRistretti')]
    public function con_il_confronto_che_riesce_il_docente_che_combacia_vede_e_gli_altri_no(
        string $scope,
        string $colonna,
        int|string $valore,
        string $query,
        array $righe,
    ): void {
        $this->modelloRistretto($this->pdoCheLanciaSu(''), $scope, $colonna, $valore, $righe);

        self::assertTrue(Permission::canView(self::MODELLO, self::DOCENTE), "$scope: il docente che combacia");
        self::assertFalse(Permission::canView(self::MODELLO, self::DOCENTE + 1), "$scope: un altro docente");
    }

    #[Test]
    public function uno_scope_sconosciuto_non_si_vede(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->schemaDiBase($pdo);
        $pdo->exec('CREATE TABLE risdoc_templates (id INTEGER PRIMARY KEY, visibility_scope TEXT,
            scope_institute_id INTEGER, scope_indirizzo TEXT, scope_classe TEXT)');
        $pdo->exec('INSERT INTO risdoc_templates (id, visibility_scope) VALUES (' . self::MODELLO . ', "zz_sconosciuto")');
        $this->usa($pdo);

        self::assertFalse(Permission::canView(self::MODELLO, self::DOCENTE));
    }

    private function registrato(): string
    {
        return is_file($this->registro) ? (string)file_get_contents($this->registro) : '';
    }

    #[Test]
    public function senza_le_colonne_dello_scope_il_modello_non_si_vede(): void
    {
        // Un database senza la migrazione 013: il modello c'è, lo scope no.
        $pdo = new PDO('sqlite::memory:');
        $this->schemaDiBase($pdo);
        $pdo->exec('CREATE TABLE risdoc_templates (id INTEGER PRIMARY KEY, code TEXT)');
        $pdo->exec('INSERT INTO risdoc_templates (id, code) VALUES (' . self::MODELLO . ', "ZZ")');
        $this->usa($pdo);

        self::assertFalse(Permission::canView(self::MODELLO, self::DOCENTE));
        self::assertStringContainsString('[Permission]', $this->registrato(), 'e il perché si registra');
    }

    #[Test]
    public function se_la_lettura_dello_scope_fallisce_il_modello_non_si_vede(): void
    {
        // Un errore qualunque su quella query (connessione, blocco, permessi):
        // la query di ripiego, quella che rispondeva «esiste», andrebbe.
        $pdo = $this->pdoCheLanciaSu('visibility_scope');
        $this->schemaDiBase($pdo);
        $pdo->exec('CREATE TABLE risdoc_templates (id INTEGER PRIMARY KEY, visibility_scope TEXT,
            scope_institute_id INTEGER, scope_indirizzo TEXT, scope_classe TEXT)');
        $pdo->exec('INSERT INTO risdoc_templates (id, visibility_scope) VALUES (' . self::MODELLO . ', "public")');
        $this->usa($pdo);

        self::assertFalse(Permission::canView(self::MODELLO, self::DOCENTE));
        self::assertStringContainsString('errore simulato', $this->registrato());
    }

    #[Test]
    public function con_lo_scope_leggibile_un_modello_pubblico_si_vede_e_uno_negato_no(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->schemaDiBase($pdo);
        $pdo->exec('CREATE TABLE risdoc_templates (id INTEGER PRIMARY KEY, visibility_scope TEXT,
            scope_institute_id INTEGER, scope_indirizzo TEXT, scope_classe TEXT)');
        $pdo->exec('INSERT INTO risdoc_templates (id, visibility_scope) VALUES (' . self::MODELLO . ', "public")');
        $negato = self::MODELLO + 1;
        $pdo->exec('INSERT INTO risdoc_templates (id, visibility_scope) VALUES (' . $negato . ', "denied")');
        $this->usa($pdo);

        self::assertTrue(Permission::canView(self::MODELLO, self::DOCENTE), 'pubblico');
        self::assertFalse(Permission::canView($negato, self::DOCENTE), 'negato');
        self::assertFalse(Permission::canView(999, self::DOCENTE), 'inesistente');
        self::assertSame('', $this->registrato(), 'niente da registrare quando la lettura riesce');
    }
}
