import { escHtml, fetchJson, fetchCsrf } from "../core/dom-utils.js";

/**
 * Phase G19.2 — Verifica Scelte / Print Info — modern wiring.
 *
 * Replica della logica legacy `script_sel-mod.js`:
 *   1. `#savePrintInfoBtn` (💾) → POST `/verifiche/print-info` con i campi
 *      ANNO/SEZ/CL/INDIRIZZO/ISTITUTO/VERSIONE/nPrint/nPrintDSA/nPrintDIS
 *      raccolti da `#wrapInfoSchool`. Salvataggio sotto chiave
 *      `{indirizzo}_{classe}_{materia}` in `print_info.json`.
 *   2. `.salva-scelte-btn` / `.carica-scelte-btn` con 3 versioni v1/v2/v3
 *      (mutex via `.scelta-versione-checkbox`). Persiste lo stato completo
 *      della verifica (checkbox A/B, defPositionImp, totali VF, scelte
 *      collex-item, flag InfoVer) in `verifiche/scelte/{verFilePath}.json`.
 *   3. `.scelte-verifica-wrapper` (default `display:none` nel template
 *      Elementi_Riservati) viene reso visibile post `_caricaCheckboxABin`.
 *
 *  Stack moderno: zero jQuery in questo modulo. Per il salvataggio dello
 *  stato esercizi (collex-item walk) richiama `window.FM.utilities` —
 *  che ha già la collezione completa testata in legacy. Bridge thin.
 */

const Q = (sel, root = document) => root.querySelector(sel);
const QQ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
const v = (el, def = "") => (el && "value" in el ? el.value : def);

function toast(kind, msg) {
    if (window.FM?.ToastManager?.show) {
        window.FM.ToastManager.show(kind, kind === "error" ? "Errore" : "OK", msg, 3500);
    } else if (kind === "error") {
        alert(`❌ ${  msg}`);
    } else {
        console.info("[verifica-scelte]", msg);
    }
}

/** G19.2 — campi InfoVer/InfoSchool da serializzare per Print Info save.
 *  G19.18 — `istituto` + `sezione` nella key estesa.
 *  G20.6 — payload esteso ai campi #wrapInfoSchool + flag #wrapInfoVer
 *  (compensa/dsa/griglie/misure).
 *  G20.7 — verTitlePrefix + verTitle ESCLUSI: appartengono allo scope
 *  "salvataggio scelte" (zone 3), non a PrintInfo (zone 2). Ora vivono
 *  nel payload di salvaScelte() insieme a verTitle. */
function collectPrintInfoFields() {
    const indirizzo = sessionStorage.getItem("selectedIIS") || "";
    const classe    = sessionStorage.getItem("selectedCLS") || "";
    const materia   = sessionStorage.getItem("selectedMATER") || "";
    if (!indirizzo || !classe) {
        toast("error", "Seleziona prima indirizzo e classe dalla sidebar.");
        return null;
    }
    if (!materia || materia === "null" || materia === "undefined" || materia === "ALL") {
        toast("error", "Seleziona una materia valida dalla sidebar.");
        return null;
    }
    const cb = (sel) => Q(sel)?.checked ? "1" : "0";
    // G20.7 — versione RIMOSSO da PrintInfo: appartiene allo scope scelte
    // (mappa al file v1/v2/v3 attivo, non all'anagrafica della classe).
    return {
        indirizzo,
        classe,
        materia,
        // Anagrafica classe
        nPrint:        v(Q("#nPrint")),
        nPrintDSA:     v(Q("#nPrintDSA")),
        nPrintDIS:     v(Q("#nPrintDIS")),
        addressSchool: v(Q("#addressSchool")),
        istituto:      v(Q("#istituto")),
        sezione:       v(Q("#sezione")),
        anno:          v(Q("#anno")),
        verTime:       v(Q("#verTime")),
        nome:          v(Q("#nome")),
        cognome:       v(Q("#cognome")),
        // Flag BES (qui perche' governano il pack scelto in saveBatch e
        // restano per anno scolastico, non cambiano per singola verifica).
        compensa:      cb("#Compensa"),
        dsa:           cb("#DSA"),
        griglie:       cb("#griglie"),
        misure:        cb("#misure"),
    };
}

/** G19.18 — Auto-fill dei campi InfoVer dalla sidebar (sessionStorage)
 *  + curriculum (etichette indirizzo) + teacher institutes API.
 *  Riempie SOLO i campi vuoti per non sovrascrivere input dell'utente.
 *
 *  - `#classe`: da `sessionStorage.selectedCLS` (es. "2")
 *  - `#addressSchool`: da curriculum.indirizzi.find(c.code===selectedIIS).label
 *  - `#istituto` datalist: popolato da `/api/teacher/institutes`
 *  - Se l'utente ha 1 solo istituto, `#istituto` value = quello (auto-pick)
 */
