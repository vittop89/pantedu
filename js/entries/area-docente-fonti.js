// Entry Vite di views/area_docente/fonti.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

// ── blocco 1 ──
import { notify } from "/js/modules/ui/sync-panel.js";
import { fetchJson, fetchCsrf, escHtml as escapeHtml } from "/js/modules/core/dom-utils.js";
{

let registry = { sources: [] };

async function loadRegistry() {
    const tbody = document.querySelector("#fm-fonti-table tbody");
    tbody.innerHTML = '<tr><td colspan="5" class="fm-text-center fm-p-4 fm-text-muted">Caricamento…</td></tr>';
    try {
        const j = await fetchJson("/api/teacher/sources.registry.json", { cache: "no-store" });
        registry = j;
        const list = Array.isArray(j.sources) ? j.sources : [];
        document.getElementById("fm-fonti-status").textContent = `${list.length} fonti registrate`;
        renderTable(list);
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" class="fm-text-center fm-p-4 fm-text-danger">Errore: ${e.message}</td></tr>`;
    }
}

function renderTable(list) {
    const tbody = document.querySelector("#fm-fonti-table tbody");
    tbody.innerHTML = "";
    if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="fm-text-center fm-p-4 fm-text-muted fm-fst-italic">Nessuna fonte registrata. Clicca "Aggiungi fonte".</td></tr>';
        return;
    }
    list.forEach((s, idx) => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td><code class="fm-mono-muted fm-text-11" >${escapeHtml(s.key || "")}</code></td>
            <td>${escapeHtml(s.book || "")}</td>
            <td>${escapeHtml(s.volume || "")}</td>
            <td>${escapeHtml(s.authors || "")}</td>
            <td>
                <div style="display:flex;gap:6px;flex-wrap:nowrap;align-items:center">
                    <button type="button" class="fm-btn fm-btn--ghost fm-btn--sm" data-act="edit" data-idx="${idx}" aria-label="Modifica">✏️</button>
                    <button type="button" class="fm-btn fm-btn--danger fm-btn--sm" data-act="del" data-idx="${idx}" aria-label="Elimina">🗑️</button>
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });
    tbody.querySelectorAll('button[data-act]').forEach(b => b.addEventListener("click", onAction));
}


async function onAction(e) {
    const idx = parseInt(e.target.dataset.idx, 10);
    const act = e.target.dataset.act;
    const list = Array.isArray(registry.sources) ? registry.sources : [];
    const item = list[idx];
    if (act === "edit") openDialog(item, idx);
    else if (act === "del") {
        if (!(await window.FM.Dialog.confirm(`Eliminare la fonte "${item.book || item.key}"?`))) return;
        list.splice(idx, 1);
        await saveRegistry();
    }
}

async function saveRegistry() {
    // G22.S15.bis — il registry è la sola source-of-truth.
    // PUT /api/teacher/sources.registry.json scrive nativamente; non
    // serve più fallback verso /sources.json (deprecato).
    const csrf = await fetchCsrf();
    try {
        const j = await fetchJson("/api/teacher/sources.registry.json", {
            method: "PUT",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify(registry),
        });
        if (!j.ok) throw new Error(j.error || "salvataggio non riuscito");
        notify("Fonti", "ok", "Salvato", 2500);
        await loadRegistry();
    } catch (e) {
        notify("Fonti", "error", `Salvataggio fallito: ${e.message}`, 5000);
    }
}

