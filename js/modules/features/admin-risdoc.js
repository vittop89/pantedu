/**
 * Admin panel risdoc per-teacher (Phase 21, U8).
 *
 * Carica /api/admin/risdoc/templates, mostra tabella + azioni:
 *   - toggle visibility per-teacher (matrix mini)
 *   - set owner (select)
 *   - add/remove collaborators (input multi)
 * Tab "Drift" mostra override con source_version != source_hash.
 */

import { esc, fetchCsrf } from "../core/dom-utils.js";

const STATE = { root: null, panel: null, tab: "templates", templates: [], teachers: [] };

let bound = false;

function init() {
    if (bound) return;
    STATE.root = document.getElementById("fm-ar-root");
    if (!STATE.root) return;
    bound = true;

    STATE.panel = document.getElementById("fm-ar-panel");
    STATE.root.querySelectorAll(".fm-ar-tab").forEach(btn => {
        btn.addEventListener("click", () => {
            STATE.root.querySelectorAll(".fm-ar-tab").forEach(t => t.setAttribute("aria-selected", "false"));
            btn.setAttribute("aria-selected", "true");
            STATE.tab = btn.dataset.tab;
            render();
        });
    });

    reloadData();
}

// Phase 24.57 — (ri)carica template + docenti e ridisegna. Usato all'init e
// dopo ogni save/rinomina nella tabella unica (riordino/cambio partizione).
function reloadData() {
    return Promise.all([
        fetch("/api/admin/risdoc/templates", { credentials: "same-origin", cache: "no-store" }).then(r => r.json()),
        fetch("/api/admin/risdoc/teachers",  { credentials: "same-origin", cache: "no-store" }).then(r => r.json()),
    ]).then(([t, u]) => {
        STATE.templates = t.templates || [];
        STATE.teachers  = u.teachers  || [];
        render();
    }).catch(e => {
        STATE.panel.innerHTML = `<div style="color:#c02a2a">Errore: ${esc(e.message)}</div>`;
    });
}

async function render() {
    if (STATE.tab === "drift")   return renderDrift();
    if (STATE.tab === "pending") return renderPending();
    renderTemplates();
}

// G22.S26 — Tab "Modifiche in revisione". Lista pending changes con
// preview body + bottoni approve/reject. Ordinata DESC: newest first.
async function renderPending() {
    STATE.panel.innerHTML = `<div class="fm-ar-loading">Caricamento modifiche in revisione…</div>`;
    let j;
    try {
        // Cache-bust: la lista pending deve essere fresh ogni volta che si
        // apre il tab (Marco potrebbe aver fatto nuovi save dal precedente
        // render). Senza ?_={ts} il browser potrebbe servire da cache.
        const r = await fetch(`/api/admin/risdoc/pending?status=pending&_=${Date.now()}`,
            { credentials: "same-origin", cache: "no-store" });
        j = await r.json();
    } catch (e) {
        STATE.panel.innerHTML = `<div style="color:#c02a2a">Errore: ${esc(e.message)}</div>`;
        return;
    }
    const badge = document.getElementById("fm-ar-pending-badge");
    if (badge) {
        const n = +j.count_pending || 0;
        if (n > 0) { badge.textContent = String(n); badge.style.display = "inline-block"; }
        else { badge.style.display = "none"; }
    }
    const rows = (j.pending || []);
    // G22.S26 — Header con bottone refresh + summary count. Senza, l'admin
    // doveva cliccare il tab in/out per refreshare la lista quando Marco
    // pushava nuovi save.
    const refreshHeader = `
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:6px 0;border-bottom:1px solid rgba(148,163,184,0.2)">
            <strong>${rows.length} pending</strong>
            <span class="fm-muted" style="font-size:12px">(più recenti in cima)</span>
            <span style="flex:1"></span>
            <button type="button" class="fm-btn fm-btn--sm fm-btn--ghost" data-action="refresh-pending">🔄 Aggiorna</button>
        </div>`;

    if (!rows.length) {
        STATE.panel.innerHTML = `<div class="fm-ar-loading">✓ Nessuna modifica in attesa di revisione.</div>`;
        return;
    }
    const items = rows.map(r => {
        const argo = String(r.template_argomento || r.template_code || "").replace(/_/g, " ");
        const dateStr = String(r.submitted_at || "").slice(0, 16).replace("T", " ");
        const isImg = r.content_encoding === "base64";
        return `
            <div class="fm-ar-pending-card" data-pending-id="${r.id}">
                <div class="fm-ar-pending-head">
                    <strong>${esc(argo)}</strong> <code>${esc(r.template_code)}</code>
                    <span class="fm-ar-pill">${esc(r.kind)}</span>
                    ${r.path ? `<code class="fm-ar-pending-path">${esc(r.path)}</code>` : ""}
                </div>
                <div class="fm-ar-pending-meta">
                    da <strong>${esc(r.submitter_username)}</strong> · ${esc(dateStr)} · ${(+r.content_size).toLocaleString()} B
                    ${r.note ? `<div class="fm-ar-pending-note">📝 ${esc(r.note)}</div>` : ""}
                </div>
                <div class="fm-ar-pending-preview" data-loaded="0">
                    <button type="button" class="fm-btn fm-btn--sm fm-btn--ghost" data-action="preview">${isImg ? "🖼 Mostra immagine" : "👁 Mostra contenuto"}</button>
                </div>
                <div class="fm-ar-pending-actions">
                    <input type="text" class="fm-input fm-input--sm" placeholder="Nota (opzionale per approva, obbligatoria per rifiuta)" data-role="reviewer-note" style="flex:1;min-width:200px">
                    <button type="button" class="fm-btn fm-btn--sm fm-btn--primary" data-action="approve" data-id="${r.id}">✓ Approva</button>
                    <button type="button" class="fm-btn fm-btn--sm" style="background:#dc2626;color:#fff" data-action="reject" data-id="${r.id}">✗ Rifiuta</button>
                </div>
            </div>`;
    }).join("");
    STATE.panel.innerHTML = refreshHeader + `<div class="fm-ar-pending-list">${items}</div>`;

    STATE.panel.querySelector('[data-action="refresh-pending"]')?.addEventListener("click", () => renderPending());

    STATE.panel.querySelectorAll(".fm-ar-pending-card").forEach(card => {
        card.querySelector('[data-action="preview"]').addEventListener("click", () => previewPending(card));
        card.querySelector('[data-action="approve"]').addEventListener("click", (e) => approvePending(+e.target.dataset.id, card));
        card.querySelector('[data-action="reject"]').addEventListener("click", (e) => rejectPending(+e.target.dataset.id, card));
    });
}

