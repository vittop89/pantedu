<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TeacherSectionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La regola "1 vs 1A" decide chi vede cosa: se sbaglia, o uno studente resta
 * senza materiali o li vede tutti — che e' il problema da cui siamo partiti.
 */
final class TeacherSectionServiceTest extends TestCase
{
    /** @return list<array{?string,?string,bool,string}> */
    public static function coppie(): array
    {
        return [
            // docente, studente, atteso, perche'
            ['1A', '1A', true,  'stessa sezione'],
            ['1',  '1A', true,  'docente sul corso intero: copre tutte le sezioni'],
            ['1',  '1B', true,  'idem, altra sezione'],
            ['1',  '1',  true,  'entrambi senza sezione'],

            ['1A', '1B', false, 'sezioni diverse'],
            ['1A', '1',  false, 'lo studente senza sezione non si assegna d ufficio'],
            ['2',  '1A', false, 'anno diverso'],
            ['1A', '2A', false, 'stessa lettera, anno diverso'],

            ['1a', '1A', true,  'confronto case-insensitive'],
            ['1A', '1a', true,  'idem, invertito'],

            [null, '1A', false, 'incarico assente'],
            ['1A', null, false, 'studente senza classe'],
            ['',   '1A', false, 'stringa vuota'],
            ['xx', '1A', false, 'classe non riconoscibile'],
        ];
    }

    #[Test]
    #[DataProvider('coppie')]
    public function la_regola_di_copertura(?string $doc, ?string $stud, bool $atteso, string $perche): void
    {
        $this->assertSame(
            $atteso,
            TeacherSectionService::classeMatches($doc, $stud),
            "docente=$doc studente=$stud — $perche"
        );
    }

    #[Test]
    public function la_copertura_non_e_simmetrica(): void
    {
        // Il punto meno ovvio della regola, e quello che va tenuto fermo:
        // "1" copre "1A", ma "1A" non copre "1".
        $this->assertTrue(TeacherSectionService::classeMatches('1', '1A'));
        $this->assertFalse(TeacherSectionService::classeMatches('1A', '1'));
    }

    /** @return list<array{?string,?string}> */
    public static function anni(): array
    {
        return [['1A', '1'], ['1', '1'], ['5BLSS', '5'], ['3b', '3'], ['xx', null], ['', null], [null, null]];
    }

    #[Test]
    #[DataProvider('anni')]
    public function estrae_l_anno_di_corso(?string $classe, ?string $atteso): void
    {
        $this->assertSame($atteso, TeacherSectionService::anno($classe));
    }

    #[Test]
    public function le_sezioni_lunghe_del_Esempio_restano_riconoscibili(): void
    {
        // Nel dataset MIUR il Esempio ha 1ALSS, 1BLSS, 2BA, 3AR: il suffisso
        // codifica l'indirizzo dentro la sezione. Devono restare classi valide.
        foreach (['1ALSS', '1BLSS', '2BA', '3AR', '5BA'] as $sezione) {
            $this->assertNotNull(
                TeacherSectionService::anno($sezione),
                "$sezione deve essere riconosciuta come classe"
            );
            $this->assertTrue(
                TeacherSectionService::classeMatches(TeacherSectionService::anno($sezione), $sezione),
                "un docente sull'anno deve coprire $sezione"
            );
        }
    }
}
