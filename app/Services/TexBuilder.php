<?php

namespace App\Services;

use App\Core\Config;
use App\Services\TexBuilder\BadgeRenderer;
use App\Services\TexBuilder\BuildResult;
use App\Services\TexBuilder\PlaceholderResolver;
use App\Services\TexBuilder\EserciziBodyRenderer;
use App\Services\TexBuilder\Sanitizer;
use App\Services\TexBuilder\Selection;
use App\Services\TexBuilder\TableRenderer;
use App\Services\TexBuilder\VersionPicker;
use App\Services\TexCompile\TexFormatClient;
use App\Services\Verifica\TemplateFileStore;
use Throwable;

/**
 * G20.0 + G22.S4 — TexBuilder produce un BuildResult multi-file. Ogni
 * invocazione genera per UNA variant: 1 main_*.tex + 1 esercizi_*.tex +
 * i file `texCommon`/`griglie` necessari, letti via cascade da
 * TemplateFileStore.
 *
 * Modalita' (BuildResult.mode):
 *   - 'zip':  layout piatto archivio (tutti i file sotto root ZIP)
 *   - 'vsc':  layout distribuito (texCommon a istituto root, griglie a
 *             indirizzo, main+problemi a version folder per editing locale)
 *   - 'flat': layout canonico, destinato a `BuildResult::flatten()` per
 *             produrre un singolo .tex self-contained (storage blob unico,
 *             download /tex, invio diretto al VPS tex-compile-vps)
 *
 * Helper `buildFlat()` (alias di `build(MODE_FLAT)->flatten()`) ritorna
 * direttamente la stringa .tex monolitica per i caller che ne hanno
 * bisogno (saveBatch single-blob storage, AdminPrintController, ecc.).
 */
final class TexBuilder
{
    public function __construct(
        private readonly EserciziBodyRenderer $eserciziRenderer = new EserciziBodyRenderer(),
    ) {
    }

