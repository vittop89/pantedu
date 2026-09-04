import { addInlineItemActions, removeInlineItemActions } from "./sidepage-inline-actions.js";
import { fetchJson, fetchCsrf } from "../core/dom-utils.js";

/**
 * Phase G8 — Render verifica_documents (TEX/PDF) nella sidepage Verifiche.
 *
 * Hook into l'event `fm:db-sidepage-rendered` emesso da db-sidepage.js
 * dopo aver renderizzato il loader category-grouped per type=verifica.
 *
 * Aggiunge fm-db-block per-materia (head-label = nome materia) con
 * gli item da /api/verifica/list. Ogni item:
 *   - <span class="fm-numarg">{id}</span>
 *   - <a class="linkref" href="/studio/verifica-doc/{id}">{title}</a>
 *   - <button class="fm-vd-tex" title="Scarica .tex">📄</button>
 *   - badge PDF (✅ se has_pdf, 📥 se no)
 *
 * Click sul link:
 *   - has_pdf: navigate /studio/verifica-doc/{id} (G8.8 render PDF iframe)
 *   - !has_pdf: open popup PDF upload (G8.8)
 *
 * Re-render automatico su `fm:verifica-saved` (post SalvaTEX).
 */

const SIDEPAGE_KEY = "verif";
const HOST_CLASS   = "fm-vd-host";        // wrapper dei blocchi per-materia
const BLOCK_CLASS  = "fm-vd-block";       // marker per re-render idempotente

/** G20.5 — query string scope-aware: indirizzo + classe (+ materia opzionale).
 *  Senza questi filtri la sidebar mostrava verifiche di classi diverse
 *  mischiate (es. 1a vs 2a scientifico). Lasciamo materia non bindato a
 *  #sel-mater perche' la sidepage e' raggruppata per materia: il filtro
 *  materia agisce gia' lato render (block per materia). */
function readSel(id) {
    const el = document.getElementById(id);
    return el && el.value ? String(el.value).trim() : "";
}

async function fetchVerifiche() {
    try {
        const qs = new URLSearchParams();
        const ind = readSel("sel-iis");
        const cls = readSel("sel-cls");
        if (ind) qs.set("indirizzo", ind);
        if (cls) qs.set("classe", cls);
        // Phase 25.Q.15 — endpoint per-ruolo:
        //   - teacher/admin → /api/verifica/list (proprio catalog completo)
        //   - student/guest → /api/study/verifica/list (solo shared_with_pool=1
        //     dello stesso istituto, filtrato per sezione)
        const role = document.body?.dataset?.fmRole || "guest";
        const base = (role === "teacher" || role === "admin")
            ? "/api/verifica/list"
            : "/api/study/verifica/list";
        const url = qs.toString() ? `${base}?${qs}` : base;
        const j = await fetchJson(url);
        if (!j?.ok || !Array.isArray(j.items)) return null;
        return j;
    } catch (_) {
        return null;
    }
}

/** G19.43 — Strip suffisso varianti dal title: "q — A_SOL" → "q". */
function stripVariantSuffix(title) {
    return String(title || "")
        .replace(/\s*[—-]\s*[AB]_(SOL|NOR|DSA|DIS)\s*$/u, "")
        .trim();
}

/** G19.44 — Dedup per TITLE (no batch_id): N save-batch dello stesso
 *  title producono N batch DB ma UN solo link in sidepage. Il modal
 *  poi espande TUTTI i doc del title in versioni per kind SOL/NOR/DSA/DIS.
 *  Tiene il doc piu' RECENTE come representative (created_at desc). */
function dedupeByTitle(items) {
    const seen = new Map();
    for (const it of items) {
        const baseTitle = stripVariantSuffix(it.title);
        const key = `${(it.materia || "").toLowerCase()}::${baseTitle.toLowerCase()}`;
        if (!seen.has(key)) {
            seen.set(key, { ...it, title: baseTitle });
        }
    }
    return [...seen.values()];
}

function groupByMateria(items) {
    const map = new Map();
    for (const it of items) {
        const k = it.materia || "—";
        if (!map.has(k)) map.set(k, []);
        map.get(k).push(it);
    }
    // Sort items per materia by created_at desc + dedup by title
    for (const [k, arr] of map) {
        arr.sort((a, b) => String(b.created_at).localeCompare(String(a.created_at)));
        map.set(k, dedupeByTitle(arr));
    }
    return map;
}

