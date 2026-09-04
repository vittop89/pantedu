/**
 * Phase G19.24 — Popup centrale "dettaglio verifica".
 *
 * Click su `[data-fm-action="open-detail"]` (sia sul btn `PDF` che sul
 * link `.fm-vd-link`) apre un modal centrale con:
 *   1. 4 slot PDF: SOL / NOR / DSA / DIS — uno per variante del batch.
 *      Se la verifica e' AB (8 varianti), 2 colonne A/B, 4 righe SOL/NOR/DSA/DIS.
 *      Stato cella: PDF ✓ caricato (preview/replace) | + carica.
 *   2. Tabella TEX: per ogni variante, link `📄` download + `📝` open VSCode.
 *   3. (Roadmap G19.25) — TEX modificate: lista versioni caricate dall'utente.
 *      Placeholder "Carica .tex modificato (prossimamente)".
 *
 * Source dati: `/api/verifica/list` filtrata per `batch_id` + `id` corrente.
 * (publicView G19.24 ora include `variant` e `batch_id`.)
 *
 * Click delegation: `capture: true` per intercettare prima dei vecchi
 * handler in `verifica-pdf-modal.js` (che agisce su `.fm-vd-link` senza
 * data-action). Stop propagation per evitare il modal upload legacy.
 */

import { esc, fetchJson, fetchCsrf } from "../core/dom-utils.js";

const MODAL_ID = "fm-vd-detail-modal";
const VARIANT_KINDS = ["SOL", "NOR", "DSA", "DIS"];

function ensureToast(kind, title, msg, ms = 3500) {
    if (window.FM?.ToastManager?.show) {
        window.FM.ToastManager.show(kind, title, msg, ms);
    } else {
        console.info(`[verifica-detail] ${title}: ${msg}`);
    }
}

async function fetchAll() {
    try {
        const j = await fetchJson("/api/verifica/list");
        return Array.isArray(j?.items) ? j.items : [];
    } catch (_) {
        return [];
    }
}

/** Da `r` (item) ricava { letter:'A'|'B'|'', kind:'SOL'|'NOR'|'DSA'|'DIS'|'' }.
 *  variant attesa: "A_SOL" | "B_NOR" | "" (singleton senza varianti). */
function parseVariant(variant) {
    if (!variant) return { letter: "", kind: "" };
    const m = String(variant).match(/^([AB])_(SOL|NOR|DSA|DIS)$/);
    if (!m) return { letter: "", kind: variant };
    return { letter: m[1], kind: m[2] };
}

/** Strip suffisso `— A_NOR` / `- B_DSA` dal title per l'header del modal. */
function stripVariantSuffix(title) {
    return String(title || "")
        .replace(/\s*[—-]\s*[AB]_(SOL|NOR|DSA|DIS)\s*$/u, "")
        .trim();
}

/** G19.44 — Trova TUTTI i doc con lo stesso base title (no batch_id),
 *  cosi' il modal mostra "Versioni {title}" raggruppate per kind
 *  (SOL/NOR/DSA/DIS), ciascuno con N versioni (A/B [+ future v1/v2]). */
async function resolveSiblings(currentId) {
    const items = await fetchAll();
    const cur = items.find(i => Number(i.id) === Number(currentId));
    if (!cur) return { current: null, siblings: [], baseTitle: "" };

    const baseTitle = stripVariantSuffix(cur.title);
    const materia = (cur.materia || "").toLowerCase();
    const siblings = items.filter(i => {
        return (i.materia || "").toLowerCase() === materia
            && stripVariantSuffix(i.title).toLowerCase() === baseTitle.toLowerCase();
    });
    return { current: cur, siblings, baseTitle };
}

/** G19.44 — Tutti i doc per un kind (es. SOL) attraverso tutti i batch
 *  dello stesso base title. Ordinati per created_at desc (piu' recente top). */
function findVariantsForKind(siblings, kind) {
    return siblings
        .filter(d => parseVariant(d.variant).kind === kind)
        .sort((a, b) => String(b.created_at).localeCompare(String(a.created_at)));
}

