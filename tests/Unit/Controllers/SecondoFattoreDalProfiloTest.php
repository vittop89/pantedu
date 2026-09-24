<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\TotpController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Services\Security\TotpService;
use App\Services\Security\TwoFactorPolicy;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * La pagina del secondo fattore dice il vero quando il database non scrive,
 * e il codice dell'iscrizione vale una volta sola (23/9/2026).
 *
 * Revisione architetturale 2026-09, rilievi A-74 e A-65:
 *   - `disable` ingoiava l'eccezione dell'UPDATE e diceva «2FA disabilitato».
 *     L'utente toglieva la voce dall'app di autenticazione, la 2FA restava
 *     attiva, e al login successivo non entrava piu';
 *   - `enable` ed `enableEmail` mettevano il messaggio dell'eccezione del
 *     database nel flash, cioe' sulla pagina;
 *   - il codice con cui ci si iscrive all'app non era consumato: valeva
 *     ancora per entrare, per circa novanta secondi.
 *
 * Il database e' uno SQLite in memoria messo al posto della connessione
 * condivisa, come in IdDellaRottaArrivaAllAzioneTest: cosi' la prova gira
 * anche nel cancello senza database. Il guasto e' un trigger che rifiuta ogni
 * UPDATE su `users`: un errore vero del motore, non un'eccezione finta.
 */
final class SecondoFattoreDalProfiloTest extends TestCase
{
    private const UTENTE   = 'zz_2fa_profilo';
    private const PASSWORD = 'una-password-di-prova';
    /** Il testo del guasto: non deve mai arrivare sulla pagina. */
    private const GUASTO   = 'guasto simulato sulla tabella users';

    private ?PDO $pdo = null;
    private ?PDO $pdoPrima = null;
    private mixed $dbPrima = null;
    private string $registro = '';
    private string $registroPrima = '';

