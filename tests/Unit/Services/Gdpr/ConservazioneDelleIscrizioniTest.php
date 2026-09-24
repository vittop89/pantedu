<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gdpr;

use App\Services\Gdpr\ConservazioneDelleIscrizioni;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Le domande di iscrizione hanno un termine, e lo si applica al file dove
 * stanno davvero (24/9/2026).
 *
 * ── Il difetto ────────────────────────────────────────────────────────────
 *
 * Le domande stanno in `registrations.json`; il lavoro dei 30 giorni
 * cancellava da una tabella `registrations` che nessun codice scrive. Le
 * domande mai approvate restavano per sempre, con IP e User-Agent in chiaro;
 * lo storico delle decisioni e la copia degli account in `users.json` pure.
 *
 * ── Che cosa guarda ───────────────────────────────────────────────────────
 *
 * Ogni regola nei due versi, su file temporanei: la domanda scaduta se ne va
 * e quella giovane resta (col suo hash e la sua email: si tolgono solo IP e
 * User-Agent); la copia degli utenti se ne va e, quando non c'è, non succede
 * niente; in simulazione non si scrive un byte; un file di domande illeggibile
 * si segnala invece di dire «zero», e non si tocca.
 *
 * E dalla revisione dello stesso giorno: una copia degli utenti illeggibile si
 * segnala una volta e si cancella lo stesso (prima il giro falliva ogni notte
 * senza toglierla); i temporanei abbandonati se ne vanno e quelli in corso
 * restano; ogni scrittura ha un temporaneo suo e non tocca quello di un altro
 * scrittore; le domande nel termine sono quelle che si mostrano.
 */