    /**
     * G20.0 — build multi-file per una variant.
     *
     * @param Selection $sel
     * @param string $variant 'NORMAL'|'DSA'|'DYSLEXIC' (mappa a NOR/DSA/DIS)
     * @param array{
     *   mode: string,                // 'zip' | 'vsc'
     *   variant_kind?: string,        // 'NOR'|'SOL'|'DSA'|'DIS' (override esplicito)
     *   institute_code?: string,      // scope per cascade lookup, default '_default'
     *   institute_name?: string,      // {{ISTITUTO_NOME}} (header_label || name)
     *   docente_nome?: string,        // {{DOCENTE_NOME}}
     *   tempo_minuti?: int,           // {{TEMPO_MINUTI}}, default 55
     *   copie?: array{NOR?:int, DSA?:int, DIS?:int}
     * } $opts
     * @return BuildResult
     */
    public function build(Selection $sel, string $variant = VersionPicker::NORMAL, array $opts = []): BuildResult
    {
        $mode      = (string)($opts['mode'] ?? BuildResult::MODE_ZIP);
        $kind      = (string)($opts['variant_kind'] ?? self::variantToKind($variant));
        $scope     = (string)($opts['institute_code'] ?? TemplateFileStore::SCOPE_DEFAULT);
        $isSol     = ($kind === 'SOL') || !empty($sel->options['includeSolutions']);
        // G20.6 — compensa: opts wins; fallback a $sel->options['compensa']
        // (cosi' funziona anche per i caller ZIP/VSC che non lo passano via opts).
        if (!array_key_exists('compensa', $opts)) {
            $opts['compensa'] = !empty($sel->options['compensa']);
        }

        // 1. Render del body problemi → problemi_KIND.tex
        // G27.badge — Per SOL costruisco un EserciziBodyRenderer scoped che
        // include un BadgeRenderer caricato per la coppia (institute, teacher).
        // Il caller (VerificaDocumentService::saveBatch) passa teacher_id +
        // institute_id via opts. Se uno dei due manca, fallback al renderer
        // di default (no badge inline) → output identico a pre-G27.badge:
        // zero regression sui flow che non hanno ancora propagato gli id.
        //
        // $usedKeys cattura le source_key effettivamente referenziate dagli
        // items SOL: usato dopo per generare versioni/fonti_KIND.tex con
        // SOLO le \definefonte necessarie (no dump completo del registro).
        // G27.dsa.scope — marker (*F*)/(*GF*) emessi SOLO per kind DSA|DIS.
        // Su NOR (versione standard) e SOL (soluzionario docente) restano off.
        $emitDsaMarks = ($kind === 'DSA' || $kind === 'DIS');
        $eserciziRenderer = $this->eserciziRenderer;
        $badges    = null;
        $usedKeys  = [];
        if ($isSol) {
            $teacherId   = (int)($opts['teacher_id']   ?? 0);
            $instituteId = (int)($opts['institute_id'] ?? 0);
            if ($teacherId > 0 && $instituteId > 0) {
                $badges = BadgeRenderer::loadFor($instituteId, $teacherId);
                $eserciziRenderer = new EserciziBodyRenderer(
                    tables: new TableRenderer($badges, $emitDsaMarks),
                );
                $usedKeys = $badges->collectUsedKeys($sel);
            }
        } elseif ($emitDsaMarks) {
            // DSA/DIS senza badges (no SOL): renderer custom solo per marks.
            $eserciziRenderer = new EserciziBodyRenderer(
                tables: new TableRenderer(null, true),
            );
        }
        // G27.tikz.hoist — reset accumulator preamble TikZ PRIMA del render.
        // Il Sanitizer accumula \newcommand/\newif/\newenvironment/\usepackage
        // estratti dal preamble dei TikZ template (es. "poligono" dal dropdown
        // che usa macro custom \SetPoints, \RenderFigure ecc.). Senza hoisting
        // queste macro restavano undefined nel main_*.tex finale e le figure
        // non renderizzavano (silent fail: pdflatex compila ma PDF mostra solo
        // i nomi punto residui A,B,C,D senza disegno).
        // G27.tikz.hoist.preamble — il preamble raccolto viene iniettato in
        // main_*.tex via placeholder `{{TIKZ_PREAMBLE}}` (PRIMA di
        // \begin{document}, posizione corretta per macro LaTeX). Vedi step
        // PlaceholderResolver sotto. Il body esercizi resta pulito.
        Sanitizer::resetHoistedPreamble();
        $problemiBody = $eserciziRenderer->render($sel, $isSol);
        $problemiFile = "esercizi_$kind.tex";

        // 2. Read main_KIND.tex template via cascade (override istituto → _default)
        $mainTemplate = TemplateFileStore::read($scope, "versioni/main_$kind.tex");
        if ($mainTemplate === null) {
            throw new \RuntimeException("template_missing:versioni/main_$kind.tex");
        }

        // 3. Risolve placeholder
        $vars = $this->buildPlaceholders($sel, $kind, $mode, $opts);
        $resolver = PlaceholderResolver::fromContext($vars);
        $mainContent = $resolver->apply($mainTemplate);

        // 4. Read texCommon files (cascade)
        $texCommonFiles = [
            'texCommon/verifica.sty',
            'texCommon/intestazione.tex',
            'texCommon/ulteriori_misure.tex',
            'texCommon/BES_DSA/misure_dispensative.tex',
            'texCommon/BES_DSA/compensazione_orale.tex',
        ];
        $files = [];
        foreach ($texCommonFiles as $rel) {
            $content = TemplateFileStore::read($scope, $rel);
            if ($content === null) {
                continue;
            }
            // Risolvi placeholder anche dentro i texCommon (es. intestazione.tex
            // ha {{DOCENTE_NOME}}, {{CLASSE_LABEL}}, ecc.).
            $files[] = ['path' => $rel, 'content' => $resolver->apply($content)];
        }

        // 5. Read griglie file per (indirizzo, materia)
        // G20.6 — Compensa+(DSA|DIS): emette ANCHE la griglia compact, ottenuta
        // applicando la regex legacy `\fontsize{N}{M}\selectfont` →
        // `\fontsize{7}{2}\selectfont` su tutti i \fontsize interni alla
        // tabularx. main_DSA/DIS.tex referenzia la _compact via il
        // placeholder {{GRIGLIA_SUFFIX}} (default '', '_compact' se compensa).
        $ind = (string)($sel->iis ?? '');
        $mat = (string)($sel->mater ?? '');
        if ($ind !== '' && $mat !== '') {
            $grigliaPath = "griglie/{$ind}_{$mat}.tex";
            $grigliaContent = TemplateFileStore::read($scope, $grigliaPath);
            if ($grigliaContent !== null) {
                // Normalize baseline: i template legacy usano \fontsize{N}{2}
                // (baseline 2pt) che schiaccia il testo verso il top delle cell
                // m{...} di tabularx (perché la "line height" effettiva è ~2pt
                // → centro verticale skewed verso top). Sostituiamo con baseline
                // congruente al font size per centratura visuale corretta.
                $grigliaContent = self::normalizeGrigliaBaseline($grigliaContent);
                $files[] = ['path' => $grigliaPath, 'content' => $resolver->apply($grigliaContent)];
                $isCompactKind = ($kind === 'DSA' || $kind === 'DIS');
                if ($isCompactKind && !empty($opts['compensa'])) {
                    $compactPath = "griglie/{$ind}_{$mat}_compact.tex";
                    $compactContent = self::makeGrigliaCompact($grigliaContent);
                    $files[] = ['path' => $compactPath, 'content' => $resolver->apply($compactContent)];
                }
            } else {
                // Nessuna griglia configurata per questo scope (es. scope di test):
                // emetti file VUOTI così `\input{../griglie/{ind}_{mat}}` in
                // main_*.tex non fallisce fatale ("File not found"). Niente griglia
                // = sezione vuota, ma il PDF si genera.
                $emptyGrid = "% nessuna griglia configurata per {$ind}_{$mat}\n";
                $files[] = ['path' => $grigliaPath, 'content' => $emptyGrid];
                if (($kind === 'DSA' || $kind === 'DIS') && !empty($opts['compensa'])) {
                    $files[] = ['path' => "griglie/{$ind}_{$mat}_compact.tex", 'content' => $emptyGrid];
                }
            }
        }

        // 6. Add main + problemi
        $files[] = ['path' => "versioni/main_$kind.tex",     'content' => $mainContent];
        $files[] = ['path' => "versioni/$problemiFile",      'content' => $problemiBody];

        // G27.tikz.hoist — emetti SEMPRE versioni/tikz_preamble.tex (path
        // unico cross-variant) così `\input{tikz_preamble}` in main_*.tex
        // non fallisce in MODE_FLAT (bundle inlinato, no IfFileExists).
        // Il file contiene le macro `\providecommand`/`\newif`/`\newenvironment`
        // dedupate estratte dai TikZ template (es. "poligono" del dropdown).
        // Vuoto = nessun TikZ con preamble custom (caso comune).
        // G27.tikz.unified — Path UNICO (no _KIND suffix): il contenuto è
        // identico per tutte le varianti (depends solo dai TikZ in selection,
        // non da SOL/NOR/DSA/DIS), quindi 4 manifest entries → 1 entry.
        $tikzPreambleRaw = Sanitizer::collectHoistedPreamble();
        // G27.tikz.collision — annotazione: se due template diversi
        // definiscono la stessa macro con corpo differente, solo la prima
        // resta attiva (\providecommand idempotente). Le altre figure
        // potrebbero renderizzare con la logica del primo template.
        $collisions = Sanitizer::collectHoistedCollisions();
        $collisionsHeader = '';
        if (!empty($collisions)) {
            $collisionsHeader = "% [G27.tikz.collision] " . count($collisions) . " collision(s) rilevate:\n";
            foreach ($collisions as $c) {
                $collisionsHeader .= "%   - " . $c['macro'] . " definita da template diversi (solo la prima e' attiva)\n";
            }
        }
        $tikzPreambleContent = $tikzPreambleRaw !== ''
            ? "% G27.tikz.hoist — preamble macro estratte dai TikZ template\n"
              . $collisionsHeader
              . "\\makeatletter\n" . $tikzPreambleRaw . "\n\\makeatother\n"
            : "% [G27.tikz.hoist] nessun TikZ template con preamble custom\n";
        $files[] = [
            'path'    => 'versioni/tikz_preamble.tex',
            'content' => $tikzPreambleContent,
        ];

        // G27.badge — Per SOL emetti SEMPRE versioni/fonti_KIND.tex (anche
        // vuoto / solo commento) cosi' `\input{fonti_SOL}` in main_SOL.tex
        // non fallisce. Filtro su collectUsedKeys → emette \definefonte solo
        // per le fonti referenziate. Se BadgeRenderer non attivo (no
        // teacher_id/institute_id) → file segnaposto con commento.
        //
        // G27.badge.style — PRIMA delle \definefonte aggiungi il blocco
        // \fmsetfonte{...}\fmsetbadge{...} risolto da BadgeStyleRepository
        // (preset admin + override docente). Cosi' tutti i \badge e
        // \fontebox del documento ereditano lo stile teacher-scoped.
        if ($isSol) {
            $stylePreamble = '';
            if ($badges !== null && $teacherId > 0 && $instituteId > 0) {
                $instituteCode = (string)($opts['institute_code'] ?? \App\Services\TexBuilder\BadgeStylePresetStore::SCOPE_DEFAULT);
                $resolvedStyle = \App\Services\TexBuilder\BadgeStyleRepository::loadResolved(
                    $instituteId,
                    $teacherId,
                    $instituteCode,
                );
                $stylePreamble = $resolvedStyle->toLatexPreamble();
            }
            $fontiContent = $badges !== null
                ? $stylePreamble . $badges->renderFontiPreamble($usedKeys)
                : "% [G27.badge] BadgeRenderer non disponibile (manca teacher_id/institute_id)\n";
            $files[] = [
                'path'    => "versioni/fonti_$kind.tex",
                'content' => $fontiContent,
            ];
        }

        return new BuildResult(
            files:   $files,
            mode:    $mode,
            variant: $kind,
            meta:    ['scope' => $scope, 'indirizzo' => $ind, 'materia' => $mat],
        );
    }

