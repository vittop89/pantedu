<?php

namespace Tests\Unit;

use App\Services\Contract\ContractAggregate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regola di classificazione della fonte dei quesiti (copyright, art. 70-bis):
 * ContractAggregate::classifyItems è pura (nessun DB) ed è la stessa usata dal
 * contratto, dalle verifiche salvate dallo studio e dal backfill.
 */
final class ContractAggregateClassifyTest extends TestCase
{
    #[Test]
    public function nessunQuesitoRestaNonClassificato(): void
    {
        $r = ContractAggregate::classifyItems([]);
        self::assertNull($r['source_type']);
        self::assertSame(0, $r['items_total']);
    }

    #[Test]
    public function originPersonalEChiaveVuotaSonoQuesitiPropri(): void
    {
        $r = ContractAggregate::classifyItems([
            ['origin' => 'personal', 'source' => ''],
            ['source' => ''],
            [],
            ['origin' => 'PERSONAL'],
        ]);
        self::assertSame('personal', $r['source_type']);
        self::assertSame(4, $r['items_personal']);
        self::assertSame(0, $r['items_from_book']);
    }

    #[Test]
    public function chiaveDelRegistroInOriginSourceOBadgeEDalLibro(): void
    {
        $r = ContractAggregate::classifyItems([
            ['origin' => 'cdm_v4_ed1'],
            ['source' => 'matematica_multimediale_blu_vol_2_ed_3_zanichelli'],
            // source vuoto NON deve nascondere il badge del registro
            ['source' => '', 'badge' => ['source_key' => 'colori_della_matematica_vol_3_ed_1_deascuola_petrini']],
        ]);
        self::assertSame('book_textbook', $r['source_type']);
        self::assertSame(3, $r['items_from_book']);
    }

    #[Test]
    public function libroPiuPropriEMisto(): void
    {
        $r = ContractAggregate::classifyItems([
            ['origin' => 'personal'],
            ['badge' => ['source_key' => 'cdm_v4_ed1']],
        ]);
        self::assertSame('mixed', $r['source_type']);
    }

    #[Test]
    public function fonteNonDichiarataLasciaNonClassificato(): void
    {
        $r = ContractAggregate::classifyItems([
            ['origin' => 'personal'],
            ['origin' => 'unknown'],
        ]);
        self::assertNull($r['source_type']);
        self::assertSame(1, $r['items_unknown']);

        // Con almeno un quesito dal libro la cautela vince: resta bloccato come libro.
        $r = ContractAggregate::classifyItems([
            ['origin' => 'unknown'],
            ['origin' => 'cdm_v4_ed1'],
        ]);
        self::assertSame('book_textbook', $r['source_type']);
    }

    #[Test]
    public function originDichiaratoVinceSulBadge(): void
    {
        self::assertSame('personal', ContractAggregate::itemSourceKey(['origin' => 'personal', 'badge' => ['source_key' => 'cdm_v4_ed1']]));
        self::assertSame('cdm_v4_ed1', ContractAggregate::itemSourceKey(['origin' => '', 'badge' => ['source_key' => 'cdm_v4_ed1']]));
        self::assertSame('', ContractAggregate::itemSourceKey(['origin' => null, 'badge' => 'non-array']));
    }
}
