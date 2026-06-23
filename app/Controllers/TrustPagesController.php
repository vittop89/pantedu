<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Support\StandalonePageRenderer;

/**
 * Phase 25.E8 — Trust pages pubbliche per trasparenza GDPR + sicurezza.
 *
 * Endpoint:
 *   GET /security              → panoramica architettura sicurezza
 *   GET /privacy/your-data     → dashboard self-service per data subject autenticato
 *   GET /privacy/informativa   → render informativa.md (markdown → HTML)
 *
 * Tutte pagine pubbliche (senza auth) per visibilità conformità.
 */
final class TrustPagesController
{
    /**
     * GET /security — panoramica misure tecniche.
     */
    public function security(Request $req): Response
    {
        $body = <<<'HTML'
            <h1>Sicurezza tecnica — Pantedu</h1>
            <p>Questa pagina riassume le misure tecniche (Art. 32 GDPR) implementate per proteggere i tuoi dati.</p>

            <h2>🔐 Cifratura at-rest (Phase 25.D)</h2>
            <ul>
                <li><strong>Algoritmo</strong>: AES-256-GCM (NIST SP 800-38D), authenticated encryption.</li>
                <li><strong>Architettura</strong>: envelope encryption con per-teacher KEK derivata via HKDF-SHA256 da KMS_MASTER_KEY off-line.</li>
                <li><strong>Crypto-shredding O(1)</strong>: cancellazione 1 row teacher_keys = tutti i body cifrati immediatamente illeggibili.</li>
                <li><strong>Backup</strong>: anche i backup del database restano illeggibili senza la chiave master.</li>
            </ul>

            <h2>🛡️ Sicurezza in transito</h2>
            <ul>
                <li><strong>HTTPS obbligatorio</strong> in produzione (HSTS 1 anno, includeSubDomains).</li>
                <li><strong>CSP rigorosa</strong>: prevenzione XSS + frame injection.</li>
                <li><strong>SameSite=Lax</strong> sui cookie sessione.</li>
                <li><strong>CSRF token</strong> rotation su ogni mutazione.</li>
            </ul>

            <h2>🔑 Autenticazione</h2>
            <ul>
                <li><strong>Password</strong>: bcrypt cost 12 (resistant a rainbow tables + brute-force GPU).</li>
                <li><strong>Rate-limiting</strong>: 10/min/IP su /login (anti-brute-force).</li>
                <li><strong>Session rotation</strong>: ID rigenerato a ogni privilege change.</li>
            </ul>

            <h2>📊 Audit & monitoring</h2>
            <ul>
                <li><strong>privileged_access_log</strong>: append-only, ogni azione admin loggata con motivazione obbligatoria.</li>
                <li><strong>crypto_access_log</strong>: tracking di ogni encrypt/decrypt/shred (Art. 5 §2 accountability).</li>
                <li><strong>consent_audit</strong>: storia immutabile di consent grant/revoke.</li>
                <li><strong>Pseudonimizzazione IP/UA</strong>: hash SHA-256, no PII raw.</li>
            </ul>

            <h2>🔒 Isolation per-utente</h2>
            <ul>
                <li>Ogni docente vede SOLO i propri contenuti (verificato con test E2E concurrent multi-teacher).</li>
                <li>Super-admin tecnici NON hanno accesso automatico ai contenuti dei docenti — il KMS_MASTER è off-line.</li>
                <li>Permission system per-template + multi-instance fork isolato per teacher_id.</li>
            </ul>

            <h2>🚨 Data breach response</h2>
            <ul>
                <li>Notifica al Garante entro <strong>72h</strong> (Art. 33 GDPR).</li>
                <li>Notifica utenti se rischio elevato (Art. 34 GDPR).</li>
                <li>Drill semestrale documentato (Phase 25.C12).</li>
            </ul>

            <p class="fm-trust-meta">
                <a href="/privacy/your-data">Esercita i tuoi diritti GDPR</a> ·
                <a href="/dpo-contact">Contatta il DPO</a> ·
                <a href="/privacy/informativa">Informativa privacy</a>
            </p>
            HTML;

        return Response::html($this->renderPage('Sicurezza — Pantedu', $body));
    }

