import { fetchJson, fetchCsrf } from "../core/dom-utils.js";

/**
 * «Dove vale» e «Duplica in…» nel modale del contenuto (ADR-037, fase 2).
 *
 * Un contenuto ha una pubblicazione principale (il posto in cui l'hai creato,
 * che si sposta con «Sposta di classe») e ne può avere altre, anche in
 * un'altra delle tue scuole: una mappa pubblicata in 2A e in 2B è una mappa
 * sola, e la correggi una volta. Ogni pubblicazione ha il suo stato.
 * «Duplica in…» invece crea una copia indipendente, che da lì in poi vive per
 * conto suo.
 *
 * I posti proposti sono solo le voci che hai spuntato nel profilo, scuola per
 * scuola; il server rifà comunque tutti i controlli (DoveVale::verificaLuogo).
 *
 * Il pannello si costruisce con i nodi, non con innerHTML: nomi di scuole e
 * sigle arrivano dal server, e con i nodi non c'è niente da escapare (la
 * regola semgrep `js-innerhtml-with-user-data` conta, e non deve salire).
 *
 * API: GET /api/teacher/content/{id}/pubblicazioni, GET /api/teacher/pubblicazioni/luoghi,
 *      POST /api/teacher/content/{id}/pubblicazioni, POST /api/teacher/pubblicazioni/{id}/stato|togli,
 *      POST /api/teacher/content/{id}/duplica
 *
 * ADR-037, fase 3 — lo stesso pannello per le verifiche (`fonte: "verifica"`),
 * nel modale della verifica. Lì un posto vale per tutte le varianti e le
 * versioni con quel titolo, lo stato della principale si sceglie qui (è ciò
 * che decide se gli studenti la vedono), e la copia duplica il pacchetto.
 * API: /api/verifica/{id}/pubblicazioni[/{pub}/stato|togli], /api/verifica/{id}/duplica.
 *
 * ADR-037, fase 4c — la visibilità del contenuto resta l'interruttore: in bozza
 * o archiviato non lo vede nessuno, in nessun posto. Un posto «Pubblicato» di un
 * contenuto che non è pubblicato si mostra «sospeso», e il pannello segue il
 * selettore della visibilità del modale (`selettoreStato`) mentre lo si cambia,
 * prima ancora di salvare. Le verifiche non hanno un interruttore.
 */

const FONTI = {
    contenuto: {
        elenco: (id) => `/api/teacher/content/${id}/pubblicazioni`,
        aggiungi: (id) => `/api/teacher/content/${id}/pubblicazioni`,
        stato: (_id, pub) => `/api/teacher/pubblicazioni/${pub}/stato`,
        togli: (_id, pub) => `/api/teacher/pubblicazioni/${pub}/togli`,
        duplica: (id) => `/api/teacher/content/${id}/duplica`,
        stati: [["draft", "Bozza"], ["published", "Pubblicato"], ["archived", "Archiviato"]],
        principaleModificabile: false,
        aggiunta: "Pubblicazione aggiunta: è lo stesso contenuto, si corregge una volta.",
        copia: "Copia creata, in bozza: da adesso è un contenuto a sé.",
        invito: "Pubblica lo stesso contenuto anche in:",
    },
    verifica: {
        elenco: (id) => `/api/verifica/${id}/pubblicazioni`,
        aggiungi: (id) => `/api/verifica/${id}/pubblicazioni`,
        stato: (id, pub) => `/api/verifica/${id}/pubblicazioni/${pub}/stato`,
        togli: (id, pub) => `/api/verifica/${id}/pubblicazioni/${pub}/togli`,
        duplica: (id) => `/api/verifica/${id}/duplica`,
        stati: [["draft", "Bozza"], ["published", "Pubblicata"], ["archived", "Archiviata"]],
        principaleModificabile: true,
        aggiunta: "Pubblicazione aggiunta: vale per tutte le varianti della verifica.",
        copia: "Copia creata, in bozza: da adesso è una verifica a sé.",
        invito: "Pubblica la stessa verifica anche in:",
    },
};

