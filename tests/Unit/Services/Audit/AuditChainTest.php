<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Audit;

use App\Services\Audit\AuditChain;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** L'impronta cambia se cambia una riga, un byte binario, o l'ordine della catena. */
final class AuditChainTest extends TestCase
{
    #[Test]
    public function stessa_riga_stessa_impronta_anche_con_colonne_in_ordine_diverso(): void
    {
        $a = ['id' => 1, 'action' => 'x', 'ip_hash' => "\xff\x00\x10", 'reason' => null];
        $b = ['reason' => null, 'ip_hash' => "\xff\x00\x10", 'action' => 'x', 'id' => 1];
        self::assertSame(AuditChain::rowHash($a), AuditChain::rowHash($b));
    }

    #[Test]
    public function null_e_stringa_vuota_sono_diversi_e_un_byte_binario_conta(): void
    {
        $base = ['id' => 1, 'reason' => null, 'ip_hash' => "\xff\x00\x10"];
        self::assertNotSame(AuditChain::rowHash($base), AuditChain::rowHash(['id' => 1, 'reason' => '', 'ip_hash' => "\xff\x00\x10"]));
        self::assertNotSame(AuditChain::rowHash($base), AuditChain::rowHash(['id' => 1, 'reason' => null, 'ip_hash' => "\xff\x00\x11"]));
    }

    #[Test]
    public function la_piega_dipende_dall_ordine_e_dall_impronta_precedente(): void
    {
        $h1 = AuditChain::rowHash(['id' => 1, 'v' => 'a']);
        $h2 = AuditChain::rowHash(['id' => 2, 'v' => 'b']);
        $head = AuditChain::fold(AuditChain::GENESIS, [$h1, $h2]);
        self::assertNotSame($head, AuditChain::fold(AuditChain::GENESIS, [$h2, $h1]));
        self::assertNotSame($head, AuditChain::fold('altro', [$h1, $h2]));
        self::assertSame($head, AuditChain::fold(AuditChain::GENESIS, [$h1, $h2]));
        self::assertSame(64, strlen($head));
    }

    #[Test]
    public function segmento_vuoto_cambia_comunque_impronta_rispetto_al_genesis(): void
    {
        self::assertNotSame(AuditChain::GENESIS, AuditChain::fold(AuditChain::GENESIS, []));
    }
}
