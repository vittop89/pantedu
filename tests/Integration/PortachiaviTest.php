<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Domain\ContentVisibilityPolicy;
use App\Domain\ViewerContext;
use App\Repositories\TeacherContentRepository;
use App\Repositories\TeacherCredentialRepository;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Piano classi-credenziali-scenari, C — il portachiavi: piu' credenziali,
 * ognuna nel proprio perimetro, dal gate fino alla query; e le credenziali
 * con scadenza, QR permanente e codice a tempo.
 *
 * Fixture isolata in transazione (rollback in tearDown): istituto, catalogo,
 * tre docenti (Rossi 3A, Verdi terza, un terzo fuori dal portachiavi).
 */
final class PortachiaviTest extends TestCase
{
    private PDO $pdo;
    private TeacherContentRepository $contents;
    private TeacherCredentialRepository $credentials;
    private int $instId = 0;
    private int $rossi = 0;
    private int $verdi = 0;
    private int $terzo = 0;
    private bool $inTx = false;

    private const IND  = 'ZPC';
    private const SUBJ = 'ZMP';

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
            $this->pdo->query('SELECT qr_token FROM teacher_access_credentials LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB o migration 105 non disponibili: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();
        $this->inTx = true;

        $this->pdo->prepare('INSERT INTO institutes (code, name, city, active) VALUES (?, ?, ?, 1)')
            ->execute(['ZZPORTA01', 'ISTITUTO PORTACHIAVI', 'Comune Esempio']);
        $this->instId = (int)$this->pdo->lastInsertId();
        // ADR-041 — la prova non riguarda chi dei docenti può usare le sezioni:
        // l'istituto vale «tutti», come ogni istituto prima della migrazione 126.
        $this->pdo->prepare("UPDATE institutes SET sezioni_docenti = 'tutti' WHERE id = ?")->execute([$this->instId]);
        $ins = $this->pdo->prepare(
            'INSERT INTO curriculum_entries (kind, institute_id, code, label, indirizzo, active, shared_with_pool)
             VALUES (?, ?, ?, ?, ?, 1, 0)'
        );
        // «3A» appartiene all'indirizzo (migrazione 100); dal 15/9/2026 anche gli
        // anni (ADR-042): «2» c'è in tutti e due i corsi, «3» solo nel primo.
        foreach ([
            ['indirizzi', self::IND, null], ['indirizzi', 'ZALT', null],
            ['classi', '2', self::IND], ['classi', '2', 'ZALT'], ['classi', '3', self::IND], ['classi', '3A', self::IND],
            ['materie', self::SUBJ, null],
        ] as [$k, $c, $ind]) {
            $ins->execute([$k, $this->instId, $c, $c, $ind]);
        }
        $this->rossi = $this->docente('zzrossi');
        $this->verdi = $this->docente('zzverdi');
        $this->terzo = $this->docente('zzterzo');
        $this->contents    = new TeacherContentRepository();
        $this->credentials = new TeacherCredentialRepository();
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function docente(string $username): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, created_at)
             VALUES (?, "teacher", "Zz", ?, ?, "x", "approved", 1, NOW())'
        )->execute([$username, $username, $username . '@example.invalid']);
        $id = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO teacher_institutes (user_id, institute_id) VALUES (?, ?)')->execute([$id, $this->instId]);
        return $id;
    }

    private function contenuto(int $teacherId, string $cls, string $type = 'esercizio'): int
    {
        return $this->contents->create([
            'teacher_id'   => $teacherId,
            'content_type' => $type,
            'subject_code' => self::SUBJ,
            'indirizzo'    => self::IND,
            'classe'       => $cls,
            'topic'        => 'PORTA_' . uniqid(),
            'title'        => "$type $cls $teacherId",
            'body_html'    => '<p>x</p>',
            'visibility'   => 'published',
        ]);
    }

    /** @return list<int> */
    private function vistoDalPortachiavi(array $grants, ?string $classe, string $type = 'esercizio'): array
    {
        $ctx = ViewerContext::forKeychain($grants);
        $fragment = (new ContentVisibilityPolicy())->studyListFilters($ctx, [], $classe, self::IND);
        $rows = $this->contents->search($fragment + [
            'content_type' => $type,
            'subject_code' => self::SUBJ,
            'indirizzo'    => self::IND,
            'classe'       => $classe ?? $ctx->classe,
            'limit'        => 500,
        ]);
        return array_map(static fn($r) => (int)$r['id'], $rows);
    }

    private function portachiavi(): array
    {
        return [
            ['teacher_id' => $this->rossi, 'indirizzo' => self::IND, 'classe' => '3A', 'credential_id' => 1, 'label' => 'Rossi 3A'],
            ['teacher_id' => $this->verdi, 'indirizzo' => self::IND, 'classe' => '3',  'credential_id' => 2, 'label' => 'Verdi terza'],
        ];
    }

    #[Test]
    public function ogni_credenziale_apre_solo_il_proprio_perimetro(): void
    {
        $rossi3A = $this->contenuto($this->rossi, '3A');
        $rossi3  = $this->contenuto($this->rossi, '3');
        $verdi3  = $this->contenuto($this->verdi, '3');
        $verdi3A = $this->contenuto($this->verdi, '3A');
        $terzo3A = $this->contenuto($this->terzo, '3A');

        $visti = $this->vistoDalPortachiavi($this->portachiavi(), '3A');
        $this->assertContains($rossi3A, $visti, 'Rossi 3A: la sua sezione');
        $this->assertContains($rossi3, $visti, 'Rossi 3: l\'anno copre la sezione');
        $this->assertContains($verdi3, $visti, 'Verdi 3: cio\' che la credenziale autorizza');
        $this->assertNotContains($verdi3A, $visti, 'Verdi 3A: la credenziale «3» non autorizza una sezione');
        $this->assertNotContains($terzo3A, $visti, 'un docente fuori dal portachiavi non si vede');
    }

    #[Test]
    public function senza_classe_chiesta_ogni_credenziale_vale_per_la_propria(): void
    {
        $rossi3A = $this->contenuto($this->rossi, '3A');
        $verdi3  = $this->contenuto($this->verdi, '3');
        $visti = $this->vistoDalPortachiavi($this->portachiavi(), null);
        $this->assertContains($rossi3A, $visti);
        $this->assertContains($verdi3, $visti);
    }

    #[Test]
    public function l_archivio_del_portachiavi_tiene_fuori_le_verifiche(): void
    {
        $rossi2   = $this->contenuto($this->rossi, '2');
        $rossiVer = $this->contenuto($this->rossi, '2', 'verifica');
        $this->assertContains($rossi2, $this->vistoDalPortachiavi($this->portachiavi(), '2'));
        $this->assertNotContains($rossiVer, $this->vistoDalPortachiavi($this->portachiavi(), '2', 'verifica'));
    }

    #[Test]
    public function la_credenziale_nasce_con_scadenza_e_qr_e_si_apre_anche_a_tempo(): void
    {
        $id = $this->credentials->create($this->rossi, [
            'username' => 'zz-rossi-3a', 'password' => 'segreto1',
            'indirizzo' => self::IND, 'classe' => '3A', 'institute_id' => $this->instId,
        ]);
        $row = $this->credentials->find($this->rossi, $id);
        $this->assertNotNull($row);
        $this->assertSame(TeacherCredentialRepository::defaultExpiry(), $row['expires_at']);
        $this->assertSame(43, strlen((string)$row['qr_token']));
        $this->assertSame('3A', $row['classe']);

        $viaQr = $this->credentials->findByToken((string)$row['qr_token']);
        $this->assertSame($id, (int)$viaQr['id'], 'il QR permanente apre');

        $temp = $this->credentials->issueTempToken($this->rossi, $id, 10);
        $this->assertNotNull($temp);
        $this->assertSame($id, (int)$this->credentials->findByToken($temp['token'])['id'], 'il codice a tempo apre');
        $this->assertNull($this->credentials->findByToken('non-un-codice-valido-davvero-no'));

        $this->assertNotNull($this->credentials->verify('zz-rossi-3a', 'segreto1'));
        $this->assertTrue($this->credentials->setPassword($this->rossi, $id, 'nuova-pw'));
        $this->assertNull($this->credentials->verify('zz-rossi-3a', 'segreto1'), 'la vecchia password non apre');
        $this->assertNotNull($this->credentials->verify('zz-rossi-3a', 'nuova-pw'));

        $this->assertSame([$row['qr_token']], $this->credentials->qrTokensFor([$id, 999999]));
        $this->assertSame($row['expires_at'], $this->credentials->earliestExpiry([$id]));
    }

    /**
     * 23/9/2026 (A-70) — il codice a tempo scade, e scade quando deve.
     *
     * La scadenza si scriveva con l'ora di PHP (Europe/Rome) e si confrontava
     * con NOW() del database, che in produzione è in UTC: un codice da dieci
     * minuti apriva per circa 130 minuti d'estate e 70 d'inverno. La prova
     * sopra guardava solo che il codice aprisse. Qui si fissano i fusi, qualunque
     * sia quello del database di chi la lancia: la sessione del database in
     * UTC e PHP in Europe/Rome, come in produzione; e la sessione del database
     * in +02:00 con PHP in UTC, perché la scadenza deve seguire l'orologio con
     * cui si confronta (NOW() della sessione), non l'UTC del server né quello
     * di PHP. Poi si fa passare il tempo spostando indietro la scadenza. Sul
     * codice di prima falliva la prima misura: circa 7.800 secondi al posto di
     * 600 nel primo caso, -6.600 nel secondo; con `UTC_TIMESTAMP()` al posto
     * di `NOW()` il secondo caso misura -6.600.
     */
    #[Test]
    #[DataProvider('fusi')]
    public function il_codice_a_tempo_scade_dopo_i_suoi_minuti_con_l_orologio_del_database(
        string $fusoDelDatabase,
        string $fusoDiPhp,
    ): void {
        $id = $this->credentials->create($this->rossi, [
            'username' => 'zz-tempo-' . bin2hex(random_bytes(3)), 'password' => 'segreto1',
            'indirizzo' => self::IND, 'classe' => '3A', 'institute_id' => $this->instId,
        ]);
        $fusoDb  = (string)$this->pdo->query('SELECT @@session.time_zone')->fetchColumn();
        $fusoPhp = date_default_timezone_get();
        $this->pdo->exec('SET time_zone = ' . $this->pdo->quote($fusoDelDatabase));
        date_default_timezone_set($fusoDiPhp);
        try {
            $temp = $this->credentials->issueTempToken($this->rossi, $id, 10);
            $this->assertNotNull($temp);
            $restano = $this->pdo->prepare(
                'SELECT TIMESTAMPDIFF(SECOND, NOW(), temp_token_expires_at)
                   FROM teacher_access_credentials_data WHERE id = ?'
            );
            $restano->execute([$id]);
            $secondi = (int)$restano->fetchColumn();
            $this->assertGreaterThan(540, $secondi, 'dieci minuti per l\'orologio del database');
            $this->assertLessThanOrEqual(600, $secondi, 'e non di più');

            // Al chiamante la scadenza nell'ora dell'applicazione, quella che
            // vede il docente: anche lei fra dieci minuti da adesso.
            $this->assertEqualsWithDelta(time() + 600, (int)strtotime($temp['expires_at']), 5);

            $passano = $this->pdo->prepare(
                'UPDATE teacher_access_credentials_data
                    SET temp_token_expires_at = temp_token_expires_at - INTERVAL ? MINUTE WHERE id = ?'
            );
            $passano->execute([9, $id]);
            $aperta = $this->credentials->findByToken($temp['token']);
            $this->assertSame($id, (int)($aperta['id'] ?? 0), 'dopo 9 minuti apre');
            $passano->execute([2, $id]);
            $this->assertNull($this->credentials->findByToken($temp['token']), 'dopo 11 minuti no');
            $this->assertNotNull(
                $this->credentials->findByToken((string)$this->credentials->find($this->rossi, $id)['qr_token']),
                'e il QR permanente della stessa credenziale apre ancora: è scaduto il codice, non la credenziale'
            );
        } finally {
            $this->pdo->exec('SET time_zone = ' . $this->pdo->quote($fusoDb));
            date_default_timezone_set($fusoPhp);
        }
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function fusi(): array
    {
        return [
            'database in UTC, PHP in Europe/Rome (produzione)' => ['+00:00', 'Europe/Rome'],
            'database in +02:00, PHP in UTC'                   => ['+02:00', 'UTC'],
        ];
    }

    #[Test]
    public function un_codice_a_tempo_con_la_scadenza_passata_non_apre(): void
    {
        $id = $this->credentials->create($this->rossi, [
            'username' => 'zz-tempo-' . bin2hex(random_bytes(3)), 'password' => 'segreto1',
            'institute_id' => $this->instId,
        ]);
        $temp = $this->credentials->issueTempToken($this->rossi, $id, 10);
        $this->assertNotNull($temp);
        $this->pdo->prepare(
            'UPDATE teacher_access_credentials_data SET temp_token_expires_at = NOW() - INTERVAL 1 SECOND WHERE id = ?'
        )->execute([$id]);
        $this->assertNull($this->credentials->findByToken($temp['token']));
    }

    #[Test]
    public function scaduta_o_spenta_non_apre_ne_con_password_ne_con_qr(): void
    {
        $id = $this->credentials->create($this->verdi, [
            'username' => 'zz-verdi', 'password' => 'segreto1', 'institute_id' => $this->instId,
        ]);
        $qr = (string)$this->credentials->find($this->verdi, $id)['qr_token'];
        // ADR-044 — il docente non può più mettere una scadenza passata: la
        // credenziale scaduta la fa il tempo, qui una UPDATE.
        $this->pdo->prepare('UPDATE teacher_access_credentials_data SET expires_at = "2020-01-01" WHERE id = ?')->execute([$id]);
        $this->assertNull($this->credentials->verify('zz-verdi', 'segreto1'), 'scaduta');
        $this->assertNull($this->credentials->findByToken($qr), 'scaduta anche via QR');
        $this->assertSame([], $this->credentials->qrTokensFor([$id]));

        $this->assertTrue($this->credentials->setExpiry($this->verdi, $id, null));
        $this->assertNotNull($this->credentials->verify('zz-verdi', 'segreto1'), 'senza scadenza apre');
        $this->assertTrue($this->credentials->setActive($this->verdi, $id, false));
        $this->assertNull($this->credentials->findByToken($qr), 'spenta');

        $this->expectException(\InvalidArgumentException::class);
        $this->credentials->setExpiry($this->verdi, $id, '31/08/2027');
    }

    #[Test]
    public function la_classe_corregge_un_indirizzo_sbagliato(): void
    {
        // Il selettore in sidebar era sull'altro corso: la 3A sta sotto ZPC e
        // la credenziale deve dirlo, altrimenti lo studente vede «3A ZALT».
        $id = $this->credentials->create($this->rossi, [
            'username' => 'zz-rossi-3a-ind', 'password' => 'segreto1',
            'indirizzo' => 'ZALT', 'classe' => '3A', 'institute_id' => $this->instId,
        ]);
        $row = $this->credentials->find($this->rossi, $id);
        $this->assertSame('3A', $row['classe']);
        $this->assertSame(self::IND, $row['indirizzo'], 'vince l\'indirizzo della classe');

        // Un anno resta sotto l'indirizzo scelto, ed è l'anno di quel corso.
        $id2 = $this->credentials->create($this->rossi, [
            'username' => 'zz-rossi-2-ind', 'password' => 'segreto1',
            'indirizzo' => 'ZALT', 'classe' => '2', 'institute_id' => $this->instId,
        ]);
        $this->assertSame('ZALT', $this->credentials->find($this->rossi, $id2)['indirizzo']);
        $st = $this->pdo->prepare('SELECT c.indirizzo FROM teacher_access_credentials_data t JOIN curriculum_entries c ON c.id = t.classe_id WHERE t.id = ?');
        $st->execute([$id2]);
        $this->assertSame('ZALT', $st->fetchColumn(), 'la «2» di ZALT, non quella dell\'altro corso');
    }

    #[Test]
    public function un_anno_che_il_corso_non_ha_non_diventa_l_anno_di_un_altro_corso(): void
    {
        // ADR-042 — ZALT non ha la terza: «3» con ZALT non si risolve nella «3»
        // dell'altro indirizzo. Prima, con un anno per tutta la scuola, passava.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_classe');
        $this->credentials->create($this->rossi, [
            'username' => 'zz-rossi-3-alt', 'password' => 'segreto1',
            'indirizzo' => 'ZALT', 'classe' => '3', 'institute_id' => $this->instId,
        ]);
    }

    #[Test]
    public function l_etichetta_rigenerata_arriva_a_chi_e_gia_entrato(): void
    {
        // ADR-044 — ClassAccessGrant::revalidate rileggeva solo id, active ed
        // expires_at: un'etichetta cambiata dal docente non arrivava agli
        // studenti già entrati finché la sessione durava.
        $materia = (int)$this->pdo->query(
            "SELECT id FROM curriculum_entries WHERE institute_id = {$this->instId} AND kind = 'materie' AND code = '" . self::SUBJ . "'"
        )->fetchColumn();
        $this->pdo->prepare('UPDATE curriculum_entries SET label = "Materia di prova" WHERE id = ?')->execute([$materia]);
        $this->pdo->prepare('INSERT INTO curriculum_teacher (curriculum_id, user_id, active, sospesa_dalla_scuola) VALUES (?, ?, 1, 0)')
            ->execute([$materia, $this->rossi]);
        $creata = $this->credentials->crea($this->rossi, [
            'username' => 'zz-rossi-eti', 'password' => 'segreto1',
            'indirizzo' => self::IND, 'classe' => '3A', 'institute_id' => $this->instId,
        ]);
        $this->assertSame('3A_' . self::IND, $creata['label']);

        $_SESSION = [];
        \App\Support\ClassAccessGrant::resetCache();
        try {
            \App\Support\ClassAccessGrant::add([
                'teacher_id' => $this->rossi, 'institute_id' => $this->instId, 'indirizzo' => self::IND, 'classe' => '3A',
                'label' => $creata['label'], 'credential_id' => $creata['id'], 'granted_at' => time(),
            ]);
            \App\Support\ClassAccessGrant::resetCache();
            $prima = \App\Support\ClassAccessGrant::all();
            $this->assertSame('3A_' . self::IND, $prima[0]['label']);
            $this->assertNull($prima[0]['materie_nomi'], 'senza materie niente nomi');

            $nuova = $this->credentials->setEtichetta($this->rossi, $creata['id'], self::SUBJ, 'b');
            $this->assertSame('3A_' . self::IND . '_' . self::SUBJ . '_B', $nuova);

            \App\Support\ClassAccessGrant::resetCache(); // la richiesta successiva
            $dopo = \App\Support\ClassAccessGrant::all();
            $this->assertCount(1, $dopo, 'la credenziale resta nel portachiavi');
            $this->assertSame($nuova, $dopo[0]['label'], 'con l\'etichetta nuova');
            $this->assertSame('Materia di prova', $dopo[0]['materie_nomi'], 'e i nomi delle materie per il title');
            $this->assertSame([], \App\Support\ClassAccessGrant::notices(), 'nessun avviso: la credenziale non è caduta');

            \App\Support\ClassAccessGrant::resetCache();
            $this->assertSame($nuova, \App\Support\ClassAccessGrant::all()[0]['label'], 'ed è salvata in sessione');
        } finally {
            $_SESSION = [];
            \App\Support\ClassAccessGrant::resetCache();
        }
    }

    #[Test]
    public function un_altro_docente_non_tocca_la_credenziale(): void
    {
        $id = $this->credentials->create($this->rossi, [
            'username' => 'zz-rossi-x', 'password' => 'segreto1', 'institute_id' => $this->instId,
        ]);
        $this->assertNull($this->credentials->find($this->verdi, $id));
        $this->assertFalse($this->credentials->setPassword($this->verdi, $id, 'altra-pw'));
        $this->assertNull($this->credentials->regenerateQrToken($this->verdi, $id));
        $this->assertNull($this->credentials->issueTempToken($this->verdi, $id));
    }
}
