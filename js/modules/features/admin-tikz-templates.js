import { escAttr } from "../core/dom-utils.js";
/**
 * G22.S15.bis — Admin UI per gestire i template TikZ/LaTeX globali (defaults).
 *
 * Carica /modelli_tikz_elements.json (NO effective: vogliamo i defaults
 * crudi, niente teacher overrides) e renderizza una lista gruppi+items
 * con CRUD admin tramite gli endpoint esistenti:
 *   POST /tikz/save-new-element
 *   POST /tikz/edit-element
 *   POST /tikz/delete-element
 *
 * Modali riusati (lazy import dal manifest Vite):
 *   tex-element-editor (mode "edit"/"new") — CM6 + preview, label+group+type
 *   tikz-template-filler — solo per editare valori schema modulare
 *
 * Gating: la pagina è già protetta da /admin route group (admin role).
 */

const STATUS_EL = () => document.getElementById("fm-tikz-admin-status");
const CONTAINER = () => document.getElementById("fm-tikz-admin-groups");

let _cache = null;

function setStatus(text, color = "#999") {
    const s = STATUS_EL();
    if (!s) return;
    s.textContent = text || "";
    s.style.color = color;
}

// ─────────────────────── confirm dialog (dark) ───────────────────────
function confirmDialog({ title = "Conferma", message = "Sicuro?", confirmLabel = "OK", cancelLabel = "Annulla", danger = false } = {}) {
    return new Promise((resolve) => {
        document.getElementById("fm-tikz-admin-confirm")?.remove();
        const dlg = document.createElement("div");
        dlg.id = "fm-tikz-admin-confirm";
        dlg.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:100050;display:flex;align-items:center;justify-content:center;font:13px/1.4 system-ui";
        const confirmBg = danger ? "#c02a2a" : "#2a5ac7";
        dlg.innerHTML = `
            <div style="background:#1e1e1e;color:#ddd;border:1px solid #444;border-radius:8px;box-shadow:0 12px 48px rgba(0,0,0,0.6);min-width:380px;max-width:90vw;overflow:hidden">
                <div style="padding:12px 16px;background:#2a2a2a;border-bottom:1px solid #444;font-weight:600">${escAttr(title)}</div>
                <div style="padding:18px 16px;color:#ccc;white-space:pre-wrap">${escAttr(message)}</div>
                <div style="padding:10px 12px;background:#252525;border-top:1px solid #444;display:flex;gap:8px;justify-content:flex-end">
                    <button data-act="cancel" style="padding:6px 14px;background:#3a3a3a;color:#ddd;border:1px solid #555;border-radius:4px;cursor:pointer">${escAttr(cancelLabel)}</button>
                    <button data-act="ok" style="padding:6px 14px;background:${confirmBg};color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600">${escAttr(confirmLabel)}</button>
                </div>
            </div>`;
        document.body.appendChild(dlg);
        const done = (v) => { dlg.remove(); document.removeEventListener("keydown", kh); resolve(v); };
        const kh = (e) => { if (e.key === "Escape") done(false); else if (e.key === "Enter") done(true); };
        document.addEventListener("keydown", kh);
        dlg.addEventListener("click", (e) => {
            const a = e.target?.dataset?.act;
            if (a === "ok") done(true);
            else if (a === "cancel") done(false);
            else if (e.target === dlg) done(false);
        });
        dlg.querySelector('[data-act="ok"]').focus();
    });
}

// ─────────────────────── lazy-load editors ───────────────────────
async function loadEntry(entryKey) {
    const cacheBust = `?t=${Date.now()}`;
    const res = await fetch(`/build/manifest.json${cacheBust}`, { credentials: "same-origin", cache: "no-store" });
    if (!res.ok) throw new Error(`manifest HTTP ${res.status} — npm run build`);
    const manifest = await res.json();
    const entry = manifest[entryKey];
    if (!entry) throw new Error(`entry ${entryKey} assente — npm run build`);
    await import(/* @vite-ignore */ `/build/${entry.file}`);
}

async function ensureCm6Editor() {
    if (window.FM?.openTexElementEditor) return;
    await loadEntry("js/entries/tex-element-editor.js");
    if (!window.FM?.openTexElementEditor) throw new Error("bundle non popola FM.openTexElementEditor");
}

async function ensureFiller() {
    if (window.FM?.openTemplateFiller) return;
    await loadEntry("js/entries/tikz-template-filler.js");
    if (!window.FM?.openTemplateFiller) throw new Error("bundle non popola FM.openTemplateFiller");
}

// ─────────────────────── API calls ───────────────────────
async function apiPost(url, payload) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
    const res = await fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": csrf,
            "Accept": "application/json",
        },
        body: JSON.stringify(payload || {}),
    });
    let json = null;
    try { json = await res.json(); } catch (_) { json = null; }
    if (!res.ok) return { ok: false, status: res.status, error: json?.error || `HTTP ${res.status}` };
    return json || { ok: true };
}

