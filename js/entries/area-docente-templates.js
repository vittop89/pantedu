// Entry Vite di views/area_docente/templates.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

// ── blocco 1 ── (nella vista era dentro `if ($tab === 'risdoc')`)
import { notify } from "/js/modules/ui/sync-panel.js";
import { fetchJson, fetchCsrf, escHtml, escHtml as escapeHtml } from "/js/modules/core/dom-utils.js";
import { caricaEntry } from "/js/modules/core/carica-entry.js";

// Il tab attivo lo dice la vista (data-tab): ogni blocco gira solo nel suo tab,
// come quando era uno <script> inline dentro `if ($tab === '…')`.
const fmTab = document.getElementById("fm-templates-page")?.dataset.tab ?? "";
if (fmTab === "risdoc") {
/** G22.S13 — modal editor per i 3 file texCommon di risdoc.
 *  Usa templateFileAdapter (mode='risdoc-templates'): GET lista + POST save
 *  via /api/teacher/risdoc/templates/files. Backend gestisce cascade
 *  default→institute→teacher; admin salva a scope institute. */

function activeInstituteCode() {
    try { return sessionStorage.getItem("activeInstituteCode") || ""; }
    catch { return ""; }
}
async function refreshStatus() {
    const el = document.getElementById("fm-trd-status");
    if (!el) return;
    const code = activeInstituteCode();
    el.textContent = code
        ? `Istituto attivo: ${code} · 3 file texCommon condivisi (main.tex, risdoc.sty, intestaLAteX_IIS.tex)`
        : "Nessun istituto collegato — usi modello comune";
}
async function openRisdocEditor() {
    // 2026-05-28 — lazy-load verifica-preview-modal: il bundle bootstrap
    // lo importa solo se _fmEditorNeeded (pagine editor), NON sulla pagina
    // templates → window.FM.openVerificaPreview era undefined. Import inline
    // qui registra il loader e abilita il modal su click.
    if (typeof window.FM?.openVerificaPreview !== "function") {
        try { await import("/js/modules/features/verifica-preview-modal.js"); }
        catch (e) { console.error("[templates] verifica-preview-modal load failed:", e); }
    }
    const opener = window.FM?.openVerificaPreview;
    if (typeof opener !== "function") {
        alert("Modulo editor non disponibile (ricarica la pagina dopo il build).");
        return;
    }
    try {
        await opener([{
            id: "teacher-risdoc-templates",
            variant: "modelli-risdoc",
            title: "Modelli risdoc",
            institute: activeInstituteCode() || null,
        }], { mode: "risdoc-templates" });
    } catch (e) {
        console.error("[risdoc-templates] open failed:", e);
        alert(`Errore apertura editor: ${e.message}`);
    }
}
function bootstrap() {
    const btn = document.getElementById("fm-trd-open");
    if (!btn) return;
    btn.addEventListener("click", openRisdocEditor);
    document.addEventListener("fm:active-institute-changed", refreshStatus);
    refreshStatus();
    // G22.S15.bis Fase 5 — auto-open rimosso: il modal copriva i sub-tab
    // bloccando la navigazione. La pagina è il launcher; l'editor si apre
    // su click esplicito del pulsante "✏️ Apri editor modelli risdoc".
}
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bootstrap, { once: true });
} else {
    bootstrap();
}
}

// ── blocco 2 ── (nella vista era dentro `if ($tab === 'verifiche')`)
if (fmTab === "verifiche") {
/** G22.S10c — modal-based templates editor (sostituisce inline UI).
 *  Apre il modal full-screen via window.FM.openVerificaPreview con
 *  mode='template-file'. Il modal usa templateFileAdapter che fetcha
 *  l'intera manifest /api/teacher/verifica/files con paths + sources,
 *  carica content paralleli via /read, gestisce save/compile per file. */

function activeInstituteCode() {
    try { return sessionStorage.getItem("activeInstituteCode") || ""; }
    catch { return ""; }
}

async function refreshStatus() {
    const el = document.getElementById("fm-tvf-status");
    if (!el) return;
    const code = activeInstituteCode();
    el.textContent = code ? `Istituto attivo: ${code}` : "Nessun istituto collegato — usi modello comune";
}

async function openTemplatesEditor() {
    // 2026-05-28 — lazy-load verifica-preview-modal: il bundle bootstrap
    // lo importa solo se _fmEditorNeeded (pagine editor), NON sulla pagina
    // templates → window.FM.openVerificaPreview era undefined. Import inline
    // qui registra il loader e abilita il modal su click.
    if (typeof window.FM?.openVerificaPreview !== "function") {
        try { await import("/js/modules/features/verifica-preview-modal.js"); }
        catch (e) { console.error("[templates] verifica-preview-modal load failed:", e); }
    }
    const opener = window.FM?.openVerificaPreview;
    if (typeof opener !== "function") {
        alert("Modulo editor non disponibile (ricarica la pagina dopo il build).");
        return;
    }
    try {
        await opener([{
            id: "teacher-templates",
            variant: "modelli",
            title: "I miei modelli",
            institute: activeInstituteCode() || null,
        }], { mode: "template-file" });
    } catch (e) {
        console.error("[templates] open failed:", e);
        alert(`Errore apertura editor: ${e.message}`);
    }
}

function bootstrap() {
    const btn = document.getElementById("fm-tvf-open");
    if (!btn) return;
    btn.addEventListener("click", openTemplatesEditor);
    // G20.7 — riapri editor (forza refresh data) al cambio istituto sidebar.
    document.addEventListener("fm:active-institute-changed", () => {
        refreshStatus();
    });
    refreshStatus();
    // G22.S15.bis Fase 5 — auto-open rimosso: il modal full-screen
    // copriva i sub-tab bloccando la navigazione tra Verifiche/Esercizi/
    // Risdoc. La pagina è il launcher; l'editor si apre su click esplicito
    // del pulsante "✏️ Apri editor modelli".
}
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bootstrap, { once: true });
} else {
    bootstrap();
}
}

