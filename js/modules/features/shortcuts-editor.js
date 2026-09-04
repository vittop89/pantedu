/**
 * Phase 25 — Editor del modello "Scorciatoie LaTeX", condiviso tra:
 *   - /area-docente/templates?tab=scorciatoie  (admin=false → override per-docente)
 *   - /admin/templates (tab shortcuts)         (admin=true  → riferimento globale)
 *
 * Teacher: parte dal riferimento (GET effective), personalizza per-scorciatoia
 * (POST save), disabilita (enabled=false), reset singolo o totale.
 * Admin: edita il riferimento (GET adminList) e salva l'intero set (POST adminSave).
 *
 * Dopo ogni modifica ricarica il motore runtime: window.FM.LatexShortcuts.reload().
 */

import { esc } from "../core/dom-utils.js";

const GROUP_TITLES = {
    "espansioni": "Espansioni (digita la sigla)",
    "strutture": "Strutture (digita la sigla)",
    "tasti-simboli": "Simboli (combinazioni tasti)",
    "tasti-operazioni": "Operazioni (combinazioni tasti)",
    "tasti-parentesi": "Parentesi & ambienti (tasti)",
    "tasti-colori": "Colori & evidenziatori (tasti)",
    "tasti-passaggi": "Passaggi numerati (tasti)",
};

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || "";
}

async function postJson(url, body) {
    const res = await fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf() },
        body: JSON.stringify(body),
    });
    const j = await res.json().catch(() => ({}));
    if (!res.ok || j?.ok === false) throw new Error(j?.error || `HTTP ${res.status}`);
    return j;
}

function toast(kind, title, msg) {
    if (window.FM?.ToastManager?.show) window.FM.ToastManager.show(kind, title, msg, 3500);
    else console[kind === "error" ? "error" : "info"](`[shortcuts] ${title}: ${msg}`);
}

function reloadEngine() {
    try { window.FM?.LatexShortcuts?.reload?.(); } catch { /* noop */ }
}

/** Render un singolo item come riga editabile. */
function renderItem(groupKey, it, admin) {
    const isKey = it.type === "hotkey";
    const trig = isKey ? (it.keys || "") : (it.trigger || "");
    const disabled = !!it._disabled;
    const mine = it._override ? '<span class="fm-sc-mine" title="Personalizzata">✱</span>' : "";
    const row = document.createElement("div");
    row.className = "fm-sc-row" + (disabled ? " fm-sc-row--off" : "");
    row.dataset.group = groupKey;
    row.dataset.label = it.label;
    row.dataset.type = it.type;
    row.innerHTML = `
        <div class="fm-sc-row__head">
            <span class="fm-sc-row__lbl">${esc(it.label)} ${mine}</span>
            <span class="fm-sc-row__desc">${esc(it.desc || "")}</span>
        </div>
        <label class="fm-sc-row__field">
            <span>${isKey ? "Combo" : "Sigla"}</span>
            <input type="text" class="fm-sc-trig" value="${esc(trig)}"
                   placeholder="${isKey ? "es. alt+shift+d" : "es. \\\\frac"}">
        </label>
        <label class="fm-sc-row__field fm-sc-row__field--snippet">
            <span>Inserisce</span>
            <textarea class="fm-sc-snip" rows="2" spellcheck="false">${esc(it.snippet || "")}</textarea>
        </label>
        <div class="fm-sc-row__actions">
            ${admin ? "" : `<label class="fm-sc-row__toggle"><input type="checkbox" class="fm-sc-enabled" ${disabled ? "" : "checked"}> attiva</label>`}
            <button type="button" class="fm-btn fm-btn--primary fm-btn--xs fm-sc-save">${admin ? "Applica" : "Salva mia"}</button>
            ${admin ? "" : '<button type="button" class="fm-btn fm-btn--ghost fm-btn--xs fm-sc-reset">↺ riferimento</button>'}
        </div>`;
    return row;
}

function readRow(row) {
    const isKey = row.dataset.type === "hotkey";
    const trig = row.querySelector(".fm-sc-trig").value.trim();
    const snippet = row.querySelector(".fm-sc-snip").value;
    const enabledCb = row.querySelector(".fm-sc-enabled");
    const out = {
        groupKey: row.dataset.group,
        label: row.dataset.label,
        type: row.dataset.type,
        snippet,
    };
    if (isKey) out.keys = trig; else out.trigger = trig;
    if (enabledCb) out.enabled = enabledCb.checked;
    return out;
}

