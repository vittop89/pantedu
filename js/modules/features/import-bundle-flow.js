/**
 * G22.S20 — Import Bundle flow (Modalità A: bundle plaintext + Recovery Key).
 *
 * Pulsante separato a destra della sync-bar. Operazione potenzialmente
 * distruttiva → colore ambra + conferma esplicita pre-apply.
 *
 * Flow:
 *   1. User click → modal step 1: pick cartella bundle (FS Access).
 *   2. Walk recursivo della cartella → trova manifest.json.
 *   3. Step 2: prompt Recovery Key (R hex 64 o base32 52).
 *   4. POST /api/teacher/import-bundle/preview con manifest + tutti i files.
 *      Server verifica HMAC + ritorna diff (created/conflicts/errors).
 *   5. Step 3: mostra diff + conflict resolution radio (skip|rename).
 *      User clicca "Applica" o "Annulla".
 *   6. Se Applica → POST /api/teacher/import-bundle/apply → mostra summary.
 */
import { notify, openSession, logLine, closeSession, setProgress } from "../ui/sync-panel.js";
import { fetchCsrf } from "../core/dom-utils.js";

// Freccia verso l'alto (upload) — opposto di ICON_LOCAL (download, freccia giù).
const ICON_IMPORT = `<svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14">
    <path fill="currentColor" d="M9 16h6v-6h4l-7-7-7 7h4z"/>
    <path fill="currentColor" d="M5 18h14v2H5z"/>
</svg>`;

async function postJson(url, payload) {
    const csrf = await fetchCsrf();
    const body = { ...payload, _csrf: csrf };
    const r = await fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": csrf,
            "Accept": "application/json",
        },
        body: JSON.stringify(body),
    });
    const ct = r.headers.get("content-type") || "";
    if (!ct.includes("application/json")) throw new Error(`HTTP ${r.status} (no JSON)`);
    return { status: r.status, body: await r.json() };
}

function buildModal() {
    const m = document.createElement("div");
    m.className = "fm-import-modal";
    m.innerHTML = `
        <div class="fm-import-backdrop"></div>
        <div class="fm-import-dialog" role="dialog" aria-modal="true" aria-labelledby="fm-import-title">
            <h2 id="fm-import-title">📥 Importa bundle</h2>
            <div class="fm-import-body"></div>
            <div class="fm-import-actions">
                <button type="button" class="fm-import-cancel">Annulla</button>
                <button type="button" class="fm-import-next" disabled>Avanti</button>
            </div>
        </div>
    `;
    document.body.appendChild(m);
    return m;
}

function renderStep1(body) {
    body.innerHTML = `
        <p>Carica la <strong>cartella bundle</strong> scaricata in precedenza (deve contenere
        <code>manifest.json</code> alla radice o in una sottocartella).</p>
        <button type="button" class="fm-import-pick">📂 Scegli cartella…</button>
        <div class="fm-import-status" aria-live="polite"></div>
    `;
}

function renderStep2(body, manifest) {
    const exportedAt = new Date(manifest.exported_at || Date.now()).toLocaleString();
    body.innerHTML = `
        <p>Bundle trovato:</p>
        <ul class="fm-import-meta">
            <li><strong>Esportato da:</strong> ${escapeHtml(manifest.exporter_username || "?")}</li>
            <li><strong>Data export:</strong> ${escapeHtml(exportedAt)}</li>
            <li><strong>Istituto:</strong> ${escapeHtml(manifest.institute_code || "?")}</li>
            <li><strong>File totali:</strong> ${(manifest.files || []).length}</li>
        </ul>
        <label>
            <strong>Recovery Key</strong> (incolla il codice dal PDF cassaforte
            dell'esportatore, formato hex 64 char o base32 52 char):<br>
            <textarea class="fm-import-recovery" rows="2" autocomplete="off"
                spellcheck="false" placeholder="es. <INSERIRE_QUI_LA_NUOVA_KEY>"></textarea>
        </label>
        <label class="fm-import-strategy">
            <strong>In caso di conflitto:</strong>
            <select class="fm-import-strategy-sel">
                <option value="rename">Rinomina (aggiungi suffisso "imp YYYY-MM-DD" e tieni entrambe le versioni)</option>
                <option value="skip">Salta (mantieni solo l'esistente, scarta il file in arrivo)</option>
            </select>
        </label>
    `;
}

