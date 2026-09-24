<?php

declare(strict_types=1);

namespace Tests\Unit\Maps;

use App\Services\Maps\RipristinoCaratteri;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le regole per rimettere le lettere accentate diventate «??» nelle mappe
 * (wiki/domains/mappe/mappe-overview.md, «Caratteri persi»).
 *
 * Il file rovinato si ottiene come l'ha ottenuto il vecchio sito nel 2025:
 * `mb_convert_encoding($xml, 'UTF-8', 'auto')` su un drawio lungo con pochi
 * accenti. Poi si controlla che le regole ricostruiscano i byte dell'originale,
 * e che rifiutino tutto quello che toccherebbe altro che i «?».
 */
final class RipristinoCaratteriTest extends TestCase
{
    private const TESTO = 'città è più 30 °C l’acqua 5 € COS\'È? e ??? mm';

    /**
     * Un drawio con 200 KB di solo ASCII prima del testo accentato, come le
     * mappe vere. La lunghezza conta: «auto» sceglie ASCII quando i byte non
     * ASCII sono pochi rispetto al resto (misurato con PHP 8.3: «città» dopo
     * 8 KB resta UTF-8, dopo 16 KB diventa «citt??»), e le mappe del 2025
     * pesavano da 40 KB a 8 MB con qualche decina di accenti.
     */
    private static function originale(string $testo = self::TESTO): string
    {
        $celle = '';
        for ($i = 2; strlen($celle) < 200_000; $i++) {
            $celle .= '<mxCell id="c' . $i . '" value="testo della mappa senza accenti" style="rounded=1;" vertex="1" parent="1">'
                . '<mxGeometry x="10" y="' . $i . '" width="120" height="40" as="geometry"/></mxCell>';
        }
        return '<mxfile host="prova"><diagram id="d" name="Pagina-1"><mxGraphModel><root>'
            . '<mxCell id="0"/><mxCell id="1" parent="0"/>' . $celle
            . '<mxCell id="t" value="' . htmlspecialchars($testo, ENT_COMPAT | ENT_XML1) . '" vertex="1" parent="1">'
            . '<mxGeometry x="0" y="0" width="80" height="30" as="geometry"/></mxCell>'
            . '</root></mxGraphModel></diagram></mxfile>';
    }

    /** @return list<array{offset:int, corsa:string, testo:string}> solo le corse da cambiare */
    private static function daCambiare(string $corrotto, string $originale): array
    {
        $tutte = RipristinoCaratteri::daOriginale($corrotto, $originale);
        self::assertNotNull($tutte);
        return array_values(array_filter($tutte, static fn(array $s): bool => $s['testo'] !== $s['corsa']));
    }

    #[Test]
    public function la_conversione_auto_del_vecchio_sito_toglie_ogni_accento_e_si_ricostruisce_byte_per_byte(): void
    {
        $originale = self::originale();
        $corrotto = mb_convert_encoding($originale, 'UTF-8', 'auto');

        // La perdita, riprodotta: nessun byte non ASCII, al loro posto dei «?».
        self::assertSame(0, preg_match('/[\x80-\xFF]/', $corrotto), 'con «auto» PHP ha scelto ASCII');
        self::assertStringContainsString('citt?? ?? pi?? 30 ??C l???acqua 5 ??? COS\'??? e ??? mm', $corrotto);
        self::assertSame(RipristinoCaratteri::asciify($originale), $corrotto, 'il file rovinato è asciify(originale)');
        self::assertTrue(RipristinoCaratteri::firmaDiPerdita($corrotto));

        $sostituzioni = self::daCambiare($corrotto, $originale);
        $r = RipristinoCaratteri::applica($corrotto, $sostituzioni, hash('sha256', $corrotto));

        self::assertNull($r['errore']);
        self::assertSame([], $r['conflitti']);
        self::assertTrue($r['per_offset']);
        self::assertSame($originale, $r['xml'], 'gli stessi byte dell\'originale');
        self::assertCount(7, $r['applicate'], 'sette corse cambiate; «??? mm» c\'era già nell\'originale');
    }

