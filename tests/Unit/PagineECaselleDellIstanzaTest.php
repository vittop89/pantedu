<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\DpoContactController;
use App\Controllers\ParentConsentController;
use App\Controllers\Public\PublicTakedownController;
use App\Controllers\RegistrationController;
use App\Controllers\TeacherCredentialController;
use App\Controllers\TrustPagesController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Gdpr\ParentConsentService;
use App\Services\Gdpr\TakedownRequestService;
use App\Services\Mailer;
use App\Services\RegistrationMailer;
use App\Services\Security\QrCode;
use App\Support\Anomalia;
use App\Support\IndirizzoPubblico;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * I controller mettono nelle pagine e nelle email le caselle e i collegamenti
 * dell'istanza (23/9/2026, rilievo A-13, dalla verifica avversaria del ramo).
 *
 * `CollegamentiDellIstanzaTest` prova i servizi. Qui i controller che la
 * verifica aveva trovato senza prova: con il ripiego sul dominio di
 * produzione rimesso — scritto spezzato, così che la guardia
 * `DominioNonScrittoNelCodiceTest` non lo veda — la suite restava verde.
 *
 *   - il QR delle credenziali di classe, l'unico flusso che fino a quel giorno
 *     costruiva il collegamento dall'intestazione `Host` della richiesta;
 *   - la pagina `/security` (casella delle vulnerabilità);
 *   - la pagina del consenso confermato (casella per la revoca);
 *   - la pagina del modulo di segnalazione (casella delle segnalazioni);
 *   - l'email di iscrizione come la costruisce il controller;
 *   - la ricevuta al richiedente e la notifica al DPO.
 *
 * Ognuna nei due versi: con la configurazione di un'altra istanza compare la
 * sua; senza, nessun ripiego verso la produzione. Posta finta, database
 * SQLite in memoria dove serve: niente esce, niente resta.
 */
final class PagineECaselleDellIstanzaTest extends TestCase
{
    private const ALTRA = 'https://scuola.example';

    /**
     * Nelle pagine si cerca una casella o un collegamento, non il nome: il
     * layout porta la nota di copyright con il nome dell'opera (l'eccezione
     * motivata di DominioNonScrittoNelCodiceTest).
     */
    private const CASELLA_O_LINK_DI_PRODUZIONE = '#@pantedu\.eu|//(www\.)?pantedu\.eu#i';

    private const CHIAVI = [
        'app.url', 'app.paths.logs', 'app.paths.storage', 'database.enabled',
        'mail.from', 'mail.from_name', 'mail.contact_email', 'mail.dpo_email', 'mail.abuse_email', 'mail.security_email',
    ];

    /** @var list<array{to: string, subject: string, body: string, hdrs: string}> */
    private array $posta = [];

    /** @var array<string, mixed> */
    private array $configPrima = [];
    private string $cartella;
    private string|false $errorLogPrima;
    private ?PDO $pdoPrima = null;
    private bool $pdoToccato = false;

    protected function setUp(): void
    {
        foreach (self::CHIAVI as $k) {
            $this->configPrima[$k] = Config::get($k);
        }
        // Registro delle anomalie, error_log e registro della posta in una
        // cartella della prova.
        $this->cartella = sys_get_temp_dir() . '/pantedu-pagine-istanza-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);
        Config::set('app.paths.logs', $this->cartella);
        Config::set('app.paths.storage', $this->cartella);
        $this->errorLogPrima = ini_set('error_log', $this->cartella . '/php_errors.log');
        // Le caselle di un'altra istanza: quelle del `.env` di chi fa girare
        // la prova non devono contare.
        Config::set('mail.from', 'noreply@scuola.example');
        Config::set('mail.from_name', 'Pantedu');
        Config::set('mail.contact_email', 'contatti@scuola.example');
        Config::set('mail.dpo_email', 'dpo@scuola.example');
        Config::set('mail.abuse_email', 'segnalazioni@scuola.example');
        Config::set('mail.security_email', 'sicurezza@scuola.example');
        Config::set('app.url', self::ALTRA);
    }

    protected function tearDown(): void
    {
        if ($this->pdoToccato) {
            (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $this->pdoPrima);
        }
        foreach ($this->configPrima as $k => $v) {
            Config::set($k, $v);
        }
        ini_set('error_log', $this->errorLogPrima === false ? '' : $this->errorLogPrima);
        $this->svuota($this->cartella);
    }

