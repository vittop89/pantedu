<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Repositories\Contract\ContractRepository;
use App\Services\ContractRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Agli studenti non arriva mai la traccia di un esercizio preso da un libro
 * (24/9/2026, Termini 1.6).
 *
 * Il riferimento (libro, pagina, numero, difficoltà) sta nel badge, con la
 * chiave del libro; la soluzione è quella scritta dal docente. Fino a quel
 * giorno la garanzia dipendeva da come il docente aveva copiato l'esercizio: la
 * copia «completa» portava la traccia, e una traccia scritta a mano nel campo
 * arrivava agli studenti così com'era.
 *
 * Nei due versi: chi non può modificare riceve il segnaposto e la soluzione,
 * non la traccia né il testo delle opzioni; il docente riceve tutto; un
 * esercizio senza libro (scritto dal docente) arriva intero anche agli
 * studenti.
 */
final class TracceDeiLibriMaiAgliStudentiTest extends TestCase
{
    private const TRACCIA = 'TRACCIA COPIATA DAL LIBRO';
    private const OPZIONE = 'OPZIONE COPIATA DAL LIBRO';
    private const SOLUZIONE = 'SVOLGIMENTO SCRITTO DAL DOCENTE';

    /** @param array<string,mixed> $item */
    private function rendi(array $item, string $tipo, bool $puoModificare): string
    {
        $contract = [
            'title' => 'Prova',
            'groups' => [[
                'id' => 'g1', 'type' => $tipo, 'title' => 'Gruppo', 'intro' => '',
                'items' => [$item],
            ]],
        ];
        return (new ContractRenderer([], $puoModificare))->renderContract($contract);
    }

    /** @return array<string,mixed> */
    private function dalLibro(): array
    {
        return [
            'id' => 'q1',
            'source' => 'mmb_v2_ed3',
            'difficulty' => 2,
            'badge' => ['source_key' => 'matematica_multimediale_blu_vol_2', 'page' => '732', 'ex_num' => '7'],
            'question' => [['type' => 'text', 'content' => self::TRACCIA]],
            'solution' => [['type' => 'text', 'content' => self::SOLUZIONE]],
        ];
    }

    #[Test]
    public function allo_studente_arrivano_riferimento_e_soluzione_non_la_traccia(): void
    {
        $html = $this->rendi($this->dalLibro(), 'type_Collect', false);

        self::assertStringNotContainsString(self::TRACCIA, $html);
        self::assertStringContainsString(ContractRepository::SOURCE_PLACEHOLDER, $html);
        self::assertStringContainsString('732', $html, 'il badge con la pagina resta');
    }

    #[Test]
    public function il_docente_vede_la_traccia(): void
    {
        $html = $this->rendi($this->dalLibro(), 'type_Collect', true);

        self::assertStringContainsString(self::TRACCIA, $html);
    }

    #[Test]
    public function un_esercizio_senza_libro_arriva_intero_anche_allo_studente(): void
    {
        $proprio = $this->dalLibro();
        unset($proprio['badge'], $proprio['source']);

        $html = $this->rendi($proprio, 'type_Collect', false);

        self::assertStringContainsString(self::TRACCIA, $html);
    }

    #[Test]
    public function in_una_domanda_a_scelta_le_opzioni_del_libro_non_arrivano(): void
    {
        $item = $this->dalLibro();
        $item['options'] = [
            ['letter' => 'a', 'correct' => true, 'content' => [['type' => 'text', 'content' => self::OPZIONE]]],
            ['letter' => 'b', 'correct' => false, 'content' => [['type' => 'text', 'content' => self::OPZIONE]]],
        ];

        $studente = $this->rendi($item, 'type_RMulti_1', false);
        $docente = $this->rendi($item, 'type_RMulti_1', true);

        self::assertStringNotContainsString(self::OPZIONE, $studente);
        self::assertStringNotContainsString(self::TRACCIA, $studente);
        self::assertStringContainsString(self::OPZIONE, $docente);
    }

    #[Test]
    public function il_filtro_da_solo_non_tocca_la_soluzione(): void
    {
        $filtrato = ContractRenderer::senzaLaTracciaDelLibro($this->dalLibro());

        self::assertSame($this->dalLibro()['solution'], $filtrato['solution']);
        self::assertSame($this->dalLibro()['badge'], $filtrato['badge']);
    }
}