const ERRORI = {
    non_trovato: "Il contenuto non è tuo, o non esiste più.",
    non_consentito: "Il tuo profilo non consente di pubblicare in più posti.",
    stato_non_valido: "Stato non valido.",
    scuola_non_tua: "Non sei collegato a questa scuola.",
    voce_non_valida: "Indirizzo, classe o materia non sono del catalogo di quella scuola.",
    voce_non_spuntata: "Spunta prima indirizzo, classe e materia nel profilo, per quella scuola.",
    classe_di_un_altro_corso: "Quella classe appartiene a un altro corso.",
    gia_pubblicato: "È già pubblicato in quella classe di quella scuola.",
    troppe_pubblicazioni: "Troppe pubblicazioni per un documento solo.",
    principale_non_si_toglie: "Il posto principale non si toglie: si sposta con «Sposta di classe».",
    copia_non_riuscita: "La copia non è riuscita: non è stato creato niente.",
    illeggibile: "Il contenuto non si riesce a leggere: la copia non è partita.",
};

function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v === undefined || v === null || v === false) continue;
        if (k === "dataset") { Object.assign(node.dataset, v); continue; }
        node.setAttribute(k, String(v));
    }
    node.append(...children.filter((c) => c !== null && c !== undefined && c !== false));
    return node;
}

async function invia(url, fields) {
    const csrf = await fetchCsrf();
    const body = new URLSearchParams({ _csrf: csrf, ...fields });
    const j = await fetchJson(url, {
        method: "POST",
        cache: "no-store",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString(),
    });
    if (!j || !j.ok) {
        const codice = (j && j.error) || "errore";
        throw new Error(ERRORI[codice] || codice);
    }
    return j;
}

function etichettaDiPosto(p) {
    const sigle = [p.indirizzo, p.classe, p.materia].filter(Boolean).join(" · ");
    return sigle ? `${p.scuola} — ${sigle}` : p.scuola;
}

/**
 * Tre selettori in catena (scuola → indirizzo → classe → materia) sulle voci
 * spuntate. `valore()` torna i quattro id, o null finché manca qualcosa.
 */
function sceltaDelPosto(scuole, prefisso) {
    const opzione = (value, text) => el("option", { value }, text);
    const vuoto = (testo) => opzione("", testo);
    const sel = (nome, etichetta) => {
        const s = el("select", { class: "fm-input", name: `${prefisso}-${nome}`, "aria-label": etichetta });
        return s;
    };
    const selScuola = sel("scuola", "Scuola");
    const selIndirizzo = sel("indirizzo", "Indirizzo");
    const selClasse = sel("classe", "Classe");
    const selMateria = sel("materia", "Materia");

    selScuola.replaceChildren(vuoto("Scuola…"), ...scuole.map((s) => opzione(String(s.id), s.nome)));
    const scuolaScelta = () => scuole.find((s) => String(s.id) === selScuola.value) || null;

    const ridisegna = () => {
        const s = scuolaScelta();
        const indirizzoPrima = selIndirizzo.value;
        selIndirizzo.replaceChildren(vuoto("Indirizzo…"), ...(s ? s.indirizzi.map((v) => opzione(String(v.id), `${v.code} — ${v.label}`)) : []));
        if (s && s.indirizzi.some((v) => String(v.id) === indirizzoPrima)) selIndirizzo.value = indirizzoPrima;
        const corso = s ? (s.indirizzi.find((v) => String(v.id) === selIndirizzo.value)?.code || null) : null;
        const classi = s && corso ? s.classi.filter((c) => !c.indirizzo || c.indirizzo.toUpperCase() === corso.toUpperCase()) : [];
        const classePrima = selClasse.value;
        selClasse.replaceChildren(vuoto("Classe…"), ...classi.map((c) => opzione(String(c.id), `${c.code} — ${c.label}`)));
        if (classi.some((c) => String(c.id) === classePrima)) selClasse.value = classePrima;
        const materiaPrima = selMateria.value;
        selMateria.replaceChildren(vuoto("Materia…"), ...(s && selClasse.value ? s.materie.map((m) => opzione(String(m.id), `${m.code} — ${m.label}`)) : []));
        if (s && s.materie.some((m) => String(m.id) === materiaPrima)) selMateria.value = materiaPrima;
        selIndirizzo.disabled = !s;
        selClasse.disabled = !selIndirizzo.value;
        selMateria.disabled = !selClasse.value;
    };
    for (const s of [selScuola, selIndirizzo, selClasse]) s.addEventListener("change", ridisegna);
    ridisegna();

    return {
        nodi: [selScuola, selIndirizzo, selClasse, selMateria],
        valore() {
            if (!selScuola.value || !selIndirizzo.value || !selClasse.value || !selMateria.value) return null;
            return { scuola: selScuola.value, indirizzo: selIndirizzo.value, classe: selClasse.value, materia: selMateria.value };
        },
    };
}

