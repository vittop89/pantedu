// Entry Vite di views/area_docente/profilo.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

// ── blocco 1 ── (IIFE async: usava await al top level)
import { notify } from "/js/modules/ui/sync-panel.js";
import { fetchJson, fetchCsrf, FetchJsonError, escHtml as escapeHtml } from "/js/modules/core/dom-utils.js";
import { attachAutocomplete } from "/js/modules/ui/autocomplete.js";
import { initTeacherCredentials } from "/js/modules/features/teacher-credentials.js";
import { gruppiDelleClassi, soloPresenti, chiaveClasse } from "/js/modules/features/catalogo-classi.js";
(async () => {
// Centralizzazione (anti-duplicazione + gestione WAF challenge unica):
// fetchJson/fetchCsrf/FetchJsonError vivono in dom-utils. assertJson
// riconosce il waf_challenge (JSON o HTML) e auto-ricarica per rinnovare
// il cookie waf_session; qui ci limitiamo a notificare l'errore.

let allInstitutes = [];
let activeInstituteId = null; // settato da loadCurrent
// Il vocabolario della scuola per l'istituto attivo e le spunte del docente
// (accese e spente), per kind: dal server, con la stessa chiamata.
let vocabolario = { indirizzi: [], classi: [], materie: [] };
let spunte = { indirizzi: [], classi: [], materie: [] };
let linkedCodes = new Set();  // codici istituto già collegati (annotati nei suggerimenti)
let selectedInstitute = null; // ultima voce scelta dall'autocomplete
let instituteAC = null;       // handle autocomplete (refresh on data change)

const getCsrf = fetchCsrf;

/** Notifica leggibile per gli errori di rete/JSON (incl. WAF challenge). */
function notifyFetchError(scope, e) {
    const msg = (e instanceof FetchJsonError)
        ? e.message
        : (e && e.message) ? e.message : "Errore di rete";
    notify(scope, "error", msg, e?.code === "waf_challenge" ? 4000 : 0);
}

/**
 * Conferma inline 2-step (memory rule: no browser confirm()).
 * Bottone passa a stato "⚠ Conferma?" per `timeoutMs`, secondo click esegue.
 */
function confirmInline(btn, action, timeoutMs = 4000) {
    if (btn.dataset.confirmPending === "1") return;
    const orig = btn.innerHTML;
    const cls = btn.className;
    btn.dataset.confirmPending = "1";
    btn.innerHTML = "⚠ Conferma?";
    btn.classList.add("fm-btn--danger");
    const handler = (e) => {
        e.preventDefault();
        cleanup();
        action();
    };
    const cleanup = () => {
        btn.removeEventListener("click", handler);
        btn.innerHTML = orig;
        btn.className = cls;
        delete btn.dataset.confirmPending;
    };
    btn.addEventListener("click", handler, { once: true });
    setTimeout(cleanup, timeoutMs);
}

async function loadCurrent() {
    let j;
    try { j = await fetchJson("/api/teacher/institutes"); }
    catch (e) { notifyFetchError("Istituti", e); return; }
    const ul = document.getElementById("fm-profile-current");
    const list = j.institutes || [];
    // Codici già collegati → annotati nel datalist di ricerca (evita di
    // "ricollegare" la stessa scuola che appare sotto un'altra etichetta).
    linkedCodes = new Set(list.map(i => i.code));
    renderSuggestions();
    if (!list.length) {
        ul.innerHTML = '<p class="fm-muted">Nessun istituto collegato. Aggiungine uno qui sotto.</p>';
        activeInstituteId = null;
        return;
    }
    // Istituto attivo: STESSA fonte della sidebar, così profilo ed editor
    // mostrano sempre lo stesso istituto.
    //  1. sessionStorage.activeInstituteCode (settato da wireIstitutoSelector
    //     quando l'utente cambia istituto dal dropdown);
    //  2. valore corrente del dropdown #sel-istituto (reso server-side con
    //     l'opzione `selected` = currentInstituteId di sessione) — copre il
    //     caso sessionStorage vuoto, in cui prima si cadeva su list[0]
    //     (primo istituto collegato), divergendo da ciò che la sidebar mostra;
    //  3. primo istituto collegato come ultimo fallback.
    const selIst = document.getElementById("sel-istituto");
    const activeCode = sessionStorage.getItem("activeInstituteCode")
        || (selIst ? selIst.value : "")
        || "";
    const activeRow = list.find(i => i.code === activeCode) || list[0];
    activeInstituteId = activeRow ? activeRow.id : null;
    // Mostra ESPLICITAMENTE quale istituto è attivo (la causa principale
    // della confusione era che il pannello non lo diceva).
    const instNameEl = document.getElementById("fm-curr-active-inst");
    if (instNameEl) {
        instNameEl.innerHTML = activeRow
            ? `📍 Stai gestendo: <strong>${escapeHtml(activeRow.name || activeRow.header_label || "")}</strong> <code class="fm-mono-muted fm-text-11">${escapeHtml(activeRow.code || "")}</code> — per un altro istituto, cambialo dal menu <em>Istituto</em> nella sidebar.`
            : "Nessun istituto attivo.";
    }
    ul.innerHTML = `
        <table class="fm-data-table">
            <thead>
                <tr>
                    <th scope="col">Codice</th>
                    <th scope="col">Nome</th>
                    <th scope="col">Citta'</th>
                    <th scope="col" class="fm-text-center">Stato</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
            ${list.map(i => `
                <tr>
                    <td class="fm-mono-muted fm-font-mono fm-text-xs" >${escapeHtml(i.code)}</td>
                    <td><strong>${escapeHtml(i.name || i.header_label)}</strong></td>
                    <td>${escapeHtml(i.city || '—')}</td>
                    <td class="fm-text-center">
                        ${i.id === activeInstituteId ? '<span class="fm-badge--active">● attivo</span>' : ''}
                    </td>
                    <td class="fm-text-right">
                        <button class="fm-btn fm-btn--xs fm-btn--danger" data-unlink-id="${i.id}" data-unlink-name="${escapeHtml(i.name)}">🗑 Rimuovi</button>
                    </td>
                </tr>`).join('')}
            </tbody>
        </table>
    `;
    ul.querySelectorAll("[data-unlink-id]").forEach(b => {
        b.addEventListener("click", () => {
            confirmInline(b, () =>
                unlinkInstitute(parseInt(b.dataset.unlinkId, 10), b.dataset.unlinkName));
        });
    });
}

async function loadAllInstitutes() {
    let j;
    try { j = await fetchJson("/api/institutes"); }
    catch (e) { notifyFetchError("Istituti", e); return; }
    allInstitutes = j.institutes || [];
    renderSuggestions();
}

/**
 * Autocomplete custom (sostituisce il <datalist> nativo: non stilabile né
 * a tema dark). Mostra il NOME ufficiale MIUR (più chiaro e disambiguante
 * dell'header_label, che è un'etichetta per i documenti), col codice
 * meccanografico + città come meta e il marcatore "✓ già collegato".
 */
function renderSuggestions() {
    const input = document.getElementById("fm-profile-search");
    if (!input) return;
    if (!instituteAC) {
        instituteAC = attachAutocomplete(input, {
            items: () => allInstitutes,
            getLabel: (i) => i.name,
            getMeta:  (i) => [i.code, i.city].filter(Boolean).join(" · "),
            getNote:  (i) => linkedCodes.has(i.code) ? "✓ già collegato" : "",
            onSelect: (i) => { selectedInstitute = i; },
            minChars: 2,
        });
    } else {
        instituteAC.refresh();
    }
}

async function addInstitute() {
    const input = document.getElementById("fm-profile-search");
    const fb = document.getElementById("fm-profile-add-feedback");
    const v = input.value.trim();
    const vl = v.toLowerCase();
    // Preferisci la voce scelta dall'autocomplete; altrimenti risolvi per
    // nome esatto, poi codice meccanografico, poi header_label.
    let inst = (selectedInstitute && selectedInstitute.name === v) ? selectedInstitute : null;
    if (!inst) {
        inst = allInstitutes.find(i => (i.name || "").toLowerCase() === vl)
            || allInstitutes.find(i => (i.code || "").toLowerCase() === vl)
            || allInstitutes.find(i => (i.header_label || "").toLowerCase() === vl);
    }
    if (!inst) {
        fb.style.color = "#b91c1c";
        fb.textContent = "Istituto non trovato. Scegli da elenco autocomplete.";
        return;
    }
    const instLabel = inst.name || inst.header_label;
    // Già collegato (anche se appare sotto altra etichetta): niente POST inutile.
    if (linkedCodes.has(inst.code)) {
        fb.style.color = "#b45309";
        fb.textContent = `Già collegato: ${instLabel}`;
        return;
    }
    const csrf = await getCsrf();
    const fd = new FormData();
    fd.set("institute_id", String(inst.id));
    fd.set("_csrf", csrf);
    let j;
    try {
        j = await fetchJson("/api/teacher/institutes/link", {
            method: "POST", headers: { "X-CSRF-Token": csrf }, body: fd,
        });
    } catch (e) {
        fb.style.color = "#b91c1c";
        fb.textContent = (e instanceof FetchJsonError) ? e.message : "Errore di rete";
        return;
    }
    if (j.ok && j.already_linked) {
        // Il server conferma: nessuna nuova riga (stessa scuola già presente).
        fb.style.color = "#b45309";
        fb.textContent = `Già collegato: ${instLabel}`;
        await loadCurrent();
    } else if (j.ok) {
        fb.style.color = "#15803d";
        fb.textContent = `✓ Collegato: ${instLabel}`;
        input.value = "";
        selectedInstitute = null;
        await Promise.all([loadCurrent(), loadCurriculumAndPivot()]);
    } else {
        fb.style.color = "#b91c1c";
        const human = j.error === "institute_inactive"
            ? "Istituto non attivo (probabile duplicato unito a un altro). Non collegabile."
            : (j.error || "richiesta non riuscita");
        fb.textContent = `Errore: ${human}`;
    }
}

async function unlinkInstitute(id, name) {
    const csrf = await getCsrf();
    const fd = new FormData();
    fd.set("_csrf", csrf);
    let j;
    try {
        j = await fetchJson(`/api/teacher/institutes/${id}/unlink`, {
            method: "POST", headers: { "X-CSRF-Token": csrf }, body: fd,
        });
    } catch (e) { notifyFetchError("Istituti", e); return; }
    if (j.ok) {
        // 2026-09-22 — se era l'ultima scuola, il server lo dice e qui si dice
        // al docente. Prima scollegare l'ultima rispondeva `{ok:true}` e
        // basta: catalogo, fonti e adozioni si spegnevano senza che niente
        // l'avesse annunciato.
        if (j.senza_scuola) {
            notify("Istituti", "warn", j.messaggio || "Non hai più nessuna scuola collegata.", 0);
        } else {
            notify("Istituti", "ok", `🔓 Scollegato: ${name}`, 3000);
        }
        await Promise.all([loadCurrent(), loadCurriculumAndPivot()]);
    } else {
        notify("Istituti", "error", j.messaggio || `Errore: ${j.error || "richiesta non riuscita"}`, 0);
    }
}

/* ──────────────── Curriculum dell'istituto attivo (editor) ──────────────── */

// skipEditor=true → NON ri-renderizza la tabella-editor: usato dopo il save
// di UNA riga, così le modifiche non salvate digitate nelle ALTRE righe non
// vengono scartate (in quel caso aggiorniamo solo i selettori sidebar).
async function loadCurriculumAndPivot({ skipEditor = false } = {}) {
    const panels = Object.fromEntries(
        Array.from(document.querySelectorAll(".fm-curr-panel"))
            .map((p) => [p.dataset.panel, p])
    );
    const setAllPanels = (html) => {
        for (const k of ["indirizzi", "classi", "materie"]) {
            if (panels[k]) panels[k].innerHTML = html;
        }
    };
    if (!activeInstituteId) {
        setAllPanels('<p class="fm-muted">Collega prima un istituto per vedere il curriculum.</p>');
        return;
    }

    // Il vocabolario della scuola e le spunte del docente nell'istituto
    // attivo, con la stessa chiamata. include_inactive=1: una casella spenta
    // deve poter tornare accesa, quindi anche le spunte spente devono arrivare.
    const qs = `?institute_id=${encodeURIComponent(activeInstituteId)}&include_inactive=1`;
    let cat;
    try {
        cat = await fetchJson(`/api/teacher/curriculum${qs}`);
    } catch (e) {
        const m = (e instanceof FetchJsonError) ? e.message : "errore di rete";
        setAllPanels(`<p class="fm-muted">Errore catalog: ${escapeHtml(m)}</p>`);
        return;
    }
    if (!cat.ok) { setAllPanels(`<p class="fm-muted">Errore catalog: ${escapeHtml(cat.error || "?")}</p>`); return; }
    vocabolario = cat.institute_vocabolario || { indirizzi: [], classi: [], materie: [] };
    spunte = cat.curriculum || { indirizzi: [], classi: [], materie: [] };

    if (!skipEditor) {
        for (const kind of ["indirizzi", "classi", "materie"]) {
            renderCatalogPanel(kind, panels[kind]);
        }
    }
}

/** La barra laterale ricarica i suoi selettori da sola (js/modules/core/sidebar-cascade.js). */
function segnalaCurriculumCambiato() {
    document.dispatchEvent(new CustomEvent("fm:curriculum-changed", { detail: { iid: activeInstituteId } }));
}

/** La spunta del docente su una voce, se c'e' (accesa o spenta). Una classe si
 *  riconosce da sigla e corso: «3» dello scientifico e «3» dell'artistico sono
 *  due classi (ADR-042). */
function spuntaDi(kind, voce) {
    const chiave = kind === "classi" ? chiaveClasse(voce) : null;
    return (spunte[kind] || []).find((e) => (chiave ? chiaveClasse(e) === chiave : e.code === voce.code)) || null;
}

/** I codici accesi di un kind. */
function codiciAccesi(kind) {
    return new Set((spunte[kind] || []).filter((e) => e.active).map((e) => e.code));
}

const NOMI_KIND = { indirizzi: "indirizzi", classi: "classi", materie: "materie" };

/** Un nodo con attributi e figli: si costruisce col DOM, non con innerHTML,
 *  perche' etichette e sigle arrivano dal vocabolario della scuola. */
function nodo(tag, attrs = {}, ...children) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v === undefined || v === null || v === false) continue;
        if (k === "dataset") { Object.assign(n.dataset, v); continue; }
        n.setAttribute(k, v === true ? "" : String(v));
    }
    n.append(...children.filter((c) => c !== null && c !== undefined && c !== false));
    return n;
}