async function autoFillFromSidebar() {
    const ind = sessionStorage.getItem("selectedIIS") || "";
    const cls = sessionStorage.getItem("selectedCLS") || "";
    const fillIfEmpty = (el, val) => {
        if (el && !el.value && val) el.value = val;
    };
    fillIfEmpty(Q("#classe"), cls);

    // 1) AddressSchool: deriva la label da curriculum.json
    if (ind) {
        try {
            const data = await fetchJson("/curriculum");
            const row = (data?.indirizzi || []).find(r => r.code === ind);
            if (row?.label) fillIfEmpty(Q("#addressSchool"), row.label);
        } catch (_) { /* ignore */ }
    }

    // 2) Istituti: popola datalist + auto-pick se 1 solo
    try {
        const j = await fetchJson("/api/teacher/institutes");
        const list = j?.institutes || [];
        const dl = Q("#fm-istituto-suggestions");
        if (dl) {
            dl.innerHTML = "";
            list.forEach(inst => {
                const opt = document.createElement("option");
                opt.value = inst.name || inst.code || "";
                if (inst.city) opt.label = inst.city;
                dl.appendChild(opt);
            });
        }
        if (list.length === 1) {
            fillIfEmpty(Q("#istituto"), list[0].name || list[0].code || "");
        }
        // G19.48 — cache primo institute code per VSC/Drive path mirror
        // (XXPS00000A → root cartella istituto in Drive sync mappe).
        const firstCode = (list[0]?.code || "").trim();
        if (firstCode) sessionStorage.setItem("instituteCode", firstCode);
    } catch (_) { /* ignore */ }
}

async function savePrintInfo() {
    const data = collectPrintInfoFields();
    if (!data) return;
    // G19.4 — usa l'endpoint modern G9 `/api/teacher/print-info`
    // (response shape `{ok, ...}`) invece del legacy `/verifiche/print-info`
    // (il wildcard `/verifiche/{path*}` legacy_gone lo shadowava → 410).
    // L'endpoint moderno è teacher-scoped (Auth::user()['username'] →
    // chiave `print_info.json`) — salvataggio per-docente isolato.
    try {
        const csrf = await fetchCsrf();
        const j = await fetchJson("/api/teacher/print-info", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": csrf,
            },
            body: JSON.stringify(data),
        });
        if (j?.ok) {
            toast("success", `Info stampa salvate (${data.materia} ${data.indirizzo}/${data.classe}).`);
        } else {
            toast("error", j?.error || "richiesta non riuscita");
        }
    } catch (e) {
        toast("error", `Errore di rete: ${e.message}`);
    }
}

/** G20.7 — toggle-mutex per i 3 button .fm-version-btn (sostituiscono
 *  le checkbox legacy .scelta-versione-checkbox). Click rende attivo
 *  il button (--active class) e disattiva gli altri. Mantiene almeno
 *  uno attivo: ri-cliccando l'attivo, NON lo deseleziona. */
function activateVersionButton(target) {
    const wrapper = target.closest(".fm-scelte-versioni") || document;
    const all = QQ(".fm-version-btn", wrapper);
    all.forEach(b => b.classList.remove("fm-version-btn--active"));
    target.classList.add("fm-version-btn--active");
}

function selectedVersion() {
    const active = Q(".fm-version-btn.fm-version-btn--active");
    return active?.dataset?.version || "v1";
}

/** G19.2 — proxy per `verFilePath` legacy: usa il pathname della pagina
 *  (es. `/studio/esercizio/ar/2s/MAT/1`) come chiave del file scelte. */
function currentVerFilePath() {
    return location.pathname;
}

/** G19.2 — Raccoglie lo state completo della verifica per il salvataggio.
 *  Usa `window.FM.utilities.salvaScelte` solo per la collection del state
 *  (lui chiama `$.ajax` senza CSRF → 403). Qui costruiamo il payload
 *  manualmente + fetch con CSRF, replica della stessa shape JSON che il
 *  legacy invierebbe. */
/** G19.2 — Raccoglie lo state completo della verifica per il salvataggio.
 *  G20.7 — pulizia ridondanza con PrintInfo:
 *  - RIMOSSI da scelte: anno, compensa, dsa, griglie, misure (ora vivono
 *    SOLO in PrintInfo come anagrafica della classe).
 *  - RESTANO in scelte: versione (mappa v1/v2/v3 al file scelta corrente),
 *    verTitle + verTitlePrefix (titolo della verifica nella versione),
 *    state esercizi (checkboxA/B, defPositionImp, collexItems, dsaMarks). */
