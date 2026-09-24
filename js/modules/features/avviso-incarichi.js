/**
 * La finestra prima di togliere incarichi a un docente, in /admin/sections
 * (15/9/2026, richiesta dell'utente).
 *
 * Fino a quel giorno c'era un `window.confirm` che non diceva il nome del
 * docente, non diceva se su quelle sezioni c'erano suoi materiali, e affermava
 * che «gli studenti di quelle sezioni non vedranno più i suoi contenuti»: vero
 * solo per gli studenti con account (scenario Istituto). Chi entra con una
 * credenziale di classe continua a vederli, perché la visibilità segue la
 * credenziale. La revoca dalla tabella non chiedeva nemmeno conferma.
 *
 * Adesso la finestra chiede al server (`/admin/sections/anteprima-revoca`) che
 * cosa ha il docente su ogni sezione, per tipo, e lo mette davanti
 * all'amministratore, con le conseguenze e l'esito dell'email al docente. Tutto
 * il testo che viene dal server passa da textContent.
 */

import { el, finestraDiConferma } from "../ui/finestra-conferma.js";

const TESTO_EMAIL = {
    si: (nome) => `${nome} riceverà un'email con questo elenco e con il modo di spostare i materiali.`,
    // ADR-043 (15/9/2026, scelta dell'utente): senza materiali né credenziali non gli si scrive.
    niente_da_fare: (nome, una) => `Nessuna email: su ${una ? "questa classe" : "queste classi"} ${nome} non ha materiali né credenziali, e non ha niente da fare.`,
    senza_indirizzo: (nome) => `Nessuna email: ${nome} non ha un indirizzo valido.`,
    senza_posta: () => "Nessuna email: questa installazione non manda posta.",
};

/** L'anteprima dal server. */
export async function caricaAnteprima({ istituto, docente, indirizzo, classi }) {
    const q = new URLSearchParams({
        institute_id: String(istituto),
        user_id: String(docente),
        indirizzo: indirizzo || "",
        classi: classi.join(","),
    });
    const r = await fetch(`/admin/sections/anteprima-revoca?${q.toString()}`, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
    });
    const dati = await r.json().catch(() => null);
    if (!r.ok || !dati || dati.ok !== true) {
        throw new Error((dati && dati.error) || `HTTP ${r.status}`);
    }
    return dati;
}


/**
 * Il contenuto della finestra per un'anteprima arrivata dal server.
 * Esportata per le prove: non tocca la rete.
 */
export function disegnaAnteprima(corpo, dati, classi) {
    corpo.replaceChildren();
    const nome = dati.docente || "il docente";
    const una = classi.length === 1;
    const sezioni = Array.isArray(dati.sezioni) ? dati.sezioni : [];
    const materiali = sezioni.reduce((n, s) => n + (Number(s.materiali) || 0), 0);
    const credenziali = sezioni.reduce((n, s) => n + (Number(s.credenziali) || 0), 0);
    const studenti = sezioni.reduce((n, s) => n + (Number(s.studenti) || 0), 0);
    const tutti = dati.modalita === "tutti";
    // ADR-043 — una classe è una sezione o un anno di un corso; «porta sull'anno» vale solo per le sezioni.
    const qui = una ? "questa classe" : "queste classi";
    const conSezioni = classi.some((c) => !/^[1-9]$/.test(String(c)));

    if (!tutti) {
        const att = el("p", "fm-avviso-inc__attenzione");
        att.setAttribute("role", "note");
        att.append(el("strong", "", "Attenzione. "),
            `${nome} perde ${qui} dai suoi menù: `
            + "non potrà più pubblicarvi materiali né creare credenziali di classe.");
        corpo.append(att);
    }

    const tabella = el("table", "fm-avviso-inc__tabella");
    const testa = tabella.createTHead().insertRow();
    for (const t of ["Classe", `Materiali di ${nome}`, "Credenziali attive"]) {
        const th = el("th", "", t);
        th.setAttribute("scope", "col");
        testa.append(th);
    }
    const righe = tabella.createTBody();
    for (const s of sezioni) {
        const tr = righe.insertRow();
        tr.dataset.sezione = s.code;
        tr.append(el("td", "", s.code));
        tr.append(el("td", Number(s.materiali) > 0 ? "" : "fm-avviso-inc__vuota", s.descrizione));
        tr.append(el("td", "", String(Number(s.credenziali) || 0)));
    }
    corpo.append(tabella);

    const punti = el("ul", "fm-avviso-inc__punti");
    if (materiali > 0) {
        const sullAnno = conSezioni ? "; da una sezione, se nessuno li sposta, qui sotto puoi portarli sull'anno." : ".";
        punti.append(el("li", "fm-avviso-inc__forte",
            `I materiali non si cancellano e non si spostano: restano lì. ${nome} li ritrova in «Sposta di classe» come «senza incarico»${sullAnno}`));
    } else {
        punti.append(el("li", "", `Su ${qui} ${nome} non ha materiali pubblicati.`));
    }
    if (credenziali > 0) {
        punti.append(el("li", "", `Chi entra con le sue credenziali di classe su ${qui} continua a vedere i materiali.`));
    }
    if (studenti > 0) {
        punti.append(el("li", "fm-avviso-inc__forte",
            `${studenti} ${studenti === 1 ? "studente con account" : "studenti con account"} di ${qui} non ${studenti === 1 ? "vedrà" : "vedranno"} più i suoi contenuti.`));
    }
    if (tutti) {
        punti.append(el("li", "", "Con la modalità «tutti» il docente continua a usare le classi: l'incarico conta solo per gli studenti con account."));
    } else {
        punti.append(el("li", "", `Ridando l'incarico, ${una ? "la classe torna" : "le classi tornano"} nei suoi menù con i materiali che sono ancora lì.`));
    }
    const email = TESTO_EMAIL[dati.email];
    if (email) punti.append(el("li", "", email(nome, una)));
    corpo.append(punti);
    return { materiali, credenziali, studenti };
}

/**
 * Apre la finestra e risolve true se l'amministratore conferma.
 * `carica` si sostituisce nelle prove.
 */
export function chiediConferma({ istituto, docente, nomeDocente, indirizzo, classi, carica = caricaAnteprima }) {
    const una = classi.length === 1;
    const domanda = (nome) => `Togliere ${una ? "l'incarico" : "gli incarichi"} a ${nome}?`;
    return finestraDiConferma({
        titolo: domanda(nomeDocente || "questo docente"),
        sottotitolo: `${indirizzo ? `${indirizzo} · ` : ""}${una ? "classe" : "classi"} ${classi.join(", ")}`,
        conferma: una ? "Togli l'incarico" : "Togli gli incarichi",
        pericolo: true,
        attesa: true,
        riempi: (corpo, { titolo, abilita, aperta }) => {
            corpo.append(el("p", "fm-avviso-inc__attesa", "Conto i materiali del docente su queste classi…"));
            carica({ istituto, docente, indirizzo, classi })
                .then((dati) => {
                    if (!aperta()) return;
                    if (dati.docente) titolo.textContent = domanda(dati.docente);
                    disegnaAnteprima(corpo, dati, classi);
                    abilita();
                })
                .catch(() => {
                    if (!aperta()) return;
                    corpo.replaceChildren(el("p", "fm-avviso-inc__attenzione",
                        "Non riesco a contare i materiali del docente su queste classi. "
                        + "Puoi annullare e riprovare, o togliere comunque: i materiali non si cancellano."));
                    abilita();
                });
        },
    });
}
