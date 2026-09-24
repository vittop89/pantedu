/**
 * Phase 20 — Editor modelli esercizi per-docente (VF/RM/Collect).
 *
 * Flusso:
 *   1. Fetch GET /api/teacher/templates.json (fallback: defaults backend)
 *   2. Render 3 sezioni (Collect/RM/VF) con textarea/input per ogni item.
 *   3. Save PUT /api/teacher/templates.json (normalized JSON).
 *
 * L'utente modifica SOLO il contenuto (testi, opzioni, answer V/F).
 * La struttura (markup tabelle wrapCheckCell, .wrapsolVF, etc) è decisa
 * lato ContractRenderer; questa pagina non la tocca.
 */

import { Api } from "../core/api.js";

const KINDS = ["Collect", "RM", "VF"];
const state = { Collect: blankCollect(), RM: blankRM(), VF: blankVF() };

function blankCollect() { return { title: "Equazioni", intro: "Risolvi", items: [{ question: "", solution: "" }] }; }
function blankRM()      { return { title: "RM", intro: "Rispondi crociando la casella corretta", items: [{ question: "", options: [{ content: "", correct: false }], justification: "giustifica" }] }; }
function blankVF()      { return { title: "VoF d", intro: "Rispondi correttamente Vero o Falso.", items: [{ question: "", answer: "V", justification: "giustifica" }] }; }

async function loadTemplates() {
    try {
        const data = await Api.getJson("/api/teacher/templates.json");
        for (const k of KINDS) {
            if (data?.[k] && typeof data[k] === "object") state[k] = data[k];
        }
    } catch (e) {
        setStatus(`Errore caricamento: ${e.message || e}`, "err");
    }
    renderAll();
}

async function saveTemplates() {
    syncFromDom();
    const saveBtn = document.getElementById("fm-tpl-save");
    const originalLabel = saveBtn?.textContent;
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = "Salvataggio…";
    }
    const loadingId = notifyLoading("Salvataggio modelli…");
    try {
        const resp = await Api.putJson("/api/teacher/templates.json", state);
        resolveLoading(loadingId, "success", "Modelli salvati",
            `${resp.blocks || 0} ${resp.blocks === 1 ? "sezione" : "sezioni"} persistite.`);
        setStatus(`✓ Modelli salvati (${resp.blocks || 0} sezioni).`, "ok");
    } catch (e) {
        resolveLoading(loadingId, "error", "Errore salvataggio", String(e.message || e));
        setStatus(`Errore salvataggio: ${e.message || e}`, "err");
    } finally {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = originalLabel || "Salva modelli";
        }
    }
}

/** Usa ToastManager globale se presente, altrimenti null (fallback su
 *  setStatus inline box). Ritorna l'id del toast o null. */
function notifyLoading(msg) {
    try {
        const tm = window.FM?.ToastManager || window.ToastManager;
        if (tm?.showLoading) return tm.showLoading(msg);
    } catch (_) {}
    return null;
}

function resolveLoading(toastId, type, title, msg) {
    const tm = window.FM?.ToastManager || window.ToastManager;
    if (tm?.update && toastId) {
        try { tm.update(toastId, type, title, msg); return; } catch (_) {}
    }
    // Fallback: toast nuovo (senza loading precedente)
    if (tm?.show) {
        try { tm.show(type, title, msg); } catch (_) {}
    }
}

function setStatus(msg, kind = "info") {
    const box = document.getElementById("fm-tpl-status");
    if (!box) return;
    const bg = kind === "ok" ? "#e8f5e9" : kind === "err" ? "#fdecea" : "#eef4ff";
    const color = kind === "ok" ? "#1b5e20" : kind === "err" ? "#b71c1c" : "#1a3a8c";
    box.style.cssText = `padding:10px 14px;border-radius:4px;background:${bg};color:${color};border:1px solid ${color}33;font-weight:600`;
    box.textContent = msg;
    if (kind === "ok") setTimeout(() => { if (box.textContent === msg) box.textContent = ""; }, 4000);
}

// ── Render ──────────────────────────────────────────────────────────────

function renderAll() {
    for (const kind of KINDS) renderCard(kind);
}

function renderCard(kind) {
    const card = document.querySelector(`.fm-tpl-card[data-kind="${kind}"]`);
    if (!card) return;
    const block = state[kind] || {};
    const title = card.querySelector(".fm-tpl-title");
    if (title) title.value = block.title || "";
    const intro = card.querySelector(".fm-tpl-intro");
    if (intro) intro.value = block.intro || "";

    const container = card.querySelector(
        kind === "Collect" ? ".fm-tpl-items-collect"
        : kind === "RM"    ? ".fm-tpl-items-rm"
                           : ".fm-tpl-items-vf"
    );
    if (!container) return;
    container.innerHTML = "";
    (block.items || []).forEach((it, idx) => {
        container.appendChild(renderItem(kind, it, idx));
    });
}

