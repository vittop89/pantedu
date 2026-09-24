<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gdpr\Export;

use App\Services\Gdpr\Export\ExportContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExportContextTest extends TestCase
{
    #[Test]
    public function self_service_scope_flags(): void
    {
        $ctx = new ExportContext(
            userId: 77,
            scope: ExportContext::SCOPE_SELF_SERVICE,
            requestorId: 77,
        );
        $this->assertTrue($ctx->isSelfService());
        $this->assertFalse($ctx->isAuthority());
        $this->assertFalse($ctx->isAdmin());
        $this->assertTrue($ctx->isOwnerRequest());
    }

    #[Test]
    public function authority_scope_flags(): void
    {
        $ctx = new ExportContext(
            userId: 140,
            scope: ExportContext::SCOPE_AUTHORITY,
            requestorId: 77,
            reason: 'decreto X',
        );
        $this->assertFalse($ctx->isSelfService());
        $this->assertTrue($ctx->isAuthority());
        $this->assertTrue($ctx->isAdmin());
        $this->assertFalse($ctx->isOwnerRequest());
        $this->assertSame('decreto X', $ctx->reason);
    }

    #[Test]
    public function admin_audit_scope_is_admin_but_not_authority(): void
    {
        $ctx = new ExportContext(
            userId: 1,
            scope: ExportContext::SCOPE_ADMIN_AUDIT,
            requestorId: 77,
        );
        $this->assertFalse($ctx->isSelfService());
        $this->assertFalse($ctx->isAuthority());
        $this->assertTrue($ctx->isAdmin());
    }

    #[Test]
    public function default_scope_is_self_service(): void
    {
        $ctx = new ExportContext(userId: 1);
        $this->assertTrue($ctx->isSelfService());
        $this->assertNull($ctx->requestorId);
        $this->assertFalse($ctx->isOwnerRequest()); // requestor null != userId
    }

    #[Test]
    public function filters_are_stored(): void
    {
        $filters = ['date_from' => '2026-01-01', 'teacher_id' => 7];
        $ctx = new ExportContext(userId: 7, filters: $filters);
        $this->assertSame($filters, $ctx->filters);
    }
}
