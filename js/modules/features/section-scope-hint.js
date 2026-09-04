/**
 * InfoTip — componente ⓘ + popover riusabile (ADR-028 / pulizia UI).
 *
 * Sostituisce le descrizioni inline sparse: accanto a un titolo/campo si mette
 * una ⓘ (.fm-infotip) col testo nascosto; al click appare in popover. Pulisce
 * le pagine lasciando la spiegazione a richiesta.
 *
 * Trigger supportati:
 *   - <button class="fm-infotip"><span class="fm-infotip__body" hidden>HTML…</span></button>
 *     → mostra l'HTML del body (supporta link).
 *   - .fm-sb-info[data-group-mode] (sidebar sezioni) → testo scope generato.
 *
 * Vanilla ESM, accessibile (role/aria sul trigger lato markup), theme-aware.
 */

const POPOVER_ID = "fm-infotip-popover";
const TRIGGER_SEL = ".fm-infotip, .fm-sb-info";

function bodyHtmlFor(trigger) {
    // 1) contenuto esplicito nascosto nel markup (caso generico).
    const body = trigger.querySelector(":scope > .fm-infotip__body");
    if (body) return body.innerHTML;
    // 2) sidebar sezioni: testo derivato da data-group-mode.
    const gm = trigger.dataset.groupMode;
    const sidepage = trigger.closest("[data-sidepage]")?.dataset.sidepage;
    let base = "";
    if (gm === "subject") {
        base = `<p>📍 <strong>Sezione legata alla classe.</strong></p>
                <p>Mostra solo i documenti dell'<em>indirizzo · classe · materia</em>
                selezionati in alto. Cambia i selettori per le altre classi/materie.</p>`;
    } else if (gm === "category") {
        base = `<p>🌐 <strong>Sezione sempre visibile.</strong></p>
                <p>Non dipende dalla classe/materia selezionata.</p>
                <p><a href="/area-docente/categorie">🗂️ Gestisci categorie →</a></p>`;
    } else if (trigger.dataset.fmInfo) {
        // 3) fallback: attributo data-fm-info (testo semplice).
        const span = document.createElement("span");
        span.textContent = trigger.dataset.fmInfo;
        base = `<p>${span.innerHTML}</p>`;
    }
    // Nota specifica sezione VERIFICHE: l'inserimento è automatico.
    if (sidepage === "verif") {
        base += `<p>📝 <strong>Le verifiche salvate si inseriscono da sole</strong>
                 quando le <em>generi</em> (💾 SalvaTEX / TEX·PDF) dalla selezione
                 esercizi — non vanno aggiunte a mano. In quest'unica area materia
                 trovi i <em>contenuti/documenti</em> e, sotto «Verifiche salvate»,
                 le versioni (v1/v2/v3) generate.</p>`;
    }
    return base;
}

function closePopover() {
    document.getElementById(POPOVER_ID)?.remove();
    document.removeEventListener("click", onDocClick, true);
    document.removeEventListener("keydown", onKey, true);
}
function onDocClick(e) {
    const pop = document.getElementById(POPOVER_ID);
    if (pop && !pop.contains(e.target) && !e.target.closest?.(TRIGGER_SEL)) closePopover();
}
function onKey(e) { if (e.key === "Escape") closePopover(); }

function openPopover(trigger) {
    const existing = document.getElementById(POPOVER_ID);
    const owner = trigger.dataset.infotipOwner || "";
    if (existing) {
        const same = existing.dataset.owner === owner;
        closePopover();
        if (same) return; // ri-click sullo stesso ⓘ → chiude (toggle)
    }
    const html = bodyHtmlFor(trigger);
    if (!html) return;
    const pop = document.createElement("div");
    pop.id = POPOVER_ID;
    pop.className = "fm-infotip-popover";
    pop.dataset.owner = owner;
    pop.innerHTML = html + `<button type="button" class="fm-infotip-popover__close" aria-label="Chiudi">✕</button>`;
    document.body.appendChild(pop);
    // Difesa contro regole legacy che forzano position:relative (popover ancorato
    // in fondo al body invece che al viewport).
    pop.style.setProperty("position", "fixed", "important");

    const r = trigger.getBoundingClientRect();
    const pw = pop.offsetWidth, ph = pop.offsetHeight;
    const left = Math.min(r.left, window.innerWidth - pw - 8);
    let top = r.bottom + 6;
    if (top + ph > window.innerHeight - 8) top = Math.max(8, r.top - ph - 6);
    pop.style.left = `${Math.max(8, left)}px`;
    pop.style.top = `${top}px`;

    pop.querySelector(".fm-infotip-popover__close")?.addEventListener("click", closePopover);
    setTimeout(() => {
        document.addEventListener("click", onDocClick, true);
        document.addEventListener("keydown", onKey, true);
    }, 0);
}

let _seq = 0;
function handle(e) {
    const trigger = e.target.closest(TRIGGER_SEL);
    if (!trigger) return;
    e.preventDefault();
    e.stopPropagation();
    if (!trigger.dataset.infotipOwner) trigger.dataset.infotipOwner = `t${++_seq}`;
    openPopover(trigger);
}

function init() {
    document.addEventListener("click", (e) => {
        if (e.target.closest?.(TRIGGER_SEL)) handle(e);
    }, true);
    document.addEventListener("keydown", (e) => {
        if ((e.key === "Enter" || e.key === " ") && e.target.closest?.(TRIGGER_SEL)) handle(e);
    }, true);
}

if (typeof document !== "undefined") {
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init, { once: true });
    } else {
        init();
    }
}
