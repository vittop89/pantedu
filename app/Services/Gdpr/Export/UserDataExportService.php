<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export;

use Throwable;

/**
 * Phase 25.R.23 — Orchestratore + registry export dati utente.
 *
 * Source-of-truth UNICA per Art. 15/20/6(1)(c) GDPR. Consumato da:
 *   - SelfServiceController::exportData()           Art. 15/20 self-service
 *   - AdminGdprController::authorityExportSubmit()  Art. 6(1)(c) autorità
 *   - Future: AdminBackupController DPA snapshot, etc.
 *
 * Pattern: Strategy + Registry pluggable. Aggiungere nuovo tipo di documento
 * = creare nuovo Exporter + register() — zero modifiche a questo file.
 *
 * Vedi ContentExporterInterface per dettagli.
 *
 * Esempio:
 *
 *   $svc = UserDataExportService::default();
 *   $sections = $svc->buildExport(new ExportContext(
 *       userId: 140,
 *       scope: ExportContext::SCOPE_AUTHORITY,
 *       requestorId: 77,
 *       reason: 'Decreto Tribunale Milano 1234/2026',
 *   ));
 *   // → array<string,ExportSection> con tutte le sezioni autorizzate
 */
final class UserDataExportService
{
    /** @var array<string, ContentExporterInterface> */
    private array $exporters = [];

    public function register(ContentExporterInterface $exporter): void
    {
        $this->exporters[$exporter->getKey()] = $exporter;
    }

    /** @return array<string,ContentExporterInterface> */
    public function listAvailable(string $scope): array
    {
        return array_filter($this->exporters, static function (ContentExporterInterface $e) use ($scope) {
            return match ($scope) {
                ExportContext::SCOPE_SELF_SERVICE              => $e->isAvailableForSelfService(),
                ExportContext::SCOPE_AUTHORITY, ExportContext::SCOPE_ADMIN_AUDIT => $e->isAvailableForAuthority(),
                default                                        => false,
            };
        });
    }

    /**
     * Genera l'export.
     *
     * @param ExportContext $ctx contesto richiesta
     * @param list<string>  $scope keys exporters da includere (vuoto = tutti disponibili per il context)
     * @return array<string, ExportSection>
     */
    public function buildExport(ExportContext $ctx, array $scope = []): array
    {
        $sections = [];
        foreach ($this->exporters as $key => $exporter) {
            // Filtro scope esplicito
            if (!empty($scope) && !in_array($key, $scope, true)) {
                continue;
            }
            // Filtro disponibilità basata sul context
            if ($ctx->isAdmin() && !$exporter->isAvailableForAuthority()) {
                continue;
            }
            if ($ctx->isSelfService() && !$exporter->isAvailableForSelfService()) {
                continue;
            }
            try {
                $sections[$key] = $exporter->export($ctx);
            } catch (Throwable $e) {
                // Errori isolati: section vuota con _error nel summary, no abort export
                $err = new ExportSection($key, '', $exporter->getLabel());
                $err->summary['_error'] = $e->getMessage();
                $sections[$key] = $err;
            }
        }
        return $sections;
    }

    /**
     * Aggregate summary di tutte le sezioni — utile per manifest.json.
     *
     * @param array<string,ExportSection> $sections
     * @return array<string,array{label:string, files_count:int, total_size:int, summary:array, sha256:list<array{path:string,sha256:string}>}>
     */
    public function aggregateSummary(array $sections): array
    {
        $out = [];
        foreach ($sections as $key => $section) {
            $shas = [];
            foreach ($section->files as $f) {
                $shas[] = ['path' => $f->relativePath, 'sha256' => $f->sha256, 'size' => $f->size()];
            }
            $out[$key] = [
                'label'        => $section->label,
                'files_count'  => $section->fileCount(),
                'total_size'   => $section->totalSize(),
                'summary'      => $section->summary,
                'files_sha256' => $shas,
            ];
        }
        return $out;
    }

    /**
     * Factory di default con exporters standard registrati.
     * Per aggiungere nuovi exporters in futuro: register() qui o via DI.
     */
    public static function default(): self
    {
        $svc = new self();
        $svc->register(new Exporters\ProfileExporter());
        $svc->register(new Exporters\ConsentsExporter());
        $svc->register(new Exporters\TeacherContentExporter());
        $svc->register(new Exporters\VerificheExporter());
        $svc->register(new Exporters\TemplatesExporter());
        $svc->register(new Exporters\RisdocExporter());
        $svc->register(new Exporters\CurriculumExporter());
        $svc->register(new Exporters\SharesExporter());
        $svc->register(new Exporters\PublishedContentExporter());
        $svc->register(new Exporters\ClasseKeysExporter());
        $svc->register(new Exporters\AuditLogExporter());
        return $svc;
    }
}
