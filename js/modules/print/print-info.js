/**
 * PrintInfoManager — estratto da functions-mod.js (Phase 9d).
 * G26.phase4.7 — migrato a vanilla JS (no jQuery).
 *
 * G27.printinfo — load usa l'endpoint moderno `/api/teacher/print-info`
 * (response shape `{ok, data, found}`) + carica TUTTI i campi salvati
 * (istituto, versione, nome, cognome, flag BES) coerentemente col save
 * via verifica-scelte.js. Auto-init + auto-load al boot.
 */
import { Endpoints } from "../core/endpoints.js";
import { labelFor } from "../core/curriculum-codes.js";

/** Sync getter/setter input value via #id (helper). */
const getVal = (id) => document.getElementById(id)?.value || "";
const setVal = (id, v) => {
    const el = document.getElementById(id);
    if (el) el.value = v;
};
/** Setter per checkbox (campi BES: compensa, dsa, griglie, misure). */
const setCb = (id, v) => {
    const el = document.getElementById(id);
    if (el && el.type === "checkbox") el.checked = (v === "1" || v === 1 || v === true);
};

/*
 * 2026-09-07 — le letture di questo modulo si annullano quando la pagina se
 * ne va.
 *
 * Le informazioni di stampa si caricano da sole all'apertura della pagina e
 * nessuno le aspetta. Se il docente cambia pagina mentre sono per strada, il
 * browser tronca la richiesta e `fetch` rifiuta con «TypeError: Failed to
 * fetch». Quello non è un guasto — è la pagina che sta chiudendo — ma
 * finiva in console come errore, scritto uguale a quello di un server che non
 * risponde: chi legge la console non poteva distinguerli, e la suite
 * end-to-end lo segnalava come difetto ogni volta che una prova cambiava
 * pagina in fretta.
 *
 * Adesso ogni lettura nasce legata a un `AbortController`. Alla prima
 * avvisaglia di uscita (`pagehide`, e `beforeunload` che arriva prima che il
 * browser tronchi le richieste) si annullano tutte, e chi le aspettava tace.
 * L'unico errore che resta in console è quello vero.
 */
const _letture = new Set();
let _inUscita = false;

const _annullaLetture = () => {
    _inUscita = true;
    _letture.forEach((c) => c.abort());
    _letture.clear();
};

if (typeof window !== "undefined") {
    window.addEventListener("pagehide", _annullaLetture);
    // `beforeunload` può essere annullato (l'editor avvisa se ci sono bozze
    // non salvate): il timer rimette a posto la bandiera se la pagina resta.
    // I rifiuti delle fetch sono microtask e arrivano prima del timer, quindi
    // la finestra di silenzio copre esattamente l'uscita e nulla di più.
    window.addEventListener("beforeunload", () => {
        _annullaLetture();
        setTimeout(() => { _inUscita = false; }, 0);
    });
    // Tornando indietro la pagina rivive dalla cache del browser: le letture
    // devono poter ripartire.
    window.addEventListener("pageshow", (e) => { if (e.persisted) _inUscita = false; });
}

/**
 * `fetch` legato all'uscita della pagina.
 * @param {string} url
 * @param {RequestInit} [opzioni]
 * @returns {Promise<Response>}
 */
const leggi = (url, opzioni = {}) => {
    const controllo = new AbortController();
    _letture.add(controllo);
    return fetch(url, { credentials: "same-origin", signal: controllo.signal, ...opzioni })
        .finally(() => _letture.delete(controllo));
};

/**
 * Vero quando l'errore è solo la pagina che chiude: non c'è niente da dire,
 * perché nessuno vedrà mai il risultato di quella lettura.
 * @param {unknown} errore
 */
const soloUscita = (errore) => _inUscita
    || (errore instanceof Error && errore.name === "AbortError");