function collectFullSceltaState() {
    const Q = (sel) => document.querySelector(sel);
    const QQ = (sel) => Array.from(document.querySelectorAll(sel));
    const data = {
        data:    new Date().toISOString().split("T")[0],
        ora:     new Date().toTimeString().split(" ")[0],
        checkboxA:      [],
        checkboxB:      [],
        defPositionImp: [],
        collexItems:    [],
        // Ordine dei gruppi nel DOM (riordino via .fm-move-position-problem):
        // va persistito o al reload i gruppi tornano nell'ordine originale.
        groupOrder:     [],
        // G20.7 — versione + verTitle + verTitlePrefix sono parte di
        // 'scelte' (zone 3); flag BES + anno vivono in PrintInfo.
        versione:       Q("#versione")?.value        || "",
        verTitle:       Q("#verTitle")?.value        || "",
        verTitlePrefix: Q("#verTitlePrefix")?.value  || "",
    };
    QQ(".fm-groupcollex").forEach((problem) => {
        const problemId = (problem.id || "").replace(/_add\d+$/, "");
        if (!problemId) return;
        data.groupOrder.push(problemId);
        data.checkboxA.push({ problemId, checked: !!problem.querySelector(".checkboxA")?.checked });
        data.checkboxB.push({ problemId, checked: !!problem.querySelector(".checkboxB")?.checked });
        const pos = problem.querySelector(".fm-def-position-imp")?.value;
        if (pos) data.defPositionImp.push({ problemId, value: pos });
        // Per item: salva ain/bin/inputPt
        QQ(".fm-collection__item", problem).forEach?.((el, idx) => {});
        Array.from(problem.querySelectorAll(".fm-collection__item")).forEach((el, idx) => {
            data.collexItems.push({
                problemId,
                index: idx,
                checkboxAin: !!el.querySelector(".fm-checkbox-ain")?.checked,
                checkboxBin: !!el.querySelector(".fm-checkbox-bin")?.checked,
                inputPt: el.querySelector(".fm-input-pt, input.inputPt")?.value || "",
            });
        });
    });
    // G19.3 — DSA marks F/GF da sessionStorage (popolato da dsa-marks.js)
    try {
        const m = JSON.parse(sessionStorage.getItem("fm-dsa-marks") || "{}");
        if (m && typeof m === "object") data.dsaMarks = m;
    } catch (_) { /* no-op */ }
    return data;
}

/** G27.scelte-server — Persistenza SERVER-SIDE per la coppia 💾/📂.
 *  Endpoint `/verifiche/scelte` (VerificheController::saveLoadScelte) →
 *  storage/data/scelte/{user}/{slug}.json, per (verFilePath, versionKey).
 *  Prima (M11→) le scelte vivevano in localStorage: svuotare la cache =
 *  perdere tutto, e nessun cross-device. Ora sopravvivono a clear-cache e
 *  seguono l'utente. Lo snapshot include anche l'ORDINE dei gruppi
 *  (groupOrder), così il riordino via .fm-move-position-problem persiste.
 */
const SCELTE_ENDPOINT = "/verifiche/scelte";

async function salvaScelte({ silent = false } = {}) {
    const state = collectFullSceltaState();
    const verFilePath = currentVerFilePath();
    const version = selectedVersion();
    try {
        const csrf = await fetchCsrf();
        const resp = await fetchJson(SCELTE_ENDPOINT, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8", "X-CSRF-Token": csrf },
            body: new URLSearchParams({
                action: "save", verFilePath, versionKey: version, data: JSON.stringify(state),
            }).toString(),
        });
        if (resp && resp.success === false) throw new Error(resp.message || "save failed");
        if (!silent) {
            toast("success",
                `Scelte salvate (${version}): ${state.checkboxA.length} problem, ${state.collexItems.length} item, ${state.defPositionImp.length} pos.`);
        }
        console.log("[verifica-scelte] saved (server)", { verFilePath, version, silent });
    } catch (err) {
        if (!silent) toast("error", `Salvataggio scelte fallito: ${err.message || err}`);
        console.error("[verifica-scelte] save error", err);
    }
}

/** Auto-save SILENZIOSO debounced — usato quando l'utente riordina i gruppi
 *  (.fm-move-position-problem) o gli item: l'ordine deve persistere SENZA dover
 *  cliccare 💾. Esposto su window.FM.VerificaScelte.autosave. */
let _autosaveTimer = null;
function autosave() {
    if (_autosaveTimer) clearTimeout(_autosaveTimer);
    _autosaveTimer = setTimeout(() => { salvaScelte({ silent: true }).catch(() => {}); }, 900);
}

/** Carica scelte da localStorage. `silent=true` evita toast su miss
 *  (usato dall'auto-restore al page-load). */
async function caricaScelte({ silent = false } = {}) {
    const verFilePath = currentVerFilePath();
    const version = selectedVersion();
    let state = null;
    try {
        const csrf = await fetchCsrf();
        const resp = await fetchJson(SCELTE_ENDPOINT, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8", "X-CSRF-Token": csrf },
            body: new URLSearchParams({ action: "load", verFilePath, versionKey: version }).toString(),
        });
        if (resp && resp.success && resp.data) state = resp.data;
    } catch (err) {
        console.warn("[verifica-scelte] load error", err);
    }

    if (!state) {
        if (!silent) toast("info", `Nessuna scelta salvata per ${version} su questo path.`);
        return false;
    }

    applySceltaToDom(state);
    if (!silent) {
        const when = state.data ? ` (salvate il ${state.data} ${state.ora || ""})` : "";
        toast("success", `Scelte caricate (${version})${when}.`);
    }
    console.log("[verifica-scelte] loaded (server)", { verFilePath, version });
    return true;
}