function renderStep3(body, report) {
    const created = report.created || [];
    const conflicts = report.conflicts || [];
    const errors = report.errors || [];
    const unsupported = report.unsupported || [];
    body.innerHTML = `
        <p>Anteprima import (nessun dato ancora scritto):</p>
        <ul class="fm-import-summary">
            <li>✓ <strong>${created.length}</strong> nuovi da creare</li>
            <li>⚠ <strong>${conflicts.length}</strong> conflitti</li>
            <li>❌ <strong>${errors.length}</strong> errori</li>
            <li>⏭ <strong>${unsupported.length}</strong> non supportati (PDF e template — i PDF si rigenerano compilando le verifiche TEX, i template sono gestiti separatamente)</li>
        </ul>
        ${conflicts.length ? `
        <div class="fm-import-info" style="margin-top:.5em;padding:.5em .75em;background:rgba(245,158,11,.12);border-left:3px solid #f59e0b;border-radius:4px;font-size:.85rem;line-height:1.4">
            <strong>Cos'è un conflitto?</strong> Hai già un contenuto con stesso titolo e materia nel tuo account (e per le verifiche anche stessa variante A/B+SOL/NOR/DSA/DIS).
            Strategia scelta: <strong>${escapeHtml(conflicts[0]?.resolution || '?')}</strong>${conflicts[0]?.resolution === 'rename' ? ` (verrà aggiunto suffisso <em>(imp YYYY-MM-DD)</em> al titolo importato; mantieni entrambe le versioni)` : ` (l'esistente resta intatto, il file in conflitto viene saltato)`}.
        </div>` : ""}
        ${errors.length ? `<details style="margin-top:.5em"><summary>Errori (${errors.length})</summary><ul>${
            errors.slice(0, 20).map(e => `<li><code>${escapeHtml(e.path)}</code> — ${escapeHtml(e.reason)}</li>`).join("")
        }</ul></details>` : ""}
        ${conflicts.length ? `<details style="margin-top:.5em"><summary>Dettaglio conflitti (${conflicts.length})</summary><ul>${
            conflicts.slice(0, 20).map(c => `<li><code>${escapeHtml(c.title)}</code> (${escapeHtml(c.type)}) — ${escapeHtml(c.resolution)}</li>`).join("")
        }</ul></details>` : ""}
        <p style="margin-top:1em">Confermi l'import? Verranno scritti nel tuo account
        <strong>${created.length + (conflicts.length)}</strong> elementi.</p>
    `;
}

function renderStep4(body, applyReport) {
    body.innerHTML = `
        <p>✓ Import completato:</p>
        <ul class="fm-import-summary">
            <li>Creati: <strong>${applyReport.applied || 0}</strong></li>
            <li>Errori: <strong>${(applyReport.errors || []).length}</strong></li>
            <li>Conflitti gestiti: <strong>${(applyReport.conflicts || []).length}</strong></li>
        </ul>
        <p>Puoi chiudere questa finestra.</p>
    `;
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}

