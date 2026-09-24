<?php

declare(strict_types=1);

namespace Tests\Fixtures\PhpunitSessione;

use PHPUnit\Framework\TestCase;

/** Fixture di RichiestaPulitaFraLeProveTest: gira dopo SporcaLaSessione e vuole la sessione vuota. */
final class TrovaLaSessioneVuota extends TestCase
{
    public function test_non_eredita_la_sessione(): void
    {
        $this->assertSame([], $_SESSION ?? [], 'la prova di prima ha lasciato la sua sessione');
        $this->assertArrayNotHasKey('HTTP_X_PARTIAL', $_SERVER, 'la prova di prima ha lasciato la sua richiesta parziale');
        $this->assertArrayNotHasKey('embed', $_GET, 'la prova di prima ha lasciato il suo embed');
    }
}