function materiaLabel(code) {
    // Risolve label leggendo #sel-mater option text; fallback al code.
    const opt = document.querySelector(`#sel-mater option[value="${CSS.escape(code)}"]`);
    return (opt?.textContent || "").trim() || code;
}

function buildBlock(materiaCode, rows) {
    const ul = document.createElement("ul");
    ul.className = `fm-db-block ${BLOCK_CLASS}`;
    ul.dataset.type = "verifica";
    ul.dataset.category = "VERIFICHE";
    ul.dataset.section = "VERIFICHE";
    ul.dataset.sectionKind = "category";
    ul.dataset.materia = materiaCode;

    const head = document.createElement("li");
    head.className = "fm-db-head";
    const label = document.createElement("span");
    label.className = "fm-db-head-label";
    label.textContent = materiaLabel(materiaCode);
    head.appendChild(label);

    // Phase 25.Q.16 — edit/add buttons emessi SOLO per teacher/admin
    // (body.fm-can-edit). Studente non deve vedere ✎/➕ in header materia.
    const canEdit = document.body?.dataset?.fmCanEdit === "1";
    if (canEdit) {
        // G19.26 — uniformato al pattern db-sidepage: edit-section + section-add
        // accanto al label. Edit toggle attiva inline actions (✎/🗑) per ogni li.
        const editBtn = document.createElement("button");
        editBtn.type = "button";
        editBtn.className = "fm-btn fm-btn--xs js-edit-section";
        editBtn.title = "Modifica sezione (rinomina/elimina verifiche)";
        editBtn.setAttribute("aria-label", "Modifica sezione");
        editBtn.dataset.section = "verifica";
        editBtn.dataset.action = "toggle-edit-section";
        editBtn.innerHTML = "<strong>✎</strong>";
        head.appendChild(editBtn);

        // `+ Nuovo` per sezione: focus + click su SalvaTEX in topbar (per
        // creare una verifica serve la selezione esercizi + verTitle).
        const addBtn = document.createElement("button");
        addBtn.type = "button";
        addBtn.className = "fm-btn fm-btn--xs fm-section-add fm-vd-section-add";
        addBtn.dataset.fmType = "verifica";
        addBtn.dataset.fmSubj = materiaCode;
        addBtn.title = `Crea nuova verifica per ${materiaLabel(materiaCode)} (apre SalvaTEX in topbar)`;
        addBtn.textContent = "➕";
        head.appendChild(addBtn);
    }

    ul.appendChild(head);

    if (!rows.length) {
        const empty = document.createElement("li");
        empty.className = "fm-muted";
        empty.style.fontSize = "11px";
        empty.textContent = "Nessuna verifica salvata";
        ul.appendChild(empty);
        return ul;
    }

    for (const r of rows) {
        const li = document.createElement("li");
        li.dataset.contentId = String(r.id);
        li.dataset.fmHasPdf  = r.has_pdf ? "1" : "0";
        // G19.26 — marker per delete handler custom (override standard
        // wireItemActions che chiamerebbe /api/teacher/content/{id}/delete).
        li.dataset.fmContentKind = "verifica";

        // G19.28 — sidepage row minima: [#id] [PDF] [link]. I bottoni
        // `.tex` (📄) e VSCode (📝) sono ora SOLO dentro il popup
        // `verifica-detail-modal.js` per ogni variante (SOL/NOR/DSA/DIS).
        // Il PDF prefix btn (sotto) apre il popup che ha anche il PDF picker.
        // G19.33 — niente `<span class="fm-item-actions">` empty: lo standard
        // `addInlineItemActions` (da sidepage-inline-actions.js) skippa li
        // con un .fm-item-actions gia' presente. Lasciandolo vuoto bloccava
        // l'iniezione delle azioni ✎/🗑 in edit mode.

        const num = document.createElement("span");
        num.className = "fm-numarg";
        num.textContent = `#${  r.id}`;

        // G19.23 — `PDF` button text-label SUBITO PRIMA del link (richiesta utente).
        // dataset.fmAction = "open-detail" (G19.24): apre popup centrale con
        // 4 slot SOL/NOR/DSA/DIS + lista TEX. Cambia da "upload-pdf" → "open-detail"
        // per consistency con il click sul link.
        const pdfBtn = document.createElement("button");
        pdfBtn.type = "button";
        pdfBtn.className = "fm-vd-pdf fm-btn fm-btn--xs";
        pdfBtn.dataset.fmAction = "open-detail";
        pdfBtn.dataset.fmId = String(r.id);
        pdfBtn.title = r.has_pdf
            ? `PDF caricato (${r.pdf_filename || ""}) — apri dettaglio`
            : "Apri dettaglio (carica PDF SOL/NOR/DSA/DIS)";
        pdfBtn.textContent = "PDF";

        const a = document.createElement("a");
        a.className = "linkref fm-vd-link";
        a.href = "#";  // G19.24 — click apre popup centrale, no navigate
        a.dataset.fmAction = "open-detail";
        a.dataset.fmId = String(r.id);
        a.dataset.fmHasPdf = r.has_pdf ? "1" : "0";
        a.textContent = r.title || "(senza titolo)";

        // G22.S25 — Indicator inline (read-only): mostra stato share senza
        // azione. Per modificare la condivisione, aprire il detail popup
        // (click PDF/link) che ha i bottoni 🤝/🔒 + 🎯 nella sezione dedicata.
        let shareInd = null;
        if (r.shared_with_pool) {
            shareInd = document.createElement("span");
            shareInd.className = "fm-vd-share-indicator";
            shareInd.textContent = "🤝";
            shareInd.title = "Condivisa con i colleghi (apri dettaglio per gestire)";
            shareInd.style.cssText = "background:rgba(34,197,94,0.18);padding:0 4px;border-radius:3px;font-size:11px";
        }

        if (shareInd) li.append(num, " ", pdfBtn, " ", a, " ", shareInd);
        else          li.append(num, " ", pdfBtn, " ", a);
        ul.appendChild(li);
    }

    return ul;
}