export const PrintInfoManager = {
    _lastLoadKey: null,
    _lastLoadTs: 0,
    /**
     * Salva le informazioni di stampa correnti sul server
     */
    savePrintInfo () {
        const indirizzo = sessionStorage.getItem("selectedIIS") || "";
        const classe = sessionStorage.getItem("selectedCLS") || "";
        const materia = sessionStorage.getItem("selectedMATER") || "";

        if (!indirizzo || !classe) {
            alert("❌ Seleziona prima indirizzo e classe dalla sidebar");
            return;
        }

        if (!materia || materia === "null" || materia === "undefined" || materia === "ALL") {
            alert("❌ Seleziona una materia valida dalla sidebar");
            return;
        }

        const body = new URLSearchParams({
            indirizzo,
            classe,
            materia,
            nPrint:        getVal("nPrint"),
            nPrintDSA:     getVal("nPrintDSA"),
            nPrintDIS:     getVal("nPrintDIS"),
            addressSchool: getVal("addressSchool"),
            sezione:       getVal("sezione"),
            anno:          getVal("anno"),
            verTime:       getVal("verTime"),
        });

        fetch(Endpoints.verifiche.managePrintInfo, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
            body: body.toString(),
            credentials: "same-origin",
        })
            .then((res) => res.json())
            .then((response) => {
                if (response.success) {
                    alert(`✅ ${response.message}`);
                    console.log("💾 Info stampa salvate:", response.key);
                } else {
                    alert(`❌ ${response.message}`);
                }
            })
            .catch((error) => {
                console.error("Errore salvataggio info stampa:", error);
                alert("❌ Errore nel salvataggio");
            });
    },

    /**
     * Carica le informazioni di stampa dal server per la combinazione corrente
     */
    loadPrintInfo () {
        const indirizzo = sessionStorage.getItem("selectedIIS") || "";
        const classe = sessionStorage.getItem("selectedCLS") || "";
        const materia = sessionStorage.getItem("selectedMATER") || "";

        if (!indirizzo || !classe || !materia) {
            console.log("⚠️ Impossibile caricare info stampa: parametri mancanti");
            return;
        }

        const reqKey = `${indirizzo}_${classe}_${materia}`;
        const now = Date.now();
        if (PrintInfoManager._lastLoadKey === reqKey && now - PrintInfoManager._lastLoadTs < 800) {
            return;
        }
        PrintInfoManager._lastLoadKey = reqKey;
        PrintInfoManager._lastLoadTs = now;

        // Formatta sempre classe e indirizzo dalla sidebar
        setVal("classe", PrintInfoManager._formatClasseDisplay(classe));

        // Label indirizzo DINAMICO dal catalogo curriculum (no mappa hardcoded:
        // i codici/label sono per-istituto). Fallback graceful al codice stesso.
        // Il fetch /api/teacher/print-info qui sotto sovrascrive con il valore
        // salvato (addressSchool) quando esiste un record per la combo.
        setVal("addressSchool", labelFor("indirizzi", indirizzo) || indirizzo);

        // G27.printinfo — endpoint moderno (response shape {ok,data,found}).
        // Carica TUTTI i campi salvati da verifica-scelte.js: identificatori,
        // anagrafica classe (istituto, sezione, anno, verTime), copie
        // (nPrint/DSA/DIS), studente (nome, cognome), versione, flag BES.
        const qs = new URLSearchParams({ indirizzo, classe, materia });
        leggi(`/api/teacher/print-info?${qs}`, { method: "GET" })
            .then((res) => res.json())
            .then((response) => {
                const textFields = [
                    "nPrint", "nPrintDSA", "nPrintDIS",
                    "sezione", "anno", "verTime",
                    "istituto", "versione", "nome", "cognome",
                    "addressSchool",
                ];
                const cbFields = ["Compensa", "DSA", "griglie", "misure"];
                // Server payload key per le checkbox: lowercase compensa/dsa
                const cbKeyMap = { Compensa: "compensa", DSA: "dsa", griglie: "griglie", misure: "misure" };

                if (response.ok && response.data) {
                    const data = response.data;
                    textFields.forEach((id) => {
                        const v = data[id];
                        setVal(id, (v !== undefined && v !== null && v !== "") ? v : "");
                    });
                    cbFields.forEach((id) => setCb(id, data[cbKeyMap[id]]));
                    console.log("✅ Info stampa caricate:", data);
                } else {
                    // G27.printinfo.fallback — nessun match combo-specifico:
                    // popola dal record piu' recente disponibile (lista
                    // visibile in `.fm-modal.fm-load-printinfo`). Cosi' non
                    // resta tutto vuoto se l'utente apre un'altra
                    // (indirizzo/classe/materia) ma vuole vedere comunque
                    // l'ultima configurazione salvata.
                    PrintInfoManager._loadLatestFromList();
                    console.log("ℹ️ Nessun match combo: tentativo fallback latest-list");
                }
            })
            .catch((error) => {
                if (soloUscita(error)) return;
                console.error("Errore caricamento info stampa:", error);
            });
    },

    /**
     * Fallback: carica il record piu' recente dalla lista
     * `/api/teacher/print-info/list` (ordinato per `timestamp` desc) e
     * popola i campi infover. Usato quando nessun match combo-specifico.
     */
    _loadLatestFromList () {
        leggi("/api/teacher/print-info/list")
            .then((res) => res.json())
            .then((response) => {
                const items = response?.items || [];
                if (!items.length) return;
                // Sort by timestamp desc; record senza timestamp finiscono
                // in fondo (compat con record vecchi pre-G19.normalize).
                const sorted = [...items].sort((a, b) => {
                    const ta = a?.timestamp ? Date.parse(a.timestamp) : 0;
                    const tb = b?.timestamp ? Date.parse(b.timestamp) : 0;
                    return tb - ta;
                });
                const latest = sorted[0];
                if (!latest) return;
                const textFields = [
                    "nPrint", "nPrintDSA", "nPrintDIS",
                    "sezione", "anno", "verTime",
                    "istituto", "versione", "nome", "cognome",
                    "addressSchool",
                ];
                const cbFields = ["Compensa", "DSA", "griglie", "misure"];
                const cbKeyMap = { Compensa: "compensa", DSA: "dsa", griglie: "griglie", misure: "misure" };
                textFields.forEach((id) => {
                    const v = latest[id];
                    setVal(id, (v !== undefined && v !== null && v !== "") ? v : "");
                });
                cbFields.forEach((id) => setCb(id, latest[cbKeyMap[id]]));
                console.log("✅ Info stampa caricate (latest from list):", latest);
            })
            .catch((error) => {
                if (soloUscita(error)) return;
                console.error("Errore fallback latest-list:", error);
            });
    },

    /**
     * Display della classe. I codici classe sono DINAMICI (es. "1","2","1B"):
     * si mostra il codice intero. Back-compat: solo la forma legacy "Ns"/"Nb"
     * (cifra + suffisso MINUSCOLO s/b, es. "3s") viene ridotta alla cifra ("3").
     * Le sezioni dinamiche maiuscole ("1B") restano intatte.
     */
    _formatClasseDisplay (classe) {
        if (!classe) return classe;
        const c = String(classe).trim();
        return /^\d[a-z]$/.test(c) ? c.charAt(0) : c;
    },

    /**
     * Inizializza gli event handler per il salvataggio e caricamento.
     * G27.printinfo — auto-load delle info salvate al boot della pagina e
     * dopo navigazione SPA, se i 3 parametri sidebar (selectedIIS/CLS/MATER)
     * sono già presenti in sessionStorage. Idempotente: il throttle interno
     * di loadPrintInfo (800ms) blocca chiamate duplicate.
     *
     * NB: il click handler per #savePrintInfoBtn NON è registrato qui per
     * evitare doppia call con verifica-scelte.js (che chiama l'endpoint
     * moderno /api/teacher/print-info via fetch CSRF-aware). Il click flow
     * primario passa per verifica-scelte.
     */
    init () {
        const tryAutoLoad = () => {
            // Aspetta che gli input #anno/#nPrint etc. siano nel DOM
            // (renderizzati post-load via _CaricaSel_EserOr/legacy).
            if (!document.getElementById("nPrint") && !document.getElementById("anno")) {
                return;
            }
            const ind = sessionStorage.getItem("selectedIIS");
            const cls = sessionStorage.getItem("selectedCLS");
            const mat = sessionStorage.getItem("selectedMATER");
            if (ind && cls && mat && mat !== "ALL") {
                // Combo selezionata: prova load combo-specifico (con fallback
                // latest interno se nessun match server-side).
                PrintInfoManager.loadPrintInfo();
            } else {
                // G27.printinfo.fallback — combo non selezionata (utente
                // appena entrato nella pagina): popola direttamente con
                // l'ultimo record salvato nella lista.
                PrintInfoManager._loadLatestFromList();
            }
        };
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", tryAutoLoad, { once: true });
        } else {
            tryAutoLoad();
        }
        window.addEventListener("fm:navigated", tryAutoLoad);
        // Re-tenta dopo che il pannello info viene aperto (i campi vengono
        // mounted lazily dal flow legacy).
        window.addEventListener("fm:verifica-ui-loaded", tryAutoLoad);
    },
};

// G27.printinfo — auto-init: il modulo si auto-registra al primo import,
// niente piu' chiamate manuali necessarie da bootstrap.js.
PrintInfoManager.init();

window.FM = window.FM || {};
window.FM.PrintInfoManager = PrintInfoManager;
window.PrintInfoManager    = PrintInfoManager;