function openDialog(existing, editIdx) {
    document.getElementById("fm-fonti-dialog")?.remove();
    const isEdit = editIdx !== undefined;
    const dlg = document.createElement("div");
    dlg.id = "fm-fonti-dialog";
    dlg.className = "fm-fd-overlay";
    const slug = (s) => String(s).toLowerCase().normalize("NFKD").replace(/[̀-ͯ]/g, "").replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "");
    dlg.innerHTML = `
        <div class="fm-fd-card">
            <div class="fm-fd-header">📚 ${isEdit ? 'Modifica' : 'Aggiungi'} fonte</div>
            <div class="fm-fd-body">
                <label class="fm-fd-label">
                    <span>Libro (titolo)</span>
                    <input data-role="book" type="text" class="fm-fd-input" value="${escapeHtml(existing?.book || '')}">
                </label>
                <label class="fm-fd-label">
                    <span>Volume / edizione (es. "Vol.2 Ed.3 - ZANICHELLI")</span>
                    <input data-role="volume" type="text" class="fm-fd-input" value="${escapeHtml(existing?.volume || '')}">
                </label>
                <label class="fm-fd-label">
                    <span>Autori</span>
                    <input data-role="authors" type="text" class="fm-fd-input" value="${escapeHtml(existing?.authors || '')}">
                </label>
                <label class="fm-fd-label">
                    <span>Key (auto-generata da libro+volume — non cambiare se già usata)</span>
                    <input data-role="key" type="text" class="fm-fd-input fm-fd-input--mono" value="${escapeHtml(existing?.key || '')}">
                </label>
            </div>
            <div class="fm-fd-footer">
                <button data-act="cancel" class="fm-btn fm-btn--ghost fm-btn--sm">Annulla</button>
                <button data-act="ok" class="fm-btn fm-btn--primary fm-btn--sm">${isEdit ? 'Salva' : 'Aggiungi'}</button>
            </div>
        </div>`;
    document.body.appendChild(dlg);
    const qs = (sel) => dlg.querySelector(sel);
    const close = () => { dlg.remove(); document.removeEventListener("keydown", esc); };
    const esc = (e) => { if (e.key === "Escape") close(); };
    document.addEventListener("keydown", esc);
    // Auto-key se vuota
    const refreshKey = () => {
        const k = qs('[data-role="key"]');
        if (k.value.trim()) return;
        const book = qs('[data-role="book"]').value;
        const vol = qs('[data-role="volume"]').value;
        k.value = slug(`${book  } ${  vol}`);
    };
    ['book','volume'].forEach(r => qs(`[data-role="${r}"]`).addEventListener('blur', refreshKey));
    dlg.addEventListener("click", (e) => {
        const act = e.target?.dataset?.act;
        if (act === "cancel" || e.target === dlg) close();
        if (act === "ok") {
            const item = {
                key: qs('[data-role="key"]').value.trim(),
                book: qs('[data-role="book"]').value.trim(),
                volume: qs('[data-role="volume"]').value.trim(),
                authors: qs('[data-role="authors"]').value.trim(),
            };
            if (!item.book || !item.key) {
                notify("Fonti", "warn", "Libro e key obbligatori", 4000);
                return;
            }
            if (!Array.isArray(registry.sources)) registry.sources = [];
            if (isEdit) registry.sources[editIdx] = item;
            else registry.sources.push(item);
            close();
            saveRegistry();
        }
    });
    setTimeout(() => qs('[data-role="book"]').focus(), 50);
}

document.getElementById("fm-fonti-add").addEventListener("click", () => openDialog(null));
document.getElementById("fm-fonti-refresh").addEventListener("click", loadRegistry);
// Il blocco del catalogo (più sotto) scrive nel registro con lo stesso PUT:
// quando lo fa, questa tabella si rilegge da sola.
window.addEventListener("fm:fonti-registro-cambiato", loadRegistry);
loadRegistry();
}

