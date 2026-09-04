<?php

declare(strict_types=1);

namespace Tests\Unit\Maps;

use App\Repositories\MapShareRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Phase G2 — Unit tests MapShareRepository validation.
 *
 * NB: i metodi che toccano DB (grant/revoke/listForContent/bestPermissionFor)
 * sono coperti da test integrazione (richiedono DB + teacher_content +
 * users + map_shares schema). Qui validiamo solo il guard di
 * scope_type / permission perche' raggiungibile pre-DB.
 */
final class MapShareRepositoryTest extends TestCase
{
    public function testScopeTypesConstantContainsExpectedValues(): void
    {
        $this->assertSame(
            ['institute', 'class', 'student', 'teacher'],
            MapShareRepository::SCOPE_TYPES
        );
    }

    public function testPermissionsConstantContainsExpectedValues(): void
    {
        $this->assertSame(['view', 'copy'], MapShareRepository::PERMISSIONS);
    }

    public function testGrantThrowsOnInvalidScopeType(): void
    {
        $repo = new MapShareRepository();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/scope_type/');
        $repo->grant(1, 'galaxy', '42', 'view', 99);
    }

    public function testGrantThrowsOnInvalidPermission(): void
    {
        $repo = new MapShareRepository();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/permission/');
        $repo->grant(1, 'teacher', '42', 'admin', 99);
    }

    public function testGrantThrowsOnEmptyPermission(): void
    {
        $repo = new MapShareRepository();
        $this->expectException(InvalidArgumentException::class);
        $repo->grant(1, 'teacher', '42', '', 99);
    }
}
