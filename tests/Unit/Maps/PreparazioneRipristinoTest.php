<?php

declare(strict_types=1);

namespace Tests\Unit\Maps;

use App\Services\Maps\PreparazioneRipristino;
use App\Services\Maps\RipristinoCaratteri;
use App\Services\Maps\RipristinoCaratteriMappe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Dal censimento e dagli originali, la patch e l'elenco da rivedere
 * (tools/maps/prepara_ripristino_caratteri.php). Nei due versi: entra nella
 * patch solo quello che ha una fonte (originale esatto o contesto univoco),
 * il resto va nell'elenco e ci entra solo se approvato.
 */
final class PreparazioneRipristinoTest extends TestCase
{
    private static function drawio(string $testo, string $riempitivo = 'nodo'): string
    {
        return '<mxfile><diagram id="d" name="P"><mxGraphModel><root><mxCell id="0"/>'
            . '<mxCell id="a" value="' . $riempitivo . '"/><mxCell id="t" value="' . $testo . '"/></root></mxGraphModel></diagram></mxfile>';
    }

    /**
     * Il censimento di un insieme di mappe, come lo fa il server.
     *
     * @param array<int, array{0:int, 1:string}> $mappe id => [docente, blob]
     * @return array<string,mixed>
     */
    private static function censimento(array $mappe): array
    {
        $elenco = [];
        foreach ($mappe as $id => [$docente, $xml]) {
            $corse = [];
            foreach (RipristinoCaratteri::corse($xml) as $c) {
                $corse[] = $c + [
                    'sinistra' => RipristinoCaratteri::contestoSinistro($xml, $c['offset']),
                    'destra'   => RipristinoCaratteri::contestoDestro($xml, $c['offset'] + strlen($c['corsa'])),
                ];
            }
            $elenco[] = [
                'id' => $id, 'teacher_id' => $docente, 'titolo' => "Mappa $id", 'map_version' => 1,
                'sha256' => hash('sha256', $xml), 'utf8_valido' => true, 'corse' => $corse,
            ];
        }
        return ['formato' => RipristinoCaratteriMappe::FORMATO_CENSIMENTO, 'generato' => '2026-09-19T00:00:00Z', 'mappe' => $elenco];
    }

    #[Test]
    public function con_l_originale_esatto_le_sostituzioni_sono_per_offset_e_le_copie_si_decidono_una_volta(): void
    {
        $originale = self::drawio('La città è più bella. Misura ??? mm.');
        $blob = RipristinoCaratteri::asciify($originale);

        $esito = (new PreparazioneRipristino(['o.drawio' => $originale]))->prepara(self::censimento([10 => [77, $blob], 20 => [140, $blob]]));

        self::assertCount(2, $esito['patch']['mappe'], 'la mappa e la sua copia');
        foreach ($esito['patch']['mappe'] as $m) {
            self::assertSame(['à', 'è', 'ù'], array_column($m['sostituzioni'], 'testo'));
            self::assertSame([RipristinoCaratteri::FONTE_ESATTO], array_values(array_unique(array_column($m['sostituzioni'], 'fonte'))));
            $r = RipristinoCaratteri::applica($blob, $m['sostituzioni'], $m['sha256']);
            self::assertSame($originale, $r['xml']);
        }
        // Il segnaposto è legittimo: una riga sola per le due copie, e fuori dalla patch.
        self::assertCount(1, $esito['revisione']);
        self::assertSame(['legittima', '10 (77), 20 (140)'], [$esito['revisione'][0]['fonte'], $esito['revisione'][0]['mappe']]);
        self::assertSame(['mappe' => 1, 'corse' => 4, 'esatto' => 3, 'contesto' => 0, 'legittime' => 1, 'proposte' => 0, 'senza_fonte' => 0], $esito['conteggi']['per_docente'][77]);
    }

    #[Test]
    public function una_mappa_modificata_dal_docente_si_ricostruisce_dal_contesto_e_non_per_offset(): void
    {
        $originale = self::drawio('La velocità è costante, la densità no.');
        // Il docente ha cambiato il riempitivo: lo sha non è più quello dell'originale.
        $blob = RipristinoCaratteri::asciify(self::drawio('La velocità è costante, la densità no.', 'nodo cambiato dal docente'));

        $esito = (new PreparazioneRipristino(['o.drawio' => $originale]))->prepara(self::censimento([30 => [77, $blob]]));

        $sost = $esito['patch']['mappe'][0]['sostituzioni'];
        self::assertSame(['à', 'è', 'à'], array_column($sost, 'testo'));
        self::assertSame([RipristinoCaratteri::FONTE_CONTESTO], array_values(array_unique(array_column($sost, 'fonte'))));
        self::assertSame([], $esito['revisione']);
    }