    protected function setUp(): void
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite non disponibile in questo runtime');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Il controller scrive totp_enrolled_at con NOW(): si aggiunge la
        // funzione invece di cambiare la query (vedi ParentConsentAuditTest).
        $this->pdo->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'), 0);
        // Le colonne di `users` che il secondo fattore tocca (migrazioni 055,
        // 096 e 140), e la tabella dei codici via email (096).
        $this->pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                email TEXT NOT NULL DEFAULT "",
                password_hash TEXT NOT NULL,
                active INTEGER NOT NULL DEFAULT 1,
                totp_secret TEXT,
                totp_enabled INTEGER NOT NULL DEFAULT 0,
                two_factor_method TEXT DEFAULT NULL,
                totp_backup_codes TEXT,
                totp_enrolled_at TEXT,
                totp_last_counter INTEGER DEFAULT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE two_factor_email_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                code_hash TEXT NOT NULL,
                purpose TEXT NOT NULL DEFAULT "login",
                expires_at TEXT NOT NULL,
                used_at TEXT DEFAULT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)')
            ->execute([self::UTENTE, self::UTENTE . '@example.test', password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])]);

        $conn = new ReflectionProperty(Database::class, 'pdo');
        $prima = $conn->getValue();
        $this->pdoPrima = $prima instanceof PDO ? $prima : null;
        $conn->setValue(null, $this->pdo);
        $this->dbPrima = Config::get('database.enabled');
        Config::set('database.enabled', true);

        // Il messaggio del database deve finire qui, e non nel flash.
        $this->registro = (string)tempnam(sys_get_temp_dir(), 'pantedu_2fa_profilo_');
        $this->registroPrima = (string)ini_get('error_log');
        ini_set('error_log', $this->registro);

        $_SESSION = [
            'autenticato' => true,
            'username'    => self::UTENTE,
            'user_id'     => 1,
            'user_role'   => 'teacher',
        ];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->registroPrima);
        if ($this->registro !== '') {
            @unlink($this->registro);
        }
        (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $this->pdoPrima);
        Config::set('database.enabled', $this->dbPrima);
        $this->pdo = null;
        $_SESSION = [];
        $_POST = [];
    }

    /** Da qui in poi ogni UPDATE su users fallisce, come un database che non scrive. */
    private function guasto(): void
    {
        $this->pdo?->exec(
            "CREATE TRIGGER guasto BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, '" . self::GUASTO . "'); END"
        );
    }

    private function attiva(string $segreto): void
    {
        $this->pdo?->prepare('UPDATE users SET totp_enabled = 1, totp_secret = ?, two_factor_method = "app"')
            ->execute([$segreto]);
    }

    /** @return array{type?: string, msg?: string} */
    private function flash(): array
    {
        $flash = $_SESSION['totp_flash'] ?? [];
        return is_array($flash) ? $flash : [];
    }

    private function colonna(string $nome): mixed
    {
        return $this->pdo?->query("SELECT $nome FROM users")->fetchColumn();
    }

    #[Test]
    public function la_disattivazione_che_fallisce_non_dice_disabilitato(): void
    {
        $this->attiva((new TotpService())->generateSecret());
        $this->guasto();

        $_POST = ['current_password' => self::PASSWORD];
        $risposta = (new TotpController())->disable(new Request());

        self::assertSame(302, $risposta->status);
        self::assertSame('/me/2fa', $risposta->headers['Location'] ?? null);
        self::assertSame('error', $this->flash()['type'] ?? null, 'un errore, non un esito');
        $messaggio = (string)($this->flash()['msg'] ?? '');
        self::assertStringNotContainsString('disabilitat', $messaggio, 'la pagina non dice che e\' spenta');
        self::assertStringContainsString('ancora attiva', $messaggio, 'dice come stanno le cose');
        self::assertStringNotContainsString(self::GUASTO, $messaggio, 'il testo del database non va sulla pagina');
        self::assertSame(1, (int)$this->colonna('totp_enabled'), 'e davvero la 2FA e\' ancora attiva');
        self::assertStringContainsString(self::GUASTO, (string)file_get_contents($this->registro), 'il perche\' e\' nel registro');
    }

    /** L'altro verso: senza guasto la disattivazione riesce e lo dice. */
    #[Test]
    public function la_disattivazione_che_riesce_lo_dice(): void
    {
        $this->attiva((new TotpService())->generateSecret());
        $this->pdo?->exec('UPDATE users SET totp_last_counter = 12345');

        $_POST = ['current_password' => self::PASSWORD];
        (new TotpController())->disable(new Request());

        self::assertSame(['type' => 'ok', 'msg' => '2FA disabilitato.'], $this->flash());
        self::assertSame(0, (int)$this->colonna('totp_enabled'));
        self::assertNull($this->colonna('totp_secret'));
        self::assertNull($this->colonna('totp_last_counter'), 'il contatore del segreto tolto se ne va con lui');
        self::assertSame('', (string)file_get_contents($this->registro), 'nessun errore nel registro');
    }

    #[Test]
    public function l_attivazione_che_fallisce_non_mostra_il_messaggio_del_database(): void
    {
        $svc = new TotpService();
        $segreto = $svc->generateSecret();
        $_SESSION['totp_pending'] = ['secret' => $segreto, 'backups' => ['aaaa111111'], 'uri' => 'otpauth://totp/x'];
        $this->guasto();

        $_POST = ['code' => $svc->generateCode($segreto)];
        (new TotpController())->enable(new Request());

        self::assertSame('error', $this->flash()['type'] ?? null);
        self::assertStringNotContainsString(self::GUASTO, (string)($this->flash()['msg'] ?? ''));
        self::assertStringNotContainsString('Errore DB', (string)($this->flash()['msg'] ?? ''));
        self::assertSame(0, (int)$this->colonna('totp_enabled'));
        self::assertStringContainsString(self::GUASTO, (string)file_get_contents($this->registro));
    }

    #[Test]
    public function l_attivazione_via_email_che_fallisce_non_mostra_il_messaggio_del_database(): void
    {
        $this->pdo?->prepare(
            'INSERT INTO two_factor_email_codes (user_id, code_hash, purpose, expires_at, created_at)
             VALUES (1, ?, "enrol", "2099-01-01 00:00:00", "2026-09-23 10:00:00")'
        )->execute([hash('sha256', '482913')]);
        $_SESSION['totp_email_pending'] = true;
        $this->guasto();

        $_POST = ['code' => '482913'];
        (new TotpController())->enableEmail(new Request());

        self::assertSame('error', $this->flash()['type'] ?? null, 'il codice era giusto: a fallire e\' la scrittura');
        self::assertStringNotContainsString(self::GUASTO, (string)($this->flash()['msg'] ?? ''));
        self::assertStringNotContainsString('Errore DB', (string)($this->flash()['msg'] ?? ''));
        self::assertSame(0, (int)$this->colonna('totp_enabled'));
        self::assertStringContainsString(self::GUASTO, (string)file_get_contents($this->registro));
    }

    /**
     * Il codice con cui ci si iscrive e' consumato: non apre l'accesso
     * subito dopo. Sul codice di prima l'iscrizione lasciava il contatore
     * vuoto, e lo stesso codice passava al login.
     */
    #[Test]
    public function il_codice_dell_iscrizione_non_vale_per_entrare(): void
    {
        $svc = new TotpService();
        $segreto = $svc->generateSecret();
        $_SESSION['totp_pending'] = ['secret' => $segreto, 'backups' => ['aaaa111111'], 'uri' => 'otpauth://totp/x'];
        $adesso = time();
        $codice = $svc->generateCode($segreto, $adesso);

        $_POST = ['code' => $codice];
        (new TotpController())->enable(new Request());
        self::assertSame('ok', $this->flash()['type'] ?? null, 'iscrizione riuscita');
        self::assertSame(1, (int)$this->colonna('totp_enabled'));

        $policy = new TwoFactorPolicy($this->pdo);
        self::assertFalse($policy->verifyTotp(self::UTENTE, $codice), 'lo stesso codice non vale per entrare');
        self::assertTrue(
            $policy->verifyTotp(self::UTENTE, $svc->generateCode($segreto, $adesso + 30)),
            'il codice del passo dopo si'
        );
    }
}
