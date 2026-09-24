<?php

declare(strict_types=1);

namespace Tests\Integration\Gdpr;

use App\Core\Config;
use App\Core\Database;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Gdpr\AnonimizzazioneDegliInattivi;
use App\Services\Gdpr\CancellazioneDellAccount;
use App\Services\Mailer;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Gli account inattivi ricevono tre avvisi prima della cancellazione
 * (24/9/2026, decisione del titolare).
 *
 * 730 giorni senza accessi; avvisi a 60, 30 e 7 giorni; un accesso azzera il
 * conto; nessun account si cancella se l'avviso dei 7 giorni non è partito da
 * almeno 7 giorni; senza email, o con l'invio che fallisce, niente
 * cancellazione; mai gli amministratori della piattaforma.
 *
 * L'orologio è un parametro: le prove spostano l'«adesso» invece di aspettare.
 * Sul codice di prima (cancellazione a 730 giorni senza avvisi) cade la prova
 * che nessuno si cancella senza l'ultimo avviso.
 */
final class AvvisiDiInattivitaTest extends TestCase
{
    private PDO $pdo;
    private string $marca = '';
    /** @var list<int> */
    private array $utenti = [];
    /** @var list<array{to:string, subject:string, body:string}> */
    private array $inviate = [];
    private bool $accetta = true;
    private string $radice = '';
    /** @var array<string, mixed> */
    private array $configPrima = [];

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 3);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM avvisi_di_inattivita LIMIT 0');
        } catch (\Throwable $e) {
            self::markTestSkipped('DB o tabella avvisi_di_inattivita non disponibili: ' . $e->getMessage());
        }
        // Date nel passato lontano (1992-1998), come la prova della
        // cancellazione: nessun account vero è così vecchio. Se lo fosse, ci si
        // ferma invece di avvisarlo o cancellarlo.
        $altri = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM users WHERE username NOT LIKE 'zz_inatt_%'
               AND COALESCE(last_access_at, approved_at, created_at) < '1999-01-01'"
        )->fetchColumn();
        if ($altri > 0) {
            self::fail("$altri account non della prova hanno attività prima del 1999: mi fermo per non toccarli.");
        }
        $this->marca = date('ymdHis') . bin2hex(random_bytes(3));
        $this->radice = sys_get_temp_dir() . '/pantedu-inattivi-' . $this->marca;
        mkdir($this->radice . '/storage/objects', 0700, true);
        foreach (['app.url', 'app.paths.storage', 'storage.local.root', 'storage.default_provider', 'app.paths.logs'] as $k) {
            $this->configPrima[$k] = Config::get($k);
        }
        Config::set('app.url', 'https://istanza.example.test');
        Config::set('app.paths.storage', $this->radice . '/storage');
        Config::set('app.paths.logs', $this->radice);
        Config::set('storage.local.root', $this->radice . '/storage/objects');
        Config::set('storage.default_provider', 'local');
    }

    protected function tearDown(): void
    {
        if ($this->utenti !== []) {
            $in = implode(',', array_fill(0, count($this->utenti), '?'));
            $this->pdo->prepare("DELETE FROM crypto_access_log WHERE teacher_id IN ($in)")->execute($this->utenti);
            $this->pdo->prepare("DELETE FROM users WHERE id IN ($in)")->execute($this->utenti);
        }
        foreach ($this->configPrima as $k => $v) {
            Config::set($k, $v);
        }
        if ($this->radice !== '' && is_dir($this->radice)) {
            exec('rm -rf ' . escapeshellarg($this->radice));
        }
    }

    private function utente(string $nome, string $ultimoAccesso, string $ruolo = 'teacher', int $super = 0, ?string $email = null): int
    {
        $username = 'zz_inatt_' . $nome . '_' . $this->marca;
        $this->pdo->prepare(
            "INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active,
                                is_super_admin, created_at, approved_at, last_access_at)
             VALUES (?, ?, 'Prova', 'Inattivo', ?, 'x', 'approved', 1, ?, '1992-01-01 10:00:00', '1992-01-02 10:00:00', ?)"
        )->execute([$username, $ruolo, $email ?? ($username . '@example.test'), $super, $ultimoAccesso]);
        $id = (int)$this->pdo->lastInsertId();
        $this->utenti[] = $id;
        return $id;
    }

    private function giro(string $adesso, bool $prova = false): array
    {
        $mailer = new Mailer('noreply@istanza.example.test', 'Pantedu', function (
            string $to,
            string $subject,
            string $body,
            string $headers
        ): bool {
            $this->inviate[] = ['to' => $to, 'subject' => (string)iconv_mime_decode($subject, 0, 'UTF-8'), 'body' => $body];
            return $this->accetta;
        });
        $cancellazione = new CancellazioneDellAccount(
            $this->pdo,
            new TeacherCryptoService(bin2hex(random_bytes(32))),
            $this->radice . '/storage',
            $this->radice . '/storage/objects',
        );
        return (new AnonimizzazioneDegliInattivi($this->pdo, $cancellazione, static fn(): Mailer => $mailer))
            ->esegui(new DateTimeImmutable($adesso), 730, $prova);
    }

    private function stato(int $id): string
    {
        $st = $this->pdo->prepare('SELECT status FROM users WHERE id = ?');
        $st->execute([$id]);
        return (string)$st->fetchColumn();
    }

    /** @return list<array{to:string, subject:string, body:string}> */
    private function posta(int $id): array
    {
        $st = $this->pdo->prepare('SELECT email FROM users WHERE id = ?');
        $st->execute([$id]);
        $email = (string)$st->fetchColumn();
        return array_values(array_filter($this->inviate, fn(array $m): bool => $m['to'] === $email));
    }

    #[Test]
    public function tre_avvisi_poi_la_cancellazione_e_un_avviso_non_si_ripete(): void
    {
        // Ultimo accesso: 1/1/1996 → cancellazione il 31/12/1997 (730 giorni).
        $id = $this->utente('tre', '1996-01-01 12:00:00');

        $this->giro('1997-10-20 03:00:00');                 // mancano ~72 giorni: niente
        self::assertCount(0, $this->posta($id));

        $this->giro('1997-11-02 03:00:00');                 // ~59 giorni: primo avviso
        $this->giro('1997-11-02 04:00:00');                 // stesso giorno: non si ripete
        self::assertCount(1, $this->posta($id));
        self::assertStringContainsString('31/12/1997', $this->posta($id)[0]['subject']);

        $this->giro('1997-12-02 03:00:00');                 // ~29 giorni: secondo
        $this->giro('1997-12-25 03:00:00');                 // ~6 giorni: terzo, l'ultimo
        self::assertCount(3, $this->posta($id));
        self::assertStringContainsString("È l'ultimo avviso", $this->posta($id)[2]['body']);

        $this->giro('1997-12-30 03:00:00');                 // prima della data: resta
        self::assertNotSame('anonymized', $this->stato($id));

        $esito = $this->giro('1998-01-02 03:00:00');        // data passata, ultimo avviso da 8 giorni
        self::assertSame(1, $esito['cancellati']);
        self::assertSame('anonymized', $this->stato($id));
    }

    #[Test]
    public function nessuno_si_cancella_senza_l_ultimo_avviso_partito_da_sette_giorni(): void
    {
        // Inattivo da anni, nessun avviso mai partito (il lavoro non girava).
        $id = $this->utente('saltati', '1995-01-01 12:00:00');

        $this->giro('1998-01-10 03:00:00');
        self::assertNotSame('anonymized', $this->stato($id), 'mai cancellato senza avviso');
        self::assertCount(1, $this->posta($id), 'parte solo l\'avviso più avanzato, non tutti quelli persi');
        self::assertStringContainsString('17/01/1998', $this->posta($id)[0]['subject'], 'e dice la data vera: fra 7 giorni');

        $this->giro('1998-01-15 03:00:00');                 // 5 giorni dopo l'avviso: ancora no
        self::assertNotSame('anonymized', $this->stato($id));

        $this->giro('1998-01-17 04:00:00');                 // 7 giorni dopo: sì
        self::assertSame('anonymized', $this->stato($id));
    }

    #[Test]
    public function un_accesso_azzera_il_conto(): void
    {
        $id = $this->utente('rientra', '1996-01-01 12:00:00');
        $this->giro('1997-12-25 03:00:00');
        self::assertCount(1, $this->posta($id));

        $this->pdo->prepare("UPDATE users SET last_access_at = '1997-12-27 09:00:00' WHERE id = ?")->execute([$id]);
        $this->giro('1998-01-05 03:00:00');

        self::assertNotSame('anonymized', $this->stato($id));
        self::assertCount(1, $this->posta($id), 'nessun avviso nuovo: la data è di nuovo lontana');
    }

    #[Test]
    public function se_l_email_non_parte_l_account_resta(): void
    {
        $id = $this->utente('rifiutata', '1995-01-01 12:00:00');
        $this->accetta = false;

        $esito = $this->giro('1998-01-10 03:00:00');
        $this->giro('1998-01-20 03:00:00');
        $this->giro('1998-02-10 03:00:00');

        self::assertNotSame('anonymized', $this->stato($id));
        self::assertNotSame([], $esito['avvisi_non_partiti']);
        self::assertSame([], $esito['errori'], 'un avviso non partito non fa fallire il giro');
    }

    #[Test]
    public function senza_email_non_si_avvisa_e_non_si_cancella(): void
    {
        $id = $this->utente('senzaemail', '1995-01-01 12:00:00', email: '');

        $esito = $this->giro('1998-06-01 03:00:00');

        self::assertNotSame('anonymized', $this->stato($id));
        self::assertNotSame([], $esito['avvisi_non_partiti']);
    }

    #[Test]
    public function gli_amministratori_della_piattaforma_non_si_toccano(): void
    {
        $super = $this->utente('super', '1995-01-01 12:00:00', 'teacher', 1);
        $admin = $this->utente('admin', '1995-01-01 12:00:00', 'administrator');

        for ($m = 1; $m <= 3; $m++) {
            $this->giro(sprintf('1998-%02d-15 03:00:00', $m));
        }

        self::assertSame([], $this->posta($super));
        self::assertSame([], $this->posta($admin));
        self::assertNotSame('anonymized', $this->stato($super));
        self::assertNotSame('anonymized', $this->stato($admin));
    }

    #[Test]
    public function in_prova_non_manda_e_non_cancella(): void
    {
        $id = $this->utente('prova', '1995-01-01 12:00:00');

        $esito = $this->giro('1998-06-01 03:00:00', true);

        self::assertSame([], $this->posta($id));
        self::assertNotSame('anonymized', $this->stato($id));
        self::assertNotSame([], $esito['avvisi'], 'ma dice che cosa farebbe');
    }
}