/** G19.46 — Format: `{version_label}-{DD_MM_YYYY}` es. `v01cr-02_05_2026`.
 *  Se version_label assente, fallback `{letter}-{DD_MM_YYYY}`. */
function variantVersionLabel(doc) {
    let dateStr = "";
    if (doc.created_at) {
        // created_at: "YYYY-MM-DD HH:MM:SS" o "YYYY-MM-DDTHH:MM:SS"
        const m = String(doc.created_at).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) dateStr = `${m[3]}_${m[2]}_${m[1]}`;
    }
    const lbl = doc.version_label || parseVariant(doc.variant).letter || "?";
    return dateStr ? `${lbl}-${dateStr}` : lbl;
}

/** G19.44 — Slot per kind (SOL/NOR/DSA/DIS) con N versioni listate.
 *  Ogni versione = 1 doc con il proprio set di action (PDF view/upload,
 *  TEX download, VSCode open). */
function buildSlot(docs, kind) {
    const cell = document.createElement("div");
    cell.className = "fm-vd-detail-slot";
    cell.dataset.kind = kind;
    if (!docs || !docs.length) {
        cell.classList.add("is-empty");
        cell.innerHTML = `<div class="fm-vd-detail-slot-label">${esc(kind)}</div>
                          <div class="fm-vd-detail-slot-body fm-muted">— nessuna versione —</div>`;
        return cell;
    }
    const versionsHtml = docs.map(doc => {
        const hasPdf = !!doc.has_pdf;
        const pdfBtn = hasPdf
            ? `<button type="button" class="fm-btn fm-btn--xs" data-action="view-pdf" data-id="${doc.id}" title="Apri PDF">👁</button>
               <button type="button" class="fm-btn fm-btn--xs" data-action="replace-pdf" data-id="${doc.id}" title="Sostituisci PDF">↻</button>`
            : `<button type="button" class="fm-btn fm-btn--xs fm-btn--accent" data-action="upload-pdf" data-id="${doc.id}" title="Carica PDF compilato">+ PDF</button>`;
        const texBtn = `<a class="fm-btn fm-btn--xs" href="${esc(doc.tex_url)}" download="${esc(doc.tex_filename || `verifica_${doc.id}.tex`)}" title="Scarica .tex">📄</a>`;
        const vscBtn = `<button type="button" class="fm-btn fm-btn--xs" data-action="open-vscode" data-id="${doc.id}" title="Apri in VSCode">📝</button>`;
        // G21.1 — Anteprima preview modal full-screen (TeX↔PDF + SyncTeX)
        const previewBtn = `<button type="button" class="fm-btn fm-btn--xs" data-action="open-preview" data-id="${doc.id}" title="Apri preview modal: editor TeX + PDF + SyncTeX">👁🖋</button>`;
        const delBtn = `<button type="button" class="fm-btn fm-btn--xs fm-vd-version-del" data-action="del-doc" data-id="${doc.id}" title="Elimina questa versione">🗑</button>`;
        const label = esc(variantVersionLabel(doc));
        return `<div class="fm-vd-version-row" data-doc-id="${doc.id}">
                    <span class="fm-vd-version-label" title="${label}">${label}</span>
                    <span class="fm-vd-version-actions">${pdfBtn}${texBtn}${vscBtn}${previewBtn}${delBtn}</span>
                </div>`;
    }).join("");
    cell.innerHTML = `
        <div class="fm-vd-detail-slot-label">${esc(kind)}</div>
        <div class="fm-vd-detail-slot-body">
            <div class="fm-vd-version-list">${versionsHtml}</div>
        </div>`;
    return cell;
}

