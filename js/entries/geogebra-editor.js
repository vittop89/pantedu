/**
 * G22.S15.bis Fase 4 — Modal editor GeoGebra (applet + preview SVG live).
 *
 * Lazy-loaded da checkin-handlers al click del bottone "📐 GeoGebra".
 *
 * Pipeline:
 *   1. Inietta lo script deployggb.js (CDN GeoGebra, ~1MB lazy)
 *   2. Crea un applet via window.GGBApplet({...}) con (opt) ggbBase64 iniziale
 *   3. Pannello sinistro: applet GeoGebra interattivo
 *   4. Pannello destro: preview SVG live (refresh debounced via getXML hash)
 *   5. Toolbar 4 bottoni:
 *        ➕ Aggiungi → exportSVG + getBase64 → callback al chiamante (textarea quesito)
 *        💾 Salva nel catalogo → POST /geogebra/catalog/save (label da prompt)
 *        🔄 Reset → ricarica stato originale (ggbBase64 iniziale) o new file
 *        ✕ Chiudi → close (con conferma se modificato)
 *
 * API:
 *   import { openGeoGebraEditor } from "/js/entries/geogebra-editor.js";
 *   openGeoGebraEditor({
 *     initialGgbBase64: "UEsD..." | null,   // stato di partenza
 *     initialLabel: "..." | null,           // pre-fill label per "Salva nel catalogo"
 *     itemId: "..." | null,                 // se editing item del catalogo
 *     onAdd: ({ggb_b64, svg, label}) => true|false,  // inserisci nel quesito
 *     onSavedToCatalog: ({id, label}) => void,       // dopo save catalogo
 *     onCancel: () => void,
 *   });
 */

import { escAttr } from "../modules/core/dom-utils.js";

const DEPLOY_URL = "https://www.geogebra.org/apps/deployggb.js";
const APPLET_CONTAINER_ID = "fm-ggb-applet-container";

let _modalState = null;
let _styleInjected = false;
let _deployScriptLoading = null;

function injectStyles() { /* ADR-023 Fase 2: CSS spostato in css/modules/ */ }

/** Lazy-load deployggb.js CDN. Cached: secondi load = no-op. */
function loadDeployScript() {
    if (typeof window.GGBApplet === "function") return Promise.resolve();
    if (_deployScriptLoading) return _deployScriptLoading;
    _deployScriptLoading = new Promise((resolve, reject) => {
        const s = document.createElement("script");
        s.src = DEPLOY_URL;
        s.async = true;
        s.onload = () => {
            if (typeof window.GGBApplet === "function") resolve();
            else reject(new Error("deployggb.js caricato ma window.GGBApplet assente"));
        };
        s.onerror = () => reject(new Error("Impossibile caricare deployggb.js (CDN GeoGebra)"));
        document.head.appendChild(s);
    });
    return _deployScriptLoading;
}

let _previewTimer = null;
let _lastSvgHash = "";

function quickHash(s) {
    let h = 0;
    for (let i = 0; i < s.length; i++) { h = ((h << 5) - h + s.charCodeAt(i)) | 0; }
    return String(h);
}

async function refreshPreview(previewEl, applet) {
    const status = previewEl.querySelector(".preview-status");
    if (status) status.textContent = "rendering…";
    if (!applet || typeof applet.exportSVG !== "function") {
        if (status) status.textContent = "applet non pronto";
        return;
    }
    try {
        // exportSVG è async via callback. Wrap in promise.
        const svg = await new Promise((resolve) => {
            try {
                applet.exportSVG((s) => resolve(s || ""));
            } catch (_) { resolve(""); }
        });
        const h = quickHash(svg);
        if (h === _lastSvgHash) {
            if (status) status.textContent = "ok";
            return;
        }
        _lastSvgHash = h;
        previewEl.innerHTML = '<div class="preview-status">ok</div>';
        const div = document.createElement("div");
        div.innerHTML = svg;
        const inserted = div.querySelector("svg");
        if (inserted) previewEl.appendChild(inserted);
        else previewEl.appendChild(div);
    } catch (e) {
        previewEl.innerHTML = '<div class="preview-status">errore</div>';
        const err = document.createElement("div");
        err.className = "err";
        err.textContent = "Errore: " + (e.message || e);
        previewEl.appendChild(err);
    }
}

