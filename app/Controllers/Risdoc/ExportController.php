<?php

declare(strict_types=1);

namespace App\Controllers\Risdoc;

use App\Core\Request;
use App\Core\Response;
use App\Services\Risdoc\Permission;
use App\Services\Risdoc\TemplateResolver;

/**
 * Export TeX per risdoc per-teacher (Phase 21, U9).
 *
 * POST /api/risdoc/templates/{id}/export
 *   body: form_state (JSON), mode: 'zip' | 'overleaf'
 *
 * Flusso:
 *   1. Risolve doc TeX body (override o source) + main.tex + intestaLAteX_IIS.tex + risdoc.sty
 *   2. Sostituisce marker semplici ([field-*]) con valori da form_state.
 *      (Logica avanzata list-show/list-hide/testo/selection richiederebbe
 *      porting completo di risdoc.js — qui MVP semplice.)
 *   3. Scrive ZIP in public/storage/risdoc-tmp/doc-<uuid>.zip
 *   4. Ritorna URL assoluto (client redirect per Overleaf snip_uri).
 */
final class ExportController
{
    public function __construct(private TemplateResolver $resolver = new TemplateResolver())
    {
    }

    /**
     * Stream ZIP via PHP (bypass .htaccess). GET /api/risdoc/exports/{file}
     */
    public function serve(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        if ($tid === 0 && !Permission::isSuperAdmin()) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $file = (string)($params['file'] ?? '');
// Phase 24.36 — accept anche content-{id}-{hex}.zip da TeacherContentController
        if (!preg_match('/^(?:doc-[a-f0-9]{16}|content-\d+-[a-f0-9]{12})\.zip$/', $file)) {
            return Response::json(['error' => 'invalid_filename'], 400);
        }
        $root = dirname(__DIR__, 3);
        $abs = $root . '/storage/risdoc-tmp/' . $file;
        if (!is_file($abs)) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $body = (string)@file_get_contents($abs);
        $r = new Response($body, 200);
        $r->headers['Content-Type']        = 'application/zip';
        $r->headers['Content-Disposition'] = 'attachment; filename="' . $file . '"';
        $r->headers['Content-Length']      = (string)strlen($body);
        $r->headers['Cache-Control']       = 'private, no-cache';
        return $r;
    }

    public function export(Request $req, array $params): Response
    {
        $id  = (int)($params['id'] ?? 0);
        $tid = Permission::currentTeacherId();
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $tmpl = $this->resolver->findTemplate($id);
        if (!$tmpl) {
            return Response::json(['error' => 'template_not_found'], 404);
        }

        $formState = $this->parseFormState((string)($req->post['form_state'] ?? ''));
        $mode      = (string)($req->post['mode'] ?? 'zip');

        try {
            $built = $this->buildFiles($id, $tid, $tmpl, $formState);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'texbuilder_failed', 'detail' => $e->getMessage()], 500);
        }
        $root      = $built['root'];
        $docName   = $built['docName'];
        $mainFinal = $built['mainFinal'];
        $doc       = $built['doc'];
        $styBody   = $built['styBody'];
        $headBody  = $built['headBody'];
        // Build ZIP
        // Nota: il ZIP serve essere raggiungibile via URL. Con Apache root =
        // repo root (hosting legacy setup), scriviamo in `storage/risdoc-tmp/` e
        // aggiungiamo whitelist `.htaccess` (vedi root `.htaccess`).
        $pubDir = $root . '/storage/risdoc-tmp';
        if (!is_dir($pubDir) && !@mkdir($pubDir, 0775, true) && !is_dir($pubDir)) {
            return Response::json(['error' => 'storage_unavailable'], 500);
        }
        $this->cleanupOld($pubDir, 3600);
        $name = 'doc-' . bin2hex(random_bytes(8)) . '.zip';
        $zipPath = $pubDir . DIRECTORY_SEPARATOR . $name;
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return Response::json(['error' => 'zip_open_failed'], 500);
        }
        $zip->addFromString('main.tex', $mainFinal);
        $zip->addFromString($docName, $doc);
        if ($styBody !== '') {
            $zip->addFromString('texCommon/risdoc.sty', $styBody);
        }
        if ($headBody !== '') {
            $zip->addFromString('texCommon/intestaLAteX_IIS.tex', $headBody);
        }
        // Images: se presenti nella sorgente origin/images
        $imgDir = $root . '/storage/templates/risdoc/images';
        if (is_dir($imgDir)) {
            foreach (glob($imgDir . '/*') ?: [] as $img) {
                if (is_file($img)) {
                    $zip->addFile($img, 'images/' . basename($img));
                }
            }
        }
        $zip->close();