function buildModal(detail) {
    const { current, siblings, baseTitle } = detail;
    const m = document.createElement("div");
    m.id = MODAL_ID;
    m.className = "fm-modal-backdrop fm-vd-modal";

    // G19.46 — count = distinct version_label (non totale docs).
    // Doc senza version_label conta come "(senza-label)" unico.
    const distinctLabels = new Set(siblings.map(s => (s.version_label || "(no-label)").toLowerCase()));
    const totalVersions = distinctLabels.size;
    const header = `
        <header class="fm-vd-detail-header">
            <h3 id="fm-vd-detail-title">Versioni ${esc(baseTitle)}</h3>
            <div class="fm-vd-detail-meta">
                <span class="fm-muted">${esc(current.materia)} · ${totalVersions} versione${totalVersions === 1 ? "" : "i"} totali</span>
                <!-- G21.1 — Anteprima tutte le varianti in modal full-screen -->
                <button type="button" class="fm-btn fm-btn--xs"
                        data-action="open-preview-all" data-id="${current.id}"
                        title="Apri preview modal con tutte le varianti (TeX↔PDF + SyncTeX)">
                    👁 Anteprima
                </button>
            </div>
            <button type="button" class="fm-modal-close" data-action="close" aria-label="Chiudi">×</button>
        </header>`;

    // G19.44 — UN'unica colonna con 4 slot kind (SOL/NOR/DSA/DIS), ognuno
    // mostra TUTTE le versioni (A/B + future v1/v2/...) per quel kind.
    const slots = VARIANT_KINDS.map(k => {
        const docs = findVariantsForKind(siblings, k);
        return buildSlot(docs, k).outerHTML;
    }).join("");
    const cols = `<section class="fm-vd-detail-col">
                    <div class="fm-vd-detail-grid">${slots}</div>
                  </section>`;

    // G19.25 placeholder: TEX modificate
    const texSection = `
        <section class="fm-vd-detail-tex-versions">
            <h4>TEX modificate</h4>
            <p class="fm-muted">Carica un .tex modificato (es. da VSCode/Overleaf): le versioni
                resteranno qui per ricomporre il PDF poi.</p>
            <div class="fm-muted" style="font-style:italic;">
                ⚙ Funzione in arrivo (G19.25). Per ora salva il .tex localmente
                + ricarica via il bottone <code>+ PDF</code> dopo compilazione.
            </div>
        </section>`;

    // G22.S25 — Sezione "Condivisione" inline nel detail: toggle 🤝 share-pool
    // istituto + bottone 🎯 grants espliciti (docenti/gruppi). Stato pre-popolato
    // da current.shared_with_pool (publicView espone il flag).
    const sharedNow = !!current.shared_with_pool;
    const shareSection = `
        <section class="fm-vd-detail-share" style="margin:8px 16px;padding:10px 12px;background:rgba(99,102,241,0.08);border-left:3px solid #6366f1;border-radius:6px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <strong style="font-size:13px">🤝 Condivisione:</strong>
            <button type="button" class="fm-btn fm-btn--xs fm-vd-detail-share-toggle"
                    data-id="${current.id}" data-shared="${sharedNow ? "1" : "0"}"
                    style="${sharedNow ? "background:rgba(34,197,94,0.18)" : "background:rgba(148,163,184,0.12)"}"
                    title="${sharedNow ? "Condivisa con tutto l'istituto. Click per ritirare." : "Privata. Click per condividere con tutto l'istituto."}">
                ${sharedNow ? "🤝 Istituto attivo" : "🔒 Privata"}
            </button>
            <button type="button" class="fm-btn fm-btn--xs fm-vd-detail-grants"
                    data-id="${current.id}" data-title="${esc(baseTitle)}"
                    title="Condividi a docenti specifici o gruppi personali">
                🎯 Avanzato (docenti/gruppi)
            </button>
            <span class="fm-muted" style="font-size:11px;flex-basis:100%;margin-top:2px">
                Il toggle agisce sull'intero batch (tutte le varianti A/B + SOL/NOR/DSA/DIS).
            </span>
        </section>`;

    m.innerHTML = `
        <div class="fm-vd-detail" role="dialog" aria-modal="true" aria-labelledby="fm-vd-detail-title">
            ${header}
            ${shareSection}
            <div class="fm-vd-detail-cols">${cols}</div>
            ${texSection}
        </div>`;

    // G19.30c — geometry FORZATA inline (alcuni site CSS / dev-tools docked
    // possono confondere il flex/grid centering dichiarato in stylesheet).
    // Inline style ha specificita' massima e non e' soggetta a override.
    // Flex su grid: piu' predictable per centering single child (grid
    // place-items centra DENTRO il track, ma il track puo' restare in alto
    // del container se non c'e' anche `place-content: center`).
    Object.assign(m.style, {
        position: "fixed",
        top: "0",
        left: "0",
        right: "0",
        bottom: "0",
        width: "100vw",
        height: "100vh",
        margin: "0",
        padding: "0",
        zIndex: "10100",
        background: "rgba(15, 23, 42, 0.62)",
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        overflow: "hidden",
        opacity: "1",
    });

    document.body.appendChild(m);
    requestAnimationFrame(() => m.classList.add("fm-is-open"));
    bindModal(m, detail);
}

