/**
 * Admin — editor dei file options-source JSON (tab "Sorgenti JSON" in
 * /admin/templates). Lista i file via /api/admin/risdoc/options-sources,
 * carica/salva un file via /api/admin/risdoc/options-source.
 *
 * Editor STRUTTURATO per il formato comune `[{titolo, contenuti:[{label,checked}]}]`
 * (preserva eventuali campi extra del gruppo, es. UDA di programmi_svolti) +
 * fallback RAW JSON per formati non standard o modifiche avanzate.
 */
import { notify } from "/js/modules/ui/sync-panel.js";
import { fetchJson, fetchCsrf } from "/js/modules/core/dom-utils.js";

const $ = (id) => document.getElementById(id);
const root = () => document.querySelector('[data-panel="json-sources"]');

let model = [];        // [{...group, titolo, contenuti:[{...item, label, checked}]}]
let currentPath = "";
let rawMode = false;
let structuredOk = false; // il file ha il formato gruppi/contenuti
let allFiles = [];        // [{path, label, bytes}] — lista completa per il filtro

const setStatus = (s) => { const el = $("fm-osa-status"); if (el) el.textContent = s; };

/** Il parsed ha il formato strutturato gruppi→contenuti? */
function isStructured(parsed) {
    return Array.isArray(parsed) && parsed.length > 0
        && parsed.every((g) => g && typeof g === "object" && Array.isArray(g.contenuti));
}

/** Dataset = primo segmento del path (i top-level folder/file). */
function datasetOf(path) { return (path.split("/")[0] || path); }

async function loadFileList() {
    const sel = $("fm-osa-file");
    try {
        const j = await fetchJson("/api/admin/risdoc/options-sources", { cache: "no-store" });
        allFiles = Array.isArray(j.files) ? j.files : [];
        // popola il select Dataset (distinti)
        const datasets = [...new Set(allFiles.map((f) => datasetOf(f.path)))].sort();
        const dsSel = $("fm-osa-dataset");
        dsSel.innerHTML = '<option value="">(tutti)</option>'
            + datasets.map((d) => `<option value="${d}">${d}</option>`).join("");
        applyFilter();
        setStatus(`${allFiles.length} file trovati`);
    } catch (e) {
        sel.innerHTML = '<option value="">(errore)</option>';
        setStatus(`Errore lista: ${e.message}`);
    }
}

/** Filtra la lista file per dataset / indirizzo / classe / materia / testo
 *  (stile tab "Dati curriculari") e ripopola la tendina file. */
function applyFilter() {
    const ds = $("fm-osa-dataset").value;
    const ind = ($("fm-osa-ind").value || "").trim().toLowerCase();
    const cls = ($("fm-osa-cls").value || "").trim().toLowerCase();
    const mat = ($("fm-osa-mat").value || "").trim().toLowerCase();
    const q = ($("fm-osa-search").value || "").trim().toLowerCase();
    const matched = allFiles.filter((f) => {
        const p = f.path.toLowerCase();
        if (ds && datasetOf(f.path) !== ds) return false;
        if (ind && !p.includes(`/${ind}/`) && !p.includes(`${ind}_`)) return false;
        if (mat && !p.includes(`/${mat}/`) && !p.includes(`_${mat}`)) return false;
        if (cls && !p.includes(`_${cls}_`)) return false;
        if (q && !p.includes(q) && !(f.label || "").toLowerCase().includes(q)) return false;
        return true;
    });
    const sel = $("fm-osa-file");
    sel.innerHTML = matched.length
        ? matched.map((f) => `<option value="${f.path}">${f.label} — ${f.path}</option>`).join("")
        : '<option value="">(nessun file con questi filtri)</option>';
    const cnt = $("fm-osa-count");
    if (cnt) cnt.textContent = String(matched.length);
}

async function loadFile() {
    const path = $("fm-osa-file").value;
    if (!path) return;
    setStatus("Caricamento…");
    try {
        const j = await fetchJson(`/api/admin/risdoc/options-source?path=${encodeURIComponent(path)}`, { cache: "no-store" });
        if (j.error) throw new Error(j.error);
        currentPath = j.path;
        const parsed = j.valid ? j.parsed : null;
        structuredOk = isStructured(parsed);
        if (structuredOk) {
            // clona profondo nel model (così editiamo senza toccare l'originale)
            model = parsed.map((g) => ({
                ...g,
                titolo: typeof g.titolo === "string" ? g.titolo : "",
                contenuti: (g.contenuti || []).map((it) => ({
                    ...it,
                    label: typeof it.label === "string" ? it.label : String(it.label ?? ""),
                    checked: !!it.checked,
                })),
            }));
            rawMode = false;
        } else {
            model = [];
            rawMode = true; // formato non standard → solo raw
        }
        $("fm-osa-raw").value = j.content || "";
        applyMode();
        $("fm-osa-save").disabled = false;
        $("fm-osa-raw-toggle").disabled = !structuredOk; // se non strutturato resta raw
        setStatus(`Caricato "${path}" (${j.bytes} byte)${structuredOk ? "" : " — formato non standard: solo Raw JSON"}`);
    } catch (e) {
        setStatus(`Errore: ${e.message}`);
    }
}