/**
 * ADR-035 — il catalogo e' della scuola e il docente lo spunta. Ogni scheda
 * e' l'elenco delle voci del vocabolario dell'istituto attivo, con una
 * casella per voce; accanto a una voce accesa c'e' il nome che vede lui
 * (quello della scuola resta a fianco) e, per le materie, «Condivisa».
 *
 * La catena e' la stessa dei selettori della sidebar: le classi compaiono
 * sotto il loro corso, anni compresi (ADR-042), e solo per gli indirizzi gia'
 * spuntati; le materie si spuntano dopo almeno una classe.
 */
function renderCatalogPanel(kind, panelEl) {
    if (!panelEl) return;
    const voci = vocabolario[kind] || [];
    if (!voci.length) {
        panelEl.replaceChildren(nodo("p", { class: "fm-muted fm-text-13", dataset: { catalogoAvviso: "" } },
            `La scuola non ha ancora ${NOMI_KIND[kind]} a catalogo: li mette l'amministratore dal pannello degli istituti, dal dataset MIUR delle adozioni o a mano.`));
        return;
    }
    const indirizziAccesi = codiciAccesi("indirizzi");
    const classiAccese = codiciAccesi("classi").size;
    const gruppi = [];
    let avviso = "";
    let bloccato = false;
    if (kind === "classi") {
        // Un gruppo per corso spuntato, con prima i suoi anni e poi le sue
        // sezioni: catalogo-classi.js.
        gruppi.push(...gruppiDelleClassi(voci, vocabolario.indirizzi || [], indirizziAccesi));
        if (!indirizziAccesi.size && voci.some((v) => v.indirizzo)) {
            avviso = "Spunta prima un indirizzo: le classi compaiono sotto il loro corso.";
        } else if (!gruppi.length) {
            avviso = "Nessuna classe a catalogo per gli indirizzi che hai spuntato.";
        }
    } else {
        gruppi.push({ titolo: "", voci });
        if (kind === "materie" && !classiAccese) {
            avviso = "Spunta prima una classe: le materie vengono dopo.";
            bloccato = true;
        }
    }
    // Le spunte accese e non i codici: due «3» di corsi diversi sono due classi.
    const accese = (spunte[kind] || []).filter((e) => e.active).length;
    const riga = (v) => {
        const s = spuntaDi(kind, v);
        const on = !!(s && s.active);
        const id = `fm-cat-${kind}-${v.code}-${v.indirizzo || ""}`.replace(/[^A-Za-z0-9_-]/g, "_");
        const nome = v.label || v.code;
        const casella = nodo("input", {
            type: "checkbox", id, dataset: { spunta: "", kind, code: v.code, indirizzo: v.indirizzo || "" }, checked: on, disabled: bloccato,
        });
        const etichetta = nodo("label", { for: id }, nodo("code", {}, v.code), ` ${nome}`,
            v.group ? nodo("span", { class: "fm-text-11" }, ` · ${v.group}`) : null);
        const voce = nodo("div", { class: `fm-catalogo__voce${on ? " fm-catalogo__voce--on" : ""}`, dataset: { voce: v.code, indirizzo: v.indirizzo || "" } }, casella, etichetta);
        if (on) {
            voce.append(nodo("input", {
                type: "text", class: "fm-catalogo__nome", value: s.label, placeholder: nome, maxlength: "120",
                "aria-label": `Nome personale di ${nome}`, title: `Il nome che vedi tu; quello della scuola resta «${nome}»`,
                dataset: { nome: "", id: String(s.id) },
            }));
            if (kind === "materie") {
                voce.append(nodo("label", { class: "fm-catalogo__condivisa" },
                    nodo("input", { type: "checkbox", dataset: { sharedId: String(s.id) }, checked: !!s.shared_with_pool }), " Condivisa"));
            }
        }
        return voce;
    };
    const lista = nodo("div", { class: "fm-catalogo", dataset: { catalogoLista: "", kind } });
    for (const g of gruppi) {
        if (g.titolo) {
            lista.append(nodo("fieldset", { class: "fm-catalogo__gruppo" },
                nodo("legend", {}, g.titolo),
                g.nota ? nodo("p", { class: "fm-muted fm-text-12 fm-m-0", dataset: { catalogoNota: "" } }, g.nota) : null,
                ...g.voci.map(riga)));
        } else {
            lista.append(...g.voci.map(riga));
        }
    }
    // soloPresenti: replaceChildren scrive come testo quel che non è un nodo, e
    // senza avviso compariva la parola «null» sotto il conto (fino al 14/9/2026).
    panelEl.replaceChildren(...soloPresenti(
        nodo("p", { class: "fm-muted fm-text-13", dataset: { catalogoConto: "" } },
            `${accese} su ${voci.length} ${NOMI_KIND[kind]} della scuola: spunta quelli che ti riguardano.`),
        avviso ? nodo("p", { class: "fm-catalogo__avviso fm-text-13", dataset: { catalogoAvviso: "" } }, avviso) : null,
        lista,
    ));
    panelEl.querySelectorAll("[data-spunta]").forEach((cb) => {
        cb.addEventListener("change", () => spunta(cb.dataset.kind, { code: cb.dataset.code, indirizzo: cb.dataset.indirizzo || null }, cb.checked, cb));
    });
    panelEl.querySelectorAll("[data-nome]").forEach((inp) => {
        inp.addEventListener("change", () => salvaNome(parseInt(inp.dataset.id, 10), inp.value.trim(), inp));
    });
    panelEl.querySelectorAll("[data-shared-id]").forEach((cb) => {
        cb.addEventListener("change", () => toggleShare(parseInt(cb.dataset.sharedId, 10), cb.checked, cb));
    });
}

