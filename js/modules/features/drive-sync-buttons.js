/**
 * Phase G3.a — Drive sync buttons (scaffold UI).
 * Phase G7 — semplificato: SOLO sync globale nella session banner.
 *
 * G22.S15.bis Fase 5 — refactored:
 *   - Panel UI (logLine/setProgress/notify/openSession) → sync-panel.js
 *   - Log persistence (localStorage) → sync-log-store.js
 * Questo modulo orchestrale la sync logic e usa i moduli UI come consumer.
 */
import {
    openSession, closeSession, logLine, setProgress, notify as panelNotify,
    registerAbort, clearAbort, hasActiveAbort,
} from "../ui/sync-panel.js";
import { persistSyncLog } from "../ui/sync-log-store.js";
import { fetchJson, fetchCsrf } from "../core/dom-utils.js";

const SYNC_GLOBAL_CLASS = "fm-session-drive-sync";
const SYNC_BAR_CLASS    = "fm-sync-bar";

const ICON_DRIVE = `<svg class="fm-drive-icon" viewBox="0 0 87.3 78" aria-hidden="true">
    <path fill="#0066da" d="M6.6 66.85l3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8H0c0 1.55.4 3.1 1.2 4.5z"/>
    <path fill="#00ac47" d="M43.65 25l-13.75-23.8c-1.35.8-2.5 1.9-3.3 3.3l-25.4 44A9.06 9.06 0 0 0 0 53h27.5z"/>
    <path fill="#ea4335" d="M73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5H59.8l5.85 11.5z"/>
    <path fill="#00832d" d="M43.65 25L57.4 1.2C56.05.4 54.5 0 52.9 0H34.4c-1.6 0-3.15.45-4.5 1.2z"/>
    <path fill="#2684fc" d="M59.8 53H27.5L13.75 76.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.2z"/>
    <path fill="#ffba00" d="M73.4 26.5l-12.7-22c-.8-1.4-1.95-2.5-3.3-3.3L43.65 25 59.8 53h27.45c0-1.55-.4-3.1-1.2-4.5z"/>
</svg>`;

const ICON_LOCAL = `<svg class="fm-local-icon" viewBox="0 0 24 24" aria-hidden="true" width="14" height="14">
    <path fill="currentColor" d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/>
</svg>`;

// G19.48 — GitHub Octocat (placeholder, future implementation).
const ICON_GITHUB = `<svg class="fm-github-icon" viewBox="0 0 24 24" aria-hidden="true" width="14" height="14">
    <path fill="currentColor" d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.4 3-.405 1.02.005 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/>
</svg>`;

// G22.S15.bis Fase 5 — wrapper attorno a panelNotify. Mappa kind UI
// (success/info/warning/error) → kind del SyncPanel (ok/info/error).
function ensureToast(message, kind = "info", title = "Sync") {
    const panelKind = kind === "success" ? "ok"
                    : kind === "warning" ? "info"
                    : (kind === "error" ? "error" : "info");
    try { panelNotify(title, panelKind, message, 4000); }
    catch { console.info(`[${title.toLowerCase()}]`, message); }
}

async function postJson(url, fd, headers = {}, abortSignal = null) {
    const csrf = await fetchCsrf();
    fd.set("_csrf", csrf);
    const r = await fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: { "X-CSRF-Token": csrf, "Accept": "application/json", ...headers },
        body: fd,
        signal: abortSignal,
    });
    const ct = r.headers.get("content-type") || "";
    if (!ct.includes("application/json")) {
        throw new Error(`HTTP ${r.status} (no JSON)`);
    }
    const j = await r.json();
    return { status: r.status, body: j };
}

// ─────── Global sync (session banner) ───────

/** G19.48 — Garantisce un wrapper `.fm-sync-bar` con label "Sync:" dentro
 *  `.fm-session-links`. Idempotente. Ritorna il container per i 3 button.
 */
