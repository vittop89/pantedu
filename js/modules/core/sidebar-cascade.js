/**
 * I selettori della sidebar seguono l'istituto e l'indirizzo scelti, e si
 * scelgono in ordine: indirizzo, poi classe, poi materia.
 *
 * IL PROBLEMA
 *   Cambiando istituto, indirizzo/classe/materia restavano quelli di prima.
 *   Si arrivava a combinazioni inesistenti — "Liceo Musicale" con indirizzo
 *   "Scientifico" e classe "1A" — che non danno errore: semplicemente non
 *   trovano niente, e sembra che i contenuti siano spariti.
 *
 *   Da quando l'attribuzione dei contenuti segue l'istituto CORRENTE
 *   (CurriculumLookup::instituteForTeacher), il problema e' peggiorato: si
 *   poteva salvare del lavoro nuovo sotto una combinazione che non esiste.
 *
 *   E ogni selettore sceglieva da solo il primo valore: si poteva avere una
 *   classe senza aver scelto un indirizzo, e una materia senza una classe.
 *
 * COSA FA
 *   - cambia istituto  → ricarica indirizzi, classi e materie di quella scuola
 *   - cambia indirizzo → lascia solo le classi di quel corso
 *   - la catena: senza indirizzo il selettore della classe e' chiuso, senza
 *     classe e' chiuso quello della materia. Vale anche al primo disegno, non
 *     solo dopo un cambio.
 *   - il profilo del docente avvisa quando cambia le spunte
 *     (`fm:curriculum-changed`): i selettori si ricaricano da soli.
 *
 *   Le classi portano il loro indirizzo dalla migration 100 (e il server lo
 *   scrive in `data-indirizzo` sulle opzioni): e' quello che permette di
 *   filtrarle invece di mostrarle tutte insieme.
 *
 * COSA CONSERVA
 *   Se dopo il cambio la selezione precedente esiste ancora, resta. Se non
 *   esiste piu' e la voce e' una sola, prende quella (la regola «prima
 *   l'indirizzo» e' rispettata: un indirizzo scelto c'e'). Con piu' voci
 *   resta sul segnaposto: la scelta e' di chi usa la pagina, non del codice.
 *
 * CHI ALTRO SCRIVE NEI SELETTORI, E PERCHE' QUI SI DISTINGUE
 *   Al primo disegno i valori li rimettono altri, da codice e senza evento:
 *   `dom-manager.updateSelectsFromState` (dalla sessione) e `fm-url-state.js`
 *   (dall'URL, con un `change` non fidato). Quindi al primo disegno questo
 *   modulo NON azzera niente e NON scrive in sessione: chiude solo i selettori
 *   a valle di uno vuoto, e ricontrolla a `load`, quando gli altri hanno
 *   finito. Azzerare e ricordare si fa solo su un cambio dell'utente
 *   (`ev.isTrusted`): il 13/9/2026 la versione che ricordava al primo disegno
 *   cancellava dalla sessione la materia scelta prima di aprire un contenuto.
 */

// Le voci DEL DOCENTE nell'istituto indicato — le stesse che il server
// renderizza nella sidebar. Il catalogo completo della scuola (/curriculum)
// mostrerebbe anche indirizzi che il docente non ha attivato: corretto per la
// registrazione di uno studente, sbagliato qui, dove i selettori dicono
// "su cosa lavoro io".
const ENDPOINT = "/api/teacher/curriculum";

/** Il testo del segnaposto di ogni selettore: valore vuoto, non si sceglie. */
const SEGNAPOSTO = {
    "sel-iis": "Scegli l'indirizzo:",
    "sel-cls": "Scegli la classe:",
    "sel-mater": "Scegli la materia:",
};

/**
 * L'indirizzo da cui leggere il catalogo.
 *
 * Senza id si chiede lo stesso: il server sa qual e' l'istituto attivo in
 * sessione. Prima si usciva subito, e siccome l'id lo si leggeva dal selettore
 * degli istituti — che c'e' solo per chi ne ha piu' d'uno collegato — un
 * docente senza quel selettore restava senza catalogo, e il filtro per
 * indirizzo non partiva mai.
 *
 * Il visitatore senza login (barra pubblica) non ha un catalogo da docente:
 * legge quello pubblico, `/curriculum`, cioe' le voci del docente che pubblica
 * in rete. Fino al 15/9/2026 anche lui chiedeva /api/teacher/curriculum, che
 * gli risponde 401: il catalogo restava quello del primo disegno, vuoto, e
 * scegliere l'indirizzo cancellava le classi che public-sidebar-selectors.js
 * aveva appena aggiunto.
 *
 * @param {string|number|null|undefined} instituteId
 * @param {boolean} [ospite] visitatore senza login
 * @returns {string}
 */
