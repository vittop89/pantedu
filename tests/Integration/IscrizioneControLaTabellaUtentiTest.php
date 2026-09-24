<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\RegistrationService;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Una domanda di iscrizione si confronta con gli account del database, e
 * approvarla non tocca un account che c'è già (24/9/2026).
 *
 * ── Il difetto ────────────────────────────────────────────────────────────
 *
 * Nome utente ed email si controllavano solo su `users.json`, la copia degli
 * account nati da una domanda, e sulle domande in attesa. Un account nato solo
 * nel database — il super-amministratore del seme, un amministratore di
 * istituto — non c'era. Il nome utente di una domanda è nome.cognome: chi si
 * iscriveva con il nome e il cognome di quell'account riceveva lo stesso nome
 * utente, e approvare la domanda faceva `INSERT … ON DUPLICATE KEY UPDATE
 * password_hash=…, status=…, active=…`: la password dell'account diventava
 * quella scelta nella domanda, e l'account teneva ruolo e super-amministrazione.
 *
 * ── Che cosa guarda ───────────────────────────────────────────────────────
 *
 * Nei due versi: con un account nel database il nome utente derivato cambia e
 * l'email si rifiuta, senza lo stesso nome o la stessa email la domanda passa
 * com'è; approvare una domanda col nome di un account esistente fallisce e la
 * password resta quella di prima, approvarne una libera crea l'account solo
 * nel database, senza copia in `users.json`, senza storico nel file e con il
 * verbale di accettazione senza IP né User-Agent.
 *
 * E l'approvazione è tutta o niente (revisione dello stesso giorno): se la
 * scrittura del file fallisce dopo l'INSERT, l'account non resta nel database,
 * la domanda resta in attesa, e il secondo tentativo, con il file scrivibile,
 * crea l'account, toglie la domanda e scrive l'evento `registration_approved`
 * una volta. Sul codice di prima il primo tentativo lasciava l'account attivo
 * e il secondo rispondeva `username_taken`, per sempre. Nell'altro verso, la
 * scrittura che fallisce non apre una strada a sovrascrivere un account che
 * c'è già.
 *
 * Tutto dentro una transazione annullata in tearDown: nel database non resta
 * niente (l'approvazione, dentro una transazione aperta, usa un punto di
 * salvataggio). I file stanno in una cartella temporanea.
 */
final class IscrizioneControLaTabellaUtentiTest extends TestCase
{
    private PDO $pdo;
    private string $tmp = '';

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();

