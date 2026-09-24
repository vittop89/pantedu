<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\RegistrationController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Services\Mailer;
use App\Services\RateLimitStore;
use App\Services\RegistrationMailer;
use App\Services\RegistrationService;
use App\Support\DeploymentScenario;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Il modulo di iscrizione non dice a chi lo usa quali account esistono
 * (24/9/2026).
 *
 * ── Il difetto ────────────────────────────────────────────────────────────
 *
 * Due modi di saperlo, dal modulo pubblico e senza un account:
 *
 *   - il redirect dopo la domanda portava il nome utente assegnato,
 *     `/register?ok=1&u=nome.cognome2`: il 2 diceva che nome.cognome esisteva;
 *   - un'email già usata rispondeva «Email già registrata» o «Email già in
 *     attesa di approvazione», per qualunque riga di `users` (il
 *     super-amministratore compreso).
 *
 * ── Che cosa guarda ───────────────────────────────────────────────────────
 *
 * Nei due versi. Una domanda nuova, un'email di un account e un'email con una
 * domanda in attesa ricevono la stessa risposta, senza nome utente; la
 * differenza arriva solo all'indirizzo, per email (con un account: il
 * recupero della password), e al più una volta all'ora. Un errore che non
 * rivela niente — un'email scritta male — resta un errore. La domanda nuova
 * si crea solo nel primo caso.
 *
 * Il database è uno SQLite in memoria messo al posto della connessione
 * condivisa, con la sola tabella `users` che il servizio interroga: la prova
 * gira anche nel cancello senza database.
 */
final class IscrizioneSenzaRivelareGliAccountTest extends TestCase
{
    private const EMAIL_DI_UN_ACCOUNT = 'anna.bianchi@example.invalid';

    private ?PDO $pdo = null;
    private ?PDO $pdoPrima = null;
    /** @var array<string,mixed> */
    private array $configPrima = [];
    private string $cartella = '';
    private string|false $errorLogPrima = false;
    /** @var list<array{to:string, subj:string, body:string}> */
    private array $posta = [];

