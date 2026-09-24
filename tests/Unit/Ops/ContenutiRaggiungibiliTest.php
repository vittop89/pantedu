<?php

declare(strict_types=1);

namespace Tests\Unit\Ops;

use App\Services\Ops\ContenutiRaggiungibili;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'invariante nuovo della diagnostica, provato nei due versi: deve trovare
 * una riga che nessuna domanda della barra restituisce, e **non** deve
 * lamentarsi di quelle che una domanda la trovano.
 *
 * Il caso vero, misurato in produzione il 20/9/2026: nove contenuti con
 * `section_id` vuoto, invisibili in ogni pannello da aprile, mentre tutte e
 * sei le sezioni accettavano quattro tipi e quindi chiedevano per sezione.
 *
 * Un controllo che si limitasse a contare `section_id IS NULL` griderebbe al
 * lupo su un assetto con una sezione mono-tipo, dove quelle righe si vedono
 * benissimo: per questo qui c'è anche quel verso.
 */
final class ContenutiRaggiungibiliTest extends TestCase
{
    /** Le sei sezioni di produzione: tutte multi-tipo, tutte chieste per sezione. */
    private const SEI_MULTITIPO = [
        ['id' => 1, 'key' => 'mappe',  'type' => 'mappa',     'allowedTypes' => ['mappa', 'esercizio', 'verifica', 'document']],
        ['id' => 2, 'key' => 'lab',    'type' => 'esercizio', 'allowedTypes' => ['mappa', 'esercizio', 'verifica', 'document']],
        ['id' => 3, 'key' => 'eser',   'type' => 'esercizio', 'allowedTypes' => ['mappa', 'esercizio', 'verifica', 'document']],
        ['id' => 4, 'key' => 'verif',  'type' => 'verifica',  'allowedTypes' => ['mappa', 'esercizio', 'verifica', 'document']],
        ['id' => 5, 'key' => 'bes',    'type' => 'document',  'allowedTypes' => ['mappa', 'esercizio', 'verifica', 'document']],
        ['id' => 6, 'key' => 'risdoc', 'type' => 'document',  'allowedTypes' => ['mappa', 'esercizio', 'verifica', 'document']],
    ];

    #[Test]
    public function una_riga_senza_sezione_non_la_chiede_nessuno(): void
    {
        $fuori = ContenutiRaggiungibili::irraggiungibili(self::SEI_MULTITIPO, [
            ['id' => 962, 'content_type' => 'verifica', 'section_id' => null],
        ]);

        $this->assertCount(1, $fuori);
        $this->assertSame(962, $fuori[0]['id']);
        $this->assertStringContainsString('senza sezione', $fuori[0]['perche']);
    }

    #[Test]
    public function la_stessa_riga_si_vede_se_una_sezione_chiede_per_tipo(): void
    {
        // Stessa riga, stesso `section_id` vuoto: cambia solo che «verif»
        // accetta un tipo solo, e allora la barra chiede `?type=verifica`.
        $sezioni = self::SEI_MULTITIPO;
        $sezioni[3]['allowedTypes'] = ['verifica'];

        $this->assertSame([], ContenutiRaggiungibili::irraggiungibili($sezioni, [
            ['id' => 962, 'content_type' => 'verifica', 'section_id' => null],
        ]));
    }

    #[Test]
    public function una_riga_agganciata_a_una_sezione_viva_non_e_un_guasto(): void
    {
        $this->assertSame([], ContenutiRaggiungibili::irraggiungibili(self::SEI_MULTITIPO, [
            ['id' => 1013, 'content_type' => 'esercizio', 'section_id' => 3],
            ['id' => 80,   'content_type' => 'verifica',  'section_id' => 4],
            ['id' => 245,  'content_type' => 'mappa',     'section_id' => 1],
            // Un tipo che non è quello predefinito della sua sezione va
            // benissimo: la sezione multi-tipo li accetta tutti (ADR-027).
            ['id' => 999,  'content_type' => 'mappa',     'section_id' => 2],
        ]));
    }

    #[Test]
    public function una_sezione_spenta_lascia_fuori_i_suoi_contenuti(): void
    {
        // La sezione 4 non è più fra le attive: le sue righe non le chiede
        // più nessuno, anche se un `section_id` ce l'hanno.
        $sezioni = array_values(array_filter(
            self::SEI_MULTITIPO,
            static fn(array $s): bool => $s['id'] !== 4
        ));

        $fuori = ContenutiRaggiungibili::irraggiungibili($sezioni, [
            ['id' => 80, 'content_type' => 'verifica', 'section_id' => 4],
        ]);

        $this->assertCount(1, $fuori);
        $this->assertSame(4, $fuori[0]['section_id']);
        $this->assertStringContainsString('non è fra quelle attive', $fuori[0]['perche']);
    }

    #[Test]
    public function senza_contenuti_non_trova_niente(): void
    {
        $this->assertSame([], ContenutiRaggiungibili::irraggiungibili(self::SEI_MULTITIPO, []));
    }

    #[Test]
    public function i_nove_di_produzione_tornano_tutti_e_nove(): void
    {
        // Le righe misurate in produzione il 20/9/2026, prima della pulizia.
        $contenuti = [];
        foreach ([[1013, 'esercizio'], [962, 'verifica'], [335, 'document'], [336, 'document'],
                  [337, 'document'], [341, 'document'], [342, 'document'], [893, 'document'],
                  [894, 'document']] as [$id, $tipo]) {
            $contenuti[] = ['id' => $id, 'content_type' => $tipo, 'section_id' => null];
        }
        // ...e una manciata di righe sane, che non devono finire nell'elenco.
        $contenuti[] = ['id' => 80,  'content_type' => 'verifica', 'section_id' => 4];
        $contenuti[] = ['id' => 245, 'content_type' => 'mappa',    'section_id' => 1];

        $fuori = ContenutiRaggiungibili::irraggiungibili(self::SEI_MULTITIPO, $contenuti);

        $this->assertCount(9, $fuori);
        $this->assertSame(
            [1013, 962, 335, 336, 337, 341, 342, 893, 894],
            array_column($fuori, 'id')
        );
    }
}
