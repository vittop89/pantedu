<?php
declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\TeacherCredentialRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** La scadenza per default delle credenziali di classe: il 31 agosto dell'anno scolastico in corso. */
final class TeacherCredentialExpiryTest extends TestCase
{
    /** @return iterable<string, array{0:string,1:string}> */
    public static function giorni(): iterable
    {
        yield 'inizio anno solare'    => ['2026-01-10', '2026-08-31'];
        yield 'ultimo giorno utile'   => ['2026-08-31', '2026-08-31'];
        yield 'primo settembre'       => ['2026-09-01', '2027-08-31'];
        yield 'dicembre'              => ['2026-12-24', '2027-08-31'];
    }

    #[Test]
    #[DataProvider('giorni')]
    public function scade_a_fine_anno_scolastico(string $oggi, string $atteso): void
    {
        self::assertSame($atteso, TeacherCredentialRepository::defaultExpiry(new \DateTimeImmutable($oggi)));
    }
}