    #[Test]
    public function il_segnaposto_che_c_era_anche_nell_originale_resta_com_e(): void
    {
        $originale = self::originale('misura ??? mm e ?? h');
        $corrotto = RipristinoCaratteri::asciify($originale);

        $tutte = RipristinoCaratteri::daOriginale($corrotto, $originale);

        self::assertNotNull($tutte);
        self::assertCount(2, $tutte);
        foreach ($tutte as $s) {
            self::assertSame($s['corsa'], $s['testo'], 'legittima: il testo vero è la corsa stessa');
        }
        self::assertSame([], self::daCambiare($corrotto, $originale));
        $r = RipristinoCaratteri::applica($corrotto, $tutte, hash('sha256', $corrotto));
        self::assertSame($corrotto, $r['xml']);
        self::assertSame([], $r['applicate']);
        self::assertSame(['testo_non_ammesso', 'testo_non_ammesso'], array_column($r['conflitti'], 'motivo'));
    }

    #[Test]
    public function una_corsa_seguita_da_un_punto_di_domanda_vero_torna_con_il_suo_punto(): void
    {
        $originale = self::originale('COM\'È? E COS\'È?');
        $corrotto = RipristinoCaratteri::asciify($originale);
        self::assertStringContainsString('COM\'??? E COS\'???', $corrotto);

        $sostituzioni = self::daCambiare($corrotto, $originale);
        self::assertSame(['È?', 'È?'], array_column($sostituzioni, 'testo'));
        $r = RipristinoCaratteri::applica($corrotto, $sostituzioni, hash('sha256', $corrotto));

        self::assertNull($r['errore']);
        self::assertSame($originale, $r['xml']);
    }

    #[Test]
    public function un_originale_diverso_anche_di_un_byte_ascii_non_vale_come_originale(): void
    {
        $originale = self::originale();
        $corrotto = RipristinoCaratteri::asciify($originale);

        self::assertNull(RipristinoCaratteri::daOriginale($corrotto, str_replace('città', 'citta', $originale)), 'lunghezza diversa');
        self::assertNull(RipristinoCaratteri::daOriginale($corrotto, str_replace('senza accenti', 'senza accentI', $originale)), 'un byte ASCII diverso');
    }

    #[Test]
    public function una_sostituzione_che_tocca_altro_che_i_punti_di_domanda_si_rifiuta(): void
    {
        $originale = self::originale('unità di misura');
        $corrotto = RipristinoCaratteri::asciify($originale);
        $offset = strpos($corrotto, 'unit??');
        self::assertIsInt($offset);
        $offset += 4;
        $sha = hash('sha256', $corrotto);

        $casi = [
            'più lunga'              => 'à ',
            'più corta'              => "\xC3",
            'con un byte ASCII'      => 'aa',
            'con mezzo carattere'    => "?\xA0",
        ];
        foreach ($casi as $nome => $testo) {
            $r = RipristinoCaratteri::applica($corrotto, [['offset' => $offset, 'corsa' => '??', 'testo' => $testo]], $sha);
            self::assertSame($corrotto, $r['xml'], $nome);
            self::assertSame('testo_non_ammesso', $r['conflitti'][0]['motivo'] ?? null, $nome);
        }

        // L'invariante, da sola: un «nuovo» con un byte ASCII cambiato non passa.
        self::assertSame(
            'cambiato_altro_che_i_punti_di_domanda',
            RipristinoCaratteri::invarianteViolata($corrotto, str_replace('di misura', 'di misurA', $corrotto), [])
        );
        self::assertNull(RipristinoCaratteri::invarianteViolata($corrotto, str_replace('unit??', 'unità', $corrotto), [['corsa' => '??', 'testo' => 'à']]));
    }

