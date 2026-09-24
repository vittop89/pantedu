/**
 * Comportamenti dichiarativi via attributi `data-fm-*` (2026-09-05).
 *
 * Sostituiscono gli <script> inline di una riga che le viste mettevano
 * accanto a un bottone o a un form (`document.currentScript.previousElementSibling`),
 * pattern che nei moduli ES non esiste. Tutto e' delegato al document, quindi
 * vale anche per markup arrivato dopo (swap SPA, fetch di partial).
 *
 *   data-fm-confirm="testo"    form → conferma al submit; bottone o link → conferma al click
 *   data-fm-dismiss[="sel"]    click → nasconde l'antenato `sel` (closest) o il genitore
 *   data-fm-autosubmit         select/input → submit del form proprietario al change
 *   data-fm-autorefresh="ms"   ricarica la pagina dopo N millisecondi (se l'elemento e' ancora nel DOM)
 *   data-fm-copy               click → copia il testo dell'elemento negli appunti, con flash
 *   data-fm-match="#id"        input → deve coincidere con l'input indicato (setCustomValidity)
 *   data-fm-row-toggle         bottone dentro una <tr> → mostra/nasconde la riga successiva
 *   data-fm-action="nome"      click (bottone/link) o submit (form) → chiama
 *                              window.FM.pageActions[nome](event, elemento); le entry di
 *                              pagina registrano le proprie azioni in quel registro. Sul
 *                              submit il default viene bloccato, sul click no (come gli
 *                              handler inline che sostituisce).
 *   data-fm-seleziona          click su un campo di testo → ne seleziona tutto il contenuto
 *   data-fm-chiudi-scheda="/p" click → prova a chiudere la scheda; se resta aperta
 *                              (il browser chiude solo quelle aperte da uno script),
 *                              dopo 80 ms va al percorso "/p" (solo percorsi del sito)
 *
 * Gli ultimi due (23/9/2026, revisione architetturale A-19) sostituiscono gli
 * ultimi `onclick` in linea: `onclick="this.select()"` nella finestra della
 * chiave di recupero (js/entries/teacher-dashboard.js) e il «✕ Esci» della
 * modifica della struttura dei modelli (TemplateViewController). Con la CSP
 * rigorosa un gestore in linea non parte: in produzione quei due non facevano
 * niente. Prova: tests/js-unit/gestori-senza-onclick.test.js.
 */

function dispatchAction(e, kind) {
    const el = e.target instanceof Element ? e.target.closest("[data-fm-action]") : null;
    if (!el) return;
    const isForm = el.tagName === "FORM";
    if (kind === "submit" ? !isForm : isForm) return;
    const fn = window.FM?.pageActions?.[el.getAttribute("data-fm-action") || ""];
    if (typeof fn !== "function") return;
    if (kind === "submit") e.preventDefault();
    fn(e, el);
}
document.addEventListener("click", (e) => dispatchAction(e, "click"));
document.addEventListener("submit", (e) => dispatchAction(e, "submit"));

document.addEventListener("click", (e) => {
    const el = e.target instanceof Element ? e.target.closest("[data-fm-seleziona]") : null;
    if (el && typeof el.select === "function") el.select();
});

/**
 * `data-fm-chiudi-scheda`: chiude la scheda, o se il browser non lo permette
 * porta al percorso dell'attributo. È quello che faceva il gestore in linea
 * del «✕ Esci» (`window.close()` e, 80 ms dopo, `location.href`). Il percorso
 * vale solo se è del sito: comincia con una sola `/`.
 *
 * @param {Element} el
 * @param {{chiudi?: () => void, vai?: (percorso: string) => void, attesa?: number}} [opzioni] per le prove
 * @returns {string|null} il percorso a cui andrà, o null
 */
export function chiudiSchedaOVai(el, opzioni = {}) {
    const chiudi = opzioni.chiudi || (() => window.close());
    const vai = opzioni.vai || ((percorso) => { window.location.href = percorso; });
    const percorso = el.getAttribute("data-fm-chiudi-scheda") || "";
    try { chiudi(); } catch (_) { /* il browser può rifiutare */ }
    if (!percorso.startsWith("/") || percorso.startsWith("//")) return null;
    setTimeout(() => vai(percorso), opzioni.attesa ?? 80);
    return percorso;
}

document.addEventListener("click", (e) => {
    const el = e.target instanceof Element ? e.target.closest("[data-fm-chiudi-scheda]") : null;
    if (el) chiudiSchedaOVai(el);
});

function confirmOr(el, event) {
    const msg = el.getAttribute("data-fm-confirm");
    if (msg && !window.confirm(msg)) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}

// Conferme: in fase di cattura, cosi' precedono qualunque handler della pagina.
document.addEventListener("submit", (e) => {
    const form = e.target instanceof Element ? e.target.closest("form[data-fm-confirm]") : null;
    if (form) confirmOr(form, e);
}, true);

document.addEventListener("click", (e) => {
    const el = e.target instanceof Element ? e.target.closest("[data-fm-confirm]") : null;
    if (el && el.tagName !== "FORM") confirmOr(el, e);
}, true);