// ─────────────────────── data-marker round-trip ───────────────────────
const FM_TPL_DATA_RE = /^%\s*__FM_TPL_DATA__:([A-Za-z0-9+/=]+)\s*$/m;
function extractTemplateData(content) {
    const m = (content || "").match(FM_TPL_DATA_RE);
    if (!m) return null;
    try { return JSON.parse(decodeURIComponent(escape(atob(m[1])))); }
    catch (_) { try { return JSON.parse(atob(m[1])); } catch (__) { return null; } }
}

// ─────────────────────── render groups+items ───────────────────────
async function loadAndRender() {
    const c = CONTAINER();
    if (!c) return;
    setStatus("Carico…");
    try {
        const res = await fetch("/modelli_tikz_elements.json", { credentials: "same-origin", cache: "no-store" });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        _cache = await res.json();
        renderAll(c, _cache);
        setStatus(`OK — ${Object.keys(_cache).length} gruppi`, "#aaa");
    } catch (e) {
        c.innerHTML = `<div style="padding:12px;color:#c02a2a">Errore caricamento: ${e.message}</div>`;
        setStatus(`Errore: ${e.message}`, "#c02a2a");
    }
}

function renderAll(container, data) {
    container.innerHTML = "";
    if (!data || typeof data !== "object" || Object.keys(data).length === 0) {
        container.innerHTML = `<div style="padding:12px;color:#999;font-style:italic">Nessun template (clicca ➕ Nuovo per crearne uno).</div>`;
        return;
    }
    Object.entries(data).forEach(([groupKey, items]) => {
        if (!Array.isArray(items)) return;
        container.appendChild(renderGroup(groupKey, items));
    });
}

function renderGroup(groupKey, items) {
    const name = groupKey.replace(/^gruppo-/, "");
    const group = document.createElement("div");
    group.className = "fm-tex-group";
    group.dataset.group = groupKey;
    group.style.cssText = "border-top:1px solid #333";

    const hdr = document.createElement("div");
    hdr.style.cssText = "display:flex;align-items:center;gap:6px;padding:6px 12px;background:#262626";

    const hdrBtn = document.createElement("button");
    hdrBtn.type = "button";
    hdrBtn.style.cssText = "flex:1;text-align:left;border:none;background:transparent;cursor:pointer;font:600 13px/1.3 system-ui;color:#ddd;padding:4px 0";
    hdrBtn.innerHTML = `<span class="fm-chevron">▶</span> ${escAttr(name)} <span style="color:#999;font-weight:normal">(${items.length})</span>`;

    const list = document.createElement("div");
    list.style.cssText = "display:none;background:#222";

    hdrBtn.addEventListener("click", () => {
        const chev = hdrBtn.querySelector(".fm-chevron");
        if (list.style.display === "none") { list.style.display = "block"; chev.textContent = "▼"; }
        else { list.style.display = "none"; chev.textContent = "▶"; }
    });

    const mkBtn = (icon, title, color, fn) => {
        const b = document.createElement("button");
        b.type = "button"; b.textContent = icon; b.title = title;
        b.style.cssText = `padding:3px 8px;background:${color};border:1px solid #555;border-radius:3px;cursor:pointer;font:13px/1 system-ui;color:#ddd`;
        b.addEventListener("click", (e) => { e.preventDefault(); e.stopPropagation(); fn(); });
        return b;
    };

    hdr.appendChild(hdrBtn);
    hdr.appendChild(mkBtn("➕", `Aggiungi elemento al gruppo "${name}"`, "#1e3a1e",
        () => openNewElement(groupKey)));
    hdr.appendChild(mkBtn("✏️", `Rinomina gruppo "${name}"`, "#2a3a5a",
        () => renameGroup(groupKey, items)));
    hdr.appendChild(mkBtn("🗑️", `Elimina intero gruppo "${name}"`, "#3a1e1e",
        () => deleteGroup(groupKey, name, items.length)));

    items.forEach((it, idx) => list.appendChild(renderItem(groupKey, it, idx)));

    group.appendChild(hdr);
    group.appendChild(list);
    return group;
}