function applyMode() {
    const struct = $("fm-osa-structured");
    const addG = $("fm-osa-add-group");
    const raw = $("fm-osa-raw");
    // NB: il bottone ha .fm-btn{display} che vince su [hidden] → uso style.display.
    if (rawMode) {
        struct.hidden = true; addG.style.display = "none"; raw.hidden = false;
        $("fm-osa-raw-toggle").textContent = "▦ Editor strutturato";
    } else {
        struct.hidden = false; addG.style.display = ""; raw.hidden = true;
        $("fm-osa-raw-toggle").textContent = "{ } Raw JSON";
        renderStructured();
    }
}

function renderStructured() {
    const wrap = $("fm-osa-structured");
    wrap.innerHTML = "";
    model.forEach((g, gi) => wrap.appendChild(renderGroup(g, gi)));
}

function mini(txt, title, danger, onClick) {
    const b = document.createElement("button");
    b.type = "button";
    b.className = "fm-osa__mini" + (danger ? " fm-osa__mini--danger" : "");
    b.textContent = txt; b.title = title;
    b.addEventListener("click", onClick);
    return b;
}

function renderGroup(g, gi) {
    const box = document.createElement("div");
    box.className = "fm-osa__group";

    const head = document.createElement("div");
    head.className = "fm-osa__group-head";
    const titleIn = document.createElement("input");
    titleIn.className = "fm-bordered-box fm-osa__group-title";
    titleIn.value = g.titolo || "";
    titleIn.placeholder = "Titolo gruppo (può essere vuoto)";
    titleIn.addEventListener("input", () => { model[gi].titolo = titleIn.value; });

    // campi extra preservati (es. UDA di programmi_svolti) — solo indicazione
    const extras = Object.keys(g).filter((k) => k !== "titolo" && k !== "contenuti");
    const extraNote = document.createElement("span");
    extraNote.className = "fm-osa__extra";
    if (extras.length) extraNote.textContent = `+${extras.length} campi extra preservati`;

    head.append(
        titleIn, extraNote,
        mini("↑", "Sposta gruppo su", false, () => moveGroup(gi, -1)),
        mini("↓", "Sposta gruppo giù", false, () => moveGroup(gi, 1)),
        mini("🗑", "Elimina gruppo", true, () => removeGroup(gi)),
    );
    box.appendChild(head);

    (g.contenuti || []).forEach((it, ii) => box.appendChild(renderItem(gi, it, ii)));

    const addItem = document.createElement("button");
    addItem.type = "button";
    addItem.className = "fm-btn fm-osa__additem";
    addItem.textContent = "+ voce";
    addItem.addEventListener("click", () => {
        model[gi].contenuti.push({ label: "", checked: false });
        renderStructured();
    });
    box.appendChild(addItem);
    return box;
}

function renderItem(gi, it, ii) {
    const row = document.createElement("div");
    row.className = "fm-osa__item";

    const cb = document.createElement("input");
    cb.type = "checkbox";
    cb.className = "fm-osa__item-check";
    cb.checked = !!it.checked;
    cb.title = "Spuntata di default";
    cb.addEventListener("change", () => { model[gi].contenuti[ii].checked = cb.checked; });

    const lab = document.createElement("textarea");
    lab.className = "fm-bordered-box fm-osa__item-label";
    lab.rows = 1;
    lab.value = it.label || "";
    lab.placeholder = "Etichetta voce…";
    lab.addEventListener("input", () => { model[gi].contenuti[ii].label = lab.value; });

    const btns = document.createElement("div");
    btns.className = "fm-osa__btnrow";
    btns.append(
        mini("↑", "Su", false, () => moveItem(gi, ii, -1)),
        mini("↓", "Giù", false, () => moveItem(gi, ii, 1)),
        mini("🗑", "Elimina voce", true, () => { model[gi].contenuti.splice(ii, 1); renderStructured(); }),
    );

    row.append(cb, lab, btns);
    return row;
}

