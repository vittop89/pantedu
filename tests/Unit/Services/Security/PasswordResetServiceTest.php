<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Security;

use App\Core\Config;
use App\Services\Security\PasswordPolicy;
use App\Services\Security\PasswordResetService;
use App\Support\ImprontaIp;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Copre il ciclo di vita del token di recupero password e la politica delle
 * password.
 *
 * Due proprieta' meritano attenzione piu' delle altre.
 *
 * `request_says_nothing_about_the_address`: il modulo non deve permettere di
 * scoprire chi ha un account. Il test lo verifica dal lato che conta — cosa
 * finisce in tabella — perche' un giorno qualcuno potrebbe aggiungere un
 * ramo "utente non trovato" credendo di essere gentile.
 *
 * `other_pending_tokens_die_with_the_reset`: se qualcuno ha chiesto piu'
 * link e ne usa uno, gli altri devono decadere. Altrimenti restano in giro
 * chiavi valide per una porta che l'utente crede di avere richiuso.
 */
final class PasswordResetServiceTest extends TestCase
{
    private mixed $indirizzoPrima = null;

    /**
     * Le prove sul gettone fissano un indirizzo d'istanza (23/9/2026). Senza
     * mailer passato, il servizio ne costruisce uno dalla configurazione; con
     * un mittente configurato chiede la radice pubblica prima di emettere il
     * gettone, e senza `app.url` non lo emette (A-13: un gettone senza un
     * collegamento da mandare non serve). In CI `APP_URL` è vuoto nel `.env`
     * versionato (R-4), in sviluppo no: senza questo le prove dipendevano
     * dall'ambiente.
     */
    protected function setUp(): void
    {
        $this->indirizzoPrima = Config::get('app.url');
        Config::set('app.url', 'https://istanza-di-prova.example.invalid');
    }

