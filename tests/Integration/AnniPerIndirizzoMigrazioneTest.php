<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La migrazione 132 (ADR-042) e il suo ritorno indietro, sui dati.
 *
 * Il database di prova è già migrato, e il vincolo `chk_anno_ha_corso` non
 * lascerebbe scrivere un anno senza corso: la fixture lo scrive con i vincoli
 * spenti per la sessione, poi li riaccende e fa girare le istruzioni fra
 * «DATI: inizio» e «DATI: fine» (nessun DDL su tabelle vere, quindi dentro la
 * transazione). Tutto annullato alla fine.
 *
 * La scuola di prova ha tre corsi: ZMS con la sezione 1A, ZMT con la 3T, ZMU
 * senza sezioni; gli anni 1, 2, 3 senza corso; un docente che ha acceso ZMS e
 * spento ZMT, e un collega.
 */
final class AnniPerIndirizzoMigrazioneTest extends TestCase
{
    private PDO $pdo;
    private int $scuola = 0;
    private int $docente = 0;
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
        Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->exec('SET SESSION check_constraint_checks = 1');
        }
    }

    /** Le istruzioni sui dati di un file SQL, come le spezza il migratore. */
    private function dati(string $file): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        $inizio = strpos($sql, '-- ── DATI: inizio');
        $fine = strpos($sql, '-- ── DATI: fine');
        self::assertNotFalse($inizio, "$file: manca «DATI: inizio»");
        self::assertNotFalse($fine, "$file: manca «DATI: fine»");
        foreach (Migrator::splitStatements(substr($sql, $inizio, $fine - $inizio)) as $istruzione) {
            $this->pdo->exec($istruzione);
        }
        self::assertTrue($this->pdo->inTransaction(), "$file: le istruzioni sui dati non devono chiudere la transazione");
    }

    private function scuola(string $codice): int
    {
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute([$codice, 'ISTITUTO DELLA 132 ' . $codice, 'Comune Esempio']);
        return (int)$this->pdo->lastInsertId();
    }

    private function voce(int $scuola, string $kind, string $code, ?string $corso = null, string $label = ''): int
    {
        $this->pdo->prepare(
            "INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, origine) VALUES (?, ?, ?, ?, ?, 1, 'istituto')"
        )->execute([$kind, $scuola, $code, $label !== '' ? $label : $code, $corso]);
        return (int)$this->pdo->lastInsertId();
    }

    private function utente(string $nome, int $scuola): int
    {
        $this->pdo->prepare(
            "INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active)
             VALUES (?, 'teacher', 'Zz', 'Migrazione', ?, 'x', 'approved', 1)"
        )->execute([$nome, "$nome@example.test"]);
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$id, $scuola]);
        return $id;
    }

    private function spunta(int $voce, int $utente, int $attiva = 1, ?string $nome = null): void
    {
        $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active, label_override) VALUES (?, ?, ?, ?)')
            ->execute([$voce, $utente, $attiva, $nome]);
    }

    /** Una pubblicazione principale di un contenuto (o di una verifica) nuovo. */
    private function pubblicazione(int $utente, ?string $corso, string $anno, bool $verifica = false): int
    {
        if ($verifica) {
            $this->pdo->prepare('INSERT INTO verifica_documents_data (teacher_id, title) VALUES (?, ?)')->execute([$utente, 'Bozza ' . uniqid()]);
        } else {
            $this->pdo->prepare('INSERT INTO teacher_content_data (teacher_id, content_subtype, title) VALUES (?, "esercizio", ?)')->execute([$utente, 'Contenuto ' . uniqid()]);
        }
        $doc = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO content_publications (' . ($verifica ? 'verifica_document_id' : 'teacher_content_id') . ',
                institute_id, indirizzo_id, classe_id, subject_id, is_primary, origine, visibility, archive_visible)
             VALUES (?, ?, ?, ?, ?, 1, "riga", "draft", 0)'
        )->execute([$doc, $this->scuola, $corso !== null ? $this->voce[$corso] : null, $this->voce[$anno], $this->voce['ZMM']]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array{classe:string, corso_classe:?string, corso_riga:?string} */
    private function dove(string $tabella, int $id): array
    {
        $st = $this->pdo->prepare(
            "SELECT c.code AS classe, c.indirizzo AS corso_classe, i.code AS corso_riga
               FROM $tabella t JOIN curriculum_entries c ON c.id = t.classe_id
               LEFT JOIN curriculum_entries i ON i.id = t.indirizzo_id
              WHERE t.id = ?"
        );
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($r, "$tabella $id: la riga ha perso la classe");
        return $r;
    }

    /** @return list<string> «ZMS1», «ZMT3»… con lo stato della spunta */
    private function spunteDegliAnni(int $utente): array
    {
        $st = $this->pdo->prepare(
            "SELECT CONCAT(COALESCE(c.indirizzo, '-'), c.code, ':', ct.active, IF(ct.label_override IS NULL, '', CONCAT(':', ct.label_override)))
               FROM curriculum_teacher ct JOIN curriculum_entries c ON c.id = ct.curriculum_id
              WHERE ct.user_id = ? AND c.kind = 'classi' AND c.code REGEXP '^[1-9]$'
              ORDER BY 1"
        );
        $st->execute([$utente]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    /** La scuola di prova, con gli anni senza corso scritti a vincoli spenti. */
    private function scuolaDiPrima(): void
    {
        $this->scuola = $this->scuola('ZZMIG132A');
        foreach (['ZMS', 'ZMT', 'ZMU'] as $c) {
            $this->voce[$c] = $this->voce($this->scuola, 'indirizzi', $c);
        }
        $this->voce['ZMM'] = $this->voce($this->scuola, 'materie', 'ZMM');
        $this->voce['1A'] = $this->voce($this->scuola, 'classi', '1A', 'ZMS');
        $this->voce['3T'] = $this->voce($this->scuola, 'classi', '3T', 'ZMT');
        $this->pdo->exec('SET SESSION check_constraint_checks = 0');
        foreach (['1' => 'Classe I', '2' => 'Classe II', '3' => 'Classe III'] as $anno => $label) {
            $this->voce[(string)$anno] = $this->voce($this->scuola, 'classi', (string)$anno, null, $label);
        }
        $this->pdo->exec('SET SESSION check_constraint_checks = 1');

        $this->docente = $this->utente('zz_mig132_doc', $this->scuola);
        $this->collega = $this->utente('zz_mig132_col', $this->scuola);
        $this->spunta($this->voce['ZMS'], $this->docente);
        $this->spunta($this->voce['ZMT'], $this->docente, 0);
        $this->spunta($this->voce['1'], $this->docente, 1, 'Primina');
        $this->spunta($this->voce['2'], $this->docente);
        $this->spunta($this->voce['3'], $this->docente);
    }

    #[Test]
    public function gli_anni_diventano_dei_corsi_e_i_dati_li_seguono(): void
    {
        $this->scuolaDiPrima();
        $pubS1 = $this->pubblicazione($this->docente, 'ZMS', '1');
        $pubT3 = $this->pubblicazione($this->docente, 'ZMT', '3');
        $bozza = $this->pubblicazione($this->docente, null, '2', true);
        $delCollega = $this->pubblicazione($this->collega, 'ZMT', '1');
        $this->pdo->prepare('INSERT INTO print_info_data (user_id, page_key, indirizzo_id, classe_id, materia_id, n_print) VALUES (?, ?, NULL, ?, ?, 1)')
            ->execute([$this->docente, 'zz_mig132_' . uniqid(), $this->voce['3'], $this->voce['ZMM']]);
        $stampa = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO exercises_data (indirizzo_id, classe_id, materia_id, topic, title, body_html) VALUES (?, ?, ?, "Prova", "Esercizio della 132", "")')
            ->execute([$this->voce['ZMT'], $this->voce['3'], $this->voce['ZMM']]);
        $esercizio = (int)$this->pdo->lastInsertId();

        $this->dati('database/migrations/132_anni_per_indirizzo.sql');

        $st = $this->pdo->prepare("SELECT GROUP_CONCAT(CONCAT(indirizzo, code) ORDER BY indirizzo, code) FROM curriculum_entries WHERE institute_id = ? AND kind = 'classi' AND code REGEXP '^[1-9]$'");
        $st->execute([$this->scuola]);
        self::assertSame(
            'ZMS1,ZMS2,ZMT1,ZMT3,ZMU1,ZMU2,ZMU3',
            $st->fetchColumn(),
            'ZMS: la 1 dalla sezione, la 2 per la bozza senza corso; ZMT: la 3 dalla sezione, la 1 dai dati del collega; ZMU, senza sezioni, tutte'
        );
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM curriculum_entries WHERE id IN (?, ?, ?)');
        $st->execute([$this->voce['1'], $this->voce['2'], $this->voce['3']]);
        self::assertSame(0, (int)$st->fetchColumn(), 'gli anni senza corso non ci sono più');

        self::assertSame(['classe' => '1', 'corso_classe' => 'ZMS', 'corso_riga' => 'ZMS'], $this->dove('content_publications', $pubS1));
        self::assertSame(['classe' => '3', 'corso_classe' => 'ZMT', 'corso_riga' => 'ZMT'], $this->dove('content_publications', $pubT3));
        self::assertSame(['classe' => '1', 'corso_classe' => 'ZMT', 'corso_riga' => 'ZMT'], $this->dove('content_publications', $delCollega));
        self::assertSame(['classe' => '2', 'corso_classe' => 'ZMS', 'corso_riga' => 'ZMS'], $this->dove('content_publications', $bozza),
            'senza corso e senza dati sull\'anno: il primo corso per sigla, e la riga prende il corso');
        self::assertSame(['classe' => '3', 'corso_classe' => 'ZMT', 'corso_riga' => 'ZMT'], $this->dove('print_info_data', $stampa),
            'senza corso: il corso con più dati sulla terza');
        self::assertSame(['classe' => '3', 'corso_classe' => 'ZMT', 'corso_riga' => 'ZMT'], $this->dove('exercises_data', $esercizio));

        self::assertSame(
            ['ZMS1:1:Primina', 'ZMS2:1', 'ZMT3:1'],
            $this->spunteDegliAnni($this->docente),
            'la 1 e la 2 nel corso acceso (con il nome personale); la 3 dove ha dati; niente in ZMT1 (dati del collega) né in ZMU'
        );
        self::assertSame([], $this->spunteDegliAnni($this->collega), 'il collega non aveva anni spuntati');
    }

    #[Test]
    public function il_ritorno_indietro_rimette_gli_anni_della_scuola(): void
    {
        $this->scuolaDiPrima();
        $pubS1 = $this->pubblicazione($this->docente, 'ZMS', '1');
        $pubT3 = $this->pubblicazione($this->docente, 'ZMT', '3');
        $this->spunta($this->voce['ZMU'], $this->docente);

        $this->dati('database/migrations/132_anni_per_indirizzo.sql');
        // Senza la bozza sulla «2», ZMS non ha la seconda: la «2» accesa va solo in ZMU.
        self::assertSame(['ZMS1:1:Primina', 'ZMT3:1', 'ZMU1:1:Primina', 'ZMU2:1', 'ZMU3:1'], $this->spunteDegliAnni($this->docente));

        $this->pdo->exec('SET SESSION check_constraint_checks = 0');
        $this->dati('tools/curriculum/anni_di_tutta_la_scuola.sql');

        self::assertSame(['classe' => '1', 'corso_classe' => null, 'corso_riga' => 'ZMS'], $this->dove('content_publications', $pubS1));
        self::assertSame(['classe' => '3', 'corso_classe' => null, 'corso_riga' => 'ZMT'], $this->dove('content_publications', $pubT3));
        self::assertSame(['-1:1:Primina', '-2:1', '-3:1'], $this->spunteDegliAnni($this->docente), 'una spunta per anno, per unione');
        $st = $this->pdo->prepare("SELECT GROUP_CONCAT(CONCAT(COALESCE(indirizzo, '-'), code, '=', label) ORDER BY code) FROM curriculum_entries WHERE institute_id = ? AND kind = 'classi' AND code REGEXP '^[1-9]$'");
        $st->execute([$this->scuola]);
        self::assertSame('-1=Classe I,-2=Classe II,-3=Classe III', $st->fetchColumn());
    }

    #[Test]
    public function con_righe_che_non_trovano_un_corso_si_ferma_prima_di_cancellare(): void
    {
        // Una scuola senza nessun corso, con un anno usato da una pubblicazione.
        $this->scuola = $this->scuola('ZZMIG132B');
        $this->voce['ZMM'] = $this->voce($this->scuola, 'materie', 'ZMM');
        $this->pdo->exec('SET SESSION check_constraint_checks = 0');
        $this->voce['4'] = $this->voce($this->scuola, 'classi', '4', null, 'Classe IV');
        $this->pdo->exec('SET SESSION check_constraint_checks = 1');
        $this->docente = $this->utente('zz_mig132_senza', $this->scuola);
        $pub = $this->pubblicazione($this->docente, null, '4');

        try {
            $this->dati('database/migrations/132_anni_per_indirizzo.sql');
            self::fail('la migrazione è andata avanti con una riga senza posto');
        } catch (\PDOException $e) {
            self::assertStringContainsString('132 (ADR-042): restano righe sugli anni senza corso', $e->getMessage());
        }
        $st = $this->pdo->prepare('SELECT classe_id FROM content_publications WHERE id = ?');
        $st->execute([$pub]);
        self::assertSame($this->voce['4'], (int)$st->fetchColumn(), 'l\'anno non è stato cancellato, e la pubblicazione ci punta ancora');
    }
}