function ensureSyncBar(linksContainer) {
    let bar = linksContainer.querySelector(`.${SYNC_BAR_CLASS}`);
    if (bar) return bar;
    bar = document.createElement("span");
    bar.className = SYNC_BAR_CLASS;
    const lbl = document.createElement("span");
    lbl.className = "fm-sync-bar__label";
    lbl.textContent = "Sync:";
    bar.appendChild(lbl);
    linksContainer.appendChild(bar);
    return bar;
}

function injectGlobalSync() {
    const linksContainer = document.querySelector(
        ".fm-session-banner--teacher .fm-session-links"
    );
    if (!linksContainer) return; // non teacher session
    if (linksContainer.querySelector(`.${SYNC_GLOBAL_CLASS}`)) return; // already

    const bar = ensureSyncBar(linksContainer);

    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = `${SYNC_GLOBAL_CLASS} fm-sync-btn fm-sync-btn--drive`;
    btn.title = "Sincronizza TUTTE le tue mappe + verifiche con Google Drive";
    btn.setAttribute("aria-label", "Sync Drive");
    btn.innerHTML = ICON_DRIVE;
    btn.dataset.state = "idle";
    btn.addEventListener("click", async (e) => {
        e.preventDefault();

        // Phase G7 — durante sync, click sul btn principale FERMA la sync
        // (oltre al ✖ nel panel floating). Affordance dual: stop ovunque.
        if (btn.dataset.state === "syncing" && btn._fmAbortCtrl) {
            btn._fmAborted = true;
            btn._fmAbortCtrl.abort();
            return;
        }

        btn.dataset.state = "syncing";
        const origInner = btn.innerHTML;
        btn.innerHTML = "⏸";
        btn.title = "Click per fermare la sincronizzazione in corso";

        // Phase G7 — sync incrementale a batch da 20 con log live + abort.
        openSession("☁ Sync Drive");
        let done = 0;
        const total = 0;
        let aborted = false;
        const abortCtrl = new AbortController();
        btn._fmAbortCtrl = abortCtrl;
        btn._fmAborted = false;
        // G19.48b — registra come abort attivo cosi' il ✖/Stop del panel
        // (e qualunque altro entry-point) puo' interromperla.
        const abortTarget = {
            ctrl: abortCtrl,
            flagAborted: () => {
                aborted = true;
                btn._fmAborted = true;
                logLine("⚠ Sync fermato dall'utente.", "error");
            },
        };
        registerAbort(abortTarget);
        logLine("Inizio sync incrementale (batch 20)…", "info");
        const startedAt = Date.now();
        const tally = { ok: 0, skip: 0, error: 0 };

        // G19.47 — helper riusabile: drena un endpoint sync-all (maps o
         // verifiche) in batch fino a count=0. Aggiorna tally + log.
         // G19.49e — timeout per-batch 180s (Drive API folder resolve +
         // create/update di 20 file puo' arrivare a ~2 min). Aggiunto
         // heartbeat log all'inizio di ogni batch per feedback all'utente.
        // G22.S15.bis Fase 5 — batch ridotto da 20 a 5 per feedback più
        // frequente all'utente (ogni ~10s invece di ~30s). Sync completo
        // più lungo in totale (più round-trip), ma UX più viva.
        async function drainEndpoint(endpoint, label) {
            const BATCH_SIZE = 5;
            // G22.S15.bis Fase 5 — safety: track ID ricevuti. Se 2 batch
            // consecutivi ritornano gli stessi ID → loop server-side
            // (orphan non escluso dalla query) → break per evitare
            // log gonfio + chiamate inutili.
            let lastIds = "";
            let sameIdsCount = 0;
            for (let batch = 0; batch < 200 && !aborted && !btn._fmAborted; batch++) {
                logLine(`📤 ${label} batch ${batch+1} (max ${BATCH_SIZE} file)…`, "info");
                const tid = setTimeout(() => abortCtrl.abort(), 180000);
                const fd = new FormData();
                fd.set("limit", String(BATCH_SIZE));
                fd.set("onlyChanged", "1");
                let resp;
                try {
                    resp = await postJson(endpoint, fd, {}, abortCtrl.signal);
                } finally { clearTimeout(tid); }
                if (!resp.body.ok) {
                    const msg = `${label} batch ${batch+1}: ${resp.body.error || resp.status}`;
                    logLine(`❌ ${msg}`, "error");
                    persistSyncLog("drive", "error", msg, { label, batch });
                    break;
                }
                const r = resp.body.report;
                if (r.count === 0 && batch === 0) {
                    logLine(`✓ Nessuna ${label} da pushare.`, "ok");
                    break;
                }
                if (r.count === 0) break;
                // G22.S15.bis Fase 5 — detect loop server-side (stessi ID
                // ritornati 2 volte di seguito → orphan non escluso).
                const currentIds = (r.items || []).map(it => it.id).sort().join(",");
                if (currentIds === lastIds) {
                    sameIdsCount++;
                    if (sameIdsCount >= 1) {
                        logLine(`⚠ Loop rilevato: stessi ${label} ID ricevuti 2 volte. Stop.`, "error");
                        logLine(`   Causa probabile: orphan non escluso server-side. Pulisci con cleanup-orphans.`, "info");
                        break;
                    }
                } else {
                    sameIdsCount = 0;
                    lastIds = currentIds;
                }
                for (const it of (r.items || [])) {
                    done++;
                    const action = it.action || "?";
                    const icon = action === "created" ? "🆕"
                               : action === "updated" ? "🔄"
                               : action === "skipped" ? "⏭"
                               : action === "orphan"  ? "⚠"
                               : "❌";
                    const id = it.drive_file_id ? ` → drive:${it.drive_file_id.slice(0, 12)}…` : "";
                    const errInfo = it.error ? ` (${it.error})` : "";
                    const lineKind = action === "failed" ? "error"
                                   : action === "orphan" ? "info"
                                                         : "ok";
                    logLine(`${icon} ${label} #${it.id} ${action}${id}${errInfo}`, lineKind);
                    if (action === "failed") {
                        tally.error++;
                        persistSyncLog("drive", "error",
                            `${label} #${it.id}: ${it.error || "fail"}`,
                            { label, id: it.id });
                    } else if (action === "orphan") {
                        tally.orphan = (tally.orphan || 0) + 1;
                        // Persist come info, non error: ammasso nel log
                        persistSyncLog("drive", "info",
                            `${label} #${it.id}: blob mancante (orphan)`,
                            { label, id: it.id });
                    } else if (action === "skipped") tally.skip++;
                    else tally.ok++;
                }
                // Delete-orphans report (verifiche only)
                for (const d of (r.deleted_items || [])) {
                    const errInfo = d.error ? ` (${d.error})` : "";
                    logLine(`🗑 ${label} orphan: ${d.name}${errInfo}`,
                        d.error ? "error" : "ok");
                    if (!d.error) tally.deleted = (tally.deleted || 0) + 1;
                }
                setProgress(done, done + r.count);
                // G22.S15.bis Fase 5 — drive_token_expired: backend ha
                // rilevato invalid_grant e abortito → abort full sync,
                // suggerisci riconnessione, non flooda errori.
                if (r.drive_token_expired) {
                    logLine("⚠ Token Google Drive scaduto/revocato.", "error");
                    logLine("   Vai in Dashboard → ☁ Google Drive → Disconnetti, poi riconnetti.", "info");
                    persistSyncLog("drive", "error",
                        "Token Drive scaduto: riconnessione richiesta in Dashboard");
                    btn._fmAborted = true;
                    aborted = true;
                    return; // exit drainEndpoint helper
                }
                if (r.count < BATCH_SIZE || r.error > 0) break;
            }
        }

        try {
            await drainEndpoint("/api/maps/sync-all", "mappa");
            if (!aborted && !btn._fmAborted) {
                logLine("→ passaggio a verifiche…", "info");
            }
            await drainEndpoint("/api/verifica/sync-all", "verifica");

            const elapsed = ((Date.now() - startedAt) / 1000).toFixed(1);
            const delPart = tally.deleted ? ` · ${tally.deleted} deleted` : "";
            const orphanPart = tally.orphan ? ` · ${tally.orphan} orphan` : "";
            const summary = `Fine. ${tally.ok} OK · ${tally.skip} skip · ${tally.error} errori${orphanPart}${delPart} · ${elapsed}s`;
            logLine(summary, tally.error ? "error" : "ok");
            ensureToast(`☁ ${  summary}`, tally.error ? "error" : "success");
            persistSyncLog("drive", tally.error ? "error" : "ok", summary, tally);
            // G22.S15.bis Fase 5 — suggerimento cleanup se ci sono orphan
            if (tally.orphan > 0) {
                logLine(`⚠ ${tally.orphan} verifiche/mappe orfane (riga DB ma blob mancante).`, "info");
                logLine(`   Pulisci con: POST /api/teacher/sync/cleanup-orphans`, "info");
            }
        } catch (err) {
            const msg = err.name === "AbortError" ? "annullato/timeout" : err.message;
            logLine(`❌ ${msg}`, "error");
            ensureToast(`Errore sync: ${  msg}`, "error");
            persistSyncLog("drive", "error", msg);
        } finally {
            closeSession({ delayMs: 6000 });
            btn.dataset.state = "idle";
            btn.innerHTML = origInner;
            btn.title = "Sincronizza TUTTE le tue mappe + verifiche con Google Drive";
            btn._fmAbortCtrl = null;
            btn._fmAborted = false;
            clearAbort(abortTarget);
        }
    });
    bar.appendChild(btn);
    injectLocalSync(linksContainer);
    injectGithubSync(linksContainer);
    injectSyncAll(linksContainer);
}