function closeModal() {
    const m = document.getElementById(MODAL_ID);
    if (!m) return;
    m.classList.remove("fm-is-open");
    setTimeout(() => m.remove(), 150);
}

function refreshModal(currentId) {
    closeModal();
    setTimeout(() => openDetailModal(currentId), 80);
}

function bindModal(m, detail) {
    m.addEventListener("click", async (e) => {
        // G19.28 — close SOLO su click diretto sul backdrop (e.target === m)
        // o sul bottone × esplicito. Click interni NON chiudono.
        if (e.target === m) { closeModal(); return; }
        const closeBtn = e.target.closest('[data-action="close"]');
        if (closeBtn) { closeModal(); return; }

        // G22.S25 — Share toggle inline (delegato a share-client centralizzato)
        const shareToggle = e.target.closest(".fm-vd-detail-share-toggle");
        if (shareToggle) {
            e.preventDefault();
            const vid = parseInt(shareToggle.dataset.id || "0", 10);
            if (!vid) return;
            const wasShared = shareToggle.dataset.shared === "1";
            const newVal = !wasShared;
            shareToggle.disabled = true;
            try {
                const { toggleSharePool } = await import("./share/share-client.js");
                await toggleSharePool("verifica_documents", vid, newVal);
                shareToggle.dataset.shared = newVal ? "1" : "0";
                shareToggle.textContent = newVal ? "🤝 Istituto attivo" : "🔒 Privata";
                shareToggle.style.background = newVal
                    ? "rgba(34,197,94,0.18)" : "rgba(148,163,184,0.12)";
                shareToggle.title = newVal
                    ? "Condivisa con tutto l'istituto. Click per ritirare."
                    : "Privata. Click per condividere con tutto l'istituto.";
                window.FM?.SyncPanel?.notify?.("Pool", "ok",
                    newVal ? "✓ Condivisa con i colleghi" : "✓ Condivisione ritirata", 2500);
            } catch (err) {
                window.FM?.SyncPanel?.notify?.("Pool", "error", "Errore: " + err.message, 4000);
            } finally {
                shareToggle.disabled = false;
            }
            return;
        }
        const grantsBtn = e.target.closest(".fm-vd-detail-grants");
        if (grantsBtn) {
            e.preventDefault();
            const vid = parseInt(grantsBtn.dataset.id || "0", 10);
            const title = grantsBtn.dataset.title || "";
            if (!vid) return;
            try {
                const mod = await import("./share-grants-popup.js");
                mod.openShareGrantsPopup({ source: "verifica_documents", id: vid, title });
            } catch (err) {
                window.FM?.SyncPanel?.notify?.("Pool", "error", "Errore popup: " + err.message, 4000);
            }
            return;
        }

        const btn = e.target.closest("button[data-action]");
        if (!btn) return;
        const id = parseInt(btn.dataset.id || "0", 10);
        const action = btn.dataset.action;
        if (!id || !action) return;
        if (action === "view-pdf") {
            window.open(`/api/verifica/${id}/pdf`, "_blank", "noopener");
            return;
        }
        if (action === "open-vscode") {
            // G19.43 — usa FS Access flow (root pairata via ⚙ topbar)
            // invece del legacy `verifica-vscode-launch.js launchInVscode`
            // che scaricava in Downloads e tentava un path inesistente.
            await openVariantInVscode(id);
            return;
        }
        if (action === "open-preview" || action === "open-preview-all") {
            // G21.1 — apre preview modal con TUTTI i siblings (varianti)
            // della stessa verifica, posizionando il tab attivo su questo doc.
            const allDocs = (detail.siblings || []).map(s => ({
                id: s.id,
                variant: s.variant || "",
                title: s.title || "",
                version_label: s.version_label || "",
            }));
            if (!allDocs.length) {
                allDocs.push({ id, variant: "", title: "", version_label: "" });
            }
            const openIdx = action === "open-preview"
                ? allDocs.findIndex(d => d.id === id)
                : 0;  // open-preview-all parte dal primo
            // G22.S15.bis Fase 4 — "open-preview-all" (bottone "👁 Anteprima"
            // del header) apre in `previewOnly: true` → ricompila nel preview
            // SENZA salvare il TeX nel DB. Il save persistente avviene solo
            // dal bottone TEX/PDF (open-preview singolo).
            const previewOnly = action === "open-preview-all";
            const opener = window.FM?.openVerificaPreview;
            if (typeof opener === "function") {
                opener(allDocs, { openIdx: Math.max(0, openIdx), previewOnly });
            } else {
                ensureToast("error", "Anteprima",
                    "Modulo preview non disponibile (ricarica pagina dopo build).");
            }
            return;
        }
        if (action === "upload-pdf" || action === "replace-pdf") {
            await pickAndUploadPdf(id);
            // Re-render con dati aggiornati (incluso has_pdf)
            const anyId = m.querySelector("[data-id]")?.dataset.id;
            if (anyId) refreshModal(parseInt(anyId, 10));
            return;
        }
        if (action === "del-doc") {
            // G19.44 — elimina singola version (= 1 doc DB).
            const row = btn.closest(".fm-vd-version-row");
            const label = row?.querySelector(".fm-vd-version-label")?.textContent || `#${id}`;
            const ok = window.FM?.Dialog?.confirm
                ? await window.FM.Dialog.confirm(
                    `Eliminare la versione "${label}"?\n\nVerranno cancellati anche TEX e PDF associati.\nOperazione non reversibile.`,
                    { title: "Elimina versione", kind: "danger" })
                : window.confirm(`Eliminare la versione "${label}"?`);
            if (!ok) return;
            try {
                const csrf = await fetchCsrf();
                const j = await fetchJson(`/api/verifica/${id}/delete`, {
                    method: "POST",
                    headers: { "X-CSRF-Token": csrf },
                });
                if (j.ok) {
                    ensureToast("success", "Versione eliminata", `${label}`, 2500);
                    // Rimuovi solo la row (preserva resto del modal aperto)
                    row?.remove();
                    // Se la slot resta vuota, mostra placeholder
                    const slot = m.querySelector(`.fm-vd-detail-slot[data-kind] .fm-vd-version-list`);
                    // Re-render leggero
                    const anyId = m.querySelector("[data-id]")?.dataset.id;
                    if (anyId) refreshModal(parseInt(anyId, 10));
                } else {
                    ensureToast("error", "Errore eliminazione", j.error || "richiesta non riuscita", 3500);
                }
            } catch (e) {
                ensureToast("error", "Errore di rete", e.message, 3500);
            }
            return;
        }
    });
    // ESC chiude
    document.addEventListener("keydown", function onKey(ev) {
        if (ev.key === "Escape") {
            closeModal();
            document.removeEventListener("keydown", onKey);
        }
    });
}