async function runImportFlow() {
    const fs = window.FM?.FsAccess;
    if (!fs?.isSupported?.()) {
        notify("📥 Import", "error", "File System Access API non supportata. Usa Chrome/Edge desktop.", 5000);
        return;
    }

    const modal = buildModal();
    const body = modal.querySelector(".fm-import-body");
    const nextBtn = modal.querySelector(".fm-import-next");
    const cancelBtn = modal.querySelector(".fm-import-cancel");
    const cleanup = () => { modal.remove(); };
    cancelBtn.addEventListener("click", () => cleanup());
    modal.querySelector(".fm-import-backdrop").addEventListener("click", () => cleanup());

    // ─── Step 1: pick folder + walk + find manifest.json
    renderStep1(body);
    let manifest = null;
    let filesIndex = null; // Map<path, File>
    let manifestPrefix = ""; // prefix relativo se manifest.json non è alla root

    const pickBtn = body.querySelector(".fm-import-pick");
    const statusEl = body.querySelector(".fm-import-status");
    pickBtn.addEventListener("click", async () => {
        try {
            const root = await fs.pickFolderOneShot();
            statusEl.textContent = "Scansione cartella…";
            const all = await fs.walkAll(root);
            const manifestEntry = all.find(e => e.path.endsWith("manifest.json"));
            if (!manifestEntry) {
                statusEl.textContent = "❌ manifest.json non trovato nella cartella. Verifica che sia un bundle valido.";
                return;
            }
            manifestPrefix = manifestEntry.path.replace(/manifest\.json$/, "");
            const txt = await manifestEntry.file.text();
            manifest = JSON.parse(txt);
            filesIndex = new Map();
            for (const e of all) {
                if (e.path === manifestEntry.path) continue;
                // strip prefix per matchare i path del manifest server-side
                const rel = manifestPrefix && e.path.startsWith(manifestPrefix)
                    ? e.path.slice(manifestPrefix.length)
                    : e.path;
                filesIndex.set(rel, e.file);
            }
            statusEl.textContent = `✓ Trovati ${all.length - 1} file + manifest. Procedi.`;
            nextBtn.disabled = false;
        } catch (err) {
            if (err.name === "AbortError") { statusEl.textContent = ""; return; }
            statusEl.textContent = `❌ ${err.message}`;
        }
    });

    // ─── Step 2: recovery code + strategy
    await new Promise(resolve => {
        const handler = async () => {
            if (!manifest) return;
            nextBtn.removeEventListener("click", handler);
            renderStep2(body, manifest);
            nextBtn.disabled = false;
            resolve();
        };
        nextBtn.addEventListener("click", handler);
    });

    const recoveryCode = await new Promise(resolve => {
        const handler = () => {
            const code = body.querySelector(".fm-import-recovery").value.trim();
            const strategy = body.querySelector(".fm-import-strategy-sel").value;
            if (!code) return;
            nextBtn.removeEventListener("click", handler);
            resolve({ code, strategy });
        };
        nextBtn.addEventListener("click", handler);
    });

    // ─── Step 3: preview (NO files, solo manifest → server verifica HMAC
    // su manifest e classifica entries usando solo metadata). Risparmia
    // memory: niente upload b64 a questo step.
    body.innerHTML = "<p>⏳ Verifica firma e analisi bundle in corso…</p>";
    nextBtn.disabled = true;
    let previewReport = null;
    try {
        const resp = await postJson("/api/teacher/import-bundle/preview", {
            recovery_code: recoveryCode.code,
            manifest,
            files: [], // dry-run senza payload pesante
            conflict_strategy: recoveryCode.strategy,
        });
        if (resp.status !== 200 || !resp.body.ok) {
            body.innerHTML = `<p>❌ Errore: ${escapeHtml(resp.body.error || resp.status)}</p>`;
            nextBtn.textContent = "Chiudi";
            nextBtn.disabled = false;
            nextBtn.addEventListener("click", () => cleanup(), { once: true });
            return;
        }
        previewReport = resp.body.report;
        renderStep3(body, previewReport);
        nextBtn.textContent = "Applica";
        nextBtn.disabled = false;
    } catch (err) {
        body.innerHTML = `<p>❌ Errore preview: ${escapeHtml(err.message)}</p>`;
        nextBtn.textContent = "Chiudi";
        nextBtn.disabled = false;
        nextBtn.addEventListener("click", () => cleanup(), { once: true });
        return;
    }

    // ─── Step 4: apply chunked — modal CHIUDE, progress vive nel sync-panel
    // (toast in basso a destra). User può navigare durante l'import.
    await new Promise(resolve => {
        const handler = () => {
            nextBtn.removeEventListener("click", handler);
            resolve();
        };
        nextBtn.addEventListener("click", handler);
    });
    // Chiude modal immediatamente, apre sync-panel session.
    cleanup();
    openSession("📥 Import bundle");
    logLine(`Inizio import: ${manifest.files?.length || 0} file totali`, "info");

    const BATCH_SIZE = 5; // file per chunk. 5 × ~5 MB drawio = ~25 MB body max.
    const allEntries = (manifest.files || []).filter(e => filesIndex.has(e.path));
    const totalEntries = allEntries.length;
    const cumulative = { created: [], conflicts: [], skipped: [], unsupported: [], errors: [], applied: 0 };
    let processed = 0;
    try {
        for (let i = 0; i < totalEntries; i += BATCH_SIZE) {
            const batchEntries = allEntries.slice(i, i + BATCH_SIZE);
            const batchFiles = [];
            for (const entry of batchEntries) {
                const file = filesIndex.get(entry.path);
                if (!file) continue;
                const buf = await file.arrayBuffer();
                batchFiles.push({ path: entry.path, content_b64: bufferToBase64(buf) });
            }
            const batchNum = Math.floor(i / BATCH_SIZE) + 1;
            const totalBatches = Math.ceil(totalEntries / BATCH_SIZE);
            const resp = await postJson("/api/teacher/import-bundle/apply", {
                recovery_code: recoveryCode.code,
                manifest,
                files: batchFiles,
                conflict_strategy: recoveryCode.strategy,
            });
            if (resp.status !== 200 || !resp.body.ok) {
                logLine(`❌ batch ${batchNum}/${totalBatches}: ${resp.body.error || resp.status}`, "error");
                cumulative.errors.push({ path: `batch_${i}`, reason: resp.body.error || resp.status });
                continue;
            }
            const r = resp.body.report;
            cumulative.created.push(...(r.created || []));
            cumulative.conflicts.push(...(r.conflicts || []));
            cumulative.skipped.push(...(r.skipped || []).filter(s => s.reason !== "not_in_manifest"));
            cumulative.unsupported.push(...(r.unsupported || []));
            cumulative.errors.push(...(r.errors || []));
            cumulative.applied += r.applied || 0;
            processed += batchEntries.length;
            setProgress(processed, totalEntries);
            // Log batch every 10 to avoid panel overflow
            if (batchNum % 10 === 0 || batchNum === totalBatches) {
                logLine(`✓ batch ${batchNum}/${totalBatches} — ${cumulative.applied} applied cumulative`, "ok");
            }
        }
        const summary = `Fine. ${cumulative.applied} importati, ${cumulative.errors.length} errori, ${cumulative.conflicts.length} conflitti gestiti.`;
        logLine(summary, cumulative.errors.length ? "error" : "ok");
    } catch (err) {
        logLine(`❌ apply: ${err.message}`, "error");
    } finally {
        // Modal già chiuso prima del loop apply. Sync-panel si chiude da solo
        // dopo delayMs. User può continuare a navigare durante import.
        closeSession({ delayMs: 6000 });
    }
}