function renderItem(kind, item, idx) {
    const row = document.createElement("div");
    row.className = "fm-tpl-item";
    row.dataset.idx = String(idx);
    row.style.cssText = "margin-top:10px;padding:10px;background:#fafafa;border:1px solid #e0e0e0;border-radius:4px;position:relative";

    const head = document.createElement("div");
    head.style.cssText = "display:flex;justify-content:space-between;align-items:center;margin-bottom:6px";
    const label = document.createElement("span");
    label.style.cssText = "font-weight:600;font-size:12px;color:#555";
    label.textContent = `Item ${idx + 1}`;
    const del = document.createElement("button");
    del.type = "button";
    del.textContent = "✕";
    del.title = "Rimuovi item";
    del.style.cssText = "background:#fff;border:1px solid #d33;color:#d33;border-radius:50%;width:24px;height:24px;cursor:pointer;font-weight:600";
    del.addEventListener("click", () => {
        // Ordine critico: syncFromDom PRIMA dello splice, altrimenti il
        // sync rilegge il DOM ancora "vecchio" (che include l'item che
        // stiamo per rimuovere) e sovrascrive lo splice → l'item
        // sembrava non rimosso.
        syncFromDom();
        state[kind].items.splice(idx, 1);
        if (state[kind].items.length === 0) state[kind].items.push(defaultItemForKind(kind));
        renderCard(kind);
    });
    head.appendChild(label);
    head.appendChild(del);
    row.appendChild(head);

    if (kind === "Collect") {
        row.appendChild(textareaField("Quesito", "question", item.question || "", 2));
        row.appendChild(textareaField("Soluzione", "solution", item.solution || "", 2));
    } else if (kind === "VF") {
        row.appendChild(textareaField("Affermazione", "question", item.question || "", 2));
        row.appendChild(selectField("Risposta", "answer", item.answer || "V", [["V", "Vero"], ["F", "Falso"]]));
        row.appendChild(textareaField("Giustificazione", "justification", item.justification || "", 2));
    } else if (kind === "RM") {
        row.appendChild(textareaField("Quesito", "question", item.question || "", 2));
        const optsLbl = document.createElement("div");
        optsLbl.style.cssText = "font-weight:600;font-size:12px;color:#555;margin:8px 0 4px";
        optsLbl.textContent = "Opzioni (spunta = corretta)";
        row.appendChild(optsLbl);
        const optsWrap = document.createElement("div");
        optsWrap.className = "fm-tpl-opts";
        (item.options || []).forEach((op, oi) => optsWrap.appendChild(renderOption(kind, idx, op, oi)));
        row.appendChild(optsWrap);
        const addOpt = document.createElement("button");
        addOpt.type = "button";
        addOpt.textContent = "+ opzione";
        addOpt.style.cssText = "margin-top:6px;padding:4px 10px;background:#eef4ff;border:1px solid #b0c8e8;border-radius:3px;cursor:pointer;color:#2a5ac7;font-size:12px";
        addOpt.addEventListener("click", () => {
            syncFromDom();
            state[kind].items[idx].options.push({ content: "", correct: false });
            renderCard(kind);
        });
        row.appendChild(addOpt);
        row.appendChild(textareaField("Giustificazione", "justification", item.justification || "", 2));
    }
    return row;
}

function renderOption(kind, itemIdx, op, oi) {
    const row = document.createElement("div");
    row.className = "fm-tpl-opt";
    row.dataset.oi = String(oi);
    row.style.cssText = "display:flex;gap:8px;align-items:center;margin-bottom:4px";

    const cb = document.createElement("input");
    cb.type = "checkbox";
    cb.className = "fm-tpl-opt-correct";
    cb.checked = !!op.correct;
    cb.title = "Opzione corretta";

    const inp = document.createElement("input");
    inp.type = "text";
    inp.className = "fm-tpl-opt-content";
    inp.value = op.content || "";
    inp.placeholder = `Opzione ${String.fromCharCode(97 + oi)}`;
    inp.style.cssText = "flex:1;padding:6px 8px;border:1px solid #ccc;border-radius:3px";

    const del = document.createElement("button");
    del.type = "button";
    del.textContent = "✕";
    del.title = "Rimuovi opzione";
    del.style.cssText = "background:transparent;border:none;color:#d33;cursor:pointer;font-weight:600";
    del.addEventListener("click", () => {
        syncFromDom();
        state[kind].items[itemIdx].options.splice(oi, 1);
        if (state[kind].items[itemIdx].options.length === 0) {
            state[kind].items[itemIdx].options.push({ content: "", correct: false });
        }
        renderCard(kind);
    });

    row.appendChild(cb);
    row.appendChild(inp);
    row.appendChild(del);
    return row;
}