async function pickAndUploadPdf(id) {
    const input = document.createElement("input");
    input.type = "file";
    input.accept = "application/pdf,.pdf";
    input.style.display = "none";
    document.body.appendChild(input);
    return new Promise(resolve => {
        input.addEventListener("change", async () => {
            const f = input.files?.[0];
            input.remove();
            if (!f) return resolve(false);
            try {
                const fd = new FormData();
                fd.append("file", f);
                // CSRF: il middleware accetta header X-CSRF-Token; legge da meta o cookie.
                const csrf = await fetchCsrf();
                const j = await fetchJson(`/api/verifica/${id}/pdf`, {
                    method: "POST",
                    body: fd,
                    headers: { "X-CSRF-Token": csrf },
                });
                if (j.ok) {
                    ensureToast("success", "PDF", `Caricato: ${f.name}`);
                    window.dispatchEvent(new CustomEvent("fm:verifica-saved", { detail: j.doc }));
                    resolve(true);
                } else {
                    ensureToast("error", "PDF", `Errore upload: ${j.error || "richiesta non riuscita"}`);
                    resolve(false);
                }
            } catch (e) {
                ensureToast("error", "PDF", `Errore di rete: ${e.message}`);
                resolve(false);
            }
        }, { once: true });
        input.click();
    });
}