/**
 * G19.47 — Bottone "Sync locale" accanto al Drive sync. Scarica TUTTI i
 * file (mappe + verifiche) del docente e li scrive nella cartella FS
 * Access pairata (stessa root usata da VSC button). Permette mirroring
 * completo locale dei contenuti server-side.
 */
function injectLocalSync(linksContainer) {
    if (!linksContainer || linksContainer.querySelector(".fm-session-local-sync")) return;
    const bar = ensureSyncBar(linksContainer);
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "fm-session-local-sync fm-sync-btn fm-sync-btn--local";
    btn.title = "Salva una copia di tutte le tue mappe e verifiche nella cartella sul tuo computer (configurabile in Dashboard → Cartella locale)";
    btn.setAttribute("aria-label", "Sync locale");
    btn.innerHTML = ICON_LOCAL;
    btn.dataset.state = "idle";
    btn.addEventListener("click", async (e) => {
        e.preventDefault();

        // G19.48b — click durante syncing → abort. Affordance dual con il
        // bottone ✖/⏸ nel panel floating.
        if (btn.dataset.state === "syncing" && btn._fmAbortCtrl) {
            btn._fmAborted = true;
            btn._fmAbortCtrl.abort();
            return;
        }

        const fs = window.FM?.FsAccess;
        if (!fs?.isSupported?.()) {
            ensureToast("File System Access API non supportata. Usa Chrome/Edge desktop.", "error");
            return;
        }
        let root = await fs.getRoot();
        if (!root) {
            ensureToast("Scegli prima una cartella radice via ⚙ in topbar.", "warning");
            try { root = await fs.pickRoot(); } catch (_) { return; }
        }
        if (!(await fs.getOrRequestPermission(root, "readwrite"))) {
            ensureToast("Permesso scrittura negato.", "error");
            return;
        }
        // G19.48b — pre-flight: verifica che il handle sia ancora valido
        // (l'utente potrebbe aver cancellato/spostato la cartella → handle
        // diventa orfano e ogni writeFile fallisce con NotFoundError).
        try {
            // values() throws NotFoundError immediato se il dir non esiste piu'.
            const it = root.values();
            await it.next();
        } catch (e) {
            if (e?.name === "NotFoundError" || /could not be found/i.test(e?.message || "")) {
                await fs.clearRoot();
                ensureToast(
                    "Cartella radice non trovata (cancellata/spostata). Scegli di nuovo la cartella via ⚙ in topbar.",
                    "error",
                );
                return;
            }
            // altri errori: log + abort
            ensureToast(`Errore accesso cartella: ${  e?.message || e}`, "error");
            return;
        }
        btn.dataset.state = "syncing";
        const origInner = btn.innerHTML;
        btn.innerHTML = "⏸";
        btn.title = "Click per fermare la sincronizzazione locale in corso";
        openSession("💾 Sync locale");
        const t0 = Date.now();

        // G19.48b — abort: registra come abort attivo (panel ✖/Stop +
        // click sul btn locale → entrambi triggerano lo stesso target).
        const abortCtrl = new AbortController();
        btn._fmAbortCtrl = abortCtrl;
        btn._fmAborted = false;
        const abortTarget = {
            ctrl: abortCtrl,
            flagAborted: () => {
                btn._fmAborted = true;
                logLine("⚠ Sync locale fermato dall'utente.", "error");
            },
        };
        registerAbort(abortTarget);

        // G19.48 — bundle paginato: drain `?offset=&limit=` finche'
        // !hasMore. Memoria server controllata + progress reale.
        const CHUNK = 20;
        let offset = 0;
        let total  = 0;
        let ok     = 0;
        let err    = 0;
        let firstChunk = true;
        try {
            // G22.S20 — scrivi manifest.json signed alla root del bundle.
            // Permette successivo re-import via 📥 Import. Se Recovery Key
            // non generata: warn + procedi (bundle non-signed, sync OK ma
            // non importabile finché docente non genera Recovery Key).
            try {
                const mr = await fetch("/api/teacher/sync-bundle/manifest", {
                    credentials: "same-origin", signal: abortCtrl.signal,
                });
                const mj = await mr.json();
                if (mr.ok && mj.ok && mj.manifest) {
                    const txt = JSON.stringify(mj.manifest, null, 2);
                    await fs.writeFile(root, "manifest.json", new TextEncoder().encode(txt));
                    logLine(`✓ manifest.json (firmato, ${mj.manifest.files.length} file)`, "ok");
                } else if (mr.status === 412) {
                    logLine("⚠ Manifest non firmato: genera Recovery Key in Dashboard → Sicurezza per abilitare re-import.", "info");
                } else {
                    logLine(`⚠ Manifest skipped: ${mj.error || mr.status}`, "info");
                }
            } catch (e) {
                logLine(`⚠ Manifest non scritto: ${e.message}`, "info");
            }
            while (!btn._fmAborted) {
                const url = `/api/teacher/sync-local-bundle?offset=${offset}&limit=${CHUNK}`;
                const r = await fetch(url, {
                    credentials: "same-origin",
                    signal: abortCtrl.signal,
                });
                let j;
                try { j = await r.json(); }
                catch (_jsonErr) {
                    throw new Error(`risposta server non JSON (HTTP ${r.status})`);
                }
                if (!r.ok || !j.ok) {
                    logLine(`❌ Errore server: ${j.error || r.status}`, "error");
                    ensureToast(`Errore sync locale: ${  j.error || r.status}`, "error");
                    return;
                }
                if (firstChunk) {
                    total = j.total || 0;
                    if (total === 0) {
                        logLine("✓ Nessun file da sincronizzare.", "ok");
                        ensureToast("Nessun file da sincronizzare.", "info");
                        return;
                    }
                    logLine(`Bundle: ${total} file totali, chunk da ${CHUNK}…`, "info");
                    firstChunk = false;
                }
                const files = j.files || [];
                // G19.48b — safety: break se il chunk e' vuoto (loop runaway).
                if (files.length === 0) break;
                let firstFileError = null;
                for (const f of files) {
                    if (btn._fmAborted) break;
                    try {
                        const bytes = Uint8Array.from(atob(f.content), c => c.charCodeAt(0));
                        await fs.writeFile(root, f.path, bytes);
                        ok++;
                        // log compatto: ogni 5 file + l'ultimo del totale
                        if (ok % 5 === 0 || (ok + err) === total) {
                            logLine(`✓ ${f.path}`, "ok");
                        }
                    } catch (e) {
                        err++;
                        if (!firstFileError) firstFileError = e;
                        logLine(`❌ ${f.path}: ${e.message}`, "error");
                        persistSyncLog("local", "error", `${f.path}: ${e.message}`, { path: f.path });
                    }
                    setProgress(ok + err, total);
                }
                // G19.48b — se TUTTI i file del primo chunk falliscono con
                // NotFoundError, il handle e' orfano (cartella cancellata).
                // Abort + clear handle + prompt re-pick.
                if (firstFileError && err === files.length && offset === 0
                    && (firstFileError.name === "NotFoundError"
                        || /could not be found/i.test(firstFileError.message || ""))) {
                    await fs.clearRoot();
                    throw new Error("Cartella radice non trovata. Riconfigurala via ⚙ in topbar.");
                }
                if (btn._fmAborted) break;
                if (!j.hasMore) break;
                // G19.48b — safety: break se offset >= total (server bug guard).
                offset += CHUNK;
                if (offset >= total) break;
            }
            const elapsed = ((Date.now() - t0) / 1000).toFixed(1);
            const aborted = btn._fmAborted;
            const summary = aborted
                ? `Interrotto. ${ok} OK · ${err} errori · ${elapsed}s (di ${total})`
                : `Fine. ${ok} OK · ${err} errori · ${elapsed}s`;
            logLine(summary, (err || aborted) ? "error" : "ok");
            ensureToast(`💾 ${  summary}`, (err || aborted) ? "error" : "success");
            persistSyncLog("local", (err || aborted) ? "error" : "ok", summary, { ok, err, total, aborted });
        } catch (e) {
            const msg = e.name === "AbortError" ? "annullato dall'utente" : e.message;
            logLine(`❌ ${msg}`, "error");
            ensureToast(`Errore sync locale: ${  msg}`, "error");
            persistSyncLog("local", "error", msg);
        } finally {
            closeSession({ delayMs: 6000 });
            btn.dataset.state = "idle";
            btn.innerHTML = origInner;
            btn.title = "Salva una copia di tutte le tue mappe e verifiche nella cartella sul tuo computer (configurabile in Dashboard → Cartella locale)";
            btn._fmAbortCtrl = null;
            btn._fmAborted = false;
            clearAbort(abortTarget);
        }
    });
    bar.appendChild(btn);
}

