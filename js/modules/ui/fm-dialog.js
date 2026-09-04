/**
 * Phase G19.45 — Replace browser alert/confirm/prompt con modal popup
 * curati stilisticamente (light + dark theme).
 *
 * API esposte su `window.FM.Dialog`:
 *   - alert(message, opts?)        → Promise<void>
 *   - confirm(message, opts?)      → Promise<boolean>
 *   - prompt(message, defaultVal?, opts?) → Promise<string|null>
 *
 * `opts`:
 *   { title, okLabel, cancelLabel, kind: 'info'|'warn'|'danger'|'success' }
 *
 * Caratteristiche:
 *   - Backdrop semi-trasparente full-viewport, modal centrato (grid)
 *   - ESC chiude (rejects con AbortError per prompt/confirm)
 *   - Click backdrop cancella
 *   - Focus trap base (autofocus su input/OK btn)
 *   - Dark theme automatico via `body.fm-dark`
 *   - Accessibilita': aria-modal, aria-labelledby, role=dialog
 *
 * Drop-in replacement: `await FM.Dialog.confirm("…")` invece di
 * `confirm("…")`. Il chiamante deve essere async.
 */

import { esc } from "../core/dom-utils.js";

const ID = "fm-dialog-modal";

function buildModal({ kind = "info", title, body, okLabel = "OK", cancelLabel = null, inputDefault = null, inputType = "text" }) {
    const m = document.createElement("div");
    m.id = ID;
    m.className = `fm-dialog-backdrop fm-dialog-backdrop--${kind}`;
    Object.assign(m.style, {
        position: "fixed", top: "0", left: "0", right: "0", bottom: "0",
        width: "100vw", height: "100vh", margin: "0", padding: "0",
        zIndex: "10500", background: "rgba(15, 23, 42, 0.62)",
        display: "flex", alignItems: "center", justifyContent: "center",
        overflow: "hidden", opacity: "1",
    });
    // Audit 25.R.31 (L10d) — supporto type=password per segreti (es. PAT GitHub).
    const _itype = inputType === "password" ? "password" : "text";
    const inputHtml = inputDefault !== null
        ? `<input type="${_itype}" class="fm-dialog-input" value="${esc(inputDefault)}" autocomplete="off">`
        : "";
    const cancelHtml = cancelLabel
        ? `<button type="button" class="fm-dialog-btn fm-dialog-btn--cancel" data-action="cancel">${esc(cancelLabel)}</button>`
        : "";
    const iconMap = { info: "ⓘ", warn: "⚠", danger: "⛔", success: "✓" };
    m.innerHTML = `
        <div class="fm-dialog" role="dialog" aria-modal="true" aria-labelledby="fm-dialog-title">
            <header class="fm-dialog-header">
                <span class="fm-dialog-icon" aria-hidden="true">${iconMap[kind] || "ⓘ"}</span>
                <h3 id="fm-dialog-title" class="fm-dialog-title">${esc(title || "")}</h3>
            </header>
            <div class="fm-dialog-body">${esc(body || "")}</div>
            ${inputHtml}
            <footer class="fm-dialog-actions">
                ${cancelHtml}
                <button type="button" class="fm-dialog-btn fm-dialog-btn--ok" data-action="ok">${esc(okLabel)}</button>
            </footer>
        </div>`;
    return m;
}

function close(m) {
    if (!m) return;
    m.style.opacity = "0";
    setTimeout(() => m.remove(), 120);
}

function openDialog(opts) {
    return new Promise((resolve) => {
        // Rimuovi eventuali aperti
        document.querySelectorAll(`#${ID}`).forEach(n => n.remove());
        const m = buildModal(opts);
        document.body.appendChild(m);
        const input = m.querySelector(".fm-dialog-input");
        const okBtn = m.querySelector('[data-action="ok"]');
        const cancelBtn = m.querySelector('[data-action="cancel"]');
        // Autofocus
        setTimeout(() => (input || okBtn)?.focus(), 60);
        const finish = (result) => { close(m); document.removeEventListener("keydown", onKey, true); resolve(result); };
        const onKey = (e) => {
            if (e.key === "Escape") { e.preventDefault(); finish(opts.cancelLabel ? null : undefined); }
            if (e.key === "Enter" && document.activeElement?.tagName !== "TEXTAREA") {
                e.preventDefault();
                finish(input ? input.value : true);
            }
        };
        document.addEventListener("keydown", onKey, true);
        m.addEventListener("click", (e) => {
            if (e.target === m) { finish(opts.cancelLabel ? null : undefined); return; }
            if (e.target.closest('[data-action="cancel"]')) { finish(null); return; }
            if (e.target.closest('[data-action="ok"]')) {
                finish(input ? input.value : true);
            }
        });
    });
}

export async function alert(message, { title = "Avviso", okLabel = "OK", kind = "info" } = {}) {
    await openDialog({ kind, title, body: message, okLabel });
}

export async function confirm(message, { title = "Conferma", okLabel = "OK", cancelLabel = "Annulla", kind = "warn" } = {}) {
    const r = await openDialog({ kind, title, body: message, okLabel, cancelLabel });
    return r === true;
}

export async function prompt(message, defaultVal = "", { title = "Inserisci valore", okLabel = "OK", cancelLabel = "Annulla", kind = "info", type = "text" } = {}) {
    const r = await openDialog({ kind, title, body: message, okLabel, cancelLabel, inputDefault: defaultVal, inputType: type });
    return r === null ? null : String(r);
}

window.FM = window.FM || {};
window.FM.Dialog = { alert, confirm, prompt };

// Phase 24.75 — override globale di window.alert con il popup custom. alert()
// è fire-and-forget (nessun valore di ritorno usato), quindi il rimpiazzo
// asincrono è trasparente per TUTTI i chiamanti: niente più dialog nativo.
// NB: window.confirm/window.prompt NON si possono overridare in modo
// trasparente (ritorno SINCRONO usato dal chiamante) → quei siti sono
// convertiti uno a uno a `await FM.Dialog.confirm/prompt`.
try {
    const _nativeAlert = window.alert?.bind(window);
    window.alert = function (message) {
        try { void alert(String(message ?? "")); }
        catch (_) { if (_nativeAlert) _nativeAlert(message); }
    };
} catch (_) { /* ambienti senza window.alert: ignora */ }