function renderItem(groupKey, it, idx) {
    const tplKnown = /\\schemaModulare\b|\\schemaModulareCore\b/.test(it.content || "");
    const row = document.createElement("div");
    row.style.cssText = "display:flex;align-items:center;gap:4px;padding:4px 12px 4px 28px;background:#222;border-bottom:1px solid #2a2a2a";

    const lbl = document.createElement("span");
    lbl.textContent = it.label || `(elemento ${idx})`;
    lbl.title = `Tipo: ${it.type || "tikz"}${  tplKnown ? " — schema modulare" : ""}`;
    lbl.style.cssText = "flex:1;color:#ddd;font:12px/1.3 system-ui;cursor:default;user-select:text";

    const editBtn = document.createElement("button");
    editBtn.type = "button"; editBtn.textContent = "✏️";
    editBtn.title = tplKnown ? "Modifica (CM6 + preview, oppure usa 📋 Filler nel modale)"
                              : "Modifica (CM6 + preview)";
    editBtn.style.cssText = "padding:3px 8px;background:#2a3a5a;border:1px solid #4a6a9a;border-radius:3px;cursor:pointer;font:11px/1 system-ui;color:#ddd";
    editBtn.addEventListener("click", () => editElement(groupKey, it, tplKnown));

    const delBtn = document.createElement("button");
    delBtn.type = "button"; delBtn.textContent = "🗑️";
    delBtn.title = "Elimina questo elemento";
    delBtn.style.cssText = "padding:3px 8px;background:#3a1e1e;border:1px solid #c02a2a;border-radius:3px;cursor:pointer;font:11px/1 system-ui;color:#ddd";
    delBtn.addEventListener("click", () => deleteElement(groupKey, it));

    row.appendChild(lbl);
    row.appendChild(editBtn);
    row.appendChild(delBtn);
    return row;
}

// ─────────────────────── CRUD admin ───────────────────────
async function openNewElement(groupKey = "") {
    try { await ensureCm6Editor(); } catch (e) { setStatus(e.message, "#c02a2a"); return; }
    const existingGroups = Object.keys(_cache || {});
    window.FM.openTexElementEditor({
        mode: "new",
        groupKey,
        initialType: "tikz",
        initialLabel: "",
        initialCode: "",
        existingGroups,
        onSave: async ({ type, label, code, groupName, newGroup }) => {
            const res = await apiPost("/tikz/save-new-element", {
                groupName: newGroup || "",
                existingGroup: !newGroup ? groupName : "",
                elementType: type, label, code,
            });
            if (res?.success === true || res?.ok === true) {
                setStatus("Elemento creato", "#7c7");
                await loadAndRender();
                return { ok: true };
            }
            setStatus(`Errore: ${res?.error || "?"}`, "#c02a2a");
            return { ok: false, error: res?.error };
        },
    });
}

async function editElement(groupKey, it, tplKnown) {
    try { await ensureCm6Editor(); } catch (e) { setStatus(e.message, "#c02a2a"); return; }
    const extraToolbar = tplKnown ? [{
        label: "📋 Apri/aggiorna via Filler",
        title: "Apre il Template Filler con i valori attuali; al Save sostituisce il codice TikZ nel CM6",
        onClick: (api) => openFillerForCm6(api, it),
    }] : [];
    window.FM.openTexElementEditor({
        mode: "edit",
        groupKey,
        elementLabel: it.label || "",
        initialType: it.type || "tikz",
        initialLabel: it.label || "",
        initialCode: it.content || "",
        extraToolbar,
        onSave: async ({ type, label, code }) => {
            const res = await apiPost("/tikz/edit-element", {
                groupName: groupKey,
                elementLabel: it.label || "",
                elementType: type, label, code,
            });
            if (res?.success === true || res?.ok === true) {
                setStatus("Elemento aggiornato", "#7c7");
                await loadAndRender();
                return { ok: true };
            }
            setStatus(`Errore: ${res?.error || "?"}`, "#c02a2a");
            return { ok: false, error: res?.error };
        },
    });
}

async function openFillerForCm6(api, item) {
    try { await ensureFiller(); } catch (e) { setStatus(e.message, "#c02a2a"); return; }
    const currentCode = api?.getCode ? api.getCode() : (item.content || "");
    const initialData = extractTemplateData(currentCode) || extractTemplateData(item.content);
    window.FM.openTemplateFiller("schema-modulare", initialData, (tikzString) => {
        if (api?.setCode) api.setCode(tikzString);
    });
}

async function deleteElement(groupKey, it) {
    const ok = await confirmDialog({
        title: "Elimina elemento",
        message: `Eliminare "${it.label || "(senza nome)"}" dal gruppo "${groupKey.replace(/^gruppo-/, "")}"?\nQuesto modifica i DEFAULTS GLOBALI: tutti i docenti vedranno la modifica al prossimo refresh.`,
        confirmLabel: "Elimina", danger: true,
    });
    if (!ok) return;
    const res = await apiPost("/tikz/delete-element", {
        groupName: groupKey, deleteWholeGroup: "false", elementLabel: it.label || "",
    });
    if (res?.success === true || res?.ok === true) {
        setStatus("Eliminato", "#7c7");
        await loadAndRender();
    } else {
        setStatus(`Errore: ${res?.error || "?"}`, "#c02a2a");
    }
}

