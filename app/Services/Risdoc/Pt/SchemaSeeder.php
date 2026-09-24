<?php

declare(strict_types=1);

namespace App\Services\Risdoc\Pt;

/**
 * SchemaSeeder — Phase 22.4b.
 *
 * Popola il campo `default` (Portable Text AST) dei field nelle schemi JSON
 * risdoc che dichiarano `tex_source: { file, section }`. Usa
 * `TexBlockExtractor` per leggere il template .tex legacy e `PtValidator`
 * per garantire che l'output sia valido prima dell'inject.
 *
 * Design testabile: logica pura in-memory + I/O separato. Facile da invocare
 * da CLI (`bin/risdoc-pt-seed.php`) e da PHPUnit con fixture stringhe.
 *
 * Schema field convention:
 * ```json
 * {
 *   "type": "nota-textarea",
 *   "name": "profilo_classe",
 *   "label": "1.2 Profilo della classe",
 *   "tex_source": {
 *     "file": "MODELLI/tex/0.0_DOC-Piano_annuale_(docente)-MODELLI.tex",
 *     "section": "OSSERVAZIONI"
 *   }
 * }
 * ```
 *
 * Dopo seed, il field riceve `default: [...PT AST]`. Il campo `tex_source`
 * viene preservato (fa da pointer per future re-seed post-edit .tex).
 */
final class SchemaSeeder
{
    public function __construct(private readonly string $texRootDir,)
    {
    }

    /**
     * Processa uno schema in-memory.
     *
     * @param array<string, mixed> $schema Decoded JSON
     * @return array{schema: array<string, mixed>, report: list<array<string, mixed>>}
     */
    public function seed(array $schema): array
    {
        $report = [];
        $this->walk($schema, '', function (array &$field, string $path) use (&$report): void {

            $entry = ['path' => $path, 'name' => $field['name'] ?? '?'];
            $src = $field['tex_source'] ?? null;
            if (!is_array($src) || empty($src['file']) || empty($src['section'])) {
                return;
        // no tex_source → skip silenzioso
            }
            $texPath = rtrim($this->texRootDir, '/') . '/' . ltrim((string)$src['file'], '/');
            if (!is_file($texPath)) {
                $entry['status'] = 'skip:file_missing';
                $entry['detail'] = $texPath;
                $report[] = $entry;
                return;
            }
            $tex = (string)file_get_contents($texPath);
            $pt = TexBlockExtractor::extract($tex, (string)$src['section']);
            if ($pt === null) {
                $entry['status'] = 'skip:section_not_found';
                $entry['detail'] = $src['section'];
                $report[] = $entry;
                return;
            }
            $valid = PtValidator::validate($pt);
            if (!$valid['valid']) {
                $entry['status'] = 'skip:invalid_pt';
                $entry['detail'] = implode('; ', $valid['errors']);
                $report[] = $entry;
                return;
            }
            $field['default'] = $pt;
            $entry['status'] = 'seeded';
            $entry['blocks'] = count($pt);
            $report[] = $entry;
        });
        return ['schema' => $schema, 'report' => $report];
    }

    /**
     * Walk ricorsivo sullo schema tree. Invoca $visitor per ogni node che
     * ha {name, tex_source}. Modifica $node per reference.
     *
     * @param array<string, mixed>|list<mixed> $tree
     */
    private function walk(array &$tree, string $path, callable $visitor): void
    {
        if (!empty($tree['name']) && isset($tree['tex_source'])) {
            $visitor($tree, $path);
        }
        foreach ($tree as $key => &$value) {
            if (is_array($value)) {
                $nextPath = $path === '' ? (string)$key : $path . '.' . $key;
                $this->walk($value, $nextPath, $visitor);
            }
        }
    }

    /**
     * Statistiche report aggregate.
     * @param list<array<string, mixed>> $report
     */
    public static function summarize(array $report): array
    {
        $stats = ['seeded' => 0, 'skipped' => 0, 'total' => count($report)];
        foreach ($report as $e) {
            if (($e['status'] ?? '') === 'seeded') {
                $stats['seeded']++;
            } elseif (str_starts_with((string)($e['status'] ?? ''), 'skip:')) {
                $stats['skipped']++;
            }
        }
        return $stats;
    }
}
