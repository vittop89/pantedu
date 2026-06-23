<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\InstituteRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit puro (no DB) per l'identità canonica degli istituti:
 * isRealMiurCode() + dedupKey(). Vedi [[project_institute_dedup]].
 */
final class InstituteRepositoryDedupTest extends TestCase
{
    #[Test]
    public function real_miur_code_matches_mechanographic_format(): void
    {
        // 2 lettere provincia + 8 alfanumerici
        $this->assertTrue(InstituteRepository::isRealMiurCode('XXSL000000'));
        $this->assertTrue(InstituteRepository::isRealMiurCode('XXPS00000A'));
        $this->assertTrue(InstituteRepository::isRealMiurCode('XXIS00000X'));
    }

    #[Test]
    public function synthetic_codes_are_not_real(): void
    {
        $this->assertFalse(InstituteRepository::isRealMiurCode('MIUR-ESEMPIO-COMUNE ESEMPIO-ART'));
        $this->assertFalse(InstituteRepository::isRealMiurCode('MIUR-TESTDUP-SCI'));
        $this->assertFalse(InstituteRepository::isRealMiurCode('VBSL00101'));   // troppo corto
        $this->assertFalse(InstituteRepository::isRealMiurCode('XXSL000000'));  // minuscolo
        $this->assertFalse(InstituteRepository::isRealMiurCode(''));
    }

    #[Test]
    public function dedup_key_is_stable_across_punctuation_case_accents(): void
    {
        $a = InstituteRepository::dedupKey('LICEO MUSICALE "ESEMPIO"', 'COMUNE ESEMPIO');
        $b = InstituteRepository::dedupKey('liceo  musicale  Esempio', 'Comune Esempio');
        $this->assertSame($a, $b);
        $this->assertSame('LICEOMUSICALEESEMPIO|COMUNE ESEMPIO', $a);
    }

    #[Test]
    public function dedup_key_separates_distinct_schools(): void
    {
        $musicale = InstituteRepository::dedupKey('LICEO MUSICALE "ESEMPIO"', 'COMUNE ESEMPIO');
        $sportivo = InstituteRepository::dedupKey('LICEO SC. ART. E SPORTIVO "ESEMPIO"', 'COMUNE ESEMPIO');
        $this->assertNotSame($musicale, $sportivo);
    }

    #[Test]
    public function dedup_key_handles_null_city(): void
    {
        $this->assertSame('SCUOLAX|', InstituteRepository::dedupKey('Scuola X', null));
    }
}