document.addEventListener("click", (e) => {
    const el = e.target instanceof Element ? e.target.closest("[data-fm-dismiss]") : null;
    if (!el) return;
    const sel = el.getAttribute("data-fm-dismiss");
    const target = sel ? el.closest(sel) : el.parentElement;
    if (target) target.hidden = true;
});

document.addEventListener("change", (e) => {
    const el = e.target instanceof Element ? e.target.closest("[data-fm-autosubmit]") : null;
    if (el && el.form) el.form.submit();
});

document.addEventListener("click", async (e) => {
    const el = e.target instanceof Element ? e.target.closest("[data-fm-copy]") : null;
    if (!el) return;
    const txt = el.textContent || "";
    try {
        await navigator.clipboard.writeText(txt);
    } catch (_) {
        // Browser senza Clipboard API: seleziona il testo, l'utente copia a mano.
        const r = document.createRange();
        r.selectNodeContents(el);
        const s = window.getSelection();
        if (s) { s.removeAllRanges(); s.addRange(r); }
        return;
    }
    el.classList.add("fm-copied");
    setTimeout(() => el.classList.remove("fm-copied"), 350);
});

document.addEventListener("input", (e) => {
    const el = e.target;
    if (!(el instanceof HTMLInputElement)) return;
    // Sia il campo con data-fm-match sia quello di riferimento aggiornano la validita'.
    const dependents = el.hasAttribute("data-fm-match")
        ? [el]
        : (el.id ? Array.from(document.querySelectorAll(`input[data-fm-match="#${CSS.escape(el.id)}"]`)) : []);
    for (const dep of dependents) {
        const ref = document.querySelector(dep.getAttribute("data-fm-match") || "");
        if (!(ref instanceof HTMLInputElement)) continue;
        const msg = dep.getAttribute("data-fm-match-message") || "I due valori non corrispondono";
        dep.setCustomValidity(dep.value && dep.value !== ref.value ? msg : "");
    }
});

document.addEventListener("click", (e) => {
    const btn = e.target instanceof Element ? e.target.closest("[data-fm-row-toggle]") : null;
    if (!btn) return;
    const row = btn.closest("tr");
    const detail = row ? row.nextElementSibling : null;
    if (!detail) return;
    const expanded = btn.getAttribute("aria-expanded") === "true";
    btn.setAttribute("aria-expanded", expanded ? "false" : "true");
    btn.textContent = expanded ? "▶" : "▼";
    detail.hidden = expanded;
});

/**
 * C'è qualcuno che sta usando la pagina in questo momento?
 *
 * Basta il fuoco dentro un campo: chi scrive non deve trovarsi la pagina
 * ricaricata sotto le dita. Vale anche per i menu a tendina e per il testo
 * modificabile.
 */
export function staUsandoLaPagina(doc = document) {
    const a = doc.activeElement;
    if (!a || a === doc.body) return false;
    const tag = (a.tagName || "").toLowerCase();
    return tag === "input" || tag === "textarea" || tag === "select"
        || a.isContentEditable === true;
}

/**
 * `data-fm-autorefresh`: ricarica la pagina ogni N millisecondi.
 *
 * 21/9/2026 — segnalazione dell'utente: «nelle pagine di amministrazione ogni
 * tot secondi mi ricarica la pagina e flickera, perdendo tutte le
 * informazioni inserite». Era questo, e l'unica pagina che lo chiede è il
 * cruscotto del WAF (dieci secondi, per i contatori dal vivo). Finché lì non
 * c'era niente da scrivere non faceva danno; da quando c'è il filtro delle
 * richieste, ricaricare mentre si compila butta via quello che si sta
 * scrivendo.
 *
 * Adesso il giro **aspetta il suo turno**: se c'è il fuoco dentro un campo, o
 * se la scheda non è in primo piano, non ricarica — riprova più tardi. Un
 * contatore vecchio di dieci secondi in più non ha mai fatto male a nessuno;
 * una riga di filtro persa sì.
 *
 * @param {Document|HTMLElement} root
 * @param {{ricarica?: () => void, doc?: Document}} [opzioni] per le prove
 */
function armAutorefresh(root = document, opzioni = {}) {
    const ricarica = opzioni.ricarica || (() => window.location.reload());
    const doc = opzioni.doc || (root.ownerDocument || document);
    root.querySelectorAll("[data-fm-autorefresh]").forEach((el) => {
        if (el.dataset.fmArmed === "1") return;
        el.dataset.fmArmed = "1";
        const ms = Math.max(1000, Number(el.getAttribute("data-fm-autorefresh")) || 0);
        const giro = () => {
            if (!el.isConnected) return;
            if (staUsandoLaPagina(doc) || doc.visibilityState === "hidden") {
                setTimeout(giro, ms);
                return;
            }
            ricarica();
        };
        setTimeout(giro, ms);
    });
}

// Le prove hanno bisogno di chiamarlo con un finto `ricarica`: la navigazione
// vera, in jsdom, non esiste.
export { armAutorefresh };

function init() {
    armAutorefresh();
    // I campi data-fm-copy sono cliccabili: cursore e titolo lo dicono.
    document.querySelectorAll("[data-fm-copy]").forEach((el) => {
        if (!el.title) el.title = "Click per copiare";
        el.style.cursor = "pointer";
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
} else {
    init();
}
window.addEventListener("fm:navigated", init);
