/**
 * Phase 25.A3 — Estratto da section-edit-mode.js (1003 LOC → 4 moduli).
 *
 * Inline actions ✏🗑👁📥 su ogni <li> + .fm-section-add "+ Nuovo" per
 * sezione (esercizi/verifiche/lab/mappa/risdoc/bes/strcomp).
 *
 * Esposto:
 *   - bindSectionAddButtons(sidepage, fallbackType)
 *   - addInlineItemActions(sidepage, type)
 *   - removeInlineItemActions(sidepage)
 *
 * Wire handlers privati:
 *   - wireTemplateAdminActions (super_admin shortcut su template istituzionali)
 *   - wireInstanceActions (rename/delete/reset istanza fork)
 *   - wireItemActions (edit/delete/visibility/export teacher_content)
 */

import { byKey as sidepageByKey } from "./sidepage-registry.js";
import { fetchCsrf, wafFetch } from "../core/dom-utils.js";
import { pacchettoDallaRisposta, scaricaPacchetto } from "../core/pacchetto.js";
import { openModal, fetchRow, deleteContent, setVisibility, refreshSidepage } from "./sidepage-modal-content.js";

export function bindSectionAddButtons(sidepage, fallbackType) {
    // 19/9/2026 — difesa per un «+» di domani: la classe fm-can-edit la mette
    // il server solo a docenti e amministratori (views/layout/app.php). Senza,
    // un «+» disegnato per sbaglio resta un bottone che non apre niente. Le
    // scritture le chiude comunque il server (auth + role:teacher: 401 a chi
    // non ha un account, 403 allo studente con account).
    if (!document.body.classList.contains("fm-can-edit")) return;
    sidepage.querySelectorAll(".fm-section-add").forEach((btn) => {
        if (btn.dataset.fmSecAddBound === "1") return;
        btn.dataset.fmSecAddBound = "1";
        btn.addEventListener("click", (e) => {
            e.preventDefault();
            e.stopPropagation();
            const t = btn.dataset.fmType || fallbackType;
            const cat = btn.dataset.fmPreCategory || "";
            const subj = btn.dataset.fmSubj || "";
            if (!t) return;
            // ADR-027 Step 5-6 — passa la sezione di creazione + i tipi ammessi:
            // il modal mostra un selettore tipo se >1 e invia section_key allo
            // store (validazione + ancoraggio section_id).
            const secKey = sidepage?.dataset?.sidepage || "";
            const def = secKey ? sidepageByKey(secKey) : null;
            const allowedTypes = def?.allowedTypes && def.allowedTypes.length ? def.allowedTypes : [t];
            // ADR-024 — modal UNICO cross-categoria. Niente più branching su
            // supportsFork: ogni "+ Nuovo" apre lo stesso modal, che offre
            // fork template / PT libero / stile esercizi (default per categoria).
            openModal({ type: t, mode: "create", sidepage, preCategory: cat, preSubject: subj,
                        sectionKey: secKey, allowedTypes,
                        templateOrigin: def?.templateOrigin || "",
                        templateGroups: def?.templateGroups || [] });
        });
    });
}

