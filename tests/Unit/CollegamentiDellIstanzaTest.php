<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Public\PublicTakedownController;
use App\Core\Config;
use App\Services\Crypto\CustodyNotifier;
use App\Services\Gdpr\TakedownRequestService;
use App\Services\Mailer;
use App\Services\RegistrationMailer;
use App\Services\Risdoc\SpazzataDelleBozze;
use App\Services\Security\CambioEmail;
use App\Services\Security\EmailSecondFactor;
use App\Services\Security\PasswordResetService;
use App\Support\Anomalia;
use App\Support\IndirizzoPubblico;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * I collegamenti e le caselle di un'istanza sono i suoi (23/9/2026, rilievo
 * A-13 della revisione architetturale).
 *
 * Fino a quel giorno il dominio di produzione stava nel codice in tre forme:
 * come ripiego di `app.url`, come collegamento fisso, come casella fissa. Su
 * un'altra istanza — o in CI con `app.url` vuota — il reset della password
 * mandava il gettone con un collegamento al nostro sito, il codice del secondo
 * fattore rimandava a noi per cambiare la password, e le segnalazioni di
 * contenuti, con nome, email e IP di chi segnala, arrivavano alla nostra
 * casella anche con la posta configurata.
 *
 * Qui, nei due versi, un flusso per volta:
 *   - con `app.url` di un'altra istanza, i collegamenti sono i suoi;
 *   - con `app.url` vuota, nessun collegamento al dominio di produzione: i
 *     messaggi il cui collegamento è la ragione d'essere non partono (e non
 *     lasciano gettoni), gli altri partono senza, e in tutti e due i casi
 *     l'anomalia `indirizzo_pubblico_mancante` resta nel registro;
 *   - le segnalazioni vanno alla casella configurata, e senza casella non
 *     vanno a nessun altro.
 *
 * Posta finta e database SQLite in memoria: niente esce, niente resta.
 */
final class CollegamentiDellIstanzaTest extends TestCase
{
    private const ALTRA = 'https://scuola.example';

    /**
     * Nelle pagine si cerca una casella o un collegamento, non il nome: la
     * pagina porta il layout, e il layout la nota di copyright con il nome
     * dell'opera (l'eccezione motivata di DominioNonScrittoNelCodiceTest).
     */
    private const CASELLA_O_LINK_DI_PRODUZIONE = '#@pantedu\.eu|//(www\.)?pantedu\.eu#i';

    /** @var list<array{to: string, subject: string, body: string, hdrs: string}> */
    private array $posta = [];

    /** @var array<string, mixed> */
    private array $configPrima = [];
    private string $cartella;
    private string|false $errorLogPrima;

