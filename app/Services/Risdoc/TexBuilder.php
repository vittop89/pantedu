<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

/**
 * TexBuilder — Plan A phase 3 (modernization).
 *
 * Genera TeX output da uno schema JSON + una compilation data_json.
 *
 * Strategia:
 *   1. Se schema.tex.wrapper e' presente → carica quel file .tex, sostituisce
 *      i placeholder `{{field_name}}` con i valori della compilation.
 *   2. Se schema.tex.placeholders e' mappato → applica i mapping custom
 *      (chiave=nome-campo, valore=stringa LaTeX template con {{value}}).
 *   3. Fallback auto: wrapper minimo con elenco sezioni/valori per debug/
 *      PoC prima che ogni schema abbia un wrapper .tex dedicato.
 *
 * Uso:
 *   $tex = (new TexBuilder(ROOT.'/schemas/risdoc/motivazione-voti.json'))
 *            ->build(['fields' => [...], 'state' => [...]]);
 */
final class TexBuilder
{
    private array $schema;
    private string $rootDir;
    private array $_lastState = [];
// Phase 24.29: state corrente per PtToTex context

    public function __construct(string $schemaPath, ?string $rootDir = null)
    {
        if (!is_file($schemaPath)) {
            throw new \InvalidArgumentException("Schema not found: {$schemaPath}");
        }
        $decoded = json_decode((string)file_get_contents($schemaPath), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid JSON: {$schemaPath}");
        }
        $this->schema = $decoded;
        $this->rootDir = $rootDir ?? dirname(__DIR__, 3);
    }

    /**
     * @param array{fields?:array,state?:array} $compilation
     */
    public function build(array $compilation): string
    {
        $wrapperPath = (string)($this->schema['tex']['wrapper'] ?? '');
        $placeholders = (array)($this->schema['tex']['placeholders'] ?? []);
        if ($wrapperPath !== '') {
            $abs = $this->rootDir . '/' . ltrim($wrapperPath, '/');
            if (is_file($abs)) {
                return $this->applyReplace((string)file_get_contents($abs), $compilation, $placeholders);
            }
        }
        return $this->buildFallbackTex($compilation);
    }

    /**
     * Sostituisce `{{field_name}}` con i valori di compilation.fields,
     * `{{state.indirizzo}}` con i valori di compilation.state, e applica
     * le trasformazioni custom da $placeholders.
     */
    private function applyReplace(string $wrapper, array $compilation, array $placeholders): string
    {
        $fields = (array)($compilation['fields'] ?? []);
        $state  = (array)($compilation['state']  ?? []);
// Custom placeholders dallo schema (LaTeX template con {{value}}).
        foreach ($placeholders as $key => $tpl) {
            if (!is_string($tpl)) {
                continue;
            }
            $v = $fields[$key] ?? $state[$key] ?? '';
            $vTex = $this->valueToTex($v);
            $rendered = str_replace(['{{value}}', '{{' . $key . '}}'], [$vTex, $vTex], $tpl);
            $wrapper = str_replace('{{' . $key . '}}', $rendered, $wrapper);
        }

        // Generic {{field}} → fields[field]
        $wrapper = preg_replace_callback('/\{\{state\.([a-zA-Z0-9_]+)\}\}/', fn($m) => $this->valueToTex($state[$m[1]] ?? ''), $wrapper) ?? $wrapper;
        $wrapper = preg_replace_callback('/\{\{([a-zA-Z0-9_]+)\}\}/', fn($m) => $this->valueToTex($fields[$m[1]] ?? ''), $wrapper) ?? $wrapper;
        return $wrapper;
    }

    /**
     * Phase 22.5 — Converte un field value in TeX:
     *   - Se value è un array con shape Portable Text AST (primo elemento
     *     ha `_type`), renderizza via PtToTex (escape LaTeX interno)
     *   - Altrimenti: string + esc standard
     *
     * Pattern detection: un PT AST è `list<array>` dove ogni top-level
     * node ha `_type` tra {block, checkboxGroup, rawTex}. Check lazy
     * (no validator full) per evitare overhead su string semplici.
     */
    private function valueToTex(mixed $v): string
    {
        if (self::looksLikePortableText($v)) {
            return \App\Services\Risdoc\Pt\PtToTex::render($v);
        }
        if (\is_array($v)) {
// Non-PT array (es. checkbox-group values): join-comma stringify
            $flat = \array_map(static fn($x) => \is_scalar($x) ? (string)$x : \json_encode($x), $v);
            return $this->esc(\implode(', ', $flat));
        }
        return $this->esc((string)$v);
    }