/**
 * Accende o spegne una voce della scuola per il docente. Una voce mai
 * spuntata si aggiunge (e' la POST che crea la riga di relazione); una gia'
 * spuntata si accende o si spegne con l'update. Se il server dice di no la
 * casella torna com'era.
 */
async function spunta(kind, voce, on, cb) {
    if (!activeInstituteId) {
        if (cb) cb.checked = !on;
        notify("Curriculum", "error", "Nessun istituto attivo", 3000);
        return;
    }
    const code = voce.code;
    const s = spuntaDi(kind, voce);
    const v = (vocabolario[kind] || []).find((x) => (kind === "classi" ? chiaveClasse(x) === chiaveClasse(voce) : x.code === code));
    const nome = (v && v.label) || code;
    const csrf = await getCsrf();
    const fd = new FormData();
    fd.set("_csrf", csrf);
    let url;
    if (s) {
        url = `/api/teacher/curriculum/${s.id}/update`;
        fd.set("active", on ? "true" : "false");
    } else if (on) {
        url = `/api/teacher/curriculum/${kind}`;
        fd.set("code", code);
        fd.set("label", nome);
        fd.set("group", (v && v.group) || "");
        fd.set("active", "true");
        fd.set("institute_id", String(activeInstituteId));
        // ADR-042 — un anno si spunta nel suo corso.
        if (kind === "classi" && voce.indirizzo) fd.set("indirizzo", voce.indirizzo);
    } else {
        return;
    }
    let j;
    try {
        j = await fetchJson(url, { method: "POST", headers: { "X-CSRF-Token": csrf }, body: fd });
    } catch (e) {
        if (cb) cb.checked = !on;
        notifyFetchError("Curriculum", e);
        return;
    }
    if (!j.ok) {
        if (cb) cb.checked = !on;
        notify("Curriculum", "error", `Errore: ${j.error || "richiesta non riuscita"}`, 0);
        return;
    }
    notify("Curriculum", "ok", on ? `✓ Spuntata: ${nome}` : `Spenta: ${nome}`, 2000);
    await loadCurriculumAndPivot();
    segnalaCurriculumCambiato();
}

