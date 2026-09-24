/**
 * Risdoc override editor (Phase 21, U6).
 *
 * Layout: [HTML | TeX | CSS] tabs + textarea + panel guida (server-rendered).
 * Carica body via GET /api/risdoc/templates/{id}/file?kind=...
 * Salva via POST /api/risdoc/templates/{id}/override (body + kind + path).
 * Revert (remove override) via POST /api/risdoc/templates/{id}/override/del.
 *
 * Debounce save 800ms. Status indicator: idle|saving|saved|error.
 */
import { escAttr, escHtml, fetchCsrf } from "../core/dom-utils.js";

const STATE = {
    // Phase 24.30 — default tab is texCommon (only remaining)
    root: null, templateId: 0, currentKind: "texCommon",
    textarea: null, statusEl: null,
    jsonPicker: null, jsonSelect: null, jsonValidity: null,
    currentJsonPath: "", jsonFilesLoaded: false,
    // Phase 24.28: texCommon picker (3 file fissi)
    currentTexCommonPath: "main.tex",
    dirty: false, saveTimer: null, loaded: {},   // cache body per kind+path
};
const TEX_COMMON_FILES = ["main.tex", "risdoc.sty", "intestaLAteX_IIS.tex"];

let bound = false;

function init() {
    if (bound) return;
    const root = document.querySelector(".fm-re-editor[data-template-id]");
    if (!root) return;
    bound = true;

    STATE.root         = root;
    STATE.templateId   = parseInt(root.dataset.templateId, 10);
    STATE.textarea     = root.querySelector(".fm-re-textarea");
    STATE.statusEl     = root.querySelector(".fm-re-status");
    STATE.jsonPicker   = root.querySelector(".fm-re-jsonpicker");
    STATE.jsonSelect   = root.querySelector(".fm-re-json-select");
    STATE.jsonValidity = root.querySelector(".fm-re-json-validity");

    STATE.jsonSelect?.addEventListener("change", () => {
        // Phase 24.28: il picker è condiviso tra json e texCommon
        if (STATE.currentKind === "texCommon") {
            STATE.currentTexCommonPath = STATE.jsonSelect.value;
            loadKind("texCommon");
        } else {
            STATE.currentJsonPath = STATE.jsonSelect.value;
            loadKind("json");
        }
    });
    STATE.textarea.addEventListener("input", () => {
        if (STATE.currentKind === "json") validateJsonInline();
    });

    loadKind(STATE.currentKind);
    checkDrift();

    // Tab switch
    root.querySelectorAll(".fm-re-tab").forEach((tab) => {
        tab.addEventListener("click", async () => {
            if (STATE.dirty) { try { await saveNow(); } catch {} }
            const kind = tab.dataset.kind;
            root.querySelectorAll(".fm-re-tab").forEach(t => t.setAttribute("aria-selected", "false"));
            tab.setAttribute("aria-selected", "true");
            STATE.currentKind = kind;
            STATE.textarea.dataset.kind = kind;
            loadKind(kind);
        });
    });

    // Textarea input → dirty + debounce save
    STATE.textarea.addEventListener("input", () => {
        STATE.dirty = true;
        setStatus("saving");
        clearTimeout(STATE.saveTimer);
        STATE.saveTimer = setTimeout(() => { saveNow().catch(console.error); }, 800);
    });

    // Actions
    root.querySelector('[data-action="save"]')?.addEventListener("click", () => {
        saveNow().catch(e => window.FM?.ToastManager?.show?.("error", "Errore", e.message, 3000));
    });
    root.querySelector('[data-action="revert"]')?.addEventListener("click", async () => {
        if (!await window.FM.Dialog.confirm(`Ripristinare ${STATE.currentKind.toUpperCase()} al sorgente master?\n\nLe tue modifiche personali per questo kind verranno eliminate (non intacca altri docenti).`)) return;
        revertKind(STATE.currentKind).catch(e => window.FM?.ToastManager?.show?.("error", "Errore", e.message, 3000));
    });

    window.FM = window.FM || {};
    window.FM.RisdocEditor = { saveNow, loadKind, revertKind };
}

