<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\UserProfileController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Il cambio della password dal profilo (POST /me/change-password) legge e
 * scrive solo il database (24/9/2026).
 *
 * ── Il difetto ────────────────────────────────────────────────────────────
 *
 * Fino a quel giorno il controller ripiegava su `users.json`, la copia degli
 * account approvati che `RegistrationService` teneva accanto al database: se
 * la riga del database non aveva un hash, valeva quello della copia, e senza
 * database il cambio riusciva scrivendo solo lì. Il login non legge quella
 * copia dalla Phase 18, quindi il cambio «riuscito» non cambiava la password
 * con cui si entra, e la copia poteva far valere come password attuale quella
 * di un account a cui il database l'aveva tolta. Nessuna prova copriva questa
 * rotta.
 *
 * ── Che cosa guarda ───────────────────────────────────────────────────────
 *
 * Nei due versi: la password attuale giusta cambia l'hash nel database e
 * quella sbagliata no; una copia in `users.json` con un hash che il database
 * non ha non vale come password attuale, né col database né senza, e non
 * viene scritta; un database che non scrive dà `persist_failed`, senza il
 * testo del database nell'indirizzo, e la password resta quella di prima.
 *
 * Il database è uno SQLite in memoria messo al posto della connessione
 * condivisa, come in SecondoFattoreDalProfiloTest: la prova gira anche nel
 * cancello senza database.
 */
final class CambioPasswordDalProfiloTest extends TestCase
{
    private const UTENTE   = 'zz_cambio_password';
    private const ATTUALE  = 'la-password-attuale-1';
    private const NUOVA    = 'la-password-nuova-2';
    private const GUASTO   = 'guasto simulato sulla password';

    private ?PDO $pdo = null;
    private ?PDO $pdoPrima = null;
    /** @var array<string,mixed> */
    private array $configPrima = [];
    private string $cartella = '';
    private string $copia = '';
    private string|false $errorLogPrima = false;

