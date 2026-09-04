/**
 * G22.S15.bis Fase 5 — Universal sync notification panel.
 *
 * Pannello unico bottom-right per:
 *   - Sync long-running con log streaming (Drive/Local/GitHub bulk push)
 *   - Toast notifications minimali (replace di ToastManager jQuery)
 *
 * Single instance #fm-drive-sync-panel (ID storico, mantenuto). Animazione
 * slide-in da destra via CSS class .fm-drive-sync-panel--active.
 *
 * API:
 *   - openSession(title): apre pannello in modalità sync (header + counter
 *     + progress bar + Stop button). Ritorna l'elemento panel per chiamate
 *     successive di logLine/setProgress.
 *   - closeSession(): chiude pannello + cancella auto-hide pending.
 *   - logLine(text, kind='info'): append una riga colorata
 *     (kind: 'ok'|'error'|'info').
 *   - setProgress(done, total): aggiorna counter + barra.
 *   - notify(title, kind, message, autoHideMs=4000): mostra notifica
 *     stand-alone (replace toast). Auto-hide configurabile, NON interrompe
 *     una session attiva: si limita ad accodare la riga.
 *   - registerAbort({ctrl, flagAborted}): registra abort target attivo
 *     così il bottone Stop interrompe la sync corrente.
 *   - clearAbort(target): de-registra (post-finally).
 *
 * Layout HTML interno (definito in CSS layout.css):
 *   .fm-drive-sync-panel
 *     .fm-drive-sync-head [strong, .fm-drive-sync-counter, .fm-drive-sync-cancel]
 *     .fm-drive-sync-bar > .fm-drive-sync-bar-fill
 *     .fm-drive-sync-log
 */

const PANEL_ID = "fm-drive-sync-panel";
const ACTIVE_CLASS = "fm-drive-sync-panel--active";

// ─────────────────────── Abort registry ─────────────────────────────────
// Drive/Local syncs registrano qui il proprio AbortController + flag così
// il pulsante Stop (e qualunque altro entry-point) può interrompere la sync
// in corso senza accoppiamento diretto.
let _activeAbort = null;

export function registerAbort(target) { _activeAbort = target; }
export function clearAbort(target) {
    if (_activeAbort === target) _activeAbort = null;
}
export function triggerAbort(reason = "user_cancel") {
    const t = _activeAbort;
    if (!t) return false;
    try { t.flagAborted?.(); } catch (_) {}
    try { t.ctrl?.abort?.(reason); } catch (_) {}
    return true;
}
export function hasActiveAbort() { return !!_activeAbort; }

// ─────────────────────── Panel DOM lifecycle ────────────────────────────
let _autoHideTimer = null;

function ensurePanel(title = "☁ Sync") {
    let panel = document.getElementById(PANEL_ID);
    if (panel) {
        const head = panel.querySelector(".fm-drive-sync-head strong");
        if (head && title) head.textContent = title;
        return panel;
    }
    panel = document.createElement("div");
    panel.id = PANEL_ID;
    panel.className = "fm-drive-sync-panel";
    // WCAG 4.1.3 Status Messages: il panel funge da live region per
    // notifiche e log sync. role="status" + aria-live="polite" annuncia
    // gli aggiornamenti agli screen reader senza interrompere la lettura.
    // aria-atomic="false": solo le righe nuove (children .log) vengono
    // annunciate, non l'intero panel.
    panel.setAttribute("role", "status");
    panel.setAttribute("aria-live", "polite");
    panel.setAttribute("aria-atomic", "false");
    panel.setAttribute("aria-label", "Notifiche e log sincronizzazione");
    // Label dinamico Stop: il content cambia tramite CSS pseudo (vedi
    // .fm-drive-sync-panel--notify .fm-drive-sync-cancel) oppure si può
    // override via JS leggendo la classe parent.
    panel.innerHTML = `
        <div class="fm-drive-sync-head">
            <strong>${escapeHtml(title)}</strong>
            <span class="fm-drive-sync-counter">0/0</span>
            <button type="button" class="fm-drive-sync-cancel" title="Chiudi / annulla" aria-label="Chiudi o annulla notifiche">
                <span class="fm-cancel-label-session" aria-hidden="true">⏸ Stop</span>
                <span class="fm-cancel-label-notify" aria-hidden="true">✕</span>
            </button>
        </div>
        <div class="fm-drive-sync-bar" role="progressbar" aria-label="Avanzamento sincronizzazione" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="fm-drive-sync-bar-fill"></div></div>
        <div class="fm-drive-sync-log"></div>
    `;
    document.body.appendChild(panel);
    panel.querySelector(".fm-drive-sync-cancel").addEventListener("click", (e) => {
        e.preventDefault();
        // G22.S15.bis Fase 5 — bottone polimorfico:
        //   - mode 'session' + sync attiva → triggerAbort()
        //   - mode 'notify' o sync finita → close immediato
        if (panel.classList.contains("fm-drive-sync-panel--session") && triggerAbort()) {
            return; // abort in corso, mostrerà "fermato dall'utente"
        }
        panel.classList.remove(ACTIVE_CLASS);
        panel.classList.remove("fm-drive-sync-panel--session");
        panel.classList.remove("fm-drive-sync-panel--notify");
    });
    return panel;
}

