<?php

declare(strict_types=1);

namespace Tests\Unit\PdfImport;

use App\Services\PdfImport\Provider\SsrfGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SsrfGuardTest extends TestCase
{
    #[Test]
    public function allows_loopback_in_allowlist(): void
    {
        $url = SsrfGuard::assertOllamaBaseUrl('http://127.0.0.1:11434/', ['127.0.0.1', 'localhost']);
        $this->assertSame('http://127.0.0.1:11434', $url);
    }

    #[Test]
    public function rejects_host_not_in_allowlist(): void
    {
        $this->expectExceptionMessage('ollama_host_not_allowed');
        SsrfGuard::assertOllamaBaseUrl('http://169.254.169.254', ['127.0.0.1']);
    }

    #[Test]
    public function rejects_non_http_scheme(): void
    {
        $this->expectExceptionMessage('ollama_base_url_invalid');
        SsrfGuard::assertOllamaBaseUrl('file:///etc/passwd', ['127.0.0.1']);
    }

    #[Test]
    public function rejects_userinfo_in_url(): void
    {
        $this->expectExceptionMessage('ollama_base_url_invalid');
        SsrfGuard::assertOllamaBaseUrl('http://user:pass@127.0.0.1', ['127.0.0.1']);
    }

    #[Test]
    public function strips_path_and_query(): void
    {
        $url = SsrfGuard::assertOllamaBaseUrl('http://localhost:11434/api/chat?x=1', ['localhost']);
        $this->assertSame('http://localhost:11434', $url);
    }
}