/** Il nome personale di una voce: vuoto = quello della scuola. */
async function salvaNome(entryId, label, inp) {
    const csrf = await getCsrf();
    const fd = new FormData();
    fd.set("label", label || (inp && inp.placeholder) || "");
    fd.set("_csrf", csrf);
    let j;
    try {
        j = await fetchJson(`/api/teacher/curriculum/${entryId}/update`, {
            method: "POST", headers: { "X-CSRF-Token": csrf }, body: fd,
        });
    } catch (e) { notifyFetchError("Curriculum", e); return; }
    if (!j.ok) {
        notify("Curriculum", "error", `Errore: ${j.error || "richiesta non riuscita"}`, 0);
        return;
    }
    notify("Curriculum", "ok", "✓ Nome salvato", 2000);
    await loadCurriculumAndPivot();
    segnalaCurriculumCambiato();
}

async function toggleShare(entryId, enabled, cb) {
    const csrf = await getCsrf();
    const fd = new FormData();
    fd.set("shared_with_pool", enabled ? "true" : "false");
    fd.set("_csrf", csrf);
    let j;
    try {
        j = await fetchJson(`/api/teacher/curriculum/${entryId}/update`, {
            method: "POST", headers: { "X-CSRF-Token": csrf }, body: fd,
        });
    } catch (e) { cb.checked = !enabled; notifyFetchError("Curriculum", e); return; }
    if (j.ok) {
        notify("Curriculum", "ok", enabled ? "✓ Materia condivisa nel pool" : "✓ Condivisione disattivata", 2500);
        await loadCurriculumAndPivot({ skipEditor: true });
        segnalaCurriculumCambiato();
    } else {
        cb.checked = !enabled; // rollback UI
        notify("Curriculum", "error", `Errore: ${j.error || "richiesta non riuscita"}`, 0);
    }
}