function textareaField(label, name, value, rows = 2) {
    const wrap = document.createElement("label");
    wrap.style.cssText = "display:block;margin-bottom:6px";
    const lbl = document.createElement("span");
    lbl.style.cssText = "display:block;font-weight:600;font-size:12px;color:#444;margin-bottom:2px";
    lbl.textContent = label;
    const ta = document.createElement("textarea");
    ta.className = `fm-tpl-field fm-tpl-field-${name}`;
    ta.dataset.field = name;
    ta.rows = rows;
    ta.value = value;
    ta.style.cssText = "width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:3px;resize:vertical;box-sizing:border-box;font:13px/1.4 system-ui";
    wrap.appendChild(lbl);
    wrap.appendChild(ta);
    return wrap;
}

function selectField(label, name, value, options) {
    const wrap = document.createElement("label");
    wrap.style.cssText = "display:inline-flex;flex-direction:column;gap:2px;margin-right:12px";
    const lbl = document.createElement("span");
    lbl.style.cssText = "font-weight:600;font-size:12px;color:#444";
    lbl.textContent = label;
    const sel = document.createElement("select");
    sel.className = `fm-tpl-field fm-tpl-field-${name}`;
    sel.dataset.field = name;
    sel.style.cssText = "padding:6px 8px;border:1px solid #ccc;border-radius:3px;font:13px/1.4 system-ui";
    for (const [v, l] of options) {
        const opt = document.createElement("option");
        opt.value = v;
        opt.textContent = l;
        if (v === value) opt.selected = true;
        sel.appendChild(opt);
    }
    wrap.appendChild(lbl);
    wrap.appendChild(sel);
    return wrap;
}

function defaultItemForKind(kind) {
    if (kind === "Collect") return { question: "", solution: "" };
    if (kind === "RM")      return { question: "", options: [{ content: "", correct: false }], justification: "giustifica" };
    return { question: "", answer: "V", justification: "giustifica" };
}

// ── Sync DOM → state ────────────────────────────────────────────────────

function syncFromDom() {
    for (const kind of KINDS) {
        const card = document.querySelector(`.fm-tpl-card[data-kind="${kind}"]`);
        if (!card) continue;
        state[kind].title = card.querySelector(".fm-tpl-title")?.value || "";
        state[kind].intro = card.querySelector(".fm-tpl-intro")?.value || "";
        const items = [];
        card.querySelectorAll(".fm-tpl-item").forEach((row) => {
            const get = (f) => row.querySelector(`.fm-tpl-field-${f}`)?.value || "";
            if (kind === "Collect") {
                items.push({ question: get("question"), solution: get("solution") });
            } else if (kind === "VF") {
                items.push({
                    question:      get("question"),
                    answer:        get("answer") === "F" ? "F" : "V",
                    justification: get("justification"),
                });
            } else if (kind === "RM") {
                const options = [];
                row.querySelectorAll(".fm-tpl-opt").forEach((or) => {
                    options.push({
                        content: or.querySelector(".fm-tpl-opt-content")?.value || "",
                        correct: !!or.querySelector(".fm-tpl-opt-correct")?.checked,
                    });
                });
                items.push({
                    question:      get("question"),
                    options,
                    justification: get("justification"),
                });
            }
        });
        state[kind].items = items.length ? items : [defaultItemForKind(kind)];
    }
}

// ── Wire buttons ────────────────────────────────────────────────────────

function bindButtons() {
    document.querySelector(".fm-tpl-add-collect")?.addEventListener("click", () => {
        syncFromDom();
        state.Collect.items.push(defaultItemForKind("Collect"));
        renderCard("Collect");
    });
    document.querySelector(".fm-tpl-add-rm")?.addEventListener("click", () => {
        syncFromDom();
        state.RM.items.push(defaultItemForKind("RM"));
        renderCard("RM");
    });
    document.querySelector(".fm-tpl-add-vf")?.addEventListener("click", () => {
        syncFromDom();
        state.VF.items.push(defaultItemForKind("VF"));
        renderCard("VF");
    });
    document.getElementById("fm-tpl-save")?.addEventListener("click", saveTemplates);
    document.getElementById("fm-tpl-reload")?.addEventListener("click", () => {
        setStatus("Ricarico dai dati salvati…");
        loadTemplates();
    });
}

function init() {
    bindButtons();
    loadTemplates();
}

// I module script sono deferiti: DOMContentLoaded potrebbe essere già
// stato emesso quando questo file viene valutato. Check readyState +
// fallback sincrono per non perdere l'init.
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