// ── blocco 3 ── (nella vista era dentro `if ($tab === 'drawio')`)
if (fmTab === "drawio") {
/** G22.S15.bis Fase 5 — Drawio shape libraries: list + upload + delete.
 *  Niente browser alert(): notifiche via SyncPanel (sync-panel.js).
 *  Conferma delete via flow inline: pending state + click conferma. */
(function() {
    const list = document.getElementById('fm-drawio-list');
    if (!list) return;
    const uploadInput = document.getElementById('fm-drawio-upload');
    const refreshBtn  = document.getElementById('fm-drawio-refresh');

    function fmtSize(b) {
        if (b < 1024) return `${b  } B`;
        if (b < 1024*1024) return `${Math.round(b/1024)  } KB`;
        return `${(b/(1024*1024)).toFixed(1)  } MB`;
    }
    function notify(title, kind, msg, ttl = 4000) {
        const sp = window.FM && window.FM.SyncPanel;
        if (sp && typeof sp.notify === 'function') {
            sp.notify(title, kind, msg, ttl);
        } else {
            console.info(`[${  title  }]`, msg);
        }
    }
    const csrf = (...a) => window.FM.DomUtils.fetchCsrf(...a); // lazy: window.FM pronto a call-time

    async function refresh() {
        try {
            const j = await window.FM.DomUtils.fetchJson('/api/teacher/drawio/libraries');
            if (!j.ok) {
                list.innerHTML = `<em>Errore: ${  escHtml(j.error || 'unknown')  }</em>`;
                return;
            }
            if (!j.libraries || j.libraries.length === 0) {
                list.innerHTML = '<em>Nessuna libreria caricata. Usa "Carica nuova libreria" per aggiungerne.</em>';
                return;
            }
            const html = j.libraries.map(lib => {
                const sourceTag = lib.source === 'teacher'
                    ? '<span class="fm-pill-success-sm">tua</span>'
                    : '<span class="fm-pill-muted-sm">default</span>';
                const delBtn = lib.source === 'teacher'
                    ? `<button class="fm-btn fm-btn--ghost fm-btn--sm" data-del="${escHtml(lib.name)}" title="Click per eliminare (richiede 2° click di conferma)">🗑</button>`
                    : '';
                return `<div class="fm-drawio-list__item">
                    <span>📐 <strong>${escHtml(lib.name)}</strong> ${sourceTag} <span class="fm-opacity-60 fm-ml-2">${fmtSize(lib.size)}</span></span>
                    <span>${delBtn}</span>
                </div>`;
            }).join('');
            list.innerHTML = html;
            // Inline confirm flow: 1° click → bottone diventa "Conferma?";
            // 2° click entro 4s → cancellazione effettiva. Niente confirm() browser.
            list.querySelectorAll('[data-del]').forEach(btn => {
                let pending = false;
                let pendingTimer = null;
                btn.addEventListener('click', async () => {
                    const name = btn.dataset.del;
                    if (!pending) {
                        pending = true;
                        const orig = btn.innerHTML;
                        btn.innerHTML = '⚠ Conferma?';
                        btn.style.background = '#fef2f2';
                        btn.style.color = '#b91c1c';
                        btn.style.borderColor = '#fca5a5';
                        pendingTimer = setTimeout(() => {
                            pending = false;
                            btn.innerHTML = orig;
                            btn.style.cssText = '';
                        }, 4000);
                        return;
                    }
                    clearTimeout(pendingTimer);
                    pending = false;
                    try {
                        const tok = await csrf();
                        const j = await window.FM.DomUtils.fetchJson('/api/teacher/drawio/libraries/delete', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tok },
                            body: JSON.stringify({ name, _csrf: tok }),
                        });
                        if (!j.ok) {
                            notify('🗑 Drawio', 'error', `Errore: ${  j.error || 'richiesta non riuscita'}`, 5000);
                            return;
                        }
                        notify('🗑 Drawio', 'ok', `Libreria "${name}" eliminata`, 3000);
                        await refresh();
                    } catch (e) {
                        notify('🗑 Drawio', 'error', `Errore di rete: ${  e.message}`, 5000);
                    }
                });
            });
        } catch (e) {
            list.innerHTML = `<em>Errore: ${  escHtml(e.message)  }</em>`;
        }
    }

    uploadInput?.addEventListener('change', async (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        if (file.size > 1024 * 1024) {
            notify('📤 Upload', 'error', 'File troppo grande (max 1 MB).', 4000);
            uploadInput.value = '';
            return;
        }
        if (!/\.xml$/i.test(file.name)) {
            notify('📤 Upload', 'error', 'Solo file .xml accettati.', 4000);
            uploadInput.value = '';
            return;
        }
        try {
            const tok = await csrf();
            const fd = new FormData();
            fd.append('file', file);
            fd.append('_csrf', tok);
            const j = await window.FM.DomUtils.fetchJson('/api/teacher/drawio/libraries/upload', {
                method: 'POST',
                headers: { 'X-CSRF-Token': tok },
                body: fd,
            });
            if (!j.ok) {
                notify('📤 Upload', 'error', `Errore: ${  j.error || 'richiesta non riuscita'}`, 5000);
                return;
            }
            notify('📤 Upload', 'ok', `"${j.name}" caricata (${fmtSize(j.size)})`, 3000);
            await refresh();
        } catch (err) {
            notify('📤 Upload', 'error', `Errore di rete: ${  err.message}`, 5000);
        } finally { uploadInput.value = ''; }
    });

    refreshBtn?.addEventListener('click', refresh);
    refresh();
})();
}

