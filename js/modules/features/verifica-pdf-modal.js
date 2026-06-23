/**
 * Phase G8 — PDF upload modal + PDF viewer per verifica_documents.
 *
 * Wires click handler su:
 *   1. .fm-vd-link senza PDF → open upload modal (drag&drop + file picker)
 *   2. .fm-vd-link con PDF   → open viewer iframe (lightbox)
 *   3. button[data-fm-action="upload-pdf"] → stesso upload modal
 *
 * Upload flow:
 *   - User drag/drop o sceglie .pdf
 *   - POST multipart /api/verifica/{id}/pdf con CSRF
 *   - Su success: emit fm:verifica-saved → re-render sidepage block
 *   - Errori: toast + retry
 *
 * Viewer flow:
 *   - Iframe full-screen con /api/verifica/{id}/pdf
 *   - Pulsanti: chiudi, scarica (con ?download=1), elimina PDF (replace)
 */

import { esc, fetchJson, fetchCsrf } from "../core/dom-utils.js";

const MODAL_UPLOAD_ID = "fm-vd-upload-modal";
const MODAL_VIEWER_ID = "fm-vd-viewer-modal";

function ensureToast(kind, title, msg) {
    if (window.FM?.ToastManager?.show) {
        window.FM.ToastManager.show(kind, title, msg, 4500);
    } else {
        console.info(`[verifica-pdf] ${title}: ${msg}`);
    }
}

// ─────── Upload Modal ───────

function buildUploadModal(docId, docTitle) {
    const m = document.createElement("div");
    m.id = MODAL_UPLOAD_ID;
    m.className = "fm-modal-backdrop fm-vd-modal";
    m.innerHTML = `
        <div class="fm-modal fm-vd-upload" role="dialog" aria-modal="true" aria-labelledby="fm-vd-upload-title">
            <button type="button" class="fm-modal-close" data-action="close" aria-label="Chiudi">×</button>
            <h3 id="fm-vd-upload-title">Carica PDF compilato</h3>
            <p class="fm-muted">Verifica: <strong>${esc(docTitle)}</strong> (id ${docId})</p>
            <div class="fm-drop-zone" data-action="open-picker" tabindex="0">
                <p>📥 <strong>Trascina il PDF qui</strong></p>
                <p class="fm-muted">oppure <button type="button" class="fm-btn fm-btn--xs" data-action="open-picker">scegli un file</button></p>
                <p class="fm-muted" style="font-size:11px;">Solo .pdf, max 30 MB. Genera il PDF da Overleaf, TeXworks o VSCode (LaTeX Workshop).</p>
            </div>
            <input type="file" accept="application/pdf,.pdf" hidden data-role="filepicker">
            <div class="fm-vd-progress" hidden>
                <div class="fm-vd-progress-bar"></div>
                <span class="fm-vd-progress-msg">Caricamento…</span>
            </div>
            <div class="fm-modal-actions">
                <button type="button" class="fm-btn" data-action="close">Annulla</button>
            </div>
        </div>`;
    return m;
}

async function uploadPdf(modal, docId, file) {
    if (!file) return;
    if (!/\.pdf$/i.test(file.name) && file.type !== "application/pdf") {
        ensureToast("error", "PDF", "Il file deve essere un .pdf");
        return;
    }
    if (file.size > 30 * 1024 * 1024) {
        ensureToast("error", "PDF", "PDF troppo grande (max 30 MB).");
        return;
    }

    const progress = modal.querySelector(".fm-vd-progress");
    const msg      = modal.querySelector(".fm-vd-progress-msg");
    progress.hidden = false;
    if (msg) msg.textContent = "Caricamento…";

    const csrf = await fetchCsrf();
    const fd = new FormData();
    fd.set("file", file, file.name);
    fd.set("_csrf", csrf);

    try {
        const j = await fetchJson(`/api/verifica/${docId}/pdf`, {
            method: "POST",
            headers: { "X-CSRF-Token": csrf },
            body: fd,
        });
        if (j?.ok) {
            ensureToast("success", "PDF", `PDF caricato (${(file.size/1024).toFixed(0)} KB).`);
            window.dispatchEvent(new CustomEvent("fm:verifica-saved", { detail: j.doc }));
            closeModal(modal);
        } else {
            const err = j?.error || "richiesta non riuscita";
            ensureToast("error", "PDF", `Errore upload: ${err}`);
            progress.hidden = true;
        }
    } catch (e) {
        ensureToast("error", "PDF", `Errore di rete: ${e.message}`);
        progress.hidden = true;
    }
}

