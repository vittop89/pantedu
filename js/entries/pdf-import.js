/**
 * Phase PDF-Import — entry pagina /teacher/pdf-import.
 *
 * Orchestratore client: upload → poll → tabella di revisione editabile →
 * insert come bozze. Usa fetchJson/fetchCsrf (via api.js) e window.FM.Dialog/
 * ToastManager per i messaggi (niente alert/confirm nativi).
 */
import { createSession, editCell, bulkEdit, generateSolutions, generateTopics, refineDifficulty, translate, getStatus, stopSession, insert, getOrigins, toggleCache, toggleSetting } from "../modules/pdf-import/api.js";
import { ReviewTable } from "../modules/pdf-import/review-table.js";
import { SessionPoller } from "../modules/pdf-import/poll.js";
import { SideViewer } from "../modules/pdf-import/side-viewer.js";
import { openKeyModal } from "../modules/pdf-import/key-modal.js";
import { notify } from "../modules/ui/sync-panel.js";

function qs(sel, root = document) { return root.querySelector(sel); }

// Messaggi di esito (successo/errore/avviso) → pannello #fm-drive-sync-panel
// (auto-creato da sync-panel.js, slide-in da destra). kind: ok|error|info.
function toast(kind, title, msg) {
    const k = kind === "success" ? "ok" : (kind === "error" ? "error" : "info");
    try {
        notify(title || "Importa PDF", k, msg || "", kind === "error" ? 8000 : 4500);
    } catch (_) {
        console.log(`[pdf-import] ${kind}: ${title} — ${msg}`);
    }
}

function statusMsg(el, text, isError = false) {
    if (!el) return;
    el.hidden = false;
    el.textContent = text;
    el.classList.toggle("fm-pdfimport__status--error", isError);
}

const ERR_IT = {
    invalid_subject_code: "Codice materia mancante o non valido (max 16 caratteri: lettere, numeri, - _).",
    no_rows_selected: "Nessuna riga valida selezionata.",
    contracts_not_ready: "Sessione non pronta o scaduta: ri-estrai il PDF.",
    target_not_found: "Verifica di destinazione non trovata (potrebbe essere stata eliminata).",
    contract_load_failed: "Impossibile caricare il documento di destinazione.",
    title_required: "Titolo mancante.",
    invalid_content_type: "Tipo contenuto non valido.",
    invalid_visibility: "Visibilità non valida.",
    forbidden: "Operazione non consentita per il tuo profilo.",
    session_not_found: "Sessione non trovata o scaduta.",
};
function errText(e) {
    const code = (e && (e.message || e.error)) ? (e.message || e.error) : "";
    return ERR_IT[code] || code || "Errore imprevisto";
}

// MathJax on-demand (la pagina ha solo la config; il loader lo carichiamo qui).
function ensureMathJax() {
    if (window.MathJax && window.MathJax.typesetPromise) return;
    if (document.getElementById("MathJax-script")) return;
    const s = document.createElement("script");
    s.id = "MathJax-script";
    s.async = true;
    s.src = "https://cdn.jsdelivr.net/npm/mathjax@4/tex-mml-chtml.js";
    document.head.appendChild(s);
}
async function typesetMath(el) {
    ensureMathJax();
    for (let i = 0; i < 60 && !(window.MathJax && window.MathJax.typesetPromise); i++) {
        await new Promise((r) => setTimeout(r, 150));
    }
    try {
        window.MathJax?.typesetClear?.([el]);
        if (window.MathJax?.typesetPromise) await window.MathJax.typesetPromise([el]);
    } catch (_) { /* noop */ }
}