function urlCatalogo(instituteId, ospite = false) {
    if (ospite) return "/curriculum";
    return instituteId
        ? `${ENDPOINT}?institute_id=${encodeURIComponent(instituteId)}`
        : ENDPOINT;
}

/** La barra e' quella pubblica, disegnata per un visitatore senza login. */
function eOspite() {
    return !!document.querySelector('nav.sidebar[data-fm-guest="1"]');
}

/** Legge le voci del docente. Null se non si puo': meglio non toccare niente
 *  che svuotare i selettori per un errore di rete. */
async function caricaCurriculum(instituteId) {
    try {
        const r = await fetch(urlCatalogo(instituteId, eOspite()), {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        });
        if (!r.ok) return null;
        const j = await r.json();
        const c = j && j.curriculum;
        if (!c) return null;
        return {
            indirizzi: Array.isArray(c.indirizzi) ? c.indirizzi : [],
            classi: Array.isArray(c.classi) ? c.classi : [],
            materie: Array.isArray(c.materie) ? c.materie : [],
        };
    } catch (_) {
        return null;
    }
}

/** Il valore scelto di un <select>: vuoto se non c'e' o se e' il segnaposto. */
function valore(sel) {
    if (!sel) return "";
    const o = sel.options[sel.selectedIndex];
    return o && !o.disabled ? String(sel.value || "") : "";
}

/**
 * Riempie un <select> conservando la scelta se esiste ancora.
 *
 * Con un segnaposto (testo non vuoto) la prima opzione e' il segnaposto —
 * valore vuoto, disabilitata, selezionata finche' non si sceglie — e con piu'
 * voci nessuna viene scelta al posto dell'utente. Senza segnaposto, il
 * comportamento storico: la prima voce.
 *
 * @param {HTMLSelectElement|null} sel
 * @param {Array<{code:string,label?:string,indirizzo?:string|null}>} voci
 * @param {string} valorePrecedente
 * @param {string} [segnaposto]
 * @returns {string} il valore selezionato dopo l'operazione ("" = nessuno)
 */
function riempi(sel, voci, valorePrecedente, segnaposto) {
    if (!sel) return "";
    const testo = segnaposto === undefined ? (SEGNAPOSTO[sel.id] || "") : String(segnaposto || "");
    // Le voci arrivano gia' deduplicate per codice dal server, ma un catalogo
    // potrebbe portare due righe con lo stesso codice: senza questo si
    // vedrebbe due volte la stessa materia.
    const viste = new Set();
    const uniche = [];
    for (const v of voci) {
        const code = String(v.code || "");
        if (!code || viste.has(code)) continue;
        viste.add(code);
        uniche.push({ code, label: String(v.label || code), indirizzo: v.indirizzo ? String(v.indirizzo) : "" });
    }
    uniche.sort((a, b) => a.label.localeCompare(b.label, "it"));

    sel.textContent = "";
    if (testo) {
        const p = document.createElement("option");
        p.value = "";
        p.disabled = true;
        p.selected = true;
        p.textContent = testo;
        sel.appendChild(p);
    }
    for (const v of uniche) {
        const o = document.createElement("option");
        o.value = v.code;
        o.textContent = v.label;
        if (v.indirizzo) o.dataset.indirizzo = v.indirizzo;
        sel.appendChild(o);
    }
    if (valorePrecedente && viste.has(valorePrecedente)) {
        sel.value = valorePrecedente;
    } else if (uniche.length === 1 || (!testo && uniche.length > 0)) {
        sel.value = uniche[0].code;
    }
    return valore(sel);
}

/** Le classi di un indirizzo. Quelle senza indirizzo valgono per tutti:
 *  sono righe create prima della migration 100, e nasconderle toglierebbe
 *  accesso a contenuti che ci sono. */
function classiDi(classi, indirizzo) {
    if (!indirizzo) return classi;
    return classi.filter((c) => !c.indirizzo || String(c.indirizzo) === indirizzo);
}

/** Le classi come le ha disegnate il server: codice, etichetta e corso
 *  (`data-indirizzo`), segnaposto escluso. Bastano per filtrare al primo
 *  disegno, prima che arrivi il catalogo dalla rete. */
function classiNelDom(selCls) {
    if (!selCls) return [];
    return Array.from(selCls.options)
        .filter((o) => o.value !== "")
        .map((o) => ({
            code: o.value,
            label: o.textContent.trim(),
            indirizzo: o.dataset.indirizzo || null,
        }));
}