/* Tab switching curriculum — pattern ARIA tabs (WCAG 4.1.2): aria-selected,
   roving tabindex, attivazione click + frecce ←/→/Home/Fine. */
const _currTablist = document.getElementById("fm-curr-tabs");
function activateCurrTab(btn, focus = false) {
    if (!btn) return;
    const kind = btn.dataset.kind;
    _currTablist.querySelectorAll(".fm-subtab").forEach(b => {
        const on = b === btn;
        b.classList.toggle("fm-subtab--active", on);
        b.setAttribute("aria-selected", on ? "true" : "false");
        b.tabIndex = on ? 0 : -1;   // roving tabindex
    });
    document.querySelectorAll(".fm-curr-panel").forEach(p => {
        // fm-d-none vive in @utilities (display:none, no !important):
        // impostare style.display="" NON basta perché la classe resta e
        // continua a nascondere il pannello. Toggle della classe.
        p.classList.toggle("fm-d-none", p.dataset.panel !== kind);
        p.style.display = "";
    });
    if (focus) btn.focus();
}
_currTablist.addEventListener("click", (e) => {
    const btn = e.target.closest(".fm-subtab");
    if (btn) activateCurrTab(btn);
});
_currTablist.addEventListener("keydown", (e) => {
    const tabs = Array.from(_currTablist.querySelectorAll(".fm-subtab"));
    const i = tabs.indexOf(document.activeElement);
    if (i < 0) return;
    let j = null;
    if (e.key === "ArrowRight" || e.key === "ArrowDown") j = (i + 1) % tabs.length;
    else if (e.key === "ArrowLeft" || e.key === "ArrowUp") j = (i - 1 + tabs.length) % tabs.length;
    else if (e.key === "Home") j = 0;
    else if (e.key === "End") j = tabs.length - 1;
    if (j !== null) { e.preventDefault(); activateCurrTab(tabs[j], true); }
});


