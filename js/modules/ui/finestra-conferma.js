/**
 * Una finestra di conferma (<dialog> nativo): titolo, sottotitolo, un corpo che
 * riempie chi la usa, «Annulla» e un bottone di conferma. Risolve true solo se
 * si conferma; Esc e «Annulla» risolvono false.
 *
 * Nata il 15/9/2026 per l'avviso degli incarichi tolti, e usata anche dal
 * riepilogo di «Sposta di classe». Gli stili sono in
 * css/modules/_admin-cards.css (`fm-avviso-inc`), caricati in ogni pagina.
 *
 * `riempi(corpo, { titolo, abilita })` scrive il contenuto. Con `attesa: true`
 * la conferma parte spenta, e la accende `abilita()`: serve quando il corpo
 * aspetta dati dal server. Tutto il testo si scrive con textContent.
 */

export function el(tag, classe, testo) {
    const nodo = document.createElement(tag);
    if (classe) nodo.className = classe;
    if (testo !== undefined) nodo.textContent = testo;
    return nodo;
}

let progressivo = 0;

export function finestraDiConferma({
    titolo,
    sottotitolo = "",
    conferma = "Conferma",
    annulla = "Annulla",
    pericolo = false,
    attesa = false,
    riempi,
}) {
    return new Promise((risolvi) => {
        progressivo += 1;
        const idTitolo = `fm-finestra-titolo-${progressivo}`;
        const dialog = el("dialog", "fm-avviso-inc");
        dialog.setAttribute("aria-labelledby", idTitolo);

        const testa = el("div", "fm-avviso-inc__testa");
        const h2 = el("h2", "fm-avviso-inc__titolo", titolo);
        h2.id = idTitolo;
        testa.append(h2);
        if (sottotitolo) testa.append(el("p", "fm-avviso-inc__sotto", sottotitolo));

        const corpo = el("div", "fm-avviso-inc__corpo");
        const piede = el("div", "fm-avviso-inc__piede");
        const bAnnulla = el("button", "fm-btn fm-btn--ghost fm-btn--sm", annulla);
        bAnnulla.type = "button";
        const bConferma = el("button", `fm-btn ${pericolo ? "fm-btn--danger" : "fm-btn--primary"} fm-btn--sm`, conferma);
        bConferma.type = "button";
        bConferma.dataset.fmConferma = "";
        bConferma.disabled = attesa;
        piede.append(bAnnulla, bConferma);
        dialog.append(testa, corpo, piede);
        document.body.append(dialog);

        let finita = false;
        const chiudi = (esito) => {
            if (finita) return;
            finita = true;
            if (typeof dialog.close === "function" && dialog.open) dialog.close();
            dialog.remove();
            risolvi(esito);
        };
        bAnnulla.addEventListener("click", () => chiudi(false));
        bConferma.addEventListener("click", () => chiudi(true));
        dialog.addEventListener("cancel", (e) => { e.preventDefault(); chiudi(false); });

        if (typeof dialog.showModal === "function") {
            try { dialog.showModal(); } catch { dialog.setAttribute("open", ""); }
        } else {
            dialog.setAttribute("open", "");
        }
        bAnnulla.focus();

        riempi(corpo, {
            titolo: h2,
            abilita: () => { if (!finita) bConferma.disabled = false; },
            aperta: () => !finita,
        });
    });
}