    protected function setUp(): void
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite non disponibile in questo runtime');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                password_hash TEXT NOT NULL DEFAULT "",
                must_change_password INTEGER NOT NULL DEFAULT 0
            )'
        );
        $this->pdo->prepare('INSERT INTO users (username, password_hash, must_change_password) VALUES (?, ?, 1)')
            ->execute([self::UTENTE, password_hash(self::ATTUALE, PASSWORD_BCRYPT, ['cost' => 4])]);

        $conn = new ReflectionProperty(Database::class, 'pdo');
        $prima = $conn->getValue();
        $this->pdoPrima = $prima instanceof PDO ? $prima : null;
        $conn->setValue(null, $this->pdo);

        foreach (['database.enabled', 'security.hibp_enabled', 'auth.paths.registered_users'] as $chiave) {
            $this->configPrima[$chiave] = Config::get($chiave);
        }
        Config::set('database.enabled', true);
        // Have I Been Pwned è una chiamata in rete: qui non si prova quello.
        Config::set('security.hibp_enabled', false);

        $this->cartella = sys_get_temp_dir() . '/pantedu_cambio_password_' . bin2hex(random_bytes(5));
        mkdir($this->cartella, 0o700, true);
        $this->copia = $this->cartella . '/users.json';
        Config::set('auth.paths.registered_users', $this->copia);

        $this->errorLogPrima = ini_set('error_log', $this->cartella . '/errori.log');

        $_SESSION = [
            'autenticato' => true,
            'username'    => self::UTENTE,
            'user_id'     => 1,
            'user_role'   => 'teacher',
            'must_change_password' => true,
        ];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLogPrima === false ? '' : $this->errorLogPrima);
        (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $this->pdoPrima);
        foreach ($this->configPrima as $chiave => $valore) {
            Config::set($chiave, $valore);
        }
        foreach (glob($this->cartella . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cartella);
        $this->pdo = null;
        $_SESSION = [];
        $_POST = [];
    }

    private function cambia(string $attuale, string $nuova = self::NUOVA): string
    {
        $_POST = [
            'current_password' => $attuale,
            'new_password'     => $nuova,
            'confirm_password' => $nuova,
        ];
        $risposta = (new UserProfileController())->changePassword(new Request());
        self::assertSame(302, $risposta->status);
        return (string)($risposta->headers['Location'] ?? '');
    }

    private function hashNelDatabase(): string
    {
        return (string)$this->pdo?->query('SELECT password_hash FROM users')->fetchColumn();
    }

    /** La vecchia copia degli account, con l'hash di una password che il database non ha. */
    private function copiaConLaPassword(string $password): string
    {
        $contenuto = (string)json_encode(['users' => [[
            'username'      => self::UTENTE,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
        ]]]);
        file_put_contents($this->copia, $contenuto);
        return $contenuto;
    }

    #[Test]
    public function con_la_password_attuale_giusta_cambia_l_hash_nel_database(): void
    {
        self::assertSame('/me/change-password?ok=1', $this->cambia(self::ATTUALE));

        self::assertTrue(password_verify(self::NUOVA, $this->hashNelDatabase()), 'la nuova password è quella del database');
        self::assertSame(0, (int)$this->pdo?->query('SELECT must_change_password FROM users')->fetchColumn());
        self::assertArrayNotHasKey('must_change_password', $_SESSION);
        self::assertFileDoesNotExist($this->copia, 'nessuna copia fuori dal database');
    }

    #[Test]
    public function con_la_password_attuale_sbagliata_non_cambia_niente(): void
    {
        $prima = $this->hashNelDatabase();

        self::assertSame('/me/change-password?error=wrong_current', $this->cambia('non-e-questa-1'));

        self::assertSame($prima, $this->hashNelDatabase());
    }

    /**
     * Sul codice di prima: la riga senza hash ripiegava sulla copia, il cambio
     * riusciva, e la nuova password finiva solo in users.json.
     */
    #[Test]
    public function la_copia_in_users_json_non_vale_come_password_attuale(): void
    {
        $this->pdo?->exec("UPDATE users SET password_hash = ''");
        $copia = $this->copiaConLaPassword('quella-della-copia-3');

        self::assertSame('/me/change-password?error=wrong_current', $this->cambia('quella-della-copia-3'));

        self::assertSame('', $this->hashNelDatabase());
        self::assertSame($copia, (string)file_get_contents($this->copia), 'la copia non si scrive');
    }

    /**
     * Sul codice di prima: senza database valeva la copia, e il cambio
     * «riusciva» scrivendo solo lì, dove il login non guarda.
     */
    #[Test]
    public function senza_database_il_cambio_non_riesce_e_lo_dice(): void
    {
        Config::set('database.enabled', false);
        $copia = $this->copiaConLaPassword(self::ATTUALE);

        self::assertSame('/me/change-password?error=database_unavailable', $this->cambia(self::ATTUALE));

        self::assertSame($copia, (string)file_get_contents($this->copia), 'la copia non si scrive');
        Config::set('database.enabled', true);
        self::assertTrue(password_verify(self::ATTUALE, $this->hashNelDatabase()), 'e nel database la password è quella di prima');
    }

    #[Test]
    public function un_database_che_non_scrive_da_persist_failed_senza_il_suo_testo(): void
    {
        $prima = $this->hashNelDatabase();
        $this->pdo?->exec(
            "CREATE TRIGGER guasto BEFORE UPDATE OF password_hash ON users BEGIN SELECT RAISE(ABORT, '" . self::GUASTO . "'); END"
        );

        $dove = $this->cambia(self::ATTUALE);

        self::assertSame('/me/change-password?error=persist_failed', $dove);
        self::assertStringNotContainsString('guasto', $dove, 'il testo del database non va nell\'indirizzo');
        self::assertSame($prima, $this->hashNelDatabase(), 'la password resta quella di prima');
        self::assertStringContainsString(self::GUASTO, (string)@file_get_contents($this->cartella . '/errori.log'), 'il perché è nel registro');
    }
}