async function renderInto(panel) {
    if (!panel) return;
    const verifCat = panel.querySelector('.fm-risdoc-cat[data-category="VERIFICHE"]');

    // ── AREA UNIFICATA (richiesta utente) ────────────────────────────────
    // Prima: db-sidepage renderizzava il blocco CONTENUTI (documenti, "materia
    // test" / "Nessun contenuto") e noi aggiungevamo un blocco SEPARATO per le
    // verifiche salvate ("materia test" / "Nessuna verifica salvata") → due
    // "materia test". Ora le verifiche salvate vanno DENTRO lo stesso blocco
    // contenuti, sotto un divider "Verifiche salvate" → UNA sola area materia.
    // Fallback (no blocco contenuti in DOM): blocco separato come prima.
    const MERGED = "fm-vd-merged";
    const contentUl = verifCat
        ? verifCat.querySelector(`:scope > ul.fm-db-block:not(.${BLOCK_CLASS})`)
        : panel.querySelector(`ul.fm-db-block:not(.${BLOCK_CLASS})`);

    // Idempotente: rimuovi nostri inserimenti precedenti (merged + host legacy).
    panel.querySelectorAll(`.${MERGED}, .${HOST_CLASS}`).forEach(n => n.remove());

    // skeleton durante il fetch async
    const skeleton = document.createElement(contentUl ? "li" : "div");
    skeleton.className = `fm-vd-loading ${MERGED}`;
    skeleton.style.cssText = "padding:8px 14px;color:#888;font-size:11px;font-style:italic;text-align:center";
    skeleton.textContent = "Caricamento verifiche…";
    (contentUl || verifCat || panel).appendChild(skeleton);

    const data = await fetchVerifiche();
    skeleton.remove();
    if (!data) return;

    const grouped = groupByMateria(data.items);
    const allRows = [];
    for (const k of [...grouped.keys()].sort((a, b) => a.localeCompare(b))) {
        for (const r of (grouped.get(k) || [])) allRows.push(r);
    }

    if (contentUl) {
        // ── MERGE: tutto dentro il blocco contenuti ──
        contentUl.style.display = "";  // mai più nascosto (vecchio anti-flash)
        // togli "Nessun contenuto" se aggiungiamo verifiche
        if (allRows.length) {
            contentUl.querySelectorAll(":scope > li.fm-muted").forEach(n => {
                if (/Nessun contenuto/i.test(n.textContent || "")) n.remove();
            });
        }
        // NB: niente ➕ "nuova verifica" qui — l'inserimento delle verifiche è
        // automatico a partire dalla generazione (SalvaTEX) dalla sezione
        // esercizi. L'info è spiegata nell'infotip ⓘ della sezione Verifiche.
        // divider "Verifiche salvate"
        const div = document.createElement("li");
        div.className = `fm-vd-divider fm-muted ${MERGED}`;
        div.style.cssText = "font-size:10px;text-transform:uppercase;letter-spacing:.4px;opacity:.65;padding:6px 0 2px";
        div.textContent = "Verifiche salvate";
        contentUl.appendChild(div);
        if (!allRows.length) {
            const empty = document.createElement("li");
            empty.className = `fm-muted ${MERGED}`;
            empty.style.fontSize = "11px";
            empty.textContent = "Nessuna verifica salvata";
            contentUl.appendChild(empty);
        } else {
            // riusa buildBlock per costruire le righe, poi trasferiscile (skip header)
            const tmp = buildBlock(grouped.keys().next().value || "", allRows);
            tmp.querySelectorAll(":scope > li:not(.fm-db-head)").forEach(li => {
                li.classList.add(MERGED);
                contentUl.appendChild(li);
            });
        }
    } else {
        // ── FALLBACK: blocco separato (comportamento storico) ──
        const host = document.createElement("div");
        host.className = HOST_CLASS;
        (verifCat || panel).appendChild(host);
        if (grouped.size === 0) {
            const subj = document.getElementById("sel-mater")?.value || window.FM?.Curriculum?.firstCode("materie") || "";
            host.appendChild(buildBlock(subj, []));
        } else {
            for (const k of [...grouped.keys()].sort((a, b) => a.localeCompare(b))) {
                host.appendChild(buildBlock(k, grouped.get(k) || []));
            }
        }
    }

    // G19.32 — bind dei `.js-edit-section` appena renderizzati. La call
    // a `bindSidebarEditButtons` da `section-edit-mode.js` su evento
    // `fm:db-sidepage-rendered` avviene PRIMA del nostro append (le
    // listener si chiamano in ordine di registrazione). Senza questa
    // call manuale i nostri `.js-edit-section` resterebbero unbound
    // finche' un altro evento non triggera il re-bind.
    if (typeof window.FM?.bindSidebarEditButtons === "function") {
        window.FM.bindSidebarEditButtons();
    }
    // G20.7 — section-edit-mode chiama addInlineItemActions sincrono su
    // fm:db-sidepage-rendered, ma i nostri li[data-content-id] vengono
    // aggiunti async (dopo fetch). Risultato: senza .fm-item-actions sui
    // nostri li → niente bottoni ✎/🗑 in edit mode → delete TEX/PDF da
    // sidepage non funzionava. Iniettiamo qui in modo idempotente
    // (addInlineItemActions skippa li gia' decorati).
    removeInlineItemActions(panel);
    addInlineItemActions(panel, "verifica");
}