async function previewPending(card) {
    const previewWrap = card.querySelector(".fm-ar-pending-preview");
    if (previewWrap.dataset.loaded === "1") return;
    const pid = card.dataset.pendingId;
    try {
        const r = await fetch(`/api/admin/risdoc/pending/${pid}/content`, { credentials: "same-origin" });
        const j = await r.json();
        if (!j.ok) { previewWrap.innerHTML = `<em style="color:#c02a2a">Errore: ${esc(j.error)}</em>`; return; }
        // G22.S26 — pid è necessario per buildare l'URL anteprima
        j.pending_id = pid;
        if (j.is_image) {
            previewWrap.innerHTML = `<img alt="preview" style="max-width:100%;max-height:300px;border:1px solid #ccc;border-radius:4px"
                src="data:image/*;base64,${esc(j.content)}">`;
            previewWrap.dataset.loaded = "1";
            return;
        }
        // G22.S26 — Per kind=schema (JSON) e altri kind testuali: pretty-print
        // + diff vs baseline. Per JSON validi facciamo JSON.stringify(_, 2)
        // così le righe sono confrontabili strutturalmente.
        const isJson = (j.kind === "schema" || j.kind === "json")
                    || /^\s*[\{\[]/.test(j.content);
        const fmt = (s) => {
            if (!isJson) return s;
            try { return JSON.stringify(JSON.parse(s), null, 2); } catch { return s; }
        };
        const proposed = fmt(j.content);
        const baseline = j.baseline != null ? fmt(j.baseline) : null;
        if (baseline === null) {
            // Nessuna baseline disponibile: solo pretty-print della proposta.
            previewWrap.innerHTML = renderPreviewOnly(proposed, j);
        } else {
            previewWrap.innerHTML = renderDiffView(baseline, proposed, j);
            bindDiffToggle(previewWrap);
        }
        previewWrap.dataset.loaded = "1";
    } catch (e) {
        previewWrap.innerHTML = `<em style="color:#c02a2a">Errore: ${esc(e.message)}</em>`;
    }
}

// Render single pretty-printed content (no baseline available).
function renderPreviewOnly(content, j) {
    const lines = content.split("\n").length;
    return `
        <div class="fm-ar-diff fm-ar-diff--single">
            <div class="fm-ar-diff-bar">
                <strong>Proposta</strong> · <span class="fm-muted">nessuna baseline per il diff</span>
                <span style="flex:1"></span>
                <span class="fm-muted" style="font-size:11px">${lines} righe · ${j.content.length} car · ${esc(j.kind)}</span>
            </div>
            <pre class="fm-ar-diff-pre">${esc(content)}</pre>
        </div>`;
}

// G22.S26 — Diff view: 3 modes via toggle (unified | side-by-side | proposed-only).
// Default = unified (compatto). LCS algoritmo per allineare righe identiche.
function renderDiffView(baseline, proposed, j) {
    const ops = computeDiff(baseline.split("\n"), proposed.split("\n"));
    const stats = ops.reduce((s, o) => {
        if (o.type === "add")  s.add++;
        if (o.type === "del")  s.del++;
        return s;
    }, { add: 0, del: 0 });

    const unified = ops.map((o, i) => {
        const cls = o.type === "add" ? "fm-ar-diff-add"
                  : o.type === "del" ? "fm-ar-diff-del"
                  : "fm-ar-diff-ctx";
        const sign = o.type === "add" ? "+" : o.type === "del" ? "-" : " ";
        return `<div class="fm-ar-diff-line ${cls}"><span class="fm-ar-diff-sign">${sign}</span><span>${esc(o.line)}</span></div>`;
    }).join("");

    const sideRows = [];
    let lIdx = 0; let rIdx = 0;
    for (const o of ops) {
        if (o.type === "ctx") {
            sideRows.push(`<tr><td>${++lIdx}</td><td class="fm-ar-diff-ctx-cell">${esc(o.line)}</td><td>${++rIdx}</td><td class="fm-ar-diff-ctx-cell">${esc(o.line)}</td></tr>`);
        } else if (o.type === "del") {
            sideRows.push(`<tr><td>${++lIdx}</td><td class="fm-ar-diff-del-cell">${esc(o.line)}</td><td></td><td class="fm-ar-diff-empty-cell"></td></tr>`);
        } else if (o.type === "add") {
            sideRows.push(`<tr><td></td><td class="fm-ar-diff-empty-cell"></td><td>${++rIdx}</td><td class="fm-ar-diff-add-cell">${esc(o.line)}</td></tr>`);
        }
    }

    // G22.S26 — Modalità "Anteprima" disponibile solo per kind renderizzabili
    // (schema/json). L'iframe carica la view del template con schema-url
    // puntato all'endpoint pending → fm-pt-document (source=risdoc-template)
    // renderizza come se fosse la live schema (ADR-026 #3).
    const isRenderable = j.kind === "schema" || j.kind === "json";
    const previewUrl = isRenderable
        ? `/admin/risdoc/pending/${encodeURIComponent(j.pending_id || "")}/preview`
        : null;
    const previewBtn = isRenderable
        ? `<button type="button" class="fm-btn fm-btn--xs fm-btn--ghost" data-diff-mode="rendered">🖼 Anteprima</button>`
        : "";
    // G22.S26 — sandbox: defense-in-depth. allow-scripts + allow-same-origin
    // necessari per Web Component + fetch schemaUrl. allow-forms per even-
    // tuali submit interni (selettori header). NO allow-top-navigation,
    // allow-popups: l'anteprima non deve poter aprire finestre esterne.
    const previewBody = isRenderable
        ? `<div class="fm-ar-diff-body fm-ar-diff-body--rendered" data-diff-mode="rendered" hidden>
               <iframe class="fm-ar-diff-iframe" data-preview-url="${esc(previewUrl)}"
                       title="Anteprima template con schema proposto"
                       referrerpolicy="no-referrer"
                       sandbox="allow-scripts allow-same-origin allow-forms"></iframe>
           </div>`
        : "";

    return `
        <div class="fm-ar-diff">
            <div class="fm-ar-diff-bar">
                <strong>Diff</strong>
                <span class="fm-ar-diff-stat fm-ar-diff-stat--add">+${stats.add}</span>
                <span class="fm-ar-diff-stat fm-ar-diff-stat--del">−${stats.del}</span>
                <span style="flex:1"></span>
                <span class="fm-muted" style="font-size:11px">${esc(j.kind)} · ${esc(j.path)}</span>
                <button type="button" class="fm-btn fm-btn--xs fm-btn--ghost" data-diff-mode="unified">Unificato</button>
                <button type="button" class="fm-btn fm-btn--xs fm-btn--ghost" data-diff-mode="side">Affiancato</button>
                <button type="button" class="fm-btn fm-btn--xs fm-btn--ghost" data-diff-mode="full">Pretty-print</button>
                ${previewBtn}
            </div>
            <div class="fm-ar-diff-body fm-ar-diff-body--unified" data-diff-mode="unified">${unified}</div>
            <div class="fm-ar-diff-body fm-ar-diff-body--side" data-diff-mode="side" hidden>
                <table class="fm-ar-diff-side">
                    <thead><tr><th></th><th>Baseline</th><th></th><th>Proposta</th></tr></thead>
                    <tbody>${sideRows.join("")}</tbody>
                </table>
            </div>
            <div class="fm-ar-diff-body fm-ar-diff-body--full" data-diff-mode="full" hidden>
                <pre class="fm-ar-diff-pre">${esc(proposed)}</pre>
            </div>
            ${previewBody}
        </div>`;
}

function bindDiffToggle(wrap) {
    const buttons = wrap.querySelectorAll("[data-diff-mode]");
    buttons.forEach(btn => {
        if (btn.tagName !== "BUTTON") return;
        btn.addEventListener("click", () => {
            const mode = btn.dataset.diffMode;
            wrap.querySelectorAll(".fm-ar-diff-body").forEach(body => {
                body.hidden = body.dataset.diffMode !== mode;
            });
            wrap.querySelectorAll("button[data-diff-mode]").forEach(b => {
                b.classList.toggle("fm-btn--primary", b.dataset.diffMode === mode);
            });
            // G22.S26 — Lazy-load iframe Anteprima al primo click (no
            // pre-fetch + permette dimensionamento iframe a viewport reale).
            // Cache-bust client-side `?_=ts` su src per garantire fresh HTML
            // + script ES module (browser tende a cachare ESM aggressivo).
            if (mode === "rendered") {
                const iframe = wrap.querySelector(".fm-ar-diff-iframe");
                if (iframe && !iframe.src && iframe.dataset.previewUrl) {
                    const sep = iframe.dataset.previewUrl.includes("?") ? "&" : "?";
                    iframe.src = `${iframe.dataset.previewUrl}${sep}_=${Date.now()}`;
                }
            }
        });
    });
    // Default: unified active.
    const def = wrap.querySelector('button[data-diff-mode="unified"]');
    def?.classList.add("fm-btn--primary");
}

// G22.S26 — Diff line-based via LCS (Longest Common Subsequence).
// O(N*M) memory ma input tipico <2000 righe — accettabile.
// Output: array di {type:"ctx"|"add"|"del", line:string}.
function computeDiff(a, b) {
    const n = a.length, m = b.length;
    // LCS DP table
    const dp = Array.from({ length: n + 1 }, () => new Int32Array(m + 1));
    for (let i = 1; i <= n; i++) {
        for (let j = 1; j <= m; j++) {
            dp[i][j] = a[i - 1] === b[j - 1]
                ? dp[i - 1][j - 1] + 1
                : Math.max(dp[i - 1][j], dp[i][j - 1]);
        }
    }
    // Backtrace
    const ops = [];
    let i = n, j = m;
    while (i > 0 && j > 0) {
        if (a[i - 1] === b[j - 1]) {
            ops.push({ type: "ctx", line: a[i - 1] });
            i--; j--;
        } else if (dp[i - 1][j] >= dp[i][j - 1]) {
            ops.push({ type: "del", line: a[i - 1] });
            i--;
        } else {
            ops.push({ type: "add", line: b[j - 1] });
            j--;
        }
    }
    while (i > 0) { ops.push({ type: "del", line: a[--i] }); }
    while (j > 0) { ops.push({ type: "add", line: b[--j] }); }
    ops.reverse();
    return ops;
}

async function approvePending(pid, card) {
    const note = card.querySelector('[data-role="reviewer-note"]').value;
    if (!await window.FM.Dialog.confirm("Approvare questa modifica? Verrà applicata immediatamente al template istituzionale.")) return;
    try {
        const csrf = await fetchCsrf();
        const fd = new URLSearchParams({ _csrf: csrf });
        if (note) fd.set("note", note);
        const r = await fetch(`/api/admin/risdoc/pending/${pid}/approve`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: fd.toString(),
        });
        const j = await r.json();
        if (j.ok) {
            card.style.opacity = "0.4";
            card.querySelectorAll("button, input").forEach(b => b.disabled = true);
            window.FM?.SyncPanel?.notify?.("Revisione", "ok", "✓ Approvata", 2000);
            setTimeout(() => renderPending(), 800);
        } else {
            window.FM?.SyncPanel?.notify?.("Revisione", "error", "Errore: " + (j.error || "?"), 4000);
        }
    } catch (e) {
        window.FM?.SyncPanel?.notify?.("Revisione", "error", "Errore: " + e.message, 4000);
    }
}

async function rejectPending(pid, card) {
    const note = card.querySelector('[data-role="reviewer-note"]').value.trim();
    if (!note) { alert("La motivazione è obbligatoria per rifiutare."); return; }
    try {
        const csrf = await fetchCsrf();
        const fd = new URLSearchParams({ _csrf: csrf, note });
        const r = await fetch(`/api/admin/risdoc/pending/${pid}/reject`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: fd.toString(),
        });
        const j = await r.json();
        if (j.ok) {
            card.style.opacity = "0.4";
            card.querySelectorAll("button, input").forEach(b => b.disabled = true);
            window.FM?.SyncPanel?.notify?.("Revisione", "ok", "✗ Rifiutata", 2000);
            setTimeout(() => renderPending(), 800);
        } else {
            window.FM?.SyncPanel?.notify?.("Revisione", "error", "Errore: " + (j.error || "?"), 4000);
        }
    } catch (e) {
        window.FM?.SyncPanel?.notify?.("Revisione", "error", "Errore: " + e.message, 4000);
    }
}