/** G19.43 — Apre la variante TEX (singola) in VSCode usando FS Access:
 *  scrive il .tex nella radice + sub-cartella mirror, poi apre `vscode://file`. */
async function openVariantInVscode(docId) {
    const fs = window.FM?.FsAccess;
    const VSC_ROOT_KEY = "fm.vscode.user_dir";
    const root = (localStorage.getItem(VSC_ROOT_KEY) || "").trim();
    if (!fs?.isSupported?.() || !root) {
        ensureToast("error", "VSC",
            "Configura prima la cartella radice via ⚙ in topbar (Chrome/Edge desktop).",
            6000);
        return;
    }
    const handle = await fs.getRoot();
    if (!handle) {
        ensureToast("error", "VSC", "Cartella non pairata: clicca ⚙ in topbar.", 5000);
        return;
    }
    const ok = await fs.getOrRequestPermission(handle, "readwrite");
    if (!ok) { ensureToast("error", "VSC", "Permesso scrittura negato."); return; }

    // Recupera doc + tex da API
    try {
        const items = await fetchAll();
        const cur = items.find(i => Number(i.id) === Number(docId));
        if (!cur) { ensureToast("error", "VSC", `Verifica ${docId} non trovata.`); return; }
        const r = await fetch(cur.tex_url, { credentials: "same-origin" });
        const tex = await r.text();
        const filename = cur.tex_filename || `verifica_${cur.id}.tex`;
        // Mirror struttura: {materia}/{title-stripped}/{filename}
        const titleClean = stripVariantSuffix(cur.title);
        const slug = String(titleClean).toLowerCase()
            .replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "").slice(0, 40) || "verifica";
        const materia = (cur.materia || window.FM?.Curriculum?.firstCode("materie") || "").toLowerCase();
        const sub = `${materia}/${slug}`;
        await fs.writeFile(handle, `${sub}/${filename}`, tex);

        const fullPath = `${root.replace(/\\/g, "/").replace(/\/+$/, "")}/${sub}/${filename}`;
        const url = `vscode://file/${fullPath.replace(/^\/+/, "")}?windowId=_blank`;
        console.log("[VSC variant] opening:", url);
        window.location.href = url;
        ensureToast("success", "VSC",
            `${filename} scritto in "${handle.name}/${sub}". VSCode aperto.`, 6000);
    } catch (e) {
        ensureToast("error", "VSC", `Errore: ${e.message}`, 5000);
    }
}

async function openDetailModal(id) {
    if (!id) return;
    closeModal();
    const detail = await resolveSiblings(id);
    if (!detail.current) {
        ensureToast("error", "Dettaglio", `Verifica ${id} non trovata.`);
        return;
    }
    buildModal(detail);
}

// ─────── Click delegation (capture phase) ───────
// G19.24 — capture:true intercetta PRIMA del listener bubble di
// verifica-pdf-modal.js (che agisce su `.fm-vd-link` senza data-fm-action),
// stop propagation per evitare il modal upload legacy.
document.addEventListener("click", (e) => {
    const trg = e.target.closest('[data-fm-action="open-detail"]');
    if (!trg) return;
    e.preventDefault();
    e.stopPropagation();
    const li = trg.closest("li[data-content-id]");
    const id = parseInt(li?.dataset.contentId || trg.dataset.fmId || "0", 10);
    if (id <= 0) return;
    openDetailModal(id);
}, true);

// Espone API per debug.
window.FM = window.FM || {};
window.FM.VerificaDetailModal = { openDetailModal, closeModal };
