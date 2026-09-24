<?php

namespace Tests\Unit;

use App\Core\Config;
use App\Services\RegistrationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * La pipeline di iscrizione, senza database.
 *
 * 2026-09-24 — il database si spegne per ogni prova: fino a quel giorno, dove
 * c'era (la suite gira anche su MariaDB), approve_moves_to_users_and_activates
 * creava l'account «maria.rossi» nel database di prova e non lo toglieva più
 * (trovato nel database di prova locale, creato il 2/9/2026). Senza database
 * approve() adesso rifiuta, e l'approvazione vera sta in
 * tests/Integration/IscrizioneControLaTabellaUtentiTest.php, dentro una
 * transazione.
 */
final class RegistrationServiceTest extends TestCase
{
    private string $sandbox;
    private string $reg;
    private string $usr;
    private RegistrationService $svc;
    private mixed $databasePrima = null;

    protected function setUp(): void
    {
        $this->databasePrima = Config::get('database.enabled');
        Config::set('database.enabled', false);

        $this->sandbox = sys_get_temp_dir() . '/pantedu_reg_' . uniqid();
        mkdir($this->sandbox, 0755, true);
        $this->reg = $this->sandbox . '/registrations.json';
        // Il percorso della vecchia copia degli account: nessuno deve più
        // crearla.
        $this->usr = $this->sandbox . '/users.json';
        file_put_contents($this->reg, json_encode(['pending' => []]));
        $this->svc = new RegistrationService($this->reg);
    }