// G22.S15.bis Fase 5+ — delegate canonical (semantica identica).
function escapeHtml(s) {
    return window.FM?.DomUtils?.escHtml
        ? window.FM.DomUtils.escHtml(s)
        : String(s ?? "").replace(/[&<>"']/g, (c) =>
            ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}

// ─────────────────────── Public API ─────────────────────────────────────

/**
 * Apre il pannello in modalità sync session. Reset log + progress, header
 * impostato dal title. Mostra il bottone Stop (cancellabile via abort).
 */
export function openSession(title = "Sync") {
    const panel = ensurePanel(title);
    panel.querySelector(".fm-drive-sync-log").innerHTML = "";
    setProgress(0, 0);
    panel.classList.add(ACTIVE_CLASS);
    // G22.S15.bis Fase 5 — mode 'session': counter + progress bar visibili,
    // bottone Stop attivo per abort.
    panel.classList.remove("fm-drive-sync-panel--notify");
    panel.classList.add("fm-drive-sync-panel--session");
    if (_autoHideTimer) { clearTimeout(_autoHideTimer); _autoHideTimer = null; }
    return panel;
}

/** Chiusura immediata o ritardata. Rimuove anche le classi di mode. */
export function closeSession({ delayMs = 0 } = {}) {
    const panel = document.getElementById(PANEL_ID);
    if (!panel) return;
    if (_autoHideTimer) { clearTimeout(_autoHideTimer); _autoHideTimer = null; }
    const doClose = () => {
        if (_activeAbort) return;
        panel.classList.remove(ACTIVE_CLASS);
        panel.classList.remove("fm-drive-sync-panel--session");
        panel.classList.remove("fm-drive-sync-panel--notify");
    };
    if (delayMs > 0) {
        _autoHideTimer = setTimeout(doClose, delayMs);
    } else {
        doClose();
    }
}

/** Append una riga al log. */
export function logLine(text, kind = "info") {
    const panel = document.getElementById(PANEL_ID);
    if (!panel) return;
    const log = panel.querySelector(".fm-drive-sync-log");
    if (!log) return;
    const line = document.createElement("div");
    line.className = `fm-drive-sync-line fm-drive-sync-line--${kind}`;
    line.textContent = text;
    log.appendChild(line);
    log.scrollTop = log.scrollHeight;
}

/** Aggiorna counter + barra progresso. */
export function setProgress(done, total) {
    const panel = document.getElementById(PANEL_ID);
    if (!panel) return;
    panel.querySelector(".fm-drive-sync-counter").textContent = `${done}/${total}`;
    const pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
    panel.querySelector(".fm-drive-sync-bar-fill").style.width = `${pct  }%`;
    // WCAG 4.1.2: aggiorna aria-valuenow per screen reader.
    const bar = panel.querySelector(".fm-drive-sync-bar");
    if (bar) {
        bar.setAttribute("aria-valuenow", String(pct));
        bar.setAttribute("aria-valuetext", `${done} di ${total} (${pct}%)`);
    }
}

/**
 * Notifica stand-alone (replace toast). Apre il panel se non già aperto,
 * appende la riga, schedula auto-hide. NON interferisce con session attiva
 * (l'auto-hide è skipped se c'è una abort attiva = sync in corso).
 */
export function notify(title, kind = "info", message = "", autoHideMs = 4000) {
    const panel = ensurePanel(title);
    const isSession = panel.classList.contains("fm-drive-sync-panel--session");
    const isVisible = panel.classList.contains(ACTIVE_CLASS);
    // G22.S15.bis Fase 5 — mode 'notify': solo header + log, niente progress
    // bar, bottone Stop diventa "✕ Chiudi" (close immediate, no abort logic).
    // Se NON c'è session attiva e il pannello era nascosto/timeout-chiuso,
    // resetta il log per evitare accumulo di messaggi vecchi.
    if (!isSession && !isVisible) {
        const log = panel.querySelector(".fm-drive-sync-log");
        if (log) log.innerHTML = "";
        // Reset progress (nascosta in notify ma evita "1/2" stale al reuse)
        setProgress(0, 0);
    }
    panel.classList.add(ACTIVE_CLASS);
    if (!isSession) panel.classList.add("fm-drive-sync-panel--notify");
    if (message) logLine(message, kind);
    if (_autoHideTimer) { clearTimeout(_autoHideTimer); _autoHideTimer = null; }
    if (!_activeAbort && autoHideMs > 0) {
        _autoHideTimer = setTimeout(() => {
            if (!_activeAbort) {
                panel.classList.remove(ACTIVE_CLASS);
                panel.classList.remove("fm-drive-sync-panel--notify");
            }
        }, autoHideMs);
    }
}

// ─────────────────────── State persistence ─────────────────────────────
// G22.S15.bis Fase 5 — su navigation salviamo lo stato del panel in
// sessionStorage. Al prossimo page load lo ripristiniamo per dare
// continuità visiva (la sync vera è abortita dal navigate, ma user
// vede log+context e può ri-cliccare per riprendere).
const STATE_KEY = "fm:syncPanelState";

function snapshotPanel() {
    if (typeof sessionStorage === "undefined") return;
    const panel = document.getElementById(PANEL_ID);
    if (!panel || !panel.classList.contains(ACTIVE_CLASS)) {
        try { sessionStorage.removeItem(STATE_KEY); } catch (_) {}
        return;
    }
    const head = panel.querySelector(".fm-drive-sync-head strong");
    const counter = panel.querySelector(".fm-drive-sync-counter");
    const log = panel.querySelector(".fm-drive-sync-log");
    const isSession = panel.classList.contains("fm-drive-sync-panel--session");
    try {
        sessionStorage.setItem(STATE_KEY, JSON.stringify({
            title: head?.textContent || "Sync",
            counter: counter?.textContent || "0/0",
            logHtml: log?.innerHTML || "",
            isSession,
            ts: Date.now(),
        }));
    } catch (_) { /* storage piena */ }
}

function restorePanelFromSnapshot() {
    if (typeof sessionStorage === "undefined") return false;
    let snap;
    try {
        const raw = sessionStorage.getItem(STATE_KEY);
        if (!raw) return false;
        snap = JSON.parse(raw);
    } catch (_) { return false; }
    // Snapshot stale (>5 min) → drop
    if (!snap || (Date.now() - (snap.ts || 0)) > 5 * 60 * 1000) {
        try { sessionStorage.removeItem(STATE_KEY); } catch (_) {}
        return false;
    }
    const panel = ensurePanel(snap.title || "Sync");
    panel.classList.add(ACTIVE_CLASS);
    if (snap.isSession) panel.classList.add("fm-drive-sync-panel--session");
    else panel.classList.add("fm-drive-sync-panel--notify");
    const log = panel.querySelector(".fm-drive-sync-log");
    if (log && snap.logHtml) log.innerHTML = snap.logHtml;
    const counter = panel.querySelector(".fm-drive-sync-counter");
    if (counter) counter.textContent = snap.counter || "0/0";
    // Nota "interrotta": SOLO se non è già l'ultima riga. Senza questa guardia
    // ogni navigazione ne aggiungeva una nuova → muro di messaggi ripetuti
    // (il log ripristinato da snap.logHtml conteneva già le precedenti).
    const _lines = log ? log.querySelectorAll(".fm-drive-sync-line") : [];
    const _last = _lines.length ? (_lines[_lines.length - 1].textContent || "") : "";
    if (!/Sync interrotta dal cambio pagina/.test(_last)) {
        logLine("⚠ Sync interrotta dal cambio pagina. Re-clicca il pulsante per riprendere.", "info");
    }
    return true;
}

// Snapshot periodico (ogni 2s) durante session attiva
let _snapshotTimer = null;
function startSnapshotting() {
    if (_snapshotTimer) return;
    _snapshotTimer = setInterval(() => {
        const panel = document.getElementById(PANEL_ID);
        if (panel?.classList.contains(ACTIVE_CLASS)) snapshotPanel();
    }, 2000);
}

if (typeof window !== "undefined") {
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", () => {
            restorePanelFromSnapshot();
            startSnapshotting();
        });
    } else {
        setTimeout(() => { restorePanelFromSnapshot(); startSnapshotting(); }, 0);
    }
    // Snapshot finale prima del page unload
    window.addEventListener("beforeunload", snapshotPanel);
}

// ─────────────────────── Compatibility shim ─────────────────────────────
// Esposizione su window.FM per moduli che non usano import (legacy).
// I moduli moderni dovrebbero importare direttamente.
if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.SyncPanel = {
        openSession, closeSession, logLine, setProgress, notify,
        registerAbort, clearAbort, triggerAbort, hasActiveAbort,
    };
}
