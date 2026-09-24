<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Drive;

use App\Services\Drive\MapSyncService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il tipo e il nome del file che la sincronizzazione manda a Drive (15/9/2026:
 * 56 mappe senza tipo registrato arrivavano senza estensione, come dati binari).
 */
final class MapSyncNomeETipoTest extends TestCase
{
    /** @return iterable<string, array{0:string, 1:string, 2:string}> */
    public static function contenuti(): iterable
    {
        yield 'draw.io senza tipo' => ['', '<mxfile host="Electron">…</mxfile>', 'application/xml'];
        yield 'XML con BOM e a capo' => ['', "\xEF\xBB\xBF\n  <?xml version=\"1.0\"?><mxfile/>", 'application/xml'];
        yield 'PDF senza tipo' => ['', "%PDF-1.7\n…", 'application/pdf'];
        yield 'PNG senza tipo' => ['', "\x89PNG\r\n\x1A\n…", 'image/png'];
        yield 'JPEG senza tipo' => ['', "\xFF\xD8\xFF\xE0…", 'image/jpeg'];
        yield 'binario che non si riconosce' => ['', "\x00\x01\x02", MapSyncService::TIPO_SCONOSCIUTO];
        yield 'vuoto' => ['', '', MapSyncService::TIPO_SCONOSCIUTO];
        yield 'tipo registrato vince sul contenuto' => ['image/png', '<mxfile/>', 'image/png'];
    }

    #[Test]
    #[DataProvider('contenuti')]
    public function il_tipo_e_quello_registrato_o_quello_del_contenuto(string $registrato, string $contenuto, string $atteso): void
    {
        $this->assertSame($atteso, MapSyncService::tipoDelContenuto($registrato, $contenuto));
    }

    #[Test]
    public function il_nome_prende_l_estensione_dal_tipo(): void
    {
        $this->assertSame('6.1_Specchi.drawio', MapSyncService::nomeDelFile(['title' => '6.1_Specchi'], 'application/xml'));
        $this->assertSame('Ottica_Specchi.drawio', MapSyncService::nomeDelFile(['title' => 'Specchi', 'topic' => 'Ottica'], 'application/xml'));
        $this->assertSame('Tavola.pdf', MapSyncService::nomeDelFile(['title' => 'Tavola'], 'application/pdf'));
        $this->assertSame('Sconosciuto', MapSyncService::nomeDelFile(['title' => 'Sconosciuto'], MapSyncService::TIPO_SCONOSCIUTO), 'senza tipo, niente estensione inventata');
    }
}