function renderTemplates() {
    // Phase 24.57 — TABELLA UNICA: l'admin vede e MODIFICA tutto qui.
    // Colonne allineate (via <colgroup>): Pos. | Argomento | Categoria
    // (editabili) | Stats (read-only) | Azioni | Salva.
    // Partizioni FLAT per `category` (Phase 24.58 — colonna `origin` rimossa
    // del tutto: la partizione È la category, niente più origin tecnico).
    const byCat = {};
    for (const t of STATE.templates) {
        const key = String(t.category || "—");
        (byCat[key] ??= []).push(t);
    }
    const sortNumArg = (a, b) => {
        const na = parseFloat(a.num_arg), nb = parseFloat(b.num_arg);
        if (!isNaN(na) && !isNaN(nb)) return na - nb;
        return String(a.num_arg).localeCompare(String(b.num_arg));
    };
    // Elenco partizioni esistenti (per la <select> Categoria di ogni riga). Si
    // rigenera a ogni reloadData → resta allineato a rinomine/creazioni.
    const allCats = [...new Set(STATE.templates.map(t => String(t.category || "")).filter(Boolean))].sort();
    const catSelect = (cur) => {
        const c = String(cur || "");
        const opts = (allCats.includes(c) ? allCats : [c, ...allCats])
            .map(x => `<option value="${esc(x)}"${x === c ? " selected" : ""}>${esc(x)}</option>`)
            .join("");
        return `<select class="fm-input fm-ar-edit-cat" aria-label="Partizione (sposta in un'altra partizione)">${opts}</select>`;
    };
    const sections = Object.keys(byCat).sort().map((category) => {
        const list = byCat[category].slice().sort(sortNumArg);
        const rows = list.map((t) => {
            const drift = +t.drift_count > 0
                ? `<span class="fm-ar-pill fm-ar-pill--warn">${t.drift_count} drift</span>`
                : `<span class="fm-ar-pill fm-ar-pill--ok">ok</span>`;
            // G22.S26 — pill pending revisione (badge giallo se >0).
            const pending = +t.pending_count > 0
                ? `<span class="fm-ar-pill fm-ar-pill--warn" title="Modifiche di collaboratori da revisionare">🛡 ${t.pending_count}</span>`
                : "";
            return `
                <tr data-template-id="${t.id}">
                    <td class="c-pos"><input class="fm-input fm-ar-edit-num" value="${esc(t.num_arg || "")}" aria-label="Posizione"></td>
                    <td class="c-arg"><input class="fm-input fm-ar-edit-name" value="${esc(t.argomento || "")}" aria-label="Argomento"></td>
                    <td class="c-cat">${catSelect(t.category)}</td>
                    <td class="c-stats">
                        <div class="fm-ar-inline-list">
                            <span>${+t.visible_count} visib</span>
                            <span>${+t.collab_count} collab</span>
                            <span>${+t.override_count} override</span>
                            ${drift}
                            ${pending}
                        </div>
                    </td>
                    <td class="c-act">
                        <div class="fm-ar-actions">
                            <a class="fm-ar-link-btn fm-ar-icon-btn" href="/risdoc/view/${t.id}" target="_blank" rel="noopener" title="Vedi template come docente (web component)">👁</a>
                            <button data-action="manage" data-id="${t.id}" title="Visibilità per-docente + collaboratori">Gestisci</button>
                            <button class="fm-ar-icon-btn" data-action="edit-images" data-id="${t.id}" title="Gestisci immagini del template (loghi: stemma, logo scuola, ecc.). Incluse nel PDF via \\includegraphics{images/...}.">🖼</button>
                            <button class="fm-ar-icon-btn" data-action="edit-schema" data-id="${t.id}" title="Edita schema istituzionale (struttura del template)">✏️</button>
                        </div>
                    </td>
                    <td class="c-save"><button class="fm-btn fm-btn--xs fm-btn--primary" data-action="save-meta" title="Salva Pos./Argomento/Partizione">Salva</button></td>
                </tr>`;
        }).join("");
        return `
            <div class="fm-ar-partition">
                <div class="fm-ar-partition-head">
                    <span class="fm-ar-part-label">Partizione</span>
                    <input class="fm-input fm-ar-edit-partname" data-from="${esc(category)}" value="${esc(category)}" aria-label="Nome partizione">
                    <button class="fm-btn fm-btn--xs" data-action="rename-partition" title="Rinomina la categoria per tutti i template di questa partizione">Rinomina partizione</button>
                </div>
                <table class="fm-ar-tbl fm-ar-tbl--unified">
                    <thead>
                        <tr>
                            <th class="c-pos" style="width:5em">Pos.</th>
                            <th class="c-arg">Argomento</th>
                            <th class="c-cat" style="width:9.5em">Partizione</th>
                            <th class="c-stats" style="width:19em">Stats</th>
                            <th class="c-act" style="width:14.5em">Azioni</th>
                            <th class="c-save" style="width:5em"></th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;
    }).join("");

    // Phase 24.57 — toolbar: crea nuovo template (e, con category nuova, una
    // nuova partizione). datalist con le category esistenti per riuso/typo-free.
    const toolbar = `
        <div class="fm-ar-toolbar">
            <button class="fm-btn fm-btn--sm fm-btn--primary" data-action="new-template-toggle">➕ Nuovo template</button>
            <form class="fm-ar-newtpl" data-action="new-template-form" hidden>
                <label>Partizione
                    <input class="fm-input" name="category" list="fm-ar-cats" placeholder="es. modelli (o nuova)" required>
                </label>
                <datalist id="fm-ar-cats">${allCats.map(c => `<option value="${esc(c)}">`).join("")}</datalist>
                <label>Posizione
                    <input class="fm-input" name="num_arg" placeholder="es. 5.0" pattern="[0-9]{1,3}(\\.[0-9]{1,3})?" required>
                </label>
                <label class="fm-ar-newtpl-arg">Argomento
                    <input class="fm-input" name="argomento" placeholder="Nome del template" required>
                </label>
                <button type="submit" class="fm-btn fm-btn--sm fm-btn--primary">Crea</button>
                <button type="button" class="fm-btn fm-btn--sm" data-action="new-template-cancel">Annulla</button>
                <span class="fm-ar-newtpl-msg" role="status" aria-live="polite"></span>
            </form>
        </div>`;

    STATE.panel.innerHTML = toolbar + (sections || `<p class="fm-muted">Nessun template ancora. Crea il primo con “➕ Nuovo template”.</p>`);

    const toggleBtn = STATE.panel.querySelector("button[data-action='new-template-toggle']");
    const newForm = STATE.panel.querySelector("form[data-action='new-template-form']");
    if (toggleBtn && newForm) {
        toggleBtn.addEventListener("click", () => {
            newForm.hidden = !newForm.hidden;
            if (!newForm.hidden) newForm.querySelector("input[name='category']").focus();
        });
        newForm.querySelector("button[data-action='new-template-cancel']").addEventListener("click", () => { newForm.hidden = true; });
        newForm.addEventListener("submit", (e) => { e.preventDefault(); createTemplate(newForm); });
    }

    STATE.panel.querySelectorAll("button[data-action='manage']").forEach(b => {
        b.addEventListener("click", () => openDetail(+b.dataset.id, b));
    });
    STATE.panel.querySelectorAll("button[data-action='edit-images']").forEach(b => {
        b.addEventListener("click", () => openImagesManager(+b.dataset.id, b));
    });
    STATE.panel.querySelectorAll("button[data-action='edit-schema']").forEach(b => {
        b.addEventListener("click", () => {
            // ADR-026 #3 — apre /risdoc/view/{id}?admin_edit=1 in nuova
            // scheda: fm-pt-document monta in modalità admin-edit; save
            // passa per RisdocTemplateAdapter.save adminEdit branch
            // (POST /api/risdoc/templates/{id}/body-pt).
            window.open(`/risdoc/view/${b.dataset.id}?admin_edit=1`, "_blank", "noopener");
        });
    });
    STATE.panel.querySelectorAll("button[data-action='save-meta']").forEach(b => {
        b.addEventListener("click", () => saveTemplateMeta(b));
    });
    STATE.panel.querySelectorAll("button[data-action='rename-partition']").forEach(b => {
        b.addEventListener("click", () => renamePartition(b));
    });
}

// Phase 24.57 — salva Pos./Argomento/Categoria di un template (riga della
// tabella unica). POST /api/admin/risdoc/templates/{id}/meta. Se cambia la
// categoria, il template migra di partizione: ricarico per riallineare.
async function saveTemplateMeta(btn) {
    const tr = btn.closest("tr");
    if (!tr) return;
    const id = tr.dataset.templateId;
    const num = tr.querySelector(".fm-ar-edit-num").value.trim();
    const name = tr.querySelector(".fm-ar-edit-name").value.trim();
    const cat = tr.querySelector(".fm-ar-edit-cat").value.trim();
    const old = btn.textContent;
    btn.disabled = true; btn.textContent = "…";
    try {
        const csrf = await fetchCsrf();
        const fd = new URLSearchParams({ _csrf: csrf, num_arg: num, argomento: name, category: cat });
        const r = await fetch(`/api/admin/risdoc/templates/${id}/meta`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: fd.toString(),
        });
        const j = await r.json().catch(() => ({}));
        if (j && j.ok) {
            btn.textContent = "✓";
            // ricarico l'elenco completo (riordino + eventuale cambio partizione)
            setTimeout(() => reloadData(), 500);
        } else {
            btn.textContent = "✗";
            btn.disabled = false;
            setTimeout(() => { btn.textContent = old; }, 1500);
        }
    } catch (e) {
        btn.textContent = "✗"; btn.disabled = false;
        setTimeout(() => { btn.textContent = old; }, 1500);
    }
}

// Phase 24.58 — rinomina la partizione (category) from→to. Niente più origin:
// una sola rename-group per category.
async function renamePartition(btn) {
    const head = btn.closest(".fm-ar-partition-head");
    if (!head) return;
    const inp = head.querySelector(".fm-ar-edit-partname");
    const to = inp.value.trim();
    const from = inp.dataset.from || "";
    if (!to || to === from) { inp.focus(); return; }
    const old = btn.textContent;
    btn.disabled = true; btn.textContent = "…";
    try {
        const csrf = await fetchCsrf();
        const fd = new URLSearchParams({ _csrf: csrf, from, to });
        const r = await fetch("/api/admin/risdoc/templates/rename-group", {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: fd.toString(),
        });
        const j = await r.json().catch(() => ({}));
        if (j && j.ok) {
            btn.textContent = `✓ ${j.updated ?? ""}`.trim();
            setTimeout(() => reloadData(), 500);
        } else {
            btn.textContent = "✗"; btn.disabled = false;
            setTimeout(() => { btn.textContent = old; }, 1500);
        }
    } catch (e) {
        btn.textContent = "✗"; btn.disabled = false;
        setTimeout(() => { btn.textContent = old; }, 1500);
    }
}

// Phase 24.57 — crea un nuovo template. POST /api/admin/risdoc/templates/create.
// Una category nuova crea de-facto una nuova partizione. Dopo la creazione
// ricarico e (per comodità) apro subito l'editor schema in nuova scheda.
async function createTemplate(form) {
    const msg = form.querySelector(".fm-ar-newtpl-msg");
    const submit = form.querySelector("button[type='submit']");
    const data = new FormData(form);
    const category = String(data.get("category") || "").trim();
    const numArg = String(data.get("num_arg") || "").trim();
    const argomento = String(data.get("argomento") || "").trim();
    if (!category || !numArg || !argomento) { msg.textContent = "Compila tutti i campi."; return; }
    submit.disabled = true; msg.textContent = "Creo…";
    try {
        const csrf = await fetchCsrf();
        const fd = new URLSearchParams({ _csrf: csrf, category, num_arg: numArg, argomento });
        const r = await fetch("/api/admin/risdoc/templates/create", {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: fd.toString(),
        });
        const j = await r.json().catch(() => ({}));
        if (j && j.ok) {
            msg.textContent = "✓ creato";
            // apre l'editor schema del nuovo template per costruirne la struttura
            if (j.id) window.open(`/risdoc/view/${j.id}?admin_edit=1`, "_blank", "noopener");
            setTimeout(() => reloadData(), 500);
        } else {
            msg.textContent = "✗ " + (j.error || "errore");
            submit.disabled = false;
        }
    } catch (e) {
        msg.textContent = "✗ " + e.message;
        submit.disabled = false;
    }
}

/**
 * Inline expansion: inserisce un TR colspan immediatamente SOTTO la riga
 * cliccata col contenuto passato. Idempotente: collassa eventuali altre
 * espansioni aperte prima di inserire. Toggle: cliccando lo stesso
 * trigger-button mentre la riga è già espansa la collassa.
 *
 * @param {HTMLButtonElement} triggerBtn  pulsante che ha innescato
 * @param {HTMLElement} content           pannello da renderizzare in colspan TD
 * @param {string} kind                   modifier BEM (es. "images" / "detail")
 * @returns {boolean}                     true se ha espanso, false se collassato
 */
function expandInlineUnderRow(triggerBtn, content, kind) {
    const row = triggerBtn?.closest("tr");
    if (!row) return false;
    const existing = row.nextElementSibling;
    // Toggle: se subito sotto c'è già un'espansione dello STESSO kind → collassa
    if (existing?.classList?.contains("fm-ar-row-expanded")
        && existing.classList.contains(`fm-ar-row-expanded--${kind}`)) {
        existing.remove();
        return false;
    }
    collapseInlineRows();
    const expanded = document.createElement("tr");
    expanded.className = `fm-ar-row-expanded fm-ar-row-expanded--${kind}`;
    const cols = row.querySelectorAll("td").length || 4;
    const td = document.createElement("td");
    td.colSpan = cols;
    td.className = "fm-ar-row-expanded__cell";
    td.appendChild(content);
    expanded.appendChild(td);
    row.parentNode.insertBefore(expanded, row.nextSibling);
    // Scroll into view (smooth) — l'utente vede subito il pannello.
    expanded.scrollIntoView({ behavior: "smooth", block: "nearest" });
    return true;
}

/** Rimuove tutte le righe espanse inline. */
function collapseInlineRows() {
    document.querySelectorAll(".fm-ar-row-expanded").forEach(r => r.remove());
}

/**
 * Pannello inline gestione immagini template istituzionale.
 *
 * Sostituisce l'editor PT seed (rimosso 2026-05-28): le immagini caricate qui
 * finiscono come override sul template (`/api/risdoc/templates/{id}/override`
 * kind=image) e vengono incluse nel PDF tramite gli `\includegraphics{images/...}`
 * nei file `.tex` (es. `images/logo_scuola.png`, `images/stemma_REP.png`).
 * Espanso inline sotto la riga della tabella admin/templates (no overlay
 * modal): meno disorientante per l'admin che vede ancora il contesto.
 */
async function openImagesManager(templateId, triggerBtn) {
    const tpl = STATE.templates.find(t => +t.id === +templateId);
    const code = tpl?.code || `#${templateId}`;

    const panel = document.createElement("div");
    panel.className = "fm-ar-inline-panel fm-ar-inline-panel--images";
    panel.innerHTML = `
        <div class="fm-ar-inline-panel__header">
            <span class="fm-ar-inline-panel__title">🖼 Immagini · <code>${esc(code)}</code></span>
            <button type="button" class="fm-ar-inline-panel__close" title="Chiudi">×</button>
        </div>
        <p class="fm-ar-inline-panel__help">
            Le immagini caricate qui sono incluse nel PDF tramite
            <code>\\includegraphics{images/&lt;nome&gt;}</code> nei file <code>.tex</code>
            del template. Tipici: <code>images/stemma_REP.png</code> (stemma Repubblica),
            <code>images/logo_scuola.png</code> (logo istituto).
        </p>
        <div class="fm-ar-inline-panel__body fm-ar-inline-panel__body--images"></div>
    `;
    panel.querySelector(".fm-ar-inline-panel__close").addEventListener("click", collapseInlineRows);

    const opened = expandInlineUnderRow(triggerBtn, panel, "images");
    if (!opened) return; // toggle: stato collassato

    const host = panel.querySelector(".fm-ar-inline-panel__body");
    try {
        await import("../../components/risdoc/fm-risdoc-images-manager.js");
        const mgr = document.createElement("fm-risdoc-images-manager");
        mgr.setAttribute("template-id", String(templateId));
        host.appendChild(mgr);
    } catch (e) {
        host.innerHTML = `<div style="color:var(--fm-c-danger,#b91c1c);padding:8px">Image manager unavailable: ${esc(e.message)}</div>`;
    }
}

/**
 * Phase 24.51 — overlay editor PT seed per template istituzionale (DEPRECATED 2026-05-28).
 * Ora il body_pt del template viene editato direttamente da super-admin via
 * /risdoc/view/{id}?admin_edit=1 (onepath ADR-026 #3). Funzione mantenuta come
 * fallback per chi avesse bookmark/script che la richiama.
 */
async function openPtEditor(templateId) {
    closePtEditor();
    const tpl = STATE.templates.find(t => +t.id === +templateId);
    const code = tpl?.code || `#${templateId}`;

    const backdrop = document.createElement("div");
    backdrop.className = "fm-ar-pt-backdrop";
    backdrop.innerHTML = `
        <div class="fm-ar-pt-modal">
            <div class="fm-ar-pt-header">
                <span>📝 PT seed · <code>${esc(code)}</code></span>
                <button type="button" class="fm-ar-pt-close" title="Chiudi">×</button>
            </div>
            <div class="fm-ar-pt-body">
                <p style="font-size:12px;color:#475569;margin:0 0 8px">
                    Il <strong>body_pt</strong> qui salvato sarà copiato (no FK) nel teacher_content
                    quando un docente seleziona questo template come base in modal "Stile esercizi".
                </p>
                <div class="fm-ar-pt-editor-host" style="min-height:320px;background:#fff;border:1px solid #cbd5e1;border-radius:4px">
                    <div style="color:#64748b;font-style:italic;padding:8px">Caricamento editor…</div>
                </div>
                <div class="fm-ar-pt-error" style="color:#b91c1c;font-size:12px;margin-top:6px"></div>
            </div>
            <div class="fm-ar-pt-actions">
                <button type="button" class="fm-ar-pt-clear">🗑 Pulisci PT seed</button>
                <span style="flex:1"></span>
                <button type="button" class="fm-ar-pt-cancel">Annulla</button>
                <button type="button" class="fm-ar-pt-save">💾 Salva</button>
            </div>
        </div>
    `;
    document.body.appendChild(backdrop);

    backdrop.querySelector(".fm-ar-pt-close").addEventListener("click", closePtEditor);
    backdrop.querySelector(".fm-ar-pt-cancel").addEventListener("click", closePtEditor);
    backdrop.addEventListener("click", (e) => { if (e.target === backdrop) closePtEditor(); });

    const host = backdrop.querySelector(".fm-ar-pt-editor-host");
    let edRef = null;

    try {
        // Phase 24.51 + fix path relativo per Vite production bundle
        const m = await import("../../components/risdoc/_pt-loader.js");
        await m.ensurePtEditorLoaded();
        const ed = document.createElement("fm-pt-editor");
        ed.style.cssText = "display:block;min-height:320px;background:#fff;border-radius:4px";
        host.innerHTML = "";
        host.appendChild(ed);
        edRef = ed;

        // Pre-popola con body_pt corrente del template
        const r = await fetch(`/api/admin/risdoc/templates/${templateId}`, { credentials: "same-origin" });
        const j = await r.json();
        let cur = null;
        if (j.template?.body_pt) {
            try {
                cur = typeof j.template.body_pt === "string"
                    ? JSON.parse(j.template.body_pt)
                    : j.template.body_pt;
            } catch (_) { cur = null; }
        }
        if (Array.isArray(cur) && cur.length > 0) ed.value = cur;
    } catch (err) {
        host.innerHTML = `<div style="color:#b91c1c;padding:8px">PT editor unavailable: ${esc(err.message)}</div>`;
        return;
    }

    backdrop.querySelector(".fm-ar-pt-save").addEventListener("click", async () => {
        if (!edRef) return;
        const errBox = backdrop.querySelector(".fm-ar-pt-error");
        errBox.textContent = "";
        const pt = typeof edRef.getPt === "function" ? edRef.getPt() : edRef.value;
        if (!Array.isArray(pt)) { errBox.textContent = "PT non valido."; return; }
        try {
            const csrf = await fetchCsrf();
            const fd = new URLSearchParams({ _csrf: csrf, body_pt: JSON.stringify(pt) });
            const r = await fetch(`/api/risdoc/templates/${templateId}/body-pt`, {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: fd.toString(),
            });
            const j = await r.json();
            if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
            closePtEditor();
        } catch (e) {
            errBox.textContent = e.message;
        }
    });

    backdrop.querySelector(".fm-ar-pt-clear").addEventListener("click", async () => {
        if (!await window.FM.Dialog.confirm(`Cancellare il PT seed di ${code}? I docenti non vedranno più il template nel picker.`)) return;
        try {
            const csrf = await fetchCsrf();
            const fd = new URLSearchParams({ _csrf: csrf, body_pt: "" });
            const r = await fetch(`/api/risdoc/templates/${templateId}/body-pt`, {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: fd.toString(),
            });
            const j = await r.json();
            if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
            closePtEditor();
        } catch (e) {
            backdrop.querySelector(".fm-ar-pt-error").textContent = e.message;
        }
    });
}

function closePtEditor() {
    document.querySelector(".fm-ar-pt-backdrop")?.remove();
}

async function openDetail(id, triggerBtn) {
    const panel = document.createElement("div");
    panel.className = "fm-ar-inline-panel fm-ar-inline-panel--detail";
    panel.innerHTML = `
        <div class="fm-ar-inline-panel__header">
            <span class="fm-ar-inline-panel__title">📋 Gestisci template</span>
            <button type="button" class="fm-ar-inline-panel__close" title="Chiudi">×</button>
        </div>
        <div class="fm-ar-inline-panel__body" id="fm-ar-detail">
            <div class="fm-ar-loading">Caricamento dettaglio…</div>
        </div>
    `;
    panel.querySelector(".fm-ar-inline-panel__close").addEventListener("click", collapseInlineRows);
    const opened = expandInlineUnderRow(triggerBtn, panel, "detail");
    if (!opened) return;
    const detail = panel.querySelector("#fm-ar-detail");
    const r = await fetch(`/api/admin/risdoc/templates/${id}`, { credentials: "same-origin" });
    const j = await r.json();
    if (!j.ok) { detail.innerHTML = `<div style="color:var(--fm-c-danger,#c02a2a)">Errore</div>`; return; }

    // G22.S26 — owner_id rimossa. Modello: collab + visible + review-flag.
    const visibleIds = new Set(j.visibility.filter(v => +v.visible === 1).map(v => +v.teacher_id));
    const collabMap  = new Map(j.collaborators.map(c => [+c.teacher_id, +(c.requires_review || 0) === 1]));

    const teacherRows = STATE.teachers.map(u => {
        const vis    = visibleIds.has(+u.id);
        const col    = collabMap.has(+u.id);
        const review = collabMap.get(+u.id) === true;
        return `
            <tr>
                <td>${esc(u.username)}</td>
                <td><input type="checkbox" data-role="visible" data-tid="${u.id}" ${vis ? "checked" : ""}></td>
                <td><input type="checkbox" data-role="collab"  data-tid="${u.id}" ${col ? "checked" : ""}></td>
                <td><input type="checkbox" data-role="review"  data-tid="${u.id}" ${review ? "checked" : ""} ${col ? "" : "disabled"}></td>
            </tr>
        `;
    }).join("");

    detail.innerHTML = `
        <h3 class="fm-ar-detail-h" style="margin:12px 0">📋 Dettaglio template <code>${esc(j.template.code)}</code></h3>
        <details class="fm-ar-detail-help">
            <summary>ℹ Cosa significano <strong>Visibile</strong>, <strong>Collaboratore</strong>, <strong>Revisione</strong>?</summary>
            <ul>
                <li><strong>Visibile</strong> 👁 — il docente vede il template nel suo picker, può forkarlo localmente (<code>risdoc_teacher_overrides</code>) ma non tocca il sorgente.</li>
                <li><strong>Collaboratore</strong> ✎ — può modificare la struttura del template (institutional override). Stessi permessi del super-admin sul singolo template.</li>
                <li><strong>Revisione</strong> 🛡 — se attivo per un collaboratore, le sue modifiche non si applicano direttamente: vanno in coda <em>pending</em> per approvazione/rifiuto del super-admin (tab "Modifiche in revisione"). Senza questo flag il collaboratore scrive in autonomia.</li>
            </ul>
            <p style="margin:6px 0 0 0;font-style:italic">Solo il super-admin gestisce questi flag — il collaboratore non può invitare altri o togliersi la revisione.</p>
        </details>
        <div class="fm-ar-detail-card">
            <label class="fm-ar-detail-lbl">Permessi per docente
                <span class="fm-ar-detail-hint">(👁 visualizza · ✎ modifica struttura · 🛡 modifiche soggette a revisione admin)</span>
            </label>
            <table class="fm-ar-tbl" style="margin-top:4px">
                <thead>
                    <tr>
                        <th>Docente</th>
                        <th title="Visibile: il docente vede il template nel picker.">👁 Visibile</th>
                        <th title="Collaboratore: può modificare la struttura.">✎ Collab.</th>
                        <th title="Se attivo, le modifiche di questo collaboratore vanno in coda revisione del super-admin invece di applicarsi direttamente.">🛡 Revisione</th>
                    </tr>
                </thead>
                <tbody>${teacherRows}</tbody>
            </table>
            <button class="fm-btn fm-btn--sm fm-btn--primary" data-action="save-matrix" data-id="${id}">💾 Salva matrice</button>
        </div>
    `;

    // Dipendenza UX: revisione attiva solo se collab è checked.
    detail.querySelectorAll('input[data-role="collab"]').forEach(cb => {
        cb.addEventListener("change", () => {
            const tid = cb.dataset.tid;
            const rev = detail.querySelector(`input[data-role="review"][data-tid="${tid}"]`);
            if (rev) {
                rev.disabled = !cb.checked;
                if (!cb.checked) rev.checked = false;
            }
        });
    });
    detail.querySelector('[data-action="save-matrix"]').addEventListener("click", () => saveMatrix(id));
}

// G22.S26 — saveOwner rimossa (endpoint /owner deprecato dopo drop col).

async function saveMatrix(id) {
    const detail = document.getElementById("fm-ar-detail");
    const visOn  = []; const visOff = []; const colAdd = []; const colDel = [];
    const reviewMap = {};

    detail.querySelectorAll('[data-role="visible"]').forEach(cb => {
        (cb.checked ? visOn : visOff).push(+cb.dataset.tid);
    });
    // Per capire cosa cambiare come collab, serve stato precedente: recuperato via data-initial attr omesso.
    // Qui: invio add = checked; remove: ricarichiamo dettaglio post-save.
    detail.querySelectorAll('[data-role="collab"]').forEach(cb => {
        (cb.checked ? colAdd : colDel).push(+cb.dataset.tid);
    });
    // G22.S26 — review_map serializzato come review_map[teacher_id]=0|1
    detail.querySelectorAll('[data-role="review"]').forEach(cb => {
        reviewMap[+cb.dataset.tid] = cb.checked ? 1 : 0;
    });

    const csrf = await fetchCsrf();

    // Bulk visibility ON
    if (visOn.length) {
        const fd = new URLSearchParams({ _csrf: csrf, visible: "1" });
        visOn.forEach(v => fd.append("teacher_ids[]", v));
        await fetch(`/api/admin/risdoc/templates/${id}/visibility`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: fd.toString(),
        });
    }
    if (visOff.length) {
        const fd = new URLSearchParams({ _csrf: csrf, visible: "0" });
        visOff.forEach(v => fd.append("teacher_ids[]", v));
        await fetch(`/api/admin/risdoc/templates/${id}/visibility`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: fd.toString(),
        });
    }
    // Collab — add for all checked, remove for all unchecked; review_map per
    // requires_review (sia per nuovi che esistenti).
    const fdc = new URLSearchParams({ _csrf: csrf });
    colAdd.forEach(v => fdc.append("add[]", v));
    colDel.forEach(v => fdc.append("remove[]", v));
    Object.entries(reviewMap).forEach(([tid, flag]) => {
        fdc.append(`review_map[${tid}]`, String(flag));
    });
    if (colAdd.length || colDel.length || Object.keys(reviewMap).length) {
        await fetch(`/api/admin/risdoc/templates/${id}/collaborators`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: fdc.toString(),
        });
    }
    alert("Matrice salvata");
    await reloadTemplates();
    await openDetail(id);
}