function debouncePreview(previewEl, getApplet, ms = 600) {
    clearTimeout(_previewTimer);
    _previewTimer = setTimeout(() => refreshPreview(previewEl, getApplet()), ms);
}

function smallDialog({ title, message, fields = [], confirmLabel = "OK", cancelLabel = "Annulla", danger = false } = {}) {
    return new Promise((resolve) => {
        document.getElementById("fm-ggb-dialog")?.remove();
        const dlg = document.createElement("div");
        dlg.id = "fm-ggb-dialog";
        dlg.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:100050;display:flex;align-items:center;justify-content:center;font:13px/1.4 system-ui";
        const confirmBg = danger ? "#c02a2a" : "#2a5ac7";
        const fieldsHtml = (fields || []).map((f, i) =>
            `<label style="display:flex;flex-direction:column;gap:4px;margin-top:8px">
                <span style="font-size:11px;color:#aaa;text-transform:uppercase;letter-spacing:0.4px">${escAttr(f.label || "")}</span>
                <input data-field-idx="${i}" placeholder="${escAttr(f.placeholder || "")}" value="${escAttr(f.value || "")}"
                    style="padding:8px 10px;background:#2a2a2a;color:#ddd;border:1px solid #555;border-radius:4px;font:13px Consolas,monospace">
            </label>`).join("");
        dlg.innerHTML = `
            <div style="background:#1e1e1e;color:#ddd;border:1px solid #444;border-radius:8px;box-shadow:0 12px 48px rgba(0,0,0,0.6);min-width:420px;max-width:90vw;overflow:hidden">
                <div style="padding:12px 16px;background:#2a2a2a;border-bottom:1px solid #444;font-weight:600">${escAttr(title || "")}</div>
                <div style="padding:18px 16px;color:#ccc;white-space:pre-wrap">${message ? escAttr(message) : ""}${fieldsHtml}</div>
                <div style="padding:10px 12px;background:#252525;border-top:1px solid #444;display:flex;gap:8px;justify-content:flex-end">
                    <button data-act="cancel" style="padding:6px 14px;background:#3a3a3a;color:#ddd;border:1px solid #555;border-radius:4px;cursor:pointer">${escAttr(cancelLabel)}</button>
                    <button data-act="ok" style="padding:6px 14px;background:${confirmBg};color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600">${escAttr(confirmLabel)}</button>
                </div>
            </div>`;
        document.body.appendChild(dlg);
        const inputs = dlg.querySelectorAll("input[data-field-idx]");
        if (inputs[0]) { inputs[0].focus(); inputs[0].select(); }
        const done = (vals) => { dlg.remove(); document.removeEventListener("keydown", kh); resolve(vals); };
        const kh = (e) => {
            if (e.key === "Escape") done(null);
            else if (e.key === "Enter" && (e.target.tagName === "INPUT")) {
                const vals = Array.from(inputs).map((i) => i.value);
                done(vals);
            }
        };
        document.addEventListener("keydown", kh);
        dlg.addEventListener("click", (e) => {
            const a = e.target?.dataset?.act;
            if (a === "ok") {
                const vals = Array.from(inputs).map((i) => i.value);
                done(vals);
            } else if (a === "cancel" || e.target === dlg) done(null);
        });
    });
}