// Servo via endpoint PHP (bypass .htaccess restrictions su hosting condiviso shared).
        $url = $this->publicUrl($req) . '/api/risdoc/exports/' . $name;
        if ($mode === 'overleaf') {
            return Response::json([
                'ok' => true, 'mode' => 'overleaf', 'url' => $url,
                'overleaf_url' => 'https://www.overleaf.com/docs?snip_uri=' . rawurlencode($url),
            ]);
        }
        return Response::json(['ok' => true, 'mode' => 'zip', 'url' => $url, 'expires' => time() + 3600]);
    }

    private function parseFormState(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    /**
     * G22.S11 — build set di file (riusabile fra export ZIP e modal multi-file
     * preview/compile). Risolve schema-driven body via TexBuilder, carica i
     * 3 texCommon files con override per-teacher (kind='texCommon'), applica
     * landscape + style overrides al .sty.
     *
     * Ritorna: [
     *   'root'      => repo root,
     *   'docName'   => '<argomento>.tex',
     *   'mainFinal' => main.tex con %[filetex] sostituito,
     *   'doc'       => body .tex generato da schema+state (read-only nel modal),
     *   'styBody'   => risdoc.sty (overridable),
     *   'headBody'  => intestaLAteX_IIS.tex (overridable),
     *   'overrides' => [path => 'user' | 'common']  (status per UI tree),
     * ]
     */
    public function buildFiles(int $id, int $tid, array $tmpl, array $formState): array
    {
        $root = dirname(__DIR__, 3);
        $ctx = [
            'fields' => (array)($formState['fields'] ?? []),
            'state'  => (array)($formState['state']  ?? []),
        ];
        $bodyPt = (array)($formState['body_pt'] ?? []);
        // ADR-026 — UNIFY: se c'è il body_pt dell'editor, è LA fonte di verità →
        // render via PtToTex (formule, renderMode checkbox, celle widget, valori,
        // sectionbox: coerente con editor/anteprima/vista web). Lo schema
        // (TexBuilder) resta il FALLBACK per i template senza body_pt.
        if (count($bodyPt) > 0) {
            $doc = \App\Services\Risdoc\Pt\PtToTex::render($bodyPt, $ctx);
        } else {
            $schemaPath = (string)($tmpl['schema_path'] ?? '');
            if ($schemaPath === '' || !is_file($root . '/' . ltrim($schemaPath, '/'))) {
                throw new \RuntimeException('schema_not_set');
            }
            $doc = (new \App\Services\Risdoc\TexBuilder($root . '/' . ltrim($schemaPath, '/')))->build($ctx);
        }

        $repo = new \App\Services\Risdoc\OverrideRepository();
        $overrides = [];
        $loadTexCommon = function (string $rel) use ($root, $tid, $id, $repo, &$overrides): string {
            $abs = $root . '/storage/templates/risdoc/texCommon/' . $rel;
            $base = is_file($abs) ? (string)file_get_contents($abs) : '';
            $ov = $repo->find($tid, $id, 'texCommon', $rel);
            if ($ov && $ov['body'] !== null) {
                $overrides['texCommon/' . $rel] = 'user';
                return (string)$ov['body'];
            }
            $overrides['texCommon/' . $rel] = $base !== '' ? 'common' : 'missing';
            return $base;
        };
        $mainTpl  = $loadTexCommon('main.tex');
        $docName  = $this->sanitizeName((string)$tmpl['argomento']) . '.tex';
        $mainFinal = str_replace('%[filetex]', '\\input{' . $docName . '}', $mainTpl);

        // Toggle intestazione istituto (checkbox nella pagina HTML — header
        // section). Default INCLUSA. Se disattivata, commenta la riga
        // \input{texCommon/intestaLAteX_IIS(.tex)} in main.tex.
        $includeHeader = !array_key_exists('includeHeader', (array)($formState['state'] ?? []))
            || filter_var($formState['state']['includeHeader'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
        if (!$includeHeader) {
            $mainFinal = preg_replace(
                '/^[ \t]*\\\\input\{texCommon\/intestaLAteX_IIS(?:\.tex)?\}.*$/m',
                '% [intestazione istituto disattivata dal docente]',
                $mainFinal
            ) ?? $mainFinal;
        }
        $styBody  = $loadTexCommon('risdoc.sty');
        $headBody = $loadTexCommon('intestaLAteX_IIS.tex');

        // Landscape orientation marker
        $orientation = (string)(($formState['state']['pageOrientation']
                             ?? $formState['pageOrientation'] ?? 'portrait'));
        if ($orientation === 'landscape' && $styBody !== '') {
            $styBody = str_replace('%[landscape]', 'landscape,', $styBody);
        }

        // Style overrides (colori sectionbox dalla toolbar)
        $styleOv = (array)($formState['state']['styleOverrides'] ?? []);
        if ($styleOv && $styBody !== '') {
            $overrideLines = [];
            $hexToRgb = function ($hex) {
                $h = ltrim((string)$hex, '#');
                if (strlen($h) === 3) {
                    $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
                }
                if (!preg_match('/^[0-9a-f]{6}$/i', $h)) {
                    return null;
                }
                return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
            };
            $map = [
                'sectionboxBg'     => 'colorBackTitleSec',
                'sectionboxBorder' => 'borderColor',
                'titleText'        => 'titleTextColor',
            ];
            foreach ($map as $key => $colorName) {
                $rgb = $hexToRgb($styleOv[$key] ?? '');
                if ($rgb) {
                    $overrideLines[] = sprintf('\\definecolor{%s}{RGB}{%d,%d,%d}', $colorName, $rgb[0], $rgb[1], $rgb[2]);
                }
            }
            if ($overrideLines) {
                $injected = "\n% Phase 24.30 — Style overrides via toolbar\n" . implode("\n", $overrideLines) . "\n";
                $styBody = preg_replace(
                    '/((?:\\\\definecolor\{[^}]+\}\{[^}]+\}\{[^}]+\}\s*\n)+)/',
                    '$1' . $injected,
                    $styBody,
                    1
                ) ?? $styBody . $injected;
            }
        }

        return [
            'root'      => $root,
            'docName'   => $docName,
            'mainFinal' => $mainFinal,
            'doc'       => $doc,
            'styBody'   => $styBody,
            'headBody'  => $headBody,
            'overrides' => $overrides,
        ];
    }

    /**
     * ADR-026 Step 5 Fix #3 — POST-PROCESS sectionbox: estrae dai sectionHeader
     * del body_pt quelli con `boxed:true` e wrappa la sezione LaTeX corrispondente
     * in \begin{sectionbox}{title}...\end{sectionbox} (titolo esatto matching).
     * Non distrugge il layout schema-based; aggiunge solo il wrapping sectionbox.
     */
    private function applyBoxedFromBodyPt(string $doc, array $bodyPt): string
    {
        $boxed = [];
        $walk = function ($nodes) use (&$walk, &$boxed) {
            foreach ((array)$nodes as $b) {
                if (!is_array($b)) {
                    continue;
                }
                if (($b['_type'] ?? '') === 'sectionHeader' && !empty($b['boxed']) && !empty($b['title'])) {
                    $boxed[] = [
                        'title' => (string)$b['title'],
                        'level' => (int)($b['level'] ?? 2),
                    ];
                }
                if (isset($b['children']) && is_array($b['children'])) {
                    $walk($b['children']);
                }
            }
        };
        $walk($bodyPt);
        if (!$boxed) {
            return $doc;
        }

        $cmd = function (int $lvl): string {
            return match (true) {
                $lvl <= 1 => 'section',
                $lvl === 2 => 'subsection',
                $lvl === 3 => 'subsubsection',
                $lvl === 4 => 'paragraph',
                default    => 'subparagraph',
            };
        };
        // Comandi di livello pari o superiore = boundary di chiusura sectionbox.
        $boundaryRegex = function (int $lvl): string {
            $cmds = [];
            if ($lvl <= 1) {
                $cmds[] = 'section';
            }
            if ($lvl <= 2) {
                $cmds[] = 'subsection';
            }
            if ($lvl <= 3) {
                $cmds[] = 'subsubsection';
            }
            if ($lvl <= 4) {
                $cmds[] = 'paragraph';
            }
            $cmds[] = 'subparagraph';
            // sempre boundary: \end{document}, \input{texCommon/codaLAteX}
            return '\\\\(?:' . implode('|', $cmds) . ')\\*?\\{|\\\\end\\{document\\}';
        };

        foreach ($boxed as $h) {
            $c = $cmd($h['level']);
            $titleEsc = preg_quote($h['title'], '/');
            // Match \<cmd>{title} (eventualmente con *) e cattura inizio + resto fino al boundary
            $pattern = '/(\\\\' . preg_quote($c, '/') . '\\*?\\{' . $titleEsc . '\\})(.*?)(?=' . $boundaryRegex($h['level']) . '|$)/s';
            $doc = preg_replace_callback($pattern, function ($m) use ($h) {
                $header = $m[1];
                $body   = $m[2];
                $title  = addcslashes($h['title'], '{}\\');
                return $header . "\n\\begin{sectionbox}{" . $title . "}" . $body . "\\end{sectionbox}\n";
            }, $doc, 1);
        }
        return $doc;
    }

    /**
     * Raccoglie i valori da TUTTE le tabelle labeled-rows in fields,
     * in ordine di apparizione (nel JSON form_state). Ogni riga con .value
     * o altre colonne editabili concorre alla lista. Usato per sostituire
     * i marker `[field]` posizionali del .tex legacy.
     *
     * fields.studenti_table = [{value:"25",__label:"TOTALE",...}, {value:"",...}, ...]
     *   → ["25", "", ...]
     */
    private function collectLabeledRowValues(array $fields): array
    {
        $out = [];
        foreach ($fields as $tableName => $rows) {
            if (!\is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                // Labeled-rows mode: row ha __label, __field, __input, value
                if (\array_key_exists('__label', $row) || \array_key_exists('__field', $row)) {
        // Skip righe con label vuoto (separator visuali nel WC, non
                    // rappresentate da [field] nel .tex legacy)
                    $lbl = trim((string)($row['__label'] ?? ''));
                    if ($lbl === '') {
                        continue;
                    }
                    $out[] = (string)($row['value'] ?? '');
                } else {
                // dynamic-rows mode: tutte le colonne sono valori
                    foreach ($row as $k => $v) {
                        if (\str_starts_with((string)$k, '__')) {
                            continue;
                        }
                        $out[] = (string)$v;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Risolve codici curriculum (es. "sc", "2s", "MAT") in label leggibili
     * ("Scientifico", "Classe II", "Matematica").
     * Ritorna un array con le label, con fallback al codice se non trovato.
     */
    private function resolveCurriculumLabels(array $state): array
    {
        $out = $state;
// copia codes originali come default
        try {
            $svc = new \App\Services\CurriculumService(dirname(__DIR__, 3) . '/storage/data/curriculum.json');
            $all = $svc->all();
            $map = fn(string $kind, string $code) =>
                $code === '' ? '' : ((function () use ($all, $kind, $code) {

                    foreach ($all[$kind] ?? [] as $row) {
if (($row['code'] ?? '') === $code) {
return (string)($row['label'] ?? $code);
}
                    }
                    return $code;
                })());
            if (isset($state['indirizzo'])) {
                $out['indirizzo']  = $map('indirizzi', (string)$state['indirizzo']);
            }
            if (isset($state['classe'])) {
                $out['classe']     = $map('classi', (string)$state['classe']);
            }
            if (isset($state['disciplina'])) {
                $out['disciplina'] = $map('materie', (string)$state['disciplina']);
            }
        } catch (\Throwable) {
        // silent: resta state originale (codici)
        }
        return $out;
    }

    /**
     * Processa un .tex legacy (storage/templates/risdoc/{CAT}/tex/*.tex)
     * con i marker risdoc.js-compatibili:
     *
     *   [field-<name>]                    → escape( state[name] || fields[name] )
     *   \simplefield{Label}[field-<name>] → {Label}{VALUE}
     *   [field]                           → valore posizionale (fields.studenti_table etc.)
     *   %[BeginList-hide]...%[EndList-hide]  → BLOCCO RIMOSSO
     *   %[BeginList-show]...%[EndList-show]  → marker stripped (contenuto resta)
     *   %[BeginTesto]...%[EndTesto]          → marker stripped (contenuto resta)
     *   %[BeginOpzione]...%[EndOpzione]      → marker stripped (contenuto resta)
     *   %[BeginTextArea]...%[EndTextArea]    → textarea content da fields (se presente) o vuoto
     *   %[selection]                         → ignorato (placeholder per selezioni JSON — TODO)
     *
     * Il risultato è un body .tex valido che main.tex include via \input{}.
     */
    private function processLegacyTex(string $tex, array $formState): string
    {
        $rawState = (array)($formState['state']  ?? []);
        $state    = $this->resolveCurriculumLabels($rawState);
// codici → label leggibili
        $fields   = (array)($formState['fields'] ?? []);
// 1. Strip intero blocco List-hide (quelle righe NON devono apparire)
        $tex = preg_replace('/%\s*\[BeginList-hide\][\s\S]*?%\s*\[EndList-hide\]/', '', $tex) ?? $tex;
// 2. Strip i marker comment SOLO (conserva il contenuto tra di essi).
        // Rimuove anche il terminator di riga per evitare righe vuote che
        // rompono contesti LaTeX fragili (\textit{...}, \item, tabular rows).
        $stripMarkers = [
            '/^\s*%\s*\[BeginList-show\]\s*\r?\n/m',
            '/^\s*%\s*\[EndList-show\]\s*\r?\n/m',
            '/^\s*%\s*\[BeginTesto\]\s*\r?\n/m',
            '/^\s*%\s*\[EndTesto\]\s*\r?\n/m',
            '/^\s*%\s*\[BeginOpzione\]\s*\r?\n/m',
            '/^\s*%\s*\[EndOpzione\]\s*\r?\n/m',
            '/^\s*%\s*\[BeginTextArea\]\s*\r?\n/m',
            '/^\s*%\s*\[EndTextArea\]\s*\r?\n/m',
            '/^\s*%\s*\[selection\]\s*\r?\n/m',
            '/^\s*%\s*EndOption\s*\r?\n/m',
            // Fallback: marker senza newline (raro, a fine file)
            '/%\s*\[BeginList-show\]/',  '/%\s*\[EndList-show\]/',
            '/%\s*\[BeginTesto\]/',      '/%\s*\[EndTesto\]/',
            '/%\s*\[BeginOpzione\]/',    '/%\s*\[EndOpzione\]/',
            '/%\s*\[BeginTextArea\]/',   '/%\s*\[EndTextArea\]/',
            '/%\s*\[selection\]/',
        ];
        $tex = preg_replace($stripMarkers, '', $tex) ?? $tex;
// 3. \simplefield{Label}[field-<name>] → {Label}{VALUE escapato}
        $tex = preg_replace_callback('/\\\\simplefield\{([^}]*)\}\[field-([a-zA-Z0-9_-]+)\]/', function ($m) use ($state, $fields) {

                $label = $m[1];
            $key   = $m[2];
            $v     = $state[$key] ?? $fields[$key] ?? '';
            return '\\simplefield{' . $label . '}{' . $this->escapeTex((string)$v) . '}';
        }, $tex) ?? $tex;
// 4. [field-<name>] → escape( state[name] || fields[name] )
        $tex = preg_replace_callback('/\[field-([a-zA-Z0-9_-]+)\]/', function ($m) use ($state, $fields) {

                $k = $m[1];
            $v = $state[$k] ?? $fields[$k] ?? '';
            return $this->escapeTex((string)$v);
        }, $tex) ?? $tex;
// 5. [field] posizionali — sostituiti con i valori della PROSSIMA tabella
        //    labeled-rows (es. fields.studenti_table = [{value:"25"},{value:"2"}…]).
        //    Scorro i marker [field] in ordine; per ognuno peso il prossimo row
        //    disponibile tra TUTTE le labeled-tables in fields, in ordine di
        //    dichiarazione nel .tex.
        $flatValues = $this->collectLabeledRowValues($fields);
        $idx = 0;
        $tex = preg_replace_callback('/\[field\](?!-)/', function () use (&$idx, $flatValues) {

            $v = $flatValues[$idx] ?? '';
            $idx++;
            return $this->escapeTex((string)$v);
        }, $tex) ?? $tex;
// 6. Se il .tex legacy include \documentclass o \begin{document}, rimuoviamo
        //    perché main.tex li fornisce già (evita double-documentclass error).
        $tex = preg_replace('/^\\\\documentclass\b[^\n]*\n?/m', '', $tex) ?? $tex;
        $tex = preg_replace('/\\\\begin\{document\}/', '', $tex) ?? $tex;
        $tex = preg_replace('/\\\\end\{document\}/', '', $tex) ?? $tex;
// Rimuove package già inclusi in risdoc.sty per evitare conflitti
        $tex = preg_replace('/^\\\\usepackage(\[[^\]]*\])?\{(babel|inputenc|hyperref|url|geometry|graphicx|tikz|fancyhdr|microtype|enumitem|amsmath|tabularx|longtable|multirow|pdflscape|xcolor|tcolorbox|array|calc|makecell|lmodern|fontenc)\}\s*\n?/m', '', $tex) ?? $tex;
// 7. Fix bug comune nei .tex legacy: `\vspace{...}\\` → "no line to end".
        //    \\ dopo \vspace{} non ha riga da terminare. Sostituiamo con solo \vspace.
        $tex = preg_replace('/(\\\\vspace\*?\{[^}]*\})\s*\\\\\\\\/', '$1', $tex) ?? $tex;
//    Analogo: `\newpage\\`
        $tex = preg_replace('/(\\\\newpage)\s*\\\\\\\\/', '$1', $tex) ?? $tex;
// Nota: NON rimuovere \\ prima di \end{tabularx/tabular} — in tabellari
        // è richiesto per terminare l'ultima riga.

        // 8. Section override: se il docente ha compilato un campo nota-textarea
        //    che corrisponde a una sectionbox hardcoded nel .tex (es. "profilo_classe"
        //    → sectionbox{OSSERVAZIONI}), sostituisci il contenuto della sectionbox
        //    con il testo inserito dall'utente. Se campo vuoto → template legacy resta.
        $sectionMap = [
            'profilo_classe'     => 'OSSERVAZIONI',
            'educazione_civica'  => 'ATTIVITÀ DI EDUCAZIONE CIVICA',
            'programma_svolto'   => 'CONTENUTI EFFETTIVAMENTE SVOLTI',
        ];
        foreach ($sectionMap as $fieldName => $sectionLabel) {
            $raw = $fields[$fieldName] ?? null;
        // Phase 24.25 — PT AST handling: se il field è un array PT (ha
            // almeno un block con _type), renderizza via PtToTex invece di
            // (string)cast che produrrebbe "Array".
            if (\is_array($raw) && $this->looksLikePt($raw)) {
                $rendered = trim(\App\Services\Risdoc\Pt\PtToTex::render($raw));
                if ($rendered === '') {
                    continue;
                }
                $pattern = '/(\\\\begin\{sectionbox\}\{' . preg_quote($sectionLabel, '/') . '\})[\s\S]*?(\\\\end\{sectionbox\})/';
                $tex = preg_replace($pattern, '$1' . "\n\t" . $rendered . "\n" . '$2', $tex, 1) ?? $tex;
                continue;
            }
            $userValue = trim((string)($raw ?? ''));
            if ($userValue === '') {
                continue;
            }
            $escaped = $this->escapeTex($userValue);
            $pattern = '/(\\\\begin\{sectionbox\}\{' . preg_quote($sectionLabel, '/') . '\})[\s\S]*?(\\\\end\{sectionbox\})/';
            $tex = preg_replace($pattern, '$1' . "\n\t" . $escaped . "\n" . '$2', $tex, 1) ?? $tex;
        }

        return $tex;
    }

    private function substituteFields(string $tex, array $formState): string
    {
        // Sostituisce [field-<name>] con valore da formState
        $tex = preg_replace_callback('/\[field-([a-zA-Z0-9_-]+)\]/', function ($m) use ($formState) {

            $k = $m[1];
            return $this->escapeTex((string)($formState[$k] ?? ''));
        }, $tex) ?? $tex;
// Sostituisce [field] sequenziali con i valori .field in ordine (se formState ha 'fields' array)
        if (isset($formState['fields']) && is_array($formState['fields'])) {
            $idx = 0;
            $tex = preg_replace_callback('/\[field\]/', function () use (&$idx, $formState) {

                $v = $formState['fields'][$idx] ?? '';
                $idx++;
                return $this->escapeTex((string)$v);
            }, $tex) ?? $tex;
        }
        return $tex;
    }

    private function escapeTex(string $s): string
    {
        return strtr($s, ['\\' => '\\textbackslash{}', '&' => '\\&', '%' => '\\%', '$' => '\\$',
                          '#' => '\\#', '_' => '\\_', '{' => '\\{', '}' => '\\}',
                          '~' => '\\textasciitilde{}', '^' => '\\textasciicircum{}']);
    }

    /**
     * Phase 24.25 — Detecta PT AST: array list con primo block che ha _type
     * tra i tipi supportati da PtToTex.
     */
    private function looksLikePt(array $v): bool
    {
        if ($v === [] || !\array_is_list($v)) {
            return false;
        }
        $first = $v[0] ?? null;
        if (!\is_array($first)) {
            return false;
        }
        $type = $first['_type'] ?? null;
        return \in_array($type, [
            'block', 'checkboxGroup', 'rawTex',
            'table', 'select', 'textField', 'formCheckbox', 'sectionHeader',
        ], true);
    }

    public function sanitizeName(string $n): string
    {
        return preg_replace('/[^\w.\-]/', '_', $n) ?: 'documento';
    }

    private function cleanupOld(string $dir, int $seconds): void
    {
        foreach (glob($dir . '/doc-*.zip') ?: [] as $f) {
            if (is_file($f) && (time() - filemtime($f)) > $seconds) {
                @unlink($f);
            }
        }
    }

    private function publicUrl(Request $req): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }
}