    protected function setUp(): void
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite non disponibile in questo runtime');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, email TEXT NOT NULL)');
        // L'account che c'è già: stesso nome e cognome di chi si iscrive.
        $this->pdo->prepare('INSERT INTO users (username, email) VALUES (?, ?)')
            ->execute(['anna.bianchi', self::EMAIL_DI_UN_ACCOUNT]);

        $conn = new ReflectionProperty(Database::class, 'pdo');
        $prima = $conn->getValue();
        $this->pdoPrima = $prima instanceof PDO ? $prima : null;
        $conn->setValue(null, $this->pdo);

        $this->cartella = sys_get_temp_dir() . '/pantedu_iscrizione_muta_' . bin2hex(random_bytes(5));
        mkdir($this->cartella, 0o700, true);

        foreach (['database.enabled', 'app.deployment_scenario', 'app.paths.storage', 'app.paths.logs', 'app.url'] as $chiave) {
            $this->configPrima[$chiave] = Config::get($chiave);
        }
        Config::set('database.enabled', true);
        // Scenario 2: si iscrivono i docenti, la scuola è facoltativa. Lo
        // storage nella cartella della prova, perché una scelta fatta dal
        // pannello (storage/config) non valga qui.
        Config::set('app.deployment_scenario', DeploymentScenario::COLLEAGUES);
        Config::set('app.paths.storage', $this->cartella);
        Config::set('app.paths.logs', $this->cartella);
        Config::set('app.url', 'https://scuola.example');
        DeploymentScenario::resetCache();

        // Il registro delle attività non ha la sua tabella, qui: lo dice in
        // error_log, che sta nella cartella della prova.
        $this->errorLogPrima = ini_set('error_log', $this->cartella . '/errori.log');

        $_SESSION = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLogPrima === false ? '' : $this->errorLogPrima);
        (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $this->pdoPrima);
        foreach ($this->configPrima as $chiave => $valore) {
            Config::set($chiave, $valore);
        }
        DeploymentScenario::resetCache();
        foreach (glob($this->cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->cartella);
        $this->pdo = null;
        $_SESSION = [];
        $_POST = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    private function controller(): RegistrationController
    {
        $postino = new Mailer('noreply@scuola.example', 'Pantedu', function (string $to, string $subj, string $body): bool {
            // L'oggetto arriva codificato per l'intestazione (RFC 2047).
            $this->posta[] = ['to' => $to, 'subj' => (string)iconv_mime_decode($subj, 0, 'UTF-8'), 'body' => $body];
            return true;
        });
        return new RegistrationController(
            new RegistrationService($this->cartella . '/registrations.json', static fn(): ?Mailer => null),
            new RegistrationMailer($postino, 'https://scuola.example', $this->cartella . '/mail.log'),
            new RateLimitStore('session'),
        );
    }

    /** La risposta del modulo a una domanda: dove porta. */
    private function iscrivi(string $email, string $nome = 'Anna', string $cognome = 'Bianchi'): string
    {
        $_POST = [
            'role'        => 'teacher',
            'first_name'  => $nome,
            'last_name'   => $cognome,
            'email'       => $email,
            'password'    => 'una-password-di-prova',
            'accept_tos'  => '1',
        ];
        $risposta = $this->controller()->submit(new Request());
        self::assertSame(302, $risposta->status);
        return (string)($risposta->headers['Location'] ?? '');
    }

    /** @return list<array<string,mixed>> le email partite finora */
    private function postaInviata(): array
    {
        return $this->posta;
    }

    /** @return list<array<string,mixed>> */
    private function domandeNelFile(): array
    {
        $file = $this->cartella . '/registrations.json';
        if (!is_file($file)) {
            return [];
        }
        return (array)(json_decode((string)file_get_contents($file), true)['pending'] ?? []);
    }

    /**
     * Sul codice di prima: `/register?ok=1&u=anna.bianchi2`, e il 2 diceva
     * che anna.bianchi c'era.
     */
    #[Test]
    public function una_domanda_nuova_risponde_senza_il_nome_utente(): void
    {
        $dove = $this->iscrivi('anna.nuova@example.invalid');

        self::assertSame('/register?ok=1', $dove);
        $domande = $this->domandeNelFile();
        self::assertCount(1, $domande, 'la domanda c\'è');
        self::assertSame('anna.bianchi2', $domande[0]['username'], 'il nome utente evita quello preso, ma non si dice');
        self::assertCount(1, $this->posta);
        self::assertStringContainsString('in attesa di approvazione', $this->posta[0]['subj']);
    }

    /**
     * Sul codice di prima: `/register?error=email_taken`, cioè «Email già
     * registrata» a chiunque provasse l'indirizzo.
     */
    #[Test]
    public function un_email_di_un_account_risponde_come_una_domanda_e_l_avviso_va_all_indirizzo(): void
    {
        $dove = $this->iscrivi(strtoupper(self::EMAIL_DI_UN_ACCOUNT));

        self::assertSame('/register?ok=1', $dove, 'la stessa risposta di una domanda nuova');
        self::assertSame([], $this->domandeNelFile(), 'ma nessuna domanda');
        self::assertCount(1, $this->posta);
        self::assertSame(self::EMAIL_DI_UN_ACCOUNT, $this->posta[0]['to']);
        self::assertStringContainsString('https://scuola.example/password/forgot', $this->posta[0]['body'], 'con il recupero della password');
        self::assertStringNotContainsString('Anna', $this->posta[0]['body'], 'senza il nome scritto da chi ha provato');
    }

    /**
     * Sul codice di prima: `/register?error=email_pending`. E l'avviso
     * all'indirizzo parte una volta all'ora, non a ogni invio del modulo.
     */
    #[Test]
    public function un_email_con_una_domanda_in_attesa_risponde_come_una_domanda_e_l_avviso_non_si_ripete(): void
    {
        self::assertSame('/register?ok=1', $this->iscrivi('carla.verdi@example.invalid', 'Carla', 'Verdi'));
        $this->posta = [];

        // Stessa email, e ogni volta un nome diverso: conta l'indirizzo.
        $risposte = [
            $this->iscrivi('carla.verdi@example.invalid', 'Carla', 'Verdi Due'),
            $this->iscrivi('carla.verdi@example.invalid', 'Carla', 'Verdi Tre'),
        ];
        self::assertSame(['/register?ok=1', '/register?ok=1'], $risposte);

        self::assertCount(1, $this->domandeNelFile(), 'resta la domanda di prima, e basta');
        $posta = $this->postaInviata();
        self::assertCount(1, $posta, 'un avviso solo, non uno per invio');
        self::assertStringContainsString('già una domanda', (string)($posta[0]['subj'] ?? ''));
    }

    /** L'altro verso: un errore che non rivela niente resta un errore. */
    #[Test]
    public function un_email_scritta_male_resta_un_errore(): void
    {
        self::assertSame('/register?error=invalid_email', $this->iscrivi('non-una-email'));
        self::assertSame([], $this->domandeNelFile());
        self::assertSame([], $this->posta);
    }

    /** La pagina di esito non legge più il nome utente dall'indirizzo. */
    #[Test]
    public function la_pagina_di_esito_non_mostra_un_nome_utente_passato_nell_indirizzo(): void
    {
        $_GET = ['ok' => '1', 'u' => 'zz.nome.dall.indirizzo'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        try {
            $html = $this->controller()->showForm(new Request())->body;
        } finally {
            $_GET = [];
        }

        self::assertStringContainsString('In attesa di approvazione', $html);
        self::assertStringNotContainsString('zz.nome.dall.indirizzo', $html);
    }
}
