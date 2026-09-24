<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\CurriculumController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\TeacherCredentialRepository;
use App\Services\Contenuti\DoveVale;
use App\Services\CurriculumService;
use App\Services\SezioniDeiDocenti;
use App\Services\TeacherSectionService;
use App\Support\CurriculumLookup;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-041 — le sezioni delle classi per i docenti, sul database vero, nei due
 * versi: con «solo incaricati» un docente usa la sezione di cui ha l'incarico e
 * non le altre; con «nessuno» solo gli anni; con «tutti» ogni sezione.
 *
 * Istituto di prova con l'anno 2 e 3, le sezioni 2A e 3B dello stesso corso, e
 * un docente incaricato della sola 2A. Tutto in una transazione annullata alla
 * fine: nessun dato resta.
 */
final class SezioniDeiDocentiTest extends TestCase
{
    private PDO $pdo;
    private int $istituto = 0;
    private int $docente = 0;
    /** @var array<string,int> codice → id nel vocabolario */
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
        $colonna = $this->pdo->query("SHOW COLUMNS FROM curriculum_teacher LIKE 'sospesa_dalla_scuola'")->fetch();
        if ($colonna === false) {
            self::fail('manca la migrazione 126 sul database di prova: APP_ENV=testing php tools/migrate.php');
        }
        CurriculumLookup::resetCache();
        $_SESSION = [];

        $this->pdo->beginTransaction();
        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZSEZDOC01', 'ISTITUTO DELLE SEZIONI', 'Comune Esempio']);
        $this->istituto = (int)$this->pdo->lastInsertId();
        $vocabolario = [
            ['indirizzi', 'ZSC', 'Scientifico di prova', null],
            ['materie', 'ZMT', 'Matematica di prova', null],
            ['classi', '2', 'Seconda', 'ZSC'],
            ['classi', '3', 'Terza', 'ZSC'],
            ['classi', '2A', '2A', 'ZSC'],
            ['classi', '3B', '3B', 'ZSC'],
        ];
        $ins = $this->pdo->prepare(
            "INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, origine)
             VALUES (?, ?, ?, ?, ?, 1, 'istituto')"
        );
        foreach ($vocabolario as [$kind, $code, $label, $ind]) {
            $ins->execute([$kind, $this->istituto, $code, $label, $ind]);
            $this->voce[$code] = (int)$this->pdo->lastInsertId();
        }
        $this->pdo->prepare(
            "INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active)
             VALUES ('zz_docente_sezioni', 'teacher', 'Prova', 'Sezioni', 'zz_sezioni@example.test', 'x', 'approved', 1)"
        )->execute();
        $this->docente = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')
            ->execute([$this->docente, $this->istituto]);
        $this->pdo->prepare("INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe) VALUES (?, ?, 'ZSC', '2A')")
            ->execute([$this->docente, $this->istituto]);
        $this->pdo->prepare("UPDATE institutes SET sezioni_docenti = 'tutti' WHERE id = ?")->execute([$this->istituto]);
        foreach (['ZSC', 'ZMT', '2', '2A', '3B'] as $code) {
            $this->spunta($code);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        CurriculumLookup::resetCache();
        $_SESSION = [];
        $_GET = [];
    }

    #[Test]
    public function solo_incaricati_sospende_la_sezione_senza_incarico_e_nessuno_anche_l_altra(): void
    {
        $sezioni = new SezioniDeiDocenti($this->pdo);

        self::assertSame(['sospese' => 2, 'riprese' => 0], $sezioni->imposta($this->istituto, SezioniDeiDocenti::SOLO_INCARICATI));
        self::assertSame(['active' => 1, 'sospesa' => 0], $this->stato('2A'), 'la sezione con incarico resta');
        self::assertSame(['active' => 0, 'sospesa' => 1], $this->stato('3B'), 'quella senza si sospende');
        self::assertSame(['active' => 0, 'sospesa' => 1], $this->stato('2'), 'anche l\'anno senza incarico (ADR-043)');

        $sezioni->imposta($this->istituto, SezioniDeiDocenti::NESSUNO);
        self::assertSame(['active' => 0, 'sospesa' => 1], $this->stato('2A'), 'con «nessuno» nemmeno l\'incarico basta');
        self::assertSame(['active' => 1, 'sospesa' => 0], $this->stato('2'), 'con «nessuno» gli anni valgono per tutti: si riprende');

        // Una spunta spenta dal docente resta sua: allargare non la riaccende.
        $this->pdo->prepare('UPDATE curriculum_teacher SET active = 0, sospesa_dalla_scuola = 0 WHERE curriculum_id = ? AND user_id = ?')
            ->execute([$this->voce['3B'], $this->docente]);
        self::assertSame(['sospese' => 0, 'riprese' => 1], $sezioni->imposta($this->istituto, SezioniDeiDocenti::TUTTI));
        self::assertSame(['active' => 1, 'sospesa' => 0], $this->stato('2A'), 'con «tutti» la sospesa si riprende');
        self::assertSame(['active' => 0, 'sospesa' => 0], $this->stato('3B'), 'quella spenta dal docente no');
    }