function bufferToBase64(buf) {
    const bytes = new Uint8Array(buf);
    let bin = "";
    const chunk = 8192;
    for (let i = 0; i < bytes.length; i += chunk) {
        bin += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
    }
    return btoa(bin);
}

/** Iniezione del 5° bottone DENTRO la sync-bar (stessa riga, a destra dei
 *  4 pulsanti sync), separato visivamente da border-left. Evita wrap su
 *  viewport stretti. */
export function injectImportBtn() {
    const linksContainer = document.querySelector(
        ".fm-session-banner--teacher .fm-session-links"
    );
    if (!linksContainer) return;
    if (linksContainer.querySelector(".fm-session-import-btn")) return;

    // L'import DEVE essere l'ultimo bottone DENTRO .fm-sync-bar (a destra del
    // gruppo sync). La bar è creata da drive-sync-buttons.js: se quel modulo
    // non ha ancora girato, riprovo a breve invece di cadere in un fallback
    // che posizionava l'import FUORI dalla bar (prima del "Sync:") lasciandolo
    // detached a sinistra — il check anti-duplicato poi lo bloccava lì.
    const target = linksContainer.querySelector(".fm-sync-bar");
    if (!target) {
        injectImportBtn._retries = (injectImportBtn._retries || 0) + 1;
        if (injectImportBtn._retries <= 40) setTimeout(injectImportBtn, 100);
        return;
    }

    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "fm-session-import-btn fm-sync-btn fm-sync-btn--import";
    btn.title = "Importa un bundle scaricato in precedenza (richiede Recovery Key)";
    btn.setAttribute("aria-label", "Importa bundle");
    btn.innerHTML = ICON_IMPORT;
    btn.addEventListener("click", e => {
        e.preventDefault();
        runImportFlow().catch(err => notify("📥 Import", "error", err.message, 5000));
    });
    target.appendChild(btn);
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", injectImportBtn, { once: true });
} else {
    injectImportBtn();
}

window.FM = window.FM || {};
window.FM.ImportBundle = { injectImportBtn, runImportFlow };
