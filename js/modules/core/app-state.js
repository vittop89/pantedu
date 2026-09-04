/**
 * AppState — estratto da script.js:106 (Phase 9b).
 *
 * Stato di sessione navigazione: indirizzo (iis), classe (cls),
 * materia selezionati + linkref corrente + visitedLinks. Persistente
 * via sessionStorage.
 *
 * Il legacy script.js mantiene la definizione attiva
 * (window.AppState). Questo modulo è bridge ES6 per nuovo codice
 * che voglia importarlo invece di leggere da global.
 *
 * G26.phase4 — Migrato a vanilla JS (no più jQuery).
 */

export const AppState = {
    selectedIIS:   null,
    selectedCLS:   null,
    selectedMATER: null,
    activeInstituteCode: null, // G20.0 — codice MIUR istituto attivo (sessione)
    optsel: "",
    folder: "",
    mater: "",
    sidebarCheck: 1,
    moreArg: 0,
    visitedLinks: [],
    linkref: "",
    isEditMode: false,
    Old: { Argomento_array: [], NumArg_array: [], IdLinkref_array: [] },

    init() {
        // G19.49d — guard: alcune sessioni hanno scritto la stringa
        // "null" / "undefined" via `setItem(key, val)` con val=null. Le
        // trattiamo come assenza di valore.
        const ss = (k) => {
            const v = sessionStorage.getItem(k);
            return (v === null || v === "null" || v === "undefined") ? null : v;
        };
        this.selectedIIS   = ss("selectedIIS");
        this.selectedCLS   = ss("selectedCLS");
        this.selectedMATER = ss("selectedMATER");
        // G20.0 — istituto attivo: prima da sessionStorage, poi auto-pick
        // dal select sidebar (#sel-istituto rendered server-side se >0).
        this.activeInstituteCode = ss("activeInstituteCode");
        this.linkref = sessionStorage.getItem("linkref")       || "";
        this.optsel  = sessionStorage.getItem("selectedMAP")   || "";
        this.folder  = sessionStorage.getItem("selectedFold")  || "";
        this.mater   = sessionStorage.getItem("selectedMater") || "";

        // Default DINAMICI: i codici indirizzo/classe/materia sono per-istituto
        // (no hardcoded "SCI"/"2"/"MAT" legacy). Quando manca una selezione,
        // si eredita il primo valore realmente disponibile dai select dinamici
        // renderizzati server-side dal catalogo curriculum; se i select non ci
        // sono, si lascia vuoto (l'UI chiede di selezionare).
        const firstOpt = (id) => {
            const sel = document.getElementById(id);
            if (!sel || !sel.options || sel.options.length === 0) return "";
            return sel.value || sel.options[0].value || "";
        };
        if (!this.selectedIIS)   this.selectedIIS   = firstOpt("sel-iis");
        if (!this.selectedCLS)   this.selectedCLS   = firstOpt("sel-cls");
        if (!this.selectedMATER) this.selectedMATER = firstOpt("sel-mater");
        if (this.selectedIIS)   sessionStorage.setItem("selectedIIS",   this.selectedIIS);
        if (this.selectedCLS)   sessionStorage.setItem("selectedCLS",   this.selectedCLS);
        if (this.selectedMATER) sessionStorage.setItem("selectedMATER", this.selectedMATER);

        // G20.0 — auto-pick from #sel-istituto if not yet in sessionStorage.
        // Opzione A (2026-05-16): se sessionStorage HA un valore ma il dropdown
        // è renderizzato server-side con valore diverso, forziamo il dropdown
        // a riflettere sessionStorage (single source of truth client-side).
        // Se l'opzione non esiste piu' (istituto rimosso), riallineiamo
        // sessionStorage al primo valore disponibile del dropdown.
        const sel = document.getElementById("sel-istituto");
        if (sel) {
            if (this.activeInstituteCode) {
                const opt = sel.querySelector(`option[value="${CSS.escape(this.activeInstituteCode)}"]`);
                if (opt) {
                    if (sel.value !== this.activeInstituteCode) sel.value = this.activeInstituteCode;
                } else if (sel.value) {
                    this.activeInstituteCode = sel.value;
                    sessionStorage.setItem("activeInstituteCode", this.activeInstituteCode);
                }
            } else if (sel.value) {
                this.activeInstituteCode = sel.value;
                sessionStorage.setItem("activeInstituteCode", this.activeInstituteCode);
            }
        }
    },

    updateFromSelects() {
        this.optsel = "";
        this.mater  = "";
        // G26 — iterazione vanilla su tutti i `<option>` selezionati.
        // Pattern: option:checked è CSS3 pseudo-class supportato modern
        // browsers per <select><option> (equivale al jQuery :selected).
        const selectedOptions = document.querySelectorAll("select option:checked");
        selectedOptions.forEach((el, i) => {
            this.optsel += el.value || "";
            if (i === 2) this.mater = (el.value || "").substring(0, 1);
        });
        this.optsel = this.optsel.substring(0, 4);
        this.folder = this.optsel.substring(0, 2) + (this.optsel.includes("b") ? "_b" : "");

        // G19.49d — non scrivere se select vuoto.
        const setOrRemove = (key, v) => {
            if (v && v !== "null" && v !== "undefined") sessionStorage.setItem(key, v);
            else sessionStorage.removeItem(key);
        };
        const getSelectVal = (id) => document.getElementById(id)?.value || "";
        setOrRemove("selectedIIS",   getSelectVal("sel-iis"));
        setOrRemove("selectedCLS",   getSelectVal("sel-cls"));
        setOrRemove("selectedMATER", getSelectVal("sel-mater"));
        sessionStorage.setItem("selectedMAP",   this.optsel);
        sessionStorage.setItem("selectedFold",  this.folder);
        sessionStorage.setItem("selectedMater", this.mater);
    },

    addVisitedLink(link) {
        if (!this.visitedLinks.includes(link)) this.visitedLinks.push(link);
    },

    resetVisitedLinks(initialLink) {
        this.visitedLinks = [initialLink];
    },

    saveOriginalState(linkElements) {
        // G26 — vanilla: linkElements è già un array DOM (NodeList o Array).
        this.Old = {
            IdLinkref_array: [],
            Argomento_array: [],
            NumArg_array:    [],
            Href_array:      [],
        };
        linkElements.forEach((el) => {
            this.Old.IdLinkref_array.push(el.parentElement?.id || "");
            this.Old.Argomento_array.push(el.querySelector(".argomento")?.textContent?.trim() || "");
            this.Old.NumArg_array.push(el.querySelector(".numArg")?.textContent?.trim() || "");
            this.Old.Href_array.push(el.getAttribute("href") || "");
        });
    },

    clearOriginalState() {
        this.Old = { IdLinkref_array: [], Argomento_array: [], NumArg_array: [], Href_array: [] };
    },
};