export function addInlineItemActions(sidepage, type) {
    // Phase 24.68 + 24.70 — actions centralizzate. 3 kind di item:
    //   • <li[data-content-id]>   → teacher_content
    //   • <li[data-instance-key]>  → istanze fork risdoc/bes
    //   • <li[data-template-id]>   → template istituzionali (super_admin only)
    const isSuperAdmin = !!window.FM?.user?.is_super_admin;
    const sel = isSuperAdmin
        ? "ul.fm-db-block li[data-content-id], ul.fm-db-block li[data-instance-key], ul.fm-db-block li[data-template-id]"
        : "ul.fm-db-block li[data-content-id], ul.fm-db-block li[data-instance-key]";
    sidepage.querySelectorAll(sel).forEach((li) => {
        if (li.classList.contains("fm-db-head")) return;
        if (li.querySelector(".fm-item-actions")) return;
        const a = li.querySelector("a");
        if (!a) return;
        const isInstance = li.hasAttribute("data-instance-key");
        const isTemplate = !isInstance && li.hasAttribute("data-template-id") && !li.hasAttribute("data-content-id");
        const actions = document.createElement("span");
        actions.className = "fm-item-actions";
        if (isTemplate) {
            actions.innerHTML = `
                <button type="button" class="fm-item-btn fm-item-admin-edit" title="Modifica (titolo / topic del template)">✎</button>
                <button type="button" class="fm-item-btn fm-item-export-tpl" title="Scarica ZIP TeX template (richiede body_pt o tex_file)">📥</button>`;
            // G22.S12 — D/C/R role chip inline (solo per origin=risdoc).
            // Persistito in localStorage per-template-id; preselezionato 'D'.
            const isRisdoc = li.dataset.origin === "risdoc";
            if (isRisdoc) {
                const tplId = parseInt(li.dataset.templateId, 10);
                const stored = (() => {
                    try { return localStorage.getItem(`fm.risdoc.role.${tplId}`) || "D"; }
                    catch { return "D"; }
                })();
                const roleEl = document.createElement("span");
                roleEl.className = "fm-item-role";
                roleEl.dataset.role = stored;
                roleEl.title = "Ruolo: D=Docente · C=Coordinatore · R=Referente";
                roleEl.innerHTML = ["D","C","R"].map(r =>
                    `<button type="button" class="fm-item-role__btn${r === stored ? ' fm-item-role__btn--active' : ''}" data-role="${r}">${r}</button>`
                ).join("");
                // 2026-05-27 — collassato: mostra SOLO la lettera attiva. Click
                // sulla lettera attiva → espande (D/C/R); click su una lettera
                // quando espanso → assegna il ruolo e ricollassa.
                roleEl.addEventListener("click", (ev) => {
                    const b = ev.target.closest("button[data-role]");
                    if (!b) return;
                    ev.preventDefault(); ev.stopPropagation();
                    if (!roleEl.classList.contains("fm-item-role--expanded")) {
                        roleEl.classList.add("fm-item-role--expanded");
                        return; // primo click: solo espandi, non cambiare ruolo
                    }
                    const r = b.dataset.role;
                    try { localStorage.setItem(`fm.risdoc.role.${tplId}`, r); } catch {}
                    roleEl.dataset.role = r;
                    roleEl.querySelectorAll(".fm-item-role__btn").forEach(x => {
                        x.classList.toggle("fm-item-role__btn--active", x.dataset.role === r);
                    });
                    roleEl.classList.remove("fm-item-role--expanded"); // ricollassa sulla scelta
                    // Aggiorna anche href del link sibling per portarsi role
                    const a = li.querySelector("a[href^='/risdoc/view/']");
                    if (a) {
                        const u = new URL(a.href, window.location.origin);
                        u.searchParams.set("role", r);
                        a.href = u.pathname + u.search;
                    }
                });
                // Setup iniziale del link con role corrente
                const a = li.querySelector("a[href^='/risdoc/view/']");
                if (a) {
                    const u = new URL(a.href, window.location.origin);
                    u.searchParams.set("role", stored);
                    a.href = u.pathname + u.search;
                }
                // 2026-05-27 — idempotenza: rimuovi eventuali chip residui prima
                // di inserirne uno nuovo (entrare/uscire da edit mode non deve
                // moltiplicare i bottoni ruolo).
                li.querySelectorAll(":scope > .fm-item-role").forEach((n) => n.remove());
                li.insertBefore(roleEl, li.firstChild);
            }
        } else if (isInstance) {
            actions.innerHTML = `
                <button type="button" class="fm-item-btn fm-item-edit" title="Rinomina istanza">✎</button>
                <button type="button" class="fm-item-btn fm-item-del"  title="Elimina istanza (i tuoi override andranno persi)">🗑</button>
                <button type="button" class="fm-item-btn fm-item-reset" title="Reset istanza al template istituzionale (mantiene la label)">⟲</button>`;
        } else {
            // Phase G7 — bottone export 📥 mostrato SOLO se has_body_pt.
            // Quando c'è un corpo da esportare lo decide il server, con una
            // regola sola per tutti i caricamenti (App\Support\RigheDellaBarra:
            // non le mappe, non i segnaposto); qui si legge e basta. Il nome
            // accessibile dice che cosa si scarica (19/9/2026).
            // 👁 fm-item-vis rimosso: la Visibilita' e' gia' gestita dal
            // modal teacher_content (select Bozza/Pubblicato/Archiviato).
            const hasBodyPt = li.dataset.hasBodyPt === "1";
            actions.innerHTML = `
                <button type="button" class="fm-item-btn fm-item-edit" title="Modifica">✎</button>
                <button type="button" class="fm-item-btn fm-item-del"  title="Elimina">🗑</button>`;
            if (hasBodyPt) {
                const exportBtn = document.createElement("button");
                exportBtn.type = "button";
                exportBtn.className = "fm-item-btn fm-item-export";
                exportBtn.title = "Scarica ZIP TeX";
                exportBtn.setAttribute("aria-label", `Scarica ZIP TeX «${titoloDellaVoce(li)}»`);
                exportBtn.textContent = "📥";
                actions.appendChild(exportBtn);
            }
            // D/C/R chip per teacher_content che hanno data-doc-roles
            // (impostato server-side da ContentStudyController via metadata).
            // Multi-role display: tutte le lettere sono mostrate side-by-side
            // — pure visualizzazione (no toggle, sola lettura).
            const docRoles = (li.dataset.docRoles || "").toUpperCase().replace(/[^DCR]/g, "");
            if (docRoles) {
                const roleEl = document.createElement("span");
                roleEl.className = "fm-item-role fm-item-role--readonly fm-item-role--multi";
                roleEl.dataset.role = docRoles; // es. "DCR"
                roleEl.title = `Ruoli assegnati: ${  docRoles.split("").map(r => ({
                    D: "D=Docente", C: "C=Coordinatore", R: "R=Referente",
                }[r])).join(" · ")}`;
                roleEl.innerHTML = docRoles.split("").map(r =>
                    `<span class="fm-item-role__btn fm-item-role__btn--active" data-role="${r}">${r}</span>`
                ).join("");
                li.querySelectorAll(":scope > .fm-item-role").forEach((n) => n.remove());
                li.insertBefore(roleEl, li.firstChild);
            }
        }
        // Phase G7 — actions all'INIZIO del <li> (prima di numarg + link):
        // <li><actions><numarg>1.0</numarg> <a>Title</a></li>
        li.insertBefore(actions, li.firstChild);
        if (isTemplate) {
            wireTemplateAdminActions(actions, li, type);
        } else if (isInstance) {
            wireInstanceActions(actions, li, type);
        } else {
            wireItemActions(actions, li, type);
        }
    });
}

