<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Response::ok() / fail() — la forma canonica delle risposte JSON
 * (revisione 2026-09, P4). Il JSON emesso deve coincidere con quello dei
 * controller che gia' rispondevano `{"ok": true, ...}` / `{"ok": false, "error": ...}`,
 * chiave `ok` per prima.
 */
final class ResponseShapesTest extends TestCase
{
    #[Test]
    public function ok_puts_the_flag_first_and_keeps_data(): void
    {
        $r = Response::ok(['items' => [1, 2], 'count' => 2]);
        self::assertSame(200, $r->status);
        self::assertSame('application/json; charset=UTF-8', $r->headers['Content-Type']);
        self::assertSame('{"ok":true,"items":[1,2],"count":2}', $r->body);
        self::assertSame('{"ok":true}', Response::ok()->body);
        self::assertSame(201, Response::ok([], 201)->status);
    }

    #[Test]
    public function ok_does_not_let_data_override_the_flag(): void
    {
        self::assertSame('{"ok":true,"x":1}', Response::ok(['ok' => false, 'x' => 1])->body);
    }

    #[Test]
    public function fail_carries_error_status_and_extra_details(): void
    {
        $r = Response::fail('not_found', 404);
        self::assertSame(404, $r->status);
        self::assertSame('{"ok":false,"error":"not_found"}', $r->body);

        $r = Response::fail('invalid', 422, ['fields' => ['name']]);
        self::assertSame('{"ok":false,"error":"invalid","fields":["name"]}', $r->body);
        self::assertSame(400, Response::fail('x')->status, 'default 400');
    }
}