export async function openGeoGebraEditor(opts = {}) {
    if (_modalState) return;
    injectStyles();
    const {
        initialGgbBase64 = null,
        initialLabel = "",
        itemId = null,
        onAdd, onSavedToCatalog, onCancel,
    } = opts;

    // App types disponibili in deployggb. "classic" è default: layout
    // tradizionale con pannello Algebra a sx + grafico cartesiano a dx
    // sempre visibili (Suite invece ha multi-tab che nasconde il grafico).
    const APP_TYPES = [
        { v: "classic",    l: "Classic (Algebra+Grafico)" },
        { v: "graphing",   l: "Grafico 2D" },
        { v: "geometry",   l: "Geometria 2D" },
        { v: "3d",         l: "Calcolatrice 3D" },
        { v: "cas",        l: "CAS (algebra)" },
        { v: "scientific", l: "Scientifica" },
        { v: "suite",      l: "Suite (multi-tab)" },
    ];

    // G22.S15.bis Fase 4 — Tolgo temporaneamente body.fm-dark mentre il
    // modal GeoGebra è aperto: tutte le regole `body.fm-dark *` smettono
    // di applicarsi a TUTTO il documento, eliminando ogni possibile bleed
    // sul CSS interno GeoGebra (specie celle Tabella). Lo ripristino al close.
    const _hadDark = document.body.classList.contains("fm-dark");
    if (_hadDark) document.body.classList.remove("fm-dark");

    const backdrop = document.createElement("div");
    backdrop.className = "fm-ggb-backdrop";
    backdrop.innerHTML = `
        <div class="fm-ggb-modal" role="dialog" aria-label="GeoGebra editor">
            <div class="fm-ggb-header">
                <h3><img src="/img/geogebra.svg" alt=""> GeoGebra ${initialLabel ? "— " + escAttr(initialLabel) : ""}</h3>
                <label style="display:inline-flex;align-items:center;gap:6px;color:#aaa;font-size:11px;text-transform:uppercase;letter-spacing:0.4px">
                    Tipo
                    <select class="fm-ggb-app-select" style="padding:4px 8px;background:#2a2a2a;color:#ddd;border:1px solid #555;border-radius:4px;font:12px system-ui">
                        ${APP_TYPES.map(t => `<option value="${t.v}">${t.l}</option>`).join("")}
                    </select>
                </label>
                <label style="display:inline-flex;align-items:center;gap:6px;color:#aaa;font-size:11px;text-transform:uppercase;letter-spacing:0.4px"
                       title="Larghezza dell'immagine. Percentuale = % della larghezza disponibile (preview HTML + LaTeX). Unità LaTeX (cm,pt) = solo PDF.">
                    Larghezza
                    <select class="fm-ggb-width-preset"
                        style="padding:4px 6px;background:#2a2a2a;color:#ddd;border:1px solid #555;border-radius:4px 0 0 4px;font:12px Consolas,monospace;border-right:none">
                        <option value="60%">60%</option>
                        <option value="50%">50%</option>
                        <option value="40%">40%</option>
                        <option value="30%">30%</option>
                        <option value="80%">80%</option>
                        <option value="100%">100%</option>
                        <option value="\\linewidth">\\linewidth</option>
                        <option value="12cm">12cm</option>
                        <option value="8cm">8cm</option>
                        <option value="__custom__">altro…</option>
                    </select>
                    <input class="fm-ggb-width" value="60%"
                        title="Valore custom (es. 70%, 10cm). Modifica il select per i preset."
                        style="padding:4px 8px;background:#1f1f1f;color:#ddd;border:1px solid #555;border-radius:0 4px 4px 0;font:12px Consolas,monospace;width:80px">
                </label>
                <div class="fm-ggb-toolbar">
                    <button type="button" data-act="add"      class="primary" title="Aggiungi il grafico nel quesito (cursor focus)">➕ Aggiungi</button>
                    <button type="button" data-act="savecat"             title="Salva il grafico nel TUO catalogo personale (riusabile)">💾 Salva nel catalogo</button>
                    <button type="button" data-act="reset"    class="danger" title="Ricarica lo stato iniziale (perdi le modifiche)">🔄 Reset</button>
                    <button type="button" data-act="cancel"             title="Chiudi">✕</button>
                </div>
            </div>
            <div class="fm-ggb-body">
                <div class="fm-ggb-applet">
                    <div id="${APPLET_CONTAINER_ID}" style="width:100%;height:100%"></div>
                    <div class="fm-ggb-loading">Caricamento applet GeoGebra…</div>
                </div>
                <div class="fm-ggb-preview">
                    <div class="preview-status">in attesa applet…</div>
                </div>
            </div>
        </div>`;
    document.body.appendChild(backdrop);

    const appletEl = backdrop.querySelector(".fm-ggb-applet");
    const previewEl = backdrop.querySelector(".fm-ggb-preview");
    const loadingEl = backdrop.querySelector(".fm-ggb-loading");

    let applet = null;
    let dirty = false;
    _lastSvgHash = "";

    let _ro = null;  // ResizeObserver, assegnato dopo l'inject
    function close() {
        if (!_modalState) return;
        _modalState = null;
        clearTimeout(_previewTimer);
        try { _ro?.disconnect?.(); } catch (_) {}
        try { applet?.remove?.(); } catch (_) {}
        backdrop.remove();
        // Ripristina dark mode se era attivo prima dell'apertura
        if (_hadDark) document.body.classList.add("fm-dark");
    }
    async function cancelAndClose() {
        if (dirty) {
            const vals = await smallDialog({
                title: "Chiudi", message: "Ci sono modifiche non aggiunte/salvate. Chiudere comunque?",
                confirmLabel: "Chiudi senza salvare", danger: true,
            });
            if (vals === null) return;
        }
        close();
        if (typeof onCancel === "function") onCancel();
    }

    try {
        await loadDeployScript();
    } catch (e) {
        loadingEl.textContent = "Errore caricamento deployggb.js: " + e.message;
        loadingEl.style.color = "#c00";
        return;
    }

    // Rimuovi loading
    loadingEl.remove();

    const containerEl = backdrop.querySelector("#" + APPLET_CONTAINER_ID);
    const appSelect = backdrop.querySelector(".fm-ggb-app-select");

    let currentAppName = "classic";
    appSelect.value = currentAppName;

    // G22.S15.bis Fase 5 — binding preset select ↔ input testo per width.
    //   - L'utente sceglie dal select → l'input testo si aggiorna con il valore
    //   - "altro…" lascia l'input editabile per valori custom (es. 70%, 10cm)
    //   - L'input testo è la fonte di verità (letta da `.fm-ggb-width`)
    const widthPresetSelect = backdrop.querySelector(".fm-ggb-width-preset");
    const widthInputEl      = backdrop.querySelector(".fm-ggb-width");
    if (widthPresetSelect && widthInputEl) {
        widthPresetSelect.addEventListener("change", () => {
            const v = widthPresetSelect.value;
            if (v === "__custom__") {
                widthInputEl.focus();
                widthInputEl.select();
            } else {
                widthInputEl.value = v;
            }
        });
        // Sync iniziale: se l'input ha già un valore (es. da apertura
        // catalogo) e matcha un preset, allinea il select.
        const matchPreset = Array.from(widthPresetSelect.options)
            .find(o => o.value === widthInputEl.value);
        if (matchPreset) widthPresetSelect.value = matchPreset.value;
        else widthPresetSelect.value = "__custom__";
    }

    /** (Re)inject l'applet GeoGebra nel container con dimensioni esplicite.
     *  Distrugge l'eventuale applet precedente. */
    function injectApplet({ appName, ggbBase64 = null } = {}) {
        // Cleanup eventuale applet precedente
        try { applet?.remove?.(); } catch (_) {}
        applet = null;
        containerEl.innerHTML = "";
        // Misuro dimensioni effettive (deve avvenire DOPO append e DOPO
        // che il browser ha fatto il layout)
        const rect = appletEl.getBoundingClientRect();
        const w = Math.max(320, Math.floor(rect.width  - 4));
        const h = Math.max(240, Math.floor(rect.height - 4));

        const params = {
            appName,
            width: w,
            height: h,
            scale: 1,
            scaleContainerClass: "fm-ggb-applet",
            allowUpscale: true,
            autoHeight: false,
            showToolBar: true,
            showAlgebraInput: true,
            showMenuBar: false,
            showResetIcon: false,
            enableLabelDrags: true,
            enableShiftDragZoom: true,
            enableRightClick: true,
            useBrowserForJS: false,
            borderColor: null,
            language: "it",
            appletOnLoad: (api) => {
                applet = api;
                refreshPreview(previewEl, applet);
                try {
                    api.registerUpdateListener?.(() => debouncePreview(previewEl, () => applet, 700));
                    api.registerAddListener?.(() => { dirty = true; debouncePreview(previewEl, () => applet, 500); });
                    api.registerRemoveListener?.(() => { dirty = true; debouncePreview(previewEl, () => applet, 500); });
                    api.registerRenameListener?.(() => { dirty = true; debouncePreview(previewEl, () => applet, 500); });
                } catch (_) {}
            },
        };
        if (ggbBase64) params.ggbBase64 = ggbBase64;

        try {
            const ggbApp = new window.GGBApplet(params, true);
            ggbApp.inject(APPLET_CONTAINER_ID);
        } catch (e) {
            previewEl.innerHTML = `<div class="err">Errore inject applet: ${e.message}</div>`;
        }
    }

    // Initial inject (delay micro-tick per garantire layout calcolato)
    requestAnimationFrame(() => injectApplet({ appName: currentAppName, ggbBase64: initialGgbBase64 }));

    // Cambio app type: salva stato corrente e re-inject col nuovo appName
    appSelect.addEventListener("change", () => {
        const newApp = appSelect.value;
        if (newApp === currentAppName) return;
        let stateB64 = null;
        try { stateB64 = applet?.getBase64?.() || null; } catch (_) {}
        currentAppName = newApp;
        // Confirma se ci sono modifiche non salvate
        injectApplet({ appName: newApp, ggbBase64: stateB64 });
    });

    // ResizeObserver: ridimensiona l'applet quando l'utente ridimensiona
    // la finestra. GGBApplet ha api.setSize(w,h) per resize live.
    _ro = new ResizeObserver(() => {
        if (!applet) return;
        const rect = appletEl.getBoundingClientRect();
        const w = Math.max(320, Math.floor(rect.width - 4));
        const h = Math.max(240, Math.floor(rect.height - 4));
        try { applet.setSize?.(w, h); } catch (_) {}
    });
    _ro.observe(appletEl);

    function getApplet() { return applet; }

    async function exportData() {
        if (!applet) return null;
        const svg = await new Promise((res) => { try { applet.exportSVG((s) => res(s || "")); } catch (_) { res(""); } });
        const ggb = (() => { try { return applet.getBase64?.() || ""; } catch (_) { return ""; } })();
        return { svg, ggb };
    }

    backdrop.addEventListener("click", async (e) => {
        const a = e.target?.dataset?.act;
        if (!a) return;
        if (a === "cancel") return cancelAndClose();
        if (a === "reset") {
            const vals = await smallDialog({
                title: "Reset", message: "Ricaricare lo stato iniziale (le modifiche andranno perse)?",
                confirmLabel: "Reset", danger: true,
            });
            if (vals === null) return;
            try {
                if (initialGgbBase64) applet?.setBase64?.(initialGgbBase64, () => debouncePreview(previewEl, getApplet, 100));
                else applet?.newConstruction?.();
                dirty = false;
            } catch (err) { console.error(err); }
            return;
        }
        if (a === "add") {
            if (typeof onAdd !== "function") { close(); return; }
            const data = await exportData();
            if (!data || !data.svg) { previewEl.querySelector(".preview-status").textContent = "errore export"; return; }
            const widthInput = backdrop.querySelector(".fm-ggb-width");
            const width = (widthInput?.value || "\\linewidth").trim();
            const ok = await onAdd({ ggb_b64: data.ggb, svg: data.svg, label: initialLabel || "", width });
            if (ok) close();
            return;
        }
        if (a === "savecat") {
            const vals = await smallDialog({
                title: "Salva nel catalogo",
                message: "Dai un nome al grafico per ritrovarlo in 📚 Mio catalogo",
                fields: [{ label: "Nome", placeholder: "es. Funzione esponenziale", value: initialLabel || "" }],
                confirmLabel: "Salva",
            });
            if (vals === null || !vals[0]) return;
            const label = vals[0].trim();
            const data = await exportData();
            if (!data || !data.ggb) { previewEl.querySelector(".preview-status").textContent = "errore export"; return; }
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
                const r = await fetch("/geogebra/catalog/save", {
                    method: "POST", credentials: "same-origin",
                    headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf, "Accept": "application/json" },
                    body: JSON.stringify({ id: itemId || "", label, ggb_b64: data.ggb, svg_cached: data.svg }),
                });
                const json = await r.json().catch(() => null);
                if (r.ok && (json?.success === true || json?.ok === true)) {
                    if (typeof onSavedToCatalog === "function") onSavedToCatalog({ id: json.id, label });
                    dirty = false;
                    if (window.FM?.ToastManager?.show) window.FM.ToastManager.show("success", "Catalogo", `Salvato "${label}"`, 3000);
                } else {
                    previewEl.querySelector(".preview-status").textContent = "errore save: " + (json?.error || r.status);
                }
            } catch (err) {
                previewEl.querySelector(".preview-status").textContent = "errore: " + err.message;
            }
            return;
        }
    });

    backdrop.addEventListener("mousedown", (e) => { if (e.target === backdrop) cancelAndClose(); });
    document.addEventListener("keydown", function escHandler(e) {
        if (!_modalState) { document.removeEventListener("keydown", escHandler); return; }
        if (e.key === "Escape") cancelAndClose();
    });

    _modalState = { close, getApplet };
}

if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.openGeoGebraEditor = openGeoGebraEditor;
}