/** Rimette il selettore sul segnaposto (o su niente, se non ce l'ha). */
function azzera(sel) {
    if (!sel) return;
    const p = Array.from(sel.options).find((o) => o.value === "");
    if (p) {
        p.selected = true;
    } else {
        sel.selectedIndex = -1;
    }
}

/**
 * La catena: senza indirizzo la classe non si sceglie, senza classe la
 * materia non si sceglie. Chiude i selettori a valle di uno vuoto; con
 * `azzera` ne azzera anche la scelta, cosi' non resta una classe di un
 * indirizzo non scelto (e' quello che si vuole su un cambio dell'utente, non
 * al primo disegno, quando i valori arrivano da altri in un ordine qualunque).
 *
 * @param {HTMLSelectElement|null} selInd
 * @param {HTMLSelectElement|null} selCls
 * @param {HTMLSelectElement|null} selMat
 * @param {{azzera?: boolean}} [opzioni]
 * @returns {{indirizzo:string, classe:string, materia:string, azzerati:string[]}}
 */
function applicaCatena(selInd, selCls, selMat, opzioni = {}) {
    const conAzzeramento = opzioni.azzera !== false;
    const azzerati = [];
    const indirizzo = valore(selInd);
    if (selCls) {
        if (!indirizzo && conAzzeramento && valore(selCls)) { azzera(selCls); azzerati.push("classe"); }
        selCls.disabled = !indirizzo;
    }
    const classe = valore(selCls);
    if (selMat) {
        if (!classe && conAzzeramento && valore(selMat)) { azzera(selMat); azzerati.push("materia"); }
        // Chiusa anche quando manca solo l'indirizzo: la catena e' rotta a
        // monte, e una classe tenuta (senza azzerare) non basta ad aprirla.
        selMat.disabled = !indirizzo || !classe;
    }
    return { indirizzo, classe, materia: valore(selMat), azzerati };
}

function stato() {
    return (window.FM && window.FM.AppState) || window.AppState || null;
}

function ricorda(chiave, valore) {
    const s = stato();
    if (s) s[chiave] = valore;
    try {
        if (valore) sessionStorage.setItem(chiave, valore);
        else sessionStorage.removeItem(chiave);
    } catch (_) {
        /* sessionStorage non disponibile: la selezione vale per questa pagina */
    }
}

function selettori() {
    return {
        selInd: document.getElementById("sel-iis"),
        selCls: document.getElementById("sel-cls"),
        selMat: document.getElementById("sel-mater"),
    };
}

/**
 * Applica la catena; su un cambio dell'utente azzera cio' che non regge piu'
 * e ricorda le scelte in sessione. Al primo disegno e sui cambi da codice
 * (URL, sessione) non tocca ne' i valori ne' la sessione.
 */
function sincronizza(daUtente) {
    const { selInd, selCls, selMat } = selettori();
    const c = applicaCatena(selInd, selCls, selMat, { azzera: daUtente });
    if (daUtente) {
        ricorda("selectedIIS", c.indirizzo);
        ricorda("selectedCLS", c.classe);
        ricorda("selectedMATER", c.materia);
    }
    return c;
}

function avvisa(sel) {
    if (sel) sel.dispatchEvent(new Event("change", { bubbles: true }));
}

let catalogo = null;

/** Le classi note: dal catalogo del server se ne ha, altrimenti dal DOM.
 *  Nella barra pubblica il catalogo del primo disegno e' vuoto, e le classi
 *  arrivano nel DOM dopo (public-sidebar-selectors.js): finche' il catalogo
 *  dalla rete non c'e', valgono quelle. */
function classiNote(selCls) {
    return catalogo && catalogo.classi.length > 0 ? catalogo.classi : classiNelDom(selCls);
}

async function suCambioIstituto(instituteId) {
    const dati = await caricaCurriculum(instituteId);
    if (!dati) return;
    catalogo = dati;

    const { selInd, selCls, selMat } = selettori();
    const ind = riempi(selInd, dati.indirizzi, valore(selInd));
    riempi(selCls, classiDi(dati.classi, ind), valore(selCls));
    riempi(selMat, dati.materie, valore(selMat));
    // Un istituto o un catalogo diverso: cio' che non esiste piu' e' gia'
    // caduto da riempi, e la catena si tira dietro il resto; le scelte nuove
    // vanno ricordate, altrimenti al prossimo caricamento tornano le vecchie.
    sincronizza(true);

    // I moduli che disegnano i contenuti ascoltano il change dei selettori:
    // dopo averli riempiti da codice va detto, o restano su cio' che mostravano.
    for (const s of [selInd, selCls, selMat]) avvisa(s);
}

