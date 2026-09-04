/**
 * Verifiche print UI (Phase 8k).
 *
 * Ascolta `fm:navigated` + `DOMContentLoaded` e, quando rileva una
 * pagina "verifica" (.DraggableContainer_ver presente), inietta un
 * pannello flottante "Stampa verifica" visibile solo ai docenti e
 * amministratori.
 *
 * Il pannello raccoglie le scelte dai checkbox esistenti dei
 * `.fm-collection__item` e POST la Selection a /teacher/print (o batch
 * admin in futuro).
 *
 * Nessuna dipendenza da jQuery — usa DOM puro.
 */

import { PrintClient } from "./print-client.js";
import { extractItemHtml, extractItemBadge, extractItemMark, extractProblemIntroHtml } from "../core/dom-block-extractor.js";

const SELECTOR      = ".DraggableContainer_ver";
const PANEL_ID      = "fm-print-panel";
const PANEL_CLASS   = "fm-print-panel";

let _installedRoute = null;

function currentUser() {
    // Prende lo stato dalla sessione se disponibile
    return window.FM?.user || null;
}

async function fetchUserRole() {
    if (window.FM?.user?.role) return window.FM.user.role;
    try {
        const res = await fetch("/auth/user-info", { credentials: "same-origin" });
        if (!res.ok) return "guest";
        const json = await res.json();
        window.FM = window.FM || {};
        window.FM.user = {
            username: json.username || "",
            role:     json.role     || "guest",
        };
        return window.FM.user.role;
    } catch (_) {
        return "guest";
    }
}

function collectSelections(container) {
    const problems = [];
    // G18 — container-agnostic: itera tutti i .fm-groupcollex del documento.
    // (cfr. topbar-modern.buildSelectionFromDOM per la stessa strategia).
    const scope = container?.querySelectorAll
        ? (container.querySelectorAll(".fm-groupcollex").length
            ? container : document)
        : document;
    scope.querySelectorAll(".fm-groupcollex").forEach((problem, idx) => {
        const checkboxA = problem.querySelector("input.checkboxA, input#checkboxA");
        if (checkboxA && !checkboxA.checked) return;

        const collexItems = Array.from(problem.querySelectorAll(".fm-collection__item"));
        // G18 — CheckAll-A semantics: se almeno un .fm-checkbox-ain e' checked
        // applica il filtro per item, altrimenti prendi tutti i quesiti.
        const anyAinChecked = collexItems.some(el => {
            const sub = el.querySelector("input.fm-checkbox-ain");
            return sub && sub.checked;
        });
        const items = [];
        collexItems.forEach(el => {
            const sub = el.querySelector("input.fm-checkbox-ain");
            if (anyAinChecked && sub && !sub.checked) return;
            const ptsInput = el.querySelector("input.fm-input-pt, input.inputPt");
            const solInput = el.querySelector("input.checksol, input.checkgiust");
            // dom-block-extractor: cattura granulare (text/latex/tikz/geogebra/list)
            // e separa problem (.fm-collection) vs solution (.fm-sol/.fm-giustsol/.giustifica).
            const ext = extractItemHtml(el);
            // G27.badge — propaga origin (source_key) + badge fields al server
            // cosi' BadgeRenderer puo' emettere \badge[...] nei SOL.tex e
            // collectUsedKeys filtra il preambolo \definefonte.
            const meta = extractItemBadge(el);
            // G27.dsa — propaga marker F/GF item-level. TableRenderer
            // server-side prefigge "(*F*) "/"(*GF*) " al testo dell'item.
            const mark = extractItemMark(el);
            items.push({
                html:             ext.html,
                solution:         ext.sol,
                points:           parseFloat(ptsInput?.value || "1") || 1,
                includeSolution:  !!(solInput && solInput.checked),
                origin:           meta.origin,
                badge:            meta.badge,
                mark:             mark,
            });
        });
        if (!items.length) return;

        const posInput = problem.querySelector("input.fm-def-position-imp");
        // G27.vf.fix — preferisci data-type (ContractRenderer), fallback su id legacy.
        const dataType = problem.dataset.type || "";
        const typeMatch = /type_([A-Za-z]+)/.exec(dataType) || /type_([A-Za-z]+)/.exec(problem.id || "");
        problems.push({
            filePath:  location.pathname,
            problemId: problem.id || `p-${idx}`,
            position:  parseInt(posInput?.value || String(idx + 1), 10) || idx + 1,
            type:      typeMatch ? typeMatch[1] : "Collect",
            // HTML (no textContent) → Sanitizer server converte b/i/u/list a LaTeX
            text:      extractProblemIntroHtml(problem),
            items,
        });
    });
    return problems;
}

