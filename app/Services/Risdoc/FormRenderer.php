<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

/**
 * FormRenderer — Plan A modernization.
 *
 * Legge un template schema JSON (schemas/risdoc/*.json) e dispatcha
 * ogni `section` al partial PHP corrispondente. Sostituisce i PHP
 * template monolitici sotto storage/templates/risdoc/{MODELLI,
 * RISORSE}/php/*.php.
 *
 * Uso:
 *   $renderer = new FormRenderer(ROOT . '/schemas/risdoc/motivazione-voti.json');
 *   echo $renderer->render([
 *       'compilation' => $dataFromDb,
 *       'teacherId'   => 42,
 *   ]);
 *
 * I partials vivono in views/risdoc/partials/section-<type>.php e ricevono
 * due variabili: $section (array dello schema), $ctx (render context).
 */
final class FormRenderer
{
    private array $schema;
    private string $partialsDir;
    public function __construct(string $schemaPath, ?string $partialsDir = null)
    {
        if (!is_file($schemaPath)) {
            throw new \InvalidArgumentException("Schema not found: {$schemaPath}");
        }
        $json = file_get_contents($schemaPath);
        $decoded = json_decode((string)$json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid JSON in {$schemaPath}: " . json_last_error_msg());
        }
        $this->schema = $decoded;
        $this->partialsDir = $partialsDir ?? dirname(__DIR__, 3) . '/views/risdoc/partials';
    }

    public function schema(): array
    {
        return $this->schema;
    }
    public function title(): string
    {
        return (string)($this->schema['title'] ?? '');
    }
    public function category(): string
    {
        return (string)($this->schema['category'] ?? '');
    }

    /**
     * Render the template body (no <html>/<head>/<body> wrapper — that
     * is provided by the TemplateViewController + app.php shell).
     *
     * @param array{compilation?:array,teacherId?:int,readonly?:bool} $ctx
     */
    public function render(array $ctx = []): string
    {
        $sections = $this->schema['sections'] ?? [];
        $out = ['<div class="page-container">'];
        $out[] = '<input id="genere" type="hidden" value="M">';
        foreach ($sections as $section) {
            $out[] = $this->renderSection($section, $ctx);
        }
        $out[] = '</div>';
// Grade-mappings + auto-compilation script inline: riproduce il
        // comportamento del <script> nel legacy PHP senza duplicare codice.
        $gradeMappings = $this->schema['grade_mappings'] ?? [];
        if (!empty($gradeMappings)) {
            $out[] = $this->renderAutoCompileScript($gradeMappings);
        }

        return implode("\n", $out);
    }

    public function renderSection(array $section, array $ctx): string
    {
        $type = (string)($section['type'] ?? '');
        if ($type === '') {
            return '<!-- section without type -->';
        }

        $partial = $this->partialsDir . '/section-' . $type . '.php';
        if (!is_file($partial)) {
            return '<!-- unknown section type: ' . htmlspecialchars($type, ENT_QUOTES) . ' -->';
        }

        // Partials usano $section, $ctx, $renderer (per ricorsione su items).
        $renderer = $this;
        ob_start();
        include $partial;
        return (string)ob_get_clean();
    }

    /** Script inline per l'autocompilazione dal voto complessivo. */
    private function renderAutoCompileScript(array $mappings): string
    {
        $json = json_encode($mappings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return <<<HTML
<script>
(function() {
    const MAPPINGS = {$json};
    document.addEventListener('DOMContentLoaded', function() {
        const grade = document.getElementById('gradeSelector');
        if (!grade) return;
        const criteria = document.querySelectorAll('.risp_giud');
        grade.addEventListener('change', () => {
            const m = MAPPINGS[grade.value];
            criteria.forEach(s => { s.value = ''; });
            if (!m) return;
            for (const [name, value] of Object.entries(m)) {
                const s = document.querySelector('select[name="' + name + '"].risp_giud');
                if (s) {
                    // Imposta per value diretto. Se l'option ha label diversa,
                    // cerca per testo option corrispondente.
                    const opt = Array.from(s.options).find(o => o.value === value || o.textContent.trim().startsWith(value));
                    if (opt) s.value = opt.value;
                }
            }
            // Trigger change manualmente per eventuali listener esterni.
            criteria.forEach(s => s.dispatchEvent(new Event('change', { bubbles: true })));
        });
    });
})();
</script>
HTML;
    }
}