    /**
     * G22.S4 — Produce un .tex monolitico self-contained (single-file)
     * passando per il pipeline build(MODE_FLAT) + flatten().
     *
     * Il risultato include preamble (verifica.sty inlined), intestazione,
     * esercizi, ulteriori_misure, BES_DSA (per DSA/DIS), griglia di
     * valutazione — tutto unito in un solo file compilabile da pdflatex
     * senza dipendenze esterne.
     *
     * Singola fonte di verita': tutti i template in storage/templates/
     * verifiche/{scope}/... — niente piu' VersionPicker::preamble +
     * applyTemplate post-process.
     *
     * Usato da:
     *   - VerificaDocumentService::saveBatch (blob singolo per row)
     *   - VerificaController::saveTex (single-doc save)
     *   - AdminPrintController::generate
     *   - TeacherPrintController::print
     *   - Endpoint download /api/verifica/{id}/tex
     */
    public function buildFlat(
        Selection $sel,
        string $variant = VersionPicker::NORMAL,
        array $opts = [],
    ): string {
        $opts['mode'] = BuildResult::MODE_FLAT;
        $tex = $this->build($sel, $variant, $opts)->flatten();
        // Best-effort latexindent via VPS /format-tex. Disattivabile con
        // $opts['format'] === false (per test / batch dove la latenza pesa).
        if (($opts['format'] ?? true) !== false) {
            $tex = self::tryFormat($tex);
        }
        return $tex;
    }

