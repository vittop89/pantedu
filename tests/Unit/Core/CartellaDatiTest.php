<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La cartella dei dati d'istanza, anche con PANTEDU_DATA_PATH vuota (14/9/2026).
 *
 * `.env.example` ha `PANTEDU_DATA_PATH=`, e il job di accessibilità della CI
 * lo copia così com'è. I file di configurazione promettevano la base del
 * repository «se vuoto», ma con `??` una stringa vuota restava vuota: `storage`
 * diventava `/storage`, e con le sessioni a file ogni pagina rispondeva 500.
 */
final class CartellaDatiTest extends TestCase
{
    private const BASE = __DIR__ . '/../../..';

    /** @var array<string, mixed> */
    private array $envPrima = [];
    /** @var array{items: mixed, loaded: mixed} */
    private array $configPrima = ['items' => [], 'loaded' => false];

    protected function setUp(): void
    {
        $this->envPrima = $_ENV;
        // La configurazione è statica: si rimette com'era, non si ricarica,
        // così le prove che vengono dopo non ne ereditano una diversa.
        $classe = new \ReflectionClass(Config::class);
        $this->configPrima = [
            'items'  => $classe->getStaticPropertyValue('items'),
            'loaded' => $classe->getStaticPropertyValue('loaded'),
        ];
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envPrima;
        $classe = new \ReflectionClass(Config::class);
        $classe->setStaticPropertyValue('items', $this->configPrima['items']);
        $classe->setStaticPropertyValue('loaded', $this->configPrima['loaded']);
    }

    private static function repository(): string
    {
        return (string)realpath(self::BASE);
    }

    #[Test]
    public function con_la_variabile_valorizzata_i_dati_stanno_li(): void
    {
        $_ENV['PANTEDU_DATA_PATH'] = '/var/lib/dati-di-prova';

        $this->assertSame('/var/lib/dati-di-prova', Config::cartellaDati());
        Config::load(self::repository() . '/app/Config');
        $this->assertSame('/var/lib/dati-di-prova/storage', Config::get('app.paths.storage'));
        $this->assertSame('/var/lib/dati-di-prova/storage/objects', Config::get('storage.local.root'));
    }

    #[Test]
    public function vuota_o_assente_vale_la_base_del_repository(): void
    {
        foreach (['', '   '] as $vuota) {
            $_ENV['PANTEDU_DATA_PATH'] = $vuota;
            $this->assertSame(self::repository(), realpath(Config::cartellaDati()), var_export($vuota, true));
        }
        unset($_ENV['PANTEDU_DATA_PATH']);
        $this->assertSame(self::repository(), realpath(Config::cartellaDati()), 'assente');

        $_ENV['PANTEDU_DATA_PATH'] = '';
        Config::load(self::repository() . '/app/Config');
        $this->assertNotSame('/storage', Config::get('app.paths.storage'), 'il difetto: una cartella alla radice del disco');
        $this->assertSame(self::repository() . '/storage', realpath(dirname((string)Config::get('app.paths.storage'))) . '/storage');
        $this->assertStringStartsWith(self::repository(), (string)realpath(dirname((string)Config::get('monitoring.backup.db_dir'), 3)));
    }
}