function isVerifSidepage(detail) {
    return detail?.sidepageKey === SIDEPAGE_KEY || detail?.type === "verifica";
}

function onSidepageRendered(ev) {
    if (!isVerifSidepage(ev.detail)) return;
    const panel = ev.detail?.sidepage
                || document.getElementById("fm-sp-verif")
                || document.querySelector('.fm-sb-panel[data-sidepage="verif"]');
    renderInto(panel);
}

function onVerificaSaved() {
    const panel = document.getElementById("fm-sp-verif")
               || document.querySelector('.fm-sb-panel[data-sidepage="verif"]');
    if (panel && panel.style.display !== "none") renderInto(panel);
}

/** G19.26 — Custom click delegation in capture phase per verifica li.
 *  Intercetta `.fm-item-del` (delete) sui li `[data-fm-content-kind=verifica]`
 *  PRIMA del listener bubble di sidepage-inline-actions.js (che postrebbe
 *  /api/teacher/content/{id}/delete — endpoint sbagliato per verifica_documents).
 *  Routing custom: POST /api/verifica/{id}/delete + re-render block.
 *
 *  Anche `.fm-item-edit` viene intercettato per evitare apertura del modal
 *  teacher_content (che e' per content rows, non per verifica_documents).
 *  Per ora: rename inline via prompt.
 *
 *  `.fm-vd-section-add` (➕ in head): focus + click su SalvaTEX in topbar
 *  (l'utente conferma il flow corrente per creare nuova verifica). */
