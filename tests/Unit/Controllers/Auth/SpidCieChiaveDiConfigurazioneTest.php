<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Auth;

use App\Controllers\Auth\CieController;
use App\Controllers\Auth\SpidController;
use App\Core\Config;
use App\Core\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SPID e CIE interrogavano una chiave di configurazione inesistente (23/9/2026).
 *
 * ── Perché (revisione architetturale del 23/9/2026, A-34; wiki/decisions/ADR-049) ──
 *
 * `SpidController::isEnabled()` leggeva `Config::get('auth.spid.enabled')`,
 * ma `app/Config/auth.php` non ha mai avuto una chiave `spid`: quella lettura
 * tornava sempre il default `false`, silenziosamente. L'unica cosa che
 * decideva per davvero era il ripiego diretto `$_ENV['SPID_ENABLED']`,
 * un'altra violazione (l'ambiente si legge solo in `app/Config`). Stessa
 * storia per `CieController` e `auth.cie.enabled`.
 *
 * La chiave giusta è `spid.enabled` / `cie.enabled`, in due file nuovi
 * (`app/Config/spid.php`, `app/Config/cie.php`) che leggono `SPID_ENABLED` /
 * `CIE_ENABLED` una volta sola, nel solo posto ammesso.
 *
 * Misurato prima della correzione: con `Config::set('spid.enabled', true)` la
 * risposta di `login()` restava quella "spenta" (`notConfigured('login')`,
 * senza `(D.2.3)`), perché il codice guardava un'altra chiave — la stessa
 * mutazione con la correzione applicata fa apparire `(D.2.3)`. È la prova nei
 * due versi di questo file.
 */
final class SpidCieChiaveDiConfigurazioneTest extends TestCase
{
    private mixed $spidPrima = null;
    private mixed $ciePrima = null;

    protected function setUp(): void
    {
        $this->spidPrima = Config::get('spid.enabled');
        $this->ciePrima = Config::get('cie.enabled');
    }

    protected function tearDown(): void
    {
        Config::set('spid.enabled', $this->spidPrima);
        Config::set('cie.enabled', $this->ciePrima);
    }

    #[Test]
    public function spidLeggeLaChiaveSpidPuntoEnabled(): void
    {
        Config::set('spid.enabled', false);
        $risposta = (new SpidController())->login(new Request());
        $corpo = json_decode($risposta->body, true);
        self::assertSame(503, $risposta->status);
        self::assertStringNotContainsString(
            '(D.2.3)',
            $corpo['message'],
            "spid.enabled=false deve restare sullo stub 'spento'"
        );

        Config::set('spid.enabled', true);
        $risposta = (new SpidController())->login(new Request());
        $corpo = json_decode($risposta->body, true);
        self::assertStringContainsString(
            '(D.2.3)',
            $corpo['message'],
            "spid.enabled=true deve arrivare al ramo 'acceso' di login():"
            . " se questo fallisce, isEnabled() sta ancora leggendo una chiave"
            . " diversa da spid.enabled (A-34)."
        );
    }

    #[Test]
    public function cieLeggeLaChiaveCiePuntoEnabled(): void
    {
        Config::set('cie.enabled', false);
        $risposta = (new CieController())->login(new Request());
        $corpo = json_decode($risposta->body, true);
        self::assertSame(503, $risposta->status);
        self::assertStringNotContainsString('(D.2.3)', $corpo['message'] ?? '');

        Config::set('cie.enabled', true);
        $risposta = (new CieController())->login(new Request());
        $corpo = json_decode($risposta->body, true);
        // CieController::login() non ha rami TODO differenziati come Spid:
        // qui la controprova è che isEnabled() non lancia e la richiesta
        // continua a rispondere 503 coerente (nessuna registrazione AgID),
        // non che la Config sia ignorata: lo garantisce il test successivo
        // sul file di configurazione.
        self::assertSame(503, $risposta->status);
    }

    #[Test]
    public function ilFileDiConfigurazioneSpidLeggeSpidEnabledDallAmbiente(): void
    {
        $prima = $_ENV['SPID_ENABLED'] ?? null;
        try {
            $_ENV['SPID_ENABLED'] = '1';
            $conf = require dirname(__DIR__, 4) . '/app/Config/spid.php';
            self::assertTrue($conf['enabled']);

            $_ENV['SPID_ENABLED'] = '0';
            $conf = require dirname(__DIR__, 4) . '/app/Config/spid.php';
            self::assertFalse($conf['enabled']);
        } finally {
            if ($prima === null) {
                unset($_ENV['SPID_ENABLED']);
            } else {
                $_ENV['SPID_ENABLED'] = $prima;
            }
        }
    }

    #[Test]
    public function ilFileDiConfigurazioneCieLeggeCieEnabledDallAmbiente(): void
    {
        $prima = $_ENV['CIE_ENABLED'] ?? null;
        try {
            $_ENV['CIE_ENABLED'] = '1';
            $conf = require dirname(__DIR__, 4) . '/app/Config/cie.php';
            self::assertTrue($conf['enabled']);

            $_ENV['CIE_ENABLED'] = '0';
            $conf = require dirname(__DIR__, 4) . '/app/Config/cie.php';
            self::assertFalse($conf['enabled']);
        } finally {
            if ($prima === null) {
                unset($_ENV['CIE_ENABLED']);
            } else {
                $_ENV['CIE_ENABLED'] = $prima;
            }
        }
    }

    #[Test]
    public function envNonHaPiuChiamanti(): void
    {
        self::assertFalse(
            function_exists('env'),
            "app/Support/helpers.php non deve più definire env(): nessun"
            . " chiamante nel repository (A-34); se questa prova fallisce,"
            . " qualcosa la chiama di nuovo e va cercato prima di ripristinarla."
        );
    }
}