/**
 * Monta il pannello dentro `box` per il documento `contentId`: un contenuto
 * (predefinito) o una verifica (`fonte: "verifica"`).
 * `onDuplicato(id)` è chiamato quando una copia è stata creata.
 */
export async function montaDoveVale(box, contentId, { onDuplicato, fonte = "contenuto", selettoreStato = null } = {}) {
    if (!box || !contentId) return;
    const id = String(Number(contentId));
    const F = FONTI[fonte] || FONTI.contenuto;
    const STATI = F.stati;
    box.replaceChildren(el("p", { class: "fm-muted" }, "Carico le pubblicazioni…"));

    let dati;
    let scuole;
    const carica = async () => {
        const t = Date.now();
        const [a, b] = await Promise.all([
            fetchJson(`${F.elenco(id)}?t=${t}`, { cache: "no-store" }),
            fetchJson(`/api/teacher/pubblicazioni/luoghi?t=${t}`, { cache: "no-store" }),
        ]);
        if (!a || !a.ok) throw new Error(ERRORI[a?.error] || a?.error || "pubblicazioni non disponibili");
        // Le due fonti rispondono con forme vicine: un contenuto elenca le sue
        // pubblicazioni, una verifica i suoi posti (con quante varianti ci sono).
        dati = fonte === "verifica"
            ? { pubblicazioni: a.posti || [], totale: a.verifica?.varianti || 0, contenuto: { publish_scope: "" } }
            : a;
        scuole = (b && b.scuole) || [];
    };

    const riscontro = el("p", { class: "fm-dove-vale-riscontro fm-text-13", role: "status", "aria-live": "polite" });
    const di = (msg, errore = false) => {
        riscontro.textContent = msg;
        riscontro.classList.toggle("fm-text-danger", errore);
    };

    const disegna = () => {
        const puoiPubblicareAltrove = window.FM?.Caps?.visibilityAllowed?.("classes") ?? true;
        // La visibilità del contenuto, com'è scelta nel modale adesso (salvata o no).
        const documento = fonte === "verifica" ? "published" : (selettoreStato?.value || dati.contenuto.visibility || "published");
        const perPiuClassi = dati.contenuto.publish_scope === "classes";
        const sospeso = (p) => documento !== "published" && !p.principale && p.stato === "published";
        const righe = dati.pubblicazioni.map((p) => {
            // La principale di un contenuto ha lo stato della visibilità scelta
            // qui sopra (in bozza se era «per più classi»).
            const statoPrincipale = fonte === "verifica" || perPiuClassi ? p.stato : documento;
            const stato = p.principale && !F.principaleModificabile
                ? el("span", { class: "fm-muted", title: "Lo stato del posto principale è la visibilità scelta qui sopra" },
                    (STATI.find(([v]) => v === statoPrincipale) || [statoPrincipale, statoPrincipale])[1])
                : (() => {
                    const s = el("select", { class: "fm-input", "aria-label": `Stato in ${etichettaDiPosto(p)}`, dataset: { pubblicazione: String(p.id) } },
                        // «misto»: le varianti di una verifica hanno stati diversi
                        // (una versione salvata dopo nasce in bozza). Si mostra, non si sceglie.
                        ...(p.stato === "misto" ? [el("option", { value: "misto", disabled: true }, "In parte")] : []),
                        ...STATI.map(([v, t]) => el("option", { value: v }, t)));
                    s.value = p.stato;
                    s.addEventListener("change", async () => {
                        s.disabled = true;
                        try {
                            await invia(F.stato(id, p.id), { stato: s.value });
                            p.stato = s.value;
                            disegna();
                            di(`Stato aggiornato in ${etichettaDiPosto(p)}.`);
                        } catch (e) {
                            s.value = p.stato;
                            di(e.message || String(e), true);
                        } finally {
                            s.disabled = false;
                        }
                    });
                    return s;
                })();
            const togli = p.principale ? null : el("button", { type: "button", class: "fm-btn fm-btn--sm fm-btn--danger" }, "Togli");
            togli?.addEventListener("click", async () => {
                togli.disabled = true;
                try {
                    await invia(F.togli(id, p.id), {});
                    await carica();
                    disegna();
                    di(`Tolto da ${etichettaDiPosto(p)}.`);
                } catch (e) {
                    togli.disabled = false;
                    di(e.message || String(e), true);
                }
            });
            const parziale = dati.totale && p.varianti !== undefined && p.varianti < dati.totale
                ? el("span", { class: "fm-muted fm-text-13", title: "Le altre varianti non hanno questo posto" }, ` ${p.varianti} varianti su ${dati.totale}`)
                : null;
            const etichettaSospeso = sospeso(p)
                ? el("span", { class: "fm-dove-vale-sospeso", title: "Il contenuto non è pubblicato: qui non lo vede nessuno finché non lo pubblichi" }, "sospeso")
                : null;
            return el("li", { class: "fm-dove-vale-riga", dataset: { pubblicazione: String(p.id), principale: p.principale ? "1" : "0", sospeso: sospeso(p) ? "1" : "0" } },
                el("span", {}, etichettaDiPosto(p), p.principale ? el("strong", {}, " (principale)") : null, parziale),
                " ", stato, etichettaSospeso, " ", togli);
        });

        const figli = [
            el("ul", { class: "fm-dove-vale-elenco" }, ...righe),
        ];
        if (dati.pubblicazioni.some(sospeso)) {
            figli.push(el("p", { class: "fm-dove-vale-nota-sospesi fm-muted fm-text-13" },
                documento === "archived"
                    ? "Il contenuto è archiviato: i posti «Pubblicato» sono sospesi, e nessuno lo vede finché non lo pubblichi di nuovo."
                    : "Il contenuto è in bozza: i posti «Pubblicato» sono sospesi, e tornano visibili agli studenti quando lo pubblichi."));
        }
        if (fonte === "verifica") {
            figli.push(el("p", { class: "fm-muted fm-text-13" },
                "Gli studenti di una classe vedono la verifica dove è «Pubblicata». Condividerla con i colleghi non la pubblica."));
        } else if (dati.contenuto.publish_scope === "classes") {
            figli.push(el("p", { class: "fm-muted fm-text-13" },
                "Questo contenuto era «per più classi»: gli studenti lo vedono nei posti qui sopra diversi dal principale, ognuno con il suo stato."));
        }

        if (puoiPubblicareAltrove && scuole.length) {
            const posto = sceltaDelPosto(scuole, "fm-pub-nuova");
            const stato = el("select", { class: "fm-input", "aria-label": "Stato nel posto nuovo" },
                ...STATI.slice(0, 2).map(([v, t]) => el("option", { value: v }, t)));
            const aggiungi = el("button", { type: "button", class: "fm-btn fm-btn--sm" }, "Pubblica anche qui");
            aggiungi.addEventListener("click", async () => {
                const v = posto.valore();
                if (!v) { di("Scegli scuola, indirizzo, classe e materia.", true); return; }
                aggiungi.disabled = true;
                try {
                    await invia(F.aggiungi(id), { ...v, stato: stato.value });
                    await carica();
                    disegna();
                    di(F.aggiunta);
                } catch (e) {
                    aggiungi.disabled = false;
                    di(e.message || String(e), true);
                }
            });
            figli.push(el("div", { class: "fm-dove-vale-aggiungi" },
                el("p", { class: "fm-text-13 fm-m-0" }, F.invito),
                ...posto.nodi, stato, aggiungi));
        }

        if (scuole.length) {
            const posto = sceltaDelPosto(scuole, "fm-pub-copia");
            const duplica = el("button", { type: "button", class: "fm-btn fm-btn--sm" }, "Duplica qui");
            duplica.addEventListener("click", async () => {
                const v = posto.valore();
                if (!v) { di("Scegli scuola, indirizzo, classe e materia per la copia.", true); return; }
                duplica.disabled = true;
                try {
                    const j = await invia(F.duplica(id), v);
                    di(F.copia);
                    onDuplicato?.(Number(j.id));
                } catch (e) {
                    di(e.message || String(e), true);
                } finally {
                    duplica.disabled = false;
                }
            });
            figli.push(el("div", { class: "fm-dove-vale-duplica" },
                el("p", { class: "fm-text-13 fm-m-0" }, "Oppure fanne una copia indipendente in:"),
                ...posto.nodi, duplica));
        }
        figli.push(riscontro);
        box.replaceChildren(...figli);
    };

    selettoreStato?.addEventListener("change", () => { if (dati) disegna(); });

    try {
        await carica();
        disegna();
    } catch (e) {
        box.replaceChildren(el("p", { class: "fm-text-danger" }, `Pubblicazioni non disponibili: ${e.message || e}`));
    }
}