async function renderDrift() {
    STATE.panel.innerHTML = `<div>Caricamento drift…</div>`;
    const r = await fetch("/api/admin/risdoc/drift", { credentials: "same-origin" });
    const j = await r.json();
    const rows = (j.drifted || []).map(d => `
        <tr>
            <td><code>${esc(d.code)}</code></td>
            <td>${esc(d.username)}</td>
            <td>${esc(d.kind)}</td>
            <td><code>${esc(d.relative_path)}</code></td>
            <td><code style="font-size:10px">${esc(d.source_version.substring(0, 8))}…</code></td>
            <td><code style="font-size:10px">${esc(d.source_hash.substring(0, 8))}…</code></td>
            <td>${esc(d.updated_at)}</td>
        </tr>
    `).join("");
    STATE.panel.innerHTML = `
        <h3 style="margin:12px 0">⚠ Override con source drift (${(j.drifted || []).length})</h3>
        <p style="font-size:12px; color:#64748b">Override dove il sorgente del template è stato aggiornato dopo il fork.</p>
        <table class="fm-ar-tbl">
            <thead><tr><th>Template</th><th>Teacher</th><th>Kind</th><th>Path</th><th>Saved@</th><th>Current</th><th>Updated</th></tr></thead>
            <tbody>${rows || '<tr><td colspan="7" style="text-align:center; color:#94a3b8">Nessun drift</td></tr>'}</tbody>
        </table>
    `;
}

async function reloadTemplates() {
    const r = await fetch("/api/admin/risdoc/templates", { credentials: "same-origin" });
    const j = await r.json();
    STATE.templates = j.templates || [];
    renderTemplates();
}

if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
else queueMicrotask(init);
window.addEventListener("fm:navigated", init);
