<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\TeacherContentRepository;
use App\Services\Contenuti\MaterialiSuSezioniNonAmmesse;
use App\Services\CurriculumService;
use App\Services\InstituteMergeService;
use App\Services\SezioniDeiDocenti;
use App\Services\TeacherSectionService;
use App\Support\CurriculumLookup;
use App\Support\PostoPrincipale;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-042 — un anno appartiene a un indirizzo (15/9/2026), sul database vero.
 *
 * Istituto di prova con due corsi: ZAS ha gli anni 1, 2, 5 e la sezione 2A;
 * ZAT ha gli anni 1, 2, 3 e la sezione 3T; ZAS anche la sezione 1S. Così «1» c'è in
 * tutti e due i corsi, «5» in uno solo, «3» solo in ZAT.
 *
 * Le voci di ZAT si scrivono dopo quelle di ZAS: il codice di prima, che
 * risolveva un anno con l'ultima voce precaricata, prenderebbe quella di ZAT.
 * Per questo le prove chiedono lo scientifico: con il codice di prima nove su
 * dieci falliscono (misurato il 15/9/2026); la decima è il vincolo del database,
 * che viene dalla migrazione. Tutto in una transazione annullata alla fine:
 * nessun dato resta.
 */
final class AnniPerIndirizzoTest extends TestCase
{
    private PDO $pdo;
    private int $istituto = 0;
    private int $docente = 0;
    /** @var array<string,int> «ZAS1», «ZAT3», «2A», «ZAS»… → id */
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
        if ($this->pdo->query("SHOW COLUMNS FROM curriculum_entries LIKE 'corso_anno'")->fetch() === false) {
            self::fail('manca la migrazione 132 sul database di prova: APP_ENV=testing php tools/migrate.php');
        }
        CurriculumLookup::resetCache();
        $_SESSION = [];