    private static function looksLikePortableText(mixed $v): bool
    {
        if (!\is_array($v) || $v === []) {
            return false;
        }
        // List check (keys 0..N)
        if (!\array_is_list($v)) {
            return false;
        }
        $first = $v[0] ?? null;
        if (!\is_array($first)) {
            return false;
        }
        $type = $first['_type'] ?? null;
// Phase 24.24 — include TUTTI i block types PT (aggiunti in 24.1-5).
        // Precedente lista ['block','checkboxGroup','rawTex'] escludeva pt
        // che iniziano con sectionHeader/table/select/textField/formCheckbox,
        // facendo perdere il rendering via PtToTex (e quindi renderMode,
        // widget cells, ecc.).
        return \in_array($type, [
            'block', 'checkboxGroup', 'rawTex',
            'table', 'select', 'textField', 'formCheckbox', 'sectionHeader',
        ], true);
    }

    /**
     * Phase 24.26 — Schema-driven TeX builder modernizzato.
     *
     * Genera BODY-only TeX iterando le schema.sections (no wrapper legacy).
     * Per ogni section determina il rendering appropriato:
     *   - header           → \begin{center}\huge{title}\end{center} + state info
     *   - pt_unified       → PtToTex::render(fields[section_name]) se presente,
     *                        altrimenti skeleton da schema.items
     *   - nota-textarea    → PtToTex per PT AST, else plain text
     *   - checkbox-group   → lista items
     *   - dynamic-table    → tabular basato su columns
     *   - text-section     → wrapper con ricorsione sui children
     *
     * main.tex fornisce preamble + \begin/\end{document}.
     */
    private function buildFallbackTex(array $compilation): string
    {
        $fields = (array)($compilation['fields'] ?? []);
        $state  = (array)($compilation['state']  ?? []);
        $this->_lastState = $state;
// Phase 24.29: per renderNotaTextarea fieldRef

        $out = [];
        $out[] = '% ─ Schema-driven TeX body (' . $this->esc((string)($this->schema['$id'] ?? '?')) . ') ─';
        $sections = (array)($this->schema['sections'] ?? []);
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $rendered = $this->renderSchemaSection($section, $fields, $state);
            if ($rendered !== '') {
                $out[] = $rendered;
                $out[] = '';
            }
        }
        return implode("\n", $out);
    }

    /**
     * Render una singola section schema → TeX string body.
     */
    private function renderSchemaSection(array $section, array $fields, array $state): string
    {
        $type  = (string)($section['type']  ?? '');
        $title = (string)($section['title'] ?? '');
        $name  = (string)($section['name']  ?? $this->autoSectionName($section));
// Section completamente PT-unified: usa il saved PT se disponibile,
        // altrimenti skeleton generato dagli items schema.
        if (!empty($section['pt_unified'])) {
            $saved = $fields[$name] ?? null;
            if (\is_array($saved) && self::looksLikePortableText($saved)) {
                return \App\Services\Risdoc\Pt\PtToTex::render($saved, ['fields' => $fields, 'state' => $state]);
            }
            // Skeleton: title + render items ricorsivi
            // Phase 24.31 — items con title matching sectionbox whitelist
            // (COMPETENZE, ABILITÀ, CONOSCENZE, OSSERVAZIONI ecc.) vengono
            // avvolti in `\begin{sectionbox}{LABEL}...\end{sectionbox}`.
            $parts = [];
            if ($title !== '') {
                $parts[] = '\\section*{' . $this->esc($title) . '}';
            }
            foreach ((array)($section['items'] ?? []) as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $r = $this->renderSchemaSection($item, $fields, $state);
                if ($r === '') {
                    continue;
                }
                $itemTitle = (string)($item['title'] ?? '');
                $boxLabel = $this->matchSectionboxLabel($itemTitle);
                if ($boxLabel !== null) {
                // Strip header del item (è ridondante col label box)
                    $r = preg_replace('/^\\\\(?:subsection|subsubsection|paragraph)\*?\{[^}]*\}\s*\n?/', '', $r, 1) ?? $r;
                    $parts[] = "\\begin{sectionbox}{" . $this->esc($boxLabel) . "}\n"
                             . $r . "\n\\end{sectionbox}";
                } else {
                    $parts[] = $r;
                }
            }
            return implode("\n\n", $parts);
        }

        return match ($type) {
            'header'          => $this->renderHeader($section, $state),
            'nota-textarea'   => $this->renderNotaTextarea($section, $fields),
            'text-section'    => $this->renderTextSection($section, $fields, $state),
            'checkbox-group'  => $this->renderCheckboxGroup($section, $fields, $state),
            'dynamic-table'   => $this->renderDynamicTable($section, $fields),
            'info-field'      => $this->renderInfoField($section, $fields),
            'form-checkbox'   => $this->renderFormCheckbox($section, $fields),
            'grade-selector', 'giudizio-item' => $this->renderSelect($section, $fields),
            'giudizio-group'  => $this->renderGiudizioGroup($section, $fields),
            'static-content'  => $this->renderStaticContent($section),
            'signature-block', 'footer-signature' => $this->renderSignature($section),
            default           => '',
        };
    }

    private function autoSectionName(array $section): string
    {
        if (!empty($section['name'])) {
            return (string)$section['name'];
        }
        $t = (string)($section['title'] ?? 'section');
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $t) ?? $t);
        $slug = trim($slug, '_');
        return 'section_' . substr($slug, 0, 60);
    }

    private function renderHeader(array $s, array $state): string
    {
        // Phase 24.33 — overrides da state (per-combination): headerTitle,
        // headerSelectors hanno priorità sui defaults schema.
        $title = (string)($state['headerTitle'] ?? $s['title'] ?? '');
        $selectors = is_array($state['headerSelectors'] ?? null)
            ? $state['headerSelectors']
            : (array)($s['selectors'] ?? []);
        $parts = [];
        if ($title !== '') {
            $parts[] = '\\begin{center}\\textbf{\\Large ' . $this->esc($title) . '}\\end{center}';
        }
        if ($selectors) {
            $items = [];
            foreach ($selectors as $sel) {
                $v = (string)($state[$sel] ?? '');
                if ($v !== '') {
                    $items[] = $this->esc(ucfirst((string)$sel)) . ': ' . $this->esc($v);
                }
            }
            if ($items) {
                $parts[] = implode(' \\hfill ', $items);
            }
        }
        return implode("\n\n", $parts);
    }

    private function renderNotaTextarea(array $s, array $fields): string
    {
        $name = (string)($s['name'] ?? '');
        $title = (string)($s['title'] ?? $s['label'] ?? '');
        $v = $fields[$name] ?? null;
        $body = '';
        if (\is_array($v) && self::looksLikePortableText($v)) {
            $body = \App\Services\Risdoc\Pt\PtToTex::render($v, ['fields' => $fields, 'state' => $this->_lastState ?? []]);
        } elseif (is_string($v) && $v !== '') {
            $body = $this->esc($v);
        } elseif (isset($s['default']) && \is_array($s['default']) && self::looksLikePortableText($s['default'])) {
            $body = \App\Services\Risdoc\Pt\PtToTex::render($s['default'], ['fields' => $fields, 'state' => $this->_lastState ?? []]);
        }
        if ($body === '') {
            return '';
        }
        // Phase 24.27 — usa sectionbox (definito in risdoc.sty come tcolorbox)
        // per preservare lo style legacy OSSERVAZIONI con cornice + header
        // colorato. Priorità label: mapping storico name→label > title schema.
        $boxLabel = $this->guessBoxLabel($name) ?: ($title !== '' ? $title : 'NOTA');
        return "\\begin{sectionbox}{" . $this->esc(strtoupper($boxLabel)) . "}\n"
             . $body . "\n"
             . "\\end{sectionbox}";
    }

    /**
     * Phase 24.31 — match title item → label sectionbox.
     * Riusa la logica di PtToTex::matchSectionboxLabel via static delegation.
     */
    private function matchSectionboxLabel(string $title): ?string
    {
        $t = mb_strtolower(trim($title));
        if ($t === '') {
            return null;
        }
        $exact = [
            'competenze' => 'COMPETENZE',
            'abilità'    => 'ABILITÀ',
            'abilita'    => 'ABILITÀ',
            'conoscenze' => 'CONOSCENZE',
        ];
        if (isset($exact[$t])) {
            return $exact[$t];
        }
        $contains = [
            'profilo della classe'     => 'OSSERVAZIONI',
            'osservazioni'             => 'OSSERVAZIONI',
            'educazione civica'        => 'ATTIVITÀ DI EDUCAZIONE CIVICA',
            'programma svolto'         => 'CONTENUTI EFFETTIVAMENTE SVOLTI',
            'contenuti effettivamente' => 'CONTENUTI EFFETTIVAMENTE SVOLTI',
        ];
        foreach ($contains as $needle => $label) {
            if (mb_strpos($t, $needle) !== false) {
                return $label;
            }
        }
        return null;
    }

    /** Mapping field-name → label storico del sectionbox (risdoc.sty). */
    private function guessBoxLabel(string $name): string
    {
        return match ($name) {
            'profilo_classe'    => 'OSSERVAZIONI',
            'educazione_civica' => 'ATTIVITÀ DI EDUCAZIONE CIVICA',
            'programma_svolto'  => 'CONTENUTI EFFETTIVAMENTE SVOLTI',
            default             => '',
        };
    }

    private function renderTextSection(array $s, array $fields, array $state): string
    {
        $title = (string)($s['title'] ?? '');
        $desc  = (string)($s['description'] ?? '');
        $parts = [];
        if ($title !== '') {
            $parts[] = '\\subsection*{' . $this->esc($title) . '}';
        }
        if ($desc  !== '') {
            $parts[] = '\\textit{' . $this->esc($desc) . '}';
        }
        foreach ((array)($s['items'] ?? []) as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $r = $this->renderSchemaSection($item, $fields, $state);
            if ($r !== '') {
                $parts[] = $r;
            }
        }
        return implode("\n\n", $parts);
    }

    private function renderCheckboxGroup(array $s, array $fields, array $state = []): string
    {
        $name   = (string)($s['name'] ?? '');
        $title  = (string)($s['title'] ?? $s['label'] ?? '');
        $values = $fields[$name] ?? [];
        $opts   = (array)($s['options'] ?? []);
        // ADR-026 — se il campo dichiara options_source (curriculum JSON file/
        // folder) e $opts è vuoto, FETCHA le opzioni server-side (raggruppate
        // per asse/argomento) → PDF rende tutti gli items del framework anche
        // se nessuna selezione (renderMode 'Tutti'). Senza questo il PDF era
        // un box vuoto perché schema.options assente.
        if (empty($opts) && !empty($s['options_source'])) {
            $opts = $this->fetchOptionsSource((array)$s['options_source'], $state);
        }
        $parts  = [];
        if ($title !== '') {
            $parts[] = '\\subsubsection*{' . $this->esc($title) . '}';
        }
        $selected = \is_array($values) ? array_map('strval', $values) : [];
        // Group-by titolo (cross-argomento) — emette label gruppo prima dei suoi items.
        $byGroup = [];
        foreach ($opts as $o) {
            $obj = \is_array($o) ? $o : ['label' => (string)$o, 'value' => (string)$o];
            $g = (string)($obj['group'] ?? '');
            $byGroup[$g][] = $obj;
        }
        foreach ($byGroup as $gName => $items) {
            if ($gName !== '') {
                $parts[] = '\\textbf{' . $this->esc($gName) . '}\\\\';
            }
            foreach ($items as $obj) {
                $label = (string)($obj['label'] ?? $obj['value'] ?? '');
                $val   = (string)($obj['value'] ?? $obj['label'] ?? '');
                $isSel = \in_array($label, $selected, true) || \in_array($val, $selected, true);
                $cmd   = $isSel ? '\\xcheckbox' : '\\checkbox';
                $parts[] = $cmd . '{' . $this->esc($label) . '}';
            }
        }
        return implode("\n", $parts);
    }

    /** Fetch JSON opzioni curriculum (file o folder per ind/classe/disciplina). */
    private function fetchOptionsSource(array $src, array $state): array
    {
        $root = $this->rootDir;
        $path = null;
        if (!empty($src['file'])) {
            $path = $root . '/storage/templates/risdoc/' . ltrim((string)$src['file'], '/');
        } elseif (!empty($src['folder'])) {
            $ind = (string)($state['indirizzo']  ?? '');
            $cls = (string)($state['classe']     ?? '');
            $mat = (string)($state['disciplina'] ?? '');
            if ($ind === '' || $cls === '' || $mat === '') {
                return [];
            }
            // Pattern: <folder>/<IND>/<mat-lower>/<IND>_<cls>_<mat-lower>.json
            $folder = (string)$src['folder'];
            $matL = strtolower($mat);
            $path = $root . '/storage/templates/risdoc/' . $folder . '/' . $ind . '/' . $matL . '/'
                  . $ind . '_' . $cls . '_' . $matL . '.json';
        }
        if (!$path || !is_file($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }
        // Stessa parseOptionsJson di _options-fetcher.js: estrai contenuti +
        // group per titolo. Formato sorgente: array di {titolo, contenuti:[{label,...}]}
        // o array di {label,...}.
        $out = [];
        foreach ($data as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['contenuti']) && is_array($node['contenuti'])) {
                $g = (string)($node['titolo'] ?? '');
                foreach ($node['contenuti'] as $item) {
                    if (!is_array($item) || !isset($item['label'])) {
                        continue;
                    }
                    $out[] = [
                        'value' => (string)$item['label'],
                        'label' => (string)$item['label'],
                        'group' => $g,
                    ];
                }
            } elseif (isset($node['label'])) {
                $out[] = [
                    'value' => (string)$node['label'],
                    'label' => (string)$node['label'],
                ];
            }
        }
        return $out;
    }

    private function renderDynamicTable(array $s, array $fields): string
    {
        $name  = (string)($s['name'] ?? '');
        $title = (string)($s['title'] ?? '');
        $cols  = (array)($s['columns'] ?? []);
        $colsResolved = array_map(fn($c) => \is_array($c) ? (string)($c['label'] ?? $c['value'] ?? '') : (string)$c, $cols);
        $rows  = (array)($fields[$name] ?? []);
        $out = [];
        if ($title !== '') {
            $out[] = '\\subsection*{' . $this->esc($title) . '}';
        }
        if (count($colsResolved) === 0) {
            return implode("\n", $out);
        }
        $spec = '|' . str_repeat('l|', count($colsResolved));
        $out[] = '\\begin{tabular}{' . $spec . '}';
        $out[] = '\\hline';
        $out[] = implode(' & ', array_map(fn($c) => $this->esc($c), $colsResolved)) . ' \\\\';
        $out[] = '\\hline';
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $cells = [];
            foreach ($colsResolved as $i => $col) {
                $fieldName = \is_array($cols[$i] ?? null) ? (string)($cols[$i]['field'] ?? $cols[$i]['value'] ?? $col) : $col;
                $cellVal = $row[$fieldName] ?? $row[$i] ?? '';
                // value può essere array (valore per-classe / multi): (string)(array)
                // darebbe "Array". Join per gli array, cast per gli scalari.
                $cellStr = \is_array($cellVal)
                    ? \implode(', ', \array_map(static fn($x) => \is_scalar($x) ? (string)$x : '', $cellVal))
                    : (string)$cellVal;
                $cells[] = $this->esc($cellStr);
            }
            $out[] = implode(' & ', $cells) . ' \\\\';
            $out[] = '\\hline';
        }
        $out[] = '\\end{tabular}';
        return implode("\n", $out);
    }

    private function renderInfoField(array $s, array $fields): string
    {
        $name  = (string)($s['name'] ?? '');
        $label = (string)($s['label'] ?? $s['title'] ?? '');
        $v = (string)($fields[$name] ?? $s['default'] ?? '');
        if ($v === '') {
            return '';
        }
        $prefix = $label !== '' ? '\\textbf{' . $this->esc($label) . ':} ' : '';
        return $prefix . $this->esc($v);
    }

    private function renderFormCheckbox(array $s, array $fields): string
    {
        $name = (string)($s['name'] ?? '');
        $label = (string)($s['label'] ?? $s['title'] ?? '');
        $checked = (bool)($fields[$name] ?? $s['default'] ?? false);
        $cmd = $checked ? '\\xcheckbox' : '\\checkbox';
        return $cmd . '{' . $this->esc($label) . '}';
    }

    private function renderSelect(array $s, array $fields): string
    {
        $name  = (string)($s['name'] ?? '');
        $label = (string)($s['label'] ?? $s['title'] ?? '');
        $v     = (string)($fields[$name] ?? '');
        if ($v === '' && $label === '') {
            return '';
        }
        $prefix = $label !== '' ? '\\textbf{' . $this->esc($label) . ':} ' : '';
        return $prefix . ($v !== '' ? '\\underline{' . $this->esc($v) . '}' : '\\underline{\\hspace{3cm}}');
    }

    private function renderGiudizioGroup(array $s, array $fields): string
    {
        $title = (string)($s['title'] ?? '');
        $parts = [];
        if ($title !== '') {
            $parts[] = '\\subsection*{' . $this->esc($title) . '}';
        }
        foreach ((array)($s['items'] ?? []) as $it) {
            if (!\is_array($it)) {
                continue;
            }
            $r = $this->renderSelect($it, $fields);
            if ($r !== '') {
                $parts[] = $r;
            }
        }
        return implode("\n\n", $parts);
    }

    private function renderStaticContent(array $s): string
    {
        $html = (string)($s['html'] ?? '');
        if ($html === '') {
            return '';
        }
        $text = trim(strip_tags($html));
        return $text === '' ? '' : $this->esc($text);
    }

    private function renderSignature(array $s): string
    {
        $title = (string)($s['title'] ?? 'Firma');
        return '\\vspace{1.5cm}\\noindent\\textbf{' . $this->esc($title) . '}: \\underline{\\hspace{5cm}}';
    }

    /** LaTeX escape — G22.S15.bis Fase 5+ delegate alla utility canonica. */
    private function esc(string $s): string
    {
        return \App\Services\Tex\TexEscape::escape($s);
    }
}
