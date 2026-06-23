<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gdpr\Export;

use App\Services\Gdpr\Export\ContentExporterInterface;
use App\Services\Gdpr\Export\ExportContext;
use App\Services\Gdpr\Export\ExportSection;
use App\Services\Gdpr\Export\UserDataExportService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Fake exporter usato dai test (no DB dependency). */
final class FakeExporter implements ContentExporterInterface
{
    public function __construct(
        private readonly string $key,
        private readonly bool $availableSelf = true,
        private readonly bool $availableAuth = true,
        private readonly bool $throws = false,
    ) {
    }

    public function getKey(): string { return $this->key; }
    public function getLabel(): string { return "Fake {$this->key}"; }
    public function getCategory(): string { return 'test'; }
    public function isAvailableForSelfService(): bool { return $this->availableSelf; }
    public function isAvailableForAuthority(): bool { return $this->availableAuth; }

    public function export(ExportContext $ctx): ExportSection
    {
        if ($this->throws) {
            throw new RuntimeException("simulated_failure_{$this->key}");
        }
        $s = new ExportSection($this->key, $this->key, "Fake {$this->key}");
        $s->addFile('data.txt', "content for {$this->key}");
        $s->setSummary(['ok' => true, 'count' => 1]);
        return $s;
    }
}

final class UserDataExportServiceTest extends TestCase
{
    #[Test]
    public function register_and_list_default(): void
    {
        $svc = new UserDataExportService();
        $svc->register(new FakeExporter('alpha'));
        $svc->register(new FakeExporter('beta'));

        $available = $svc->listAvailable(ExportContext::SCOPE_SELF_SERVICE);
        $this->assertArrayHasKey('alpha', $available);
        $this->assertArrayHasKey('beta', $available);
    }

    #[Test]
    public function listAvailable_filters_by_scope(): void
    {
        $svc = new UserDataExportService();
        $svc->register(new FakeExporter('self_only', availableSelf: true,  availableAuth: false));
        $svc->register(new FakeExporter('auth_only', availableSelf: false, availableAuth: true));
        $svc->register(new FakeExporter('both',      availableSelf: true,  availableAuth: true));

        $self = $svc->listAvailable(ExportContext::SCOPE_SELF_SERVICE);
        $this->assertArrayHasKey('self_only', $self);
        $this->assertArrayNotHasKey('auth_only', $self);
        $this->assertArrayHasKey('both', $self);

        $auth = $svc->listAvailable(ExportContext::SCOPE_AUTHORITY);
        $this->assertArrayNotHasKey('self_only', $auth);
        $this->assertArrayHasKey('auth_only', $auth);
        $this->assertArrayHasKey('both', $auth);
    }

    #[Test]
    public function buildExport_self_service_skips_authority_only(): void
    {
        $svc = new UserDataExportService();
        $svc->register(new FakeExporter('public',     availableSelf: true,  availableAuth: true));
        $svc->register(new FakeExporter('admin_only', availableSelf: false, availableAuth: true));

        $ctx = new ExportContext(userId: 7, scope: ExportContext::SCOPE_SELF_SERVICE);
        $sections = $svc->buildExport($ctx);

        $this->assertArrayHasKey('public', $sections);
        $this->assertArrayNotHasKey('admin_only', $sections);
    }

    #[Test]
    public function buildExport_authority_includes_authority_only(): void
    {
        $svc = new UserDataExportService();
        $svc->register(new FakeExporter('public',     availableSelf: true,  availableAuth: true));
        $svc->register(new FakeExporter('admin_only', availableSelf: false, availableAuth: true));

        $ctx = new ExportContext(userId: 7, scope: ExportContext::SCOPE_AUTHORITY);
        $sections = $svc->buildExport($ctx);

        $this->assertArrayHasKey('public', $sections);
        $this->assertArrayHasKey('admin_only', $sections);
    }

    #[Test]
    public function buildExport_with_explicit_scope_filters(): void
    {
        $svc = new UserDataExportService();
        $svc->register(new FakeExporter('one'));
        $svc->register(new FakeExporter('two'));
        $svc->register(new FakeExporter('three'));

        $ctx = new ExportContext(userId: 1);
        $sections = $svc->buildExport($ctx, ['one', 'three']);

        $this->assertArrayHasKey('one', $sections);
        $this->assertArrayNotHasKey('two', $sections);
        $this->assertArrayHasKey('three', $sections);
    }

    #[Test]
    public function buildExport_catches_exceptions_per_exporter(): void
    {
        $svc = new UserDataExportService();
        $svc->register(new FakeExporter('ok'));
        $svc->register(new FakeExporter('broken', throws: true));
        $svc->register(new FakeExporter('also_ok'));

        $ctx = new ExportContext(userId: 1);
        $sections = $svc->buildExport($ctx);

        // Tutti i 3 presenti — un exporter rotto NON deve interrompere gli altri
        $this->assertArrayHasKey('ok', $sections);
        $this->assertArrayHasKey('broken', $sections);
        $this->assertArrayHasKey('also_ok', $sections);

        // broken ha _error popolato
        $this->assertArrayHasKey('_error', $sections['broken']->summary);
        $this->assertStringContainsString('simulated_failure_broken', $sections['broken']->summary['_error']);

        // ok ha contenuto normale
        $this->assertCount(1, $sections['ok']->files);
        $this->assertSame(['ok' => true, 'count' => 1], $sections['ok']->summary);
    }

    #[Test]
    public function aggregateSummary_returns_per_section_metadata(): void
    {
        $svc = new UserDataExportService();
        $svc->register(new FakeExporter('alpha'));

        $ctx = new ExportContext(userId: 1);
        $sections = $svc->buildExport($ctx);
        $summary = $svc->aggregateSummary($sections);

        $this->assertArrayHasKey('alpha', $summary);
        $this->assertSame('Fake alpha', $summary['alpha']['label']);
        $this->assertSame(1, $summary['alpha']['files_count']);
        $this->assertGreaterThan(0, $summary['alpha']['total_size']);
        $this->assertCount(1, $summary['alpha']['files_sha256']);
        $this->assertArrayHasKey('sha256', $summary['alpha']['files_sha256'][0]);
        $this->assertSame('alpha/data.txt', $summary['alpha']['files_sha256'][0]['path']);
    }

    #[Test]
    public function default_factory_registers_standard_exporters(): void
    {
        $svc = UserDataExportService::default();
        $available = $svc->listAvailable(ExportContext::SCOPE_AUTHORITY);

        // Spot-check: alcuni exporters obbligatori
        $this->assertArrayHasKey('profile', $available);
        $this->assertArrayHasKey('consents', $available);
        $this->assertArrayHasKey('teacher_content', $available);
        $this->assertArrayHasKey('audit_log', $available);
    }

    #[Test]
    public function default_factory_audit_log_only_for_authority(): void
    {
        $svc = UserDataExportService::default();

        $selfAvailable = $svc->listAvailable(ExportContext::SCOPE_SELF_SERVICE);
        $authAvailable = $svc->listAvailable(ExportContext::SCOPE_AUTHORITY);

        $this->assertArrayNotHasKey('audit_log', $selfAvailable, 'audit_log must NOT be in self-service');
        $this->assertArrayHasKey('audit_log', $authAvailable, 'audit_log must be in authority');
    }
}
