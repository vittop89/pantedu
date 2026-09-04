/**
 * Phase G8 — GENERA modal: scelta target compilazione + salvataggio.
 *
 * Click su 🔘 GENERA della topbar:
 *   1. Salva il TEX (POST /api/verifica/save-tex) → ottiene doc_id
 *   2. Apre modal con 3 opzioni:
 *      - 📤 Overleaf: posta il TEX a Overleaf via overleaf-form (legacy)
 *      - 🖥 Server (pdflatex queue): stub G8.9.X
 *      - 💻 Locale: download .tex (e secondario "Apri in VSCode")
 *
 * Questo modal sostituisce la combinazione legacy
 * checkbox(#Server/#overleaf/#syncDrive) + GENERA-VER.
 */

import { esc } from "../core/dom-utils.js";

const MODAL_ID = "fm-vd-genera-modal";

function ensureToast(kind, title, msg) {
    if (window.FM?.ToastManager?.show) {
        window.FM.ToastManager.show(kind, title, msg, 4500);
    } else {
        console.info(`[verifica-genera] ${title}: ${msg}`);
    }
}

function buildModal(doc, batchInfo) {
    const m = document.createElement("div");
    m.id = MODAL_ID;
    m.className = "fm-modal-backdrop fm-vd-modal";
    const summary = batchInfo
        ? `<p class="fm-muted">Batch <strong>${esc(batchInfo.batch_id)}</strong>:
            <strong>${batchInfo.docs.length}</strong> varianti generate
            (${batchInfo.docs.map(d => esc(d.variant)).join(", ")}).</p>`
        : `<p class="fm-muted">
            Verifica <strong>${esc(doc.title)}</strong>
            salvata (<span class="fm-muted">id ${doc.id}, materia ${esc(doc.materia)},
            ${(doc.tex_size/1024).toFixed(1)} KB</span>).
          </p>`;
    m.innerHTML = `
        <div class="fm-modal fm-vd-genera" role="dialog" aria-modal="true" aria-labelledby="fm-vd-genera-title">
            <button type="button" class="fm-modal-close" data-action="close" aria-label="Chiudi">×</button>
            <h3 id="fm-vd-genera-title">Genera verifica</h3>
            ${summary}

            <div class="fm-vd-genera-targets">
                <button type="button" class="fm-vd-target-btn" data-action="overleaf" title="Apri Overleaf con il .tex (compilazione cloud).">
                    <span class="fm-vd-target-ico">📤</span>
                    <span>
                        <strong>Overleaf</strong>
                        <span class="fm-muted">Compila cloud + edit collaborativo</span>
                    </span>
                </button>
                <button type="button" class="fm-vd-target-btn" data-action="server" disabled title="Compilazione server pdflatex/lualatex (coming soon).">
                    <span class="fm-vd-target-ico">🖥</span>
                    <span>
                        <strong>Server (pdflatex)</strong>
                        <span class="fm-muted">In arrivo (G8.9.X queue)</span>
                    </span>
                </button>
                <button type="button" class="fm-vd-target-btn" data-action="local" title="Scarica .tex e apri con TeXworks/VSCode locale.">
                    <span class="fm-vd-target-ico">💻</span>
                    <span>
                        <strong>Locale</strong>
                        <span class="fm-muted">Scarica .tex (TeXworks/VSCode)</span>
                    </span>
                </button>
                <button type="button" class="fm-vd-target-btn" data-action="vscode" title="Scarica .tex e apri direttamente in VSCode (vscode:// protocol).">
                    <span class="fm-vd-target-ico">📝</span>
                    <span>
                        <strong>VSCode (quick-launch)</strong>
                        <span class="fm-muted">Scarica + tenta apertura vscode://file</span>
                    </span>
                </button>
                ${batchInfo ? `
                <button type="button" class="fm-vd-target-btn" data-action="batch-zip"
                        title="Scarica .zip con tutte le ${batchInfo.docs.length} varianti del batch">
                    <span class="fm-vd-target-ico">📦</span>
                    <span>
                        <strong>Scarica batch ZIP</strong>
                        <span class="fm-muted">${batchInfo.docs.length} varianti A/B × {SOL,NOR,DSA,DIS}</span>
                    </span>
                </button>
                ` : ""}
            </div>

            ${batchInfo ? `
            <div class="fm-vd-batch-list">
                <div class="fm-vd-batch-list__title">📥 Scarica singola variante (.tex):</div>
                <ul class="fm-vd-batch-list__items">
                    ${batchInfo.docs.map(d => `
                        <li>
                            <a href="${esc(d.tex_url)}"
                               download="${esc(d.tex_filename || `verifica_${d.id}.tex`)}"
                               class="fm-vd-batch-link"
                               title="${esc(d.tex_filename || '')} — ${(d.tex_size/1024).toFixed(1)} KB">
                                <span class="fm-vd-batch-variant">${esc(d.variant)}</span>
                                <span class="fm-vd-batch-fname">${esc(d.tex_filename || `verifica_${d.id}.tex`)}</span>
                                <span class="fm-vd-batch-size">${(d.tex_size/1024).toFixed(1)} KB</span>
                            </a>
                        </li>
                    `).join("")}
                </ul>
            </div>
            ` : ""}

            <div class="fm-modal-actions">
                <button type="button" class="fm-btn" data-action="close">Chiudi</button>
            </div>
        </div>`;
    return m;
}

