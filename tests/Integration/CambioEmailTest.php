<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\Mailer;
use App\Services\Security\CambioEmail;
use App\Support\ImprontaIp;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il cambio dell'email dell'account, con conferma (15/9/2026).
 *
 * Fino a quel giorno POST /me/profile cambiava l'email con il solo gettone CSRF,
 * e l'email è il canale del recupero password e del secondo fattore. Qui, nei due
 * versi: senza la password giusta, con un indirizzo non valido, uguale o già di un
 * altro account, o troppo presto, non parte niente; con la password parte il link
 * al nuovo indirizzo e l'avviso al vecchio, e l'email cambia solo con la conferma,
 * una volta sola, entro la scadenza, e se l'indirizzo è ancora libero.
 * Posta finta; tutto in transazione → rollback.
 */
final class CambioEmailTest extends TestCase
{
    private PDO $pdo;
    private int $utente = 0;
    /** @var list<array{to:string,subject:string,body:string}> */
    private array $posta = [];
    private CambioEmail $cambio;

    protected function setUp(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        \App\Core\Config::load($base . '/app/Config');
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1 FROM email_change_requests LIMIT 0');
        } catch (\Throwable $e) {
            self::markTestSkipped('DB o migrazione 131 non disponibili: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();

        $this->utente = $this->utente('zz_cambio_email', 'zz.vecchia@example.test');
        $mailer = new Mailer('noreply@example.test', 'Pantedu', function (string $to, string $subject, string $body): bool {
            $this->posta[] = ['to' => $to, 'subject' => (string)mb_decode_mimeheader($subject), 'body' => $body];
            return true;
        });
        $this->cambio = new CambioEmail($this->pdo, static fn(): Mailer => $mailer);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function utente(string $username, string $email): int
    {
        $this->pdo->prepare(
            "INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active)
             VALUES (?, 'teacher', 'Zz', 'Cambio', ?, ?, 'approved', 1)"
        )->execute([$username, $email, password_hash('una-password-lunga', PASSWORD_BCRYPT)]);
        return (int)$this->pdo->lastInsertId();
    }

    private function email(): string
    {
        $st = $this->pdo->prepare('SELECT email FROM users WHERE id = ?');
        $st->execute([$this->utente]);
        return (string)$st->fetchColumn();
    }

    private function richieste(): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM email_change_requests WHERE user_id = ?');
        $st->execute([$this->utente]);
        return (int)$st->fetchColumn();
    }

    /** Il token dal link mandato al nuovo indirizzo. */
    private function tokenDelLink(): string
    {
        $link = array_values(array_filter($this->posta, static fn(array $m): bool => str_contains($m['body'], '/me/account/email/conferma?token=')));
        self::assertCount(1, $link, 'un solo link');
        preg_match('/token=([0-9a-f]{64})/', $link[0]['body'], $m);
        return $m[1] ?? '';
    }

    #[Test]
    public function senza_la_password_giusta_o_con_un_indirizzo_sbagliato_non_parte_niente(): void
    {
        $altro = $this->utente('zz_cambio_altro', 'zz.presa@example.test');
        self::assertGreaterThan(0, $altro);

        self::assertSame(CambioEmail::PASSWORD_ERRATA, $this->cambio->richiedi($this->utente, 'sbagliata', 'zz.nuova@example.test', '203.0.113.9'));
        self::assertSame(CambioEmail::EMAIL_NON_VALIDA, $this->cambio->richiedi($this->utente, 'una-password-lunga', 'non-un-indirizzo', ''));
        self::assertSame(CambioEmail::UGUALE, $this->cambio->richiedi($this->utente, 'una-password-lunga', 'ZZ.Vecchia@example.test', ''));
        self::assertSame(CambioEmail::GIA_IN_USO, $this->cambio->richiedi($this->utente, 'una-password-lunga', 'zz.presa@example.test', ''));

        self::assertSame([], $this->posta);
        self::assertSame(0, $this->richieste());
        self::assertSame('zz.vecchia@example.test', $this->email());
    }

    #[Test]
    public function con_la_password_parte_il_link_al_nuovo_e_l_avviso_al_vecchio_e_l_email_non_cambia_ancora(): void
    {
        self::assertSame(CambioEmail::RICHIESTA_INVIATA, $this->cambio->richiedi($this->utente, 'una-password-lunga', 'zz.nuova@example.test', '203.0.113.9'));

        self::assertSame(['zz.nuova@example.test', 'zz.vecchia@example.test'], array_column($this->posta, 'to'));
        self::assertStringContainsString('z***@example.test', $this->posta[1]['body'], 'al vecchio indirizzo il nuovo è mascherato');
        self::assertStringNotContainsString('zz.nuova@example.test', $this->posta[1]['body']);
        self::assertStringNotContainsString('token=', $this->posta[1]['body'], 'e senza il link');
        $token = $this->tokenDelLink();
        $st = $this->pdo->prepare('SELECT token_hash, requested_ip_hash FROM email_change_requests WHERE user_id = ?');
        $st->execute([$this->utente]);
        $riga = $st->fetch(PDO::FETCH_ASSOC);
        self::assertSame(hash('sha256', $token), $riga['token_hash'], 'nel database l\'hash, non il token');
        // L'impronta con chiave dell'indirizzo (dal 24/9/2026), non lo SHA-256 nudo.
        self::assertNotNull(ImprontaIp::esadecimale('203.0.113.9'));
        self::assertSame(ImprontaIp::esadecimale('203.0.113.9'), $riga['requested_ip_hash']);
        self::assertNotSame(hash('sha256', '203.0.113.9'), $riga['requested_ip_hash']);
        self::assertSame('zz.vecchia@example.test', $this->email(), 'finché non si conferma, l\'email resta quella');

        self::assertSame(CambioEmail::TROPPO_PRESTO, $this->cambio->richiedi($this->utente, 'una-password-lunga', 'zz.altra@example.test', ''));
    }

    #[Test]
    public function la_conferma_cambia_l_email_una_volta_sola(): void
    {
        $this->cambio->richiedi($this->utente, 'una-password-lunga', 'zz.nuova@example.test', '');
        $token = $this->tokenDelLink();

        self::assertSame('zz.nuova@example.test', $this->cambio->nuovaDelToken($token));
        self::assertSame(['utente' => $this->utente, 'vecchia' => 'zz.vecchia@example.test', 'nuova' => 'zz.nuova@example.test'], $this->cambio->conferma($token));
        self::assertSame('zz.nuova@example.test', $this->email());

        self::assertNull($this->cambio->conferma($token), 'il link vale una volta sola');
        self::assertNull($this->cambio->conferma(str_repeat('a', 64)), 'un token inventato non vale');
    }

    #[Test]
    public function un_link_scaduto_o_un_indirizzo_preso_nel_frattempo_non_cambiano_niente(): void
    {
        $this->cambio->richiedi($this->utente, 'una-password-lunga', 'zz.nuova@example.test', '');
        $token = $this->tokenDelLink();
        $this->pdo->prepare('UPDATE email_change_requests SET expires_at = ? WHERE user_id = ?')
            ->execute([date('Y-m-d H:i:s', time() - 60), $this->utente]);
        self::assertNull($this->cambio->conferma($token), 'scaduto');
        self::assertSame('zz.vecchia@example.test', $this->email());

        $this->pdo->prepare('UPDATE email_change_requests SET expires_at = ? WHERE user_id = ?')
            ->execute([date('Y-m-d H:i:s', time() + 600), $this->utente]);
        $this->utente('zz_cambio_arrivato_prima', 'zz.nuova@example.test');
        self::assertNull($this->cambio->conferma($token), 'l\'indirizzo è stato preso da un altro account');
        self::assertSame('zz.vecchia@example.test', $this->email());
    }

    #[Test]
    public function senza_posta_non_si_scrive_nessuna_richiesta(): void
    {
        $senza = new CambioEmail($this->pdo, static fn(): ?Mailer => null);
        self::assertSame(CambioEmail::SENZA_POSTA, $senza->richiedi($this->utente, 'una-password-lunga', 'zz.nuova@example.test', ''));
        self::assertSame(0, $this->richieste());
    }

    #[Test]
    public function l_indirizzo_mascherato(): void
    {
        self::assertSame('m***@esempio.it', CambioEmail::mascherata('mario.rossi@esempio.it'));
        self::assertSame('***', CambioEmail::mascherata('senza-chiocciola'));
    }
}
