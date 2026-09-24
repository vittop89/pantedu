/**
 * Il riepilogo prima di spostare materiali di classe (15/9/2026, richiesta
 * dell'utente).
 *
 * PERCHÉ
 *   Dopo uno spostamento la pagina si riapre con la classe di arrivo come nuova
 *   partenza. Il 15/9 l'utente ha spostato «1A → 1»; la pagina è tornata sulla
 *   «1» con tutti i suoi materiali, e lui ha scelto «3» credendo di partire dalla
 *   3A: 55 contenuti e una verifica di prima sono finiti in terza (ripristinati
 *   a mano). Non c'è un «annulla», e da una sezione senza incarico, finiti i
 *   materiali, la classe sparisce dall'elenco: non ci si torna.
 *
 * Quindi, prima di inviare, una finestra dice da dove a dove, quanti e quali, e
 * mette in guardia nei casi che cambiano chi vede i materiali.
 */

import { el, finestraDiConferma } from "../ui/finestra-conferma.js";

const ORDINALI = { 1: "prime", 2: "seconde", 3: "terze", 4: "quarte", 5: "quinte" };

/** L'anno di corso di una classe: «3A» → «3», «3» → «3». */
export function anno(codice) {
    const m = /^([1-9])/.exec(String(codice || ""));
    return m ? m[1] : null;
}

const eAnno = (codice) => /^[1-9]$/.test(String(codice || ""));

/** «3A · SCI», o «3». */
export function nomeClasse(c) {
    return c.indirizzo ? `${c.code} · ${c.indirizzo}` : c.code;
}

function quanti(n, uno, tanti) {
    return `${n} ${n === 1 ? uno : tanti}`;
}

/**
 * Titolo, elenco breve e avvisi. Pura: nessun DOM.
 *
 * @param {{code:string, indirizzo?:string|null, sospesa?:boolean}} partenza
 * @param {{code:string, indirizzo?:string|null}} arrivo
 * @param {string[]} contenuti titoli dei contenuti scelti
 * @param {string[]} verifiche titoli delle verifiche scelte
 * @param {number} totale quanti elementi ha la classe di partenza
 */
export function riepilogo({ partenza, arrivo, contenuti, verifiche, totale }) {
    const parti = [];
    if (contenuti.length > 0) parti.push(quanti(contenuti.length, "contenuto", "contenuti"));
    if (verifiche.length > 0) parti.push(quanti(verifiche.length, "verifica", "verifiche"));
    const cosa = parti.join(" e ");
    const da = nomeClasse(partenza);
    const a = nomeClasse(arrivo);

    const titoli = [...contenuti, ...verifiche];
    const mostrati = titoli.slice(0, 4);
    const elenco = mostrati.length === 0 ? "" : mostrati.map((t) => `«${t}»`).join(", ")
        + (titoli.length > mostrati.length ? ` e altri ${titoli.length - mostrati.length}` : "");

    const avvisi = [];
    const annoDa = anno(partenza.code);
    const annoA = anno(arrivo.code);
    if (annoDa && annoA && annoDa !== annoA) {
        avvisi.push(`Cambi anno di corso, dalla «${annoDa}» alla «${annoA}»: gli studenti delle ${ORDINALI[annoDa]} `
            + `non li vedranno più, e li vedranno quelli delle ${ORDINALI[annoA]}.`);
    } else if (eAnno(partenza.code) && !eAnno(arrivo.code)) {
        avvisi.push(`Dall'anno alla sezione: i materiali passano dalla «${partenza.code}» alla sola «${arrivo.code}», `
            + `e le altre ${ORDINALI[annoDa] || "classi"} non li vedranno più.`);
    }
    if (partenza.sospesa) {
        avvisi.push(`«${da}» non è più una tua classe: finiti i materiali sparisce dall'elenco, `
            + "e da qui non potrai riportarceli.");
    }
    if (totale > 0 && titoli.length === totale) {
        avvisi.push(`Sposti tutto: «${da}» resterà senza materiali.`);
    }

    return {
        titolo: `Spostare ${cosa} da «${da}» a «${a}»?`,
        elenco,
        avvisi,
    };
}

/** Apre la finestra; true se si conferma. */
export function confermaSpostamento(dati) {
    const r = riepilogo(dati);
    return finestraDiConferma({
        titolo: r.titolo,
        conferma: "Sposta",
        riempi: (corpo) => {
            if (r.elenco) corpo.append(el("p", "", r.elenco));
            for (const avviso of r.avvisi) {
                const p = el("p", "fm-avviso-inc__attenzione", avviso);
                p.setAttribute("role", "note");
                corpo.append(p);
            }
            corpo.append(el("p", "fm-avviso-inc__attesa",
                "Non c'è un «annulla»: per tornare indietro si sposta di nuovo al contrario."));
        },
    });
}