function close(modal) {
    if (!modal) return;
    modal.classList.remove("fm-modal--visible");
    setTimeout(() => modal.remove(), 150);
}

/** Apre Overleaf con il .tex via form POST (riusa #overleaf-form legacy se presente). */
async function openInOverleaf(doc) {
    // Strategia migliore: snip_uri pointing al nostro endpoint
    // /api/verifica/{id}/tex (Overleaf scarica + crea progetto).
    const texUrl = `${location.origin}/api/verifica/${doc.id}/tex`;
    const overleafUrl = `https://www.overleaf.com/docs?snip_uri=${encodeURIComponent(texUrl)}`;
    window.open(overleafUrl, "_blank", "noopener");
    ensureToast("info", "Overleaf", "Apertura Overleaf in nuova tab.");
}

function downloadLocal(doc) {
    // Anchor con download attribute → il browser salva nel folder Downloads.
    const a = document.createElement("a");
    a.href = `/api/verifica/${doc.id}/tex`;
    a.download = `verifica_${doc.id}.tex`;
    a.style.display = "none";
    document.body.appendChild(a);
    a.click();
    a.remove();
    ensureToast("info", "Locale",
        "TEX scaricato nei Downloads. Doppio-click per aprirlo con TeXworks/VSCode (associazione di sistema).");
}

function wireModal(modal, doc, batchInfo) {
    modal.addEventListener("click", (e) => {
        const btn = e.target.closest("[data-action]");
        if (!btn || btn.disabled) return;
        switch (btn.dataset.action) {
            case "close":    close(modal); break;
            case "overleaf": openInOverleaf(doc); close(modal); break;
            case "server":
                ensureToast("info", "Server pdflatex", "Coming soon (G8.9.X queue).");
                break;
            case "local":    downloadLocal(doc); close(modal); break;
            case "vscode":
                window.FM?.VerificaVscode?.launchInVscode(doc.id);
                close(modal);
                break;
            case "batch-zip":
                if (batchInfo?.zip_url) {
                    const a = document.createElement("a");
                    a.href = batchInfo.zip_url;
                    a.download = `batch_${batchInfo.batch_id}.zip`;
                    a.style.display = "none";
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    ensureToast("success", "Batch ZIP",
                        `Scaricato ${batchInfo.docs.length} varianti.`);
                }
                close(modal);
                break;
        }
    });
    modal.addEventListener("keydown", (e) => { if (e.key === "Escape") close(modal); });
}

/** Apre la modal GENERA.
 *  doc       = response.doc dalla saveTex (single mode)
 *  batchInfo = {batch_id, docs[], zip_url} dalla saveTexBatch (batch mode)
 *  In batch mode, il primo doc del batch fa da rappresentante (Overleaf
 *  apre A_SOL; Locale/VSCode scaricano il primo; "📦 Scarica batch ZIP"
 *  scarica tutto). */
export function openGeneraModal(doc, batchInfo = null) {
    document.querySelectorAll(`#${  MODAL_ID}`).forEach(close);
    const m = buildModal(doc, batchInfo);
    document.body.appendChild(m);
    wireModal(m, doc, batchInfo);
    requestAnimationFrame(() => m.classList.add("fm-modal--visible"));
}

// Esponi per chiamata da topbar-modern.js senza import circolari.
window.FM = window.FM || {};
window.FM.openVerificaGeneraModal = openGeneraModal;