async function loadKind(kind) {
    setStatus("loading");
    // Hide image panel se cambiando a kind testuale
    const imgPanel = STATE.root?.querySelector(".fm-re-image-panel");
    if (imgPanel && kind !== "image") imgPanel.style.display = "none";
    if (kind !== "image") STATE.textarea.style.display = "";
    STATE.textarea.value = "";
    STATE.textarea.placeholder = `Caricamento ${kind.toUpperCase()}…`;

    // Tab JSON: mostra picker + carica lista file se non ancora fatto
    if (kind === "json") {
        if (STATE.jsonPicker) STATE.jsonPicker.style.display = "";
        if (!STATE.jsonFilesLoaded) await loadJsonFilesList();
        if (!STATE.currentJsonPath && STATE.jsonSelect?.options.length) {
            STATE.currentJsonPath = STATE.jsonSelect.value;
        }
        if (!STATE.currentJsonPath) {
            STATE.textarea.placeholder = "Nessun file JSON disponibile.";
            setStatus("idle");
            return;
        }
    } else if (kind === "texCommon") {
        // Phase 24.28: picker fisso 3 file texCommon (main.tex/risdoc.sty/intestaLAteX_IIS.tex)
        if (STATE.jsonPicker) {
            STATE.jsonPicker.style.display = "";
            if (STATE.jsonSelect && STATE.jsonSelect.dataset.kindMode !== "texCommon") {
                STATE.jsonSelect.dataset.kindMode = "texCommon";
                STATE.jsonSelect.innerHTML = TEX_COMMON_FILES
                    .map(f => `<option value="${f}">${f}</option>`).join("");
                STATE.jsonSelect.value = STATE.currentTexCommonPath;
            }
        }
    } else if (kind === "image") {
        // Phase 24.28 — placeholder UI per immagini override
        if (STATE.jsonPicker) STATE.jsonPicker.style.display = "none";
        renderImageManager();
        return;
    } else {
        if (STATE.jsonPicker) STATE.jsonPicker.style.display = "none";
        if (STATE.jsonSelect) STATE.jsonSelect.dataset.kindMode = "";
        if (STATE.jsonValidity) STATE.jsonValidity.textContent = "";
    }

    const path = kind === "json" ? STATE.currentJsonPath
              : kind === "texCommon" ? STATE.currentTexCommonPath
              : "";
    const url = `/api/risdoc/templates/${STATE.templateId}/file?kind=${encodeURIComponent(kind)}${
               path ? `&path=${encodeURIComponent(path)}` : ""}`;
    try {
        const res = await fetch(url, { credentials: "same-origin" });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const j = await res.json();
        const cacheKey = `${kind  }::${  path}`;
        STATE.loaded[cacheKey] = { body: j.body || "", source: j.source };
        STATE.textarea.value = j.body || "";
        STATE.textarea.dataset.source = j.source || "file";
        STATE.dirty = false;
        setStatus(j.source === "override" ? "override-active" : "idle");
        if (kind === "json") validateJsonInline();
    } catch (e) {
        STATE.textarea.placeholder = `Errore: ${e.message}`;
        setStatus("error");
    }
}

async function loadJsonFilesList() {
    try {
        const r = await fetch(`/api/risdoc/templates/${STATE.templateId}/json-files`, { credentials: "same-origin" });
        const j = await r.json();
        const files = j.files || [];
        if (STATE.jsonSelect) {
            STATE.jsonSelect.innerHTML = files.length
                ? files.map(f => `<option value="${escAttr(f.path)}">${escHtml(f.path)} (${Math.round(f.size / 1024)}KB)</option>`).join("")
                : '<option value="">(nessun file JSON)</option>';
        }
        STATE.jsonFilesLoaded = true;
    } catch (e) {
        console.warn("[risdoc-editor] loadJsonFilesList failed:", e);
    }
}

function validateJsonInline() {
    if (!STATE.jsonValidity) return;
    const v = STATE.textarea.value.trim();
    if (!v) { STATE.jsonValidity.textContent = ""; return; }
    try {
        JSON.parse(v);
        STATE.jsonValidity.style.color = "#059669";
        STATE.jsonValidity.textContent = "✓ JSON valido";
    } catch (e) {
        STATE.jsonValidity.style.color = "#dc2626";
        STATE.jsonValidity.textContent = `✕ ${  e.message}`;
    }
}

async function checkDrift() {
    try {
        const r = await fetch(`/api/risdoc/templates/${STATE.templateId}/drift`, { credentials: "same-origin" });
        const j = await r.json();
        if (!j.ok || !j.drifted?.length) return;
        const banner = document.querySelector(".fm-re-drift-banner");
        const list   = document.querySelector(".fm-re-drift-list");
        if (banner && list) {
            list.textContent = ` (${  j.drifted.map(d => `${d.kind}:${d.relative_path || "<main>"}`).join(", ")  })`;
            banner.style.display = "";
        }
    } catch (e) { /* silent */ }
}


async function saveNow() {
    if (!STATE.dirty) return;
    const csrf = await fetchCsrf();
    const body = new URLSearchParams({
        _csrf: csrf,
        kind: STATE.currentKind,
        path: pathForKind(STATE.currentKind),
        body: STATE.textarea.value,
    });
    setStatus("saving");
    const res = await fetch(`/api/risdoc/templates/${STATE.templateId}/override`, {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString(),
    });
    const j = await res.json();
    if (!res.ok || !j.ok) { setStatus("error"); throw new Error(j.error || `HTTP ${res.status}`); }
    STATE.dirty = false;
    STATE.textarea.dataset.source = "override";
    setStatus("saved");
    setTimeout(() => setStatus("idle"), 2000);
}

async function revertKind(kind) {
    const csrf = await fetchCsrf();
    const body = new URLSearchParams({ _csrf: csrf, kind, path: pathForKind(kind) });
    setStatus("saving");
    const res = await fetch(`/api/risdoc/templates/${STATE.templateId}/override/del`, {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString(),
    });
    if (!res.ok) { setStatus("error"); throw new Error(`HTTP ${res.status}`); }
    setStatus("saved");
    await loadKind(kind);
}