    private function svuota(string $dir): void
    {
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (basename($f) === '.' || basename($f) === '..') {
                continue;
            }
            is_dir($f) ? $this->svuota($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    private function trasporto(): \Closure
    {
        return function (string $to, string $s, string $body, string $hdrs): bool {
            $this->posta[] = ['to' => $to, 'subject' => (string)mb_decode_mimeheader($s), 'body' => $body, 'hdrs' => $hdrs];
            return true;
        };
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
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite non disponibile in questo runtime');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // I servizi scrivono i momenti con NOW(), che SQLite non conosce.
        $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
        $pdo->exec(
            'CREATE TABLE audit_activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, occurred_at TEXT DEFAULT CURRENT_TIMESTAMP,
                actor_user_id INTEGER, actor_name TEXT, actor_role TEXT, action TEXT, method TEXT,
                path TEXT, status INTEGER, outcome TEXT, subject_type TEXT, subject_id TEXT,
                details_json TEXT, ip_hash BLOB, ua_hash BLOB, request_id TEXT
            )'
        );
        foreach ($tabelle as $sql) {
            $pdo->exec($sql);
        }
        $this->pdoPrima = (new ReflectionProperty(Database::class, 'pdo'))->getValue();
        $this->pdoToccato = true;
        (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $pdo);
        Config::set('database.enabled', true);
        return $pdo;
    }

    // ── QR delle credenziali di classe: la radice è app.url, mai l'Host ──

    private function qr(string $percorso): Response
    {
        // Un'intestazione Host scritta da chi fa la richiesta, in tutte le
        // forme da cui un ripiego potrebbe leggerla.
        $_SERVER['HTTP_HOST'] = 'evil.example';
        $_SERVER['SERVER_NAME'] = 'evil.example';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'evil.example';
        $controller = new TeacherCredentialController();
        $metodo = new ReflectionMethod($controller, 'qrDelCollegamento');
        $risposta = $metodo->invoke($controller, $percorso);
        self::assertInstanceOf(Response::class, $risposta);
        return $risposta;
    }

    #[Test]
    public function il_qr_di_un_altra_istanza_porta_al_suo_sito_e_non_all_host_della_richiesta(): void
    {
        $risposta = $this->qr('/accesso-classe/qr/TOK');

        self::assertSame(200, $risposta->status);
        self::assertSame(QrCode::svg(self::ALTRA . '/accesso-classe/qr/TOK', 6, 4), $risposta->body, 'il QR codifica il collegamento della sua istanza');
        self::assertNotSame(QrCode::svg('http://evil.example/accesso-classe/qr/TOK', 6, 4), $risposta->body);
    }

    #[Test]
    public function senza_app_url_il_qr_non_si_fa_e_l_host_della_richiesta_non_entra(): void
    {
        Config::set('app.url', '');
        $risposta = $this->qr('/accesso-classe/qr/TOK');

        self::assertSame(500, $risposta->status, 'un QR che porta altrove è peggio di nessun QR');
        self::assertSame(['error' => 'qr_unavailable'], json_decode($risposta->body, true));
        self::assertSame(['credenziali_di_classe'], $this->flussiSenzaRadice());
    }

    // ── /security: la casella delle vulnerabilità ─────────────────────

    #[Test]
    public function la_pagina_sicurezza_di_un_altra_istanza_da_la_sua_casella(): void
    {
        $pagina = (new TrustPagesController())->security(new Request())->body;

        self::assertStringContainsString('<a href="mailto:sicurezza@scuola.example">sicurezza@scuola.example</a>', $pagina);
        self::assertDoesNotMatchRegularExpression(self::CASELLA_O_LINK_DI_PRODUZIONE, $pagina);
    }

    #[Test]
    public function senza_casella_la_pagina_sicurezza_rimanda_a_security_txt_e_non_alla_produzione(): void
    {
        Config::set('mail.security_email', '');
        $pagina = (new TrustPagesController())->security(new Request())->body;

        self::assertStringContainsString('Segnalazioni al recapito <code>Contact</code> di security.txt.', $pagina);
        self::assertStringNotContainsString('Segnalazioni a <a href="mailto:', $pagina);
        self::assertDoesNotMatchRegularExpression(self::CASELLA_O_LINK_DI_PRODUZIONE, $pagina);
    }

    // ── Consenso confermato: la casella per la revoca ─────────────────

    private function consensoConfermato(): string
    {
        $this->sqlite(
            'CREATE TABLE parent_consents (
                id INTEGER PRIMARY KEY AUTOINCREMENT, student_user_id INTEGER NOT NULL,
                parent_email TEXT NOT NULL, parent_name TEXT, confirm_token TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "pending", requested_at TEXT DEFAULT CURRENT_TIMESTAMP,
                confirmed_at TEXT, revoked_at TEXT, expires_at TEXT, confirm_ip_hash BLOB, confirm_ua_hash BLOB
            )',
            'CREATE TABLE consent_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT, consent_id INTEGER, user_id INTEGER NOT NULL,
                consent_type TEXT NOT NULL, event TEXT NOT NULL, text_version TEXT,
                accessed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, ip_hash BLOB
            )',
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT, first_name TEXT,
                last_name TEXT, password_hash TEXT DEFAULT "", active INTEGER DEFAULT 0, status TEXT,
                approved_at TEXT, deleted_at TEXT
            )',
            'INSERT INTO users (id, username, email, active, status)
             VALUES (42, "zz.minore", "zz.minore@example.invalid", 0, "pending_parent_consent")',
        );
        $gettone = (new ParentConsentService())->request(42, 'zz.genitore@example.invalid', 'Genitore');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'confirm'];
        $risposta = (new ParentConsentController())->confirm(new Request(), ['token' => $gettone]);
        self::assertSame(200, $risposta->status, 'il consenso è confermato');
        self::assertStringContainsString('Consenso confermato', $risposta->body);
        return $risposta->body;
    }

    #[Test]
    public function la_revoca_del_consenso_va_al_dpo_dell_istanza(): void
    {
        $pagina = $this->consensoConfermato();

        self::assertStringContainsString('mailto:dpo@scuola.example?subject=Revoca consenso parentale', $pagina);
        self::assertDoesNotMatchRegularExpression(self::CASELLA_O_LINK_DI_PRODUZIONE, $pagina);
    }

    #[Test]
    public function senza_dpo_la_revoca_passa_dal_modulo_e_non_dalla_casella_di_produzione(): void
    {
        Config::set('mail.dpo_email', '');
        $pagina = $this->consensoConfermato();

        self::assertStringContainsString('il modulo <a href="/dpo-contact">per le richieste privacy</a>', $pagina);
        self::assertStringNotContainsString('mailto:', $pagina);
        self::assertDoesNotMatchRegularExpression(self::CASELLA_O_LINK_DI_PRODUZIONE, $pagina);
    }

    // ── Modulo di segnalazione: la casella delle segnalazioni ─────────

    private function moduloDiSegnalazione(): string
    {
        $servizio = new class extends TakedownRequestService {
            public function submit(array $data): int
            {
                return 77;
            }
        };
        return (new PublicTakedownController($servizio, new Mailer('noreply@scuola.example', 'Pantedu', $this->trasporto())))
            ->showForm()->body;
    }

    #[Test]
    public function il_modulo_di_segnalazione_di_un_altra_istanza_da_la_sua_casella(): void
    {
        $pagina = $this->moduloDiSegnalazione();

        self::assertStringContainsString('In alternativa puoi scrivere a <strong>segnalazioni@scuola.example</strong>.', $pagina);
        self::assertDoesNotMatchRegularExpression(self::CASELLA_O_LINK_DI_PRODUZIONE, $pagina);
        self::assertSame([], $this->posta, 'mostrare il modulo non manda niente');
    }

    #[Test]
    public function senza_casella_il_modulo_di_segnalazione_non_ne_indica_un_altra(): void
    {
        Config::set('mail.abuse_email', '');
        $pagina = $this->moduloDiSegnalazione();

        self::assertStringContainsString('Segnalazione contenuti', $pagina, 'il modulo c\'è');
        self::assertStringNotContainsString('In alternativa puoi scrivere a', $pagina);
        self::assertDoesNotMatchRegularExpression(self::CASELLA_O_LINK_DI_PRODUZIONE, $pagina);
    }

    // ── Iscrizione: il mailer come lo costruisce il controller ────────

    /** L'email «approvata» con il mailer del controller e la posta finta. */
    private function iscrizioneApprovata(): bool
    {
        $controller = (new ReflectionClass(RegistrationController::class))->newInstanceWithoutConstructor();
        $mailer = (new ReflectionMethod($controller, 'defaultMailer'))->invoke($controller);
        self::assertInstanceOf(RegistrationMailer::class, $mailer);
        // Il trasporto vero (Resend o mail()) lo sostituisce quello finto.
        $postino = (new ReflectionProperty(RegistrationMailer::class, 'mailer'))->getValue($mailer);
        self::assertInstanceOf(Mailer::class, $postino);
        (new ReflectionProperty(Mailer::class, 'transport'))->setValue($postino, $this->trasporto());
        return $mailer->approved('anna@example.invalid', 'Anna', 'anna.rossi');
    }

    #[Test]
    public function l_iscrizione_costruita_dal_controller_porta_al_sito_e_alla_casella_dell_istanza(): void
    {
        self::assertTrue($this->iscrizioneApprovata());

        self::assertCount(1, $this->posta);
        self::assertStringContainsString('Accedi qui: https://scuola.example/login', $this->posta[0]['body']);
        self::assertStringContainsString("Reply-To: contatti@scuola.example\r\n", $this->posta[0]['hdrs']);
        $this->nienteVersoLaProduzione();
    }

    #[Test]
    public function senza_app_url_l_iscrizione_del_controller_parte_senza_collegamento(): void
    {
        Config::set('app.url', '');
        Config::set('mail.contact_email', '');
        self::assertTrue($this->iscrizioneApprovata());

        self::assertCount(1, $this->posta);
        self::assertStringContainsString('anna.rossi', $this->posta[0]['body']);
        self::assertStringNotContainsString('://', $this->posta[0]['body']);
        self::assertStringContainsString("Reply-To: noreply@scuola.example\r\n", $this->posta[0]['hdrs'], 'senza casella il Reply-To è il mittente');
        $this->nienteVersoLaProduzione();
        self::assertSame(['iscrizione'], $this->flussiSenzaRadice());
    }

    #[Test]
    public function senza_mittente_il_controller_non_costruisce_il_mailer_dell_iscrizione(): void
    {
        Config::set('mail.from', '');
        $controller = (new ReflectionClass(RegistrationController::class))->newInstanceWithoutConstructor();

        self::assertNull((new ReflectionMethod($controller, 'defaultMailer'))->invoke($controller));
    }

    // ── Contatto del DPO: ricevuta al richiedente e notifica al DPO ───

    private function richiestaAlDpo(): Response
    {
        $this->sqlite(
            'CREATE TABLE dpo_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL,
                subject TEXT NOT NULL, is_minor_related INTEGER NOT NULL DEFAULT 0, message TEXT NOT NULL,
                ip_hash BLOB, user_agent_hash BLOB, status TEXT NOT NULL DEFAULT "open",
                acknowledged_at TEXT
            )'
        );
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $_POST = [
            'name'    => 'Zz Richiedente',
            'email'   => 'zz.richiedente@example.invalid',
            'subject' => 'access',
            'message' => 'Vorrei una copia dei dati che mi riguardano, per favore.',
        ];
        return (new DpoContactController($this->trasporto()))->submit(new Request());
    }

    #[Test]
    public function la_richiesta_al_dpo_di_un_altra_istanza_ha_la_ricevuta_e_la_notifica_sue(): void
    {
        $risposta = $this->richiestaAlDpo();

        self::assertSame(200, $risposta->status);
        self::assertCount(2, $this->posta, 'la ricevuta al richiedente e la notifica al DPO');
        [$ricevuta, $notifica] = $this->posta;
        self::assertSame('zz.richiedente@example.invalid', $ricevuta['to']);
        self::assertStringContainsString('Reclami: https://scuola.example/privacy/informativa', $ricevuta['body']);
        self::assertStringContainsString("Reply-To: dpo@scuola.example\r\n", $ricevuta['hdrs']);
        self::assertSame('dpo@scuola.example', $notifica['to']);
        $this->nienteVersoLaProduzione();
    }

    #[Test]
    public function senza_app_url_e_senza_dpo_la_ricevuta_parte_e_non_va_a_nessun_altro(): void
    {
        Config::set('app.url', '');
        Config::set('mail.dpo_email', '');
        $risposta = $this->richiestaAlDpo();

        self::assertSame(200, $risposta->status, 'la richiesta è registrata comunque');
        self::assertCount(1, $this->posta, 'la ricevuta, dovuta (art. 12); nessuna notifica a una casella di ripiego');
        self::assertSame('zz.richiedente@example.invalid', $this->posta[0]['to']);
        self::assertStringContainsString("Reclami: vedi l'informativa privacy del sito", $this->posta[0]['body']);
        self::assertStringNotContainsString('://', $this->posta[0]['body']);
        self::assertStringContainsString("Reply-To: noreply@scuola.example\r\n", $this->posta[0]['hdrs'], 'senza DPO il Reply-To è il mittente');
        $this->nienteVersoLaProduzione();
        self::assertSame(['ricevuta_dpo'], $this->flussiSenzaRadice());
    }
}