    private function pdo(): PDO
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite non disponibile in questo runtime');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                email TEXT,
                password_hash TEXT NOT NULL DEFAULT "",
                must_change_password INTEGER NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1
            )'
        );
        $pdo->exec(
            'CREATE TABLE password_resets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                expires_at TEXT NOT NULL,
                used_at TEXT DEFAULT NULL,
                requested_ip_hash TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $pdo->exec(
            'INSERT INTO users (username, email, password_hash, active)
             VALUES ("docente", "docente@example.invalid", "vecchio-hash", 1),
                    ("sospeso", "sospeso@example.invalid", "vecchio-hash", 0)'
        );
        return $pdo;
    }

    /** Inserisce un token noto e ne restituisce la parte in chiaro. */
    private function issue(PDO $pdo, int $uid, int $ttl = 3600): string
    {
        $token = bin2hex(random_bytes(16));
        $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        )->execute([$uid, hash('sha256', $token), date('Y-m-d H:i:s', time() + $ttl)]);
        return $token;
    }

    private function tokenCount(PDO $pdo): int
    {
        return (int)$pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn();
    }

    #[Test]
    public function request_says_nothing_about_the_address(): void
    {
        $pdo = $this->pdo();
        $svc = new PasswordResetService(null, $pdo);

        // Indirizzo sconosciuto: nessun token, nessuna eccezione, nessun
        // valore di ritorno da cui dedurre alcunche'.
        $svc->request('mai-visto@example.invalid', '127.0.0.1');
        $dopoSconosciuto = $this->tokenCount($pdo);
        self::assertSame(0, $dopoSconosciuto);

        // Account disattivato: si comporta come inesistente. Variabile
        // separata da quella sopra: due assertSame(0, ...) sulla stessa
        // espressione fanno credere a PHPStan >= 2.2.14 che la seconda sia
        // sempre vera (staticMethod.alreadyNarrowedType), perche' non vede
        // che la request() fra le due puo' cambiare il conteggio.
        $svc->request('sospeso@example.invalid', '127.0.0.1');
        $dopoSospeso = $this->tokenCount($pdo);
        self::assertSame(0, $dopoSospeso);

        // Account reale e attivo: il token c'e'.
        $svc->request('docente@example.invalid', '127.0.0.1');
        $dopoAttivo = $this->tokenCount($pdo);
        self::assertSame(1, $dopoAttivo);
    }

    #[Test]
    public function l_indirizzo_della_richiesta_si_conserva_come_impronta_con_chiave(): void
    {
        $pdo = $this->pdo();
        (new PasswordResetService(null, $pdo))->request('docente@example.invalid', '198.51.100.20');

        // Dal 24/9/2026: l'impronta con chiave di ImprontaIp, in 64 cifre come
        // la colonna CHAR(64). Fino ad allora lo SHA-256 nudo dell'indirizzo.
        $salvata = $pdo->query('SELECT requested_ip_hash FROM password_resets')->fetchColumn();
        self::assertNotNull(ImprontaIp::esadecimale('198.51.100.20'));
        self::assertSame(ImprontaIp::esadecimale('198.51.100.20'), $salvata);
        self::assertNotSame(hash('sha256', '198.51.100.20'), $salvata);
    }

    #[Test]
    public function a_second_request_right_after_is_ignored(): void
    {
        $pdo = $this->pdo();
        $svc = new PasswordResetService(null, $pdo);

        $svc->request('docente@example.invalid', '127.0.0.1');
        $svc->request('docente@example.invalid', '127.0.0.1');

        // Senza questa finestra il modulo diventerebbe un modo comodo per
        // riempire la casella di posta di qualcun altro.
        self::assertSame(1, $this->tokenCount($pdo), 'un solo token entro la finestra minima');
    }

    #[Test]
    public function only_the_plain_token_opens_the_door(): void
    {
        $pdo   = $this->pdo();
        $token = $this->issue($pdo, 1);
        $svc   = new PasswordResetService(null, $pdo);

        self::assertSame(1, $svc->verify($token));

        // In tabella c'e' l'hash: chi legge il database non ha in mano un
        // link valido.
        $stored = (string)$pdo->query('SELECT token_hash FROM password_resets')->fetchColumn();
        self::assertNotSame($token, $stored);
        self::assertNull($svc->verify($stored), 'l hash non vale come token');
    }

    #[Test]
    public function expired_token_is_refused(): void
    {
        $pdo   = $this->pdo();
        $token = $this->issue($pdo, 1, -60); // gia' scaduto
        self::assertNull((new PasswordResetService(null, $pdo))->verify($token));
    }

    #[Test]
    public function consume_sets_the_password_and_burns_the_token(): void
    {
        Config::set('security.hibp_enabled', false);
        $pdo   = $this->pdo();
        $token = $this->issue($pdo, 1);
        $svc   = new PasswordResetService(null, $pdo);

        self::assertTrue($svc->consume($token, 'una-password-lunga-abbastanza'));

        $row = $pdo->query('SELECT password_hash FROM users WHERE id = 1')->fetch();
        self::assertTrue(password_verify('una-password-lunga-abbastanza', (string)$row['password_hash']));

        self::assertNull($svc->verify($token), 'il token non vale una seconda volta');
        self::assertFalse($svc->consume($token, 'un-altra-password-lunga'), 'ne si puo riusare');
    }

    #[Test]
    public function other_pending_tokens_die_with_the_reset(): void
    {
        Config::set('security.hibp_enabled', false);
        $pdo    = $this->pdo();
        $first  = $this->issue($pdo, 1);
        $second = $this->issue($pdo, 1);
        $svc    = new PasswordResetService(null, $pdo);

        self::assertTrue($svc->consume($second, 'una-password-lunga-abbastanza'));
        self::assertNull(
            $svc->verify($first),
            'il link piu vecchio non deve restare una chiave valida'
        );
    }

    #[Test]
    public function password_policy_rejects_the_obvious_mistakes(): void
    {
        Config::set('security.hibp_enabled', false);

        $this->assertThrowsCode('new_too_short', fn () => PasswordPolicy::validate('corta', 'corta'));
        $this->assertThrowsCode('mismatch', fn () => PasswordPolicy::validate('lunga-abbastanza', 'diversa-del-tutto'));
        $this->assertThrowsCode(
            'same_as_old',
            fn () => PasswordPolicy::validate('lunga-abbastanza', 'lunga-abbastanza', 'lunga-abbastanza')
        );
        $this->assertThrowsCode(
            'new_too_long',
            fn () => PasswordPolicy::validate(str_repeat('a', 5000), str_repeat('a', 5000))
        );

        // Caso valido: non deve sollevare nulla. Se sollevasse, il test
        // fallirebbe qui; non serve un'asserzione che dice sempre di sì.
        PasswordPolicy::validate('una-password-accettabile', 'una-password-accettabile');
    }

    #[Test]
    public function policy_messages_are_readable(): void
    {
        self::assertStringContainsString('8 caratteri', PasswordPolicy::message('new_too_short'));
        self::assertStringContainsString('non coincidono', PasswordPolicy::message('mismatch'));
        self::assertStringContainsString('7 violazioni', PasswordPolicy::message('pwned_password:7'));
    }

    private function assertThrowsCode(string $code, callable $fn): void
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            self::assertSame($code, $e->getMessage());
            return;
        }
        self::fail("Nessuna eccezione: attesa '$code'");
    }

    protected function tearDown(): void
    {
        Config::set('security.hibp_enabled', true);
        Config::set('app.url', $this->indirizzoPrima);
    }
}
