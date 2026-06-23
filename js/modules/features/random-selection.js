/**
 * G20.7 — Random selection mode per la verifica.
 *
 * Toggle (🎲) attiva una modalita' che inietta 2 input dentro ogni
 * .fm-groupcollex .check:
 *   - .fm-rand-n: numero esercizi da selezionare random in quel problem
 *   - .fm-rand-pt: punteggio TOTALE da dividere tra gli esercizi selezionati
 *
 * Pulsante (🎯) esegue la selezione random:
 *   - itera i .fm-groupcollex con .checkboxA o .checkboxB checked
 *   - per ognuno, sceglie N collex-item con .fm-titolo-quesito DISTINCT
 *     (random tra i possibili). Setta .fm-checkbox-ain / .fm-checkbox-bin in base
 *     a quali tra A/B erano selezionati nel .fm-groupcollex header.
 *   - distribuisce Pt sui selezionati (valore = Pt/N nei .fm-input-pt).
 *   - Se ci sono meno titolo_quesito distinct di N richiesti, seleziona
 *     comunque N (consentendo titoli ripetuti) e mostra un toast warning
 *     che specifica il problem affetto.
 */

const BODY_CLASS = "fm-rand-mode";
const RAND_INPUTS_HTML = `
    <span class="fm-rand-inputs" aria-label="Selezione random per questo problem">
        <input type="number" class="fm-rand-n"  min="1" step="1" placeholder="N" title="Numero esercizi da selezionare">
        <input type="number" class="fm-rand-pt" min="0" step="0.5" placeholder="Pt" title="Punteggio totale da dividere">
    </span>
`;

function toast(kind, title, msg) {
    if (window.FM?.ToastManager?.show) {
        window.FM.ToastManager.show(kind, title, msg, 5000);
    } else {
        console[kind === "error" ? "error" : "info"](`[random] ${title}: ${msg}`);
    }
}

function injectInputsIntoChecks() {
    // .check non e' figlio diretto di .fm-groupcollex (e' nested in .content o
    // .PosCheckEs). Selezioniamo il .check del header che contiene .checkboxA.
    document.querySelectorAll(".fm-groupcollex .fm-check:has(.checkboxA)").forEach(check => {
        if (check.querySelector(".fm-rand-inputs")) return; // idempotente
        check.insertAdjacentHTML("beforeend", RAND_INPUTS_HTML);
    });
}
function removeInputsFromChecks() {
    document.querySelectorAll(".fm-rand-inputs").forEach(n => n.remove());
}

function setRandMode(active) {
    document.body.classList.toggle(BODY_CLASS, active);
    const btn = document.getElementById("fm-random-toggle");
    if (btn) btn.setAttribute("aria-pressed", active ? "true" : "false");
    if (active) injectInputsIntoChecks();
    else        removeInputsFromChecks();
}

/** Sample N elements WITHOUT replacement preferendo titolo_quesito DISTINCT.
 *  Ritorna { picked: items[], duplicates: bool } dove duplicates=true se
 *  abbiamo dovuto sforare per raggiungere N (titoli distinti < N). */
function pickRandomDistinct(items, n) {
    const byTitle = new Map();
    for (const it of items) {
        const t = it.titolo;
        if (!byTitle.has(t)) byTitle.set(t, []);
        byTitle.get(t).push(it);
    }
    const titles = Array.from(byTitle.keys());
    shuffle(titles);
    const picked = [];
    let duplicates = false;
    // Round 1: 1 per titolo, fino a min(N, |titles|).
    for (const t of titles) {
        if (picked.length >= n) break;
        const arr = byTitle.get(t);
        const idx = Math.floor(Math.random() * arr.length);
        picked.push(arr[idx]);
    }
    // Round 2: se serve di piu', pesca dagli items rimanenti.
    if (picked.length < n) {
        duplicates = true;
        const remaining = items.filter(i => !picked.includes(i));
        shuffle(remaining);
        while (picked.length < n && remaining.length) {
            picked.push(remaining.shift());
        }
    }
    return { picked, duplicates };
}

function shuffle(arr) {
    for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [arr[i], arr[j]] = [arr[j], arr[i]];
    }
}

