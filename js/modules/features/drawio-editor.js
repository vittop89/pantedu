/**
 * Phase G4 — Drawio editor overlay per mappe con blob locale cifrato.
 *
 * G22.S15.bis Fase 5 — drawio webapp self-hosted da /drawio-app/ (no
 * piu' embed.diagrams.net). Same-origin → cookie auth funziona, niente
 * CORS, niente token signed pubblici, librerie servite via endpoint
 * normale /api/teacher/drawio/libraries/read/{name}.
 *
 * Apre /drawio-app/ con proto=json + dark=0 + saveAndExit=1 caricando
 * l'XML decifrato dal blob server-side via signed URL TTL 600s. Il save
 * postMessage ritorna il nuovo XML che POSTiamo a /api/maps/{id}/update
 * con map_version per optimistic concurrency.
 *
 * API esposta:
 *   openDrawioEditor({ contentId, mode: 'edit'|'view'|'copy' })
 *
 * Mode:
 *   - 'edit' : owner only, save → POST /api/maps/{id}/update (in-place)
 *   - 'view' : read-only, no save button
 *   - 'copy' : open editable, save → DOWNLOAD del .drawio sul device
 *              dell'utente (no server save).
 */

import { fetchCsrf } from "../core/dom-utils.js";

// G22.S15.bis Fase 5 — URL ESPLICITA index.html per bypassare il problema
// nginx VPS: nginx default serve solo `index.php` come index, /drawio-app/
// (senza file finale) → 403 perche' non c'e' index.php in quella dir.
// Apache funziona comunque (DirectoryIndex include index.html nel parent).
const DRAWIO_BASE = "/drawio-app/index.html";

export async function openDrawioEditor({ contentId, mode = "edit" }) {
    if (!contentId) throw new Error("contentId mancante");
    const validModes = ["edit", "view", "copy"];
    if (!validModes.includes(mode)) throw new Error(`mode invalida: ${mode}`);

    const [row, signed] = await Promise.all([
        fetchRowWithMap(contentId),
        fetchSignedUrl(contentId, mode === "edit" ? "view" : mode),
    ]);
    if (!row) {
        throw new Error("Mappa non trovata.");
    }
    if (!row.map_blob_path) {
        throw new Error("Questa mappa e' un link legacy, non e' modificabile in-app. Usa la modalita' classica.");
    }

    const xml = await fetchXml(signed.url);
    const initialVersion = Number(row.map_version || 0);

    // G22.S15.bis Fase 5 — le librerie docente vengono caricate dal plugin
    // drawio (pantedu-library-relay.js) come "open file" editabili nel
    // sidebar (con matita). Niente clibs= URL ne' postMessage parent-side:
    // il plugin fa fetch same-origin diretto e usa libraryLoaded API.

    return new Promise((resolve, reject) => {
        const overlay = buildOverlay({ mode, contentId });
        document.body.appendChild(overlay);

        const iframe = overlay.querySelector("iframe");
        const close  = overlay.querySelector(".fm-drawio-close");
        const status = overlay.querySelector(".fm-drawio-status");

        function teardown() {
            window.removeEventListener("message", onMsg);
            overlay.remove();
        }

        close.addEventListener("click", () => {
            teardown();
            resolve({ saved: false });
        });

        let savePending = false;
        let saveCompleted = false;
        let currentVersion = initialVersion;

        async function onMsg(e) {
            if (e.source !== iframe.contentWindow) return;
            let data;
            try { data = JSON.parse(e.data); } catch { return; }

            console.log(`[drawio] event=${data?.event}`, data);

            if (data?.event === "init") {
                iframe.contentWindow.postMessage(JSON.stringify({
                    action: "load",
                    xml: xml || "",
                    autosave: 0,
                }), "*");
                if (mode === "view") {
                    status.textContent = "👁 Visualizzazione (read-only)";
                } else {
                    status.textContent = `✎ Modifica (versione ${initialVersion})`;
                }
            } else if (data?.event === "save") {
                if (mode === "view") return;
                if (savePending) {
                    console.warn("[drawio] save event mentre ne ho gia' uno in corso, skip");
                    return;
                }
                savePending = true;
                try {
                    let result;
                    if (mode === "edit") {
                        console.log(`[drawio] POST update id=${contentId} version=${currentVersion} xml.length=${(data.xml || "").length}`);
                        result = await postUpdate(contentId, data.xml || "", currentVersion);
                        currentVersion = result.map_version;
                        status.textContent = `✅ Salvato (v${result.map_version})`;
                        console.log(`[drawio] save OK → v${result.map_version}`);
                    } else if (mode === "copy") {
                        downloadDrawioFile(row.title || `mappa-${contentId}`, data.xml || "");
                        status.textContent = `📥 Copia scaricata`;
                        result = { downloaded: true };
                    }
                    saveCompleted = true;
                    setTimeout(() => {
                        if (saveCompleted && document.body.contains(overlay)) {
                            console.log("[drawio] auto-close post-save");
                            teardown();
                            resolve({ saved: true, version: currentVersion });
                        }
                    }, 400);
                } catch (err) {
                    status.textContent = `❌ ${err.message}`;
                    console.error("[drawio] save error:", err);
                    if (err.message?.includes("version_conflict")) {
                        // Conflitto versione: notifica via SyncPanel non bloccante.
                        try {
                            const { notify } = await import("../ui/sync-panel.js");
                            notify("Drawio", "error",
                                "Conflitto versione: la mappa è stata modificata altrove. Copia il tuo lavoro e ricarica.",
                                0);
                        } catch { /* fallback console */
                            console.error("Conflitto versione drawio");
                        }
                    }
                } finally {
                    savePending = false;
                }
            } else if (data?.event === "fmLibraryUpdate") {
                // G22.S15.bis Fase 5 — plugin drawio (pantedu-library-relay.js)
                // intercetta App.saveLibrary e ci relaya {name, xml}.
                // Persistiamo sul server via /save-content (sovrascrive).
                try {
                    const result = await postLibrarySaveContent(data.name, data.xml);
                    console.log(`[drawio] libreria salvata: ${data.name} (${result.action})`);
                    try {
                        const { notify } = await import("../ui/sync-panel.js");
                        notify("Drawio", "ok",
                            `📚 Libreria "${data.name}" ${result.action === 'created' ? 'creata' : 'aggiornata'}`,
                            3000);
                    } catch { /* fallback */ }
                } catch (err) {
                    console.error("[drawio] save library failed:", err);
                    try {
                        const { notify } = await import("../ui/sync-panel.js");
                        notify("Drawio", "error",
                            `Salvataggio libreria fallito: ${err.message}`, 0);
                    } catch { /* fallback */ }
                }
            } else if (data?.event === "exit") {
                while (savePending) {
                    await new Promise(r => setTimeout(r, 50));
                }
                teardown();
                resolve({ saved: saveCompleted, version: currentVersion });
            }
        }
        window.addEventListener("message", onMsg);
    });
}