        $this->pdo->beginTransaction();
        $this->istituto = $this->istituto('ZZANNICOR1');
        // Qui si provano gli anni per corso, non chi può usarli (ADR-041, ADR-043):
        // «tutti», salvo le prove che scelgono una modalità.
        $this->pdo->prepare("UPDATE institutes SET sezioni_docenti = 'tutti' WHERE id = ?")->execute([$this->istituto]);
        $this->vocabolario($this->istituto, '');
        $this->docente = $this->docente('zz_anni_corso', [$this->istituto]);
    }

    protected function tearDown(): void
    {
        CurriculumLookup::resetCache();
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function istituto(string $codice): int
    {
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute([$codice, 'ISTITUTO DEGLI ANNI ' . $codice, 'Comune Esempio']);
        return (int)$this->pdo->lastInsertId();
    }

    /** Il vocabolario di prova; `$prefisso` distingue le voci di un secondo istituto. */
    private function vocabolario(int $istituto, string $prefisso): void
    {
        $ins = $this->pdo->prepare(
            "INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, origine)
             VALUES (?, ?, ?, ?, ?, 1, 'istituto')"
        );
        foreach ([
            ['indirizzi', 'ZAS', 'Scientifico', null], ['indirizzi', 'ZAT', 'Artistico', null],
            ['materie', 'ZAM', 'Matematica', null],
            ['classi', '1', 'Prima', 'ZAS'], ['classi', '2', 'Seconda', 'ZAS'], ['classi', '5', 'Quinta', 'ZAS'],
            ['classi', '1', 'Prima', 'ZAT'], ['classi', '2', 'Seconda', 'ZAT'], ['classi', '3', 'Terza', 'ZAT'],
            ['classi', '2A', '2A', 'ZAS'], ['classi', '1S', '1S', 'ZAS'], ['classi', '3T', '3T', 'ZAT'],
        ] as [$kind, $code, $label, $corso]) {
            $ins->execute([$kind, $istituto, $code, $label, $corso]);
            $chiave = $kind === 'classi' && preg_match('/^[1-9]$/', $code) === 1 ? $corso . $code : $code;
            $this->voce[$prefisso . $chiave] = (int)$this->pdo->lastInsertId();
        }
    }

    /** @param list<int> $istituti */
    private function docente(string $username, array $istituti): int
    {
        $this->pdo->prepare(
            "INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active)
             VALUES (?, 'teacher', 'Prova', 'Anni', ?, 'x', 'approved', 1)"
        )->execute([$username, $username . '@example.test']);
        $id = (int)$this->pdo->lastInsertId();
        foreach ($istituti as $i) {
            $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$id, $i]);
        }
        return $id;
    }

    private function spuntata(int $voce, int $docente): ?int
    {
        $st = $this->pdo->prepare('SELECT active FROM curriculum_teacher WHERE curriculum_id = ? AND user_id = ?');
        $st->execute([$voce, $docente]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    #[Test]
    public function un_anno_si_risolve_nel_suo_corso(): void
    {
        $ist = $this->istituto;
        self::assertSame($this->voce['ZAS1'], CurriculumLookup::idFromCode('classi', '1', $ist, null, 'ZAS'));
        self::assertSame($this->voce['ZAT1'], CurriculumLookup::idFromCode('classi', '1', $ist, null, 'ZAT'), '«1» dell\'artistico è un\'altra voce');
        self::assertNull(CurriculumLookup::idFromCode('classi', '3', $ist, null, 'ZAS'), 'lo scientifico non ha la terza: non diventa quella dell\'artistico');

        // Il precaricamento non confonde le due «1» (la chiave ha il corso).
        CurriculumLookup::resetCache();
        CurriculumLookup::preload();
        self::assertSame($this->voce['ZAT1'], CurriculumLookup::idFromCode('classi', '1', $ist, null, 'ZAT'));
        self::assertSame($this->voce['ZAS1'], CurriculumLookup::idFromCode('classi', '1', $ist, null, 'zas'), 'la sigla del corso in minuscolo vale lo stesso');
    }

    #[Test]
    public function senza_corso_un_anno_si_risolve_solo_se_la_scuola_lo_ha_in_un_corso_solo(): void
    {
        self::assertNull(CurriculumLookup::idFromCode('classi', '1', $this->istituto), 'due «1»: scegliere a caso metterebbe il contenuto sotto un altro corso');
        self::assertSame($this->voce['ZAS5'], CurriculumLookup::idFromCode('classi', '5', $this->istituto), 'una «5» sola');
        self::assertSame($this->voce['2A'], CurriculumLookup::idFromCode('classi', '2A', $this->istituto, null, 'ZAT'), 'una sezione si risolve con la sigla, qualunque corso si dica');
    }

    #[Test]
    public function una_sezione_non_ammessa_ripiega_sull_anno_del_suo_corso(): void
    {
        (new SezioniDeiDocenti($this->pdo))->imposta($this->istituto, SezioniDeiDocenti::SOLO_INCARICATI);
        // ADR-043 — l'anno si spunta solo con il suo incarico.
        $this->pdo->prepare("INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe) VALUES (?, ?, 'ZAS', '1')")->execute([$this->docente, $this->istituto]);
        // Chi chiama dice l'artistico, ma 1S è dello scientifico: vince il catalogo.
        $id = CurriculumLookup::idFromCodeForTeacher('classi', '1S', $this->docente, 'ZAT');
        self::assertSame($this->voce['ZAS1'], $id);
        self::assertSame(1, $this->spuntata($this->voce['ZAS1'], $this->docente), 'l\'anno entra nei suoi menù');
        self::assertNull($this->spuntata($this->voce['ZAT1'], $this->docente), 'non la «1» dell\'artistico');
        self::assertNull($this->spuntata($this->voce['1S'], $this->docente), 'né la sezione');
    }

    #[Test]
    public function il_profilo_spunta_un_anno_nel_corso_e_lo_rifiuta_senza(): void
    {
        $svc = new CurriculumService('/dev/null');
        try {
            $svc->add('classi', ['code' => '1', 'label' => 'Prima'], $this->istituto, $this->docente);
            self::fail('spuntato un anno senza corso');
        } catch (\RuntimeException $e) {
            self::assertSame('anno_senza_indirizzo', $e->getMessage());
        }
        $mia = $svc->add('classi', ['code' => '1', 'label' => 'Prima', 'indirizzo' => 'ZAT'], $this->istituto, $this->docente);
        self::assertSame($this->voce['ZAT1'], $mia['id']);
        self::assertNull($this->spuntata($this->voce['ZAS1'], $this->docente), 'la «1» dello scientifico resta non spuntata');
    }

    #[Test]
    public function il_catalogo_aggiunge_un_anno_solo_dentro_un_corso_della_scuola(): void
    {
        $svc = new CurriculumService('/dev/null');
        foreach ([
            [['code' => '4', 'label' => 'Quarta'], 'anno_senza_indirizzo'],
            [['code' => '4', 'label' => 'Quarta', 'indirizzo' => 'ZZQ'], 'indirizzo_sconosciuto'],
        ] as [$voce, $errore]) {
            try {
                $svc->add('classi', $voce, $this->istituto, null);
                self::fail("aggiunto un anno che doveva dare $errore");
            } catch (\RuntimeException $e) {
                self::assertSame($errore, $e->getMessage());
            }
        }
        $zat = $svc->add('classi', ['code' => '4', 'label' => 'Quarta', 'indirizzo' => 'ZAT'], $this->istituto, null);
        self::assertSame('ZAT', $zat['indirizzo']);
        $zas = $svc->add('classi', ['code' => '4', 'label' => 'Quarta', 'indirizzo' => 'ZAS'], $this->istituto, null);
        self::assertNotSame($zat['id'], $zas['id'], 'la «4» dello scientifico è un\'altra voce');
        try {
            $svc->add('classi', ['code' => '4', 'label' => 'Quarta', 'indirizzo' => 'ZAT'], $this->istituto, null);
            self::fail('due «4» nello stesso corso');
        } catch (\RuntimeException $e) {
            self::assertSame('duplicate_code', $e->getMessage());
        }
        // Una sezione con la sigla di un'altra resta rifiutata: le sigle delle sezioni sono uniche nella scuola.
        try {
            $svc->add('classi', ['code' => '2A', 'label' => '2A', 'indirizzo' => 'ZAT'], $this->istituto, null);
            self::fail('una seconda 2A');
        } catch (\RuntimeException $e) {
            self::assertSame('duplicate_code', $e->getMessage());
        }
    }

    #[Test]
    public function il_database_non_accetta_un_anno_senza_corso(): void
    {
        $ins = $this->pdo->prepare(
            "INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, origine) VALUES ('classi', ?, ?, ?, ?, 1, 'istituto')"
        );
        foreach ([null, ''] as $vuoto) {
            try {
                $ins->execute([$this->istituto, '4', 'Quarta', $vuoto]);
                self::fail('un anno senza corso è entrato');
            } catch (\PDOException $e) {
                self::assertStringContainsString('chk_anno_ha_corso', $e->getMessage());
            }
        }
        // Nell'altro verso: un anno con il corso e una sezione senza (righe di prima della 100) entrano.
        $ins->execute([$this->istituto, '4', 'Quarta', 'ZAS']);
        $ins->execute([$this->istituto, '4Z', 'Quarta Z', null]);
        self::assertGreaterThan(0, (int)$this->pdo->lastInsertId());
    }

    #[Test]
    public function un_incarico_su_un_anno_spunta_l_anno_di_quel_corso(): void
    {
        (new TeacherSectionService($this->pdo))->assign($this->docente, $this->istituto, 'ZAS', '2');
        self::assertSame(1, $this->spuntata($this->voce['ZAS2'], $this->docente));
        self::assertNull($this->spuntata($this->voce['ZAT2'], $this->docente), 'non la «2» dell\'artistico');
    }

    #[Test]
    public function cambiare_solo_la_classe_di_un_contenuto_resta_nel_suo_corso(): void
    {
        $repo = new TeacherContentRepository();
        $id = $repo->create([
            'teacher_id' => $this->docente, 'content_type' => 'document', 'subject_code' => 'ZAM',
            'indirizzo' => 'ZAS', 'classe' => '1', 'topic' => 'Anni', 'title' => 'Anni per corso ' . uniqid(),
        ]);
        self::assertTrue($repo->update($id, $this->docente, ['classe' => '2']));
        $st = $this->pdo->prepare('SELECT indirizzo_id, classe_id FROM content_publications WHERE teacher_content_id = ? AND is_primary = 1');
        $st->execute([$id]);
        self::assertSame(
            ['indirizzo_id' => $this->voce['ZAS'], 'classe_id' => $this->voce['ZAS2']],
            array_map('intval', $st->fetch(PDO::FETCH_ASSOC)),
            'la «2» dello scientifico, il corso della pubblicazione principale'
        );
    }

    #[Test]
    public function portare_sull_anno_accende_anche_il_corso_della_sezione(): void
    {
        (new SezioniDeiDocenti($this->pdo))->imposta($this->istituto, SezioniDeiDocenti::SOLO_INCARICATI);
        // ADR-043 — si porta su un anno di cui ha l'incarico.
        $this->pdo->prepare("INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe) VALUES (?, ?, 'ZAT', '3')")->execute([$this->docente, $this->istituto]);
        $this->pdo->prepare('INSERT INTO teacher_content_data (teacher_id, content_subtype, title) VALUES (?, "esercizio", ?)')
            ->execute([$this->docente, 'Prospettiva']);
        $contenuto = (int)$this->pdo->lastInsertId();
        PostoPrincipale::contenuto($this->pdo, $contenuto, $this->voce['ZAT'], $this->voce['3T'], $this->voce['ZAM']);

        $esito = (new MaterialiSuSezioniNonAmmesse($this->pdo))->portaSullAnno($this->istituto, $this->docente, $this->voce['3T']);

        self::assertSame('3', $esito['classe']);
        self::assertSame('ZAT', $esito['indirizzo']);
        self::assertTrue($esito['anno_spuntato']);
        self::assertTrue($esito['corso_spuntato'], 'senza l\'artistico acceso, i materiali sparirebbero dai suoi menù');
        self::assertSame(1, $this->spuntata($this->voce['ZAT'], $this->docente));
        self::assertNull($this->spuntata($this->voce['ZAS'], $this->docente), 'lo scientifico no');
    }

    #[Test]
    public function fondere_due_istituti_tiene_distinti_gli_anni_dei_corsi(): void
    {
        $doppio = $this->istituto('ZZANNICOR2');
        $this->vocabolario($doppio, 'dup:');
        $collega = $this->docente('zz_anni_corso_dup', [$doppio]);
        $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active) VALUES (?, ?, 1)')
            ->execute([$this->voce['dup:ZAS1'], $collega]);

        (new InstituteMergeService($this->pdo))->merge($this->istituto, $doppio);

        self::assertSame(1, $this->spuntata($this->voce['ZAS1'], $collega), 'la «1» dello scientifico del doppione diventa la «1» dello scientifico');
        self::assertNull($this->spuntata($this->voce['ZAT1'], $collega), 'non quella dell\'artistico');
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM curriculum_entries WHERE institute_id = ? AND kind = 'classi' AND code = '1'");
        $st->execute([$this->istituto]);
        self::assertSame(2, (int)$st->fetchColumn(), 'restano due «1», una per corso');
    }
}