/** G20.7 — Auto-restore al page-load: legge l'ultima versione attiva
 *  da localStorage (per scope verFilePath), la attiva, e fa caricaScelte
 *  silent. Se nessun salvataggio esiste (404 atteso), nessun toast. */
const LS_LAST_VERSION_KEY = "fm-last-version-by-path";
function rememberActiveVersion(verFilePath, version) {
    try {
        const map = JSON.parse(localStorage.getItem(LS_LAST_VERSION_KEY) || "{}");
        map[verFilePath] = version;
        localStorage.setItem(LS_LAST_VERSION_KEY, JSON.stringify(map));
    } catch (_) { /* quota / parse / storage off */ }
}
function recallActiveVersion(verFilePath) {
    try {
        const map = JSON.parse(localStorage.getItem(LS_LAST_VERSION_KEY) || "{}");
        return map[verFilePath] || null;
    } catch (_) { return null; }
}

async function autoCaricaScelte() {
    const verFilePath = currentVerFilePath();
    // restore active version button (default v1)
    const lastVersion = recallActiveVersion(verFilePath) || "v1";
    const target = QQ(".fm-version-btn").find(b => b.dataset.version === lastVersion);
    if (target) activateVersionButton(target);
    // silent load: niente toast se il file scelte non esiste ancora
    await caricaScelte({ silent: true });
}

/** G19.2 — Riapplica lo state caricato al DOM corrente. */
function applySceltaToDom(state) {
    if (!state || typeof state !== "object") return;
    const set = (id, val) => { const el = document.getElementById(id); if (el && "value" in el) el.value = val ?? ""; };
    const setCb = (id, val) => { const el = document.getElementById(id); if (el && "checked" in el) el.checked = !!val; };
    // G20.7 — applySceltaToDom non setta piu' anno/Compensa/DSA/griglie/
    // misure (vivono in PrintInfo). Restano: versione + verTitle/Prefix.
    set("verTitle",       state.verTitle);
    set("verTitlePrefix", state.verTitlePrefix);
    set("versione",       state.versione);
    (state.checkboxA || []).forEach(({ problemId, checked }) => {
        const p = document.getElementById(problemId);
        if (p) {
            const cb = p.querySelector(".checkboxA");
            if (cb) cb.checked = !!checked;
        }
    });
    (state.checkboxB || []).forEach(({ problemId, checked }) => {
        const p = document.getElementById(problemId);
        if (p) {
            const cb = p.querySelector(".checkboxB");
            if (cb) cb.checked = !!checked;
        }
    });
    (state.defPositionImp || []).forEach(({ problemId, value }) => {
        const p = document.getElementById(problemId);
        if (p) {
            const inp = p.querySelector(".fm-def-position-imp");
            if (inp) inp.value = value;
        }
    });
    (state.collexItems || []).forEach(({ problemId, index, checkboxAin, checkboxBin, inputPt }) => {
        const p = document.getElementById(problemId);
        if (!p) return;
        const items = p.querySelectorAll(".fm-collection__item");
        const el = items[index];
        if (!el) return;
        const ain = el.querySelector(".fm-checkbox-ain");
        const bin = el.querySelector(".fm-checkbox-bin");
        const pt  = el.querySelector(".fm-input-pt, input.inputPt");
        if (ain) ain.checked = !!checkboxAin;
        if (bin) bin.checked = !!checkboxBin;
        if (pt && inputPt !== undefined) pt.value = inputPt;
    });
    if (state.dsaMarks && typeof state.dsaMarks === "object") {
        try { sessionStorage.setItem("fm-dsa-marks", JSON.stringify(state.dsaMarks)); } catch (_) {}
        // Re-injecting il DSA visual è gestito da `dsa-marks.js injectAll`
        // al prossimo evento `fm:verifica-ui-loaded` o MutationObserver.
        window.FM?.DsaMarks?.injectAll?.();
    }
    // Ripristina l'ORDINE dei gruppi (riordino via .fm-move-position-problem).
    // I .fm-groupcollex vivono in PIÙ container (sezioni): riordino OGNI
    // container per il rank salvato, senza toccare gli altri figli (header/
    // footer). Poi rinumero le posizioni come fa moveProblemToPosition.
    if (Array.isArray(state.groupOrder) && state.groupOrder.length) {
        const bid = (p) => (p.id || "").replace(/_add\d+$/, "");
        const rank = new Map(state.groupOrder.map((id, i) => [id, i]));
        const containers = new Set();
        document.querySelectorAll(".fm-groupcollex").forEach((p) => { if (p.parentElement) containers.add(p.parentElement); });
        containers.forEach((container) => {
            const groups = [...container.children].filter((el) => el.classList.contains("fm-groupcollex"));
            if (groups.length < 2) return;
            const anchor = groups[0];
            const sorted = [...groups].sort((a, b) => (rank.get(bid(a)) ?? 1e9) - (rank.get(bid(b)) ?? 1e9));
            let prev = null;
            for (const g of sorted) {
                if (!prev) container.insertBefore(g, anchor);
                else prev.after(g);
                prev = g;
            }
        });
        try { window.FM?.populatePositionInputs?.(); } catch (_) { /* no-op */ }
    }

    // Trigger eventi per ricalcolo totali
    document.querySelectorAll(".fm-checkbox-ain, .fm-checkbox-bin").forEach(c => {
        c.dispatchEvent(new Event("change", { bubbles: true }));
    });
}

