<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\RigheDellaBarra;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Quando la barra mostra il 📥 (ZIP TeX) e quali ruoli D/C/R, nei due versi.
 * La regola era copiata in due controller e mancava nel terzo; il 📥 compariva
 * su mappe ed esercizi con il solo seme (analisi C-export-bodypt).
 */
final class RigheDellaBarraTest extends TestCase
{
    private const SEME = [
        ['_type' => 'sectionHeader', 'level' => 1, 'text' => 'Esercizi per studenti'],
        ['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => '', 'marks' => []]]],
        ['_type' => 'sectionHeader', 'level' => 1, 'text' => 'Verifiche'],
        ['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => '', 'marks' => []]]],
    ];

    private const TESTO = [
        ['_type' => 'sectionHeader', 'level' => 2, 'text' => 'Introduzione'],
        ['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => 'Una riga vera', 'marks' => []]]],
    ];

    #[Test]
    public function un_documento_con_del_testo_ha_un_corpo_da_scaricare(): void
    {
        $this->assertTrue(RigheDellaBarra::haCorpoDaEsportare(['body_pt' => self::TESTO], 'document'));
        $this->assertTrue(RigheDellaBarra::haCorpoDaEsportare(['body_pt' => self::TESTO], 'exercise'));
        // Un nodo che non è titolo né blocco è contenuto vero anche senza testo.
        $this->assertTrue(RigheDellaBarra::haCorpoDaEsportare(['body_pt' => [['_type' => 'problem-group', 'items' => []]]], 'exercise'));
    }

    #[Test]
    public function il_segnaposto_non_si_scarica(): void
    {
        $this->assertFalse(RigheDellaBarra::haCorpoDaEsportare(['body_pt' => self::SEME], 'exercise'));
        // La prima sezione vuota di un documento Personalizzabile appena creato.
        $vuoto = [
            ['_type' => 'sectionHeader', 'title' => 'Nuova sezione', 'level' => 2],
            ['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => '   ', 'marks' => []]]],
        ];
        $this->assertFalse(RigheDellaBarra::haCorpoDaEsportare(['body_pt' => $vuoto], 'document'));
        $this->assertTrue(RigheDellaBarra::eSegnaposto(self::SEME));
        $this->assertFalse(RigheDellaBarra::eSegnaposto(self::TESTO));
    }

    #[Test]
    public function una_mappa_non_si_scarica_mai(): void
    {
        $this->assertFalse(RigheDellaBarra::haCorpoDaEsportare(['body_pt' => self::SEME, 'layout' => 'exercises'], 'map'));
        $this->assertFalse(RigheDellaBarra::haCorpoDaEsportare(['body_pt' => self::TESTO], 'map'));
    }

    #[Test]
    public function senza_corpo_non_si_scarica(): void
    {
        $this->assertFalse(RigheDellaBarra::haCorpoDaEsportare(null, 'document'));
        $this->assertFalse(RigheDellaBarra::haCorpoDaEsportare([], 'document'));
        $this->assertFalse(RigheDellaBarra::haCorpoDaEsportare(['body_pt' => []], 'document'));
        $this->assertFalse(RigheDellaBarra::haCorpoDaEsportare(['body_pt' => 'testo'], 'document'));
    }

    #[Test]
    public function i_ruoli_si_mettono_in_ordine_e_si_filtrano(): void
    {
        $this->assertSame('DC', RigheDellaBarra::ruoli(['doc_roles' => ['C', 'D']]));
        $this->assertSame('R', RigheDellaBarra::ruoli(['doc_roles' => ['r', 'X']]));
        $this->assertSame('', RigheDellaBarra::ruoli(['doc_roles' => 'D']));
        $this->assertSame('', RigheDellaBarra::ruoli(null));
    }

    #[Test]
    public function le_righe_si_arricchiscono_dai_metadati_json_o_decodificati(): void
    {
        $righe = RigheDellaBarra::arricchisci([
            ['id' => 1, 'content_format' => 'document', 'metadata_json' => json_encode(['body_pt' => self::TESTO, 'doc_roles' => ['D']])],
            ['id' => 2, 'content_type' => 'mappa', 'metadata_json' => json_encode(['body_pt' => self::TESTO])],
            ['id' => 3, 'content_type' => 'esercizio', 'metadata' => ['body_pt' => self::SEME]],
            ['id' => 4, 'content_type' => 'document'],
        ]);

        $this->assertSame([true, 'D'], [$righe[0]['has_body_pt'], $righe[0]['doc_roles']]);
        $this->assertSame([false, ''], [$righe[1]['has_body_pt'], $righe[1]['doc_roles']], 'il formato si ricava dal tipo');
        $this->assertFalse($righe[2]['has_body_pt']);
        $this->assertFalse($righe[3]['has_body_pt']);
        $this->assertSame(1, $righe[0]['id'], 'il resto della riga resta com\'era');
    }
}
