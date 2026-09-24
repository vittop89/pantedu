<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Security;

use App\Services\Security\TotpService;
use App\Services\Security\TwoFactorPolicy;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Copre la politica del secondo fattore.
 *
 * Il caso che conta piu' di tutti e' `backup_code_is_consumed`: un codice di
 * riserva che resta valido dopo l'uso non e' un secondo fattore, e' una
 * seconda password statica scritta su un foglio. Se questo test diventa
 * verde per il motivo sbagliato — per esempio perche' consumeBackupCode
 * ritorna true senza scrivere — la 2FA continua ad apparire attiva mentre
 * non lo e' piu': esattamente il difetto che l'ha resa necessaria.
 */
final class TwoFactorPolicyTest extends TestCase
{
    private const USER = 'docente_prova';

    /**
     * @param bool $conContatore la colonna della migrazione 140; senza, si
     *                           prova il database che non l'ha ancora
     */
    private function pdo(bool $conContatore = true): PDO
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
                totp_enabled INTEGER NOT NULL DEFAULT 0,
                totp_secret TEXT,
                totp_backup_codes TEXT,
                two_factor_method TEXT DEFAULT NULL'
                . ($conContatore ? ', totp_last_counter INTEGER DEFAULT NULL' : '') . '
            )'
        );
        return $pdo;
    }

    /** @param list<string> $backups */
    private function seed(PDO $pdo, string $secret, array $backups, int $enabled = 1): void
    {
        $hashes = (new TotpService())->hashBackupCodes($backups);
        $st = $pdo->prepare(
            'INSERT INTO users (username, totp_enabled, totp_secret, totp_backup_codes)
             VALUES (?, ?, ?, ?)'
        );
        $st->execute([self::USER, $enabled, $secret, json_encode($hashes)]);
    }

    #[Test]
    public function enabled_reflects_the_column(): void
    {
        $pdo = $this->pdo();
        $this->seed($pdo, 'ABCDEFGHIJKLMNOP', ['aaa111'], enabled: 0);
        self::assertFalse((new TwoFactorPolicy($pdo))->enabledFor(self::USER));

        $pdo->exec('UPDATE users SET totp_enabled = 1');
        self::assertTrue((new TwoFactorPolicy($pdo))->enabledFor(self::USER));
    }

    #[Test]
    public function unknown_user_has_no_second_factor(): void
    {
        self::assertFalse((new TwoFactorPolicy($this->pdo()))->enabledFor('nessuno'));
    }

    #[Test]
    public function totp_code_verifies_against_the_stored_secret(): void
    {
        $svc    = new TotpService();
        $secret = $svc->generateSecret();
        $pdo    = $this->pdo();
        $this->seed($pdo, $secret, ['aaa111']);

        $policy = new TwoFactorPolicy($pdo);
        self::assertTrue($policy->verifyTotp(self::USER, $svc->generateCode($secret)));
        self::assertFalse($policy->verifyTotp(self::USER, '000000'));
    }

    /**
     * 23/9/2026 — un codice vale una volta (RFC 6238 §5.2, rilievo A-65).
     *
     * Prima lo stesso codice passava di nuovo per tutta la finestra di
     * tolleranza, circa novanta secondi: chi lo leggeva sopra una spalla, o
     * da una pagina di phishing che lo inoltrava, entrava con il codice che
     * l'utente aveva appena usato. Il limite dei tentativi non ferma niente,
     * perche' il codice riusato e' giusto al primo colpo.
     *
     * Sul codice di prima la seconda verifica dava true. Il codice si prende
     * dall'ora vera, come fa l'accesso: nessun orologio finto che il codice di
     * prima ignorerebbe, facendo fallire la prova per il motivo sbagliato.
     */
    #[Test]
    public function lo_stesso_codice_non_vale_due_volte(): void
    {
        $svc    = new TotpService();
        $secret = $svc->generateSecret();
        $pdo    = $this->pdo();
        $this->seed($pdo, $secret, ['aaa111']);
        $policy = new TwoFactorPolicy($pdo);

        $codice = $svc->generateCode($secret);
        self::assertTrue($policy->verifyTotp(self::USER, $codice), 'la prima volta il codice e\' buono');
        self::assertFalse($policy->verifyTotp(self::USER, $codice), 'la seconda no: e\' gia\' stato usato');
        self::assertFalse(
            $policy->verifyCode(self::USER, $codice),
            'neanche dal punto d\'ingresso del login, che passa da verifyTotp'
        );
        self::assertNotNull(
            $pdo->query('SELECT totp_last_counter FROM users')->fetchColumn(),
            'il passo usato e\' scritto nella riga'
        );
    }

    /**
     * Un codice del passo precedente, arrivato dopo quello del passo
     * successivo, non passa: la finestra lo accetterebbe (e' dentro la
     * tolleranza di un passo), il contatore no. Senza questa regola chi
     * intercetta due codici di fila ne ha uno in piu' da spendere.
     *
     * E nell'altro verso: un codice di un passo dopo l'ultimo usato passa, e
     * lo stesso codice del passo precedente, su un contatore azzerato, passa
     * anche lui. Cioe' a rifiutarlo sopra e' il contatore, non la finestra.
     */
    #[Test]
    public function il_codice_del_passo_precedente_dopo_quello_successivo_no(): void
    {
        $svc    = new TotpService();
        $secret = $svc->generateSecret();
        $pdo    = $this->pdo();
        $this->seed($pdo, $secret, ['aaa111']);
        $policy = new TwoFactorPolicy($pdo);

        // Tutti e due dentro la finestra di ±1 passo attorno all'ora vera,
        // anche se fra qui e la verifica si passa al passo successivo.
        $adesso     = time();
        $precedente = $svc->generateCode($secret, $adesso);
        $successivo = $svc->generateCode($secret, $adesso + 30);

        self::assertTrue($policy->verifyTotp(self::USER, $successivo), 'il codice del passo successivo passa');
        self::assertFalse(
            $policy->verifyTotp(self::USER, $precedente),
            'quello del passo precedente, arrivato dopo, no'
        );

        $pdo->exec('UPDATE users SET totp_last_counter = NULL');
        self::assertTrue(
            $policy->verifyTotp(self::USER, $precedente),
            'lo stesso codice su un contatore azzerato passa: sopra lo fermava il contatore'
        );
        self::assertTrue(
            $policy->verifyTotp(self::USER, $successivo),
            'e dopo di lui quello del passo seguente: un codice nuovo non si rifiuta'
        );
    }

    /**
     * Senza la colonna della migrazione 140 il codice non vale (non si puo'
     * consumare), ma la 2FA resta attiva: `enabledFor` non legge la colonna,
     * quindi il login continua a chiedere il secondo fattore invece di
     * lasciarlo cadere in silenzio.
     */
    #[Test]
    public function senza_la_colonna_il_codice_non_vale_ma_la_2fa_resta(): void
    {
        $svc    = new TotpService();
        $secret = $svc->generateSecret();
        $pdo    = $this->pdo(conContatore: false);
        $this->seed($pdo, $secret, ['aaa111']);
        $policy = new TwoFactorPolicy($pdo);

        $registro = (string)tempnam(sys_get_temp_dir(), 'pantedu_totp_log_');
        $prima = (string)ini_get('error_log');
        ini_set('error_log', $registro);
        try {
            self::assertFalse($policy->verifyTotp(self::USER, $svc->generateCode($secret)));
        } finally {
            ini_set('error_log', $prima);
        }
        $scritto = (string)file_get_contents($registro);
        @unlink($registro);

        self::assertTrue($policy->enabledFor(self::USER), 'la 2FA non sparisce');
        self::assertSame('app', $policy->methodFor(self::USER));
        self::assertStringContainsString('passo TOTP non registrato', $scritto, 'e il perche\' si legge nel registro');
    }

    #[Test]
    public function a_disabled_account_never_verifies(): void
    {
        $svc    = new TotpService();
        $secret = $svc->generateSecret();
        $pdo    = $this->pdo();
        $this->seed($pdo, $secret, ['aaa111'], enabled: 0);

        // Il codice e' giusto, ma la 2FA non e' attiva: verificarlo come valido
        // significherebbe far passare per secondo fattore un segreto che
        // l'utente ha smesso di usare.
        self::assertFalse((new TwoFactorPolicy($pdo))->verifyTotp(self::USER, $svc->generateCode($secret)));
    }

    #[Test]
    public function backup_code_is_consumed(): void
    {
        $pdo = $this->pdo();
        $this->seed($pdo, 'ABCDEFGHIJKLMNOP', ['aaa111', 'bbb222', 'ccc333']);
        $policy = new TwoFactorPolicy($pdo);

        self::assertSame(3, $policy->backupCodesLeft(self::USER));
        self::assertTrue($policy->consumeBackupCode(self::USER, 'bbb222'), 'primo uso valido');
        self::assertSame(2, $policy->backupCodesLeft(self::USER), 'il codice usato sparisce dalla lista');

        self::assertFalse(
            $policy->consumeBackupCode(self::USER, 'bbb222'),
            'lo stesso codice non deve valere una seconda volta'
        );
        self::assertTrue($policy->consumeBackupCode(self::USER, 'ccc333'), 'gli altri restano validi');
        self::assertSame(1, $policy->backupCodesLeft(self::USER));
    }

    #[Test]
    public function wrong_backup_code_consumes_nothing(): void
    {
        $pdo = $this->pdo();
        $this->seed($pdo, 'ABCDEFGHIJKLMNOP', ['aaa111', 'bbb222']);
        $policy = new TwoFactorPolicy($pdo);

        self::assertFalse($policy->consumeBackupCode(self::USER, 'zzz999'));
        self::assertSame(2, $policy->backupCodesLeft(self::USER));
    }

    #[Test]
    public function the_method_decides_which_code_is_accepted(): void
    {
        // Il caso che rende utile questo test: chi passa da un metodo all'altro.
        // Se l'iscrizione via app non riscrive `two_factor_method`, resta il
        // valore 'email' di prima e al login viene spedito un codice per posta
        // mentre l'utente digita quello dell'app. Nessun errore, nessun log:
        // semplicemente non entra piu'.
        $svc    = new TotpService();
        $secret = $svc->generateSecret();
        $pdo    = $this->pdo();
        $this->seed($pdo, $secret, ['aaa111']);

        // Metodo assente: si assume l'app, per le iscrizioni fatte prima della
        // migration 096, quando era l'unica strada.
        self::assertSame('app', (new TwoFactorPolicy($pdo))->methodFor(self::USER));
        self::assertTrue((new TwoFactorPolicy($pdo))->verifyCode(self::USER, $svc->generateCode($secret)));

        // Metodo email: il codice dell'app NON deve essere accettato, altrimenti
        // il secondo fattore varrebbe per due strade invece di una.
        $pdo->exec('UPDATE users SET two_factor_method = "email"');
        $policy = new TwoFactorPolicy($pdo);
        self::assertSame('email', $policy->methodFor(self::USER));
        self::assertFalse(
            $policy->verifyCode(self::USER, $svc->generateCode($secret)),
            'col metodo email un codice TOTP non deve aprire l accesso'
        );

        // Senza secondo fattore attivo non c'e' metodo.
        $pdo->exec('UPDATE users SET totp_enabled = 0');
        self::assertNull((new TwoFactorPolicy($pdo))->methodFor(self::USER));
    }

    #[Test]
    public function role_requirement_reads_the_configuration(): void
    {
        \App\Support\TwoFactorEnforcement::resetCache();
        $policy = new TwoFactorPolicy($this->pdo());

        // Il master switch spento senza ruoli elencati non impone nulla.
        \App\Core\Config::set('security.totp_enabled', false);
        \App\Core\Config::set('security.totp_required_roles', []);
        self::assertFalse($policy->requiredForRole('administrator'));

        // Con dei ruoli elencati l'obbligo segue i ruoli. Interruttore spento
        // e ruoli elencati si contraddicono: dal 23/9/2026 vale il lato
        // prudente, e l'anomalia lo dice (TwoFactorEnforcementTest, A-35).
        \App\Core\Config::set('security.totp_enabled', true);
        \App\Core\Config::set('security.totp_required_roles', ['administrator']);
        \App\Support\TwoFactorEnforcement::resetCache();
        self::assertTrue($policy->requiredForRole('administrator'));
        self::assertFalse($policy->requiredForRole('teacher'), 'solo i ruoli elencati');
    }

    #[Test]
    public function must_enrol_only_when_required_and_missing(): void
    {
        $pdo = $this->pdo();
        $this->seed($pdo, 'ABCDEFGHIJKLMNOP', ['aaa111'], enabled: 0);
        $policy = new TwoFactorPolicy($pdo);

        \App\Core\Config::set('security.totp_enabled', true);
        \App\Core\Config::set('security.totp_required_roles', ['teacher']);
        \App\Support\TwoFactorEnforcement::resetCache();
        self::assertTrue($policy->mustEnrol(self::USER, 'teacher'));

        $pdo->exec('UPDATE users SET totp_enabled = 1');
        self::assertFalse(
            (new TwoFactorPolicy($pdo))->mustEnrol(self::USER, 'teacher'),
            'chi l ha gia attivata non va accompagnato all iscrizione'
        );
    }

    protected function tearDown(): void
    {
        \App\Core\Config::set('security.totp_enabled', false);
        \App\Core\Config::set('security.totp_required_roles', []);
        \App\Support\TwoFactorEnforcement::resetCache();
    }
}