    #[Test]
    public function una_sostituzione_che_non_e_utf8_valido_si_rifiuta(): void
    {
        $corrotto = RipristinoCaratteri::asciify(self::originale('unità'));
        $offset = (int)strpos($corrotto, '??');

        // Due byte non ASCII, stessa lunghezza, ma non un carattere: «\xC3\xC3».
        $r = RipristinoCaratteri::applica($corrotto, [['offset' => $offset, 'corsa' => '??', 'testo' => "\xC3\xC3"]], hash('sha256', $corrotto));

        self::assertSame($corrotto, $r['xml']);
        self::assertSame('testo_non_ammesso', $r['conflitti'][0]['motivo'] ?? null);
        self::assertSame('utf8_non_valido', RipristinoCaratteri::invarianteViolata('a??b', "a\xC3\xC3b", [['corsa' => '??', 'testo' => "\xC3\xC3"]]));
    }

    #[Test]
    public function con_il_blob_cambiato_si_ancora_al_contesto_e_il_testo_del_docente_non_si_tocca(): void
    {
        $originale = self::originale('la velocità è costante');
        $corrotto = RipristinoCaratteri::asciify($originale);
        $sostituzioni = [];
        foreach (self::daCambiare($corrotto, $originale) as $s) {
            $fine = $s['offset'] + strlen($s['corsa']);
            $sostituzioni[] = $s + [
                'sinistra' => RipristinoCaratteri::contestoSinistro($corrotto, $s['offset']),
                'destra'   => RipristinoCaratteri::contestoDestro($corrotto, $fine),
            ];
        }
        $sha = hash('sha256', $corrotto);

        // Il docente, dopo il censimento, ha aggiunto in testa una cella con un
        // accento vero: gli offset si spostano, il contesto no.
        $modificato = str_replace('<mxCell id="0"/>', '<mxCell id="0"/><mxCell id="n" value="già fatto" vertex="1" parent="1"/>', $corrotto);
        $r = RipristinoCaratteri::applica($modificato, $sostituzioni, $sha);

        self::assertNull($r['errore']);
        self::assertFalse($r['per_offset']);
        self::assertSame([], $r['conflitti']);
        self::assertStringContainsString('value="la velocità è costante"', $r['xml']);
        self::assertStringContainsString('value="già fatto"', $r['xml'], 'il testo del docente è com\'era');
        self::assertSame(RipristinoCaratteri::asciify($modificato), RipristinoCaratteri::asciify($r['xml']));
    }

    #[Test]
    public function una_corsa_il_cui_contesto_compare_due_volte_non_si_applica(): void
    {
        $originale = self::originale('è vero? sì');
        $corrotto = RipristinoCaratteri::asciify($originale);
        $offset = (int)strpos($corrotto, 'value="??');
        $offset += 7;
        $sostituzione = ['offset' => $offset, 'corsa' => '??', 'testo' => 'è', 'sinistra' => 'value="', 'destra' => ' vero'];
        $sha = hash('sha256', $corrotto);

        // Il blob è cambiato (sha diverso) e la stessa frase compare due volte:
        // non si sa quale delle due, e non si tocca nessuna.
        $doppio = str_replace('</root>', '<mxCell id="z" value="?? vero? s??" vertex="1" parent="1"/></root>', $corrotto);
        $r = RipristinoCaratteri::applica($doppio, [$sostituzione], $sha);

        self::assertSame($doppio, $r['xml']);
        self::assertSame('contesto_non_univoco', $r['conflitti'][0]['motivo'] ?? null);

        // Controprova: con una sola occorrenza lo stesso contesto basta.
        $r = RipristinoCaratteri::applica($corrotto . ' ', [$sostituzione], $sha);
        self::assertSame([], $r['conflitti']);
        self::assertStringContainsString('value="è vero? s??"', $r['xml']);
    }