    #[Test]
    public function dal_profilo_non_si_spunta_ne_si_riaccende_una_sezione_non_ammessa(): void
    {
        $this->pdo->prepare('DELETE FROM curriculum_teacher WHERE curriculum_id = ? AND user_id = ?')
            ->execute([$this->voce['3B'], $this->docente]);
        (new SezioniDeiDocenti($this->pdo))->imposta($this->istituto, SezioniDeiDocenti::SOLO_INCARICATI);
        $servizio = new CurriculumService('/dev/null');

        try {
            $servizio->add('classi', ['code' => '3B', 'label' => '3B'], $this->istituto, $this->docente);
            self::fail('spuntata una sezione senza incarico');
        } catch (\RuntimeException $e) {
            self::assertSame('sezione_non_ammessa', $e->getMessage());
        }
        try {
            $servizio->add('classi', ['code' => '3', 'label' => 'Terza', 'indirizzo' => 'ZSC'], $this->istituto, $this->docente);
            self::fail('spuntato un anno senza incarico (ADR-043)');
        } catch (\RuntimeException $e) {
            self::assertSame('sezione_non_ammessa', $e->getMessage());
        }
        $this->incarico('3');
        $servizio->add('classi', ['code' => '3', 'label' => 'Terza', 'indirizzo' => 'ZSC'], $this->istituto, $this->docente);
        self::assertSame(['active' => 1, 'sospesa' => 0], $this->stato('3'), 'con l\'incarico sull\'anno sì');

        // Nell'altro verso: con «tutti» la stessa spunta passa.
        (new SezioniDeiDocenti($this->pdo))->imposta($this->istituto, SezioniDeiDocenti::TUTTI);
        $servizio->add('classi', ['code' => '3B', 'label' => '3B'], $this->istituto, $this->docente);
        self::assertSame(['active' => 1, 'sospesa' => 0], $this->stato('3B'));

        (new SezioniDeiDocenti($this->pdo))->imposta($this->istituto, SezioniDeiDocenti::SOLO_INCARICATI);
        try {
            $servizio->updateById($this->voce['3B'], ['active' => 'true'], $this->docente);
            self::fail('riaccesa una sezione sospesa dalla scuola');
        } catch (\RuntimeException $e) {
            self::assertSame('sezione_non_ammessa', $e->getMessage());
        }
        self::assertSame(['active' => 0, 'sospesa' => 1], $this->stato('3B'));
    }

    #[Test]
    public function la_classe_di_un_contenuto_ripiega_sull_anno(): void
    {
        $this->pdo->prepare('DELETE FROM curriculum_teacher WHERE curriculum_id = ? AND user_id = ?')
            ->execute([$this->voce['3B'], $this->docente]);
        (new SezioniDeiDocenti($this->pdo))->imposta($this->istituto, SezioniDeiDocenti::SOLO_INCARICATI);

        self::assertSame($this->voce['3'], CurriculumLookup::idFromCodeForTeacher('classi', '3B', $this->docente), '3B diventa 3');
        self::assertNull($this->stato('3B'), 'e sulla 3B non nasce nessuna spunta');
        self::assertNull($this->stato('3'), 'né sulla 3, che non ha l\'incarico (ADR-043): resta solo la classe del contenuto');

        $this->incarico('3');
        CurriculumLookup::resetCache();
        self::assertSame($this->voce['3'], CurriculumLookup::idFromCodeForTeacher('classi', '3B', $this->docente));
        self::assertSame(['active' => 1, 'sospesa' => 0], $this->stato('3'), 'con l\'incarico sull\'anno la spunta nasce');
        self::assertSame($this->voce['2A'], CurriculumLookup::idFromCodeForTeacher('classi', '2A', $this->docente), 'la sezione con incarico resta');
    }