/**
 * Il titolo della voce com'è scritto nel collegamento, senza il testo per i
 * lettori di schermo del segno della bozza (segno-bozza.js).
 *
 * @param {HTMLElement} li
 * @returns {string}
 */
function titoloDellaVoce(li) {
    const a = li.querySelector(":scope > a") || li.querySelector("a");
    if (!a) return "";
    const copia = /** @type {HTMLElement} */ (a.cloneNode(true));
    copia.querySelectorAll(".fm-sr-only, .fm-item-bozza").forEach((n) => n.remove());
    return (copia.textContent || "").replace(/\s+/g, " ").trim();
}

export function removeInlineItemActions(sidepage) {
    sidepage.querySelectorAll(".fm-item-actions").forEach((n) => n.remove());
    // 2026-05-27 — rimuovi anche i chip ruolo D/C/R: prima restavano nel <li>
    // e ogni toggle edit-mode ne aggiungeva un altro (bottoni moltiplicati).
    sidepage.querySelectorAll(".fm-item-role").forEach((n) => n.remove());
}

/**
 * Phase 24.70 — handler per super_admin shortcut sui template istituzionali.
 * Phase 24.74 — ✎ apre lo STESSO modal degli altri item (NON più l'editor
 * schema in una nuova pagina): si editano titolo (argomento) e topic (num_arg)
 * del template. Lo schema vero resta editabile solo da /admin/templates.
 * 📥 chiama l'endpoint export del template.
 */
function wireTemplateAdminActions(actions, li, type) {
    const tplId = parseInt(li.dataset.templateId, 10);
    if (!tplId) return;

    actions.querySelector(".fm-item-admin-edit")?.addEventListener("click", async (ev) => {
        ev.preventDefault(); ev.stopPropagation();
        // Costruisci una row dai dati reali del template (meta) per il modal.
        let tpl = {};
        try {
            const res = await fetch(`/api/admin/risdoc/templates/${tplId}`, {
                credentials: "same-origin", headers: { Accept: "application/json" },
            });
            const j = await res.json();
            tpl = j.template || {};
        } catch (_) { /* fallback ai dati nel DOM */ }
        const row = {
            id: tplId,
            title: tpl.argomento || (li.querySelector("a")?.textContent || "").trim(),
            // `data-topic` prima del testo del riquadro: dal 20/9/2026 il
            // riquadro non si disegna quando ripeterebbe il titolo (vedi
            // db-sidepage.js, itemLiHtml), mentre l'attributo c'è sempre. Le
            // voci dei modelli risdoc non lo portano, e per loro vale ancora
            // il ripiego sul testo.
            topic: (tpl.num_arg != null
                ? String(tpl.num_arg)
                : (li.dataset?.topic || li.querySelector(".fm-numarg")?.textContent || "")).trim(),
            _isTemplate: true,
            metadata: { layout: "custom" }, // template = PT → "Personalizzabile"
        };
        openModal({ type, mode: "edit", sidepage: li.closest(".fm-sb-panel"), row });
    });

    actions.querySelector(".fm-item-export-tpl")?.addEventListener("click", async (ev) => {
        ev.preventDefault(); ev.stopPropagation();
        try {
            const csrf = await fetchCsrf();
            // 21/9/2026 — il pacchetto arriva nel corpo della risposta. Questo
            // è il bottone della RIGA: qui un documento aperto non c'è, quindi
            // il pacchetto esce col modello vuoto, ed è quello che dice di fare.
            const risposta = await wafFetch(`/api/risdoc/templates/${tplId}/export`, {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ _csrf: csrf }).toString(),
            });
            scaricaPacchetto(await pacchettoDallaRisposta(risposta, `modello-${tplId}.zip`));
            window.FM?.ToastManager?.show?.("success", "ZIP pronto", "Download avviato", 2500);
        } catch (e) {
            window.FM?.ToastManager?.show?.("error", "Errore", e.message, 4000);
        }
    });
}