    #[Test]
    public function allo_stesso_sha_l_offset_deve_puntare_alla_corsa_e_al_suo_contesto(): void
    {
        $corrotto = RipristinoCaratteri::asciify(self::originale('unità e qualità'));
        $offset = (int)strpos($corrotto, '??');
        $sha = hash('sha256', $corrotto);

        $r = RipristinoCaratteri::applica($corrotto, [['offset' => $offset + 1, 'corsa' => '??', 'testo' => 'à']], $sha);
        self::assertSame('offset_non_corrisponde', $r['conflitti'][0]['motivo'] ?? null, 'offset spostato di uno');

        $r = RipristinoCaratteri::applica($corrotto, [['offset' => $offset, 'corsa' => '??', 'testo' => 'à', 'sinistra' => 'XYZ']], $sha);
        self::assertSame('offset_non_corrisponde', $r['conflitti'][0]['motivo'] ?? null, 'contesto che non torna');

        $r = RipristinoCaratteri::applica($corrotto, [['offset' => $offset, 'corsa' => '??', 'testo' => 'à', 'sinistra' => 'unit', 'destra' => ' e']], $sha);
        self::assertSame([], $r['conflitti']);
        self::assertStringContainsString('unità e qualit??', $r['xml']);
    }

    #[Test]
    public function una_corsa_a_meta_una_doppia_o_senza_contesto_non_si_applicano(): void
    {
        $corrotto = RipristinoCaratteri::asciify(self::originale('costa 5 € e unità'));
        $sha = hash('sha256', $corrotto);
        $euro = (int)strpos($corrotto, '5 ???') + 2;
        $unita = (int)strpos($corrotto, 'unit??') + 4;

        // Gli ultimi due «?» del «???» dell'euro: la corsa vera è più lunga.
        $r = RipristinoCaratteri::applica($corrotto, [['offset' => $euro + 1, 'corsa' => '??', 'testo' => 'à']], $sha);
        self::assertSame('corsa_diversa', $r['conflitti'][0]['motivo'] ?? null);

        $due = [['offset' => $unita, 'corsa' => '??', 'testo' => 'à'], ['offset' => $unita, 'corsa' => '??', 'testo' => 'è']];
        $r = RipristinoCaratteri::applica($corrotto, $due, $sha);
        self::assertSame(['doppia'], array_column($r['conflitti'], 'motivo'));
        self::assertStringContainsString('unità', $r['xml'], 'vale la prima');

        // Blob cambiato e nessun contesto a cui ancorarsi: non si indovina.
        $r = RipristinoCaratteri::applica($corrotto . ' ', [['offset' => $unita, 'corsa' => '??', 'testo' => 'à']], $sha);
        self::assertSame('senza_contesto', $r['conflitti'][0]['motivo'] ?? null);
        self::assertSame($corrotto . ' ', $r['xml']);
    }

    #[Test]
    public function le_invarianti_contano_le_corse_e_leggono_l_xml(): void
    {
        self::assertSame('corse_non_tornano', RipristinoCaratteri::invarianteViolata('<a>x?? y??</a>', '<a>xà yà</a>', [['corsa' => '??', 'testo' => 'à']]));
        self::assertNull(RipristinoCaratteri::invarianteViolata('<a>x?? y??</a>', '<a>xà yà</a>', [['corsa' => '??', 'testo' => 'à'], ['corsa' => '??', 'testo' => 'à']]));
        self::assertTrue(RipristinoCaratteri::siLeggeComeXml('<a b="c"/>'));
        self::assertFalse(RipristinoCaratteri::siLeggeComeXml('<a b="c">'));

        // U+FFFE è UTF-8 valido ma in XML non è ammesso: un documento che si
        // leggeva e non si leggerebbe più resta com'era.
        $r = RipristinoCaratteri::applica('<a>x???</a>', [['offset' => 4, 'corsa' => '???', 'testo' => "\u{FFFE}"]], hash('sha256', '<a>x???</a>'));
        self::assertSame('xml_non_leggibile', $r['errore']);
        self::assertSame('<a>x???</a>', $r['xml']);
        self::assertSame([], $r['applicate']);
    }