    #[Test]
    public function non_si_pubblica_e_non_si_crea_una_credenziale_su_una_sezione_non_ammessa(): void
    {
        (new SezioniDeiDocenti($this->pdo))->imposta($this->istituto, SezioniDeiDocenti::SOLO_INCARICATI);

        $dove = new DoveVale($this->pdo);
        try {
            $dove->verificaLuogo($this->docente, $this->istituto, $this->voce['ZSC'], $this->voce['3B'], $this->voce['ZMT']);
            self::fail('pubblicato su una sezione sospesa');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('voce_non_spuntata', $e->getMessage());
        }
        $dove->verificaLuogo($this->docente, $this->istituto, $this->voce['ZSC'], $this->voce['2A'], $this->voce['ZMT']);

        $credenziali = new TeacherCredentialRepository();
        $dati = ['password' => 'segreta-di-prova', 'indirizzo' => 'ZSC', 'institute_id' => $this->istituto];
        try {
            $credenziali->create($this->docente, $dati + ['username' => 'zz_cred_3b', 'classe' => '3B']);
            self::fail('credenziale creata su una sezione senza incarico');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('sezione_non_ammessa', $e->getMessage());
        }
        self::assertGreaterThan(0, $credenziali->create($this->docente, $dati + ['username' => 'zz_cred_2a', 'classe' => '2A']));
    }

    #[Test]
    public function togliere_l_incarico_sospende_e_ridarlo_riprende(): void
    {
        (new SezioniDeiDocenti($this->pdo))->imposta($this->istituto, SezioniDeiDocenti::SOLO_INCARICATI);
        $incarichi = new TeacherSectionService($this->pdo);

        $incarichi->revokeSection($this->docente, $this->istituto, 'ZSC', '2A');
        self::assertSame(['active' => 0, 'sospesa' => 1], $this->stato('2A'));

        $incarichi->assign($this->docente, $this->istituto, 'ZSC', '3B');
        self::assertSame(['active' => 1, 'sospesa' => 0], $this->stato('3B'), 'l\'incarico nuovo riprende la 3B');
    }

    #[Test]
    public function il_profilo_offre_solo_le_sezioni_ammesse(): void
    {
        (new SezioniDeiDocenti($this->pdo))->imposta($this->istituto, SezioniDeiDocenti::SOLO_INCARICATI);
        $_SESSION = [
            'autenticato' => true, 'username' => 'zz_docente_sezioni', 'user_id' => $this->docente,
            'user_role' => 'teacher', 'is_super_admin' => false,
        ];
        $_GET = ['institute_id' => (string)$this->istituto];

        $offerte = $this->classiOfferte();
        self::assertContains('2A', $offerte);
        self::assertNotContains('2', $offerte, 'l\'anno senza incarico non si offre (ADR-043)');
        self::assertNotContains('3B', $offerte, 'la sezione senza incarico non si offre');
        $this->incarico('2');
        self::assertContains('2', $this->classiOfferte(), 'con l\'incarico sull\'anno sì');

        // Le spunte del docente, anche spente (l'editor del profilo): la
        // sospesa dalla scuola non c'è, perché non è sua da riaccendere.
        $_GET['include_inactive'] = '1';
        $risposta = json_decode((string)(new CurriculumController())->index(new Request())->body, true);
        $sue = array_column($risposta['curriculum']['classi'] ?? [], 'code');
        self::assertContains('2A', $sue);
        self::assertNotContains('3B', $sue, 'la spunta sospesa non compare nel profilo');
        unset($_GET['include_inactive']);

        (new SezioniDeiDocenti($this->pdo))->imposta($this->istituto, SezioniDeiDocenti::TUTTI);
        self::assertContains('3B', $this->classiOfferte(), 'con «tutti» sì');
    }

    /** @return list<string> */
    private function classiOfferte(): array
    {
        $risposta = (new CurriculumController())->index(new Request());
        $json = json_decode((string)$risposta->body, true);
        self::assertIsArray($json);
        return array_column($json['institute_vocabolario']['classi'] ?? [], 'code');
    }

    private function spunta(string $code): void
    {
        $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active) VALUES (?, ?, 1)')
            ->execute([$this->voce[$code], $this->docente]);
    }

    private function incarico(string $classe): void
    {
        $this->pdo->prepare("INSERT INTO teacher_sections (user_id, institute_id, indirizzo, classe) VALUES (?, ?, 'ZSC', ?)")
            ->execute([$this->docente, $this->istituto, $classe]);
    }

    /** @return array{active:int, sospesa:int}|null */
    private function stato(string $code): ?array
    {
        $st = $this->pdo->prepare('SELECT active, sospesa_dalla_scuola FROM curriculum_teacher WHERE curriculum_id = ? AND user_id = ?');
        $st->execute([$this->voce[$code], $this->docente]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : ['active' => (int)$r['active'], 'sospesa' => (int)$r['sospesa_dalla_scuola']];
    }
}
