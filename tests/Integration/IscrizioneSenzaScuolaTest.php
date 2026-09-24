<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Services\Mailer;
use App\Services\RegistrationService;
use App\Services\SenzaScuola;
use App\Support\Anomalia;
use App\Support\IndirizzoPubblico;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Ci si può iscrivere senza indicare una scuola — tranne dove la piattaforma
 * è adottata da un Istituto.
 *
 * ── Perché (22 settembre 2026, scelta dell'utente) ────────────────────────
 *
 * La scuola dice **dove lavori**, non che cosa insegni: è un dato di natura
 * diversa da indirizzo e classe, e chiederlo per forza a chi si iscrive a uno
 * strumento personale non è proporzionato.
 *
 * Nello scenario 3 resta obbligatoria, e non per principio: lì un docente
 * senza scuola non potrebbe avere incarichi di sezione né pubblicare, e un
 * account che non può fare niente è peggio di un campo in più.
 *
 * ── Le due direzioni, che qui sono la stessa riga di codice ───────────────
 *
 * Una sola condizione decide tutto. Provarla in un verso solo significherebbe
 * non accorgersi né di una porta chiusa a chi dovrebbe entrare, né di una
 * porta aperta dove non si deve.
 *
 * ── E il collegamento al genitore (23 settembre 2026) ─────────────────────
 *
 * In fondo, un'altra parte dell'iscrizione: l'approvazione di uno studente
 * minore manda al genitore il collegamento di conferma, e la sua radice è
 * quella dell'istanza (rilievo A-13 della revisione architetturale).
 */
final class IscrizioneSenzaScuolaTest extends TestCase
{
    private PDO $pdo;
    /** @var array<string,mixed> */
    private array $configPrima = [];
    private string $tmp = '';

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');

        try {
            $this->pdo = Database::connection();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }

        $this->pdo->beginTransaction();
        $this->configPrima = $this->leggiConfig();

        // Il servizio scrive le domande su file: si danno percorsi temporanei,
        // cosi' la prova non tocca quelle vere e non lascia niente in giro.
        $this->tmp = sys_get_temp_dir() . '/pantedu_senzascuola_' . uniqid();
        mkdir($this->tmp, 0750, true);
    }

    protected function tearDown(): void
    {
        $this->scriviConfig($this->configPrima);
        \App\Support\DeploymentScenario::resetCache();
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
        return new RegistrationService(
            $this->tmp . '/registrations.json',
        );
    }

    /** @return array<string,mixed> */
    private function leggiConfig(): array
    {
        $ref = new \ReflectionClass(Config::class);
        $p = $ref->getProperty('items');
        $p->setAccessible(true);

        return (array)$p->getValue();
    }

    /** @param array<string,mixed> $items */
    private function scriviConfig(array $items): void
    {
        $ref = new \ReflectionClass(Config::class);
        $p = $ref->getProperty('items');
        $p->setAccessible(true);
        $p->setValue(null, $items);
    }

    private function scenario(string $quale): void
    {
        $items = $this->leggiConfig();
        $items['app']['deployment_scenario'] = $quale;
        $items['app']['paths']['storage'] = sys_get_temp_dir() . '/pantedu_senzascuola_' . uniqid();
        $this->scriviConfig($items);
        \App\Support\DeploymentScenario::resetCache();
    }

    /** @return array<string,mixed> */
    private function iscrizione(string $suffisso): array
    {
        return [
            'username'   => 'zz_nosch_' . $suffisso,
            'email'      => 'zz_nosch_' . $suffisso . '@example.test',
            'password'   => 'Pantedu!2026zz',
            'first_name' => 'Zz',
            'last_name'  => 'SenzaScuola',
            'role'       => 'teacher',
            // I Termini si accettano: qui non si sta provando quello, e senza
            // la spunta il servizio si ferma prima di arrivare al punto.
            'accept_tos' => '1',
            // niente institute_ids: è il punto della prova
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    /** Il verso «deve passare»: scenario 2, il docente professionista. */
    #[Test]
    public function nello_scenario_dei_colleghi_ci_si_iscrive_senza_scuola(): void
    {
        $this->scenario(\App\Support\DeploymentScenario::COLLEAGUES);
        self::assertFalse(SenzaScuola::obbligatoria(), 'controllo positivo: qui non è obbligatoria');

        $servizio = $this->servizio();
        $esito = $servizio->submit($this->iscrizione('col' . random_int(100, 999)));

        self::assertNotSame([], $esito, 'la domanda di iscrizione deve essere accettata');
    }

    /** E anche nello scenario personale, dove comunque non si iscrive nessuno. */
    #[Test]
    public function nello_scenario_personale_non_e_obbligatoria(): void
    {
        $this->scenario(\App\Support\DeploymentScenario::PERSONAL);

        self::assertFalse(SenzaScuola::obbligatoria());
    }

    /**
     * Il verso «non deve passare»: dove la piattaforma è dell'Istituto, senza
     * scuola l'iscrizione si ferma.
     */
    #[Test]
    public function nello_scenario_istituto_senza_scuola_l_iscrizione_si_ferma(): void
    {
        $this->scenario(\App\Support\DeploymentScenario::INSTITUTE);
        self::assertTrue(SenzaScuola::obbligatoria(), 'controllo positivo: qui è obbligatoria');

        $servizio = $this->servizio();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('institutes_required');
        $servizio->submit($this->iscrizione('ist' . random_int(100, 999)));
    }

    /**
     * Il modulo d'iscrizione deve dire al docente che cosa perde, e deve
     * dirlo con lo stesso elenco che usano le API: una seconda copia
     * divergerebbe.
     */
    #[Test]
    public function il_modulo_mostra_l_elenco_vero_e_non_una_copia(): void
    {
        $vista = (string)file_get_contents(\dirname(__DIR__, 2) . '/views/auth/register.php');

        self::assertStringContainsString(
            'SenzaScuola::cosaSiPerde()',
            $vista,
            'l\'elenco deve venire dal servizio, non essere riscritto nella vista'
        );
        self::assertStringContainsString('SenzaScuola::cosaResta()', $vista);
        self::assertStringContainsString(
            'SenzaScuola::obbligatoria()',
            $vista,
            'e nello scenario 3 il riquadro non deve comparire'
        );
    }

    // ── Il consenso del genitore: il collegamento è dell'istanza ──────────

    /**
     * Approva uno studente di dodici anni con `app.url` data e la posta finta.
     * La domanda si scrive nel file come la lascerebbe submit(): qui non si
     * prova il modulo, e lo scenario 3 con la sua classe ammessa non c'entra.
     *
     * @return list<array{to: string, body: string}> la posta uscita
     */
    private function approvaUnMinore(string $appUrl): array
    {
        $posta = [];
        $postino = new Mailer('noreply@scuola.example', 'Pantedu', static function (string $to, string $s, string $body) use (&$posta): bool {
            $posta[] = ['to' => $to, 'body' => $body];
            return true;
        });
        Config::set('app.url', $appUrl);
        $nome = 'zz_nosch_minore_' . bin2hex(random_bytes(4));
        file_put_contents($this->tmp . '/registrations.json', json_encode(['pending' => [[
            'id'            => 'reg_' . $nome,
            'username'      => $nome,
            'role'          => 'student',
            'first_name'    => 'Zz',
            'last_name'     => 'Minore',
            'email'         => $nome . '@example.test',
            'password_hash' => 'x',
            'status'        => 'pending',
            'created'       => date('Y-m-d H:i:s'),
            'birth_date'    => date('Y-m-d', strtotime('-12 years')),
            'is_minor'      => true,
            'parent_email'  => $nome . '.genitore@example.test',
            'parent_name'   => 'Genitore',
        ]], 'history' => []]));
        $servizio = new RegistrationService(
            $this->tmp . '/registrations.json',
            static fn(): Mailer => $postino,
        );

        $servizio->approve('reg_' . $nome, 'prova');

        return $posta;
    }

    /**
     * Con `app.url` di un'altra istanza il collegamento è suo; senza, l'email
     * al genitore non parte (il collegamento è il messaggio) e l'anomalia
     * resta nel registro. Fino al 23/9/2026 il ripiego era il dominio di
     * produzione. Registro delle anomalie, error_log e registro della posta
     * stanno nella cartella della prova.
     */
    #[Test]
    public function il_consenso_del_genitore_porta_al_sito_dell_istanza_e_senza_app_url_non_parte(): void
    {
        $errorLogPrima = ini_set('error_log', $this->tmp . '/errori.log');
        try {
            Config::set('app.paths.logs', $this->tmp);
            Config::set('app.paths.storage', $this->tmp);

            $posta = $this->approvaUnMinore('https://scuola.example/');
            self::assertCount(1, $posta, 'la richiesta di consenso al genitore');
            self::assertStringEndsWith('.genitore@example.test', $posta[0]['to']);
            self::assertMatchesRegularExpression('#https://scuola\.example/parent-consent/[0-9a-f]{64}\n#', $posta[0]['body']);
            self::assertStringNotContainsStringIgnoringCase('pantedu.eu', $posta[0]['body']);

            self::assertSame([], $this->approvaUnMinore(''), 'senza app.url il collegamento non si manda');
            $flussi = [];
            foreach (Anomalia::recenti() as $riga) {
                if (($riga['codice'] ?? '') === IndirizzoPubblico::ANOMALIA) {
                    $flussi[] = (string)($riga['dettagli']['flusso'] ?? '');
                }
            }
            self::assertSame(['consenso_genitori'], $flussi);
        } finally {
            ini_set('error_log', $errorLogPrima === false ? '' : $errorLogPrima);
            // Il registro della posta sta in una sottocartella, e le
            // anomalie lasciano file nascosti: tearDown pulisce solo il primo
            // livello.
            foreach (glob($this->tmp . '/logs/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmp . '/logs');
            foreach (glob($this->tmp . '/.*') ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
    }
}
