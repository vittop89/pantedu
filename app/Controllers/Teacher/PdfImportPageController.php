<?php

declare(strict_types=1);

namespace App\Controllers\Teacher;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\PdfImport\PdfRasterizer;
use App\Services\PdfImport\Provider\ProviderRouter;
use App\Support\AuthHelpers;

/**
 * Phase PDF-Import — pagina dedicata /teacher/pdf-import (teacher-only).
 *
 * Renderizza la shell app (sidebar + bootstrap → window.FM disponibile) con il
 * markup del tool e l'entry Vite `js/entries/pdf-import.js`. Tutta la logica di
 * upload/poll/review/insert vive nel client; gli endpoint REST sono in
 * PdfImportController.
 */
final class PdfImportPageController
{
    /** Imposta il contesto per-docente (config chiavi/modelli/… privata). */
    private function ctxTeacherId(): int
    {
        $username = AuthHelpers::teacherUsernameOrThrow();
        $id = (int)\App\Support\TeacherContextResolver::userIdFromUsername($username);
        // Il render della pagina non scrive config (lo scope global/personal lo
        // gestiscono le chiamate API via ?scope=). Qui basta il teacher.
        \App\Services\PdfImport\PdfImportContext::setTeacher(max(0, $id), false);
        return $id;
    }