    #[Test]
    public function il_contesto_negli_originali_decide_solo_se_e_univoco(): void
    {
        $o1 = 'la città di Roma; la città di Milano; tre unità di misura';
        $o2 = 'una città di mare';
        $originali = [[$o1, RipristinoCaratteri::asciify($o1)], [$o2, RipristinoCaratteri::asciify($o2)]];

        $r = RipristinoCaratteri::daContesto('la citt', '??', ' di Roma', $originali);
        self::assertSame('à', $r['testo']);
        self::assertSame(16, $r['finestra']);

        // «qu?? di» non c'è da nessuna parte, neanche a 4 byte.
        self::assertNull(RipristinoCaratteri::daContesto('qu', '??', ' di', $originali)['testo']);

        // Due originali danno due testi diversi per lo stesso contesto (un «??»
        // voluto e una «è»): i candidati sono due e non si decide.
        $o3 = 'la pena ?? di mare';
        $o4 = 'la pena è di mare';
        $ambigui = [[$o3, RipristinoCaratteri::asciify($o3)], [$o4, RipristinoCaratteri::asciify($o4)]];
        $r = RipristinoCaratteri::daContesto('pena ', '??', ' di', $ambigui);
        self::assertNull($r['testo']);
        self::assertCount(2, $r['candidati']);
    }

    #[Test]
    public function la_firma_della_perdita_scatta_sul_testo_rovinato_e_non_sugli_altri(): void
    {
        self::assertTrue(RipristinoCaratteri::firmaDiPerdita('la velocit?? ?? costante'));
        self::assertTrue(RipristinoCaratteri::firmaDiPerdita('angoli di 30?? - 60?? e COM\'?? SCRITTO'), 'anche senza una lettera davanti');
        self::assertFalse(RipristinoCaratteri::firmaDiPerdita('la velocità è costante'), 'accenti veri');
        self::assertFalse(RipristinoCaratteri::firmaDiPerdita('pi?? e anche è'), 'c\'è almeno un byte non ASCII: non è il file del 2025');
        self::assertFalse(RipristinoCaratteri::firmaDiPerdita('Che cosa? Perché?'), 'punti di domanda singoli');
        self::assertFalse(RipristinoCaratteri::firmaDiPerdita('solo testo ASCII, niente corse'));
        self::assertFalse(RipristinoCaratteri::firmaDiPerdita(''));
        // Per eccesso, di proposito: un segnaposto in un file tutto ASCII si rifiuta e si guarda a mano.
        self::assertTrue(RipristinoCaratteri::firmaDiPerdita('misura ??? mm'));
    }

    #[Test]
    public function il_contesto_non_taglia_un_carattere_a_meta(): void
    {
        $testo = 'àèìòù??éà';
        $offset = (int)strpos($testo, '??');

        $sinistra = RipristinoCaratteri::contestoSinistro($testo, $offset, 5);
        $destra = RipristinoCaratteri::contestoDestro($testo, $offset + 2, 3);

        self::assertSame('òù', $sinistra, 'cinque byte: mezzo «ì» si lascia');
        self::assertSame('é', $destra, 'tre byte: mezzo «à» si lascia');
        self::assertSame(1, preg_match('//u', $sinistra . $destra));
    }

    #[Test]
    public function le_pagine_compresse_si_contano_e_non_si_toccano(): void
    {
        $pagina = base64_encode((string)gzdeflate(rawurlencode('<mxGraphModel><root><mxCell value="\sqrt[2]{-8}=???"/></root></mxGraphModel>')));
        $xml = '<mxfile><diagram id="a" name="P1">' . $pagina . '</diagram><diagram id="b" name="P2"><mxGraphModel/></diagram></mxfile>';

        self::assertSame(['pagine' => 1, 'corse' => 1], RipristinoCaratteri::pagineCompresse($xml));
        self::assertSame([], RipristinoCaratteri::corse($xml), 'nel file le pagine compresse sono base64, senza «?»');
    }
}
