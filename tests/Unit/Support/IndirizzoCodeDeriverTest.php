<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\IndirizzoCodeDeriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndirizzoCodeDeriverTest extends TestCase
{
    /** @return list<array{string,string}> */
    public static function descrizioni(): array
    {
        return [
            // Una sola parola significativa → prime tre lettere.
            ['liceo scientifico',   'SCI'],
            ['liceo classico',      'CLA'],
            ['liceo linguistico',   'LIN'],
            ['liceo artistico',     'ART'],
            ['liceo sportivo',      'SPO'],
            ['turismo',             'TUR'],

            // Piu' parole → iniziali. "e" non conta, per questo AFM e non AFEM.
            ['amministrazione finanza e marketing', 'AFM'],
            ['liceo scientifico scienze applicate', 'SSA'],
            ['liceo scientifico a curvatura sportiva', 'SCS'],

            // Due parole darebbero due lettere: si completa dalla prima.
            ['scienze umane', 'SCU'],

            // Accenti e punteggiatura non devono cambiare il risultato.
            ['LICEO SCIENTIFICO', 'SCI'],
            ['liceo  scientifico ', 'SCI'],
        ];
    }

    #[Test]
    #[DataProvider('descrizioni')]
    public function deriva_il_codice_dalla_descrizione(string $desc, string $atteso): void
    {
        $this->assertSame($atteso, IndirizzoCodeDeriver::base($desc));
    }

    #[Test]
    public function indirizzo_sportivo_e_curvatura_sportiva_restano_distinti(): void
    {
        // NON sono la stessa cosa: "sezione ad indirizzo sportivo" e' un
        // percorso ministeriale a se', "curvatura sportiva" e' un arricchimento
        // che la scuola aggiunge a uno scientifico normale. Farli collassare in
        // un codice solo perderebbe la distinzione in silenzio, che e' il verso
        // sbagliato in cui sbagliare: una sigla brutta si corregge a mano, due
        // indirizzi fusi non si riconoscono piu'.
        $this->assertNotSame(
            IndirizzoCodeDeriver::base('liceo scientifico a curvatura sportiva'),
            IndirizzoCodeDeriver::base('SCIENTIFICO - SEZIONE AD INDIRIZZO SPORTIVO'),
            'due percorsi diversi non possono condividere il codice'
        );
    }

    #[Test]
    public function preferisce_un_codice_brutto_a_una_fusione(): void
    {
        // Tre varianti sportive dello stesso liceo: qualunque sia la sigla
        // proposta, devono restare tre.
        $codici = array_map(
            static fn(string $d): string => IndirizzoCodeDeriver::base($d),
            [
                'liceo sportivo',
                'SCIENTIFICO - SEZIONE AD INDIRIZZO SPORTIVO',
                'liceo scientifico a curvatura sportiva',
            ]
        );
        $this->assertCount(3, array_unique($codici), 'nessuna fusione: ' . implode(', ', $codici));
    }

    #[Test]
    public function il_caso_Esempio_distingue_sportivo_da_scientifico_sportivo(): void
    {
        // Il Esempio di Comune Esempio ha entrambi, e sono due indirizzi diversi:
        // con le sole prime tre lettere sarebbero SPO e SCI, ma "liceo
        // scientifico a curvatura sportiva" NON e' lo scientifico normale.
        $codici = [];
        foreach ([
            'liceo scientifico',
            'liceo sportivo',
            'liceo scientifico a curvatura sportiva',
            'liceo scientifico scienze applicate',
            'liceo artistico',
        ] as $desc) {
            $codici[$desc] = IndirizzoCodeDeriver::unique($desc, array_values($codici));
        }

        $this->assertSame(
            [
                'liceo scientifico'                      => 'SCI',
                'liceo sportivo'                         => 'SPO',
                'liceo scientifico a curvatura sportiva' => 'SCS',
                'liceo scientifico scienze applicate'    => 'SSA',
                'liceo artistico'                        => 'ART',
            ],
            $codici
        );
        $this->assertSame(count($codici), count(array_unique($codici)), 'codici tutti distinti');
    }

    #[Test]
    public function rispetta_i_codici_gia_assegnati_nella_scuola(): void
    {
        // SCI e' gia' preso da un'altra descrizione: il nuovo indirizzo deve
        // prendersi un codice suo invece di collidere.
        $code = IndirizzoCodeDeriver::unique('liceo scienze applicate', ['SCI', 'ART']);
        $this->assertNotSame('SCI', $code);
        $this->assertMatchesRegularExpression('/^[A-Z]{3,6}$/', $code);
    }

    #[Test]
    #[DataProvider('descrizioni')]
    public function ogni_codice_rispetta_il_pattern_del_curriculum(string $desc): void
    {
        // CurriculumService valida con ^[A-Z]{3,6}$: un codice che non passa
        // di li' sarebbe rifiutato all'inserimento.
        $this->assertMatchesRegularExpression('/^[A-Z]{3,6}$/', IndirizzoCodeDeriver::base($desc));
    }

    #[Test]
    public function descrizione_fatta_di_sole_stopword_non_produce_codice_vuoto(): void
    {
        $this->assertNotSame('', IndirizzoCodeDeriver::base('liceo'));
    }
}