    /**
     * GET /privacy/your-data — link hub ai diritti GDPR (utenti autenticati
     * usano endpoint /me/*; non autenticati vedono il flow di accesso).
     */
    public function yourData(Request $req): Response
    {
        $isAuth = \App\Core\Auth::check();
        $body = '<h1>I tuoi dati</h1>'
              . '<p>Questa pagina elenca tutti i diritti che puoi esercitare sui tuoi dati personali (GDPR Art. 15-22).</p>';

        if ($isAuth) {
            $body .= '<h2>Self-service (utente autenticato)</h2>'
                   . '<ul>'
                   . '<li><a href="/me/consents">Gestisci i tuoi consensi</a> (Art. 7)</li>'
                   . '<li><a href="/me/export-data">Scarica i tuoi dati</a> (Art. 20 — portabilità, JSON)</li>'
                   . '<li><a href="/me/profile">Modifica profilo</a> (Art. 16 — rettifica)</li>'
                   . '<li><a href="/me/request-deletion">Cancella account</a> (Art. 17 — oblio con cooling-off 30g)</li>'
                   . '</ul>';
        } else {
            $body .= '<h2>Sei già registrato? <a href="/login">Accedi</a> per usare il self-service.</h2>'
                   . '<p>Una volta loggato puoi:</p>'
                   . '<ul>'
                   . '<li>Gestire i tuoi consensi (analytics, marketing)</li>'
                   . '<li>Scaricare tutti i tuoi dati in JSON</li>'
                   . '<li>Modificare profilo</li>'
                   . '<li>Richiedere la cancellazione completa dell\'account (con crypto-shredding)</li>'
                   . '</ul>';
        }

        $body .= '<h2>Contatto DPO</h2>'
               . '<p>Per richieste che non hanno endpoint self-service o se non riesci ad accedere al tuo account:</p>'
               . '<p><a class="fm-btn" href="/dpo-contact">📧 Contatta il DPO</a></p>'
               . '<p>Risponderemo entro 30 giorni (Art. 12 §3 GDPR).</p>'

               . '<h2>Reclamo al Garante</h2>'
               . '<p>Se non sei soddisfatto della nostra risposta puoi presentare reclamo al '
               . '<a href="https://www.garanteprivacy.it" target="_blank">Garante per la protezione dei dati personali</a>.</p>'

               . '<p class="fm-trust-meta">'
               . '<a href="/privacy/informativa">Informativa privacy completa</a> · '
               . '<a href="/security">Misure di sicurezza tecniche</a></p>';

        return Response::html($this->renderPage('I tuoi dati — Pantedu', $body));
    }

    /**
     * GET /privacy/informativa — render markdown informativa.md → HTML
     * minimale (no template engine necessario, conversione semplice).
     */
    public function informativa(Request $req): Response
    {
        return $this->renderMarkdownPage(
            dirname(__DIR__, 2) . '/docs/privacy/informativa.md',
            'Informativa privacy — Pantedu'
        );
    }

    /**
     * Phase 25.Q — pagine legali pubbliche aggiuntive (ToS, AUP, Takedown, DPA).
     * Renderizzano i file in docs/legal/ via markdownToHtml().
     *
     * GET /legal/tos                  → ToS docente
     * GET /legal/aup                  → Acceptable Use Policy
     * GET /legal/takedown-procedure   → Procedura Notice & Takedown
     * GET /legal/dpa                  → Template DPA istituto-Vittorio
     */
    public function tos(Request $req): Response
    {
        return $this->renderMarkdownPage(
            dirname(__DIR__, 2) . '/docs/legal/tos_docente.md',
            'Termini di Servizio docente — Pantedu'
        );
    }

    public function aup(Request $req): Response
    {
        return $this->renderMarkdownPage(
            dirname(__DIR__, 2) . '/docs/legal/aup.md',
            'Acceptable Use Policy — Pantedu'
        );
    }

    public function takedownProcedure(Request $req): Response
    {
        return $this->renderMarkdownPage(
            dirname(__DIR__, 2) . '/docs/legal/takedown_procedure.md',
            'Procedura Notice & Takedown — Pantedu'
        );
    }

    public function dpa(Request $req): Response
    {
        return $this->renderMarkdownPage(
            dirname(__DIR__, 2) . '/docs/legal/dpa_template.md',
            'Data Processing Agreement (template) — Pantedu'
        );
    }

