<?php

declare(strict_types=1);

namespace App\Services\Risdoc\Pt;

/**
 * TexSourceAutoDetector — Phase 22.6.
 *
 * Auto-detection del mapping `{schema_field → tex_file + sectionbox}` per
 * annotare `tex_source` nei schemi JSON risdoc (poi popolati da SchemaSeeder).
 *
 * Euristica:
 *   1. TeX file resolution: scan `storage/templates/risdoc/{category}/tex/`
 *      per file il cui basename normalizzato contiene il $id o il title
 *      dello schema.
 *   2. Scan del .tex per blocchi `\subsection{TITLE} ... \begin{sectionbox}{LABEL}
 *      ... %[BeginTesto] ... %[EndTesto] ... \end{sectionbox}`.
 *   3. Per ogni field `type: nota-textarea` senza `tex_source` già presente,
 *      fuzzy-match `field.label` (strippato di numero/punctuation) con
 *      `subsection.title` degli hit. Match trovato → annotate con
 *      `{file, section}`.
 *
 * Output in-memory: lo schema aggiornato. La scrittura disco è
 * responsabilità del caller (CLI).
 */
final class TexSourceAutoDetector
{
    public function __construct(private readonly string $texRootDir,)
    {
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{schema: array<string, mixed>, report: list<array<string, mixed>>}
     */
    public function annotate(array $schema): array
    {
        $report = [];
        $texRel = $this->resolveTexFile($schema);
        if ($texRel === null) {
            return [
                'schema' => $schema,
                'report' => [['status' => 'no_tex_file', 'detail' => 'nessun .tex corrispondente']],
            ];
        }
        $texPath = $this->texRootDir . '/' . $texRel;
        $texContent = (string)@file_get_contents($texPath);
        if ($texContent === '') {
            return [
                'schema' => $schema,
                'report' => [['status' => 'tex_unreadable', 'file' => $texRel]],
            ];
        }

        $sections = $this->extractSectionsWithBeginTesto($texContent);
        if (count($sections) === 0) {
            return [
                'schema' => $schema,
                'report' => [['status' => 'no_blocks_in_tex', 'file' => $texRel]],
            ];
        }

        // Walk ricorsivo sullo schema: per ogni nota-textarea senza tex_source,
        // prova fuzzy match su sections.
        $this->walk($schema, '', function (array &$field, string $path) use ($texRel, $sections, &$report): void {

            if (($field['type'] ?? '') !== 'nota-textarea') {
                return;
            }
            if (isset($field['tex_source'])) {
                return;
            }
            $label = (string)($field['label'] ?? '');
            if ($label === '') {
                return;
            }

            $matched = $this->fuzzyMatch($label, $sections);
            $entry = ['path' => $path, 'name' => $field['name'] ?? '?', 'label' => $label];
            if ($matched === null) {
                $entry['status'] = 'no_match';
                $report[] = $entry;
                return;
            }
            $field['tex_source'] = [
                'file' => $texRel,
                'section' => $matched['sectionbox'],
            ];
            $entry['status'] = 'annotated';
            $entry['section'] = $matched['sectionbox'];
            $entry['subsection'] = $matched['subsection'];
            $report[] = $entry;
        });
        return ['schema' => $schema, 'report' => $report];
    }

    /** Trova il .tex più simile allo schema per convention di naming. */
    private function resolveTexFile(array $schema): ?string
    {
        $category = (string)($schema['category'] ?? 'MODELLI');
        $dir = $this->texRootDir . '/' . $category . '/tex';
        if (!is_dir($dir)) {
            return null;
        }

        $needles = [
            self::normalize((string)($schema['$id']  ?? '')),
            self::normalize((string)($schema['title'] ?? '')),
        ];
        $needles = array_filter($needles, fn($n) => $n !== '');
        foreach (scandir($dir) ?: [] as $f) {
            if (!str_ends_with($f, '.tex')) {
                continue;
            }
            $fname = self::normalize(pathinfo($f, PATHINFO_FILENAME));
            foreach ($needles as $n) {
                if (str_contains($fname, $n) || str_contains($n, $fname)) {
                    return $category . '/tex/' . $f;
                }
            }
        }
        return null;
    }

    /**
     * Estrae tutti i `\begin{sectionbox}{LABEL}...\end{sectionbox}` che
     * contengono `%[BeginTesto]...%[EndTesto]`. Per ognuno, allega la
     * subsection precedente (se esiste) — che potrebbe dare un match
     * migliore rispetto al sectionbox label generico come "OSSERVAZIONI".
     *
     * @return list<array{subsection: string, sectionbox: string}>
     */
    private function extractSectionsWithBeginTesto(string $tex): array
    {
        $results = [];
        $pattern = '/\\\\begin\{sectionbox\}\{([^}]+)\}([\s\S]*?)\\\\end\{sectionbox\}/';
        if (!\preg_match_all($pattern, $tex, $matches, PREG_OFFSET_CAPTURE)) {
            return $results;
        }

        foreach ($matches[0] as $i => [$_full, $offset]) {
            $body = $matches[2][$i][0];
// Filtra: solo sectionbox con BeginTesto blocco (escludi BeginTestoX, BeginTextArea)
            if (!\str_contains($body, '%[BeginTesto]')) {
                continue;
            }
            if (!\str_contains($body, '%[EndTesto]')) {
                continue;
            }

            $label = \trim($matches[1][$i][0]);
// Trova l'ultima \subsection{} prima di questo sectionbox
            $preText = \substr($tex, 0, $offset);
            $subsection = '';
            if (\preg_match_all('/\\\\subsection\{([^}]+)\}/', $preText, $subMatches)) {
                $subsection = \trim(\end($subMatches[1]));
            }

            $results[] = [
                'subsection' => $subsection,
                'sectionbox' => $label,
            ];
        }
        return $results;
    }

    /**
     * @param list<array{subsection: string, sectionbox: string}> $sections
     * @return array{subsection: string, sectionbox: string}|null
     */
    private function fuzzyMatch(string $label, array $sections): ?array
    {
        $n = self::normalize($label);
        if ($n === '') {
            return null;
        }
        // Matching prioritario:
        //   1. subsection title (più specifico)
        //   2. sectionbox label (fallback quando no subsection o generico)
        //
        // Richiede match sufficientemente lungo (≥4 char normalizzati) per
        // evitare false positive tipo "ok" che matcha tutto.
        if (\strlen($n) < 4) {
            return null;
        }

        foreach ($sections as $s) {
            $sub = self::normalize($s['subsection']);
            if (
                $sub !== '' && \strlen($sub) >= 4
                && ($sub === $n || \str_contains($sub, $n) || \str_contains($n, $sub))
            ) {
                return $s;
            }
        }
        foreach ($sections as $s) {
            $box = self::normalize($s['sectionbox']);
            if (
                $box !== '' && \strlen($box) >= 4
                && ($box === $n || \str_contains($box, $n) || \str_contains($n, $box))
            ) {
                return $s;
            }
        }
        return null;
    }

    /**
     * Normalizza stringa per fuzzy match:
     *   - Lowercase
     *   - Rimuove numero/versione iniziale (es. "1.2 ", "0.0_")
     *   - Rimuove suffix categoria (-MODELLI, -RISORSE)
     *   - Rimuove tutti i caratteri non-alfanumerici
     */
    public static function normalize(string $s): string
    {
        $s = \strtolower($s);
        $s = (string)\preg_replace('/^[\d.]+\s*[-_]*\s*/', '', $s);
        $s = (string)\preg_replace('/-(modelli|risorse)$/i', '', $s);
        return (string)\preg_replace('/[^a-z0-9]/i', '', $s);
    }

    /**
     * @param array<string, mixed>|list<mixed> $tree
     */
    private function walk(array &$tree, string $path, callable $visitor): void
    {
        if (!empty($tree['name']) && !empty($tree['type'])) {
            $visitor($tree, $path);
        }
        foreach ($tree as $key => &$value) {
            if (\is_array($value)) {
                $nextPath = $path === '' ? (string)$key : $path . '.' . $key;
                $this->walk($value, $nextPath, $visitor);
            }
        }
    }
}
