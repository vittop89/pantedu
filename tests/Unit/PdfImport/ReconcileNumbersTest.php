<?php

declare(strict_types=1);

namespace Tests\Unit\PdfImport;

use App\Services\PdfImport\ExtractionPipeline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReconcileNumbersTest extends TestCase
{
    #[Test]
    public function corregge_i_numeri_quando_i_conteggi_combaciano(): void
    {
        $items = [
            ['number' => '1', 'text' => 'a'],
            ['number' => '99', 'text' => 'b'],
            ['number' => '', 'text' => 'c'],
        ];
        $out = ExtractionPipeline::reconcileNumbers($items, ['7', '11', '335']);
        $this->assertSame('7', $out[0]['number']);
        $this->assertSame('11', $out[1]['number']);
        $this->assertSame('335', $out[2]['number']);
    }

    #[Test]
    public function non_tocca_nulla_se_i_conteggi_non_combaciano(): void
    {
        $items = [['number' => '1'], ['number' => '2']];
        $out = ExtractionPipeline::reconcileNumbers($items, ['7', '11', '335']);
        $this->assertSame('1', $out[0]['number']);
        $this->assertSame('2', $out[1]['number']);
    }

    #[Test]
    public function ignora_voci_vuote_nello_scan(): void
    {
        $items = [['number' => 'x'], ['number' => 'y']];
        // due numeri validi + rumore vuoto → conteggio effettivo 2 == 2 item
        $out = ExtractionPipeline::reconcileNumbers($items, ['7', '', '  ', '8']);
        $this->assertSame('7', $out[0]['number']);
        $this->assertSame('8', $out[1]['number']);
    }

    #[Test]
    public function lista_scan_vuota_lascia_invariato(): void
    {
        $items = [['number' => '5']];
        $out = ExtractionPipeline::reconcileNumbers($items, []);
        $this->assertSame('5', $out[0]['number']);
    }
}