    /**
     * Phase C.4 — Dichiarazione di Accessibilità conforme a Legge Stanca
     * (L. 4/2004 art. 3-quater) + Direttiva UE 2016/2102 + Determinazione
     * AgID 224/2020 (Form-A). Sorgente: docs/legal/accessibility.md.
     */
    public function accessibility(Request $req): Response
    {
        return $this->renderMarkdownPage(
            dirname(__DIR__, 2) . '/docs/legal/accessibility.md',
            'Dichiarazione di Accessibilità — Pantedu'
        );
    }

    /**
     * Phase 25.E8 helper — carica markdown da disco e renderizza HTML.
     * Centralizza il pattern: file_check + strip frontmatter + markdownToHtml.
     */
    private function renderMarkdownPage(string $path, string $title): Response
    {
        if (!is_file($path)) {
            return Response::html($this->renderPage($title, '<p>Documento non disponibile.</p>'), 404);
        }
        $md = (string)file_get_contents($path);
        // Strip frontmatter YAML
        $md = preg_replace('/^---\n.*?\n---\n/s', '', $md) ?? '';

        // Phase S2 (ADR-017) — token replacement deployment-mode aware.
        // Permette al markdown legale di fare riferimento al titolare /
        // DPO dinamicamente, senza fork del documento per S1/S2.
        $md = $this->applyDeploymentTokens($md);

        // Conversione markdown → HTML minimale
        $html = $this->markdownToHtml($md);

        // Phase S2 — in institute mode, prepend un banner con titolare/DPO.
        if (\App\Support\DeploymentMode::isInstitute()) {
            $instituteName = \App\Support\DeploymentMode::instituteLegalName() ?: 'Istituto';
            $dpoContact    = \App\Support\DeploymentMode::dpoContact() ?: '(DPO non configurato)';
            $banner = '<aside class="fm-trust-controller-banner" role="note">'
                    . '<strong>Titolare del trattamento:</strong> ' . htmlspecialchars($instituteName)
                    . ' · <strong>DPO / Contatto privacy:</strong> ' . htmlspecialchars($dpoContact)
                    . '</aside>';
            $html = $banner . $html;
        }
        // Footer di navigazione cross-link tra documenti legali
        $html .= '<p class="fm-trust-meta">'
              . '<a href="/legal/tos">📜 ToS</a> · '
              . '<a href="/legal/aup">📏 AUP</a> · '
              . '<a href="/legal/takedown-procedure">🛡️ Takedown</a> · '
              . '<a href="/legal/dpa">📄 DPA</a> · '
              . '<a href="/privacy/informativa">🔒 Privacy</a> · '
              . '<a href="/security">🛡️ Sicurezza</a></p>';
        return Response::html($this->renderPage($title, $html));
    }

    /**
     * Phase S2 (ADR-017) — sostituisce placeholder nel markdown legale
     * con valori derivati dal deployment mode + env.
     *
     * Token supportati:
     *   {{INSTITUTE_LEGAL_NAME}}  ragione sociale istituto (S2) / "Vittorio Pantaleo" (S1)
     *   {{DPO_CONTACT}}           email DPO o admin
     *   {{APP_URL}}               base URL istanza
     *   {{DEPLOYMENT_MODE}}       'single' | 'institute' (per condizionali markdown future)
     */
    private function applyDeploymentTokens(string $md): string
    {
        $isInstitute = \App\Support\DeploymentMode::isInstitute();
        $institute   = \App\Support\DeploymentMode::instituteLegalName();
        $controller  = $isInstitute && $institute ? $institute : 'Vittorio Pantaleo (gestore istanza)';
        $tokens = [
            '{{INSTITUTE_LEGAL_NAME}}' => $controller,
            '{{DPO_CONTACT}}'          => \App\Support\DeploymentMode::dpoContact() ?: '(DPO non configurato)',
            '{{APP_URL}}'              => (string)(\App\Core\Config::get('app.url') ?: ''),
            '{{DEPLOYMENT_MODE}}'      => \App\Support\DeploymentMode::current(),
        ];
        return strtr($md, $tokens);
    }