function showSceltaWrapper() {
    QQ(".scelte-verifica-wrapper").forEach(w => {
        if (w.style.display === "none" || w.style.display === "") {
            w.style.display = "flex";
            w.style.gap = "8px";
            w.style.alignItems = "center";
        }
    });
}

let _bound = false;
function init() {
    if (_bound) return;
    _bound = true;

    document.addEventListener("click", (e) => {
        const t = e.target;
        if (!t?.closest) return;
        if (t.closest("#savePrintInfoBtn")) {
            e.preventDefault();
            savePrintInfo();
        } else if (t.closest("#loadPrintInfoBtn")) {
            e.preventDefault();
            openLoadPrintInfoModal();
        } else if (t.closest(".fm-salva-scelte-btn")) {
            e.preventDefault();
            salvaScelte();
        } else if (t.closest(".fm-carica-scelte-btn")) {
            e.preventDefault();
            caricaScelte();
        }
    });

    // G20.7 — version button toggle: click su .fm-version-btn attiva
    // quel button + disattiva gli altri. Persiste la scelta in
    // localStorage (per verFilePath) per restore al prossimo page-load,
    // e auto-carica le scelte salvate per la versione cliccata.
    document.addEventListener("click", async (e) => {
        const btn = e.target?.closest?.(".fm-version-btn");
        if (btn) {
            e.preventDefault();
            activateVersionButton(btn);
            rememberActiveVersion(currentVerFilePath(), btn.dataset.version);
            await caricaScelte({ silent: true });
        }
    });

    // Visibility: il wrapper è hidden by default nel template Elementi_Riservati;
    // mostralo appena #scrollbarInfo + .checkIN sono pronti (verifica-mode).
    if (document.body.classList.contains("fm-verifica-mode")) showSceltaWrapper();
    window.addEventListener("fm:verifica-ui-loaded", () => {
        showSceltaWrapper();
        // G19.18 — auto-fill da sidebar al caricamento UI verifica
        autoFillFromSidebar().catch(() => {});
        // G20.7 — auto-restore scelte salvate per il scope corrente
        // (verFilePath = pathname include indirizzo/classe/materia, e
        // l'endpoint e' teacher-scoped lato server). Versione attiva da
        // localStorage; default v1.
        // Delay per attendere che _caricaCheckboxABin finisca di clonare
        // .selector-eser nel topbar slot (l'ajax in ui-comp.js e' async
        // anche dopo il dispatch di fm:verifica-ui-loaded).
        setTimeout(() => autoCaricaScelte().catch(() => {}), 600);
    });
    // G19.18 — auto-fill anche all'apertura del drawer Info (toggleInfoDrawer)
    document.addEventListener("click", (e) => {
        if (e.target?.closest?.('#fm-topbar [data-fm-action="info"]')) {
            // Drawer toggle async; auto-fill subito + dopo timeout per
            // catturare il caso in cui #infoVer è injected on demand.
            autoFillFromSidebar().catch(() => {});
            setTimeout(() => autoFillFromSidebar().catch(() => {}), 600);
        }
    });
}

/** G19.18 — Modal "Carica info classe": fetch lista print_info salvate del
 *  docente da `/api/teacher/print-info/list`, mostra come table cliccabile.
 *  Click su una riga → fetch GET single + popola fields. */
