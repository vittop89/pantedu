/**
 * a11y form labels — ADR-023 / WCAG 2.2 AA (4.1.2 Name, Role, Value).
 *
 * Molti <select>/<input> sono generati (renderer esercizi, sidebar, contenuto
 * salvato) senza nome accessibile → axe `label` / `select-name`. Questo modulo
 * NON tocca i generatori: fa un pass post-render ADDITIVO che assegna un
 * `aria-label` SIGNIFICATIVO derivato dal contesto, solo dove manca un nome.
 *
 * Idempotente, difensivo (mai modifica valore/comportamento), scoped.
 */

function _clean(s) {
    return (s || "").replace(/\s+/g, " ").trim();
}

/** Un controllo ha già un nome accessibile? */
function _hasName(el) {
    if (el.getAttribute("aria-label")) return true;
    if (el.getAttribute("aria-labelledby")) return true;
    if (el.getAttribute("title")) return true;
    // <label for> o label implicita CON testo reale (non solo MathJax/markup vuoto)
    const labels = el.labels;
    if (labels && labels.length) {
        for (const l of labels) {
            // escludi il testo dei controlli interni; conta solo testo "umano"
            const txt = _clean(l.textContent);
            if (txt.length >= 2) return true;
        }
    }
    return false;
}

/** Testo "etichetta" che precede un controllo (es. "Istituto:"). */
function _precedingLabelText(el) {
    // risali cercando un fratello/antenato-fratello con testo breve tipo etichetta
    let node = el;
    for (let hop = 0; hop < 3 && node; hop++) {
        let sib = node.previousElementSibling;
        while (sib) {
            const t = _clean(sib.textContent);
            if (t && t.length <= 40) return t.replace(/[:：]\s*$/, "");
            sib = sib.previousElementSibling;
        }
        node = node.parentElement;
    }
    return "";
}

function _deriveSelect(sel) {
    if (sel.classList.contains("origin")) return "Origine esercizio";
    const pre = _precedingLabelText(sel);
    if (pre) return pre;
    // opzione segnaposto tipo "Scegli ...:" / "Seleziona ..."
    const first = _clean(sel.options[0] && sel.options[0].textContent);
    if (/^(scegli|seleziona)\b/i.test(first)) return first.replace(/[:：]\s*$/, "");
    // valore corrente
    const cur = _clean(sel.options[sel.selectedIndex] && sel.options[sel.selectedIndex].textContent);
    if (cur) return "Selezione: " + cur.slice(0, 40);
    return "Selezione";
}

function _deriveInput(inp) {
    const cls = inp.className || "";
    if (/fm-rm-num|fm-input-pt|input-pt|pt-tot/.test(cls)) return "Punti";
    if (inp.closest(".fm-position") || /fm-move|move-position/.test(cls)) return "Posizione";
    if (/fm-rm-text|input-argomento/.test(cls)) return "Risposta";
    if (/input-numArg|numArg/.test(cls)) return "Numero argomento";
    if (/input-href|linkref|h-link/.test(cls)) return "Riferimento";
    // checkbox/radio opzione: usa il testo della cella/opzione
    if (inp.type === "checkbox" || inp.type === "radio") {
        const cell = inp.closest("label, td, li, .cellContent, .fm-collection__item");
        const txt = cell ? _clean(cell.textContent) : "";
        if (txt) return (inp.type === "radio" ? "Opzione: " : "Seleziona: ") + txt.slice(0, 50);
        return inp.type === "radio" ? "Opzione" : "Selezione";
    }
    const pre = _precedingLabelText(inp);
    if (pre) return pre;
    if (inp.placeholder) return _clean(inp.placeholder);
    return "Campo";
}

const SKIP_TYPES = new Set(["hidden", "submit", "button", "reset", "image", "file"]);

export function enhanceFormLabels(root) {
    const scope = root && root.querySelectorAll ? root : document;
    try {
        scope.querySelectorAll("select:not([data-a11y-named])").forEach((sel) => {
            sel.setAttribute("data-a11y-named", "1");
            if (_hasName(sel)) return;
            const name = _deriveSelect(sel);
            if (name) sel.setAttribute("aria-label", name);
        });
        scope.querySelectorAll("input:not([data-a11y-named])").forEach((inp) => {
            inp.setAttribute("data-a11y-named", "1");
            if (SKIP_TYPES.has(inp.type)) return;
            if (_hasName(inp)) return;
            const name = _deriveInput(inp);
            if (name) inp.setAttribute("aria-label", name);
        });
        // Struttura liste non-valida (axe list/listitem). Il markup merge/diff
        // salvato (mmb_v2 .diff2) usa <ol> con figli <div> e <li> dentro <div>:
        // è GIÀ non-semantico (gli screen reader non ottengono una lista). Le
        // liste con figli non-<li> le marchiamo esplicitamente non-lista
        // (role=presentation) — additivo, nessun cambiamento visivo. Le liste
        // VALIDE restano intatte (mantengono la semantica).
        scope.querySelectorAll("ol:not([data-a11y-list]), ul:not([data-a11y-list])").forEach((list) => {
            list.setAttribute("data-a11y-list", "1");
            let invalid = false;
            for (const c of list.children) {
                if (c.tagName !== "LI" && c.tagName !== "SCRIPT" && c.tagName !== "TEMPLATE") { invalid = true; break; }
            }
            if (invalid) {
                list.setAttribute("role", "presentation");
                for (const c of list.children) { if (c.tagName === "LI") c.setAttribute("role", "presentation"); }
            }
        });
        scope.querySelectorAll("li:not([data-a11y-li])").forEach((li) => {
            li.setAttribute("data-a11y-li", "1");
            const p = li.parentElement;
            const pTag = p && p.tagName;
            const pRole = p && p.getAttribute && p.getAttribute("role");
            if (pTag !== "UL" && pTag !== "OL" && pTag !== "MENU" && pRole !== "list") {
                li.setAttribute("role", "presentation");
            }
        });
    } catch (e) { /* a11y enhancement non deve mai rompere il render */ }
}

let _scheduled = false;
function _schedule() {
    if (_scheduled) return;
    _scheduled = true;
    const run = () => { _scheduled = false; enhanceFormLabels(document); };
    if (window.requestIdleCallback) window.requestIdleCallback(run, { timeout: 800 });
    else (window.requestAnimationFrame || setTimeout)(run);
}

// Perf: schedula SOLO se l'aggiunta contiene controlli/liste reali (ignora i
// nodi MathJax/testo che mutano in continuazione → evita pass ridondanti).
const _RELEVANT = "select,input,ol,ul,li";
function _hasRelevant(node) {
    return node.nodeType === 1 && (
        (node.matches && node.matches(_RELEVANT)) ||
        (node.querySelector && node.querySelector(_RELEVANT))
    );
}

function _init() {
    _schedule(); // primo pass in idle (non blocca il load)
    try {
        const obs = new MutationObserver((muts) => {
            for (const m of muts) {
                for (const n of m.addedNodes) {
                    if (_hasRelevant(n)) { _schedule(); return; }
                }
            }
        });
        obs.observe(document.body, { childList: true, subtree: true });
    } catch (e) { /* noop */ }
}

if (typeof document !== "undefined") {
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", _init);
    else _init();
    window.addEventListener("fm:navigated", _schedule);
}