    /**
     * Conversione markdown → HTML minimale (no parser esterno).
     * Supporta: # headings, **bold**, *italic*, [link](url), - liste, code,
     * hr, blockquote, tabelle pipe (con header + separator riga `|---|`).
     */
    private function markdownToHtml(string $md): string
    {
        $lines = explode("\n", $md);
        $out = '';
        $inList = false;
        $inCode = false;
        $inQuote = false;

        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            // Code blocks (delimitati da ``` su riga propria)
            if (preg_match('/^```/', $line)) {
                $inCode = !$inCode;
                $out .= $inCode ? '<pre><code>' : "</code></pre>\n";
                continue;
            }
            if ($inCode) {
                $out .= htmlspecialchars($line) . "\n";
                continue;
            }

            // Tabelle pipe-style: header + separator + N rows
            // Lookahead: linea con `|` seguita da linea separator (`|---|`)
            if (
                preg_match('/^\s*\|/', $line)
                && isset($lines[$i + 1])
                && preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)+\|?\s*$/', $lines[$i + 1])
            ) {
                if ($inList) {
                    $out .= "</ul>\n";
                    $inList  = false;
                }
                if ($inQuote) {
                    $out .= "</blockquote>\n";
                    $inQuote = false;
                }
                [$tableHtml, $consumed] = $this->parseTable($lines, $i);
                $out .= $tableHtml;
                $i += $consumed - 1; // -1 perché il for fa i++
                continue;
            }

            // Blockquote (`> testo`) — supporta righe consecutive
            if (preg_match('/^>\s?(.*)$/', $line, $m)) {
                if ($inList) {
                    $out .= "</ul>\n";
                    $inList = false;
                }
                if (!$inQuote) {
                    $out .= "<blockquote>\n";
                    $inQuote = true;
                }
                $inner = $m[1];
                $out .= ($inner === '')
                    ? "<br>\n"
                    : '<p>' . $this->inlineMd($inner) . "</p>\n";
                continue;
            } elseif ($inQuote) {
                $out .= "</blockquote>\n";
                $inQuote = false;
            }

            // Headings con anchor opzionale Kramdown-style {#id}.
            // Phase D.2 — supporta es. "## Stato SPID/CIE {#stato-spid-cie}"
            // per linkare /accessibility#stato-spid-cie. Se {#...} mancante,
            // genera slug auto dal testo heading.
            if (preg_match('/^(#{1,4})\s+(.+?)(?:\s*\{#([a-z0-9-]+)\})?$/i', $line, $m)) {
                if ($inList) {
                    $out .= "</ul>\n";
                    $inList = false;
                }
                $level = strlen($m[1]);
                $text  = $m[2];
                $id    = !empty($m[3])
                    ? $m[3]
                    : $this->slugify($text);
                $out .= "<h$level id=\"" . htmlspecialchars($id, ENT_QUOTES) . "\">"
                    . $this->inlineMd($text)
                    . "</h$level>\n";
                continue;
            }

            // List items — un parser dedicato assorbe l'intero blocco lista,
            // incluse le righe di continuazione indentate (testo a capo) e i
            // sotto-elenchi indentati, così da NON emettere mai <p> figli
            // diretti di <ul> (WCAG 1.3.1 / axe "list" serious).
            if (preg_match('/^[-*]\s+/', $line)) {
                [$listHtml, $consumed] = $this->parseList($lines, $i);
                $out .= $listHtml;
                $i += $consumed - 1; // -1 perché il for fa i++
                continue;
            }

            // hr
            if (preg_match('/^---+$/', trim($line))) {
                $out .= "<hr>\n";
                continue;
            }

