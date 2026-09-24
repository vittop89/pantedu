<?php

declare(strict_types=1);

namespace Tests\Unit\Ops;

use App\Services\Ops\ConservazioneDichiarata;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'invariante della conservazione dichiarata, provato nei due versi.
 *
 * Deve gridare quando un Istituto dichiara «non si conserva» e nel database ci
 * sono ancora compilazioni dei suoi docenti; e deve **tacere** quando
 * l'interruttore è acceso, altrimenti sarebbe un allarme che suona sempre —
 * cioè un allarme che dopo una settimana nessuno legge.
 *
 * Il verso che si dimentica quasi sempre è il secondo. Al 22/9/2026 in
 * produzione tutti gli Istituti hanno il salvataggio acceso: una guardia
 * provata nel solo verso «deve scattare» qui non scatterebbe mai, e
 * sembrerebbe verde per la ragione sbagliata.
 */
final class ConservazioneDichiarataTest extends TestCase
{
    #[Test]
    public function grida_quando_il_docente_ha_salvato_e_oggi_gli_si_negherebbe(): void
    {
        $fuori = ConservazioneDichiarata::incoerenti(
            perDocente: [77 => 61, 140 => 3],
            docentiNegati: [77],
        );

        self::assertSame([77 => 61], $fuori, "il docente negato con 61 righe salvate deve comparire");
    }

    #[Test]
    public function tace_quando_nessuno_ha_il_salvataggio_spento(): void
    {
        // Lo stato di produzione al 22/9/2026: due Istituti, entrambi accesi.
        $fuori = ConservazioneDichiarata::incoerenti(
            perDocente: [77 => 61, 140 => 3],
            docentiNegati: [],
        );

        self::assertSame([], $fuori, "con l'interruttore acceso non c'è niente da segnalare");
    }

    #[Test]
    public function tace_per_il_docente_negato_che_non_ha_salvato_niente(): void
    {
        // Il caso giusto: l'Istituto ha spento l'interruttore e da allora
        // nessuno ha salvato. È esattamente ciò che si voleva ottenere, e una
        // guardia che gridasse anche qui renderebbe inutile spegnerlo.
        $fuori = ConservazioneDichiarata::incoerenti(
            perDocente: [140 => 3],
            docentiNegati: [77],
        );

        self::assertSame([], $fuori, "chi non ha righe salvate non è un'incoerenza");
    }

    #[Test]
    public function un_conteggio_a_zero_non_e_unincoerenza(): void
    {
        // Un GROUP BY non produce righe a zero, ma la logica pura riceve anche
        // elenchi costruiti altrove: meglio che non inventi un allarme.
        $fuori = ConservazioneDichiarata::incoerenti(
            perDocente: [77 => 0],
            docentiNegati: [77],
        );

        self::assertSame([], $fuori, "zero compilazioni non sono una discrepanza");
    }

    #[Test]
    public function segnala_ogni_docente_negato_che_ha_righe(): void
    {
        $fuori = ConservazioneDichiarata::incoerenti(
            perDocente: [140 => 3, 77 => 61, 9 => 2],
            docentiNegati: [9, 77],
        );

        self::assertSame([9 => 2, 77 => 61], $fuori, "tutti i negati con righe, in ordine di id");
    }

    #[Test]
    public function le_chiavi_che_arrivano_come_stringhe_contano_lo_stesso(): void
    {
        // PDO, a seconda del driver e di PDO::ATTR_STRINGIFY_FETCHES,
        // restituisce i COUNT come stringhe. Una guardia che confrontasse i
        // tipi invece dei valori tacerebbe in produzione e passerebbe qui.
        $fuori = ConservazioneDichiarata::incoerenti(
            perDocente: ['77' => '61'],
            docentiNegati: ['77'],
        );

        self::assertSame([77 => 61], $fuori, "stringhe da PDO trattate come numeri");
    }
}