async function openLoadPrintInfoModal() {
    document.querySelectorAll("#fm-load-printinfo-modal").forEach(m => m.remove());
    let items = [];
    try {
        const j = await fetchJson("/api/teacher/print-info/list");
        items = j?.items || [];
    } catch (e) {
        toast("error", `Errore fetch lista: ${e.message || e}`);
        return;
    }
    if (!items.length) {
        toast("info", "Nessun salvataggio trovato. Salva prima qualche configurazione con 💾.");
        return;
    }
    const m = document.createElement("div");
    m.id = "fm-load-printinfo-modal";
    m.className = "fm-modal-backdrop";

    // G20.7 — card-based layout: ogni record mostra TUTTI i campi salvati
    // (oltre a key fields: anno, verTime, num.copie NOR/DSA/DIS, versione,
    // studente, flag bes, verTitle). Click ✎ Modifica → form inline con
    // input editabili → 💾 Salva fa POST /api/teacher/print-info.
    const cb = (v) => (v === true || v === 1 || v === "1" || v === "true") ? "✔" : "—";
    const safe = (v) => (v == null || v === "") ? "—" : String(v);
    const renderCards = (rows) => rows.map((it, idx) => `
        <article class="fm-pi-card" data-idx="${idx}">
            <header class="fm-pi-card-head">
                <span class="fm-pi-card-title">
                    <strong>${escHtml(safe(it.classe))}${escHtml(safe(it.sezione))}</strong>
                    <span class="fm-pi-tag">${escHtml(safe(it.indirizzo))}</span>
                    <span class="fm-pi-tag fm-pi-tag--mat">${escHtml(safe(it.materia))}</span>
                    <span class="fm-pi-card-istituto">${escHtml(safe(it.istituto || it.addressSchool))}</span>
                </span>
                <span class="fm-pi-card-actions">
                    <button type="button" class="fm-btn-primary fm-load-row" data-idx="${idx}">📂 Carica</button>
                    <button type="button" class="fm-btn fm-edit-row" data-idx="${idx}" title="Modifica i valori salvati">✎ Modifica</button>
                    <button type="button" class="fm-btn-danger fm-delete-row" data-idx="${idx}" title="Elimina questo salvataggio">🗑️</button>
                </span>
            </header>
            <dl class="fm-pi-card-grid">
                <div><dt>Anno</dt><dd>${escHtml(safe(it.anno))}</dd></div>
                <div><dt>Tempo</dt><dd>${escHtml(safe(it.verTime))}</dd></div>
                <div><dt>NOR</dt><dd>${escHtml(safe(it.nPrint || it.n_print))}</dd></div>
                <div><dt>DSA</dt><dd>${escHtml(safe(it.nPrintDSA || it.n_print_dsa))}</dd></div>
                <div><dt>DIS</dt><dd>${escHtml(safe(it.nPrintDIS || it.n_print_dis))}</dd></div>
                <div><dt>Versione</dt><dd>${escHtml(safe(it.versione))}</dd></div>
                <div><dt>Compensa</dt><dd>${cb(it.compensa)}</dd></div>
                <div><dt>DSA</dt><dd>${cb(it.dsa)}</dd></div>
                <div><dt>Griglie</dt><dd>${cb(it.griglie)}</dd></div>
                <div><dt>Ult.misure</dt><dd>${cb(it.misure)}</dd></div>
                <div class="fm-pi-card-grid--wide"><dt>Studente</dt><dd>${escHtml([it.nome, it.cognome].filter(Boolean).join(" ") || "—")}</dd></div>
            </dl>
            <form class="fm-pi-card-edit" hidden data-idx="${idx}">
                <div class="fm-pi-edit-grid">
                    <label>Classe<input name="classe" value="${escHtml(safe(it.classe))}"></label>
                    <label>Sezione<input name="sezione" value="${escHtml(safe(it.sezione))}"></label>
                    <label>Indirizzo<input name="indirizzo" value="${escHtml(safe(it.indirizzo))}"></label>
                    <label>Materia<input name="materia" value="${escHtml(safe(it.materia))}"></label>
                    <label>Istituto<input name="istituto" value="${escHtml(safe(it.istituto))}"></label>
                    <label>AddressSchool<input name="addressSchool" value="${escHtml(safe(it.addressSchool))}"></label>
                    <label>Anno<input name="anno" value="${escHtml(safe(it.anno))}"></label>
                    <label>Tempo<input name="verTime" value="${escHtml(safe(it.verTime))}"></label>
                    <label>NOR<input name="nPrint" value="${escHtml(safe(it.nPrint))}"></label>
                    <label>DSA<input name="nPrintDSA" value="${escHtml(safe(it.nPrintDSA))}"></label>
                    <label>DIS<input name="nPrintDIS" value="${escHtml(safe(it.nPrintDIS))}"></label>
                    <label>Versione<input name="versione" value="${escHtml(safe(it.versione))}"></label>
                    <label>Nome<input name="nome" value="${escHtml(safe(it.nome))}"></label>
                    <label>Cognome<input name="cognome" value="${escHtml(safe(it.cognome))}"></label>
                    <label class="fm-pi-edit-cb"><input type="checkbox" name="compensa" ${cb(it.compensa) === "✔" ? "checked" : ""}>Compensa</label>
                    <label class="fm-pi-edit-cb"><input type="checkbox" name="dsa" ${cb(it.dsa) === "✔" ? "checked" : ""}>DSA</label>
                    <label class="fm-pi-edit-cb"><input type="checkbox" name="griglie" ${cb(it.griglie) === "✔" ? "checked" : ""}>Griglie</label>
                    <label class="fm-pi-edit-cb"><input type="checkbox" name="misure" ${cb(it.misure) === "✔" ? "checked" : ""}>Ult.misure</label>
                </div>
                <div class="fm-pi-edit-actions">
                    <button type="submit" class="fm-btn-primary fm-save-row" data-idx="${idx}">💾 Salva</button>
                    <button type="button" class="fm-btn fm-cancel-row" data-idx="${idx}">Annulla</button>
                    <span class="fm-muted" style="font-size:11px;margin-left:auto;">
                        Modificare classe/sezione/indirizzo/materia/istituto cambia la chiave: il vecchio record verra' eliminato e ricreato.
                    </span>
                </div>
            </form>
        </article>
    `).join("");

    m.innerHTML = `
        <div class="fm-modal fm-load-printinfo" role="dialog" aria-modal="true">
            <button type="button" class="fm-modal-close" data-action="close" aria-label="Chiudi">×</button>
            <h3>Carica info classe salvate</h3>
            <p class="fm-muted">📂 Carica i dati nel pannello InfoVer · ✎ Modifica i valori · 🗑️ Elimina un salvataggio.</p>
            <div class="fm-pi-cards">${renderCards(items)}</div>
            <div class="fm-modal-actions">
                <button type="button" class="fm-btn fm-btn-danger fm-delete-all"
                        title="Elimina TUTTI i salvataggi print info del docente">
                    🗑️ Elimina tutti
                </button>
                <button type="button" class="fm-btn" data-action="close">Chiudi</button>
            </div>
        </div>
    `;
    const cardsHost = m.querySelector(".fm-pi-cards");

    async function saveRow(idx, formEl) {
        const item = items[idx];
        if (!item) return false;
        const fd = new FormData(formEl);
        const payload = {};
        for (const [k, v] of fd.entries()) payload[k] = String(v);
        // Checkbox non checked non finiscono in FormData → forziamo "0".
        for (const k of ["compensa", "dsa", "griglie", "misure"]) {
            if (!(k in payload)) payload[k] = "0";
            else payload[k] = "1";
        }
        // Required key fields
        for (const k of ["indirizzo", "classe", "materia"]) {
            if (!payload[k]) {
                toast("error", `${k} obbligatorio`);
                return false;
            }
        }
        const csrf = await fetchCsrf();
        // Se la key cambia, prima cancello il vecchio record (page_key esistente).
        const oldKey = item.page_key;
        const j = await fetchJson("/api/teacher/print-info", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": csrf,
            },
            body: JSON.stringify(payload),
        });
        if (!j?.ok) {
            toast("error", j?.error || "richiesta non riuscita");
            return false;
        }
        const newKey = j.key;
        if (oldKey && newKey && oldKey !== newKey) {
            await fetchJson("/api/teacher/print-info/delete", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
                body: JSON.stringify({ page_key: oldKey }),
            });
        }
        return true;
    }

    async function deleteOne(item) {
        const csrf = await fetchCsrf();
        // G20.7 — passa il page_key esplicito dal list (item.page_key); il
        // backend usa quello come source of truth, evitando il mismatch tra
        // legacy 3-field key (sc_2s_MAT) e new 5-field (sc_2s_MAT_B_) che
        // makeKey() ricostruirebbe quando il payload include sezione.
        const payload = item.page_key
            ? { page_key: item.page_key }
            : {
                indirizzo: item.indirizzo || "",
                classe:    item.classe    || "",
                materia:   item.materia   || "",
                sezione:   item.sezione   || "",
                istituto:  item.istituto  || "",
            };
        const j = await fetchJson("/api/teacher/print-info/delete", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": csrf,
            },
            body: JSON.stringify(payload),
        });
        if (!j?.ok) throw new Error(j?.error || "richiesta non riuscita");
        return !!j.deleted;
    }

    async function reloadList() {
        const j = await fetchJson("/api/teacher/print-info/list");
        return j?.items || [];
    }

    function rerender(refreshed) {
        if (refreshed) items.length = 0, items.push(...refreshed);
        if (!items.length) {
            toast("info", "Nessun salvataggio rimasto.");
            m.classList.remove("fm-modal--visible");
            setTimeout(() => m.remove(), 150);
            return;
        }
        cardsHost.innerHTML = renderCards(items);
    }

    m.addEventListener("submit", async (e) => {
        const form = e.target.closest("form.fm-pi-card-edit");
        if (!form) return;
        e.preventDefault();
        const idx = parseInt(form.dataset.idx, 10);
        const ok = await saveRow(idx, form);
        if (ok) {
            toast("success", "Salvato.");
            const fresh = await reloadList();
            rerender(fresh);
        }
    });

    m.addEventListener("click", async (e) => {
        const closeBtn = e.target.closest('[data-action="close"]');
        if (closeBtn || e.target === m) {
            m.classList.remove("fm-modal--visible");
            setTimeout(() => m.remove(), 150);
            return;
        }
        const loadBtn = e.target.closest(".fm-load-row");
        if (loadBtn) {
            const idx = parseInt(loadBtn.dataset.idx, 10);
            const item = items[idx];
            if (item) applyPrintInfoToFields(item);
            m.classList.remove("fm-modal--visible");
            setTimeout(() => m.remove(), 150);
            return;
        }
        const editBtn = e.target.closest(".fm-edit-row");
        if (editBtn) {
            e.preventDefault();
            const idx = parseInt(editBtn.dataset.idx, 10);
            const card = m.querySelector(`.fm-pi-card[data-idx="${idx}"]`);
            if (!card) return;
            const wasOpen = card.classList.toggle("fm-pi-card--editing");
            const form = card.querySelector("form.fm-pi-card-edit");
            if (form) form.hidden = !wasOpen;
            return;
        }
        const cancelBtn = e.target.closest(".fm-cancel-row");
        if (cancelBtn) {
            e.preventDefault();
            const idx = parseInt(cancelBtn.dataset.idx, 10);
            const card = m.querySelector(`.fm-pi-card[data-idx="${idx}"]`);
            if (card) {
                card.classList.remove("fm-pi-card--editing");
                const form = card.querySelector("form.fm-pi-card-edit");
                if (form) form.hidden = true;
            }
            return;
        }
        const delBtn = e.target.closest(".fm-delete-row");
        if (delBtn) {
            e.preventDefault();
            const idx = parseInt(delBtn.dataset.idx, 10);
            const item = items[idx];
            if (!item) return;
            const label = `${item.classe || "?"} ${item.sezione || ""} ${item.indirizzo || ""} ${item.materia || ""}`.trim();
            const ok = window.FM?.Dialog?.confirm
                ? await window.FM.Dialog.confirm(
                    `Eliminare il salvataggio "${label}"?\n\nOperazione non reversibile.`,
                    { title: "Elimina print info", kind: "danger" })
                : window.confirm(`Eliminare "${label}"?`);
            if (!ok) return;
            try {
                await deleteOne(item);
                items.splice(idx, 1);
                rerender();
                toast("success", `Eliminato "${label}".`);
            } catch (err) {
                toast("error", `Errore eliminazione: ${err.message || err}`);
            }
            return;
        }
        const delAllBtn = e.target.closest(".fm-delete-all");
        if (delAllBtn) {
            e.preventDefault();
            const ok = window.FM?.Dialog?.confirm
                ? await window.FM.Dialog.confirm(
                    `Eliminare TUTTI i ${items.length} salvataggi print info?\n\nOperazione non reversibile.`,
                    { title: "Elimina tutti", kind: "danger" })
                : window.confirm(`Eliminare tutti i ${items.length} salvataggi?`);
            if (!ok) return;
            try {
                let okCount = 0;
                for (const it of items.slice()) {
                    try { await deleteOne(it); okCount++; } catch (_) { /* continua */ }
                }
                toast("success", `${okCount}/${items.length} salvataggi eliminati.`);
                m.classList.remove("fm-modal--visible");
                setTimeout(() => m.remove(), 150);
            } catch (err) {
                toast("error", `Errore: ${err.message || err}`);
            }
        }
    });
    document.body.appendChild(m);
    requestAnimationFrame(() => m.classList.add("fm-modal--visible"));
}

