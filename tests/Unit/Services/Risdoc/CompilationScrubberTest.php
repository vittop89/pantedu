<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Risdoc;

use App\Services\Risdoc\CompilationScrubber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** I campi su studenti e genitori non arrivano al database (scenari senza account studente). */
final class CompilationScrubberTest extends TestCase
{
    #[Test]
    public function svuota_i_campi_riconosciuti_e_lascia_gli_altri(): void
    {
        $in = [
            'state'  => ['classe' => '3', 'sezione' => 'A', 'professore' => 'V. P.', 'studente' => 'Mario Rossi'],
            'fields' => [
                'studentName'  => 'Mario',
                'studentSurname' => 'Rossi',
                'studentBirthDate' => '2010-05-05',
                'parentName'   => 'Anna',
                'nota_alunno'  => 'segue con fatica',
                'premessa'     => 'testo del docente',
                'consenso'     => true,
                'elenco_alunni' => ['a', 'b'],
            ],
            'body_pt' => [
                ['_type' => 'textField', 'name' => 'studentName', 'value' => 'Mario'],
                ['_type' => 'textField', 'name' => 'premessa', 'value' => 'resta'],
                ['_type' => 'table', 'name' => 'elenco_alunni', 'rows' => [['x']]],
                ['_type' => 'accordion', 'items' => [
                    ['body_pt' => [['_type' => 'select', 'name' => 'tutore_legale', 'value' => 'si']]],
                ]],
            ],
        ];

        $out = CompilationScrubber::scrub($in);
        $d = $out['data'];

        self::assertSame('', $d['fields']['studentName']);
        self::assertSame('', $d['fields']['studentSurname']);
        self::assertSame('', $d['fields']['studentBirthDate']);
        self::assertSame('', $d['fields']['parentName']);
        self::assertSame('', $d['fields']['nota_alunno']);
        self::assertSame([], $d['fields']['elenco_alunni']);
        self::assertSame('testo del docente', $d['fields']['premessa']);
        self::assertTrue($d['fields']['consenso']);

        self::assertArrayNotHasKey('studente', $d['state']);
        self::assertSame('V. P.', $d['state']['professore']);

        self::assertSame('', $d['body_pt'][0]['value']);
        self::assertSame('resta', $d['body_pt'][1]['value']);
        self::assertSame([], $d['body_pt'][2]['rows']);
        self::assertSame('', $d['body_pt'][3]['items'][0]['body_pt'][0]['value']);

        foreach (['studentName', 'parentName', 'nota_alunno', 'state.studente', 'elenco_alunni', 'tutore_legale'] as $name) {
            self::assertContains($name, $out['scrubbed']);
        }
        self::assertNotContains('premessa', $out['scrubbed']);
    }

    #[Test]
    public function senza_campi_sensibili_non_tocca_nulla(): void
    {
        $in = ['state' => ['classe' => '1'], 'fields' => ['premessa' => 'ok'], 'body_pt' => []];
        $out = CompilationScrubber::scrub($in);
        self::assertSame($in, $out['data']);
        self::assertSame([], $out['scrubbed']);
    }

    #[Test]
    public function il_professore_non_e_uno_studente(): void
    {
        self::assertFalse(CompilationScrubber::matches('professore'));
        self::assertFalse(CompilationScrubber::matches('disciplina'));
        self::assertTrue(CompilationScrubber::matches('Data di Nascita Studente'));
        self::assertTrue(CompilationScrubber::matches('codice_fiscale'));
    }
}