// Escape HTML (i delimitatori LaTeX \(...\) restano: MathJax legge il textContent).
function escHtml(s) {
    return String(s == null ? "" : s)
        .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

// L'estrazione LLM usa spesso $...$ / $$...$$; pantedu (e il suo MathJax) usa
// \(...\) / \[...\]. Normalizza così l'anteprima renderizza E coincide con ciò
// che verrà inserito. ($$ prima di $.)
function normalizeMath(s) {
    return String(s == null ? "" : s)
        .replace(/\$\$([\s\S]+?)\$\$/g, (_m, x) => `\\[${x}\\]`)
        .replace(/\$([^$\n]+?)\$/g, (_m, x) => `\\(${x}\\)`);
}
// Testo → HTML pronto per MathJax (normalizza delimitatori, poi escape).
function fx(s) { return escHtml(normalizeMath(s)); }

// Markup auto-contenuto di un esercizio (anteprima fedele, senza CSS legacy).
function buildPreviewHtml(row) {
    const p = row.payload || {};
    const color = row.badge_color || "gray";
    const diff = Math.max(0, Math.min(4, parseInt(row.difficulty, 10) || 0));
    const dots = "●".repeat(diff) + "○".repeat(4 - diff);
    const meta = [
        row.page ? "pag. " + escHtml(row.page) : "",
        row.origin ? escHtml(row.origin) : "",
        row.topic ? escHtml(row.topic) : "",
    ].filter(Boolean).join(" · ");

    let body = "";
    if (p.shared_instruction) body += `<p class="fm-pdfimport-ex__intro">${fx(p.shared_instruction)}</p>`;
    if (p.question) body += `<div class="fm-pdfimport-ex__q">${fx(p.question)}</div>`;

    const opts = Array.isArray(p.options) ? p.options : [];
    const stmts = Array.isArray(p.statements) ? p.statements : [];
    const pts = Array.isArray(p.points) ? p.points : [];
    if (row.type === "RM" && opts.length) {
        body += `<ul class="fm-pdfimport-ex__opts">` + opts.map((o) =>
            `<li class="${o.correct ? "is-correct" : ""}"><span class="fm-pdfimport-ex__lett">${escHtml(o.letter || "")}</span> ${fx(o.text || "")}</li>`
        ).join("") + `</ul>`;
    } else if (row.type === "VF" && stmts.length) {
        body += `<ul class="fm-pdfimport-ex__vf">` + stmts.map((s) =>
            `<li><span class="fm-pdfimport-ex__vfmark">${escHtml(s.answer || "?")}</span> ${fx(s.text || "")}</li>`
        ).join("") + `</ul>`;
    } else if (pts.length) {
        body += `<ol class="fm-pdfimport-ex__pts">` + pts.map((s) =>
            `<li>${fx(s.text || "")}</li>`
        ).join("") + `</ol>`;
    }
    if (p.has_figure) {
        body += `<p class="fm-pdfimport-ex__fig">▦ Figura: ${fx(p.figure_description || "(presente nel PDF)")}</p>`;
    }
    if (p.solution) {
        body += `<details class="fm-pdfimport-ex__sol"><summary>Soluzione</summary><div>${fx(p.solution)}</div></details>`;
    }

    return `<article class="fm-pdfimport-ex fm-pdfimport-ex--${escHtml(color)}">`
        + `<header class="fm-pdfimport-ex__head">`
        + `<span class="fm-pdfimport-ex__num">${escHtml(row.number || "—")}</span>`
        + `<span class="fm-pdfimport-ex__type">${escHtml(row.type || "")}</span>`
        + `<span class="fm-pdfimport-ex__diff" title="Difficoltà ${diff}/4">${dots}</span>`
        + (meta ? `<span class="fm-pdfimport-ex__meta">${meta}</span>` : "")
        + `</header>`
        + `<div class="fm-pdfimport-ex__body">${body || '<em class="fm-pdfimport-ex__empty">(nessun testo estratto)</em>'}</div>`
        + `</article>`;
}

function init() {
    const root = qs("[data-fm-pdfimport]");
    if (!root) return;

    const els = {
        provider: qs("[data-fm-provider]", root),
        privacy: qs("[data-fm-privacy]", root),
        file: qs("[data-fm-file]", root),
        extract: qs("[data-fm-extract]", root),
        stop: qs("[data-fm-stop]", root),
        cache: qs("[data-fm-cache]", root),
        sourcePreset: qs("[data-fm-source-preset]", root),
        status: qs("[data-fm-status]", root),
        workspace: qs("[data-fm-workspace]", root),
        tbody: qs("[data-fm-tbody]", root),
        selall: qs("[data-fm-selall]", root),
        bulkbar: qs("[data-fm-bulkbar]", root),
        bulkinfo: qs("[data-fm-bulkinfo]", root),
        bulkField: qs("[data-fm-bulk-field]", root),
        bulkValueWrap: qs("[data-fm-bulk-value-wrap]", root),
        bulkApply: qs("[data-fm-bulk-apply]", root),
        bulkClear: qs("[data-fm-bulk-clear]", root),
        insertbar: qs("[data-fm-insertbar]", root),
        ctxIndirizzo: qs("[data-fm-ctx-indirizzo]", root),
        ctxClasse: qs("[data-fm-ctx-classe]", root),
        ctxSubject: qs("[data-fm-ctx-subject]", root),
        genSolutions: qs("[data-fm-gen-solutions]", root),
        genTopics: qs("[data-fm-gen-topics]", root),
        refineDiff: qs("[data-fm-refine-diff]", root),
        translate: qs("[data-fm-translate]", root),
        preview: qs("[data-fm-preview]", root),
        insert: qs("[data-fm-insert]", root),
        render: qs("[data-fm-render]", root),
        renderBody: qs("[data-fm-render-body]", root),
        logWrap: qs("[data-fm-log-wrap]", root),
        log: qs("[data-fm-log]", root),
        logCount: qs("[data-fm-log-count]", root),
        side: {
            container: qs("[data-fm-side]", root),
            info: qs("[data-fm-pageinfo]", root),
            imgWrap: qs("[data-fm-sideimg]", root),
            prevBtn: qs("[data-fm-page-prev]", root),
            nextBtn: qs("[data-fm-page-next]", root),
        },
    };
    // Dark theme: la pagina è standalone (no shell) → applica body.fm-dark da
    // localStorage (i token gestiscono anche prefers-color-scheme da soli).
    try { if (localStorage.getItem("fm_dark_mode") === "1") document.body.classList.add("fm-dark"); } catch (_) { /* noop */ }

    // Contesto sorgente: passato via localStorage dal pulsante topbar della
    // pagina esercizio (nome doc, ids, indirizzo/classe/materia, gruppi).
    let srcCtx = null;
    try {
        const raw = localStorage.getItem("fm_pdfimport_ctx");
        if (raw) {
            const c = JSON.parse(raw);
            if (c && (!c.ts || Date.now() - c.ts < 15 * 60 * 1000)) srcCtx = c; // fresco < 15min
        }
    } catch (_) { /* noop */ }

    // Contenitori = gruppi delle VERIFICHE correlate (ognuno col proprio content_id).
    const verContainers = (srcCtx && Array.isArray(srcCtx.containers)) ? srcCtx.containers : [];
    const verVerifiche = (srcCtx && Array.isArray(srcCtx.verifiche)) ? srcCtx.verifiche : [];
    const hasVerContainers = verContainers.length > 0 || verVerifiche.length > 0;

    const srcInfoEl = qs("[data-fm-source-info]", root);
    if (srcCtx && srcCtx.title && srcInfoEl) {
        const bits = [srcCtx.indirizzo, srcCtx.classe, srcCtx.materia].filter(Boolean).join("/");
        srcInfoEl.hidden = false;
        srcInfoEl.textContent = `Importazione dalla pagina “${srcCtx.title}”${bits ? " (" + bits + ")" : ""}`
            + (hasVerContainers ? " — scegli la verifica di destinazione per ogni esercizio." : "");
    }
    const destInfo = qs("[data-fm-dest-info]", root);
    const destCodes = qs("[data-fm-dest-codes]", root);
    const destLabel = qs("[data-fm-dest-label]", root);
    if (hasVerContainers) {
        if (destCodes) destCodes.hidden = true;
        if (destInfo) {
            destInfo.hidden = false;
            destInfo.textContent = "Ogni esercizio va nella verifica/gruppo scelto nella colonna “Contenitore”.";
        }
        if (destLabel) destLabel.textContent = "Destinazione:";
    }

    // Bottone admin "Chiavi LLM" — cablato PRIMA dell'early-return: serve anche
    // quando non c'è ancora nessun provider configurato.
    const keysBtn = qs("[data-fm-keys]", root);
    if (keysBtn) {
        keysBtn.addEventListener("click", () => openKeyModal());
    }

    if (!els.extract) return; // feature disabilitata / nessun provider

    let sessionId = null;
    let viewerSession = null; // evita ri-render del PDF nel viewer ad ogni poll
    let presetApplied = false; // fonte preset già applicata a fine estrazione

    const viewer = new SideViewer(els.side);

    // Render dell'anteprima (uno o più esercizi) in basso. Markup AUTO-CONTENUTO
    // (no CSS legacy esercizio: su pagina standalone collassava a altezza 0) +
    // MathJax per le formule \(...\).
    async function showPreview(rows) {
        if (!els.render || !els.renderBody) return;
        rows = (rows || []).filter(Boolean);
        if (!rows.length) { toast("warn", "Anteprima", "Seleziona o clicca una riga."); return; }
        els.render.hidden = false;
        els.renderBody.innerHTML = rows.map(buildPreviewHtml).join("");
        els.render.scrollIntoView({ behavior: "smooth", block: "nearest" });
        await typesetMath(els.renderBody);
    }

    // Pannello "Log operazioni LLM": mostra tempi/esiti/token/errori per ogni
    // chiamata al provider (estrazione, argomenti, traduzione, soluzioni).
    function renderLog(entries) {
        if (!els.log || !els.logWrap) return;
        entries = Array.isArray(entries) ? entries : [];
        // Mostra SEMPRE il pannello (anche vuoto) una volta avviata l'estrazione:
        // la prima chiamata al provider può richiedere fino a ~90s, quindi senza
        // questo l'utente non vedrebbe nulla nel frattempo.
        els.logWrap.hidden = false;
        els.logWrap.open = true;
        if (els.logCount) els.logCount.textContent = `(${entries.length})`;
        if (!entries.length) {
            els.log.innerHTML = '<div class="fm-pdfimport__logrow is-retry">'
                + 'In attesa di risposta dal provider… (può richiedere fino a ~90s per pagina)</div>';
            return;
        }
        els.log.innerHTML = entries.map((e) => {
            const cls = e.status === "errore" ? "is-err" : (e.status === "retry" ? "is-retry" : "is-ok");
            const bits = [
                e.ts ? `[${escHtml(e.ts)}]` : "",
                escHtml(e.op || ""),
                e.status ? `· ${escHtml(e.status)}` : "",
                e.ms ? `· ${e.ms} ms` : "",
                (e.tokens_in || e.tokens_out) ? `· tok ${e.tokens_in || 0}/${e.tokens_out || 0}` : "",
                e.note ? `· ${escHtml(e.note)}` : "",
                e.error ? `· ${escHtml(e.error)}` : "",
            ].filter(Boolean).join(" ");
            return `<div class="fm-pdfimport__logrow ${cls}">${bits}</div>`;
        }).join("");
        els.log.scrollTop = els.log.scrollHeight;
    }

    const table = new ReviewTable(els.tbody, {
        onEdit: async (rowId, field, value) => {
            if (!sessionId) return;
            try {
                await editCell(sessionId, rowId, field, value);
            } catch (e) {
                toast("error", "Modifica", errText(e));
            }
        },
        onSelectionChange: (ids) => {
            els.bulkinfo.textContent = `${ids.length} selezionati`;
        },
        onShowPage: (page) => viewer.show(page),
        onPreview: (row) => showPreview([row]),
    });
    // Opzioni Contenitore = gruppi delle verifiche correlate + "nuovo gruppo".
    if (hasVerContainers) {
        table.setContainerOptions(verContainers, verVerifiche);
    }
    // Opzioni Origine = codici fonte del docente (stessi del selettore editor).
    getOrigins().then((codes) => {
        if (Array.isArray(codes) && codes.length) {
            table.setOriginOptions(codes);
            // Popola anche il selettore "Fonte (preset)" pre-estrazione.
            if (els.sourcePreset) {
                for (const c of codes.filter((x) => typeof x === "string" && x)) {
                    els.sourcePreset.appendChild(new Option(c, c));
                }
            }
        }
    }).catch(() => { /* nessuna fonte → colonna con solo "— fonte —" */ });

    // Avviso privacy: visibile quando il provider è cloud (≠ Ollama locale).
    const updatePrivacy = () => {
        if (!els.privacy || !els.provider) return;
        els.privacy.hidden = (els.provider.value || "").toLowerCase() === "ollama";
    };
    els.provider?.addEventListener("change", updatePrivacy);
    updatePrivacy();

    // Toggle delle passate AUTOMATICHE (auto_topics/difficulty/translation).
    root.querySelectorAll("[data-fm-auto]").forEach((cb) => {
        cb.addEventListener("change", async () => {
            const key = cb.getAttribute("data-fm-auto");
            cb.disabled = true;
            try { await toggleSetting(key, cb.checked); toast("info", "Automatismi", `${key} ${cb.checked ? "attivo" : "disattivato"}.`); }
            catch (e) { toast("error", "Automatismi", errText(e)); cb.checked = !cb.checked; }
            cb.disabled = false;
        });
    });

    // Toggle cache (admin).
    els.cache?.addEventListener("change", async () => {
        els.cache.disabled = true;
        try { await toggleCache(els.cache.checked); toast("info", "Cache", els.cache.checked ? "Cache LLM attiva." : "Cache LLM disattivata."); }
        catch (e) { toast("error", "Cache", errText(e)); els.cache.checked = !els.cache.checked; }
        els.cache.disabled = false;
    });

    // Resizer: trascina lo splitter per ridimensionare tabella ↔ anteprima PDF.
    const resizer = qs("[data-fm-resizer]", root);
    const ws = els.workspace;
    if (resizer && ws) {
        try {
            const saved = localStorage.getItem("fm_pdfimport_side_w");
            if (saved) ws.style.setProperty("--fm-pdfimport-side-w", saved);
        } catch (_) { /* noop */ }
        const onMove = (e) => {
            const rect = ws.getBoundingClientRect();
            let w = rect.right - e.clientX - 4;
            w = Math.max(220, Math.min(760, w));
            ws.style.setProperty("--fm-pdfimport-side-w", w + "px");
        };
        const onUp = () => {
            document.removeEventListener("pointermove", onMove);
            document.removeEventListener("pointerup", onUp);
            document.body.classList.remove("fm-pdfimport-resizing");
            try { localStorage.setItem("fm_pdfimport_side_w", ws.style.getPropertyValue("--fm-pdfimport-side-w")); } catch (_) { /* noop */ }
        };
        resizer.addEventListener("pointerdown", (e) => {
            e.preventDefault();
            document.body.classList.add("fm-pdfimport-resizing");
            document.addEventListener("pointermove", onMove);
            document.addEventListener("pointerup", onUp);
        });
    }

    const poller = new SessionPoller(
        (res) => {
            const s = res.session || {};
            const st = s.status || "";
            const prog = s.page_count ? ` (${s.pages_done}/${s.page_count} pagine)` : "";
            const labels = {
                uploaded: "Caricato", rasterized: "Rasterizzato",
                extracting: "Estrazione in corso", extracted: "Estrazione completata",
                reviewing: "In revisione", failed: "Errore", inserted: "Inserito",
                retry: "Provider lento — ritento…", cancelled: "Interrotto",
            };
            statusMsg(els.status, `${labels[st] || st}${prog}`, st === "failed" || st === "cancelled");
            if (s.last_error && (st === "failed" || st === "cancelled")) statusMsg(els.status, `${labels[st]}: ${s.last_error}`, true);

            // Stop visibile finché l'estrazione è attiva.
            const active = ["uploaded", "rasterized", "extracting", "retry"].includes(st);
            if (els.stop) els.stop.hidden = !active;
            if (!active && els.extract) els.extract.disabled = false;

            table.setRows(res.rows || []);
            renderLog(res.log);

            // Fonte preset: a estrazione completata, applicala a TUTTI gli esercizi
            // (la colonna Origine resta editabile per riga).
            const presetSrc = els.sourcePreset?.value || "";
            if (st === "extracted" && presetSrc && !presetApplied && (res.rows || []).length) {
                presetApplied = true;
                const ids = res.rows.map((r) => r.id).filter(Boolean);
                bulkEdit(sessionId, ids, "origin", presetSrc)
                    .then(() => getStatus(sessionId))
                    .then((s2) => table.setRows(s2.rows || []))
                    .catch(() => { presetApplied = false; });
            }

            if (s.page_count) {
                els.workspace.hidden = false;
                els.insertbar.hidden = false;
                if (els.bulkbar) els.bulkbar.hidden = false; // sempre visibile (con righe)
                // setSession SOLO al primo poll utile: ri-chiamarlo ad ogni poll
                // ri-renderizzava il PDF nel viewer → flicker. Lo facciamo una volta.
                if (viewerSession !== sessionId) {
                    viewerSession = sessionId;
                    viewer.setSession(sessionId, s.page_count);
                }
            }
        },
        (e) => statusMsg(els.status, `Polling: ${errText(e)}`, true)
    );

    els.extract.addEventListener("click", async () => {
        const file = els.file.files?.[0];
        if (!file) { toast("warn", "PDF", "Seleziona un file PDF."); return; }
        els.extract.disabled = true;
        statusMsg(els.status, "Caricamento e rasterizzazione…");
        try {
            const res = await createSession(file, els.provider.value);
            sessionId = res.session_id;
            presetApplied = false;
            statusMsg(els.status, `Sessione #${sessionId} avviata — estrazione…`);
            renderLog([]); // mostra subito il pannello log (placeholder "in attesa")
            poller.start(sessionId);
        } catch (e) {
            statusMsg(els.status, errText(e), true);
            toast("error", "Upload", errText(e));
        } finally {
            els.extract.disabled = false;
        }
    });

    els.stop?.addEventListener("click", async () => {
        if (!sessionId) return;
        els.stop.disabled = true;
        statusMsg(els.status, "Interruzione…");
        try {
            await stopSession(sessionId);
            poller.stop();
            if (els.stop) els.stop.hidden = true;
            statusMsg(els.status, "Estrazione interrotta.", true);
            toast("info", "Stop", "Estrazione interrotta.");
        } catch (e) {
            toast("error", "Stop", errText(e));
        } finally {
            els.stop.disabled = false;
        }
    });

    els.selall?.addEventListener("change", () => {
        els.tbody.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
            if (cb.checked !== els.selall.checked) { cb.checked = els.selall.checked; cb.dispatchEvent(new Event("change")); }
        });
    });

    // Bulk: il controllo valore diventa input o select secondo il campo scelto.
    const bulkOptionsFor = (field) => {
        switch (field) {
            case "type": return [["Collect", "Collect"], ["VF", "VF"], ["RM", "RM"]];
            case "badge_color": return [["", "—"], ["red", "red"], ["blue", "blue"], ["green", "green"], ["orange", "orange"]];
            case "difficulty": return [["0", "0"], ["1", "1"], ["2", "2"], ["3", "3"], ["4", "4"]];
            case "origin": return [["", "— fonte —"], ...table.originOptions.map((c) => [c, c])];
            case "container": return [["", "— scegli —"], ...table.containerOptions.map((c) => [c.content_id + "::" + c.group, c.group])];
            default: return null; // number/page/topic → input testuale
        }
    };
    const renderBulkValue = () => {
        if (!els.bulkValueWrap) return;
        const opts = bulkOptionsFor(els.bulkField.value);
        let ctl;
        if (opts) {
            ctl = document.createElement("select");
            ctl.className = "fm-pdfimport__select fm-pdfimport__select--sm";
            for (const [v, l] of opts) {
                const o = document.createElement("option");
                o.value = v; o.textContent = l;
                ctl.appendChild(o);
            }
        } else {
            ctl = document.createElement("input");
            ctl.type = "text";
            ctl.className = "fm-pdfimport__input fm-pdfimport__input--sm";
            ctl.placeholder = "valore";
        }
        ctl.setAttribute("data-fm-bulk-value", "");
        els.bulkValueWrap.replaceChildren(ctl);
    };
    els.bulkField?.addEventListener("change", renderBulkValue);
    renderBulkValue();

    els.bulkApply?.addEventListener("click", async () => {
        const ids = table.selectedIds();
        if (!ids.length || !sessionId) return;
        const field = els.bulkField.value;
        const ctl = els.bulkValueWrap?.querySelector("[data-fm-bulk-value]");
        const raw = ctl ? ctl.value : "";
        try {
            if (field === "container") {
                // value = content_id::group → imposta container + target per riga
                let group = "", cid = null;
                if (raw) { const i = raw.indexOf("::"); cid = raw.slice(0, i); group = raw.slice(i + 2); }
                table.rows.forEach((r) => { if (ids.includes(r.id)) { r.container = group; r.target_content_id = cid; } });
                await bulkEdit(sessionId, ids, "container", group);
            } else {
                const val = field === "difficulty" ? (parseInt(raw, 10) || 0) : raw;
                table.rows.forEach((r) => { if (ids.includes(r.id)) r[field] = val; });
                await bulkEdit(sessionId, ids, field, raw);
            }
            table.render();
            toast("success", "Bulk", `${ids.length} righe aggiornate.`);
        } catch (e) {
            toast("error", "Bulk", errText(e));
        }
    });

    els.bulkClear?.addEventListener("click", () => table.clearSelection());

    els.genSolutions?.addEventListener("click", async () => {
        if (!sessionId) return;
        els.genSolutions.disabled = true;
        statusMsg(els.status, "Generazione soluzioni AI…");
        try {
            // Poche per richiesta lato server (sotto nginx) → cicla finché finite.
            let total = 0, guard = 0;
            while (guard++ < 60) {
                const res = await generateSolutions(sessionId);
                total += res.updated || 0;
                const remaining = res.remaining || 0;
                statusMsg(els.status, `Soluzioni AI: ${total} generate, ${remaining} rimaste…`);
                if (remaining <= 0 || (res.updated || 0) === 0) break;
            }
            const s = await getStatus(sessionId); // refresh righe con le soluzioni
            table.setRows(s.rows || []);
            renderLog(s.log);
            toast("success", "Soluzioni", `Generate ${total} soluzioni.`);
            statusMsg(els.status, `Soluzioni generate: ${total}.`);
        } catch (e) {
            toast("error", "Soluzioni", errText(e));
            statusMsg(els.status, errText(e), true);
        } finally {
            els.genSolutions.disabled = false;
        }
    });

    els.genTopics?.addEventListener("click", async () => {
        if (!sessionId) return;
        els.genTopics.disabled = true;
        statusMsg(els.status, "Assegnazione argomenti…");
        try {
            const res = await generateTopics(sessionId);
            const s = await getStatus(sessionId); // ricarica le righe con i nuovi argomenti
            table.setRows(s.rows || []);
            renderLog(s.log);
            toast("success", "Argomenti", `Assegnati ${res.updated ?? 0} argomenti.`);
            statusMsg(els.status, `Argomenti assegnati: ${res.updated ?? 0}.`);
        } catch (e) {
            toast("error", "Argomenti", errText(e));
            statusMsg(els.status, errText(e), true);
        } finally {
            els.genTopics.disabled = false;
        }
    });

    els.refineDiff?.addEventListener("click", async () => {
        if (!sessionId) return;
        els.refineDiff.disabled = true;
        statusMsg(els.status, "Ricalcolo difficoltà (zoom pallini)…");
        try {
            const res = await refineDifficulty(sessionId);
            const s = await getStatus(sessionId);
            table.setRows(s.rows || []);
            renderLog(s.log);
            const nf = res.numbers_fixed ? `, ${res.numbers_fixed} numeri corretti` : "";
            toast("success", "Badge", `Aggiornate ${res.updated ?? 0}/${res.total ?? 0} difficoltà${nf}.`);
            statusMsg(els.status, `Badge: ${res.updated ?? 0}/${res.total ?? 0} difficoltà${nf}.`);
        } catch (e) {
            toast("error", "Difficoltà", errText(e));
            statusMsg(els.status, errText(e), true);
        } finally {
            els.refineDiff.disabled = false;
        }
    });

    els.translate?.addEventListener("click", async () => {
        if (!sessionId) return;
        els.translate.disabled = true;
        statusMsg(els.status, "Traduzione in italiano…");
        try {
            let total = 0, guard = 0;
            while (guard++ < 60) {
                const res = await translate(sessionId);
                total += res.updated || 0;
                const remaining = res.remaining || 0;
                statusMsg(els.status, `Traduzione: ${total} tradotti, ${remaining} rimasti…`);
                if (remaining <= 0 || (res.updated || 0) === 0) break;
            }
            const s = await getStatus(sessionId);
            table.setRows(s.rows || []);
            renderLog(s.log);
            toast(total ? "success" : "info", "Traduzione",
                total ? `Tradotti ${total} esercizi.` : "Nessun esercizio in lingua straniera da tradurre.");
            statusMsg(els.status, total ? `Tradotti ${total} esercizi.` : "Niente da tradurre (già in italiano).");
        } catch (e) {
            toast("error", "Traduzione", errText(e));
            statusMsg(els.status, errText(e), true);
        } finally {
            els.translate.disabled = false;
        }
    });

    els.preview?.addEventListener("click", () => showPreview(table.selectedRows()));

    els.insert?.addEventListener("click", async () => {
        const ids = table.selectedIds();
        if (!ids.length) { toast("warn", "Inserisci", "Seleziona almeno una riga."); return; }
        if (!sessionId) return;
        // Codici: dai campi manuali; se vuoti, eredita dal contesto studio (srcCtx).
        const ctx = {
            indirizzo: els.ctxIndirizzo.value.trim() || (srcCtx && srcCtx.indirizzo) || null,
            classe: els.ctxClasse.value.trim() || (srcCtx && srcCtx.classe) || null,
            subject: els.ctxSubject.value.trim() || (srcCtx && srcCtx.materia) || null,
        };
        // Target per-riga: ogni esercizio nella verifica/gruppo scelto.
        // target_content_id può mancare (non persistito server-side) → lo
        // ricaviamo dalla stringa container via le opzioni note. Evita il drop
        // silenzioso di righe che MOSTRANO un contenitore ma non hanno l'id.
        const rowTargets = {};
        let missing = 0;
        for (const r of table.rows) {
            if (!ids.includes(r.id)) continue;
            let cid = r.target_content_id;
            if (!cid && r.container) {
                const m = (table.containerOptions || []).find(
                    (c) => c.group === r.container && (!c.type || !r.type || c.type === r.type));
                if (m) cid = m.content_id;
            }
            if (cid) {
                rowTargets[r.id] = { content_id: String(cid), group: String(r.container || "") };
            } else {
                missing++;
            }
        }
        // Se NESSUNA riga ha un contenitore, si crea una nuova bozza: serve il
        // codice materia. Senza, il server risponde 400 invalid_subject_code →
        // blocca prima con un messaggio chiaro.
        const goingToDraft = Object.keys(rowTargets).length === 0;
        if (goingToDraft && !ctx.subject) {
            toast("warn", "Inserisci", hasVerContainers
                ? "Scegli un Contenitore (verifica) per gli esercizi selezionati, oppure imposta un codice materia."
                : "Compila il codice materia (campo “materia (codice)”) per creare la bozza.");
            return;
        }
        if (hasVerContainers && missing > 0 && !goingToDraft) {
            toast("warn", "Contenitore", `${missing} esercizi senza verifica scelta: verranno saltati.`);
        }
        els.insert.disabled = true;
        try {
            const res = await insert(sessionId, ctx, ids, rowTargets);
            const nEx = Object.keys(rowTargets).length || ids.length; // esercizi inviati
            const docs = (res.created_ids || []).length;
            const where = Object.keys(rowTargets).length
                ? `in ${docs} verifica/he`
                : "come bozza";
            const skip = missing > 0 ? ` (${missing} senza contenitore: saltati)` : "";
            toast("success", "Inserito", `${nEx} esercizi inseriti ${where}${skip}.`);
            statusMsg(els.status, `${nEx} esercizi inseriti ${where}${skip}.`);
        } catch (e) {
            toast("error", "Inserisci", errText(e));
        } finally {
            els.insert.disabled = false;
        }
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