    #[Test]
    public function senza_fonte_la_corsa_va_nell_elenco_con_la_proposta_e_non_nella_patch(): void
    {
        $blob = RipristinoCaratteri::asciify(self::drawio('Si misura in 20 °C, e poi?? no'));

        $esito = (new PreparazioneRipristino(['o.drawio' => self::drawio('altro testo')]))->prepara(self::censimento([40 => [77, $blob]]));

        self::assertSame([], $esito['patch']['mappe']);
        self::assertSame(['°'], array_column(array_filter($esito['revisione'], static fn(array $r): bool => $r['regola'] === 'grado'), 'testo'));
        foreach ($esito['revisione'] as $riga) {
            self::assertSame('', $riga['approvata'], 'nessuna riga nasce approvata');
        }
    }

    #[Test]
    public function solo_le_righe_approvate_e_valide_entrano_nella_patch(): void
    {
        $blob = RipristinoCaratteri::asciify(self::drawio("Quanto ?? lungo? E l'anno 1?? ok"));
        $censimento = self::censimento([50 => [77, $blob], 51 => [140, $blob]]);
        $esito = (new PreparazioneRipristino([]))->prepara($censimento);
        $righe = $esito['revisione'];
        self::assertCount(2, $righe);
        [$e, $numero] = $righe;

        $nbsp = '\\' . 'u00A0';
        $unite = PreparazioneRipristino::conApprovate($censimento, [
            ['approvata' => 'Sì'] + $e,                                   // «è» proposta dalla regola, approvata
            ['approvata' => 'x', 'testo' => $nbsp] + $numero,            // scritta a mano, come
            ['approvata' => 'si', 'testo' => 'è', 'chiave' => 'ffffffffffffffff:1'] + $e, // chiave che non c'è
        ], $esito['patch']);

        self::assertSame(4, $unite['aggiunte'], 'due righe, due copie ciascuna');
        self::assertSame(['chiave non presente nel censimento'], array_column($unite['scartate'], 'motivo'));
        foreach ($unite['patch']['mappe'] as $m) {
            self::assertSame(['è', "\u{00A0}"], array_column($m['sostituzioni'], 'testo'));
            self::assertSame(['approvata', 'approvata'], array_column($m['sostituzioni'], 'fonte'));
        }

        // Senza «sì» non entra niente; con un testo che non può stare al posto della corsa neanche.
        self::assertSame(0, PreparazioneRipristino::conApprovate($censimento, [['approvata' => 'no'] + $e], $esito['patch'])['aggiunte']);
        $sbagliata = PreparazioneRipristino::conApprovate($censimento, [['approvata' => 'si', 'testo' => 'e '] + $e], $esito['patch']);
        self::assertSame(0, $sbagliata['aggiunte']);
        self::assertStringStartsWith('testo_non_ammesso', $sbagliata['scartate'][0]['motivo']);
    }

    #[Test]
    public function lo_spazio_non_separabile_si_scrive_in_chiaro_nel_csv_e_torna_com_era(): void
    {
        $scritto = PreparazioneRipristino::perScrivere("20\u{00A0}m");
        self::assertSame('20\\' . 'u00A0m', $scritto);
        self::assertSame("20\u{00A0}m", PreparazioneRipristino::daScritto($scritto));
    }

    #[Test]
    public function le_pagine_compresse_degli_originali_fanno_da_contesto(): void
    {
        $pagina = '<mxGraphModel><root><mxCell id="t" value="La velocità è costante"/></root></mxGraphModel>';
        $compresso = '<mxfile><diagram id="d" name="P">' . base64_encode((string)gzdeflate(rawurlencode($pagina))) . '</diagram></mxfile>';
        $blob = RipristinoCaratteri::asciify('<mxfile><diagram id="d" name="P">' . $pagina . '</diagram><diagram id="e" name="Q"/></mxfile>');

        $esito = (new PreparazioneRipristino(['o.drawio' => $compresso]))->prepara(self::censimento([60 => [77, $blob]]));

        self::assertSame(['à', 'è'], array_column($esito['patch']['mappe'][0]['sostituzioni'] ?? [], 'testo'));
    }
}