final class ConservazioneDelleIscrizioniTest extends TestCase
{
    private string $cartella;
    private string $iscrizioni;
    private string $copia;
    private DateTimeImmutable $ora;

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu_c2_' . bin2hex(random_bytes(5));
        mkdir($this->cartella, 0o700, true);
        $this->iscrizioni = $this->cartella . '/registrations.json';
        $this->copia = $this->cartella . '/users.json';
        $this->ora = new DateTimeImmutable('2026-09-24 02:05:00');
    }

    protected function tearDown(): void
    {
        @chmod($this->cartella, 0o700);
        foreach (glob($this->cartella . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->cartella);
    }

    /** @return array<string,mixed> */
    private function domanda(string $id, string $creata, bool $conIp = true): array
    {
        $voce = [
            'id'            => $id,
            'username'      => 'zz.' . $id,
            'role'          => 'teacher',
            'first_name'    => 'Zz',
            'last_name'     => $id,
            'email'         => $id . '@example.invalid',
            'password_hash' => '$2y$12$' . str_repeat('a', 53),
            'status'        => 'pending',
            'created'       => $creata,
        ];
        if ($conIp) {
            $voce['ip'] = '203.0.113.7';
            $voce['user_agent'] = 'Prova/1.0';
        }
        return $voce;
    }

    /** @param array<string,mixed> $dati */
    private function scrivi(string $percorso, array $dati): void
    {
        file_put_contents($percorso, json_encode($dati, JSON_PRETTY_PRINT));
    }

    /** @return array<string,mixed> */
    private function leggi(string $percorso): array
    {
        return json_decode((string)file_get_contents($percorso), true);
    }

    private function servizio(): ConservazioneDelleIscrizioni
    {
        return new ConservazioneDelleIscrizioni($this->iscrizioni, $this->copia, 30);
    }

    #[Test]
    public function una_domanda_in_attesa_da_piu_di_trenta_giorni_si_cancella_e_una_piu_giovane_resta(): void
    {
        $this->scrivi($this->iscrizioni, ['pending' => [
            $this->domanda('vecchia', '2026-08-24 01:05:00'),  // 31 giorni e un'ora
            $this->domanda('giovane', '2026-08-25 03:05:00'),  // 29 giorni e 23 ore
        ]]);

        $esito = $this->servizio()->applica($this->ora, false);

        $rimaste = $this->leggi($this->iscrizioni)['pending'];
        self::assertSame(['giovane'], array_column($rimaste, 'id'));
        self::assertSame(1, $esito['scadute']);
        self::assertSame(1, $esito['in_attesa']);
        self::assertTrue($esito['scritto']);
        // Della domanda che resta non si perde niente di ciò che serve ad
        // approvarla.
        self::assertSame('giovane@example.invalid', $rimaste[0]['email']);
        self::assertStringStartsWith('$2y$12$', $rimaste[0]['password_hash']);
    }

    #[Test]
    public function da_una_domanda_che_resta_si_tolgono_ip_e_user_agent_e_basta(): void
    {
        $this->scrivi($this->iscrizioni, ['pending' => [
            $this->domanda('con', '2026-09-20 10:00:00', true),
            $this->domanda('senza', '2026-09-20 10:00:00', false),
        ]]);

        $esito = $this->servizio()->applica($this->ora, false);

        $grezzo = (string)file_get_contents($this->iscrizioni);
        self::assertStringNotContainsString('203.0.113.7', $grezzo);
        self::assertStringNotContainsString('Prova/1.0', $grezzo);
        $rimaste = $this->leggi($this->iscrizioni)['pending'];
        self::assertCount(2, $rimaste);
        foreach ($rimaste as $voce) {
            self::assertArrayNotHasKey('ip', $voce);
            self::assertArrayNotHasKey('user_agent', $voce);
        }
        // Contata solo quella che li aveva.
        self::assertSame(1, $esito['ip_tolti']);
        self::assertSame(0, $esito['scadute']);
    }

    #[Test]
    public function lo_storico_delle_decisioni_si_toglie(): void
    {
        $this->scrivi($this->iscrizioni, ['pending' => [], 'history' => [
            ['id' => 'a', 'username' => 'zz.a', 'email' => 'a@example.invalid', 'action' => 'rejected', 'reason' => 'motivo', 'timestamp' => '2026-09-23 10:00:00'],
            ['id' => 'b', 'username' => 'zz.b', 'email' => 'b@example.invalid', 'action' => 'approved', 'reason' => '', 'timestamp' => '2025-01-01 10:00:00'],
        ]]);

        $esito = $this->servizio()->applica($this->ora, false);

        self::assertSame(['pending' => []], $this->leggi($this->iscrizioni));
        self::assertSame(2, $esito['storico']);
    }

    #[Test]
    public function un_file_gia_in_regola_non_si_riscrive(): void
    {
        $this->scrivi($this->iscrizioni, ['pending' => [$this->domanda('giovane', '2026-09-20 10:00:00', false)]]);
        $prima = (string)file_get_contents($this->iscrizioni);

        $esito = $this->servizio()->applica($this->ora, false);

        self::assertFalse($esito['scritto']);
        self::assertSame($prima, (string)file_get_contents($this->iscrizioni));
        self::assertSame(['scadute' => 0, 'senza_data' => 0, 'ip_tolti' => 0, 'storico' => 0], array_intersect_key($esito, array_flip(['scadute', 'senza_data', 'ip_tolti', 'storico'])));
    }

    #[Test]
    public function una_domanda_senza_una_data_leggibile_si_cancella(): void
    {
        $senza = $this->domanda('senza', '');
        unset($senza['created']);
        $this->scrivi($this->iscrizioni, ['pending' => [
            $senza,
            $this->domanda('storta', '24/09/2026'),
            $this->domanda('buona', '2026-09-20 10:00:00'),
        ]]);

        $esito = $this->servizio()->applica($this->ora, false);

        self::assertSame(['buona'], array_column($this->leggi($this->iscrizioni)['pending'], 'id'));
        self::assertSame(2, $esito['senza_data']);
    }

    #[Test]
    public function la_copia_degli_utenti_si_cancella_e_se_manca_non_succede_niente(): void
    {
        $this->scrivi($this->copia, ['users' => [
            ['username' => 'zz.uno', 'email' => 'uno@example.invalid', 'password_hash' => '$2y$12$x'],
            ['username' => 'zz.due', 'email' => 'due@example.invalid', 'password_hash' => '$2y$12$y'],
        ]]);
        file_put_contents($this->copia . '.tmp', '{"users":[]}');

        $esito = $this->servizio()->applica($this->ora, false);

        self::assertTrue($esito['copia_presente']);
        self::assertSame(2, $esito['copia_voci']);
        self::assertSame([], $esito['problemi'], 'una copia che si legge non è un problema');
        self::assertFileDoesNotExist($this->copia);
        self::assertFileDoesNotExist($this->copia . '.tmp');

        // L'altro verso: niente copia, niente da fare, niente errori.
        $ancora = $this->servizio()->applica($this->ora, false);
        self::assertFalse($ancora['copia_presente']);
        self::assertFalse($ancora['iscrizioni_presenti']);
        self::assertFalse($ancora['scritto']);
    }

    #[Test]
    public function in_simulazione_si_conta_e_non_si_scrive_niente(): void
    {
        $this->scrivi($this->iscrizioni, ['pending' => [
            $this->domanda('vecchia', '2026-07-01 10:00:00'),
            $this->domanda('giovane', '2026-09-20 10:00:00'),
        ], 'history' => [['id' => 'x']]]);
        $this->scrivi($this->copia, ['users' => [['username' => 'zz.uno']]]);
        $iscrizioniPrima = (string)file_get_contents($this->iscrizioni);
        $copiaPrima = (string)file_get_contents($this->copia);

        $esito = $this->servizio()->applica($this->ora, true);

        self::assertSame(1, $esito['scadute']);
        self::assertSame(1, $esito['ip_tolti']);
        self::assertSame(1, $esito['storico']);
        self::assertSame(1, $esito['copia_voci']);
        self::assertFalse($esito['scritto']);
        self::assertSame($iscrizioniPrima, (string)file_get_contents($this->iscrizioni));
        self::assertSame($copiaPrima, (string)file_get_contents($this->copia));
    }

    #[Test]
    public function un_file_di_domande_che_non_e_json_si_segnala_invece_di_dire_zero_e_non_si_tocca(): void
    {
        file_put_contents($this->iscrizioni, '{"pending": [ troncato');
        // La copia degli utenti, accanto: un file rotto non ferma l'altro.
        $this->scrivi($this->copia, ['users' => [['username' => 'zz.uno']]]);

        $esito = $this->servizio()->applica($this->ora, false);

        self::assertTrue($esito['iscrizioni_presenti']);
        self::assertFalse($esito['iscrizioni_lette'], 'nessun conto da un file che non si è letto');
        self::assertCount(1, $esito['problemi']);
        self::assertStringContainsString('registrations.json', $esito['problemi'][0]);
        // E non l'ha sovrascritto con un file vuoto: dentro ci sono domande.
        self::assertSame('{"pending": [ troncato', (string)file_get_contents($this->iscrizioni));
        self::assertFileDoesNotExist($this->copia, 'la copia se n\'è andata lo stesso');
    }

    /**
     * Sul codice di prima leggiFile() lanciava prima di unlink(): la copia
     * restava, e il giro usciva con 1 ogni notte, per sempre.
     */
    #[Test]
    public function una_copia_degli_utenti_illeggibile_si_segnala_una_volta_e_si_cancella(): void
    {
        file_put_contents($this->copia, '{"users": [ {"password_hash": "$2y$12$tronc');

        $esito = $this->servizio()->applica($this->ora, false);

        self::assertTrue($esito['copia_presente']);
        self::assertCount(1, $esito['problemi'], 'si segnala');
        self::assertStringContainsString('users.json', $esito['problemi'][0]);
        self::assertStringContainsString('cancellato comunque', $esito['problemi'][0]);
        self::assertFileDoesNotExist($this->copia, 'e si cancella lo stesso');

        // La notte dopo non c'è più niente da dire.
        $dopo = $this->servizio()->applica($this->ora, false);
        self::assertSame([], $dopo['problemi']);
        self::assertFalse($dopo['copia_presente']);
    }

    /** L'altro verso: in simulazione la copia illeggibile si segnala e resta. */
    #[Test]
    public function in_simulazione_la_copia_illeggibile_si_segnala_e_resta(): void
    {
        file_put_contents($this->copia, 'non è JSON');

        $esito = $this->servizio()->applica($this->ora, true);

        self::assertCount(1, $esito['problemi']);
        self::assertStringContainsString('da cancellare comunque', $esito['problemi'][0]);
        self::assertSame('non è JSON', (string)file_get_contents($this->copia));
    }

    #[Test]
    public function i_temporanei_abbandonati_se_ne_vanno_e_quelli_in_corso_restano(): void
    {
        $vecchio = $this->ora->getTimestamp() - 2 * 3600;
        $abbandonati = [$this->iscrizioni . '.tmp', $this->iscrizioni . '.Ab12Cd'];
        foreach ($abbandonati as $f) {
            file_put_contents($f, '{"pending":[{"ip":"203.0.113.7"}]}');
            touch($f, $vecchio);
        }
        // Una scrittura in corso adesso, e un file che non è un temporaneo.
        $inCorso = $this->iscrizioni . '.Xy34Zw';
        file_put_contents($inCorso, '{"pending":[]}');
        touch($inCorso, $this->ora->getTimestamp() - 60);
        $altro = $this->iscrizioni . '.bak';
        file_put_contents($altro, '{}');
        touch($altro, $vecchio);

        $simulato = $this->servizio()->applica($this->ora, true);
        self::assertSame(2, $simulato['temporanei']);
        foreach ($abbandonati as $f) {
            self::assertFileExists($f, 'in simulazione non si cancella');
        }

        $esito = $this->servizio()->applica($this->ora, false);

        self::assertSame(2, $esito['temporanei']);
        foreach ($abbandonati as $f) {
            self::assertFileDoesNotExist($f);
        }
        self::assertFileExists($inCorso, 'la scrittura di un altro, in corso, non si tocca');
        self::assertFileExists($altro, 'né un file che non è un temporaneo');
    }

    /**
     * Sul codice di prima il temporaneo era `<file>.tmp` per tutti: la
     * scrittura lo sovrascriveva e lo rinominava, e il temporaneo di un altro
     * scrittore, a metà del suo lavoro, spariva o diventava il file.
     */
    #[Test]
    public function ogni_scrittura_ha_un_temporaneo_suo_e_non_tocca_quello_di_un_altro(): void
    {
        $dellAltro = $this->iscrizioni . '.tmp';
        file_put_contents($dellAltro, '{"pending":["dell\'altro scrittore"]}');

        ConservazioneDelleIscrizioni::scriviFile($this->iscrizioni, ['pending' => [$this->domanda('mia', '2026-09-20 10:00:00', false)]]);

        self::assertSame(['mia'], array_column($this->leggi($this->iscrizioni)['pending'], 'id'), 'la scrittura è arrivata');
        self::assertSame('{"pending":["dell\'altro scrittore"]}', (string)file_get_contents($dellAltro), 'e il temporaneo dell\'altro è intatto');
        $restanti = array_map('basename', glob($this->iscrizioni . '.*') ?: []);
        self::assertSame(['registrations.json.tmp'], $restanti, 'del proprio temporaneo non resta niente');
    }

    /**
     * tempnam(), se la cartella non si scrive, ripiega sulla cartella
     * temporanea di sistema: lì la rinomina non sarebbe atomica. La scrittura
     * deve fallire, senza lasciare le domande in /tmp.
     */
    #[Test]
    public function in_una_cartella_che_non_si_scrive_la_scrittura_fallisce_senza_temporanei_altrove(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('da root ogni cartella si scrive: la prova non direbbe niente');
        }
        $this->scrivi($this->iscrizioni, ['pending' => []]);
        $prima = (string)file_get_contents($this->iscrizioni);
        $inTmpPrima = glob(sys_get_temp_dir() . '/registrations.json.*') ?: [];
        chmod($this->cartella, 0o500);

        try {
            ConservazioneDelleIscrizioni::scriviFile($this->iscrizioni, ['pending' => [$this->domanda('mia', '2026-09-20 10:00:00', false)]]);
            self::fail('una cartella che non si scrive deve far fallire la scrittura');
        } catch (RuntimeException $e) {
            self::assertSame('write_failed', $e->getMessage());
        } finally {
            chmod($this->cartella, 0o700);
        }

        self::assertSame($prima, (string)file_get_contents($this->iscrizioni));
        self::assertSame($inTmpPrima, glob(sys_get_temp_dir() . '/registrations.json.*') ?: [], 'niente domande nella cartella temporanea di sistema');
    }

    #[Test]
    public function il_file_riscritto_e_del_gruppo_della_cartella_e_non_e_leggibile_da_altri(): void
    {
        $this->scrivi($this->iscrizioni, ['pending' => [$this->domanda('vecchia', '2026-07-01 10:00:00')]]);
        chmod($this->iscrizioni, 0o644);

        $this->servizio()->applica($this->ora, false);

        clearstatcache();
        self::assertSame(0o660, fileperms($this->iscrizioni) & 0o777);
        self::assertSame(filegroup($this->cartella), filegroup($this->iscrizioni));
    }

    #[Test]
    public function la_regola_pura_usa_il_termine_che_le_si_da(): void
    {
        $dati = ['pending' => [$this->domanda('dieci', '2026-09-14 01:00:00', false)]];

        [$con30] = ConservazioneDelleIscrizioni::ripulisci($dati, $this->ora, 30);
        [$con7, $conti7] = ConservazioneDelleIscrizioni::ripulisci($dati, $this->ora, 7);

        self::assertCount(1, $con30['pending']);
        self::assertSame([], $con7['pending']);
        self::assertSame(1, $conti7['scadute']);

        // Le domande da mostrare e contare sono le stesse della regola.
        self::assertSame($con30['pending'], ConservazioneDelleIscrizioni::domandeInTermine($dati, $this->ora, 30));
        self::assertSame([], ConservazioneDelleIscrizioni::domandeInTermine($dati, $this->ora, 7));
    }
}