            // Paragrafo
            if (trim($line) !== '') {
                $out .= '<p>' . $this->inlineMd($line) . "</p>\n";
            }
        }
        if ($inList) {
            $out .= "</ul>\n";
        }
        if ($inQuote) {
            $out .= "</blockquote>\n";
        }
        return $out;
    }

    /**
     * Parsing di un blocco lista a partire da $start (riga "- "/"* " a colonna 0).
     * Gestisce:
     *   - item top-level (`- testo`);
     *   - righe di continuazione indentate senza bullet → appese al testo
     *     dell'item corrente (il markdown va a capo i paragrafi lunghi);
     *   - sotto-elenchi indentati (`  - testo`) → <ul> annidato dentro il <li>.
     * Garantisce HTML valido: <ul> contiene SOLO <li> (mai <p>) — fix WCAG 1.3.1.
     *
     * @param array<int,string> $lines
     * @return array{0:string,1:int} [html, lines_consumed]
     */
    private function parseList(array $lines, int $start): array
    {
        $items = [];               // ['text'=>string, 'sub'=>list<string>]
        $i = $start;
        $n = count($lines);
        while ($i < $n) {
            $line = $lines[$i];
            // Nuovo item top-level (nessuna indentazione).
            if (preg_match('/^[-*]\s+(.+)$/', $line, $m)) {
                $items[] = ['text' => $m[1], 'sub' => []];
                $i++;
                continue;
            }
            // Riga indentata non vuota → continuazione o sotto-elenco dell'item.
            if ($items !== [] && preg_match('/^\s+(\S.*)$/', $line, $m)) {
                $content = trim($m[1]);
                $last = count($items) - 1;
                if (preg_match('/^[-*]\s+(.+)$/', $content, $mm)) {
                    $items[$last]['sub'][] = $mm[1];      // sotto-bullet
                } else {
                    $items[$last]['text'] .= ' ' . $content; // continuazione testo
                }
                $i++;
                continue;
            }
            break; // riga vuota o non-lista → fine blocco
        }

        $html = "<ul>\n";
        foreach ($items as $it) {
            $html .= '<li>' . $this->inlineMd($it['text']);
            if ($it['sub'] !== []) {
                $html .= "\n<ul>\n";
                foreach ($it['sub'] as $s) {
                    $html .= '<li>' . $this->inlineMd($s) . "</li>\n";
                }
                $html .= "</ul>\n";
            }
            $html .= "</li>\n";
        }
        $html .= "</ul>\n";
        return [$html, $i - $start];
    }

    /**
     * Parsing tabella pipe-style. Ritorna [html, lines_consumed].
     * @param array<int,string> $lines
     * @return array{0:string,1:int}
     */
    private function parseTable(array $lines, int $start): array
    {
        $headerCells = $this->splitPipeRow($lines[$start]);
        $consumed = 2; // header + separator
        $rows = [];
        $count = count($lines);
        for ($j = $start + 2; $j < $count; $j++) {
            if (!preg_match('/^\s*\|/', $lines[$j])) {
                break;
            }
            $rows[] = $this->splitPipeRow($lines[$j]);
            $consumed++;
        }

        $html = "<table>\n<thead><tr>";
        foreach ($headerCells as $cell) {
            $html .= '<th>' . $this->inlineMd($cell) . '</th>';
        }
        $html .= "</tr></thead>\n<tbody>\n";
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . $this->inlineMd($cell) . '</td>';
            }
            $html .= "</tr>\n";
        }
        $html .= "</tbody></table>\n";
        return [$html, $consumed];
    }

    /** Split riga `| a | b | c |` → ['a','b','c']. */
    private function splitPipeRow(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\|/', '', $line) ?? '';
        $line = preg_replace('/\|$/', '', $line) ?? '';
        return array_map('trim', explode('|', $line));
    }

    /**
     * Slugify per generare anchor ID auto da heading text. Phase D.2.
     * "Stato SPID/CIE" -> "stato-spidcie". Limita ASCII safe + hyphen.
     */
    private function slugify(string $text): string
    {
        // Strip inline markdown markers
        $text = preg_replace('/\[(.+?)\]\([^)]+\)/', '$1', $text) ?? $text;
        $text = strip_tags($text);
        // Lowercase
        $text = mb_strtolower($text, 'UTF-8');
        // Replace accented chars
        $text = strtr($text, [
            'à' => 'a','è' => 'e','é' => 'e','ì' => 'i','ò' => 'o','ù' => 'u',
            'À' => 'a','È' => 'e','É' => 'e','Ì' => 'i','Ò' => 'o','Ù' => 'u',
        ]);
        // Non alphanumeric -> hyphen
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        return trim($text, '-') ?: 'section';
    }

    private function inlineMd(string $s): string
    {
        // Escape HTML first
        $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        // Bold + italic
        $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
        $s = preg_replace('/\*([^*]+?)\*/', '<em>$1</em>', $s);
        // Inline code
        $s = preg_replace('/`([^`]+?)`/', '<code>$1</code>', $s);
        // Link [text](url)
        $s = preg_replace_callback('/\[(.+?)\]\(([^)]+)\)/', function ($m) {
            $url = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
            return "<a href=\"$url\">{$m[1]}</a>";
        }, $s);
        return $s;
    }

    private function renderPage(string $title, string $body): string
    {
        return StandalonePageRenderer::render($title, $body, [
            'extraStyles' => '.fm-trust-meta { margin-top: 2em; font-size: 0.9em; color: var(--fm-fg-muted); }',
            // Phase 25.R.2.4 — direct hit wrap in layout/app.php (sidebar +
            // bottombar) per coerenza UX. SPA partial mode invariato.
            'useAppLayout' => true,
        ]);
    }
}
