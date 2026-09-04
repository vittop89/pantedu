/**
 * I selettori della sidebar seguono l'istituto e l'indirizzo scelti.
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
 * COSA FA
 *   - cambia istituto  → ricarica indirizzi, classi e materie di quella scuola
 *   - cambia indirizzo → lascia solo le classi di quel corso
 *
 *   Le classi portano il loro indirizzo dalla migration 100: e' quello che
 *   permette di filtrarle invece di mostrarle tutte insieme.
 *
 * COSA CONSERVA
 *   Se dopo il cambio la selezione precedente esiste ancora, resta. Perde solo
 *   cio' che nella scuola nuova non c'e' — e in quel caso prende il primo
 *   valore disponibile invece di lasciare il selettore vuoto, che e' il modo
 *   in cui una pagina sembra rotta.
 */

// Le voci DEL DOCENTE nell'istituto indicato — le stesse che il server
// renderizza nella sidebar. Il catalogo completo della scuola (/curriculum)
// mostrerebbe anche indirizzi che il docente non ha attivato: corretto per la
// registrazione di uno studente, sbagliato qui, dove i selettori dicono
// "su cosa lavoro io".
const ENDPOINT = "/api/teacher/curriculum";

/**
 * L'indirizzo da cui leggere il catalogo.
 *
 * Senza id si chiede lo stesso: il server sa qual e' l'istituto attivo in
 * sessione. Prima si usciva subito, e siccome l'id lo si leggeva dal selettore
 * degli istituti — che c'e' solo per chi ne ha piu' d'uno collegato — un
 * docente senza quel selettore restava senza catalogo, e il filtro per
 * indirizzo non partiva mai.
 *
 * @param {string|number|null|undefined} instituteId
 * @returns {string}
 */
function urlCatalogo(instituteId) {
    return instituteId
        ? `${ENDPOINT}?institute_id=${encodeURIComponent(instituteId)}`
        : ENDPOINT;
}

/** Legge le voci del docente. Null se non si puo': meglio non toccare niente
 *  che svuotare i selettori per un errore di rete. */
async function caricaCurriculum(instituteId) {
    try {
        const r = await fetch(urlCatalogo(instituteId), {
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

/**
 * Riempie un <select> conservando la scelta se esiste ancora.
 * @returns {string} il valore selezionato dopo l'operazione
 */
function riempi(sel, voci, valorePrecedente) {
    if (!sel) return "";
    // Le voci arrivano gia' deduplicate per codice dal server, ma il catalogo
    // ha righe di istituto e righe per-docente con lo stesso codice: senza
    // questo si vedrebbe due volte la stessa materia.
    const viste = new Set();
    const uniche = [];
    for (const v of voci) {
        const code = String(v.code || "");
        if (!code || viste.has(code)) continue;
        viste.add(code);
        uniche.push({ code, label: String(v.label || code) });
    }
    uniche.sort((a, b) => a.label.localeCompare(b.label, "it"));

    sel.textContent = "";
    for (const v of uniche) {
        const o = document.createElement("option");
        o.value = v.code;
        o.textContent = v.label;
        sel.appendChild(o);
    }
    if (valorePrecedente && viste.has(valorePrecedente)) {
        sel.value = valorePrecedente;
    } else if (uniche.length > 0) {
        sel.value = uniche[0].code;
    }
    return sel.value || "";
}

/** Le classi di un indirizzo. Quelle senza indirizzo valgono per tutti:
 *  sono righe create prima della migration 100, e nasconderle toglierebbe
 *  accesso a contenuti che ci sono. */
function classiDi(classi, indirizzo) {
    if (!indirizzo) return classi;
    return classi.filter((c) => !c.indirizzo || String(c.indirizzo) === indirizzo);
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

let catalogo = null;

async function suCambioIstituto(instituteId) {
    const dati = await caricaCurriculum(instituteId);
    if (!dati) return;
    catalogo = dati;

    const selInd = document.getElementById("sel-iis");
    const selCls = document.getElementById("sel-cls");
    const selMat = document.getElementById("sel-mater");

    const ind = riempi(selInd, dati.indirizzi, selInd ? selInd.value : "");
    ricorda("selectedIIS", ind);

    const cls = riempi(selCls, classiDi(dati.classi, ind), selCls ? selCls.value : "");
    ricorda("selectedCLS", cls);

    const mat = riempi(selMat, dati.materie, selMat ? selMat.value : "");
    ricorda("selectedMATER", mat);

    // I moduli che disegnano i contenuti ascoltano il change dei selettori:
    // dopo averli riempiti da codice va detto, o restano su cio' che mostravano.
    for (const s of [selInd, selCls, selMat]) {
        if (s) s.dispatchEvent(new Event("change", { bubbles: true }));
    }
}

function suCambioIndirizzo() {
    if (!catalogo) return;
    const selInd = document.getElementById("sel-iis");
    const selCls = document.getElementById("sel-cls");
    if (!selInd || !selCls) return;

    const cls = riempi(selCls, classiDi(catalogo.classi, selInd.value), selCls.value);
    ricorda("selectedCLS", cls);
    selCls.dispatchEvent(new Event("change", { bubbles: true }));
}

function collega() {
    // Il badge dello studente lascia i <select> nel DOM ma nascosti e con
    // un'unica opzione: la sua classe non si sceglie, e ricaricarla di la'
    // sarebbe sbagliato oltre che inutile.
    const selInd = document.getElementById("sel-iis");
    if (!selInd || selInd.hidden) {
        console.debug("[FM] sidebar-cascade: non agganciato (nessun selettore indirizzo)");
        return;
    }
    console.debug("[FM] sidebar-cascade: agganciato");

    // L'evento porta gia' l'id numerico: e' quello che serve all'endpoint,
    // e arriva DOPO che /api/tenant/switch ha cambiato l'istituto in sessione.
    document.addEventListener("fm:active-institute-changed", (ev) => {
        const iid = ev && ev.detail && ev.detail.iid;
        if (iid) suCambioIstituto(iid);
    });
    selInd.addEventListener("change", suCambioIndirizzo);

    // Catalogo della scuola corrente, per poter filtrare le classi al primo
    // cambio di indirizzo senza aspettare un cambio di istituto.
    const selIst = document.getElementById("sel-istituto");
    const iidCorrente = selIst && selIst.options[selIst.selectedIndex]
        ? selIst.options[selIst.selectedIndex].dataset.iid
        : null;
    // Anche senza id: il selettore degli istituti c'e' solo per chi ne ha piu'
    // d'uno collegato, e il filtro per indirizzo deve funzionare per tutti.
    caricaCurriculum(iidCorrente).then((d) => {
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

export { classiDi, riempi, urlCatalogo };