/**
 * G22.S15.bis Fase 5 — Sync GitHub: push README.md di backup nel repo
 * configurato in Dashboard. Se non configurato, mostra toast con link a
 * Dashboard. Il push completo di tutti i file è in roadmap (vedi design
 * doc /docs/architecture/sync-strategy.md).
 */
function injectGithubSync(linksContainer) {
    if (!linksContainer || linksContainer.querySelector(".fm-session-github-sync")) return;
    const bar = ensureSyncBar(linksContainer);
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "fm-session-github-sync fm-sync-btn fm-sync-btn--github";
    btn.title = "Sync GitHub: salva un backup nel tuo repo (configura in Dashboard docente)";
    btn.setAttribute("aria-label", "Sync GitHub");
    btn.innerHTML = ICON_GITHUB;
    btn.dataset.state = "idle";
    btn.addEventListener("click", async (e) => {
        e.preventDefault();
        if (btn.dataset.state === "syncing") return;
        // Verifica configurazione
        let cfg = null;
        try {
            const j = await fetchJson("/api/teacher/github/status");
            if (j.ok && j.configured) cfg = j.config;
        } catch (_) {}
        if (!cfg) {
            panelNotify("🐙 GitHub", "error",
                "Non configurato. Apri /area-docente/dashboard → 🐙 GitHub per impostare repo + PAT.",
                6000);
            return;
        }
        btn.dataset.state = "syncing";
        const orig = btn.innerHTML;
        btn.innerHTML = "⏳";
        // G22.S15.bis Fase 5 — STREAMING client-side: drena local-bundle a
        // chunk e push file-per-file via /push-file → progress real-time.
        // Vantaggio: stessa UX di Drive/Local, niente blocco UI per 30s.
        openSession("🐙 Sync GitHub");
        logLine(`Repo target: ${cfg.repo_owner}/${cfg.repo_name} (${cfg.branch})`, "info");
        let hasErrors = false;
        const t0 = Date.now();
        let pushed = 0, skipped = 0, errors = 0, total = 0;

        // Abort controller per cancel durante streaming
        const abortCtrl = new AbortController();
        const abortTarget = {
            ctrl: abortCtrl,
            flagAborted: () => {
                logLine("⚠ Sync GitHub fermato dall'utente.", "error");
                btn._fmAborted = true;
            },
        };
        registerAbort(abortTarget);
        btn._fmAbortCtrl = abortCtrl;
        btn._fmAborted = false;

        try {
            const csrfTok = await fetchCsrf();
            // 1. Drena local-bundle (paginato 20 per chunk, stesso del local-sync)
            const CHUNK = 20;
            let offset = 0;
            let firstChunk = true;
            while (!btn._fmAborted) {
                const url = `/api/teacher/sync-local-bundle?offset=${offset}&limit=${CHUNK}`;
                const r = await fetch(url, {
                    credentials: "same-origin", signal: abortCtrl.signal,
                });
                const j = await r.json();
                if (!r.ok || !j.ok) {
                    hasErrors = true;
                    logLine(`❌ Errore bundle: ${j.error || r.status}`, "error");
                    break;
                }
                if (firstChunk) {
                    total = j.total || 0;
                    if (total === 0) {
                        logLine("✓ Nessun file da pushare.", "ok");
                        break;
                    }
                    logLine(`Bundle: ${total} file. Push su GitHub…`, "info");
                    firstChunk = false;
                }
                const files = j.files || [];
                if (files.length === 0) break;
                // 2. Push ogni file via /push-file (un fetch per file)
                for (const f of files) {
                    if (btn._fmAborted) break;
                    try {
                        const pj = await fetchJson("/api/teacher/github/push-file", {
                            method: "POST",
                            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfTok },
                            body: JSON.stringify({
                                path: f.path, content_b64: f.content,
                                _csrf: csrfTok,
                            }),
                            signal: abortCtrl.signal,
                        });
                        if (pj.ok) {
                            const action = pj.action || "?";
                            const icon = action === "created" ? "🆕"
                                       : action === "updated" ? "🔄"
                                       : action === "unchanged" ? "⏭"
                                       : "📤";
                            // log compatto: mostra ogni file
                            logLine(`${icon} ${f.path}`,
                                action === "unchanged" ? "info" : "ok");
                            if (action === "unchanged") skipped++;
                            else pushed++;
                        } else {
                            hasErrors = true;
                            errors++;
                            logLine(`❌ ${f.path}: ${pj.error || "fail"}`, "error");
                            persistSyncLog("github", "error", `${f.path}: ${pj.error || "fail"}`);
                        }
                    } catch (e) {
                        if (e.name === "AbortError") break;
                        hasErrors = true;
                        errors++;
                        logLine(`❌ ${f.path}: ${e.message}`, "error");
                        persistSyncLog("github", "error", `${f.path}: ${e.message}`);
                    }
                    setProgress(pushed + skipped + errors, total);
                }
                if (btn._fmAborted) break;
                if (!j.hasMore) break;
                offset += CHUNK;
                if (offset >= total) break;
            }
            const elapsed = ((Date.now() - t0) / 1000).toFixed(1);
            const summary = btn._fmAborted
                ? `Interrotto. ${pushed} push, ${skipped} skip, ${errors} err (su ${total}) · ${elapsed}s`
                : `Fine. ${pushed} push, ${skipped} skip, ${errors} err (su ${total}) · ${elapsed}s`;
            logLine(summary, hasErrors ? "error" : "ok");
            persistSyncLog("github", hasErrors ? "error" : "ok",
                `${cfg.repo_owner}/${cfg.repo_name}: ${summary}`);
        } catch (err) {
            hasErrors = true;
            const msg = err.name === "AbortError" ? "annullato" : err.message;
            logLine(`❌ ${msg}`, "error");
            persistSyncLog("github", "error", msg);
        } finally {
            if (!hasErrors) closeSession({ delayMs: 6000 });
            btn.dataset.state = "idle";
            btn.innerHTML = orig;
            btn._fmAbortCtrl = null;
            btn._fmAborted = false;
            clearAbort(abortTarget);
        }
    });
    bar.appendChild(btn);
}