    protected function tearDown(): void
    {
        Config::set('database.enabled', $this->databasePrima);
        foreach (glob($this->sandbox . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->sandbox);
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'role'       => 'teacher',
            // Phase 13: teacher richiede ≥1 istituto (institute_ids); senza,
            // RegistrationService lancia institutes_required.
            'institute_ids' => [1],
            // ToS obbligatorio (accept_tos) → altrimenti tos_required.
            'accept_tos'    => true,
            'first_name' => 'Maria',
            'last_name'  => 'Rossi',
            'email'      => 'maria.rossi@example.it',
            'password'   => 'supersecret8',
        ], $overrides);
    }

    #[Test]
    public function submit_creates_pending_entry(): void
    {
        $out = $this->svc->submit($this->input());
        $this->assertSame('pending', $out['status']);
        $this->assertSame('maria.rossi', $out['username']);

        $data = json_decode(file_get_contents($this->reg), true);
        $this->assertCount(1, $data['pending']);
        $this->assertSame('pending', $data['pending'][0]['status']);
        $this->assertStringStartsWith('$2y$', $data['pending'][0]['password_hash']);
    }

    #[Test]
    public function submit_rejects_invalid_role(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->submit($this->input(['role' => 'administrator']));
    }

    #[Test]
    public function submit_rejects_bad_email(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->submit($this->input(['email' => 'not-an-email']));
    }

    #[Test]
    public function submit_rejects_short_password(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->submit($this->input(['password' => 'short']));
    }

    #[Test]
    public function submit_rejects_duplicate_email(): void
    {
        $this->svc->submit($this->input());
        $this->expectException(RuntimeException::class);
        $this->svc->submit($this->input(['first_name' => 'Mario']));
    }

    #[Test]
    public function submit_disambiguates_username_on_collision(): void
    {
        $this->svc->submit($this->input());
        $out = $this->svc->submit($this->input([
            'email' => 'maria.rossi2@example.it',
        ]));
        $this->assertSame('maria.rossi2', $out['username']);
    }

    #[Test]
    public function approve_senza_database_rifiuta_e_la_domanda_resta(): void
    {
        $this->svc->submit($this->input());
        $id = $this->svc->pending()[0]['id'];

        try {
            $this->svc->approve($id, 'admin');
            $this->fail('senza database non si approva');
        } catch (RuntimeException $e) {
            $this->assertSame('database_unavailable', $e->getMessage());
        }

        // La domanda non si perde, e nessuna copia dell'account nasce fuori
        // dal database.
        $this->assertCount(1, $this->svc->pending());
        $this->assertFileDoesNotExist($this->usr);
    }

    #[Test]
    public function reject_toglie_la_domanda_senza_storico(): void
    {
        $this->svc->submit($this->input());
        $id = $this->svc->pending()[0]['id'];

        $res = $this->svc->reject($id, 'admin', 'duplicate');
        $this->assertTrue($res['ok']);
        $this->assertSame('duplicate', $res['reason']);
        $this->assertCount(0, $this->svc->pending());

        // L'esito sta nel registro delle attività: nel file non resta niente,
        // né la domanda né una riga di storico con email e motivazione.
        $this->assertSame(['pending' => []], json_decode(file_get_contents($this->reg), true));
        $this->assertStringNotContainsString('maria.rossi@example.it', (string)file_get_contents($this->reg));
        $this->assertFileDoesNotExist($this->usr);
    }

    #[Test]
    public function una_domanda_nuova_non_conserva_ip_ne_user_agent(): void
    {
        $this->svc->submit($this->input([
            'ip'         => '203.0.113.9',
            'user_agent' => 'Prova-Iscrizione/1.0',
        ]));

        $grezzo = (string)file_get_contents($this->reg);
        $this->assertStringNotContainsString('203.0.113.9', $grezzo);
        $this->assertStringNotContainsString('Prova-Iscrizione/1.0', $grezzo);
        $voce = json_decode($grezzo, true)['pending'][0];
        $this->assertArrayNotHasKey('ip', $voce);
        $this->assertArrayNotHasKey('user_agent', $voce);
        // Il resto della domanda c'è.
        $this->assertSame('maria.rossi@example.it', $voce['email']);
    }

    #[Test]
    public function la_prima_scrittura_applica_il_termine_alle_domande_di_prima(): void
    {
        $vecchia = date('Y-m-d H:i:s', strtotime('-31 days'));
        $recente = date('Y-m-d H:i:s', strtotime('-2 days'));
        file_put_contents($this->reg, json_encode([
            'pending' => [
                ['id' => 'v', 'username' => 'zz.vecchia', 'email' => 'v@example.invalid', 'created' => $vecchia, 'ip' => '198.51.100.1', 'user_agent' => 'UA-v'],
                ['id' => 'r', 'username' => 'zz.recente', 'email' => 'r@example.invalid', 'created' => $recente, 'ip' => '198.51.100.2', 'user_agent' => 'UA-r'],
            ],
            'history' => [['id' => 'h', 'email' => 'h@example.invalid', 'action' => 'rejected', 'reason' => 'motivo']],
        ]));

        $this->svc->submit($this->input());

        $dati = json_decode((string)file_get_contents($this->reg), true);
        $this->assertSame(['r', null], [$dati['pending'][0]['id'], $dati['pending'][0]['ip'] ?? null]);
        $this->assertCount(2, $dati['pending'], 'la recente e la nuova; la vecchia se n\'è andata');
        $this->assertArrayNotHasKey('history', $dati);
        $grezzo = (string)file_get_contents($this->reg);
        foreach (['198.51.100.1', '198.51.100.2', 'UA-v', 'UA-r', 'h@example.invalid'] as $sparito) {
            $this->assertStringNotContainsString($sparito, $grezzo);
        }
    }

    #[Test]
    public function approve_unknown_id_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->approve('does-not-exist', 'admin');
    }

    /**
     * Una domanda scaduta nel file, prima che il giro notturno la cancelli.
     *
     * @return array{0: string, 1: string} gli id della scaduta e della recente
     */
    private function unaScadutaEUnaRecente(): array
    {
        file_put_contents($this->reg, json_encode(['pending' => [
            ['id' => 'scaduta', 'username' => 'zz.scaduta', 'role' => 'teacher', 'email' => 'maria.rossi@example.it',
             'first_name' => 'Zz', 'last_name' => 'Scaduta', 'password_hash' => 'x',
             'created' => date('Y-m-d H:i:s', strtotime('-31 days'))],
            ['id' => 'recente', 'username' => 'zz.recente', 'role' => 'teacher', 'email' => 'recente@example.invalid',
             'first_name' => 'Zz', 'last_name' => 'Recente', 'password_hash' => 'x',
             'created' => date('Y-m-d H:i:s', strtotime('-2 days'))],
        ]]));
        return ['scaduta', 'recente'];
    }

    /**
     * Sul codice di prima pending() leggeva il file com'era: la domanda
     * scaduta compariva fra quelle da decidere fino al giro notturno.
     */
    #[Test]
    public function pending_mostra_solo_le_domande_nel_termine(): void
    {
        [, $recente] = $this->unaScadutaEUnaRecente();

        $this->assertSame([$recente], array_column($this->svc->pending(), 'id'));
    }

    /** Nei due versi: la scaduta non si decide, la recente arriva al database. */
    #[Test]
    public function una_domanda_scaduta_non_si_approva_ne_si_rifiuta(): void
    {
        [$scaduta, $recente] = $this->unaScadutaEUnaRecente();

        foreach (['approve', 'reject'] as $azione) {
            try {
                $this->svc->{$azione}($scaduta, 'admin');
                $this->fail("$azione di una domanda scaduta deve fallire");
            } catch (RuntimeException $e) {
                $this->assertSame('registration_expired', $e->getMessage(), $azione);
            }
        }

        try {
            $this->svc->approve($recente, 'admin');
            $this->fail('senza database non si approva');
        } catch (RuntimeException $e) {
            $this->assertSame('database_unavailable', $e->getMessage(), 'la recente passa il termine e arriva al database');
        }
    }

    /** L'email di una domanda scaduta è di nuovo libera; quella di una recente no. */
    #[Test]
    public function una_domanda_scaduta_non_tiene_occupata_la_sua_email(): void
    {
        $this->unaScadutaEUnaRecente();

        $out = $this->svc->submit($this->input());
        $this->assertSame('pending', $out['status'], 'maria.rossi@example.it era solo della scaduta');

        $this->expectExceptionMessage('email_pending');
        $this->svc->submit($this->input(['first_name' => 'Anna', 'email' => 'recente@example.invalid']));
    }

    #[Test]
    public function pending_hides_password_hash(): void
    {
        $this->svc->submit($this->input());
        $pending = $this->svc->pending();
        $this->assertArrayNotHasKey('password_hash', $pending[0]);
    }
}