    /**
     * Best-effort pass-through latexindent (VPS /format-tex). Cache sha256-
     * keyed (latexindent e' deterministico). Errori/network/config mancante
     * → ritorna raw (no-op, non bloccante).
     */
    private static function tryFormat(string $tex): string
    {
        // 1. Cache hit: stesso input → stesso output (latexindent deterministic).
        //    Cache disk in storage/cache/tex_format/<sha2>/<sha>.tex.
        $sha = hash('sha256', $tex);
        $cacheDir = (string) Config::get('app.paths.storage', dirname(__DIR__, 2) . '/storage')
                  . '/cache/tex_format/' . substr($sha, 0, 2);
        $cachePath = $cacheDir . '/' . $sha . '.tex';
        if (is_file($cachePath)) {
            $cached = @file_get_contents($cachePath);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $endpoint = (string) Config::get('tex_compile.endpoint', '');
        $secret   = (string) Config::get('tex_compile.secret', '');
        if ($endpoint === '' || $secret === '') {
            return $tex;
        }
        try {
            $client = new TexFormatClient(
                endpoint:       $endpoint,
                secret:         $secret,
                timeoutSeconds: 12,
                caBundle:       (string) Config::get('tex_compile.ca_bundle', ''),
            );
            $r = $client->format($tex, 'tex_builder_flat');
            if (!empty($r['ok']) && \is_string($r['formatted'] ?? null) && $r['formatted'] !== '') {
                $formatted = $r['formatted'];
                // 2. Cache write best-effort: errore disco → return formatted ok comunque.
                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0o775, true);
                }
                if (is_dir($cacheDir)) {
                    $tmp = $cachePath . '.tmp.' . bin2hex(random_bytes(4));
                    if (@file_put_contents($tmp, $formatted, LOCK_EX) !== false) {
                        @rename($tmp, $cachePath) || @unlink($tmp);
                    }
                }
                return $formatted;
            }
        } catch (Throwable) {
            // fallthrough → raw
        }
        return $tex;
    }

    public function buildAll(Selection $sel, array $opts = []): array
    {
        $out = [];
        foreach (VersionPicker::VARIANTS as $variant) {
            $out[$variant] = $this->buildFlat($sel, $variant, $opts);
        }
        return $out;
    }

    /** Mappa variant code → kind file (`NORMAL` → `NOR`, ecc). */
    public static function variantToKind(string $variant): string
    {
        return match ($variant) {
            VersionPicker::DSA      => 'DSA',
            VersionPicker::DYSLEXIC => 'DIS',
            default                 => 'NOR',
        };
    }

    /** @return array<string,scalar> */
    private function buildPlaceholders(Selection $sel, string $kind, string $mode, array $opts): array
    {
        $copie = (array)($opts['copie'] ?? []);
        // G27.text.escape — escape `_/&/#/%` per evitare math subscript
        // artifact (es. "test_ver" → "test\_ver" → "test_ver" letterale in PDF).
        $verTitleEsc = strtr((string)$sel->verTitle, ['_' => '\_', '&' => '\&', '#' => '\#', '%' => '\%']);
        $vars = [
            'TITOLO_VERIFICA' => $verTitleEsc,
            'INDIRIZZO_CODE'  => (string)$sel->iis,
            'INDIRIZZO_LABEL' => self::indirizzoLabel((string)$sel->iis),
            'CLASSE_LABEL'    => (string)$sel->cls,
            'MATERIA_CODE'    => (string)$sel->mater,
            'ANNO'            => (string)$sel->anno,
            'TEMPO_MINUTI'    => (string)($opts['tempo_minuti'] ?? 55),
            'DOCENTE_NOME'    => (string)($opts['docente_nome'] ?? ''),
            'ISTITUTO_NOME'   => (string)($opts['institute_name'] ?? ''),
            'ESERCIZI_FILE'   => "esercizi_$kind",
            'COPIE_NOR'       => (string)($copie['NOR'] ?? 1),
            'COPIE_DSA'       => (string)($copie['DSA'] ?? 0),
            'COPIE_DIS'       => (string)($copie['DIS'] ?? 0),
            'COMPENSA_OPEN'   => empty($opts['compensa']) ? '%' : '',
            'COMPENSA_CLOSE'  => '',
            // G20.6 — suffix file griglia: '_compact' su DSA/DIS quando
            // Compensa attivo (legacy parity: griglia con \fontsize{7}{2}
            // per liberare spazio al blocco compensazione orale).
            'GRIGLIA_SUFFIX'  => (
                !empty($opts['compensa']) && in_array($kind, ['DSA', 'DIS'], true)
            ) ? '_compact' : '',
        ];
        $vars += PlaceholderResolver::pathPrefixes($mode);
        return $vars;
    }

    /**
     * G20.6 — Trasforma il TEX di una griglia di valutazione nella sua
     * variante "compact" da affiancare al blocco compensazione.
     *
     * Adatta da regex legacy (functions-mod.js linea 13239) ma con baseline
     * più generosa per evitare overlap testo/separator nella prima riga:
     *   `\fontsize{N}{M}\selectfont`  →  `\fontsize{7}{8}\selectfont`
     * Legacy era 7/2 (baseline 2pt), troppo stretta su font 7pt.
     *
     * In aggiunta abbassiamo `\arraystretch` (se presente) per ridurre
     * ulteriormente l'altezza delle righe del tabularx.
     */
    private static function makeGrigliaCompact(string $content): string
    {
        // Regex copre numeri interi e decimali (es. "8.5", "9", "10.0").
        $content = preg_replace(
            '/\\\\fontsize\\{[0-9.]+\\}\\{[0-9.]+\\}\\\\selectfont/',
            '\\fontsize{7}{8}\\selectfont',
            $content,
        ) ?? $content;
        // Riduci arraystretch top-level (se presente con valore > 1.0).
        $content = preg_replace(
            '/\\\\renewcommand\\{\\\\arraystretch\\}\\{[1-9](?:\\.[0-9]+)?\\}/',
            '\\renewcommand{\\arraystretch}{0.85}',
            $content,
        ) ?? $content;
        // NB: firma "Comune Esempio,_/_/2026 Docente:_ Voto:_/10" mantenuta anche in
        // compact (richiesta utente). Compensazione_orale.tex incluso SOTTO
        // ha sue Firma Studente/Docente: 2 blocchi distinti (voto verifica +
        // esito compensazione). Trade-off: potrebbe overflow su pag 2 se
        // contenuto totale eccede.
        return $content;
    }

    /**
     * Normalize baseline dei \fontsize delle griglie: i template legacy usano
     * `\fontsize{N}{2}` (M=2pt) che è LEGGIBILE (no overlap) ma centra male in
     * cell `m{...}` perché baseline 2pt < font ascender. Sostituiamo M=2 con
     * M=N+1 (line spacing tight ma proporzionato): centra correttamente +
     * mantiene compatness (griglia su 1 pagina come legacy).
     *
     * Trasformazioni:
     *   \fontsize{N}{2}   → \fontsize{N}{N+1}  (es. 9/2 → 9/10, 8.5/2 → 8.5/10)
     *   \fontsize{N}{M}   con M >= 3 → invariato (assumiamo già custom).
     */
    private static function normalizeGrigliaBaseline(string $content): string
    {
        // 1) Baseline fix: \fontsize{N}{2} → \fontsize{N}{N+1}
        $content = (string)preg_replace_callback(
            '/\\\\fontsize\\{([0-9.]+)\\}\\{2\\}\\\\selectfont/',
            static function (array $m): string {
                $size = (float)$m[1];
                $baseline = (int)ceil($size + 1);
                return '\\fontsize{' . $m[1] . '}{' . $baseline . '}\\selectfont';
            },
            $content,
        );
        // 2) Riduce vspace finali (post-tabularx) per liberare spazio firma.
        // Template legacy ha \vspace{0.5cm} prima della firma → risparmiato +
        // \nopagebreak forza firma same-page.
        $content = (string)preg_replace(
            '/\\\\vspace\\{0\\.5cm\\}\\s*\\n\\s*\\n(Comune Esempio,)/',
            "\\vspace{0.2cm}\n\\nopagebreak\n$1",
            $content,
        );
        // 3) \enlargethispage prima di TOTALE: per dare ~1 cm extra alla pagina
        //    griglia (evita che firma slitti).
        $content = (string)preg_replace(
            '/(\\\\vspace\\{0\\.2cm\\}\\s*\\n\\s*\\\\hspace\\{14\\.2cm\\} TOTALE)/',
            "\\enlargethispage{2\\baselineskip}\n$1",
            $content,
        );
        return $content;
    }

    private static function indirizzoLabel(string $code): string
    {
        // G22.S15.bis Fase 5+ — code modernizzati 3-6 lettere uppercase.
        // Le legacy lowercase ('sc','ar','ling') restano qui per back-compat
        // con risorse non ancora migrate (ma il refactor le ha gia' aggiornate
        // alle nuove SCI/ART/LIN nel batch principale).
        return match (strtoupper($code)) {
            'SCI', 'SC' => 'Scientifico',
            'ART', 'AR' => 'Artistico',
            'CLA', 'LC' => 'Classico',
            'LIN', 'LING' => 'Linguistico',
            'AFM' => 'AFM',
            'MUS', 'MU' => 'Musicale',
            'SP' => 'Sportivo',
            'TEC' => 'Tecnico',
            'PROF' => 'Professionale',
            default => ucfirst(strtolower($code)),
        };
    }
}