// ICON_ALL: doppia freccia download/sync
const ICON_ALL = `<svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14">
    <path fill="currentColor" d="M12 2v6h4l-5 5-5-5h4V2h2zM4 18h16v2H4v-2zM4 14h6v2H4v-2zm10 0h6v2h-6v-2z"/>
</svg>`;

/**
 * G22.S15.bis Fase 5 — Pulsante "Sync tutto": orchestratore che esegue in
 * sequenza Drive sync + Sync locale (e in futuro GitHub). Mostra summary
 * unico al termine. Skip target se non disponibile (es. local senza FS
 * Access pairata, github non configurato).
 */
function injectSyncAll(linksContainer) {
    if (!linksContainer || linksContainer.querySelector(".fm-session-sync-all")) return;
    const bar = ensureSyncBar(linksContainer);
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "fm-session-sync-all fm-sync-btn fm-sync-btn--all";
    btn.title = "Sync tutto in una volta: Google Drive + cartella locale (+ GitHub quando disponibile)";
    btn.setAttribute("aria-label", "Sync tutto");
    btn.innerHTML = ICON_ALL;
    btn.dataset.state = "idle";
    btn.addEventListener("click", async (e) => {
        e.preventDefault();
        if (btn.dataset.state === "syncing") return;  // no abort in all-mode
        btn.dataset.state = "syncing";
        const origInner = btn.innerHTML;
        btn.innerHTML = "⏳";
        const t0 = Date.now();
        try {
            // 1. Drive: clicca il bottone esistente (riusa la sua logica)
            const driveBtn = linksContainer.querySelector(`.${SYNC_GLOBAL_CLASS}`);
            if (driveBtn) {
                ensureToast("Step 1/2: Sincronizzazione Drive…", "info");
                driveBtn.click();
                // Attendi che lo stato torni idle (drive sync è async)
                await waitForBtnIdle(driveBtn, 600_000);
            }
            // 2. Local
            const localBtn = linksContainer.querySelector(".fm-session-local-sync");
            if (localBtn) {
                ensureToast("Step 2/3: Sincronizzazione locale…", "info");
                localBtn.click();
                await waitForBtnIdle(localBtn, 600_000);
            }
            // 3. GitHub (skip se non configurato)
            const githubBtn = linksContainer.querySelector(".fm-session-github-sync");
            if (githubBtn && !githubBtn.disabled) {
                ensureToast("Step 3/3: Sincronizzazione GitHub…", "info");
                githubBtn.click();
                await waitForBtnIdle(githubBtn, 60_000);
            }
            const elapsed = ((Date.now() - t0) / 1000).toFixed(1);
            ensureToast(`✓ Sync tutto completato (${elapsed}s)`, "success");
            persistSyncLog("all", "ok", `Sync orchestrato completato in ${elapsed}s`);
        } catch (err) {
            ensureToast(`Errore sync tutto: ${err.message}`, "error");
            persistSyncLog("all", "error", err.message);
        } finally {
            btn.dataset.state = "idle";
            btn.innerHTML = origInner;
        }
    });
    bar.appendChild(btn);
}

/** Attendi che btn.dataset.state torni "idle" (polling 200ms con timeout). */
async function waitForBtnIdle(btn, timeoutMs = 60_000) {
    const start = Date.now();
    while (btn.dataset.state === "syncing") {
        if (Date.now() - start > timeoutMs) throw new Error("timeout sync");
        await new Promise(r => setTimeout(r, 200));
    }
}

// ─────── Per-item sync (.fm-item-actions) ───────

// ─────── Bootstrap ───────
// Phase G7 — solo sync globale (per-item drive-sync rimosso).

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", injectGlobalSync, { once: true });
} else {
    injectGlobalSync();
}

// Cleanup di eventuali bottoni .fm-item-drive-sync residui da render
// precedenti (post-deploy: idempotent garbage collection).
document.querySelectorAll(".fm-item-drive-sync").forEach(n => n.remove());

window.FM = window.FM || {};
window.FM.DriveSyncButtons = { injectGlobalSync, injectLocalSync, injectGithubSync };
