<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Security;

use App\Services\Security\EmailSecondFactor;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Copre il secondo fattore via email.
 *
 * I casi che contano di piu' sono due, e riguardano entrambi cosa succede
 * QUANDO QUALCOSA VA STORTO, non quando fila liscio:
 *
 *  · `wrong_code_burns_after_five_attempts` — sei cifre valide dieci minuti
 *    sono un milione di possibilita' con un bersaglio fermo. Senza un limite
 *    di tentativi, un attaccante che conosce la password prova finche' entra.
 *
 *  · `a_failed_send_does_not_block_the_retry` — se la mail non parte, la riga
 *    va bruciata subito: lasciarla in piedi consumerebbe la finestra
 *    anti-ripetizione, e chi ha appena letto "non sono riuscito a spedire"
 *    resterebbe bloccato un minuto proprio mentre riprova.
 */
final class EmailSecondFactorTest extends TestCase
{
    private const USER = 'docente_mail';

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
                username TEXT NOT NULL, email TEXT, active INTEGER NOT NULL DEFAULT 1
            )'
        );
        $pdo->exec(
            'CREATE TABLE two_factor_email_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL, code_hash TEXT NOT NULL,
                purpose TEXT NOT NULL DEFAULT "login",
                expires_at TEXT NOT NULL, used_at TEXT DEFAULT NULL,
                attempts INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL
            )'
        );
        $pdo->exec('INSERT INTO users (username, email) VALUES ("' . self::USER . '", "prova@example.invalid")');
        return $pdo;
    }

    /** Inserisce un codice noto e lo restituisce in chiaro. */
    private function seedCode(PDO $pdo, string $code, string $purpose = 'login', int $ttl = 600, int $attempts = 0): void
    {
        $pdo->prepare(
            'INSERT INTO two_factor_email_codes (user_id, code_hash, purpose, expires_at, attempts, created_at)
             VALUES (1, ?, ?, ?, ?, ?)'
        )->execute([
            hash('sha256', $code), $purpose,
            date('Y-m-d H:i:s', time() + $ttl), $attempts, date('Y-m-d H:i:s'),
        ]);
    }

    private function rows(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM two_factor_email_codes ORDER BY id')->fetchAll();
    }

    #[Test]
    public function a_correct_code_is_accepted_once(): void
    {
        $pdo = $this->pdo();
        $this->seedCode($pdo, '123456');
        $svc = new EmailSecondFactor(null, $pdo);

        self::assertTrue($svc->verify(self::USER, '123456'));
        self::assertFalse($svc->verify(self::USER, '123456'), 'un codice speso non vale piu\'');
    }

    #[Test]
    public function spaces_and_dashes_are_forgiven(): void
    {
        $pdo = $this->pdo();
        $this->seedCode($pdo, '123456');
        // Chi copia il codice dalla mail si porta dietro spazi: rifiutarlo per
        // questo sarebbe un ostacolo senza alcun guadagno di sicurezza.
        self::assertTrue((new EmailSecondFactor(null, $pdo))->verify(self::USER, ' 123 456 '));
    }

    #[Test]
    public function an_expired_code_is_refused(): void
    {
        $pdo = $this->pdo();
        $this->seedCode($pdo, '123456', 'login', -60);
        self::assertFalse((new EmailSecondFactor(null, $pdo))->verify(self::USER, '123456'));
    }

    #[Test]
    public function a_code_issued_for_enrolment_does_not_open_a_login(): void
    {
        $pdo = $this->pdo();
        $this->seedCode($pdo, '123456', 'enrol');
        $svc = new EmailSecondFactor(null, $pdo);

        // Gli scopi sono separati: un codice chiesto per attivare la verifica
        // non deve poter essere speso per entrare, e viceversa.
        self::assertFalse($svc->verify(self::USER, '123456', 'login'));
        self::assertTrue($svc->verify(self::USER, '123456', 'enrol'));
    }

    #[Test]
    public function wrong_code_burns_after_five_attempts(): void
    {
        $pdo = $this->pdo();
        $this->seedCode($pdo, '123456');
        $svc = new EmailSecondFactor(null, $pdo);

        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($svc->verify(self::USER, '000000'), "tentativo $i");
        }
        self::assertSame(5, (int)$this->rows($pdo)[0]['attempts']);

        // Sesto tentativo: anche col codice GIUSTO non si entra piu'.
        self::assertFalse(
            $svc->verify(self::USER, '123456'),
            'esauriti i tentativi il codice va bruciato, non lasciato indovinabile'
        );
        self::assertNotNull($this->rows($pdo)[0]['used_at']);
    }

    #[Test]
    public function another_user_code_is_not_accepted(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('INSERT INTO users (username, email) VALUES ("altro", "altro@example.invalid")');
        $this->seedCode($pdo, '123456'); // appartiene a user_id 1
        self::assertFalse((new EmailSecondFactor(null, $pdo))->verify('altro', '123456'));
    }

    #[Test]
    public function a_failed_send_does_not_block_the_retry(): void
    {
        // Nessun mailer: l'invio fallisce, come in un ambiente senza posta.
        $pdo = $this->pdo();
        $svc = new EmailSecondFactor(null, $pdo);

        self::assertFalse($svc->issue(self::USER, 'login'), 'senza mailer l\'invio non riesce');

        $rows = $this->rows($pdo);
        self::assertCount(1, $rows);
        self::assertNotNull(
            $rows[0]['used_at'],
            'la riga di un invio fallito va bruciata: altrimenti consuma la finestra di ritentativo'
        );
    }

    #[Test]
    public function no_address_means_no_code(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('UPDATE users SET email = NULL');
        $svc = new EmailSecondFactor(null, $pdo);

        self::assertFalse($svc->issue(self::USER));
        self::assertCount(0, $this->rows($pdo), 'nessuna riga per un utente senza indirizzo');
        self::assertNull($svc->maskedAddress(self::USER));
    }

    #[Test]
    public function the_address_is_shown_masked(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('UPDATE users SET email = "mario.rossi@example.invalid"');
        $m = (new EmailSecondFactor(null, $pdo))->maskedAddress(self::USER);

        // Riconoscibile da chi la possiede, non leggibile da chi guarda lo
        // schermo alle spalle.
        self::assertNotNull($m);
        self::assertStringStartsWith('ma', $m);
        self::assertStringEndsWith('@example.invalid', $m);
        self::assertStringNotContainsString('mario.rossi', $m);
    }
}