/**
 * Phase 24.68 — handlers per <li[data-instance-key]> istanze fork.
 * Operazioni: rename label, delete (drop tutti gli override + marker),
 * reset (delete override mantenendo il marker → istanza vuota).
 */
function wireInstanceActions(actions, li, type) {
    const tplId = parseInt(li.dataset.templateId, 10);
    const instKey = li.dataset.instanceKey;
    if (!tplId || !instKey) return;

    actions.querySelector(".fm-item-edit")?.addEventListener("click", async (ev) => {
        ev.preventDefault(); ev.stopPropagation();
        const a = li.querySelector("a");
        const cur = a?.textContent?.trim() || "";
        const next = await window.FM.Dialog.prompt("Nuova etichetta:", cur);
        if (next == null || !next.trim() || next === cur) return;
        try {
            const csrf = await fetchCsrf();
            const r = await fetch(`/api/risdoc/templates/${tplId}/instances/${encodeURIComponent(instKey)}/rename`, {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ _csrf: csrf, instance_label: next.trim() }).toString(),
            });
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            refreshSidepage(type);
        } catch (e) {
            window.FM?.ToastManager?.show?.("error", "Rename fallito", e.message, 4000);
        }
    });

    actions.querySelector(".fm-item-del")?.addEventListener("click", async (ev) => {
        ev.preventDefault(); ev.stopPropagation();
        const cur = li.querySelector("a")?.textContent?.trim() || instKey;
        if (!await window.FM.Dialog.confirm(`Eliminare l'istanza "${cur}"?\n\nTutti i tuoi override saranno persi (il template istituzionale resta intatto).`)) return;
        try {
            const csrf = await fetchCsrf();
            const r = await fetch(`/api/risdoc/templates/${tplId}/instances/${encodeURIComponent(instKey)}/delete`, {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ _csrf: csrf }).toString(),
            });
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            refreshSidepage(type);
        } catch (e) {
            window.FM?.ToastManager?.show?.("error", "Delete fallito", e.message, 4000);
        }
    });

    actions.querySelector(".fm-item-reset")?.addEventListener("click", async (ev) => {
        ev.preventDefault(); ev.stopPropagation();
        if (!await window.FM.Dialog.confirm("Reset al template istituzionale: tutti i tuoi override su questa istanza andranno persi (il marker resta).")) return;
        try {
            const csrf = await fetchCsrf();
            const list = await (await fetch(`/api/risdoc/templates/${tplId}/instances`, { credentials: "same-origin" })).json();
            const inst = (list.instances || []).find(i => i.instance_key === instKey);
            if (!inst) return;
            const r = await fetch(`/api/risdoc/templates/${tplId}/instances/${encodeURIComponent(instKey)}/delete`, {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ _csrf: csrf }).toString(),
            });
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            await fetch(`/api/risdoc/templates/${tplId}/instances`, {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({
                    _csrf: csrf,
                    instance_label: inst.instance_label || instKey,
                }).toString(),
            });
            window.FM?.ToastManager?.show?.("success", "Reset OK", "Istanza ripristinata al template istituzionale", 3000);
            refreshSidepage(type);
        } catch (e) {
            window.FM?.ToastManager?.show?.("error", "Reset fallito", e.message, 4000);
        }
    });
}

// I tipi di teacher_content (TeacherContentRepository::TYPES) e, per i tipi
// grezzi delle sidepage, la famiglia a cui appartengono.
const TIPI_CONTENUTO = ["mappa", "esercizio", "verifica", "document"];
const FAMIGLIA_DEL_TIPO = { lab: "esercizio", risdoc: "document", bes: "document" };