function pathForKind(kind) {
    // Per html/tex/css usiamo path vuoto = file principale del template
    // (resolver fa fallback a html_file / tex_file / css_file).
    // Per json, usiamo il file selezionato dal picker (U7).
    // Per texCommon, picker fisso 3 file. Per image, path = images/foo.png
    if (kind === "json")      return STATE.currentJsonPath;
    if (kind === "texCommon") return STATE.currentTexCommonPath;
    return "";
}

/**
 * Phase 24.28 — Image manager UI (override loghi/immagini per il template).
 * Mostra grid + upload form. Su submit fa POST kind=image multipart.
 */
function renderImageManager() {
    const ta = STATE.textarea;
    if (!ta) return;
    const wrap = ta.parentElement;
    let panel = wrap.querySelector(".fm-re-image-panel");
    if (!panel) {
        panel = document.createElement("div");
        panel.className = "fm-re-image-panel";
        panel.style.cssText = "flex:1; padding:16px; overflow:auto; background:#f8fafc;";
        wrap.appendChild(panel);
    }
    ta.style.display = "none";
    panel.style.display = "";
    panel.innerHTML = `
        <h3 style="margin:0 0 8px;font-size:14px">🖼 Immagini override</h3>
        <p style="font-size:12px;color:#475569;margin:0 0 12px">
            Carica un file immagine (PNG/JPG/SVG) che sostituisca il logo o la grafica
            del template per il tuo account. Path target = <code>images/&lt;nome-file&gt;</code>.
        </p>
        <form class="fm-re-img-form" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;
                margin-bottom:12px;padding:10px;background:#fff;border:1px dashed #cbd5e1;border-radius:6px">
            <label style="font-size:12px">Path:
                <input type="text" name="path" placeholder="images/logo_scuola.png"
                       value="images/logo_scuola.png"
                       style="font-size:12px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:3px;width:280px">
            </label>
            <input type="file" name="file" accept="image/*" required
                   style="font-size:12px">
            <button type="submit" class="fm-re-btn fm-re-btn--primary" style="font-size:12px">⬆ Upload</button>
        </form>
        <div class="fm-re-img-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px"></div>
    `;
    panel.querySelector(".fm-re-img-form").addEventListener("submit", async (ev) => {
        ev.preventDefault();
        const form = ev.target;
        const path = form.path.value.trim();
        const file = form.file.files[0];
        if (!path || !file) return;
        const csrf = await fetchCsrf();
        const fd = new FormData();
        fd.append("_csrf", csrf);
        fd.append("kind", "image");
        fd.append("path", path);
        fd.append("file", file);
        setStatus("saving");
        try {
            const r = await fetch(`/api/risdoc/templates/${STATE.templateId}/override`, {
                method: "POST", credentials: "same-origin", body: fd,
            });
            const j = await r.json();
            if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
            setStatus("saved");
            window.FM?.ToastManager?.show?.("success", "Upload OK", `${path}: ${j.image_hash?.slice(0,12)}…`, 3000);
            await loadImageList();
            setTimeout(() => setStatus("idle"), 1500);
        } catch (e) {
            setStatus("error");
            window.FM?.ToastManager?.show?.("error", "Upload fallito", e.message, 4000);
        }
    });
    loadImageList();
}

async function loadImageList() {
    const list = STATE.root.querySelector(".fm-re-img-list");
    if (!list) return;
    try {
        const r = await fetch(`/api/risdoc/templates/${STATE.templateId}/overrides`, {
            credentials: "same-origin",
        });
        const j = await r.json();
        const images = (j.overrides || []).filter(o => o.kind === "image");
        list.innerHTML = images.length === 0
            ? '<div style="grid-column:1/-1;font-size:12px;color:#64748b">(nessuna immagine override)</div>'
            : images.map(o => `
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:4px;padding:8px;font-size:11px">
                    <img src="/api/risdoc/templates/${STATE.templateId}/file?kind=image&path=${encodeURIComponent(o.relative_path)}"
                         alt="${escAttr(o.relative_path)}"
                         style="width:100%;height:100px;object-fit:contain;background:#f1f5f9;border-radius:3px;margin-bottom:6px">
                    <div style="word-break:break-all">${escHtml(o.relative_path)}</div>
                </div>
            `).join("");
    } catch (e) {
        list.innerHTML = `<div style="color:#b91c1c">Errore: ${escHtml(e.message)}</div>`;
    }
}

function setStatus(s) {
    if (!STATE.statusEl) return;
    STATE.statusEl.dataset.status = s;
    STATE.statusEl.textContent = ({
        idle: "",
        loading: "Carico…",
        saving: "Salvo…",
        saved: "Salvato ✓",
        error: "Errore ✕",
        "override-active": "Override attivo",
    }[s]) || s;
}

if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
else queueMicrotask(init);
window.addEventListener("fm:navigated", init);
