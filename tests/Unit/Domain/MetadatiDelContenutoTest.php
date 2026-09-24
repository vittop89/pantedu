<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\MetadatiDelContenuto;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le regole di scrittura dei metadati di un contenuto: la patch del modale ✎
 * e le chiavi che una mappa non accetta. Il giro vero, con il database, è in
 * tests/Integration/ModaleConservaMetadatiTest.php.
 */
final class MetadatiDelContenutoTest extends TestCase
{
    #[Test]
    public function la_patch_sostituisce_le_sue_chiavi_toglie_i_null_e_lascia_il_resto(): void
    {
        $salvati = '{"mappa":{"href":"a","drawio_id":"x"},"stats":{},"contract_key":"k","doc_roles":["D"]}';
        $patch = MetadatiDelContenuto::patchDa('{"mappa":{"href":"b","drawio_id":"x"},"doc_roles":null}');

        $this->assertSame(
            '{"mappa":{"href":"b","drawio_id":"x"},"stats":{},"contract_key":"k"}',
            MetadatiDelContenuto::fondi($salvati, $patch)
        );
    }

    #[Test]
    public function senza_metadati_salvati_resta_la_patch_e_senza_chiavi_resta_null(): void
    {
        $this->assertSame('{"category":"VERIFICHE"}', MetadatiDelContenuto::fondi(null, MetadatiDelContenuto::patchDa('{"category":"VERIFICHE"}')));
        $this->assertSame('{"category":"VERIFICHE"}', MetadatiDelContenuto::fondi('[]', MetadatiDelContenuto::patchDa('{"category":"VERIFICHE"}')));
        $this->assertNull(MetadatiDelContenuto::fondi('{"url":"u"}', MetadatiDelContenuto::patchDa('{"url":null}')));
    }

    #[Test]
    public function una_patch_che_non_e_un_oggetto_si_rifiuta(): void
    {
        foreach (['[1,2]', '"testo"', 'non json', '', 'null'] as $sbagliata) {
            try {
                MetadatiDelContenuto::patchDa($sbagliata);
                $this->fail("accettata: $sbagliata");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('metadata_patch_non_valida', $e->getMessage());
            }
        }
        $this->assertTrue(MetadatiDelContenuto::patchVuota(MetadatiDelContenuto::patchDa('{}')));
    }

    #[Test]
    public function una_mappa_non_accetta_layout_body_pt_e_doc_roles(): void
    {
        $patch = MetadatiDelContenuto::patchDa('{"layout":"exercises","body_pt":[],"doc_roles":["D"],"mappa":{"href":"h"}}');

        $perMappa = MetadatiDelContenuto::patchPerIlTipo($patch, 'mappa');
        $this->assertSame(['mappa'], array_keys(get_object_vars($perMappa)));
        $this->assertSame(
            ['mappa' => ['href' => 'h']],
            MetadatiDelContenuto::perIlTipo(['layout' => 'exercises', 'body_pt' => [], 'doc_roles' => ['D'], 'mappa' => ['href' => 'h']], 'mappa')
        );
    }

    /**
     * 20/9/2026, revisione della PR #145 — con il dual-write acceso il corpo
     * si sfila dai metadati per cifrarlo a parte. Se lo si sfila passando da
     * un array (`json_decode($json, true)`) ogni `{}` annidato diventa `[]`:
     * la garanzia della classe vale su una strada sola.
     */
    #[Test]
    public function sfilare_il_corpo_lascia_gli_oggetti_vuoti_come_sono(): void
    {
        $this->assertSame(
            ['{"layout":"custom","opzioni":{},"stats":{"has_tikz":false}}', '[{"_type":"block"}]'],
            MetadatiDelContenuto::sfilaIlCorpo('{"layout":"custom","opzioni":{},"body_pt":[{"_type":"block"}],"stats":{"has_tikz":false}}')
        );
    }

    #[Test]
    public function sfilare_il_corpo_dove_non_ce_lascia_tutto_dov_era(): void
    {
        $this->assertSame(['{"opzioni":{}}', null], MetadatiDelContenuto::sfilaIlCorpo('{"opzioni":{}}'));
        $this->assertSame([null, null], MetadatiDelContenuto::sfilaIlCorpo(null));
        // Senza altre chiavi i metadati restano vuoti, e il corpo esce lo stesso.
        $this->assertSame([null, '[]'], MetadatiDelContenuto::sfilaIlCorpo('{"body_pt":[]}'));
        // Un `body_pt` a null è una chiave come un'altra: esce, e vale null.
        $this->assertSame(['{"layout":"custom"}', 'null'], MetadatiDelContenuto::sfilaIlCorpo('{"layout":"custom","body_pt":null}'));
    }

    #[Test]
    public function gli_altri_tipi_accettano_tutto(): void
    {
        $patch = MetadatiDelContenuto::patchDa('{"layout":"exercises","body_pt":[],"doc_roles":["D"]}');
        foreach (['esercizio', 'verifica', 'document'] as $tipo) {
            $this->assertSame(['layout', 'body_pt', 'doc_roles'], array_keys(get_object_vars(MetadatiDelContenuto::patchPerIlTipo($patch, $tipo))), $tipo);
            $this->assertSame(['layout' => 'custom'], MetadatiDelContenuto::perIlTipo(['layout' => 'custom'], $tipo));
        }
        // La patch di partenza non cambia: il filtro lavora su una copia.
        $this->assertSame(['layout', 'body_pt', 'doc_roles'], array_keys(get_object_vars($patch)));
    }
}