async function mount(container, opts = {}) {
    const admin = !!opts.admin;
    const url = admin ? "/api/admin/latex-shortcuts" : "/api/latex-shortcuts/effective";
    let groups = {};
    try {
        const res = await fetch(url, { credentials: "same-origin" });
        const j = await res.json().catch(() => ({}));
        groups = (j && j.groups) || {};
    } catch (e) {
        container.innerHTML = `<p class="fm-error">Errore caricamento: ${esc(e.message)}</p>`;
        return;
    }
    container.innerHTML = "";
    container.dataset.admin = admin ? "1" : "0";
    // Esclude i campi di QUESTO editor dal motore scorciatoie (no espansione
    // mentre definisci una scorciatoia).
    container.setAttribute("data-no-shortcuts", "1");
    for (const gk of Object.keys(groups)) {
        const items = groups[gk];
        if (!Array.isArray(items) || !items.length) continue;
        const sec = document.createElement("section");
        sec.className = "fm-sc-group";
        sec.innerHTML = `<h3 class="fm-sc-group__title">${esc(GROUP_TITLES[gk] || gk)}</h3>`;
        const list = document.createElement("div");
        list.className = "fm-sc-list";
        items.forEach((it) => list.appendChild(renderItem(gk, it, admin)));
        sec.appendChild(list);
        container.appendChild(sec);
    }

    if (admin) {
        const bar = document.createElement("div");
        bar.className = "fm-sc-adminbar";
        bar.innerHTML = '<button type="button" class="fm-btn fm-btn--primary fm-sc-saveall">💾 Salva riferimento (tutti)</button>';
        container.appendChild(bar);
    }

    // Delegated actions
    container.addEventListener("click", async (e) => {
        const row = e.target.closest(".fm-sc-row");
        if (e.target.closest(".fm-sc-save") && row) {
            const data = readRow(row);
            if (admin) { toast("info", "Riferimento", "Usa «Salva riferimento» per applicare tutte le righe."); return; }
            try {
                await postJson("/api/latex-shortcuts/save", data);
                row.querySelector(".fm-sc-row__lbl").innerHTML =
                    `${esc(data.label)} <span class="fm-sc-mine" title="Personalizzata">✱</span>`;
                reloadEngine();
                toast("success", "Salvata", `«${data.label}» personalizzata.`);
            } catch (err) { toast("error", "Errore", err.message); }
        } else if (e.target.closest(".fm-sc-reset") && row) {
            try {
                await postJson("/api/latex-shortcuts/reset", { groupKey: row.dataset.group, label: row.dataset.label });
                reloadEngine();
                toast("success", "Ripristinata", `«${row.dataset.label}» torna al riferimento.`);
                mount(container, opts);
            } catch (err) { toast("error", "Errore", err.message); }
        } else if (e.target.closest(".fm-sc-saveall") && admin) {
            const out = {};
            container.querySelectorAll(".fm-sc-row").forEach((r) => {
                const d = readRow(r);
                (out[d.groupKey] = out[d.groupKey] || []).push({
                    label: d.label, type: d.type, snippet: d.snippet,
                    trigger: d.trigger, keys: d.keys,
                    desc: r.querySelector(".fm-sc-row__desc")?.textContent || "",
                });
            });
            try {
                await postJson("/api/admin/latex-shortcuts", { groups: out });
                reloadEngine();
                toast("success", "Riferimento salvato", "Le scorciatoie istituzionali sono aggiornate.");
            } catch (err) { toast("error", "Errore", err.message); }
        }
    });

    // Reset-all esterno (solo teacher)
    if (!admin) {
        const resetAllBtn = document.getElementById("fm-sc-resetall");
        if (resetAllBtn && !resetAllBtn.dataset.bound) {
            resetAllBtn.dataset.bound = "1";
            resetAllBtn.addEventListener("click", async () => {
                const ok = window.FM?.Dialog
                    ? await window.FM.Dialog.confirm("Rimuovere TUTTE le tue personalizzazioni e tornare al riferimento?")
                    : true;
                if (!ok) return;
                try {
                    await postJson("/api/latex-shortcuts/reset-all", {});
                    reloadEngine();
                    toast("success", "Ripristinato", "Tornato al riferimento istituzionale.");
                    mount(container, opts);
                } catch (err) { toast("error", "Errore", err.message); }
            });
        }
    }
}

window.FM = window.FM || {};
window.FM.ShortcutsEditor = { mount };

export const ShortcutsEditor = window.FM.ShortcutsEditor;