// ── blocco 2 ──
{
// G27.badge.style — UI sezione "Stile badge" (preset+overrides).

let bsState = {
    preset: "_default",
    overrides: { fonte: {}, badge: {} },
    presets: [],
    defaults: null,
    sizes: [],
};

async function bsLoad() {
    try {
        const j = await fetchJson("/api/teacher/badge-style", { cache: "no-store" });
        if (j.error) throw new Error(j.error);
        bsState = {
            preset:    j.preset    || "_default",
            overrides: j.overrides || { fonte: {}, badge: {} },
            presets:   Array.isArray(j.presets) ? j.presets : [],
            defaults:  j.defaults  || null,
            sizes:     Array.isArray(j.sizes) ? j.sizes : [],
            resolved:  j.resolved  || null,
        };
        bsRender();
        bsRenderPreview();
    } catch (e) {
        document.getElementById("fm-bs-status").textContent = `Errore caricamento: ${  e.message}`;
    }
}

function bsPopulateSizeSelect(sel) {
    sel.innerHTML = `<option value="">(eredita dal preset)</option>${
         bsState.sizes.map(s => `<option value="${s}">${s}</option>`).join("")}`;
}

function bsRender() {
    // Preset dropdown
    const pSel = document.getElementById("fm-bs-preset");
    pSel.innerHTML = bsState.presets.map(p => `<option value="${p}">${p}</option>`).join("");
    pSel.value = bsState.preset;

    // Override fields: popola size selects + valori correnti
    document.querySelectorAll(".fm-bs-override").forEach(el => {
        const section = el.dataset.section;
        const field   = el.dataset.field;
        // size selects
        const sizeFields = ["title_size", "meta_size", "ex_size", "diff_size"];
        if (el.tagName === "SELECT" && sizeFields.includes(field)) {
            bsPopulateSizeSelect(el);
        }
        const cur = (bsState.overrides[section] || {})[field];
        if (cur !== undefined && cur !== null) {
            el.value = String(cur);
        } else {
            el.value = "";
        }
    });
}

function bsCollectOverrides() {
    const ov = { fonte: {}, badge: {} };
    document.querySelectorAll(".fm-bs-override").forEach(el => {
        const v = el.value.trim();
        if (v === "") return; // empty = inherit
        const section = el.dataset.section;
        const field   = el.dataset.field;
        if (field === "diff_max") {
            const n = parseInt(v, 10);
            if (!Number.isNaN(n)) ov[section][field] = n;
        } else {
            ov[section][field] = v;
        }
    });
    if (!Object.keys(ov.fonte).length) delete ov.fonte;
    if (!Object.keys(ov.badge).length) delete ov.badge;
    return ov;
}

async function bsSave() {
    const preset = document.getElementById("fm-bs-preset").value || "_default";
    const overrides = bsCollectOverrides();
    const csrf = await fetchCsrf();
    document.getElementById("fm-bs-status").textContent = "Salvataggio…";
    try {
        const j = await fetchJson("/api/teacher/badge-style", {
            method: "PUT",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({ preset, overrides }),
        });
        if (!j.ok) throw new Error(j.error || j.message || "salvataggio non riuscito");
        bsState.preset    = j.preset;
        bsState.overrides = j.overrides;
        bsState.resolved  = j.resolved;
        document.getElementById("fm-bs-status").textContent = "Salvato.";
        notify("Stile badge", "ok", "Preferenze salvate", 2500);
        bsRenderPreview();
    } catch (e) {
        document.getElementById("fm-bs-status").textContent = `Errore: ${  e.message}`;
        notify("Stile badge", "error", `Salvataggio fallito: ${  e.message}`, 5000);
    }
}

function bsReset() {
    document.querySelectorAll(".fm-bs-override").forEach(el => { el.value = ""; });
    document.getElementById("fm-bs-status").textContent = "Override resettati (clicca Salva per persistere).";
}

function bsRenderPreview() {
    // Mock client-side della stringa preamble (NON la verita': il server fa il merge).
    // Se l'utente cambia il preset nel dropdown senza salvare, l'anteprima usa
    // bsState.resolved come baseline + override correnti dal form.
    const r = bsState.resolved || {};
    const f = (r.fonte || {});
    const b = (r.badge || {});
    const ov = bsCollectOverrides();
    const merged = {
        fonte: { ...f, ...(ov.fonte || {}) },
        badge: { ...b, ...(ov.badge || {}) },
    };
    const fontePart = `\\fmsetfonte{titlesize=${merged.fonte.title_size||"\\small"},metasize=${merged.fonte.meta_size||"\\tiny"},rowsep=${merged.fonte.row_sep||"-5pt"},colspec=${merged.fonte.col_spec||"|c|"}}`;
    const badgePart = `\\fmsetbadge{bg=${merged.badge.bg||"gray"},txt=${merged.badge.txt||"white"},exsize=${merged.badge.ex_size||"\\large"},minw=${merged.badge.min_width||"1cm"},diffmax=${merged.badge.diff_max||4},diffsize=${merged.badge.diff_size||"\\huge"}}`;
    document.getElementById("fm-bs-preview").textContent =
        `% Anteprima (il server applicherà il merge definitivo al prossimo salvataggio verifica)\n${
         fontePart  }\n${  badgePart}`;
}

document.getElementById("fm-bs-save").addEventListener("click", bsSave);
document.getElementById("fm-bs-reset").addEventListener("click", bsReset);
document.getElementById("fm-bs-preset").addEventListener("change", bsRenderPreview);
document.querySelectorAll(".fm-bs-override").forEach(el => el.addEventListener("input", bsRenderPreview));
bsLoad();
}

// ── blocco 3 — ADR-036: dal catalogo delle adozioni alle fonti ──
{
// I libri in adozione dell'istituto, per le classi e le materie che il
// docente ha spuntato. «Aggiungi» mette la proposta nel registro delle fonti
// con lo stesso PUT del blocco 1; da lì in poi è una fonte come le altre.
// Si costruisce con i nodi, non con innerHTML: titoli e autori vengono dal
// dataset, e non c'è niente da escapare.

const tabella = document.querySelector("#fm-adozioni-table tbody");
const stato   = document.getElementById("fm-adozioni-status");
const selCls  = document.getElementById("fm-adozioni-classe");
const selMat  = document.getElementById("fm-adozioni-materia");
const chkTutte = document.getElementById("fm-adozioni-tutte");
let filtriPopolati = false;

function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v === undefined || v === null || v === false) continue;
        if (k === "dataset") { Object.assign(node.dataset, v); continue; }
        node.setAttribute(k, String(v));
    }
    node.append(...children.filter((c) => c !== null && c !== undefined && c !== false));
    return node;
}

function messaggio(testo, classe = "fm-text-muted fm-fst-italic") {
    tabella.replaceChildren(el("tr", {}, el("td", { colspan: "6", class: `fm-text-center fm-p-4 ${classe}` }, testo)));
}