function suCambioIndirizzo(daUtente) {
    const { selInd, selCls, selMat } = selettori();
    if (!selInd || !selCls) return;
    const prima = valore(selMat);
    riempi(selCls, classiDi(classiNote(selCls), valore(selInd)), valore(selCls));
    const c = sincronizza(daUtente);
    if (!daUtente) return;
    avvisa(selCls);
    if (prima !== c.materia) avvisa(selMat);
}

function suCambioClasse(daUtente) {
    const { selMat } = selettori();
    const prima = valore(selMat);
    const c = sincronizza(daUtente);
    if (daUtente && prima !== c.materia) avvisa(selMat);
}

function idIstitutoCorrente() {
    const selIst = document.getElementById("sel-istituto");
    return selIst && selIst.options[selIst.selectedIndex]
        ? selIst.options[selIst.selectedIndex].dataset.iid
        : null;
}

/** Il primo disegno: le classi del corso scelto (senza rete) e la catena,
 *  senza azzerare ne' ricordare. Si rifa' a `load`, quando anche chi rimette
 *  i valori dalla sessione e dall'URL ha finito. */
function primoDisegno() {
    const { selInd, selCls } = selettori();
    if (selCls && !selCls.hidden && selInd) {
        riempi(selCls, classiDi(classiNote(selCls), valore(selInd)), valore(selCls));
    }
    sincronizza(false);
}

function collega() {
    // Il badge dello studente lascia i <select> nel DOM ma nascosti e con
    // un'unica opzione: la sua classe non si sceglie, e ricaricarla di la'
    // sarebbe sbagliato oltre che inutile.
    const { selInd, selCls, selMat } = selettori();
    if (!selInd || selInd.hidden) {
        console.debug("[FM] sidebar-cascade: non agganciato (nessun selettore indirizzo)");
        return;
    }
    console.debug("[FM] sidebar-cascade: agganciato");

    if (selCls && !selCls.hidden) {
        catalogo = { indirizzi: [], classi: classiNelDom(selCls), materie: [] };
    }
    primoDisegno();
    window.addEventListener("load", primoDisegno, { once: true });

    // L'evento porta gia' l'id numerico: e' quello che serve all'endpoint,
    // e arriva DOPO che /api/tenant/switch ha cambiato l'istituto in sessione.
    document.addEventListener("fm:active-institute-changed", (ev) => {
        const iid = ev && ev.detail && ev.detail.iid;
        if (iid) suCambioIstituto(iid);
    });
    // Il profilo ha cambiato le spunte: i selettori dicono «su cosa lavoro io»
    // e devono seguirle subito, senza ricaricare la pagina.
    document.addEventListener("fm:curriculum-changed", (ev) => {
        const iid = (ev && ev.detail && ev.detail.iid) || idIstitutoCorrente();
        suCambioIstituto(iid);
    });
    // `isTrusted` distingue la mano dell'utente dal codice: fm-url-state.js
    // manda un change non fidato quando rimette i valori dall'URL, e li
    // rimette uno alla volta — azzerare la materia perche' la classe «non
    // c'e' ancora» butterebbe via una scelta buona.
    selInd.addEventListener("change", (ev) => suCambioIndirizzo(!!(ev && ev.isTrusted)));
    if (selCls) selCls.addEventListener("change", (ev) => suCambioClasse(!!(ev && ev.isTrusted)));
    if (selMat) {
        selMat.addEventListener("change", (ev) => {
            if (ev && ev.isTrusted) ricorda("selectedMATER", valore(selMat));
        });
    }

    // Catalogo della scuola corrente, per poter filtrare le classi al primo
    // cambio di indirizzo con i dati del server e non solo con quelli del DOM.
    // Anche senza id: il selettore degli istituti c'e' solo per chi ne ha piu'
    // d'uno collegato, e il filtro per indirizzo deve funzionare per tutti.
    caricaCurriculum(idIstitutoCorrente()).then((d) => {
        if (d) catalogo = d;
    });
}

// La guardia serve ai test: le funzioni pure vanno provate senza un DOM, e
// senza questa il solo import proverebbe ad agganciare gli eventi e fallirebbe.
if (typeof document !== "undefined") {
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", collega, { once: true });
    } else {
        collega();
    }
}

export { applicaCatena, classiDi, classiNelDom, riempi, urlCatalogo, valore };
