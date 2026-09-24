<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Waf;

use App\Services\Waf\WafCtiService;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il servizio che chiede a CrowdSec CTI chi è un indirizzo.
 *
 * Quello che conta qui non è la forma della risposta — quella la decide
 * CrowdSec — ma **quante volte usciamo davvero**: la chiave del piano gratuito
 * ha una quota giornaliera, e un difetto nella cache la brucerebbe in silenzio.
 * Perciò ogni prova conta le chiamate, non solo il risultato.
 *
 * Gira su SQLite in memoria: serve `pdo_sqlite`, altrimenti si salta dicendolo.
 */
final class WafCtiServiceTest extends TestCase
{
    private PDO $pdo;

    /** Quante volte il servizio è uscito davvero. */
    private int $chiamate = 0;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('serve pdo_sqlite');
        }

        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->pdo->exec(
            'CREATE TABLE waf_cti_cache (
                ip TEXT PRIMARY KEY,
                payload TEXT NOT NULL,
                ok INTEGER NOT NULL DEFAULT 1,
                fetched_at TEXT NOT NULL
            )'
        );
        $this->chiamate = 0;
    }

    /**
     * Un servizio con un lettore finto che conta le uscite.
     *
     * @param array{stato:int, corpo:string} $risposta
     */
    private function servizio(array $risposta): WafCtiService
    {
        return new WafCtiService(
            $this->pdo,
            function () use ($risposta): array {
                $this->chiamate++;
                return $risposta;
            },
            static fn (): string => 'chiave-di-prova',
        );
    }

    /** Una risposta come quella vera, ridotta ai campi che leggiamo. */
    private static function rispostaVera(): array
    {
        return ['stato' => 200, 'corpo' => json_encode([
            'ip'               => '1.2.3.4',
            'reputation'       => 'malicious',
            'confidence'       => 'high',
            'background_noise' => 'high',
            'as_name'          => 'Esempio Networks',
            'as_num'           => 64500,
            'reverse_dns'      => 'host.esempio.test',
            'location'         => ['country' => 'NL'],
            'classifications'  => ['classifications' => [
                ['label' => 'SSH bruteforce'],
                ['label' => 'HTTP crawler'],
            ]],
            'history'          => ['last_seen' => '2026-09-07T22:10:00Z'],
        ], JSON_THROW_ON_ERROR)];
    }

    #[Test]
    public function traduce_la_risposta_nei_campi_che_servono(): void
    {
        $esito = $this->servizio(self::rispostaVera())->guarda('1.2.3.4');

        self::assertTrue($esito['ok']);
        self::assertSame('malicious', $esito['reputazione']);
        self::assertSame('Esempio Networks', $esito['operatore']);
        self::assertSame('64500', $esito['asn']);
        self::assertSame('NL', $esito['paese']);
        self::assertSame('host.esempio.test', $esito['inverso']);
        self::assertSame(['SSH bruteforce', 'HTTP crawler'], $esito['classificazioni']);
        self::assertFalse($esito['dalla_cache']);
    }

    #[Test]
    public function la_seconda_domanda_sullo_stesso_indirizzo_non_esce(): void
    {
        $s = $this->servizio(self::rispostaVera());
        $s->guarda('1.2.3.4');
        $secondo = $s->guarda('1.2.3.4');

        self::assertSame(1, $this->chiamate, 'la quota si consuma una volta sola');
        self::assertTrue($secondo['dalla_cache']);
        self::assertSame('malicious', $secondo['reputazione'], 'e il contenuto è quello di prima');
    }

    #[Test]
    public function con_forza_esce_lo_stesso(): void
    {
        $s = $this->servizio(self::rispostaVera());
        $s->guarda('1.2.3.4');
        $s->guarda('1.2.3.4', true);

        self::assertSame(2, $this->chiamate, 'chi forza sa quello che fa');
    }

    #[Test]
    public function anche_un_fallimento_finisce_in_cache(): void
    {
        // Altrimenti un indirizzo che CrowdSec non conosce verrebbe richiesto
        // a ogni apertura della pagina: il modo più veloce di finire la quota.
        $s = $this->servizio(['stato' => 404, 'corpo' => '']);
        $primo = $s->guarda('9.9.9.9');
        $secondo = $s->guarda('9.9.9.9');

        self::assertFalse($primo['ok']);
        self::assertSame(1, $this->chiamate);
        self::assertTrue($secondo['dalla_cache']);
    }

    #[Test]
    public function la_quota_esaurita_si_dice_con_parole_sue(): void
    {
        $esito = $this->servizio(['stato' => 429, 'corpo' => ''])->guarda('1.2.3.4');

        self::assertFalse($esito['ok']);
        self::assertStringContainsString('quota', $esito['motivo']);
    }

    #[Test]
    public function il_403_dice_che_e_il_piano_non_la_chiave(): void
    {
        // Distinzione che è costata mezza giornata a capire l'8 settembre 2026:
        // la chiave può essere validissima e l'endpoint restare fuori dal piano.
        $esito = $this->servizio(['stato' => 403, 'corpo' => ''])->guarda('1.2.3.4');

        self::assertFalse($esito['ok']);
        self::assertStringContainsString('piano', $esito['motivo']);
    }

    #[Test]
    public function un_indirizzo_non_valido_non_esce_affatto(): void
    {
        $esito = $this->servizio(self::rispostaVera())->guarda('non-un-indirizzo');

        self::assertFalse($esito['ok']);
        self::assertSame(0, $this->chiamate, 'non si spende quota per una domanda malposta');
    }

    #[Test]
    public function una_risposta_illeggibile_non_diventa_un_esito_valido(): void
    {
        $esito = $this->servizio(['stato' => 200, 'corpo' => 'non JSON'])->guarda('1.2.3.4');

        self::assertFalse($esito['ok']);
        self::assertStringContainsString('illeggibile', $esito['motivo']);
    }
}