async function deleteGroup(groupKey, name, count) {
    const ok = await confirmDialog({
        title: "Elimina gruppo",
        message: `Eliminare l'INTERO gruppo "${name}" con ${count} elementi?\nOperazione irreversibile, modifica i DEFAULTS GLOBALI per tutti.`,
        confirmLabel: "Elimina gruppo", danger: true,
    });
    if (!ok) return;
    const res = await apiPost("/tikz/delete-element", {
        groupName: groupKey, deleteWholeGroup: "true", elementLabel: "",
    });
    if (res?.success === true || res?.ok === true) {
        setStatus("Gruppo eliminato", "#7c7");
        await loadAndRender();
    } else {
        setStatus(`Errore: ${res?.error || "?"}`, "#c02a2a");
    }
}

async function renameGroup(groupKey, items) {
    if (!items || items.length === 0) {
        setStatus("Gruppo vuoto, impossibile rinominare", "#c02a2a");
        return;
    }
    const currentName = groupKey.replace(/^gruppo-/, "");
    const newName = await new Promise((resolve) => {
        document.getElementById("fm-tikz-admin-rename")?.remove();
        const dlg = document.createElement("div");
        dlg.id = "fm-tikz-admin-rename";
        dlg.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:100050;display:flex;align-items:center;justify-content:center;font:13px/1.4 system-ui";
        dlg.innerHTML = `
            <div style="background:#1e1e1e;color:#ddd;border:1px solid #444;border-radius:8px;box-shadow:0 12px 48px rgba(0,0,0,0.6);min-width:420px;max-width:90vw;overflow:hidden">
                <div style="padding:12px 16px;background:#2a2a2a;border-bottom:1px solid #444;font-weight:600">Rinomina gruppo</div>
                <div style="padding:18px 16px;display:flex;flex-direction:column;gap:10px">
                    <input class="fm-grn-input" value="${escAttr(currentName)}" autocomplete="off"
                        style="padding:8px 10px;background:#2a2a2a;color:#ddd;border:1px solid #555;border-radius:4px;font:13px Consolas,monospace">
                    <div style="font-size:11px;color:#888">Spazi → trattini (es: "Studio Funzioni" → gruppo-studio-funzioni).</div>
                </div>
                <div style="padding:10px 12px;background:#252525;border-top:1px solid #444;display:flex;gap:8px;justify-content:flex-end">
                    <button data-act="cancel" style="padding:6px 14px;background:#3a3a3a;color:#ddd;border:1px solid #555;border-radius:4px;cursor:pointer">Annulla</button>
                    <button data-act="ok" style="padding:6px 14px;background:#2a5ac7;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600">Salva</button>
                </div>
            </div>`;
        document.body.appendChild(dlg);
        const input = dlg.querySelector(".fm-grn-input");
        input.focus(); input.select();
        const close = (val) => { dlg.remove(); document.removeEventListener("keydown", kh); resolve(val); };
        const kh = (e) => { if (e.key === "Escape") close(null); else if (e.key === "Enter") close(input.value.trim()); };
        document.addEventListener("keydown", kh);
        dlg.addEventListener("click", (e) => {
            const a = e.target?.dataset?.act;
            if (a === "ok") close(input.value.trim());
            else if (a === "cancel") close(null);
            else if (e.target === dlg) close(null);
        });
    });
    if (!newName || newName === currentName) return;
    const first = items[0];
    const res = await apiPost("/tikz/edit-element", {
        groupName: groupKey,
        elementLabel: first.label || "",
        elementType: first.type || "tikz",
        newGroupName: newName,
        label: first.label || "",
        code: first.content || "",
    });
    if (res?.success === true || res?.ok === true) {
        setStatus("Gruppo rinominato", "#7c7");
        await loadAndRender();
    } else {
        setStatus(`Errore: ${res?.error || "?"}`, "#c02a2a");
    }
}

// ─────────────────────── init quando tab "tikz" è attiva ───────────────────────
function init() {
    const addBtn = document.getElementById("fm-tikz-admin-add");
    const refreshBtn = document.getElementById("fm-tikz-admin-refresh");
    if (addBtn) addBtn.addEventListener("click", () => openNewElement(""));
    if (refreshBtn) refreshBtn.addEventListener("click", () => loadAndRender());

    // Carica al primo display della tab tikz (lazy: evita fetch se utente
    // non apre la tab).
    let loaded = false;
    const tabBtns = document.querySelectorAll('.fm-admin-tab[data-tab="tikz"]');
    tabBtns.forEach((b) => b.addEventListener("click", () => {
        if (!loaded) { loaded = true; loadAndRender(); }
    }));

    // Se la pagina viene aperta con #tikz nell'URL, attiva subito.
    if (location.hash === "#tikz") {
        loaded = true;
        loadAndRender();
    }
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