function applyPrintInfoToFields(item) {
    const set = (id, val) => {
        const el = document.getElementById(id);
        if (el && val != null) {
            el.value = String(val);
            el.dispatchEvent(new Event("change", { bubbles: true }));
        }
    };
    const setCb = (id, val) => {
        const el = document.getElementById(id);
        if (!el || val == null) return;
        // G20.6 — supporta sia "1"/"0" string sia bool. Stringa "0" → false.
        const truthy = (val === true) || (val === 1) || (val === "1") || (val === "true");
        el.checked = truthy;
        el.dispatchEvent(new Event("change", { bubbles: true }));
    };
    set("classe",        item.classe);
    set("sezione",       item.sezione);
    set("addressSchool", item.addressSchool || item.address_school);
    set("istituto",      item.istituto);
    set("anno",          item.anno);
    set("verTime",       item.verTime || item.ver_time);
    set("nPrint",        item.nPrint    || item.n_print);
    set("nPrintDSA",     item.nPrintDSA || item.n_print_dsa);
    set("nPrintDIS",     item.nPrintDIS || item.n_print_dis);
    // G20.6 — campi estesi (esclusi verTitle/Prefix dopo G20.7)
    set("versione",       item.versione);
    set("nome",           item.nome);
    set("cognome",        item.cognome);
    setCb("Compensa", item.compensa);
    setCb("DSA",      item.dsa);
    setCb("griglie",  item.griglie);
    setCb("misure",   item.misure);
    toast("success", `Caricato (${item.classe || "?"} ${item.sezione || ""} ${item.indirizzo || ""}).`);
}

init();

window.FM = window.FM || {};
window.FM.VerificaScelte = { savePrintInfo, salvaScelte, caricaScelte, autosave, init };
