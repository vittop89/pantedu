<?php

declare(strict_types=1);

namespace Tests\Fixtures\PhpunitSessione;

use PHPUnit\Framework\TestCase;

/** Fixture di RichiestaPulitaFraLeProveTest: lascia un super-amministratore in sessione e una richiesta parziale. */
final class SporcaLaSessione extends TestCase
{
    public function test_lascia_un_super_amministratore(): void
    {
        $_SESSION = ['autenticato' => true, 'username' => 'fixture', 'is_super_admin' => true];
        $_SERVER['HTTP_X_PARTIAL'] = '1';
        $_GET['embed'] = '1';
        $this->assertNotEmpty($_SESSION);
    }
}