function applyRandomSelection() {
    const problems = document.querySelectorAll(".fm-groupcollex");
    if (!problems.length) {
        toast("info", "Random", "Nessun .fm-groupcollex in pagina.");
        return;
    }
    let totalPicked = 0;
    let touchedProblems = 0;

    problems.forEach(problem => {
        // Il .check header (che contiene .checkboxA del problem) puo' essere
        // nested. Lo identifichiamo come il primo .check del problem che ha
        // .checkboxA (gli altri .check eventuali sono dentro i .fm-collection__item
        // o sub-tabelle, non sono i flag header).
        const headerCheck = problem.querySelector(".fm-check:has(.checkboxA)");
        const wantA = !!headerCheck?.querySelector(".checkboxA")?.checked;
        const wantB = !!headerCheck?.querySelector(".checkboxB")?.checked;
        if (!wantA && !wantB) return; // skip problem non flaggato A/R

        const nInput = headerCheck?.querySelector(".fm-rand-n");
        const ptInput = headerCheck?.querySelector(".fm-rand-pt");
        const N = parseInt(nInput?.value || "0", 10) || 0;
        const PT = parseFloat(ptInput?.value || "0") || 0;
        if (N <= 0) return; // skip se N non specificato

        const items = Array.from(problem.querySelectorAll(".fm-collection__item")).map(el => ({
            el,
            titolo: (el.querySelector(".fm-titolo-quesito")?.textContent || "").trim().toLowerCase(),
        }));
        if (!items.length) return;

        // Reset prima: deseleziona TUTTI i checkboxAin/Bin del problem.
        items.forEach(({ el }) => {
            const ain = el.querySelector(".fm-checkbox-ain");
            const bin = el.querySelector(".fm-checkbox-bin");
            if (ain && wantA) ain.checked = false;
            if (bin && wantB) bin.checked = false;
        });

        const { picked, duplicates } = pickRandomDistinct(items, N);
        const ptEach = N > 0 && PT > 0 ? (PT / N) : 0;
        const ptStr = ptEach ? (Math.round(ptEach * 100) / 100).toString() : "";

        picked.forEach(({ el }) => {
            if (wantA) {
                const ain = el.querySelector(".fm-checkbox-ain");
                if (ain) {
                    ain.checked = true;
                    ain.dispatchEvent(new Event("change", { bubbles: true }));
                }
            }
            if (wantB) {
                const bin = el.querySelector(".fm-checkbox-bin");
                if (bin) {
                    bin.checked = true;
                    bin.dispatchEvent(new Event("change", { bubbles: true }));
                }
            }
            if (ptStr) {
                const pt = el.querySelector(".fm-input-pt, input.inputPt");
                if (pt) {
                    pt.value = ptStr;
                    pt.dispatchEvent(new Event("input", { bubbles: true }));
                    pt.dispatchEvent(new Event("change", { bubbles: true }));
                }
            }
        });
        totalPicked += picked.length;
        touchedProblems++;

        if (duplicates) {
            const label = problem.querySelector(".titolo_problem, h3, h4")?.textContent?.trim()
                       || problem.id || `problem #${touchedProblems}`;
            toast("warning", "Titoli insufficienti",
                `In "${label}" i titoli_quesito distinti sono meno di N=${N}: la selezione include duplicati.`);
        }
    });

    if (!touchedProblems) {
        toast("info", "Random", "Nessun problem ha A/R selezionato e N>0.");
    } else {
        toast("success", "Selezione random",
            `Selezionati ${totalPicked} esercizi su ${touchedProblems} problem.`);
    }
}

let _bound = false;
function init() {
    if (_bound) return;
    _bound = true;

    document.addEventListener("click", (e) => {
        const t = e.target;
        if (!t?.closest) return;
        if (t.closest("#fm-random-toggle")) {
            e.preventDefault();
            const active = !document.body.classList.contains(BODY_CLASS);
            setRandMode(active);
        } else if (t.closest("#fm-random-pick")) {
            e.preventDefault();
            applyRandomSelection();
        }
    });

    // G20.7 — Click su .labcheck (pill A/R) toggla il checkbox sibling.
    // ContractRenderer server-rende `<input>` senza id e `<label>` senza
    // for=, quindi il click sul label non triggava il toggle nativo. Qui
    // intercettiamo e toggle il primo input[type=checkbox] del wrapper div.
    document.addEventListener("click", (e) => {
        const lab = e.target.closest?.(".fm-groupcollex .check .labcheck");
        if (!lab) return;
        const wrap = lab.parentElement;
        const cb = wrap?.querySelector("input.checkboxA, input.checkboxB");
        if (!cb) return;
        e.preventDefault();
        cb.checked = !cb.checked;
        cb.dispatchEvent(new Event("change", { bubbles: true }));
    });

    // Re-iniezione input se sono apparsi nuovi .fm-groupcollex in modalita' attiva.
    window.addEventListener("fm:verifica-ui-loaded", () => {
        if (document.body.classList.contains(BODY_CLASS)) injectInputsIntoChecks();
    });
}

init();

window.FM = window.FM || {};
window.FM.RandomSelection = { setRandMode, applyRandomSelection };