/**
 * Il tipo con cui si apre ✎: quello del contenuto, non quello della sidepage
 * (15/9/2026, segnalato dall'utente). Ogni sidepage crea qualsiasi tipo di
 * documento (ADR-027), e una mappa creata nel Laboratorio, che ha tipo
 * «esercizio», apriva «Modifica esercizio»: senza il link e senza l'editor
 * drawio. Il tipo della sidepage resta solo quando è della stessa famiglia del
 * contenuto: «risdoc» e «bes» sono documenti, e il modale li usa per tenere la
 * categoria.
 *
 * @param {{content_type?: string}|null} row
 * @param {string} tipoSidepage
 * @returns {string}
 */
export function tipoDaModificare(row, tipoSidepage) {
    const suo = row?.content_type;
    if (!TIPI_CONTENUTO.includes(suo)) return tipoSidepage;
    return (FAMIGLIA_DEL_TIPO[tipoSidepage] || tipoSidepage) === suo ? tipoSidepage : suo;
}

function wireItemActions(actions, li, type) {
    const id = parseInt(li.dataset.contentId, 10);
    actions.querySelector(".fm-item-edit")?.addEventListener("click", async (ev) => {
        ev.preventDefault(); ev.stopPropagation();
        const row = await fetchRow(id);
        if (!row) return;
        // Phase G7 — apri SEMPRE il modal teacher_content standard
        // (titolo/topic/visibility/...). Per le mappe drawio con
        // map_blob_path il modal NON include il drawio editor inline:
        // l'utente accede al drawio editor via apposito bottone ✎ drawio
        // che apparira' nel modal stesso (vedi buildModalHtml in
        // sidepage-modal-content.js, fascia mappa-edit).
        openModal({ type: tipoDaModificare(row, type), mode: "edit", sidepage: li.closest(".fm-sb-panel"), row });
    });
    actions.querySelector(".fm-item-del")?.addEventListener("click", async (ev) => {
        ev.preventDefault(); ev.stopPropagation();
        const title = li.querySelector("a")?.textContent?.trim() || "item";
        if (!await window.FM.Dialog.confirm(`Eliminare "${title}"?`)) return;
        const ok = await deleteContent(id);
        if (!ok) return; // delete fallito → lascia la lista intatta
        // Update chirurgico: rimuovi solo questa <li> (no reload → no flicker).
        // Fallback a refreshSidepage se la <li> non è gestita dal loader db.
        const sidepageKey = li.closest(".fm-sb-panel")?.id?.replace(/^fm-sp-/, "") || "";
        if (!window.FM?.dbSidepageRemoveItem?.({ sidepageKey, id })) {
            refreshSidepage(type);
        }
    });
    actions.querySelector(".fm-item-vis")?.addEventListener("click", async (ev) => {
        ev.preventDefault(); ev.stopPropagation();
        const current = li.querySelector(".fm-item-vis");
        const toPub = !current.classList.contains("fm-is-published");
        await setVisibility(id, toPub ? "publish" : "unpublish");
        refreshSidepage(type);
    });
    // Phase 24.37 — export ZIP TeX da metadata.body_pt
    actions.querySelector(".fm-item-export")?.addEventListener("click", async (ev) => {
        ev.preventDefault(); ev.stopPropagation();
        try {
            const csrf = await fetchCsrf();
            const risposta = await wafFetch(`/api/teacher/content/${id}/export`, {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ _csrf: csrf }).toString(),
            });
            let pacchetto;
            try {
                pacchetto = await pacchettoDallaRisposta(risposta, `documento-${id}.zip`);
            } catch (errore) {
                // Il messaggio del server, non «HTTP 400»: questo il docente lo
                // legge parola per parola e gli dice che cosa fare.
                const msg = String(errore.message) === "no_body_pt"
                    ? "Questo item non ha un body PT da esportare. Aggiungilo via ✎ Modifica → 📝 Contenuto avanzato."
                    : String(errore.message);
                window.FM?.ToastManager?.show?.("error", "Export fallito", msg, 4500);
                return;
            }
            scaricaPacchetto(pacchetto);
            window.FM?.ToastManager?.show?.("success", "ZIP pronto", "Download avviato.", 2500);
        } catch (e) {
            window.FM?.ToastManager?.show?.("error", "Errore", e.message, 4000);
        }
    });
}

// Resolver helper esposto per l'orchestrator (typeForSidepageKey).
export function typeForSidepageKey(key) {
    return sidepageByKey(key)?.type || null;
}