function bindCustomActions() {
    document.addEventListener("click", async (e) => {
        // ➕ section-add: redirect a SalvaTEX in topbar
        const addBtn = e.target.closest?.(".fm-vd-section-add");
        if (addBtn) {
            e.preventDefault();
            e.stopPropagation();
            const sx = document.querySelector('#fm-topbar [data-fm-action="salvatex"]');
            if (sx) {
                sx.scrollIntoView({ behavior: "smooth", block: "nearest" });
                sx.classList.add("fm-pulse");
                setTimeout(() => sx.classList.remove("fm-pulse"), 1500);
                window.FM?.ToastManager?.show?.("info", "Nuova verifica",
                    "Seleziona gli esercizi e clicca SalvaTEX in topbar.", 4500);
            }
            return;
        }

        // 🗑 delete su verifica li
        const delBtn = e.target.closest?.(".fm-item-del");
        if (delBtn) {
            const li = delBtn.closest("li[data-fm-content-kind='verifica']");
            if (!li) return; // non e' un nostro li, lascia gestire al default
            e.preventDefault();
            e.stopPropagation();
            const id = parseInt(li.dataset.contentId || "0", 10);
            if (!id) return;
            const title = li.querySelector(".fm-vd-link")?.textContent?.trim() || `verifica #${id}`;
            const ok = window.FM?.Dialog?.confirm
                ? await window.FM.Dialog.confirm(
                    `Eliminare "${title}"?\n\nVerranno cancellati anche i file TEX e PDF associati.\nOperazione non reversibile.`,
                    { title: "Elimina verifica", kind: "danger" })
                : window.confirm(`Eliminare "${title}"?`);
            if (!ok) return;
            try {
                const csrf = await fetchCsrf();
                const r = await fetch(`/api/verifica/${id}/delete`, {
                    method: "POST",
                    credentials: "same-origin",
                    headers: { "X-CSRF-Token": csrf, Accept: "application/json" },
                });
                const j = await r.json().catch(() => ({}));
                if (r.ok && j.ok) {
                    window.FM?.ToastManager?.show?.("success", "Verifica eliminata",
                        `${title} cancellata.`, 3000);
                    // G19.34 — rimozione surgical del li (preserva edit mode
                    // attivo su `<ul>` parent). Full re-render via `renderInto`
                    // perdeva lo stato `data-edit-active="1"` + i bottoni
                    // inline ✎/🗑 sui sibling li.
                    const ul = li.closest("ul.fm-db-block");
                    li.remove();
                    // Se l'ul resta con solo head + nessun item → re-render
                    // (mostra placeholder "Nessuna verifica salvata").
                    if (ul && ul.querySelectorAll("li[data-content-id]").length === 0) {
                        const panel = ul.closest(".fm-sb-panel");
                        if (panel) renderInto(panel);
                    }
                } else {
                    window.FM?.ToastManager?.show?.("error", "Errore eliminazione",
                        j.error || `HTTP ${r.status}`, 4000);
                }
            } catch (err) {
                window.FM?.ToastManager?.show?.("error", "Errore di rete", err.message, 4000);
            }
            return;
        }

        // G22.S25 — bottoni share/grants spostati nel detail popup (.fm-vd-detail)
        // gestiti da verifica-detail-modal.js. La riga inline mostra solo
        // un indicatore 🤝 read-only se shared.

        // ✎ edit su verifica li → rename via await window.FM.Dialog.prompt(semplice, no modal)
        const editBtn = e.target.closest?.(".fm-item-edit");
        if (editBtn) {
            const li = editBtn.closest("li[data-fm-content-kind='verifica']");
            if (!li) return;
            e.preventDefault();
            e.stopPropagation();
            // Rename non implementato server-side per verifica_documents al momento.
            // Mostra info che l'edit avviene tramite il popup detail (G19.24).
            window.FM?.ToastManager?.show?.("info", "Modifica verifica",
                "Per modificare PDF/TEX clicca il link o il bottone PDF.", 4000);
            return;
        }
    }, true); // capture phase: precede listener bubble di wireItemActions
}

function init() {
    document.addEventListener("fm:db-sidepage-rendered", onSidepageRendered);
    window.addEventListener("fm:verifica-saved", onVerificaSaved);
    // Riaggancio su SPA navigate: se la sidebar e' gia' renderizzata,
    // potrebbe servirci re-render (l'evento e' sticky-meno).
    window.addEventListener("fm:navigated", () => {
        const panel = document.getElementById("fm-sp-verif");
        if (panel && panel.style.display !== "none") {
            // delay per lasciare che db-sidepage finisca prima.
            setTimeout(() => renderInto(panel), 50);
        }
    });
    bindCustomActions();
}

init();

// Export per debugging / test manuali.
window.FM = window.FM || {};
window.FM.VerificaDocsSidepage = { renderInto, fetchVerifiche };