    public function page(Request $req): Response
    {
        AuthHelpers::assertTeacherOrAdmin();
        $this->ctxTeacherId();

        $enabled   = (bool)Config::get('pdf_import.enabled', false);
        $router    = new ProviderRouter();
        $providers = $enabled ? $router->availableProviders() : [];
        $raster    = new PdfRasterizer();
        $rasterOk  = $raster->available();
        // Config PDF-Import = PRIVATA per docente → la UI (chiavi/modelli/cache/
        // automatismi) è disponibile a TUTTI i docenti, ciascuno per la propria.
        $canCfg    = $enabled;
        $cacheOn   = $enabled && (new \App\Services\PdfImport\LlmCache())->enabled();
        $autoSet   = (new \App\Services\PdfImport\PdfImportSettings())->status();

        $content = $this->buildContent($enabled, $providers, $rasterOk, $canCfg, $cacheOn, $autoSet);
        $script  = \App\Support\ViteManifest::script('js/entries/pdf-import.js');

        // Pagina STANDALONE (niente sidebar/topbar dell'app): è un tool che si
        // apre in nuova scheda. Carica solo il CSS bundle + l'entry Vite. Il dark
        // segue prefers-color-scheme dei token; l'entry applica anche body.fm-dark
        // da localStorage. Nessuno script inline (CSP-safe).
        $base = dirname(__DIR__, 3);
        $cssHref = '/css/main.css';
        $bundle = $base . '/css/main.bundle.css';
        if (is_file($bundle)) {
            $cssHref = '/css/main.bundle.css?v=' . (int)@filemtime($bundle);
        }
        $cssHrefH = htmlspecialchars($cssHref, ENT_QUOTES);

        // Config MathJax (il loader lo carica on-demand l'entry al primo preview).
        ob_start();
        include $base . '/views/partials/_mathjax_loader.php';
        $mathjax = (string)ob_get_clean();

        $html = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Importa esercizi da PDF</title>
    <link rel="stylesheet" href="{$cssHrefH}" type="text/css">
    <style>html,body{margin:0;padding:0;background:var(--fm-c-bg,#f5f7fb);min-height:100vh;}</style>
    {$mathjax}
</head>
<body class="fm-pdfimport-page">
{$content}
{$script}
</body>
</html>
HTML;

        return Response::html($html)->withNoCache();
    }

    /**
     * Pagina dedicata "Modelli per operazione" (admin): assegna un modello LLM a
     * ciascuna operazione (estrazione, numeri/pagina, argomenti, traduzione,
     * soluzioni). Standalone come page(); il client costruisce la tabella.
     */
    public function modelsPage(Request $req): Response
    {
        AuthHelpers::assertTeacherOrAdmin();
        $this->ctxTeacherId();

        $enabled = (bool)Config::get('pdf_import.enabled', false);
        // Config per-docente → pagina /models disponibile a TUTTI i docenti.
        if (!$enabled) {
            return Response::html(
                '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><title>Modelli</title></head>'
                . '<body style="font-family:system-ui;padding:2rem">Funzione non disponibile.</body></html>'
            )->withNoCache();
        }

        $script = \App\Support\ViteManifest::script('js/entries/pdf-import-models.js');

        $base = dirname(__DIR__, 3);
        $cssHref = '/css/main.css';
        $bundle = $base . '/css/main.bundle.css';
        if (is_file($bundle)) {
            $cssHref = '/css/main.bundle.css?v=' . (int)@filemtime($bundle);
        }
        $cssHrefH = htmlspecialchars($cssHref, ENT_QUOTES);

        $content = <<<HTML
<div class="fm-pdfimport" data-fm-models-page>
    <header class="fm-pdfimport__header">
        <div class="fm-pdfimport__headrow">
            <h1 class="fm-pdfimport__title">Modelli LLM per operazione</h1>
            <a class="fm-pdfimport__btn fm-pdfimport__btn--sm" href="/area-docente/pdf-import" data-fm-back>← Torna all'import</a>
        </div>
        <p class="fm-pdfimport__sub">Assegna un modello a ciascuna operazione. Vuoto = usa il
        modello di default del provider scelto nell'import. Per l'estrazione (testo, colori,
        difficoltà) conviene un modello vision forte; per traduzione/soluzioni uno più rapido.</p>
    </header>
    <div class="fm-pdfimport__status" data-fm-models-msg hidden role="status" aria-live="polite"></div>
    <div data-fm-models-list>Caricamento…</div>
</div>
HTML;

        $html = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Modelli LLM per operazione</title>
    <link rel="stylesheet" href="{$cssHrefH}" type="text/css">
    <style>html,body{margin:0;padding:0;background:var(--fm-c-bg,#f5f7fb);min-height:100vh;}</style>
</head>
<body class="fm-pdfimport-page">
{$content}
{$script}
</body>
</html>
HTML;

        return Response::html($html)->withNoCache();
    }

    /** @param list<string> $providers @param array<string,bool> $autoSet */
    private function buildContent(bool $enabled, array $providers, bool $rasterOk, bool $isAdmin = false, bool $cacheOn = false, array $autoSet = []): string
    {
        $ck = static fn(string $k): string => ($autoSet[$k] ?? true) ? ' checked' : '';
        // Bottone admin "Chiavi LLM" (solo se feature attiva + admin).
        $cacheChecked = $cacheOn ? ' checked' : '';
        $keysBtn = ($enabled && $isAdmin)
            ? '<button type="button" class="fm-pdfimport__btn fm-pdfimport__btn--sm" data-fm-keys '
              . 'title="Configura le chiavi API dei provider LLM">⚙ Chiavi LLM</button>'
              . '<a class="fm-pdfimport__btn fm-pdfimport__btn--sm" href="/area-docente/templates?tab=pdf-import" '
              . 'title="Modelli e prompt per operazione (le tue impostazioni)">⚙ Modelli per operazione</a>'
              . '<label class="fm-pdfimport__btn fm-pdfimport__btn--sm" style="cursor:pointer" '
              . 'title="Cache risposte LLM: ri-estrarre lo stesso PDF non ri-chiama l\'LLM (0 token)">'
              . '<input type="checkbox" data-fm-cache' . $cacheChecked . '> Cache LLM</label>'
            : '';

        // Header sempre presente (anche se feature off / no provider).
        $header = '<header class="fm-pdfimport__header">'
            . '<div class="fm-pdfimport__headrow">'
            . '<h1 class="fm-pdfimport__title">Importa esercizi da PDF</h1>'
            . $keysBtn
            . '</div>'
            . '<p class="fm-pdfimport__sub">Carica un PDF (libro/verifica scannerizzata): il sistema '
            . 'estrae gli esercizi, tu li rivedi e li inserisci come bozze nei tuoi contenuti.</p>'
            . '<div class="fm-pdfimport__source" data-fm-source-info hidden></div>'
            . '</header>';

        if (!$enabled) {
            return '<div class="fm-pdfimport" data-fm-pdfimport>' . $header
                . '<div class="fm-pdfimport__notice fm-pdfimport__notice--warn">'
                . 'La funzione di importazione da PDF è disattivata. '
                . 'Contatta l’amministratore (PDF_IMPORT_ENABLED).</div></div>';
        }
        if ($providers === []) {
            return '<div class="fm-pdfimport" data-fm-pdfimport>' . $header
                . '<div class="fm-pdfimport__notice fm-pdfimport__notice--warn">'
                . 'Nessun provider LLM configurato. Imposta almeno una chiave '
                . '(PDF_IMPORT_ANTHROPIC_KEY / PDF_IMPORT_OPENAI_KEY) o un endpoint Ollama.'
                . '</div></div>';
        }

        $opts = '';
        $labels = ['anthropic' => 'Claude (Anthropic)', 'openai' => 'OpenAI', 'qwen' => 'Qwen (Alibaba Model Studio)', 'openrouter' => 'OpenRouter', 'ollama' => 'Ollama locale'];
        foreach ($providers as $p) {
            $label = htmlspecialchars($labels[$p] ?? $p, ENT_QUOTES);
            $opts .= '<option value="' . htmlspecialchars($p, ENT_QUOTES) . '">' . $label . '</option>';
        }

        $rasterWarn = $rasterOk ? '' :
            '<div class="fm-pdfimport__notice fm-pdfimport__notice--warn">'
            . 'Rasterizzatore PDF non disponibile sul server (serve Imagick+Ghostscript o pdftoppm). '
            . 'L’upload risponderà 503 finché non viene installato.</div>';

        // Checkbox "auto" (solo admin) accanto alle operazioni automatiche.
        $autoChk = static function (string $k) use ($isAdmin, $ck): string {
            if (!$isAdmin) {
                return '';
            }
            return '<label class="fm-pdfimport__autochk" title="Esegui automaticamente a fine estrazione">'
                . '<input type="checkbox" data-fm-auto="' . $k . '"' . $ck($k) . '> auto</label>';
        };
        $chkTopics = $autoChk('auto_topics');
        $chkDiff   = $autoChk('auto_difficulty');
        $chkTransl = $autoChk('auto_translation');

        // Barra operazioni + destinazione + inserimento (spostata IN ALTO).
        $opsbar = <<<BAR
    <section class="fm-pdfimport__insertbar" data-fm-insertbar hidden aria-label="Operazioni e inserimento">
        <span class="fm-pdfimport__label" data-fm-dest-label>Destinazione (bozze):</span>
        <span class="fm-pdfimport__destinfo" data-fm-dest-info hidden></span>
        <span class="fm-pdfimport__destcodes" data-fm-dest-codes>
            <input type="text" class="fm-pdfimport__input fm-pdfimport__input--sm" data-fm-ctx-indirizzo placeholder="indirizzo (codice)">
            <input type="text" class="fm-pdfimport__input fm-pdfimport__input--sm" data-fm-ctx-classe placeholder="classe (codice)">
            <input type="text" class="fm-pdfimport__input fm-pdfimport__input--sm" data-fm-ctx-subject placeholder="materia (codice)">
        </span>
        {$chkTopics}<button type="button" class="fm-pdfimport__btn" data-fm-gen-topics>Argomento automatico</button>
        {$chkDiff}<button type="button" class="fm-pdfimport__btn" data-fm-refine-diff title="Rilegge la difficoltà dai pallini con un modello vision dedicato (qwen-vl)">Ricalcola difficoltà ●●○</button>
        {$chkTransl}<button type="button" class="fm-pdfimport__btn" data-fm-translate>Traduci in italiano</button>
        <button type="button" class="fm-pdfimport__btn" data-fm-gen-solutions>Genera soluzioni AI</button>
        <button type="button" class="fm-pdfimport__btn" data-fm-preview>Genera anteprima</button>
        <button type="button" class="fm-pdfimport__btn fm-pdfimport__btn--primary" data-fm-insert>Inserisci selezionati</button>
    </section>
BAR;

        // Markup statico; la tabella e i dettagli sono costruiti dal client.
        return <<<HTML
<div class="fm-pdfimport" data-fm-pdfimport>
    $header

    $rasterWarn

    <section class="fm-pdfimport__upload" aria-label="Caricamento PDF">
        <label class="fm-pdfimport__field">
            <span class="fm-pdfimport__label">Provider</span>
            <select class="fm-pdfimport__select" data-fm-provider>$opts</select>
        </label>
        <label class="fm-pdfimport__field fm-pdfimport__field--file">
            <span class="fm-pdfimport__label">File PDF</span>
            <input type="file" accept="application/pdf,.pdf" class="fm-pdfimport__file" data-fm-file>
        </label>
        <label class="fm-pdfimport__field">
            <span class="fm-pdfimport__label">Fonte (preset)</span>
            <select class="fm-pdfimport__select" data-fm-source-preset
                    title="Fonte da pre-impostare su tutti gli esercizi estratti (modificabile per riga)">
                <option value="">— nessuna —</option>
            </select>
        </label>
        <button type="button" class="fm-pdfimport__btn fm-pdfimport__btn--primary" data-fm-extract>
            Estrai esercizi
        </button>
        <button type="button" class="fm-pdfimport__btn fm-pdfimport__btn--ghost" data-fm-stop hidden
                title="Interrompi l'estrazione in corso">⨯ Stop</button>
    </section>

    <!-- Avviso privacy (GDPR): col provider CLOUD il PDF/le pagine vengono inviate a
         un servizio esterno. Codici fiscali ed email sono già redatti (PiiMasker), ma
         i NOMI propri no → per PDF con dati personali si consiglia Ollama locale. -->
    <div class="fm-pdfimport__notice fm-pdfimport__notice--info" data-fm-privacy hidden>
        ⚠️ Le pagine del PDF vengono inviate a un <strong>provider esterno (cloud)</strong> per l'analisi.
        Codici fiscali ed email sono oscurati automaticamente, ma <strong>i nomi propri no</strong>:
        per documenti con dati personali di studenti usa un <strong>modello locale (Ollama)</strong>.
    </div>

    <div class="fm-pdfimport__status" data-fm-status hidden role="status" aria-live="polite"></div>

    <!-- Log delle operazioni LLM (estrazione/argomenti/traduzione/soluzioni):
         tempi, esiti, token, errori/ritentativi. Si popola dal polling. -->
    <details class="fm-pdfimport__log" data-fm-log-wrap hidden>
        <summary>Log operazioni LLM <span class="fm-pdfimport__log-count" data-fm-log-count></span></summary>
        <div class="fm-pdfimport__log-body" data-fm-log></div>
    </details>

    $opsbar

    <div class="fm-pdfimport__workspace" data-fm-workspace hidden>
        <div class="fm-pdfimport__main">
            <div class="fm-pdfimport__bulkbar" data-fm-bulkbar hidden>
                <span class="fm-pdfimport__bulkinfo" data-fm-bulkinfo>0 selezionati</span>
                <select class="fm-pdfimport__select fm-pdfimport__select--md" data-fm-bulk-field>
                    <option value="number">N°</option>
                    <option value="page">Pag</option>
                    <option value="type">Tipo</option>
                    <option value="badge_color">Colore</option>
                    <option value="difficulty">Difficoltà</option>
                    <option value="topic">Argomento</option>
                    <option value="origin">Origine</option>
                    <option value="container">Contenitore</option>
                </select>
                <!-- Controllo valore dinamico: input o select secondo il campo. -->
                <span class="fm-pdfimport__bulkval" data-fm-bulk-value-wrap></span>
                <button type="button" class="fm-pdfimport__btn fm-pdfimport__btn--sm" data-fm-bulk-apply>Applica</button>
                <button type="button" class="fm-pdfimport__btn fm-pdfimport__btn--sm fm-pdfimport__btn--ghost" data-fm-bulk-clear>Deseleziona</button>
            </div>
            <div class="fm-pdfimport__tablewrap">
                <table class="fm-pdfimport__table" data-fm-table>
                    <thead>
                        <tr>
                            <th class="fm-pdfimport__th--sel"><input type="checkbox" data-fm-selall aria-label="Seleziona tutto"></th>
                            <th>Pag</th><th>N°</th><th>Tipo</th><th>Colore</th><th>Diff</th>
                            <th>Argomento</th><th>Origine</th><th>Contenitore</th><th>Flag</th>
                        </tr>
                    </thead>
                    <tbody data-fm-tbody></tbody>
                </table>
            </div>
        </div>
        <div class="fm-pdfimport__resizer" data-fm-resizer role="separator" aria-orientation="vertical"
             title="Trascina per ridimensionare tabella / anteprima PDF" tabindex="0" aria-label="Ridimensiona"></div>
        <aside class="fm-pdfimport__side" data-fm-side aria-label="Anteprima pagina">
            <div class="fm-pdfimport__sidenav">
                <button type="button" class="fm-pdfimport__btn fm-pdfimport__btn--sm" data-fm-page-prev>‹</button>
                <span class="fm-pdfimport__pageinfo" data-fm-pageinfo>—</span>
                <button type="button" class="fm-pdfimport__btn fm-pdfimport__btn--sm" data-fm-page-next>›</button>
            </div>
            <div class="fm-pdfimport__sideimg" data-fm-sideimg></div>
        </aside>
    </div>

    <!-- Anteprima esercizio renderizzata (LaTeX) come apparirà una volta inserito.
         Si popola cliccando una riga della tabella. -->
    <section class="fm-pdfimport__render" data-fm-render hidden aria-label="Anteprima esercizio renderizzata">
        <h2 class="fm-pdfimport__render-title">Anteprima esercizio <span class="fm-pdfimport__render-hint">— clicca una riga</span></h2>
        <div class="fm-pdfimport__render-body" data-fm-render-body></div>
    </section>
</div>
HTML;
    }
}