function popolaFiltri(j) {
    if (filtriPopolati) return;
    filtriPopolati = true;
    for (const c of j.classi_mie || []) selCls.append(el("option", { value: c }, c));
    for (const m of j.materie_mie || []) selMat.append(el("option", { value: m }, m));
}

async function caricaAdozioni() {
    stato.textContent = "Caricamento…";
    const qs = new URLSearchParams();
    if (selCls.value) qs.set("classe", selCls.value);
    if (selMat.value) qs.set("materia", selMat.value);
    if (chkTutte.checked) qs.set("tutte", "1");
    let j;
    try {
        j = await fetchJson(`/api/teacher/adozioni?${qs.toString()}`, { cache: "no-store" });
    } catch (e) {
        stato.textContent = "";
        messaggio(`Catalogo non disponibile: ${e.message || e}`, "fm-text-danger");
        return;
    }
    popolaFiltri(j);
    const libri = Array.isArray(j.libri) ? j.libri : [];
    if (j.catalogo_vuoto) {
        stato.textContent = "";
        messaggio("La scuola non ha ancora un catalogo delle adozioni: lo carica l'amministratore dal pannello degli istituti.");
        return;
    }
    if (j.senza_spunte) {
        stato.textContent = "";
        messaggio("Spunta le tue classi nel profilo per vedere i loro libri, oppure scegli «tutto l'istituto».");
        return;
    }
    stato.textContent = `${libri.length} libri${j.anno_scolastico ? ` · a.s. ${j.anno_scolastico}` : ""}`;
    if (!libri.length) {
        messaggio("Nessun libro in adozione per queste classi e materie.");
        return;
    }
    tabella.replaceChildren(...libri.map((l) => {
        const azione = l.in_registro
            ? el("span", { class: "fm-text-muted fm-text-xs" }, "✓ fra le tue fonti")
            : el("button", { type: "button", class: "fm-btn fm-btn--primary fm-btn--sm", dataset: { adozione: String(l.id) } }, "➕ Aggiungi");
        return el("tr", { dataset: { adozione: String(l.id) } },
            el("td", {}, l.classe, l.materia ? el("span", { class: "fm-text-muted fm-text-xs" }, ` · ${l.materia}`) : null),
            el("td", { class: "fm-text-13" }, l.disciplina),
            el("td", {}, el("strong", {}, l.titolo), l.sottotitolo ? el("div", { class: "fm-text-xs fm-text-muted" }, l.sottotitolo) : null,
                el("div", { class: "fm-text-xs fm-text-muted" }, `ISBN ${l.isbn}${l.nuova_adozione ? " · nuova adozione" : ""}`)),
            el("td", { class: "fm-text-13" }, l.proposta.volume || "—"),
            el("td", { class: "fm-text-13" }, l.autori || "—"),
            el("td", {}, azione),
        );
    }));
}

async function aggiungiAlleFonti(id, bottone) {
    bottone.disabled = true;
    try {
        const catalogo = await fetchJson(`/api/teacher/adozioni?tutte=1`, { cache: "no-store" });
        const libro = (catalogo.libri || []).find((l) => String(l.id) === String(id));
        if (!libro) throw new Error("libro non trovato nel catalogo");
        const reg = await fetchJson("/api/teacher/sources.registry.json", { cache: "no-store" });
        const sources = Array.isArray(reg.sources) ? reg.sources : [];
        let key = libro.proposta.key;
        // Una key gia' usata da un'altra fonte non si sovrascrive: si allunga.
        if (sources.some((s) => s.key === key && s.isbn !== libro.proposta.isbn)) key = `${key}_${libro.id}`;
        sources.push({ ...libro.proposta, key });
        const csrf = await fetchCsrf();
        const esito = await fetchJson("/api/teacher/sources.registry.json", {
            method: "PUT",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({ sources }),
        });
        if (!esito.ok) throw new Error(esito.error || "salvataggio non riuscito");
        notify("Fonti", "ok", `«${libro.titolo}» è fra le tue fonti`, 3000);
        window.dispatchEvent(new CustomEvent("fm:fonti-registro-cambiato"));
        await caricaAdozioni();
    } catch (e) {
        bottone.disabled = false;
        notify("Fonti", "error", `Non aggiunta: ${e.message || e}`, 5000);
    }
}

tabella.addEventListener("click", (e) => {
    const b = e.target.closest("button[data-adozione]");
    if (b) aggiungiAlleFonti(b.dataset.adozione, b);
});
selCls.addEventListener("change", caricaAdozioni);
selMat.addEventListener("change", caricaAdozioni);
chkTutte.addEventListener("change", caricaAdozioni);
caricaAdozioni();
}