function wireUploadModal(modal, docId) {
    const picker = modal.querySelector('[data-role="filepicker"]');
    const drop   = modal.querySelector(".fm-drop-zone");

    modal.addEventListener("click", (e) => {
        const a = e.target.closest("[data-action]");
        if (!a) return;
        if (a.dataset.action === "close") closeModal(modal);
        if (a.dataset.action === "open-picker") picker.click();
    });
    modal.addEventListener("keydown", (e) => { if (e.key === "Escape") closeModal(modal); });

    picker.addEventListener("change", () => {
        if (picker.files?.length) uploadPdf(modal, docId, picker.files[0]);
    });

    ["dragenter", "dragover"].forEach(ev => {
        drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add("fm-drop-zone--over"); });
    });
    ["dragleave", "drop"].forEach(ev => {
        drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove("fm-drop-zone--over"); });
    });
    drop.addEventListener("drop", (e) => {
        const f = e.dataTransfer?.files?.[0];
        if (f) uploadPdf(modal, docId, f);
    });
}

function openUploadModal(docId, docTitle) {
    closeAllModals();
    const m = buildUploadModal(docId, docTitle);
    document.body.appendChild(m);
    wireUploadModal(m, docId);
    requestAnimationFrame(() => m.classList.add("fm-modal--visible"));
}

// ─────── Viewer Modal ───────

function buildViewerModal(docId, docTitle, pdfFilename) {
    const m = document.createElement("div");
    m.id = MODAL_VIEWER_ID;
    m.className = "fm-modal-backdrop fm-vd-modal fm-vd-modal--viewer";
    m.innerHTML = `
        <div class="fm-vd-viewer" role="dialog" aria-modal="true" aria-label="Anteprima PDF">
            <div class="fm-vd-viewer-toolbar">
                <span class="fm-vd-viewer-title">${esc(docTitle)} <span class="fm-muted">(id ${docId})</span></span>
                <a class="fm-btn fm-btn--xs" href="/api/verifica/${docId}/pdf?download=1"
                   download="${esc(pdfFilename || (`verifica_${  docId  }.pdf`))}"
                   title="Scarica PDF">⬇ Scarica</a>
                <button type="button" class="fm-btn fm-btn--xs" data-action="replace-pdf"
                        title="Sostituisci con un nuovo PDF">🔄 Sostituisci</button>
                <button type="button" class="fm-btn fm-btn--xs" data-action="close" title="Chiudi">×</button>
            </div>
            <iframe class="fm-vd-viewer-iframe" src="/api/verifica/${docId}/pdf" title="${esc(docTitle)}"></iframe>
        </div>`;
    return m;
}

function openViewerModal(docId, docTitle, pdfFilename) {
    closeAllModals();
    const m = buildViewerModal(docId, docTitle, pdfFilename);
    document.body.appendChild(m);
    m.addEventListener("click", (e) => {
        const a = e.target.closest("[data-action]");
        if (!a) return;
        if (a.dataset.action === "close") closeModal(m);
        if (a.dataset.action === "replace-pdf") {
            closeModal(m);
            openUploadModal(docId, docTitle);
        }
    });
    m.addEventListener("keydown", (e) => { if (e.key === "Escape") closeModal(m); });
    requestAnimationFrame(() => m.classList.add("fm-modal--visible"));
}

function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove("fm-modal--visible");
    setTimeout(() => modal.remove(), 150);
}
function closeAllModals() {
    document.querySelectorAll(".fm-vd-modal").forEach(closeModal);
}

// ─────── Entry: click delegation ───────

function readDocMeta(linkOrBtn) {
    const li = linkOrBtn.closest("li[data-content-id]");
    if (!li) return null;
    const id = parseInt(li.dataset.contentId, 10) || 0;
    if (id <= 0) return null;
    const title = li.querySelector(".fm-vd-link")?.textContent?.trim() || "(senza titolo)";
    const hasPdf = li.dataset.fmHasPdf === "1";
    return { id, title, hasPdf };
}

document.addEventListener("click", (e) => {
    // Click su .fm-vd-link
    const link = e.target.closest(".fm-vd-link");
    if (link) {
        const meta = readDocMeta(link);
        if (!meta) return;
        e.preventDefault();
        if (meta.hasPdf) openViewerModal(meta.id, meta.title);
        else             openUploadModal(meta.id, meta.title);
        return;
    }
    // Click su upload PDF button (azione esplicita anche se PDF c'è già).
    const upBtn = e.target.closest('button[data-fm-action="upload-pdf"]');
    if (upBtn) {
        const meta = readDocMeta(upBtn);
        if (!meta) return;
        e.preventDefault();
        if (meta.hasPdf) {
            openViewerModal(meta.id, meta.title);
        } else {
            openUploadModal(meta.id, meta.title);
        }
    }
});

// Esponi per debug.
window.FM = window.FM || {};
window.FM.VerificaPdfModal = { openUploadModal, openViewerModal };
