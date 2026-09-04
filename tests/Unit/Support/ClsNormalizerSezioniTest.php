<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ClsNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Con le sezioni, "1B" significa sezione B — non piu' "Prima Bilinguismo".
 * shrink() collassava il suffisso b sull'anno: una sezione sarebbe sparita in
 * silenzio proprio nel percorso che serve i contenuti allo studente
 * (ContentStudyController::studyFilters).
 */
final class ClsNormalizerSezioniTest extends TestCase
{
    /** @return list<array{string,string}> */
    public static function sezioni(): array
    {
        return [
            ['1A', '1A'], ['1B', '1B'], ['1b', '1b'],
            ['2B', '2B'], ['5B', '5B'],
            ['1ALSS', '1ALSS'], ['1BLSS', '1BLSS'], ['3AR', '3AR'],
        ];
    }

    #[Test]
    #[DataProvider('sezioni')]
    public function shrink_non_mangia_la_sezione(string $in, string $atteso): void
    {
        $this->assertSame($atteso, ClsNormalizer::shrink($in));
    }

    #[Test]
    #[DataProvider('sezioni')]
    public function expand_non_tocca_le_sezioni(string $in, string $atteso): void
    {
        $this->assertSame($atteso, ClsNormalizer::expand($in));
    }

    #[Test]
    public function il_suffisso_standard_resta_gestito(): void
    {
        // "s" non e' una sezione: e' il vecchio suffisso Standard, e finche'
        // qualche URL salvato lo usa va ancora ridotto.
        $this->assertSame('2', ClsNormalizer::shrink('2s'));
        $this->assertSame('2s', ClsNormalizer::expand('2'));
        $this->assertSame('2', ClsNormalizer::shrink('2'));
    }
}