        $this->tmp = sys_get_temp_dir() . '/pantedu_c2_db_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            @chmod($this->tmp, 0o700);
        }
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    private function servizio(): RegistrationService
    {
        return new RegistrationService($this->tmp . '/registrations.json');
    }

    /** Lettere a caso: il nome utente è nome.cognome, e nel nome contano solo le lettere. */
    private static function lettere(): string
    {
        return strtr(bin2hex(random_bytes(5)), '0123456789', 'ghijklmnop');
    }

    /** Un account che esiste solo nel database, come quello del seme. */
    private function accountEsistente(string $username, string $email, string $hash): int
    {
        $this->pdo->prepare(
            "INSERT INTO users (username, role, first_name, last_name, email, password_hash, status, active, is_super_admin, created_at)
             VALUES (?, 'administrator', 'Zz', 'Esistente', ?, ?, 'approved', 1, 1, NOW())"
        )->execute([$username, $email, $hash]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function iscrizione(string $nome, string $email): array
    {
        return [
            'role'          => 'teacher',
            'institute_ids' => [1],
            'accept_tos'    => true,
            'first_name'    => $nome,
            'last_name'     => 'Prova',
            'email'         => $email,
            'password'      => 'una-password-di-prova',
        ];
    }

    /** @param array<string,mixed> $voce */
    private function inAttesa(array $voce): void
    {
        file_put_contents($this->tmp . '/registrations.json', json_encode(['pending' => [$voce]]));
    }

    #[Test]
    public function il_nome_utente_derivato_evita_quelli_del_database(): void
    {
        $nome = 'Zzc' . self::lettere();
        $base = strtolower($nome) . '.prova';
        $this->accountEsistente($base, $base . '@example.invalid', password_hash('originale', PASSWORD_BCRYPT, ['cost' => 4]));

        $esito = $this->servizio()->submit($this->iscrizione($nome, 'altra.' . self::lettere() . '@example.invalid'));

        self::assertSame($base . '2', $esito['username']);

        // L'altro verso: un nome che il database non ha resta com'è.
        $libero = 'Zzc' . self::lettere();
        $esito = $this->servizio()->submit($this->iscrizione($libero, 'libera.' . self::lettere() . '@example.invalid'));
        self::assertSame(strtolower($libero) . '.prova', $esito['username']);
    }

    #[Test]
    public function un_email_di_un_account_del_database_non_apre_una_domanda(): void
    {
        $email = 'zzc.' . self::lettere() . '@example.invalid';
        $this->accountEsistente('zzc' . self::lettere() . '.esistente', $email, 'x');

        try {
            $this->servizio()->submit($this->iscrizione('Zzc' . self::lettere(), strtoupper($email)));
            self::fail('un\'email già usata da un account non apre una domanda');
        } catch (RuntimeException $e) {
            self::assertSame('email_taken', $e->getMessage());
        }

        // L'altro verso: un'email libera sì.
        $esito = $this->servizio()->submit($this->iscrizione('Zzc' . self::lettere(), 'zzc.' . self::lettere() . '@example.invalid'));
        self::assertSame('pending', $esito['status']);
    }

    #[Test]
    public function approvare_una_domanda_col_nome_di_un_account_esistente_non_ne_cambia_la_password(): void
    {
        $username = 'zzc' . self::lettere() . '.prova';
        $originale = password_hash('originale', PASSWORD_BCRYPT, ['cost' => 4]);
        $id = $this->accountEsistente($username, $username . '@example.invalid', $originale);
        $this->inAttesa([
            'id'              => 'reg_' . $username,
            'username'        => $username,
            'role'            => 'teacher',
            'first_name'      => 'Zz',
            'last_name'       => 'Intruso',
            'email'           => 'intruso.' . self::lettere() . '@example.invalid',
            'password_hash'   => password_hash('scelta-dall-intruso', PASSWORD_BCRYPT, ['cost' => 4]),
            'status'          => 'pending',
            'created'         => date('Y-m-d H:i:s'),
            'institute_ids'   => [],
            'tos_accepted_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $this->servizio()->approve('reg_' . $username, 'prova');
            self::fail('approvare una domanda col nome di un account esistente deve fallire');
        } catch (RuntimeException $e) {
            self::assertSame('username_taken', $e->getMessage());
        }

        $st = $this->pdo->prepare('SELECT password_hash, role, is_super_admin FROM users WHERE id = ?');
        $st->execute([$id]);
        $riga = $st->fetch(PDO::FETCH_ASSOC);
        self::assertSame($originale, $riga['password_hash'], 'la password dell\'account è quella di prima');
        self::assertTrue(password_verify('originale', (string)$riga['password_hash']));
        // La domanda resta: la decide l'amministratore.
        self::assertCount(1, $this->servizio()->pending());
    }

    #[Test]
    public function approvare_crea_l_account_solo_nel_database_col_verbale_senza_ip(): void
    {
        $username = 'zzc' . self::lettere() . '.prova';
        $hash = password_hash('la-sua-password', PASSWORD_BCRYPT, ['cost' => 4]);
        // Una domanda scritta prima del 24/9/2026: ha ancora IP e User-Agent.
        $this->inAttesa([
            'id'              => 'reg_' . $username,
            'username'        => $username,
            'role'            => 'teacher',
            'first_name'      => 'Zz',
            'last_name'       => 'Prova',
            'email'           => $username . '@example.invalid',
            'password_hash'   => $hash,
            'status'          => 'pending',
            'created'         => date('Y-m-d H:i:s'),
            'ip'              => '203.0.113.5',
            'user_agent'      => 'Prova-Verbale/1.0',
            'institute_ids'   => [],
            'tos_accepted_at' => date('Y-m-d H:i:s'),
        ]);

        $esito = $this->servizio()->approve('reg_' . $username, 'prova');
        self::assertTrue($esito['ok']);

        $st = $this->pdo->prepare('SELECT id, password_hash, status, active FROM users WHERE username = ?');
        $st->execute([$username]);
        $riga = $st->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($riga, 'l\'account è nel database');
        self::assertSame($hash, $riga['password_hash']);
        self::assertSame('approved', $riga['status']);

        // Niente copia dell'account fuori dal database, niente storico nel file.
        self::assertFileDoesNotExist($this->tmp . '/users.json');
        self::assertSame(['pending' => []], json_decode((string)file_get_contents($this->tmp . '/registrations.json'), true));

        $v = $this->pdo->prepare('SELECT accepted_ip, user_agent FROM user_tos_acceptance WHERE user_id = ?');
        $v->execute([(int)$riga['id']]);
        $verbale = $v->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $verbale, 'il verbale dell\'accettazione c\'è');
        self::assertSame('', (string)$verbale[0]['accepted_ip']);
        self::assertNull($verbale[0]['user_agent']);
    }

    /** @return array<string,mixed> una domanda di docente, nel termine */
    private function domandaDiDocente(string $username, string $email): array
    {
        return [
            'id'              => 'reg_' . $username,
            'username'        => $username,
            'role'            => 'teacher',
            'first_name'      => 'Zz',
            'last_name'       => 'Prova',
            'email'           => $email,
            'password_hash'   => password_hash('la-sua-password', PASSWORD_BCRYPT, ['cost' => 4]),
            'status'          => 'pending',
            'created'         => date('Y-m-d H:i:s'),
            'institute_ids'   => [],
            'tos_accepted_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function contaAccount(string $username): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $st->execute([$username]);
        return (int)$st->fetchColumn();
    }

    private function contaEventiDiApprovazione(string $id): int
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM audit_activity_log WHERE action = 'registration_approved' AND subject_id = ?"
        );
        $st->execute([$id]);
        return (int)$st->fetchColumn();
    }

    private function saltaDaRoot(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('da root ogni cartella si scrive: la scrittura non fallirebbe');
        }
    }

    #[Test]
    public function un_approvazione_che_non_scrive_il_file_non_lascia_l_account_e_si_puo_ripetere(): void
    {
        $this->saltaDaRoot();
        $username = 'zzc' . self::lettere() . '.prova';
        $id = 'reg_' . $username;
        $this->inAttesa($this->domandaDiDocente($username, $username . '@example.invalid'));

        // La cartella si legge ma non si scrive: fallisce solo la scrittura
        // del file, dopo l'INSERT.
        chmod($this->tmp, 0o500);
        try {
            $this->servizio()->approve($id, 'prova');
            self::fail('con il file che non si scrive l\'approvazione deve fallire');
        } catch (RuntimeException $e) {
            self::assertSame('write_failed', $e->getMessage());
        } finally {
            chmod($this->tmp, 0o700);
        }

        self::assertSame(0, $this->contaAccount($username), 'l\'account non resta nel database');
        self::assertSame([$id], array_column($this->servizio()->pending(), 'id'), 'la domanda resta in attesa');
        self::assertSame(0, $this->contaEventiDiApprovazione($id), 'e nessun evento dice che è approvata');

        // Il secondo tentativo, con il file scrivibile, completa tutto.
        $esito = $this->servizio()->approve($id, 'prova');

        self::assertTrue($esito['ok']);
        self::assertSame($username, $esito['username'], 'il nome utente, per l\'email di approvazione');
        self::assertSame(1, $this->contaAccount($username));
        self::assertSame([], $this->servizio()->pending(), 'la domanda non è più in attesa');
        self::assertSame(1, $this->contaEventiDiApprovazione($id), 'l\'evento c\'è, una volta');
        $v = $this->pdo->prepare('SELECT COUNT(*) FROM user_tos_acceptance t JOIN users u ON u.id = t.user_id WHERE u.username = ?');
        $v->execute([$username]);
        self::assertSame(1, (int)$v->fetchColumn(), 'e il verbale dei Termini, una volta');
    }

    /**
     * L'altro verso: la scrittura che fallisce, e il tentativo che si può
     * ripetere, non aprono una strada a un account che c'è già.
     */
    #[Test]
    public function con_la_scrittura_che_fallisce_un_account_esistente_resta_intatto(): void
    {
        $this->saltaDaRoot();
        $username = 'zzc' . self::lettere() . '.prova';
        $originale = password_hash('originale', PASSWORD_BCRYPT, ['cost' => 4]);
        $idAccount = $this->accountEsistente($username, $username . '@example.invalid', $originale);
        $this->inAttesa($this->domandaDiDocente($username, 'intruso.' . self::lettere() . '@example.invalid'));

        chmod($this->tmp, 0o500);
        try {
            foreach ([1, 2] as $tentativo) {
                try {
                    $this->servizio()->approve('reg_' . $username, 'prova');
                    self::fail("tentativo $tentativo: un account esistente non si prende");
                } catch (RuntimeException $e) {
                    self::assertSame('username_taken', $e->getMessage(), "tentativo $tentativo");
                }
            }
        } finally {
            chmod($this->tmp, 0o700);
        }

        $st = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$idAccount]);
        self::assertSame($originale, $st->fetchColumn(), 'la password dell\'account è quella di prima');
        self::assertSame(1, $this->contaAccount($username));
        self::assertSame(0, $this->contaEventiDiApprovazione('reg_' . $username));
    }
}
