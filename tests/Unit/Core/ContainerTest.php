<?php

namespace Tests\Unit\Core;

use App\Core\Container;
use App\Core\Contracts\DatabaseInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::reset();
    }

    #[Test]
    public function resolves_default_binding_to_gateway(): void
    {
        Container::reset();
        // Senza toccare il Database reale, il binding di default mappa
        // DatabaseInterface → DatabaseGateway. Istanziazione non richiede connessione DB.
        $db = Container::get(DatabaseInterface::class);
        $this->assertInstanceOf(\App\Core\Gateway\DatabaseGateway::class, $db);
    }

    #[Test]
    public function set_allows_test_override(): void
    {
        $fake = new class implements DatabaseInterface {
            public function connection(): \PDO { throw new \LogicException('not used'); }
        };
        Container::set(DatabaseInterface::class, $fake);
        $this->assertSame($fake, Container::get(DatabaseInterface::class));
    }

    #[Test]
    public function reuses_same_instance_singleton(): void
    {
        Container::reset();
        $a = Container::get(DatabaseInterface::class);
        $b = Container::get(DatabaseInterface::class);
        $this->assertSame($a, $b);
    }

    #[Test]
    public function set_re_instantiates(): void
    {
        $a = Container::get(DatabaseInterface::class);
        $fake = new class implements DatabaseInterface {
            public function connection(): \PDO { throw new \LogicException('not used'); }
        };
        Container::set(DatabaseInterface::class, $fake);
        $b = Container::get(DatabaseInterface::class);
        $this->assertNotSame($a, $b);
        $this->assertSame($fake, $b);
    }

    #[Test]
    public function throws_for_unknown_interface(): void
    {
        $this->expectException(\RuntimeException::class);
        Container::get('NonExistent\\Interface');
    }

    #[Test]
    public function callable_binding_invoked_lazily(): void
    {
        $called = 0;
        Container::set(DatabaseInterface::class, function () use (&$called) {
            $called++;
            return new class implements DatabaseInterface {
                public function connection(): \PDO { throw new \LogicException('not used'); }
            };
        });
        $this->assertSame(0, $called);  // non chiamato al set
        Container::get(DatabaseInterface::class);
        $this->assertSame(1, $called);  // chiamato solo al get
        Container::get(DatabaseInterface::class);
        $this->assertSame(1, $called);  // cached
    }
}