function readMeta() {
    const q = (id) => document.getElementById(id);
    return {
        verTitle:      q("verTitle")?.value       ?? document.querySelector(".fm-titolo h1")?.textContent?.trim() ?? "Verifica",
        selectedIIS:   q("sel-iis")?.value        ?? window.FM?.AppState?.selectedIIS ?? window.FM?.Curriculum?.firstCode("indirizzi") ?? "",
        selectedCLS:   q("sel-cls")?.value        ?? window.FM?.AppState?.selectedCLS ?? window.FM?.Curriculum?.firstCode("classi") ?? "",
        selectedMATER: q("sel-mater")?.value      ?? window.FM?.AppState?.selectedMATER ?? window.FM?.Curriculum?.firstCode("materie") ?? "",
        anno:          q("anno")?.value           ?? String(new Date().getFullYear()),
        sezione:       q("sezione")?.value        ?? "NOR",
    };
}

function buildSelection(container, version) {
    return {
        version,
        ...readMeta(),
        problems: collectSelections(container),
        options:  { includeTitlePage: true, includeSolutions: false },
    };
}

function toast(msg, kind = "info") {
    const t = document.createElement("div");
    t.className = `fm-print-toast fm-print-toast--${kind}`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

function injectStyles() { /* ADR-023 Fase 2: CSS spostato in css/modules/ */ }

async function onPrintClick(panel, version) {
    const container = document.querySelector(SELECTOR);
    if (!container) {
        panel.querySelector(".msg").textContent = "Nessuna verifica trovata";
        panel.querySelector(".msg").className = "msg err";
        return;
    }
    const sel = buildSelection(container, version);
    if (!sel.problems.length) {
        panel.querySelector(".msg").textContent = "Seleziona almeno 1 esercizio (checkbox A)";
        panel.querySelector(".msg").className = "msg err";
        return;
    }
    const variant = panel.querySelector("select.variant").value;
    panel.querySelector(".msg").textContent = "Generazione in corso…";
    panel.querySelector(".msg").className = "msg";
    try {
        const res = await PrintClient.printTexForTeacher(sel, variant);
        panel.querySelector(".msg").textContent = `Salvato: ${res.filename}`;
        panel.querySelector(".msg").className = "msg ok";
        toast(`Scaricato ${res.filename}`, "success");
    } catch (err) {
        const msg = err?.message || "errore";
        panel.querySelector(".msg").textContent = `Errore: ${msg}`;
        panel.querySelector(".msg").className = "msg err";
        toast(`Errore: ${msg}`, "error");
    }
}

function renderPanel() {
    if (document.getElementById(PANEL_ID)) return;
    injectStyles();
    const el = document.createElement("div");
    el.id = PANEL_ID;
    el.className = PANEL_CLASS;
    el.innerHTML = `
        <h4>🖨️ Stampa verifica (TeX)</h4>
        <div class="row">
            <label style="flex:1">Variante
                <select class="variant" style="width:100%;margin-top:4px">
                    <option value="normal">Normale</option>
                    <option value="dsa">DSA (spaziato)</option>
                    <option value="dyslexic">OpenDyslexic</option>
                </select>
            </label>
        </div>
        <div class="row">
            <button class="primary" data-version="A">Versione A</button>
            <button class="ghost"   data-version="B">Versione B</button>
        </div>
        <div class="msg"></div>
    `;
    document.body.appendChild(el);

    el.querySelectorAll("button[data-version]").forEach(btn => {
        btn.addEventListener("click", () => onPrintClick(el, btn.dataset.version));
    });
}

function removePanel() {
    document.getElementById(PANEL_ID)?.remove();
}

async function refresh() {
    const container = document.querySelector(SELECTOR);
    if (!container) {
        removePanel();
        _installedRoute = null;
        return;
    }
    if (_installedRoute === location.pathname) return;
    const role = await fetchUserRole();
    if (role !== "teacher" && role !== "administrator") {
        removePanel();
        return;
    }
    _installedRoute = location.pathname;
    renderPanel();
}

export const VerifichePrintUI = { refresh };

// Auto-init
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", refresh, { once: true });
} else {
    refresh();
}
window.addEventListener("fm:navigated", refresh);

window.FM = window.FM || {};
window.FM.VerifichePrintUI = VerifichePrintUI;