document.getElementById("fm-profile-add-btn").addEventListener("click", addInstitute);
document.getElementById("fm-profile-search").addEventListener("keydown", e => {
    if (e.key === "Enter") { e.preventDefault(); addInstitute(); }
});

await Promise.all([loadCurrent(), loadAllInstitutes()]);
await loadCurriculumAndPivot();
// Piano classi, C — credenziali di classe: la porta degli studenti negli
// scenari 1 e 2. L'istituto attivo viene letto al momento della creazione.
const credenziali = initTeacherCredentials({ getInstituteId: () => activeInstituteId });
// ADR-044 — le materie dell'etichetta sono quelle spuntate nell'istituto attivo:
// cambiando le spunte nel curriculum qui sotto, le caselle del modulo seguono.
document.addEventListener("fm:curriculum-changed", () => { credenziali.ricaricaMaterie(); });

// G22.S20 v2.C2 — Refresh tabella + pivot quando l'utente cambia
// istituto attivo dalla sidebar (event globale da AppState wireIstitutoSelector).
document.addEventListener("fm:active-institute-changed", async (ev) => {
    // 1) Feedback ISTANTANEO della label "istituto attivo" dal dettaglio
    //    evento (code) + l'elenco istituti già caricato, senza attendere la
    //    rete: l'utente vede subito riflesso il cambio di selettore.
    const code = ev?.detail?.code || "";
    const row = allInstitutes.find(i => i.code === code);
    const el = document.getElementById("fm-curr-active-inst");
    if (el && row) {
        el.innerHTML = `📍 Stai gestendo: <strong>${escapeHtml(row.name || row.header_label || "")}</strong> <code class="fm-mono-muted fm-text-11">${escapeHtml(row.code || "")}</code> — per un altro istituto, cambialo dal menu <em>Istituto</em> nella sidebar.`;
    }
    // 2) Ricarica SEQUENZIALE: loadCurrent aggiorna activeInstituteId (+ label
    //    definitiva), POI loadCurriculumAndPivot ricarica i pannelli col NUOVO
    //    istituto. In parallelo (Promise.all) loadCurriculumAndPivot leggeva
    //    activeInstituteId ancora vecchio → race, pannelli stantii.
    await loadCurrent();
    await loadCurriculumAndPivot();
    // ADR-044 — e le materie offerte nel modulo delle credenziali, dopo che
    // activeInstituteId è quello nuovo.
    await credenziali.ricaricaMaterie();
});

// G22.S25 — Gestione gruppi spostata in Dashboard pool tab (vedi dashboard.php).
})();
