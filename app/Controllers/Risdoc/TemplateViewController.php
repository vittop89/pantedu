<?php

declare(strict_types=1);

namespace App\Controllers\Risdoc;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\Risdoc\Permission;
use App\Services\Risdoc\TemplateResolver;

/**
 * Template view (Phase 21, U5 revised).
 *
 * GET /risdoc/view/{id}  → HTML pagina wrappata nella shell del sito
 * (views/layout/app.php) così la sidebar resta visibile come per
 * esercizi/mappe/verifiche.
 */
final class TemplateViewController
{
    public function __construct(private TemplateResolver $resolver = new TemplateResolver())
    {
    }

    /**
     * ADR-026 #3 — emette la shell unificata <fm-pt-document
     * source="risdoc-template">. RisdocTemplateAdapter (client) fetcha lo
     * schema da /api/risdoc/templates/{id}/schema, costruisce il body_pt e
     * monta le card fm-risdoc-pt-section. Save → /compilations (docente) o
     * /body-pt (admin schema-edit).
     */
    private function renderWebComponent(Request $req, array $tmpl, int $id, int $tid): Response
    {
        $canEdit   = Permission::canEdit($id, $tid);
        $category  = htmlspecialchars((string)$tmpl['category'], ENT_QUOTES);
        $numArg    = htmlspecialchars((string)$tmpl['num_arg'], ENT_QUOTES);
        $title     = htmlspecialchars(str_replace('_', ' ', (string)$tmpl['argomento']), ENT_QUOTES);
        $schemaUrl = '/api/risdoc/templates/' . (int)$tmpl['id'] . '/schema';
// Initial state hydration: primary credential del docente (indirizzo/classe)
        // + nome utente come `professore`. Se il docente ha più classi, viene
        // presa la più recente. UI di selezione multi-classe è fuori scope.
        $state = [];
        try {
            $db = \App\Core\Database::connection();
            $st = $db->prepare('SELECT indirizzo, classe FROM teacher_access_credentials
                  WHERE teacher_id=? AND active=1 ORDER BY created_at DESC LIMIT 1');
            $st->execute([$tid]);
            $cred = $st->fetch(\PDO::FETCH_ASSOC);
            if ($cred) {
                if (!empty($cred['indirizzo'])) {
                    $state['indirizzo'] = $cred['indirizzo'];
                }
                if (!empty($cred['classe'])) {
    // classe format es. "3A" → split in {classe:3, sezione:A}
                    if (preg_match('/^(\d+)([A-Z]+)$/', $cred['classe'], $m)) {
                        $state['classe']  = $m[1];
                        $state['sezione'] = $m[2];
                    } else {
                        $state['classe'] = $cred['classe'];
                    }
                }
            }
            // Professore: nome+cognome da users
            $st = $db->prepare('SELECT first_name, last_name FROM users WHERE id=?');
            $st->execute([$tid]);
            $u = $st->fetch(\PDO::FETCH_ASSOC);
            if ($u) {
                $fullname = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                if ($fullname !== '') {
                    $state['professore'] = $fullname;
                }
            }
        } catch (\Throwable) {
        // silent: state resta vuoto, WC mostra dropdown vuoti
        }
        $initialStateAttr = htmlspecialchars(json_encode($state, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
// Admin-edit mode: query ?admin_edit=1 + super_admin (o collaboratore)
        // → fm-pt-document riceve admin-edit="1"; save passa per adapter
        // adminEdit branch (POST /api/risdoc/templates/{id}/body-pt). Le
        // modifiche dei collaboratori con requires_review entrano in
        // pending queue (institutional-override).
        $isAdminEdit = !empty($req->query['admin_edit'])
            && (Permission::isSuperAdmin() || Permission::isCollaborator($id, $tid));
        // G22.S12 — role (D/C/R) dal query param. Default 'D' (docente).
        // Usato come segmento path nel download VSCode bundle.
        $docRoleRaw = strtoupper((string)($req->query['role'] ?? 'D'));
        $docRole = in_array($docRoleRaw, ['D','C','R'], true) ? $docRoleRaw : 'D';
        $adminEditAttr = $isAdminEdit ? 'admin-edit="1"' : '';
// Phase 24.58 — multi-instance: query ?instance=KEY oppure default ''
        // (istanza base). Il WC riceve instance-key e lo passa ad ogni
        // override save / file fetch.
        $instanceKey = (string)($req->query['instance'] ?? '');
        $instanceKeyEsc = htmlspecialchars($instanceKey, ENT_QUOTES);
        $instanceKeyAttr = $instanceKey !== '' ? 'instance-key="' . $instanceKeyEsc . '"' : '';
        $argomentoAttr = htmlspecialchars((string)$tmpl['argomento'], ENT_QUOTES);
        // ADR-026 #3 — bottoni topbar comuni (TEX/PDF, VSCode, ZIP, Save,
        // Export/Import JSON) resi da fm-pt-document._topbarButtons. Qui
        // restano solo i widget institutional-only (istanze, admin chip,
        // navigator) passati via $topbarExtra trailing.
        $instanceSection = $instanceKey !== ''
            ? '<span class="fm-doc-topbar__chip fm-doc-topbar__chip--instance"'
                . ' title="Stai modificando una tua istanza personale del template">'
                . '<span class="fm-doc-topbar__pill">ISTANZA</span>'
                . '<code>' . $instanceKeyEsc . '</code>'
                . '<a class="fm-doc-topbar__btn fm-doc-topbar__btn--ghost" href="/risdoc/view/' . (int)$tmpl['id'] . '"'
                . ' title="Apri il template istituzionale (istanza base)">📋 Vai a baseline</a>'
                . '</span>'
            : '';
        // G22.S26 — Quando in admin_edit mode mostra toolbar admin con
        // pannello JSON. Per super-admin: pill "ADMIN". Per collaboratori:
        // pill "COLLAB" (eventualmente con sotto-pill "⏳ revisione" se il
        // teacher ha requires_review=1: le sue Save vanno in coda).
        $isCollabEdit = !Permission::isSuperAdmin() && Permission::isCollaborator($id, $tid);
        $reviewBadge  = ($isCollabEdit && Permission::requiresReview($id, $tid))
            ? '<span class="fm-doc-topbar__pill fm-doc-topbar__pill--review"'
                . ' title="Le tue modifiche saranno revisionate dal super-admin prima di diventare effettive">⏳ Revisione</span>'
            : '';
        // ADR-026 #3 — admin schema-edit ora salva via fm-pt-document._save()
        // (RisdocTemplateAdapter.save adminEdit branch → POST /body-pt). I
        // bottoni admin-toggle-json/admin-save/admin-revert sono stati rimossi
        // (handler fm-risdoc-toolbar-actions.js eliminato). Resta solo il
        // chip visivo ADMIN/COLLAB + ✕ Esci con close() inline.
        $editorLabel = $isCollabEdit ? 'COLLAB' : 'ADMIN';
        $adminSection = $isAdminEdit
            ? '<span class="fm-doc-topbar__chip fm-doc-topbar__chip--admin"'
                . ' title="Modifica schema istituzionale">'
                . '<span class="fm-doc-topbar__pill fm-doc-topbar__pill--admin">' . $editorLabel . '</span>'
                . $reviewBadge
                . '<button type="button" class="fm-doc-topbar__btn fm-doc-topbar__btn--ghost"'
                . ' onclick="try{window.close();}catch(_){}setTimeout(function(){location.href=\'/admin/templates#risdoc\';},80);"'
                . ' title="Chiudi questa scheda">✕ Esci</button>'
                . '</span>'
            : '';
        // G22.S26 — entry-point alla modalità admin-edit per chi può editare
        // ma non è già in modalità admin-edit. Visibile solo se: super-admin
        // OR collaboratore del template; nascosto quando già in admin_edit.
        $canEditStruct = Permission::isSuperAdmin() || Permission::isCollaborator($id, $tid);
        $enterAdminBtn = (!$isAdminEdit && $canEditStruct)
            ? '<a class="fm-doc-topbar__btn fm-doc-topbar__btn--secondary" href="/risdoc/view/' . (int)$tmpl['id'] . '?admin_edit=1"'
                . ' title="Apri la modalità di modifica struttura template (institutional override)">✏️ Modifica struttura</a>'
            : '';
        $pageHead = <<<HTML
<link rel="stylesheet" href="/css/risdoc-tokens.css">
<script type="module" src="/js/components/risdoc/index.js"></script>
HTML;
        // Phase 25.E12 — section-navigator dropdown (HTML scheletro popolato
        // runtime da fm-risdoc-section-navigator.js). Sempre visibile.
        // Phase 25.E19 — il titolo del button è dinamico (current section
        // tracking via IntersectionObserver). Default: "Sezioni".
        $navigatorSection = '<div id="header-controls-container">'
            . '<div id="section-navigator" title="Naviga tra le sezioni" tabindex="0" aria-label="Apri navigatore sezioni">'
            . '<span class="fm-section-nav-icon">📑</span>'
            . '<span class="fm-section-nav-label" data-default-label="Sezioni">Sezioni</span>'
            . '<div id="section-dropdown" role="menu"></div>'
            . '</div>'
            . '</div>';
        // ADR-026 #3 (2026-05-28) — Solo onepath unificato. fm-pt-document
        // rende internamente i bottoni topbar comuni (Save, TeX/PDF, ZIP, ecc.
        // via _topbarButtons). Qui passiamo solo i widget institutional-only
        // come $topbarExtra trailing.
        $useUnified = true;
        if ($useUnified) {
            $topbarExtra = $instanceSection . $enterAdminBtn . $adminSection . $navigatorSection;
            $topbarExtraAttr = htmlspecialchars($topbarExtra, ENT_QUOTES);
            $pageContent = <<<HTML
<div class="fm-risdoc-view fm-risdoc-view--unified" data-template-id="{$tmpl['id']}">
    <fm-pt-document source="risdoc-template" template-id="{$tmpl['id']}"
                    schema-url="{$schemaUrl}" initial-state="{$initialStateAttr}"
                    instance-key="{$instanceKeyEsc}" title="{$title}"
                    argomento="{$argomentoAttr}" doc-role="{$docRole}"
                    {$adminEditAttr}
                    topbar-extra-html="{$topbarExtraAttr}">
        <noscript>Questo modello richiede JavaScript (Web Components).</noscript>
    </fm-pt-document>
</div>
<!-- /risdoc/view non carica bootstrap → registra qui la shell fm-pt-document
     (che importa fm-doc-topbar). Toolbar/save/export resi internamente
     da fm-pt-document._topbarButtons (ADR-026 #3 cleanup). -->
<script type="module" src="/js/components/pt-document/fm-pt-document.js"></script>
<script src="/js/components/risdoc/fm-risdoc-section-navigator.js"></script>
HTML;
            // ADR-026 — la shell unificata DEVE girare nello stesso layout del
            // sito (views/layout/app.php → head.php carica main.bundle.css +
            // sidebar/chrome) ESATTAMENTE come il path legacy. Prima ritornava
            // una pagina nuda (solo risdoc-tokens.css) → topbar non stilizzata +
            // logo SVG 800x800 → il "modello" sembrava ancora diverso dal custom.
            $isPartial = ($_SERVER['HTTP_X_PARTIAL'] ?? '') === '1';
            if ($isPartial) {
                // SPA fm-router: inietta contenuto + head inline in #fm-content.
                return new Response($pageHead . $pageContent, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }
            $pageTitle   = 'PANTEDU — ' . str_replace('_', ' ', (string)$tmpl['argomento']);
            $bodyClass   = 'fm-studio-risdoc fm-studio-light';
            if (class_exists(Auth::class) && Auth::check() && Auth::hasAccess('admin')) {
                $bodyClass .= ' admin-access fm-admin-access';
            }
            if (class_exists(Auth::class) && Auth::check() && Auth::hasAccess('teacher')) {
                $bodyClass .= ' fm-teacher-access';
            }
            $pageScripts  = '';
            $currentRoute = $req->path ?? '';
            $base = dirname(__DIR__, 3);
            ob_start();
            include $base . '/views/layout/app.php';
            return new Response((string)ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        // Branch legacy server-side <fm-risdoc-template> RIMOSSO (ADR-026 #3, 2026-05-28).
        // $useUnified è ora sempre true → questo punto è irraggiungibile.
        return new Response('Unreachable: legacy ?ui=legacy branch removed', 500);
    }

    /**
     * Recupera l'ultima compilazione del docente per quel template (se presente).
     * Usata dal FormRenderer per pre-compilare i campi.
     */
    private function lookupLastCompilation(int $teacherId, int $templateId): array
    {
        if ($teacherId <= 0) {
            return [];
        }
        $stmt = \App\Core\Database::connection()->prepare('SELECT data_json FROM risdoc_compilations
             WHERE teacher_id=? AND template_id=?
             ORDER BY updated_at DESC LIMIT 1');
        $stmt->execute([$teacherId, $templateId]);
        $raw = (string)$stmt->fetchColumn();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * GET /risdoc/{category}/php/{filename}
     * Route legacy-compatibile. Deriva template_id dal filename (match code)
     * e ritorna lo stesso rendering di /risdoc/view/{id}. Serve quando il
     * browser ricarica la URL (dopo history.replaceState) o apre un link
     * legacy: altrimenti il catch-all `/risdoc/{path*}` servirebbe il file
     * .php come octet-stream → download indesiderato.
     */
    public function showByLegacyPath(Request $req, array $params): Response
    {
        $category = (string)($params['category'] ?? '');
        $filename = (string)($params['filename'] ?? '');
        if ($category === '' || $filename === '') {
            return Response::html('<h1>404</h1>', 404);
        }

        $stmt = \App\Core\Database::connection()->prepare("SELECT id FROM risdoc_templates WHERE category=? AND html_file=? LIMIT 1");
        $stmt->execute([$category, $filename]);
        $id = (int)$stmt->fetchColumn();
        if ($id === 0) {
            return Response::html('<h1>404</h1><p>Template non trovato.</p>', 404);
        }

        return $this->show($req, ['id' => $id]);
    }

    public function show(Request $req, array $params): Response
    {
        $id  = (int)($params['id'] ?? 0);
        $tid = Permission::currentTeacherId();
        if ($id === 0) {
            return Response::html('<h1>400</h1><p>Invalid template id.</p>', 400);
        }
        if (!Permission::canView($id, $tid)) {
            return Response::html('<h1>403</h1><p>Non hai accesso a questo template.</p>', 403);
        }

        $tmpl = $this->resolver->findTemplate($id);
        if (!$tmpl) {
            return Response::html('<h1>404</h1><p>Template non trovato.</p>', 404);
        }

        // Se il template ha schema_path → shell <fm-pt-document
        // source="risdoc-template"> (onepath ADR-026 #3). Altrimenti
        // fallback legacy html_file PHP.
        $schemaPath = (string)($tmpl['schema_path'] ?? '');
        if ($schemaPath !== '') {
            return $this->renderWebComponent($req, $tmpl, $id, $tid);
        }

        $htmlBody = '';
        $headExtra = '';
        if (false) {
            $absSchema = dirname(__DIR__, 3) . '/' . ltrim($schemaPath, '/');
            if (is_file($absSchema)) {
                try {
                    $renderer = new \App\Services\Risdoc\FormRenderer($absSchema);
                    // Ultima compilazione del docente per pre-compilare i campi (best-effort).
                    $lastCompilation = $this->lookupLastCompilation($tid, $id);
                    $htmlBody = $renderer->render([
                        'teacherId'   => $tid,
                        'compilation' => $lastCompilation,
                    ]);
                } catch (\Throwable $e) {
                    error_log("[risdoc-renderer] schema render failed for id={$id}: " . $e->getMessage());
                    $htmlBody = '';
                    // triggera fallback sotto
                }
            }
        }

        // Fallback legacy: carica html_file PHP e strip head/body tags.
        if ($htmlBody === '') {
            $result = $this->resolver->resolveFile($tid, $id, 'html', '');
            if (!$result) {
                return Response::html('<h1>404</h1><p>File HTML non trovato.</p>', 404);
            }
            $htmlBody = (string)($result['body'] ?? '');
            $htmlBody = preg_replace('/<\?php[\s\S]*?\?' . '>/', '', $htmlBody) ?? $htmlBody;
            if (preg_match('#<head[^>]*>([\s\S]*?)</head>#i', $htmlBody, $h)) {
                $headExtra = $h[1];
                $headExtra = preg_replace('#<title\b[^>]*>.*?</title>#is', '', $headExtra) ?? $headExtra;
                $headExtra = preg_replace('#<meta\s+charset[^>]*>#is', '', $headExtra) ?? $headExtra;
                $headExtra = preg_replace('#<meta\s+name=["\']viewport["\'][^>]*>#is', '', $headExtra) ?? $headExtra;
            }
            if (preg_match('#<body[^>]*>([\s\S]*?)</body>#i', $htmlBody, $m)) {
                $htmlBody = $m[1];
            }
        }

        // Risolve CSS
        $cssBody = null;
        if ($tmpl['css_file']) {
            $cssRes = $this->resolver->resolveFile($tid, $id, 'css', '');
            $cssBody = $cssRes['body'] ?? null;
        }

        $canEdit     = Permission::canEdit($id, $tid);
        $legacyUrl   = '/risdoc/' . $tmpl['category'] . '/php/' . ($tmpl['html_file'] ?? '');
        // Phase 24.58 — colonna `origin` rimossa. Questo è il fallback legacy
        // (raggiunto solo se schema_path è vuoto: nessun template reale lo è),
        // che storicamente caricava jQuery/MathJax per i template "risdoc".
        $category    = htmlspecialchars((string)$tmpl['category'], ENT_QUOTES);
        $numArg      = htmlspecialchars((string)$tmpl['num_arg'], ENT_QUOTES);
        $title       = htmlspecialchars(str_replace('_', ' ', (string)$tmpl['argomento']), ENT_QUOTES);
        $sourceBadge = $result['source'] === 'override' ? '📝 Override attivo' : '📄 Sorgente';
// ── Build $pageHead (asset esclusivi della pagina risdoc) ──
        $pageHead = '';
        if (true) {
        // history.replaceState → URL legacy-compatibile PRIMA di risdoc.js
            $pageHead .= '<script>if (location.pathname.startsWith("/risdoc/view/")) { try { history.replaceState(null, "", '
                       . json_encode($legacyUrl, JSON_UNESCAPED_SLASHES) . '); } catch(_){} }</script>' . "\n";
            // Sprint B (2026-06-02): jQuery rimosso (branch legacy mai raggiunto —
            // tutti i template hanno schema_path → renderWebComponent vanilla).
            $pageHead .= '<script async src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.7/MathJax.js?config=TeX-MML-AM_CHTML"></script>' . "\n";
            $pageHead .= '<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>' . "\n";
        }
        if (!empty($cssBody)) {
            $pageHead .= '<style>' . $cssBody . '</style>' . "\n";
        }
        if (!empty($headExtra)) {
            $pageHead .= "<!-- head legacy template -->\n" . $headExtra . "\n";
        }
        // Stili toolbar risdoc + override .header legacy (toglie position:fixed
        // che copriva sidebar + toolbar; lo contiene nel flow del main content).
        $pageHead .= <<<CSS
<style>
/* .header legacy sticky sotto la toolbar: resta visibile allo scroll
   (comportamento legacy con position:fixed) senza coprire la
   .fm-risdoc-toolbar che invece si aggancia al top del viewport. */
.fm-risdoc-toolbar {
    position: sticky;
    top: 0;
    z-index: 100;
}
/* .header sticky sotto la toolbar: resta fermo allo scrolling (stesso
   effetto del fixed legacy) senza richiedere width/left dinamici.
   Top = altezza toolbar (aggiornato via --fm-risdoc-tb-height da JS
   sotto). margin:0 elimina il gap verso .section-header. */
body.fm-studio-risdoc .fm-risdoc-view .header {
    position: sticky !important;
    top: var(--fm-risdoc-tb-height, 41px) !important;
    left: auto !important;
    width: 100% !important;
    z-index: 50 !important;
    margin: 0 !important;
}
/* .section-header sticky (legacy: position:sticky senza top -> non
   adhariva). Top = --fm-risdoc-sh-top calcolato dinamicamente da
   risdoc-sticky.js in base a toolbar + header visibile. Se l'header
   collassa via header-toggle, il top si riduce fino alla toolbar.
   Background fallback: alcuni template hanno .section-header bg
   commentato -> con sticky trasparente il testo sottostante leakerebbe. */
body.fm-studio-risdoc .fm-risdoc-view .section-header {
    position: sticky !important;
    top: var(--fm-risdoc-sh-top, 130px) !important;
    z-index: 40 !important;
    background-color: var(--section-header-bg, rgb(219, 228, 240)) !important;
}
/* Annulla tutti i padding/margin top che creano spazio vuoto tra la
   toolbar minimale e il contenuto del template. risdoc.js applica un
   padding-top dinamico a .page-container per compensare il .header fixed
   che nella shell non c'è; inoltre <main> e altri container possono avere
   margin default dalla layout.css. */
body.fm-studio-risdoc .fm-risdoc-view .page-container,
body.fm-studio-risdoc main#fm-risdoc-content,
body.fm-studio-risdoc .fm-risdoc-view {
    padding-top: 0 !important;
    margin-top: 0 !important;
}
body.fm-studio-risdoc .fm-risdoc-view main#fm-risdoc-content {
    padding: 0 !important;
    margin: 0 !important;
}
body.fm-studio-risdoc .fm-risdoc-view .page-container {
    margin-top: 0 !important;
}
/* Flex elimina il text-node whitespace tra toolbar e <main> (linea
   vuota ~37px). */
body.fm-studio-risdoc .fm-risdoc-view {
    display: flex !important;
    flex-direction: column !important;
    gap: 0 !important;
}
/* Il layout shell (views/layout/app.php) applica margin-top al #fm-content
   (per compensare upbar fixed che qui non abbiamo) e layout_es.css applica
   padding-top:48px a body.exercise-context. Azzerali nel contesto risdoc. */
body.fm-studio-risdoc {
    padding-top: 0 !important;
    margin-top: 0 !important;
}
body.fm-studio-risdoc #fm-content {
    margin-top: 0 !important;
    padding-top: 0 !important;
}
/* Reset del top:20px applicato via inline-style da risdoc.js _initSectionNavigator
 * (serviva solo nel rendering iframe legacy). Dentro la toolbar il nav
 * deve essere relative top:0 per rispettare align-items:center. */
body.fm-studio-risdoc #section-navigator {
    top: 0 !important;
}
/* LOGOUT fisso del template legacy: nascondere (shell ha già il suo).
 * `#logoutBtn`/`.logout-button` sono nel markup template; `#fixed-logout-
 * container` era creato runtime da risdoc.js logoutButton.init() (ora
 * disattivato). Tenere la regola CSS come difesa in depth: se qualche
 * override resuscita la logica, resta invisibile. */
body.fm-studio-risdoc #fixed-logout-container,
body.fm-studio-risdoc .fm-risdoc-view #logoutBtn,
body.fm-studio-risdoc .fm-risdoc-view .logout-button {
    display: none !important;
}
.fm-risdoc-toolbar {
    background: #1e293b; color: #fff; padding: 6px 12px;
    display: flex; align-items: center; gap: 8px;
    border-bottom: 1px solid #0f172a; font-size: 12px;
}
.fm-risdoc-toolbar__meta { flex: 1; opacity: .85; font-size: 11px; }
.fm-risdoc-toolbar__meta code { background: rgba(255,255,255,.12); padding: 1px 6px; border-radius: 3px; font-size: 10px; margin-right: 6px; }
.fm-doc-topbar__btn {
    background: #475569;
    color: #fff;
    border: 0;
    padding: 0 12px;
    height: 26px;
    border-radius: 4px;
    font-size: 12px;
    line-height: 1;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.fm-doc-topbar__btn:hover { background: #64748b; }
.fm-doc-topbar__btn--primary { background: #3b82f6; }
.fm-doc-topbar__btn--primary:hover { background: #2563eb; }
.fm-risdoc-badge { font-size: 10px; padding: 1px 8px; border-radius: 10px; background: rgba(255,255,255,.15); margin-left: 6px; }
.fm-risdoc-save-status { font-size: 10px; padding: 0 8px; opacity: .7; min-width: 80px; text-align: right; }
.fm-risdoc-save-status[data-status="saving"] { color: #fbbf24; }
.fm-risdoc-save-status[data-status="saved"]  { color: #34d399; }
.fm-risdoc-save-status[data-status="error"]  { color: #f87171; }

/* Controlli template (#section-navigator + #header-toggle) integrati
   nella .fm-risdoc-toolbar: override del position:fixed legacy +
   restyle inline coerente col tema scuro della toolbar. risdoc.js
   initHeaderToggle li aggancia prima di .fm-risdoc-save-status.
   !important: risdoc.css comune viene caricato via <link> asincrono
   e puo' arrivare DOPO questo override → serve priority forzata. */
.fm-risdoc-toolbar #header-controls-container {
    position: static !important;
    top: auto !important; right: auto !important;
    display: inline-flex !important; align-items: center !important; gap: 6px !important;
    background: transparent !important;
    border: 0 !important;
    box-shadow: none !important;
    padding: 0 !important;
    transform: none !important;
    opacity: 1 !important;
    pointer-events: auto !important;
}
.fm-risdoc-toolbar #section-navigator {
    position: relative !important;
    align-self: center !important;
    box-sizing: border-box !important;
    background: rgba(255,255,255,.12) !important;
    color: #fff !important;
    border: 1px solid rgba(255,255,255,.2) !important;
    border-radius: 4px !important;
    padding: 0 12px !important;
    margin: 0 !important;
    height: 26px !important;
    font-size: 12px !important;
    line-height: 1 !important;
    min-width: auto !important;
    box-shadow: none !important;
    transform: none !important;
    opacity: 1 !important;
    pointer-events: auto !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
}
.fm-risdoc-toolbar #section-navigator:hover {
    background: rgba(255,255,255,.2) !important;
    transform: none !important;
    box-shadow: none !important;
}
.fm-risdoc-toolbar #section-navigator.open {
    background: rgba(255,255,255,.25) !important;
    border-color: #3b82f6 !important;
}
/* Dropdown resta absolute rispetto al navigator (non toolbar).
   Tema scuro coerente con la toolbar: bg quasi-nero semi-trasparente,
   testo bianco, border coerente. I .section-item vengono restylati
   per matchare. */
.fm-risdoc-toolbar #section-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 4px) !important;
    right: 0 !important;
    background: rgba(30, 41, 59, .96) !important;
    color: #e2e8f0 !important;
    border: 1px solid rgba(255,255,255,.2) !important;
    border-radius: 6px !important;
    box-shadow: 0 6px 16px rgba(0,0,0,.4) !important;
    font-size: 12px !important;
    min-width: 220px !important;
    max-height: 320px;
    overflow-y: auto;
    z-index: 200;
}
.fm-risdoc-toolbar #section-dropdown.show { display: block; }
.fm-risdoc-toolbar #section-dropdown .section-item {
    display: block;
    text-decoration: none;
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.fm-risdoc-toolbar #section-navigator.scroll-hidden {
    opacity: 0; transform: scale(0.85); pointer-events: none;
    transition: opacity .25s ease, transform .25s ease;
}
.fm-risdoc-toolbar #section-dropdown .section-item {
    color: #e2e8f0 !important;
    border-bottom: 1px solid rgba(255,255,255,.08) !important;
    padding: 10px 14px !important;
}
.fm-risdoc-toolbar #section-dropdown .section-item:hover {
    background: rgba(255,255,255,.08) !important;
}
.fm-risdoc-toolbar #section-dropdown .section-item:active {
    background: rgba(255,255,255,.12) !important;
}
.fm-risdoc-toolbar #section-dropdown .section-item.sub-section {
    color: #94a3b8 !important;
    padding-left: 28px !important;
}
.fm-risdoc-toolbar #header-toggle-container {
    align-self: center !important;
    background: transparent !important;
    border: 0 !important;
    border-radius: 50% !important;
    padding: 0 !important;
    margin: 0 !important;
    box-shadow: none !important;
    width: 26px !important; height: 26px !important;
    display: inline-flex !important; align-items: center !important; justify-content: center !important;
}
.fm-risdoc-toolbar #header-toggle-container:hover {
    transform: none !important;
    background: transparent !important;
    box-shadow: none !important;
}
.fm-risdoc-toolbar .header-slider {
    width: 26px !important; height: 26px !important;
    background: #475569 !important;
    color: #fff !important;
    font-size: 12px !important;
    line-height: 1 !important;
    box-shadow: none !important;
    border-radius: 50% !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
}
.fm-risdoc-toolbar .header-slider:hover {
    background: #64748b !important;
    box-shadow: none !important;
    transform: none !important;
}
</style>
<script>
(function () {
    // Calcola dinamicamente --fm-risdoc-sh-top su .fm-risdoc-view = altezza
    // toolbar + altezza header visibile. Permette a .section-header sticky
    // di agganciarsi SUBITO sotto l'header (variabile per template) e, se
    // l'header si riduce/collassa (header-toggle ▼), di salire fino alla
    // toolbar. Usa ResizeObserver per reagire a ridimensionamenti header.
    function updateStickyTop() {
        var view = document.querySelector('.fm-risdoc-view');
        if (!view) return;
        var toolbar = view.querySelector('.fm-risdoc-toolbar');
        var header  = view.querySelector('.header');
        var th = toolbar ? toolbar.offsetHeight : 0;
        var hh = header  ? header.offsetHeight  : 0;
        view.style.setProperty('--fm-risdoc-tb-height', th + 'px');
        view.style.setProperty('--fm-risdoc-sh-top', (th + hh) + 'px');
    }
    function observe() {
        updateStickyTop();
        var view = document.querySelector('.fm-risdoc-view');
        if (!view || !window.ResizeObserver) return;
        var ro = new ResizeObserver(updateStickyTop);
        var toolbar = view.querySelector('.fm-risdoc-toolbar');
        var header  = view.querySelector('.header');
        if (toolbar) ro.observe(toolbar);
        if (header)  ro.observe(header);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', observe, { once: true });
    } else {
        observe();
    }
    // Rilancia dopo SPA nav (fm-router emette fm:navigated dopo innerHTML swap)
    window.addEventListener('fm:navigated', function () { setTimeout(observe, 50); });
})();
</script>
CSS;
// ── Build $pageContent (toolbar + body template) ──
        $editBtn = $canEdit
            ? '<a href="/risdoc/edit/' . (int)$tmpl['id'] . '" class="fm-doc-topbar__btn fm-doc-topbar__btn--primary" title="Editor avanzato HTML/TeX/CSS/JSON">✎ Editor avanzato</a>'
            : '';
        $pageContent = <<<HTML
<div class="fm-risdoc-view" data-template-id="{$tmpl['id']}" data-category="{$category}">
    <div class="fm-risdoc-toolbar">
        <span class="fm-risdoc-toolbar__meta">
            <code>{$category} · {$numArg}</code>{$title}
            <span class="fm-risdoc-badge">{$sourceBadge}</span>
        </span>
        {$editBtn}
        <div class="fm-risdoc-save-status" data-status="idle"></div>
    </div>
    <main id="fm-risdoc-content">
        {$htmlBody}
    </main>
</div>
HTML;
// Emetti $pageHead DENTRO #fm-content (non in document <head>):
        // - Full load: il <style>cssBody + headExtra finiscono in #fm-content.
        //   Innocui (CSS si applica ovunque) e coerenti col partial.
        // - Partial (SPA fm-router): innerHTML di #fm-content viene
        //   sostituito, gli stili/scripts del template PRECEDENTE spariscono
        //   automaticamente → no stile stale passando tra template diversi.
        // Evita accumulo in <head> che causava pagina nuova con stili del
        // template precedente (Piano_annuale → Motivazione_voti ecc.).
        $pageContent = $pageHead . $pageContent;
        $pageHead    = '';
// ── Wrappa nella shell del sito (sidebar + upbar + modals) ──
        $isPartial = ($_SERVER['HTTP_X_PARTIAL'] ?? '') === '1';
        if ($isPartial) {
            return new Response($pageContent, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $pageTitle   = 'PANTEDU — ' . str_replace('_', ' ', (string)$tmpl['argomento']);
        $pageScripts = '';
        $bodyClass   = 'fm-studio-risdoc fm-studio-light';
        if (class_exists(Auth::class) && Auth::check() && Auth::hasAccess('admin')) {
            $bodyClass .= ' admin-access fm-admin-access';
        }
        if (class_exists(Auth::class) && Auth::check() && Auth::hasAccess('teacher')) {
            $bodyClass .= ' fm-teacher-access';
        }
        $currentRoute = $req->path ?? '';
        $base = dirname(__DIR__, 3);
        ob_start();
        include $base . '/views/layout/app.php';
        return new Response((string)ob_get_clean(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