function buildOverlay({ mode, contentId }) {
    const overlay = document.createElement("div");
    overlay.className = "fm-drawio-overlay";
    overlay.dataset.fmContentId = String(contentId);

    const src = `${DRAWIO_BASE}?embed=1&proto=json&ui=kennedy&lang=it&dark=0&saveAndExit=1&noSaveBtn=${mode === "view" ? "1" : "0"}&libraries=1`;
    console.log(`[drawio] iframe src: ${src}`);

    overlay.innerHTML = `
        <iframe class="fm-drawio-iframe" allow="fullscreen" src="${src}"></iframe>
        <button type="button" class="fm-drawio-close" aria-label="Chiudi">✖</button>
        <div class="fm-drawio-status" style="position:absolute;bottom:12px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.7);color:#fff;padding:6px 12px;border-radius:4px;font-size:13px;pointer-events:none">
            Caricamento…
        </div>`;
    return overlay;
}

async function fetchRowWithMap(id) {
    const r = await fetch(`/api/teacher/content/${id}`, { credentials: "same-origin" });
    if (!r.ok) return null;
    const j = await r.json();
    return j.content || null;
}

async function fetchSignedUrl(id, mode) {
    const r = await fetch(`/api/maps/${id}/signed-url?mode=${encodeURIComponent(mode)}`, {
        credentials: "same-origin",
        headers: { "Accept": "application/json" },
    });
    const j = await r.json();
    if (!r.ok || !j.ok) {
        throw new Error(j.error || `signed_url HTTP ${r.status}`);
    }
    return j;
}

async function fetchXml(signedPath) {
    const r = await fetch(signedPath, { credentials: "same-origin" });
    if (!r.ok) throw new Error(`download HTTP ${r.status}`);
    return await r.text();
}

/**
 * G22.S15.bis Fase 5 — Salva XML libreria sovrascrivendo file teacher.
 * Usato dal listener postMessage fmLibraryUpdate (plugin drawio).
 */
async function postLibrarySaveContent(name, xml) {
    const csrf = await fetchCsrf();
    const r = await fetch("/api/teacher/drawio/libraries/save-content", {
        method: "POST",
        credentials: "same-origin",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": csrf,
        },
        body: JSON.stringify({ _csrf: csrf, name, xml }),
    });
    const j = await r.json();
    if (!r.ok || !j.ok) {
        throw new Error(j.error || `save-content HTTP ${r.status}`);
    }
    return j;
}

async function postUpdate(id, xml, version) {
    const csrf = await fetchCsrf();
    const fd = new URLSearchParams();
    fd.set("_csrf", csrf);
    fd.set("xml", xml);
    fd.set("map_version", String(version));
    const r = await fetch(`/api/maps/${id}/update`, {
        method: "POST", credentials: "same-origin",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-CSRF-Token": csrf,
        },
        body: fd.toString(),
    });
    const j = await r.json();
    if (!r.ok || !j.ok) {
        throw new Error(j.error || `update HTTP ${r.status}`);
    }
    return j;
}

function downloadDrawioFile(baseTitle, xml) {
    const safeName = String(baseTitle)
        .replace(/[\\/:*?"<>|]+/g, "_")
        .replace(/\s+/g, " ")
        .trim()
        .slice(0, 80) || "mappa";

    const blob = new Blob([xml], { type: "application/xml" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `${safeName}.drawio`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    console.log(`[drawio] download .drawio: ${safeName} (${xml.length} bytes)`);
}

window.FM = window.FM || {};
window.FM.DrawioEditor = { openDrawioEditor };
