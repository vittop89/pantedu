<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'unità della retention GDPR accende l'anonimizzazione anche da sola
 * (23/9/2026).
 *
 * ── Il difetto (registro del debito, voce 194) ────────────────────────────
 *
 * `pantedu-gdpr-retention.service` dà `Environment=GDPR_RETENTION_ENABLED=1`,
 * ma `app/Config/retention.php` leggeva solo `$_ENV`, e la PHP da riga di
 * comando di Debian e Ubuntu (`variables_order = GPCS`) non copia l'ambiente
 * del processo in `$_ENV`: senza la chiave in un `.env`, il timer girava in
 * simulazione. In produzione la chiave sta anche in `.env.local` (misurato il
 * 23/9: la retention gira davvero), quindi il difetto era latente.
 *
 * ── Che cosa guarda ───────────────────────────────────────────────────────
 *
 * Il file di configurazione valutato con la variabile solo nell'ambiente del
 * processo (`putenv`, niente in `$_ENV`): vale vero. E senza variabile vale
 * falso, il predefinito prudente.
 */
final class RetentionDallUnitaTest extends TestCase
{
    private string|false $processoPrima = false;

    private mixed $envPrima = null;

    private bool $envCera = false;

    protected function setUp(): void
    {
        $this->processoPrima = getenv('GDPR_RETENTION_ENABLED');
        $this->envCera = array_key_exists('GDPR_RETENTION_ENABLED', $_ENV);
        $this->envPrima = $_ENV['GDPR_RETENTION_ENABLED'] ?? null;
        unset($_ENV['GDPR_RETENTION_ENABLED']);
    }

    protected function tearDown(): void
    {
        putenv($this->processoPrima === false
            ? 'GDPR_RETENTION_ENABLED'
            : 'GDPR_RETENTION_ENABLED=' . $this->processoPrima);
        if ($this->envCera) {
            $_ENV['GDPR_RETENTION_ENABLED'] = $this->envPrima;
        } else {
            unset($_ENV['GDPR_RETENTION_ENABLED']);
        }
    }

    private static function accesa(): bool
    {
        $cfg = require \dirname(__DIR__, 2) . '/app/Config/retention.php';

        return (bool)$cfg['retention_enabled'];
    }

    #[Test]
    public function la_variabile_dell_unita_accende_la_retention(): void
    {
        putenv('GDPR_RETENTION_ENABLED=1');
        self::assertTrue(self::accesa(), 'l\'Environment= dell\'unità systemd deve bastare, senza un .env');
    }

    #[Test]
    public function senza_variabile_resta_in_simulazione(): void
    {
        putenv('GDPR_RETENTION_ENABLED');
        self::assertFalse(self::accesa());
    }
}