// G20.0 — wire #sel-istituto change → update AppState + sessionStorage +
// dispatch event globale per re-fetch sidepage/content scope-aware.
// Phase 25.Q — propaga il switch anche al server via /api/tenant/switch
// (best-effort: setta current_institute_id in sessione PHP + cookie).
function wireIstitutoSelector() {
    const sel = document.getElementById("sel-istituto");
    if (!sel) return;
    sel.addEventListener("change", async () => {
        const code = sel.value || "";
        AppState.activeInstituteCode = code;
        if (code) sessionStorage.setItem("activeInstituteCode", code);
        else      sessionStorage.removeItem("activeInstituteCode");

        // Server-side switch: invia institute_id (numerico, da data-iid).
        const opt = sel.options[sel.selectedIndex];
        const iid = opt?.dataset?.iid;
        const csrf = sel.dataset?.csrf || "";
        if (iid && csrf) {
            try {
                await fetch("/api/tenant/switch", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded", "Accept": "application/json" },
                    body: new URLSearchParams({ institute_id: iid, _csrf: csrf }).toString(),
                    credentials: "same-origin",
                });
            } catch (_) { /* best-effort: client-side già aggiornato */ }
        }

        // Notifica i moduli interessati (sidepage, sync-buttons, ecc.)
        document.dispatchEvent(new CustomEvent("fm:active-institute-changed", {
            detail: { code, iid: iid ? Number(iid) : null },
        }));
    });
}
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", wireIstitutoSelector, { once: true });
} else {
    wireIstitutoSelector();
}

window.FM = window.FM || {};
window.FM.AppState = AppState;
window.AppState    = AppState;