function moveItem(gi, ii, dir) {
    const arr = model[gi].contenuti;
    const j = ii + dir;
    if (j < 0 || j >= arr.length) return;
    [arr[ii], arr[j]] = [arr[j], arr[ii]];
    renderStructured();
}
function moveGroup(gi, dir) {
    const j = gi + dir;
    if (j < 0 || j >= model.length) return;
    [model[gi], model[j]] = [model[j], model[gi]];
    renderStructured();
}
async function removeGroup(gi) {
    const t = model[gi].titolo || "(senza titolo)";
    const ok = window.FM?.Dialog?.confirm
        ? await window.FM.Dialog.confirm(`Eliminare il gruppo «${t}» e le sue voci?`, { title: "Elimina gruppo", kind: "danger", okLabel: "Elimina" })
        : true;
    if (!ok) return;
    model.splice(gi, 1);
    renderStructured();
}

function toggleRaw() {
    if (!rawMode) {
        // structured → raw: serializza il model
        $("fm-osa-raw").value = JSON.stringify(model, null, 4);
        rawMode = true;
    } else {
        // raw → structured: prova a parsare
        try {
            const parsed = JSON.parse($("fm-osa-raw").value);
            if (!isStructured(parsed)) { notify("Il JSON non ha il formato gruppi/contenuti: resto in Raw.", "warn"); return; }
            model = parsed.map((g) => ({ ...g, titolo: g.titolo || "", contenuti: (g.contenuti || []).map((it) => ({ ...it, label: String(it.label ?? ""), checked: !!it.checked })) }));
            rawMode = false;
        } catch (e) { notify(`JSON non valido: ${e.message}`, "error"); return; }
    }
    applyMode();
}

async function save() {
    let content;
    if (rawMode) {
        try { content = JSON.stringify(JSON.parse($("fm-osa-raw").value)); }
        catch (e) { notify(`JSON non valido: ${e.message}`, "error"); return; }
    } else {
        content = JSON.stringify(model);
    }
    setStatus("Salvataggio…");
    try {
        const csrf = await fetchCsrf();
        const res = await fetch("/api/admin/risdoc/options-source", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": csrf,
                "X-Audit-Reason": `Modifica sorgente opzioni: ${currentPath}`,
                "Accept": "application/json",
            },
            body: JSON.stringify({ path: currentPath, content }),
        });
        const j = await res.json().catch(() => ({}));
        if (!res.ok || j.error) throw new Error(j.error || `HTTP ${res.status}`);
        setStatus(`✓ Salvato "${j.path}" (${j.bytes} byte) — backup .bak creato`);
        notify("File sorgente salvato (backup .bak creato)", "success");
    } catch (e) {
        setStatus(`Errore salvataggio: ${e.message}`);
        notify(`Salvataggio fallito: ${e.message}`, "error");
    }
}

let inited = false;
export function initOptionsSourcesAdmin() {
    if (inited || !root()) return;
    inited = true;
    $("fm-osa-add-group").style.display = "none"; // mostrato solo dopo il load (editor strutturato)
    loadFileList();
    $("fm-osa-load").addEventListener("click", loadFile);
    $("fm-osa-raw-toggle").addEventListener("click", toggleRaw);
    $("fm-osa-add-group").addEventListener("click", () => { model.push({ titolo: "", contenuti: [{ label: "", checked: false }] }); renderStructured(); });
    $("fm-osa-save").addEventListener("click", save);
    // filtro stile "Dati curriculari"
    ["fm-osa-dataset", "fm-osa-ind", "fm-osa-cls", "fm-osa-mat", "fm-osa-search"].forEach((id) => {
        const el = $(id);
        el?.addEventListener(el.tagName === "SELECT" ? "change" : "input", applyFilter);
    });
    $("fm-osa-filter-reset")?.addEventListener("click", () => {
        ["fm-osa-dataset", "fm-osa-ind", "fm-osa-cls", "fm-osa-mat", "fm-osa-search"].forEach((id) => { const el = $(id); if (el) el.value = ""; });
        applyFilter();
    });
}

// auto-init quando il tab diventa visibile (o subito se già attivo)
if (root()) {
    const tabBtn = document.querySelector('.fm-admin-tab[data-tab="json-sources"]');
    tabBtn?.addEventListener("click", () => initOptionsSourcesAdmin(), { once: true });
    if (!root().hidden) initOptionsSourcesAdmin();
}