    protected function setUp(): void
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite non disponibile in questo runtime');
        }
        foreach (['app.url', 'app.paths.logs', 'mail.contact_email', 'mail.dpo_email', 'mail.abuse_email'] as $k) {
            $this->configPrima[$k] = Config::get($k);
        }
        // Registro delle anomalie ed error_log in una cartella della prova.
        $this->cartella = sys_get_temp_dir() . '/pantedu-collegamenti-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);
        Config::set('app.paths.logs', $this->cartella);
        $this->errorLogPrima = ini_set('error_log', $this->cartella . '/php_errors.log');
        // Le caselle di un'altra istanza: quelle del `.env` di chi fa girare
        // la prova non devono contare.
        Config::set('mail.contact_email', 'contatti@scuola.example');
        Config::set('mail.dpo_email', 'dpo@scuola.example');
        Config::set('mail.abuse_email', 'segnalazioni@scuola.example');
    }

    protected function tearDown(): void
    {
        foreach ($this->configPrima as $k => $v) {
            Config::set($k, $v);
        }
        ini_set('error_log', $this->errorLogPrima === false ? '' : $this->errorLogPrima);
        foreach (glob($this->cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->cartella);
    }

    private function postino(): Mailer
    {
        return new Mailer('noreply@scuola.example', 'Pantedu', function (string $to, string $s, string $body, string $hdrs): bool {
            $this->posta[] = ['to' => $to, 'subject' => (string)mb_decode_mimeheader($s), 'body' => $body, 'hdrs' => $hdrs];
            return true;
        });
    }

    /** Tutto quello che è uscito, intestazioni comprese: il dominio non c'è. */
    private function nienteVersoLaProduzione(): void
    {
        foreach ($this->posta as $m) {
            self::assertStringNotContainsStringIgnoringCase('pantedu.eu', $m['to'] . $m['hdrs'] . $m['body']);
        }
    }

    /** @return list<string> i flussi con un'anomalia indirizzo_pubblico_mancante */
    private function flussiSenzaRadice(): array
    {
        $flussi = [];
        foreach (Anomalia::recenti() as $riga) {
            if (($riga['codice'] ?? '') === IndirizzoPubblico::ANOMALIA) {
                $flussi[] = (string)($riga['dettagli']['flusso'] ?? '');
            }
        }
        return $flussi;
    }

    private function sqlite(string ...$tabelle): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL,
                first_name TEXT, last_name TEXT, email TEXT, password_hash TEXT NOT NULL DEFAULT "",
                must_change_password INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1
            )'
        );
        foreach ($tabelle as $sql) {
            $pdo->exec($sql);
        }
        $pdo->prepare('INSERT INTO users (username, first_name, email, password_hash) VALUES (?, ?, ?, ?)')
            ->execute(['zz_docente', 'Zz', 'zz.docente@example.invalid', password_hash('una-password-lunga', PASSWORD_BCRYPT)]);
        return $pdo;
    }

    // ── Recupero della password: il collegamento è il messaggio ─────────

    private function recupero(): PDO
    {
        return $this->sqlite(
            'CREATE TABLE password_resets (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, used_at TEXT DEFAULT NULL,
                requested_ip_hash TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    #[Test]
    public function il_reset_di_un_altra_istanza_porta_al_suo_sito(): void
    {
        Config::set('app.url', self::ALTRA . '/');
        (new PasswordResetService($this->postino(), $this->recupero()))->request('zz.docente@example.invalid', '127.0.0.1');

        self::assertCount(1, $this->posta);
        self::assertMatchesRegularExpression('#https://scuola\.example/password/reset\?token=[0-9a-f]{64}#', $this->posta[0]['body']);
        $this->nienteVersoLaProduzione();
    }

    #[Test]
    public function senza_app_url_il_reset_non_manda_niente_e_non_lascia_gettoni(): void
    {
        Config::set('app.url', '');
        $pdo = $this->recupero();
        (new PasswordResetService($this->postino(), $pdo))->request('zz.docente@example.invalid', '127.0.0.1');

        self::assertSame([], $this->posta, 'un gettone senza collegamento non si manda');
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn(), 'e non si emette');
        self::assertSame(['recupero_password'], $this->flussiSenzaRadice());
    }

    // ── Secondo fattore: il messaggio è il codice ─────────────────────

    private function secondoFattore(): PDO
    {
        return $this->sqlite(
            'CREATE TABLE two_factor_email_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, code_hash TEXT NOT NULL,
                purpose TEXT NOT NULL DEFAULT "login", expires_at TEXT NOT NULL, used_at TEXT DEFAULT NULL,
                attempts INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL
            )'
        );
    }

    #[Test]
    public function il_codice_di_un_altra_istanza_rimanda_al_suo_sito(): void
    {
        Config::set('app.url', self::ALTRA);
        self::assertTrue((new EmailSecondFactor($this->postino(), $this->secondoFattore()))->issue('zz_docente'));

        self::assertCount(1, $this->posta);
        self::assertStringContainsString('https://scuola.example/me/change-password', $this->posta[0]['body']);
        $this->nienteVersoLaProduzione();
    }

    #[Test]
    public function senza_app_url_il_codice_parte_lo_stesso_senza_collegamento(): void
    {
        Config::set('app.url', '');
        self::assertTrue(
            (new EmailSecondFactor($this->postino(), $this->secondoFattore()))->issue('zz_docente'),
            'bloccare l\'accesso per un collegamento di cortesia sarebbe peggio'
        );

        self::assertCount(1, $this->posta);
        self::assertMatchesRegularExpression('/^    \d{6}$/m', $this->posta[0]['body'], 'il codice c\'è');
        self::assertStringNotContainsString('://', $this->posta[0]['body']);
        self::assertStringContainsString('dalla pagina del tuo account', $this->posta[0]['body']);
        $this->nienteVersoLaProduzione();
        self::assertSame(['secondo_fattore'], $this->flussiSenzaRadice());
    }

    // ── Cambio dell'email: il collegamento è il messaggio ──────────────

    private function cambioEmail(): PDO
    {
        return $this->sqlite(
            'CREATE TABLE email_change_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, new_email TEXT NOT NULL,
                token_hash TEXT NOT NULL, expires_at TEXT NOT NULL, used_at TEXT DEFAULT NULL,
                requested_ip_hash TEXT, created_at TEXT NOT NULL
            )'
        );
    }

    #[Test]
    public function il_cambio_email_di_un_altra_istanza_porta_al_suo_sito_e_alla_sua_casella(): void
    {
        Config::set('app.url', self::ALTRA);
        $postino = $this->postino();
        $esito = (new CambioEmail($this->cambioEmail(), static fn(): Mailer => $postino))
            ->richiedi(1, 'una-password-lunga', 'zz.nuova@example.invalid', '127.0.0.1');

        self::assertSame(CambioEmail::RICHIESTA_INVIATA, $esito);
        self::assertCount(2, $this->posta, 'il link al nuovo indirizzo e l\'avviso al vecchio');
        self::assertMatchesRegularExpression('#https://scuola\.example/me/account/email/conferma\?token=[0-9a-f]{64}#', $this->posta[0]['body']);
        self::assertStringContainsString('https://scuola.example/me/change-password e scrivi a dpo@scuola.example', $this->posta[1]['body']);
        $this->nienteVersoLaProduzione();
    }

    #[Test]
    public function senza_app_url_il_cambio_email_non_parte_e_non_scrive_richieste(): void
    {
        Config::set('app.url', '');
        $pdo = $this->cambioEmail();
        $postino = $this->postino();
        $esito = (new CambioEmail($pdo, static fn(): Mailer => $postino))
            ->richiedi(1, 'una-password-lunga', 'zz.nuova@example.invalid', '127.0.0.1');

        self::assertSame(CambioEmail::ERRORE, $esito);
        self::assertSame([], $this->posta);
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM email_change_requests')->fetchColumn());
        self::assertSame(['cambio_email'], $this->flussiSenzaRadice());
    }

    // ── Iscrizione: il messaggio è l'esito ─────────────────────────────

    private function iscrizione(): RegistrationMailer
    {
        // Null: la radice la chiede il mailer all'invio, come fa il controller.
        return new RegistrationMailer($this->postino(), null, $this->cartella . '/mail.log', 'contatti@scuola.example');
    }

    #[Test]
    public function l_iscrizione_di_un_altra_istanza_porta_al_suo_sito(): void
    {
        Config::set('app.url', self::ALTRA);
        $this->iscrizione()->pending('anna@example.invalid', 'Anna');
        $this->iscrizione()->approved('anna@example.invalid', 'Anna', 'anna.rossi');

        self::assertStringContainsString('Puoi accedere a https://scuola.example una volta', $this->posta[0]['body']);
        self::assertStringContainsString('Accedi qui: https://scuola.example/login', $this->posta[1]['body']);
        self::assertStringContainsString("Reply-To: contatti@scuola.example\r\n", $this->posta[1]['hdrs']);
        $this->nienteVersoLaProduzione();
    }

    #[Test]
    public function senza_app_url_l_iscrizione_approvata_parte_senza_collegamento(): void
    {
        Config::set('app.url', '');
        self::assertTrue($this->iscrizione()->approved('anna@example.invalid', 'Anna', 'anna.rossi'));

        self::assertStringContainsString('anna.rossi', $this->posta[0]['body']);
        self::assertStringNotContainsString('://', $this->posta[0]['body']);
        $this->nienteVersoLaProduzione();
        self::assertSame(['iscrizione'], $this->flussiSenzaRadice());
    }

    // ── Avviso delle bozze: il collegamento è l'azione da fare ─────────

    /** @return list<array<string, mixed>> */
    private static function bozze(): array
    {
        return [['id' => 1, 'etichetta' => 'Piano 3A', 'modello' => 'Piano annuale', 'scade' => '2026-10-01', 'motivo' => 'scaricata']];
    }

    #[Test]
    public function l_avviso_delle_bozze_di_un_altra_istanza_porta_al_suo_sito(): void
    {
        Config::set('app.url', self::ALTRA);
        $postino = $this->postino();
        $esito = (new SpazzataDelleBozze($this->sqlite(), static fn(): Mailer => $postino))->avvisa(1, self::bozze());

        self::assertSame(SpazzataDelleBozze::INVIATA, $esito);
        self::assertStringContainsString('Le tue compilazioni: https://scuola.example/area-docente', $this->posta[0]['body']);
        $this->nienteVersoLaProduzione();
    }

    #[Test]
    public function senza_app_url_l_avviso_delle_bozze_non_parte_e_la_notte_dopo_si_ritenta(): void
    {
        // NON_PARTITA è l'esito che il giro notturno non segna come avvisato:
        // niente avviso, niente cancellazione, si riprova (SpazzataDelleBozze::gira).
        Config::set('app.url', '');
        $postino = $this->postino();
        $esito = (new SpazzataDelleBozze($this->sqlite(), static fn(): Mailer => $postino))->avvisa(1, self::bozze());

        self::assertSame(SpazzataDelleBozze::NON_PARTITA, $esito);
        self::assertSame([], $this->posta);
        self::assertSame(['avviso_bozze'], $this->flussiSenzaRadice());
    }

    // ── Avviso di custodia: dovuto all'interessato ────────────────────

    #[Test]
    public function l_avviso_di_custodia_di_un_altra_istanza_porta_al_suo_sito_e_al_suo_dpo(): void
    {
        Config::set('app.url', self::ALTRA);
        self::assertTrue(CustodyNotifier::notify('kek_emergency_access', 1, '2026-09-23 10:00:00', 'art. 6', $this->postino(), $this->sqlite()));

        self::assertCount(1, $this->posta);
        self::assertSame('zz.docente@example.invalid', $this->posta[0]['to']);
        self::assertStringContainsString('https://scuola.example/me/custody-events', $this->posta[0]['body']);
        self::assertStringContainsString('o scrivi a dpo@scuola.example.', $this->posta[0]['body']);
        self::assertStringContainsString("Reply-To: dpo@scuola.example\r\n", $this->posta[0]['hdrs']);
        $this->nienteVersoLaProduzione();
    }

    #[Test]
    public function senza_app_url_e_senza_dpo_l_avviso_di_custodia_parte_lo_stesso(): void
    {
        Config::set('app.url', '');
        Config::set('mail.dpo_email', '');
        self::assertTrue(CustodyNotifier::notify('data_recovered', 1, '2026-09-23 10:00:00', null, $this->postino(), $this->sqlite()));

        self::assertCount(1, $this->posta);
        self::assertStringNotContainsString('://', $this->posta[0]['body']);
        self::assertStringContainsString('dalla pagina «Eventi di custodia» del tuo account', $this->posta[0]['body']);
        self::assertStringNotContainsString('dpo@', $this->posta[0]['hdrs'] . $this->posta[0]['body']);
        $this->nienteVersoLaProduzione();
        self::assertSame(['avviso_custodia'], $this->flussiSenzaRadice());
    }

    // ── Segnalazioni di contenuti: alla casella configurata ───────────

    private function segnalazioni(): PublicTakedownController
    {
        $servizio = new class extends TakedownRequestService {
            public function submit(array $data): int
            {
                return 77;
            }
        };
        return new PublicTakedownController($servizio, $this->postino());
    }

    private function segnala(): string
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $_POST = [
            'submitter_name'  => 'Zz Segnalante',
            'submitter_email' => 'zz.segnalante@example.invalid',
            'submitter_role'  => 'private',
            'content_ref'     => '/eser/mat/scheda-12.pdf',
            'violation_type'  => 'copyright',
            'description'     => 'Una descrizione abbastanza lunga della violazione.',
        ];
        try {
            return $this->segnalazioni()->submit(new \App\Core\Request())->body;
        } finally {
            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            unset($_SERVER['REMOTE_ADDR']);
        }
    }

    #[Test]
    public function le_segnalazioni_vanno_alla_casella_dell_istanza(): void
    {
        Config::set('app.url', self::ALTRA);
        $pagina = $this->segnala();

        self::assertCount(1, $this->posta);
        self::assertSame('segnalazioni@scuola.example', $this->posta[0]['to'], 'la casella configurata, non quella di produzione');
        self::assertStringContainsString('https://scuola.example/admin/takedown/77', $this->posta[0]['body']);
        self::assertStringContainsString("Reply-To: zz.segnalante@example.invalid\r\n", $this->posta[0]['hdrs']);
        $this->nienteVersoLaProduzione();
        self::assertStringContainsString('segnalazioni@scuola.example', $pagina);
        self::assertDoesNotMatchRegularExpression(self::CASELLA_O_LINK_DI_PRODUZIONE, $pagina);
    }

    #[Test]
    public function senza_casella_la_segnalazione_non_va_a_nessun_altro(): void
    {
        Config::set('app.url', self::ALTRA);
        Config::set('mail.abuse_email', '');
        $pagina = $this->segnala();

        self::assertSame([], $this->posta, 'nessuna casella, nessun destinatario di ripiego');
        self::assertStringContainsString('Segnalazione ricevuta', $pagina, 'la segnalazione è salvata comunque');
        self::assertDoesNotMatchRegularExpression(self::CASELLA_O_LINK_DI_PRODUZIONE, $pagina);
        $codici = array_column(Anomalia::recenti(), 'codice');
        self::assertContains('segnalazione_senza_casella', $codici);
        // Nell'anomalia niente dati di chi segnala.
        self::assertStringNotContainsString('zz.segnalante', (string)file_get_contents(Anomalia::percorso()));
        self::assertStringNotContainsString('192.0.2.10', (string)file_get_contents(Anomalia::percorso()));
    }

    #[Test]
    public function senza_app_url_la_segnalazione_parte_con_il_percorso_del_pannello(): void
    {
        Config::set('app.url', '');
        $this->segnala();

        self::assertCount(1, $this->posta);
        self::assertStringContainsString('/admin/takedown/77 (nel pannello di amministrazione)', $this->posta[0]['body']);
        self::assertStringNotContainsString('://', $this->posta[0]['body']);
        $this->nienteVersoLaProduzione();
        self::assertSame(['notifica_segnalazione'], $this->flussiSenzaRadice());
    }
}
