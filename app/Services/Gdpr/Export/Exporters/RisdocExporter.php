<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export\Exporters;

use App\Core\Database;
use App\Services\Gdpr\Export\ContentExporterInterface;
use App\Services\Gdpr\Export\ExportContext;
use App\Services\Gdpr\Export\ExportSection;
use PDO;

/**
 * Phase 25.R.23 — Exporter RisDoc/BES (Art. 15 GDPR).
 *
 * Esporta `risdoc_teacher_overrides` — modelli istituzionali (BES/DSA)
 * forkati + override docente in formato HTML/TeX/CSS/JSON/image.
 *
 * Body può essere cifrato (envelope) se CRYPTO_DUAL_WRITE è abilitato;
 * altrimenti plaintext nel DB. Esportiamo plaintext via repository.
 */
final class RisdocExporter implements ContentExporterInterface
{
    public function getKey(): string
    {
        return 'risdoc';
    }
    public function getLabel(): string
    {
        return 'RisDoc / BES (modelli istituzionali)';
    }
    public function getCategory(): string
    {
        return 'content';
    }
    public function isAvailableForSelfService(): bool
    {
        return true;
    }
    public function isAvailableForAuthority(): bool
    {
        return true;
    }

    public function export(ExportContext $ctx): ExportSection
    {
        $section = new ExportSection('risdoc', 'content/risdoc', $this->getLabel());

        try {
            $stmt = Database::connection()->prepare(
                'SELECT id, template_id, instance_key, instance_label, kind,
                        relative_path, body, source_version, updated_at
                 FROM risdoc_teacher_overrides
                 WHERE teacher_id = ?
                 ORDER BY template_id, instance_key, relative_path'
            );
            $stmt->execute([$ctx->userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $section->summary['_error'] = 'query_failed: ' . $e->getMessage();
            return $section;
        }

        if (empty($rows)) {
            $section->setSummary(['count' => 0]);
            return $section;
        }

        // Metadata + corpo per istanza (un file per relative_path)
        $byInstance = [];
        foreach ($rows as $r) {
            $tmplKey = (string)$r['template_id'] . '_' . (string)($r['instance_key'] ?? 'default');
            $byInstance[$tmplKey][] = $r;
        }

        $stats = ['instances' => 0, 'files' => 0];
        foreach ($byInstance as $instKey => $instRows) {
            $stats['instances']++;
            // metadata istanza
            $meta = [
                'template_id'    => $instRows[0]['template_id'],
                'instance_key'   => $instRows[0]['instance_key'],
                'instance_label' => $instRows[0]['instance_label'],
                'source_version' => $instRows[0]['source_version'],
                'files'          => array_map(static fn(array $r) => [
                    'kind'          => $r['kind'],
                    'relative_path' => $r['relative_path'],
                    'updated_at'    => $r['updated_at'],
                    'body_size'     => strlen((string)$r['body']),
                ], $instRows),
            ];
            $section->addJsonFile("{$instKey}/_meta.json", $meta);

            // file singoli (HTML, TeX, CSS, JSON, image binari)
            foreach ($instRows as $r) {
                $rel  = (string)$r['relative_path'];
                $body = (string)($r['body'] ?? '');
                $mime = match ((string)$r['kind']) {
                    'html'  => 'text/html',
                    'tex'   => 'application/x-tex',
                    'css'   => 'text/css',
                    'json'  => 'application/json',
                    'image' => 'image/png',
                    default => 'application/octet-stream',
                };
                $section->addFile("{$instKey}/{$rel}", $body, $mime);
                $stats['files']++;
            }
        }

        $section->setSummary($stats);
        return $section;
    }
}