// ── blocco 4 ── (nella vista era dentro `if ($tab === 'tikz')`)
if (fmTab === "tikz") {
/** G22.S15.bis Fase 5+ — TikZ workspace tab.
 *  Riusa endpoint TeacherWorkspaceController + modal lazy
 *  (openTexElementEditor / openTikzBlocksManager) gia' esistenti. */

const groupsEl = document.getElementById("fm-tikz-groups");
let workspaceCache = null;

async function apiPost(url, body) {
    const csrf = await fetchCsrf();
    try {
        return await fetchJson(url, {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify(body || {}),
        });
    } catch (e) { return { ok: false, error: e.message }; }
}

async function loadWorkspace() {
    groupsEl.innerHTML = '<p class="fm-muted fm-text-center fm-p-5" >Caricamento…</p>';
    try {
        workspaceCache = await fetchJson("/tikz/workspace", { cache: "no-store" });
        renderWorkspace();
    } catch (e) {
        groupsEl.innerHTML = `<p class="fm-tikz-empty">Errore caricamento: ${e.message}</p>`;
    }
}

function renderWorkspace() {
    groupsEl.innerHTML = "";
    const data = workspaceCache;
    if (!data || typeof data !== "object" || Object.keys(data).length === 0) {
        groupsEl.innerHTML = '<p class="fm-tikz-empty">Workspace vuoto. Importa dalla libreria admin o crea un nuovo elemento.</p>';
        return;
    }
    for (const [groupKey, items] of Object.entries(data)) {
        if (!Array.isArray(items)) continue;
        groupsEl.appendChild(renderGroup(groupKey, items));
    }
}

function renderGroup(groupKey, items) {
    const name = groupKey.replace(/^gruppo-/, "");
    const wrap = document.createElement("div");
    wrap.className = "fm-tikz-group";
    wrap.dataset.group = groupKey;

    const hdr = document.createElement("div");
    hdr.className = "fm-tikz-group__hdr";
    hdr.innerHTML = `
        <span class="fm-tikz-chevron">▶</span>
        <span class="fm-tikz-group__title">${escapeHtml(name)}</span>
        <span class="fm-tikz-group__count">(${items.length})</span>
    `;
    const itemsBox = document.createElement("div");
    itemsBox.className = "fm-tikz-group__items";

    // Toggle expand on header click
    hdr.addEventListener("click", (e) => {
        if (e.target.closest("button")) return;
        wrap.classList.toggle("fm-tikz-group--open");
        const chev = hdr.querySelector(".fm-tikz-chevron");
        if (chev) chev.textContent = wrap.classList.contains("fm-tikz-group--open") ? "▼" : "▶";
    });

    // Header actions: ➕ ✏️ 🗑️
    const actions = document.createElement("div");
    actions.style.cssText = "display:flex;gap:4px;";
    const newBtn = mkBtn("➕", "Nuovo elemento in questo gruppo", () => addElementInGroup(groupKey));
    const renBtn = mkBtn("✏️", "Rinomina gruppo", () => renameGroup(groupKey));
    const delBtn = mkBtn("🗑️", "Elimina gruppo", () => deleteGroup(groupKey, items.length));
    actions.appendChild(newBtn); actions.appendChild(renBtn); actions.appendChild(delBtn);
    hdr.appendChild(actions);

    // Items
    for (const it of items) {
        itemsBox.appendChild(renderItem(groupKey, it));
    }
    wrap.appendChild(hdr);
    wrap.appendChild(itemsBox);
    return wrap;
}

function renderItem(groupKey, item) {
    const row = document.createElement("div");
    row.className = "fm-tikz-item";
    const label = item?.label || item?.name || "(senza nome)";
    const type = item?.type || "tikz";
    row.innerHTML = `
        <span class="fm-tikz-item__type">${escapeHtml(type).toUpperCase()}</span>
        <span class="fm-tikz-item__label">${escapeHtml(label)}</span>
    `;
    // Schema-modulare button: visibile solo se l'item HA dati schema
    // (item._data o marker __FM_TPL_DATA__ nel content). Permette modifica
    // dei valori iniziali via form.
    if (isSchemaModulare(item)) {
        const schemaBtn = mkBtn("📋 Schema", "Modifica i valori iniziali del form (schema modulare)", () => editElementSchema(groupKey, item));
        schemaBtn.style.padding = "3px 10px";
        row.appendChild(schemaBtn);
    }
    // Codice TikZ raw: sempre disponibile (editor CodeMirror sul content).
    const codeBtn = mkBtn("✏️ Codice", "Modifica il codice TikZ/LaTeX raw (CodeMirror)", () => editElementCode(groupKey, item));
    codeBtn.style.padding = "3px 10px";
    row.appendChild(codeBtn);
    const delBtn  = mkBtn("🗑️", "Elimina elemento", () => deleteElement(groupKey, item));
    row.appendChild(delBtn);
    return row;
}

function mkBtn(label, title, handler) {
    const b = document.createElement("button");
    b.type = "button";
    b.className = "fm-tikz-item__btn";
    b.textContent = label;
    b.title = title;
    b.addEventListener("click", (e) => { e.preventDefault(); e.stopPropagation(); handler(); });
    return b;
}


/* ───── Editor lazy-loader (riuso modali esistenti) ───── */
const FM_TPL_DATA_RE = /^%\s*__FM_TPL_DATA__:([A-Za-z0-9+/=]+)\s*$/m;
function extractTemplateData(content) {
    const m = (content || "").match(FM_TPL_DATA_RE);
    if (!m) return null;
    try { return JSON.parse(decodeURIComponent(escape(atob(m[1])))); }
    catch { try { return JSON.parse(atob(m[1])); } catch { return null; } }
}
function isSchemaModulare(item) {
    return !!item?._data || extractTemplateData(item?.content) !== null;
}

/** true se l'entry è pronta; se non lo è, l'avviso l'ha già dato caricaEntry. */
async function lazyLoadEntry(entryName, fmKey) {
    try {
        await caricaEntry(`js/entries/${entryName}`, { pronto: () => !!window.FM?.[fmKey] });
        return true;
    } catch {
        return false;
    }
}
const ensureElementEditor = () => lazyLoadEntry("tex-element-editor.js", "openTexElementEditor");
const ensureTemplateFiller = () => lazyLoadEntry("tikz-template-filler.js", "openTemplateFiller");
const ensureBlocksManager = () => lazyLoadEntry("tikz-blocks-manager.js", "openTikzBlocksManager");

/* ───── Actions ───── */
/** Dialog unificato "Nuovo / Importa": tab "Da zero" → openTexElementEditor,
 *  tab "Importa libreria" → checkbox-list multi-select con filtro tipo. */
async function addNewElement() {
    // Pre-fetch admin library in parallelo con UI render.
    const libPromise = fetch("/tikz/admin-library", { credentials: "same-origin", cache: "no-store" })
        .then(r => r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`)))
        .catch(e => { notify("Libreria", "warn", `Libreria non caricata: ${e.message}`, 5000); return {}; });

    document.getElementById("fm-tikz-new-dialog")?.remove();
    const dlg = document.createElement("div");
    dlg.id = "fm-tikz-new-dialog";
    dlg.className = "fm-tikz-newdlg";
    dlg.innerHTML = `
        <div class="fm-tikz-newdlg__panel">
            <div class="fm-tikz-newdlg__hdr">
                <span class="fm-flex-1-grow fm-fw-600">➕ Nuovo elemento</span>
                <button data-act="close" class="fm-tikz-newdlg__x">✕</button>
            </div>
            <div class="fm-tikz-newdlg__tabs">
                <button data-tab="scratch" class="fm-tikz-newdlg__tab fm-tikz-newdlg__tab--active">📝 Da zero</button>
                <button data-tab="import" class="fm-tikz-newdlg__tab">📚 Importa da libreria admin</button>
            </div>
            <div data-tabbody="scratch" class="fm-tikz-newdlg__body">
                <p class="fm-muted fm-m-0 fm-mb-3 fm-text-13" >
                    Crea un elemento vuoto in un gruppo nuovo o esistente.
                    L'editor CodeMirror si apre con i campi <strong>Tipo</strong>, <strong>Gruppo</strong>, <strong>Nome</strong>, <strong>Codice</strong>.
                </p>
                <button class="fm-btn fm-btn--primary" data-act="open-scratch">Apri editor →</button>
            </div>
            <div data-tabbody="import" class="fm-tikz-newdlg__body fm-d-none" >
                <div class="fm-tikz-newdlg__filters">
                    <input type="search" data-role="search" class="fm-tikz-newdlg__search" placeholder="Cerca per nome…">
                    <label><input type="checkbox" data-filter="tikz" checked> ✒️ tikz</label>
                    <label><input type="checkbox" data-filter="schema" checked> 📋 schema</label>
                    <label><input type="checkbox" data-filter="latex" checked> ✏️ latex</label>
                </div>
                <div data-role="list" class="fm-tikz-newdlg__list">
                    <p class="fm-muted fm-text-center fm-p-5" >Caricamento libreria…</p>
                </div>
                <div class="fm-tikz-newdlg__footer">
                    <span data-role="count" class="fm-muted fm-text-xs fm-flex-1-grow" >0 selezionati</span>
                    <button class="fm-btn fm-btn--ghost fm-btn--sm" data-act="select-all">Tutti</button>
                    <button class="fm-btn fm-btn--ghost fm-btn--sm" data-act="select-none">Nessuno</button>
                    <button class="fm-btn fm-btn--primary" data-act="import" disabled>📥 Importa selezionati</button>
                </div>
            </div>
        </div>`;
    document.body.appendChild(dlg);

    const qs = (sel) => dlg.querySelector(sel);
    const qsa = (sel) => Array.from(dlg.querySelectorAll(sel));
    const close = () => { dlg.remove(); document.removeEventListener("keydown", esc); };
    const esc = (e) => { if (e.key === "Escape") close(); };
    document.addEventListener("keydown", esc);
    dlg.addEventListener("click", (e) => {
        if (e.target?.dataset?.act === "close" || e.target === dlg) close();
    });

    // Tab switching
    qsa(".fm-tikz-newdlg__tab").forEach(t => t.addEventListener("click", () => {
        const k = t.dataset.tab;
        qsa(".fm-tikz-newdlg__tab").forEach(x => x.classList.toggle("fm-tikz-newdlg__tab--active", x === t));
        qsa("[data-tabbody]").forEach(b => { b.style.display = b.dataset.tabbody === k ? "" : "none"; });
    }));

    // Tab "Da zero"
    qs('[data-act="open-scratch"]').addEventListener("click", async () => {
        close();
        await openScratchEditor();
    });

    // Tab "Importa": render checkbox list (lazy: load only after tab switch first time)
    let library = null;
    const renderLibrary = async () => {
        if (library !== null) return;
        library = await libPromise;
        const listEl = qs('[data-role="list"]');
        listEl.innerHTML = "";
        if (!library || typeof library !== "object" || Object.keys(library).length === 0) {
            listEl.innerHTML = '<p class="fm-muted fm-text-center fm-p-5" >Libreria admin vuota.</p>';
            return;
        }
        for (const [gKey, items] of Object.entries(library)) {
            if (!Array.isArray(items)) continue;
            const gName = gKey.replace(/^gruppo-/, "");
            const gWrap = document.createElement("div");
            gWrap.className = "fm-tikz-newdlg__group";
            gWrap.dataset.group = gKey;
            gWrap.innerHTML = `<div class="fm-tikz-newdlg__ghdr">📂 ${escapeHtml(gName)} <span class="fm-muted fm-fw-400" >(${items.length})</span></div>`;
            items.forEach(it => {
                const t = inferType(it);
                const row = document.createElement("label");
                row.className = "fm-tikz-newdlg__row";
                row.dataset.gkey = gKey;
                row.dataset.label = it.label || "";
                row.dataset.type = t;
                row.innerHTML = `
                    <input type="checkbox" data-role="cb">
                    <span class="fm-tikz-item__type fm-tikz-newdlg__type fm-tikz-newdlg__type--${t}">${t.toUpperCase()}</span>
                    <span class="fm-flex-1-grow">${escapeHtml(it.label || "(senza nome)")}</span>
                `;
                gWrap.appendChild(row);
            });
            listEl.appendChild(gWrap);
        }
        // bind checkbox count
        listEl.addEventListener("change", updateCount);
        updateCount();
    };

    function inferType(it) {
        if (it?._data || (typeof it.content === "string" && /^%\s*__FM_TPL_DATA__:/m.test(it.content))) return "schema";
        return (it?.type || "tikz").toLowerCase();
    }
    function visibleRows() {
        const q = (qs('[data-role="search"]').value || "").trim().toLowerCase();
        const allowTypes = qsa('[data-filter]:checked').map(c => c.dataset.filter);
        return qsa(".fm-tikz-newdlg__row").filter(r => {
            if (!allowTypes.includes(r.dataset.type)) { r.style.display = "none"; return false; }
            if (q && !r.dataset.label.toLowerCase().includes(q)) { r.style.display = "none"; return false; }
            r.style.display = "";
            return true;
        });
    }
    function updateCount() {
        visibleRows();
        const checked = qsa('[data-role="cb"]:checked').filter(c => c.closest(".fm-tikz-newdlg__row").style.display !== "none");
        qs('[data-role="count"]').textContent = `${checked.length} selezionati`;
        qs('[data-act="import"]').disabled = checked.length === 0;
    }
    qs('[data-role="search"]').addEventListener("input", updateCount);
    qsa('[data-filter]').forEach(c => c.addEventListener("change", updateCount));
    qs('[data-act="select-all"]').addEventListener("click", () => {
        visibleRows().forEach(r => r.querySelector('[data-role="cb"]').checked = true);
        updateCount();
    });
    qs('[data-act="select-none"]').addEventListener("click", () => {
        qsa('[data-role="cb"]').forEach(c => c.checked = false);
        updateCount();
    });

    // Lazy render della libreria al primo click sul tab Importa
    qsa(".fm-tikz-newdlg__tab")[1].addEventListener("click", renderLibrary, { once: true });

    // Import
    qs('[data-act="import"]').addEventListener("click", async () => {
        const rows = qsa('[data-role="cb"]:checked').map(c => c.closest(".fm-tikz-newdlg__row"))
            .filter(r => r.style.display !== "none");
        if (rows.length === 0) return;
        const btn = qs('[data-act="import"]');
        btn.disabled = true;
        const orig = btn.textContent;
        let ok = 0, fail = 0;
        const conflicts = [];
        for (const r of rows) {
            btn.textContent = `Importando ${ok + fail + 1}/${rows.length}…`;
            const res = await apiPost("/tikz/workspace/import", {
                sourceGroupKey: r.dataset.gkey,
                sourceLabel: r.dataset.label,
                targetGroupKey: r.dataset.gkey,
                conflict: "abort",
            });
            if (res?.action === "created" || res?.success === true || res?.ok === true) {
                ok++;
            } else if (res?.action === "aborted") {
                conflicts.push({ gkey: r.dataset.gkey, label: r.dataset.label, row: r });
            } else {
                fail++;
            }
        }
        btn.textContent = orig;
        // Conflicts: ask once "overwrite all / skip all"
        if (conflicts.length > 0) {
            const ow = confirm(`${conflicts.length} elemento/i hanno conflitti di nome.\n\nOK = sovrascrivere tutti\nAnnulla = saltare tutti`);
            if (ow) {
                for (const c of conflicts) {
                    const res = await apiPost("/tikz/workspace/import", {
                        sourceGroupKey: c.gkey, sourceLabel: c.label,
                        targetGroupKey: c.gkey, conflict: "overwrite",
                    });
                    if (res?.success === true || res?.ok === true || res?.action === "created" || res?.action === "overwritten") ok++;
                    else fail++;
                }
            }
        }
        await loadWorkspace();
        close();
        const msg = fail === 0 ? `${ok} elementi importati.` : `${ok} importati, ${fail} falliti.`;
        notify("Importa", fail === 0 ? "ok" : "warn", msg, 4000);
    });

    // Helper per "Da zero" (ex addNewElement)
    async function openScratchEditor(groupKey = "") {
        if (!await ensureElementEditor()) return;
        const existingGroups = Object.keys(workspaceCache || {});
        window.FM.openTexElementEditor({
            mode: "new",
            groupKey,
            existingGroups,
            initialType: "tikz",
            initialLabel: "",
            initialCode: "",
            onSave: async ({ type, label, code, groupName, newGroup }) => {
                const targetKey = newGroup
                    ? (`gruppo-${  newGroup.toLowerCase().replace(/\s+/g, "-")}`)
                    : (groupName || groupKey);
                if (!targetKey || !label) {
                    notify("Salva", "warn", "Manca gruppo o nome", 4000);
                    return { ok: false };
                }
                const res = await apiPost("/tikz/workspace/element/save", { groupKey: targetKey, label, type, code });
                if (res?.success === true || res?.ok === true) {
                    notify("Elemento", "ok", "Creato nel tuo workspace", 3000);
                    await loadWorkspace();
                    return { ok: true };
                }
                notify("Errore", "error", res?.error || "?", 5000);
                return { ok: false, error: res?.error };
            },
            onCancel: () => {},
        });
    }
}

async function addElementInGroup(groupKey) {
    if (!await ensureElementEditor()) return;
    const existingGroups = Object.keys(workspaceCache || {});
    window.FM.openTexElementEditor({
        mode: "new",
        groupKey,
        existingGroups,
        initialType: "tikz",
        initialLabel: "",
        initialCode: "",
        onSave: async ({ type, label, code, groupName, newGroup }) => {
            const targetKey = newGroup
                ? (`gruppo-${  newGroup.toLowerCase().replace(/\s+/g, "-")}`)
                : (groupName || groupKey);
            const res = await apiPost("/tikz/workspace/element/save", { groupKey: targetKey, label, type, code });
            if (res?.success === true || res?.ok === true) {
                notify("Elemento", "ok", "Creato", 3000);
                await loadWorkspace();
                return { ok: true };
            }
            notify("Errore", "error", res?.error || "?", 5000);
            return { ok: false, error: res?.error };
        },
        onCancel: () => {},
    });
}

/** Modifica i valori iniziali del form schema-modulare. Disponibile solo
 *  per item con _data o marker __FM_TPL_DATA__ nel content. */
async function editElementSchema(groupKey, item) {
    const originalLabel = item.label || "";
    if (!await ensureTemplateFiller()) return;
    const initialData = (item._data && typeof item._data === "object")
        ? item._data
        : extractTemplateData(item.content);
    window.FM.openTemplateFiller("schema-modulare", initialData, /*onSave legacy*/ null, {
        title: `Schema modulare — ${originalLabel}`,
        isOverride: !!item._override,
        groupKey, label: originalLabel,
        onSavePref: async (tikzString, data) => {
            const res = await apiPost("/tikz/workspace/element/save", {
                groupKey, label: originalLabel, oldLabel: originalLabel,
                type: "tikz", code: tikzString, data,
            });
            if (res?.success === true || res?.ok === true) {
                notify("Schema", "ok", "Valori iniziali salvati", 3000);
                await loadWorkspace();
                return true;
            }
            notify("Errore", "error", res?.error || "?", 5000);
            return false;
        },
        onReset: async () => {
            const ok = confirm(`Ripristinare "${originalLabel}" dal default admin?`);
            if (!ok) return false;
            const res = await apiPost("/tikz/workspace/import", {
                sourceGroupKey: groupKey, sourceLabel: originalLabel,
                targetGroupKey: groupKey, conflict: "overwrite",
            });
            if (res?.success === true || res?.ok === true) {
                notify("Schema", "ok", "Ripristinato", 3000);
                await loadWorkspace();
                return true;
            }
            notify("Errore", "error", res?.error || "?", 5000);
            return false;
        },
    });
}

/** Modifica il codice TikZ/LaTeX raw via CodeMirror editor. Sempre
 *  disponibile, anche per item schema-modulare (in tal caso si edita
 *  il template TeX sottostante che il filler pre-popola). */
async function editElementCode(groupKey, item) {
    const originalLabel = item.label || "";
    if (!await ensureElementEditor()) return;
    window.FM.openTexElementEditor({
        mode: "insert",  // insert mode espone toolbar (Aggiungi/Salva/Reset/Chiudi)
        initialType: item.type || "tikz",
        initialCode: item.content || "",
        title: `Codice TikZ — ${originalLabel}`,
        actions: {
            isOverride: !!item._override,
            onAdd: null, // no panel context nel tab → Aggiungi disabilitato
            onSavePref: async (api) => {
                const code = api.getCode();
                if (!code.trim()) { notify("Salva", "warn", "Codice vuoto", 4000); return; }
                const data = extractTemplateData(code);
                const res = await apiPost("/tikz/workspace/element/save", {
                    groupKey, label: originalLabel, oldLabel: originalLabel,
                    type: api.getType(), code,
                    ...(data ? { data } : {}),
                });
                if (res?.success === true || res?.ok === true) {
                    notify("Codice", "ok", "Salvato", 3000);
                    await loadWorkspace();
                    api.close();
                } else {
                    notify("Errore", "error", res?.error || "?", 5000);
                }
            },
            onReset: async (api) => {
                const ok = confirm(`Ripristinare "${originalLabel}" dal default admin?`);
                if (!ok) return;
                const res = await apiPost("/tikz/workspace/import", {
                    sourceGroupKey: groupKey, sourceLabel: originalLabel,
                    targetGroupKey: groupKey, conflict: "overwrite",
                });
                if (res?.success === true || res?.ok === true) {
                    notify("Codice", "ok", "Ripristinato", 3000);
                    await loadWorkspace();
                    api.close();
                } else {
                    notify("Errore", "error", res?.error || "?", 5000);
                }
            },
        },
    });
}

async function deleteElement(groupKey, item) {
    const ok = confirm(`Eliminare "${item.label}" dal gruppo "${groupKey.replace(/^gruppo-/, "")}"?`);
    if (!ok) return;
    const res = await apiPost("/tikz/workspace/element/delete", { groupKey, label: item.label });
    if (res?.success === true || res?.ok === true) {
        notify("Elemento", "ok", "Eliminato", 2500);
        await loadWorkspace();
    } else {
        notify("Errore", "error", res?.error || "?", 5000);
    }
}

async function renameGroup(groupKey) {
    const oldName = groupKey.replace(/^gruppo-/, "");
    const newName = prompt(`Rinomina gruppo "${oldName}" in:`, oldName);
    if (!newName || newName.trim() === oldName) return;
    const res = await apiPost("/tikz/workspace/group/rename", {
        groupKey, newName: newName.trim(),
    });
    if (res?.success === true || res?.ok === true) {
        notify("Gruppo", "ok", "Rinominato", 2500);
        await loadWorkspace();
    } else {
        notify("Errore", "error", res?.error || "?", 5000);
    }
}

async function deleteGroup(groupKey, count) {
    const name = groupKey.replace(/^gruppo-/, "");
    const ok = confirm(`Eliminare il gruppo "${name}" con i suoi ${count} elementi DAL TUO WORKSPACE?\nI defaults admin restano intatti.`);
    if (!ok) return;
    const res = await apiPost("/tikz/workspace/group/delete", { groupKey });
    if (res?.success === true || res?.ok === true) {
        notify("Gruppo", "ok", "Eliminato", 2500);
        await loadWorkspace();
    } else {
        notify("Errore", "error", res?.error || "?", 5000);
    }
}

async function resetWorkspace() {
    const ok = confirm("RESET WORKSPACE: sostituire TUTTO il tuo workspace con i defaults admin?\nGruppi rinominati, elementi modificati, aggiunte personali — TUTTO andrà perso.\nL'operazione è irreversibile.");
    if (!ok) return;
    const res = await apiPost("/tikz/workspace/reset-all", {});
    if (res?.success === true || res?.ok === true) {
        notify("Workspace", "ok", "Resettato al default admin", 3000);
        await loadWorkspace();
    } else {
        notify("Errore", "error", res?.error || "?", 5000);
    }
}

async function openManager() {
    if (!await ensureBlocksManager()) return;
    // openTikzBlocksManager si aspetta un textarea-target. Passiamo un
    // <textarea> dummy (i bottoni Insert/Apply nel modal sono no-op se
    // non c'e' un block context — l'utente puo' comunque CRUD-are.
    const dummy = document.createElement("textarea");
    dummy.value = "";
    dummy._tikzBlocks = [];
    window.FM.openTikzBlocksManager(dummy);
}

/* ───── Bind ───── */
document.getElementById("fm-tikz-new")?.addEventListener("click", addNewElement);
document.getElementById("fm-tikz-manage")?.addEventListener("click", openManager);
document.getElementById("fm-tikz-refresh")?.addEventListener("click", loadWorkspace);
document.getElementById("fm-tikz-reset")?.addEventListener("click", resetWorkspace);
loadWorkspace();
}

// ── blocco 5 ── (nella vista era dentro `if ($tab === 'scorciatoie')`)
if (fmTab === "scorciatoie") {
function _mountSc() {
    const el = document.getElementById("fm-sc-editor");
    if (el && window.FM?.ShortcutsEditor) { window.FM.ShortcutsEditor.mount(el, { admin: false }); return true; }
    return false;
}
if (!_mountSc()) {
    let n = 0;
    const t = setInterval(() => { if (_mountSc() || ++n > 40) clearInterval(t); }, 100);
}
}
