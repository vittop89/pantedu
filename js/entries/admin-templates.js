// Entry Vite di views/admin/templates.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

// ── blocco 1 ──
import { notify } from "/js/modules/ui/sync-panel.js";
import { fetchJson, fetchCsrf } from "/js/modules/core/dom-utils.js";
import { auditReason } from "/js/modules/core/audit-reason.js";
// I tre moduli della pagina, dal bundle e non più grezzi da /js/ (23/9/2026,
// A-17): con le dipendenze condivise, un'istanza sola di dom-utils e di
// sync-panel. Si valutano prima del corpo di questa entry, come quando
// erano tre <script> che la precedevano nella vista.
import "/js/modules/features/admin-verifica-templates.js";
import "/js/modules/features/admin-tikz-templates.js";
import "/js/modules/features/admin-options-sources.js";
{
// G27.badge.style — admin preset CRUD

let bsaPresets = [];
let bsaCurrentName = "";

const bsaCsrf = fetchCsrf;
const qs = (id) => document.getElementById(id);
const setStatus = (s) => { qs("fm-bsa-status").textContent = s; };

async function bsaList() {
    const scope = qs("fm-bsa-scope").value.trim() || "_default";
    try {
        const j = await fetchJson(`/api/admin/badge-style-presets?scope=${encodeURIComponent(scope)}`, { cache: "no-store" });
        if (j.error) throw new Error(j.error);
        bsaPresets = Array.isArray(j.presets) ? j.presets : [];
        const sel = qs("fm-bsa-preset");
        sel.innerHTML = bsaPresets.length
            ? bsaPresets.map(p => `<option value="${p}">${p}</option>`).join("")
            : '<option value="">(nessun preset)</option>';
        setStatus(`${bsaPresets.length} preset trovati`);
        if (bsaPresets.length) bsaLoadPreset(bsaPresets[0]);
        else { qs("fm-bsa-editor").value = ""; qs("fm-bsa-save").disabled = true; qs("fm-bsa-delete").disabled = true; }
    } catch (e) {
        setStatus(`Errore: ${  e.message}`);
    }
}

async function bsaLoadPreset(name) {
    const scope = qs("fm-bsa-scope").value.trim() || "_default";
    try {
        const j = await fetchJson(`/api/admin/badge-style-presets/${encodeURIComponent(name)}?scope=${encodeURIComponent(scope)}`, { cache: "no-store" });
        if (j.error) throw new Error(j.error);
        bsaCurrentName = j.name;
        qs("fm-bsa-editor").value = JSON.stringify(j.style, null, 2);
        qs("fm-bsa-save").disabled = false;
        qs("fm-bsa-format").disabled = false;
        qs("fm-bsa-delete").disabled = (name === "_default" && scope === "_default");
        setStatus(`preset "${j.name}" caricato (scope ${j.scope})`);
    } catch (e) {
        setStatus(`Errore: ${  e.message}`);
    }
}

async function bsaSave() {
    const scope = qs("fm-bsa-scope").value.trim() || "_default";
    const name  = bsaCurrentName;
    if (!name) return;
    let payload;
    try { payload = JSON.parse(qs("fm-bsa-editor").value); }
    catch (e) { setStatus(`JSON non valido: ${  e.message}`); return; }
    const csrf = await bsaCsrf();
    try {
        const j = await fetchJson(`/api/admin/badge-style-presets/${encodeURIComponent(name)}?scope=${encodeURIComponent(scope)}`, {
            method: "PUT",
            headers: {
                "Content-Type": "application/json", "X-CSRF-Token": csrf,
                "X-Audit-Reason": auditReason(`Salvataggio del preset di stile dei badge ${name} (ambito ${scope})`),
            },
            body: JSON.stringify(payload),
        });
        if (!j.ok) throw new Error(j.error || j.message || "richiesta non riuscita");
        qs("fm-bsa-editor").value = JSON.stringify(j.style, null, 2);
        notify("Preset stile", "ok", "Salvato (con sanitizzazione)", 2500);
        setStatus("Salvato.");
    } catch (e) {
        setStatus(`Errore: ${  e.message}`);
    }
}

async function bsaDelete() {
    const scope = qs("fm-bsa-scope").value.trim() || "_default";
    const name  = bsaCurrentName;
    if (!name || !confirm(`Eliminare il preset "${name}" dallo scope "${scope}"?`)) return;
    const csrf = await bsaCsrf();
    try {
        const j = await fetchJson(`/api/admin/badge-style-presets/${encodeURIComponent(name)}?scope=${encodeURIComponent(scope)}`, {
            method: "DELETE",
            headers: {
                "X-CSRF-Token": csrf,
                "X-Audit-Reason": auditReason(`Eliminazione del preset di stile dei badge ${name} (ambito ${scope}), confermata`),
            },
        });
        if (!j.ok) throw new Error(j.error || "richiesta non riuscita");
        notify("Preset stile", "ok", "Eliminato", 2500);
        await bsaList();
    } catch (e) { setStatus(`Errore: ${  e.message}`); }
}

function bsaNew() {
    const name = (prompt("Nome del nuovo preset (alphanumerico, _ -, max 64):") || "").trim();
    if (!/^[a-zA-Z0-9_-]{1,64}$/.test(name)) { alert("Nome non valido."); return; }
    bsaCurrentName = name;
    qs("fm-bsa-editor").value = JSON.stringify({
        "$schema": "pantedu.badge_style.v1",
        fonte: { title_size: "\\small", meta_size: "\\tiny", row_sep: "-5pt", col_spec: "|c|" },
        badge: { bg: "gray", txt: "white", ex_size: "\\large", min_width: "1cm", diff_max: 4, diff_size: "\\huge" }
    }, null, 2);
    qs("fm-bsa-save").disabled = false;
    qs("fm-bsa-format").disabled = false;
    setStatus(`nuovo preset "${name}" — clicca Salva per persistere`);
}

function bsaFormat() {
    try {
        const obj = JSON.parse(qs("fm-bsa-editor").value);
        qs("fm-bsa-editor").value = JSON.stringify(obj, null, 2);
    } catch { /* ignore */ }
}

qs("fm-bsa-refresh").addEventListener("click", bsaList);
qs("fm-bsa-scope").addEventListener("change", bsaList);
qs("fm-bsa-preset").addEventListener("change", (e) => { if (e.target.value) bsaLoadPreset(e.target.value); });
qs("fm-bsa-new").addEventListener("click", bsaNew);
qs("fm-bsa-save").addEventListener("click", bsaSave);
qs("fm-bsa-delete").addEventListener("click", bsaDelete);
qs("fm-bsa-format").addEventListener("click", bsaFormat);
// Lazy load: solo quando il tab badge-styles diventa attivo (evita 401 admin per non-admin).
document.querySelectorAll('.fm-admin-tab').forEach(t => t.addEventListener("click", () => {
    if (t.dataset.tab === "badge-styles" && bsaPresets.length === 0) bsaList();
}));
}

// ── blocco 2 ──
{
(function () {
    const qs = (id) => document.getElementById(id);
    const ds = qs("fm-co-dataset"), ind = qs("fm-co-ind"), cls = qs("fm-co-cls"),
          mat = qs("fm-co-mat"), scope = qs("fm-co-scope"), ed = qs("fm-co-editor"),
          status = qs("fm-co-status"), bSave = qs("fm-co-save"), bFmt = qs("fm-co-format"), bDel = qs("fm-co-delete");
    if (!ds) return;
    const csrf = (...a) => window.FM.DomUtils.fetchCsrf(...a); // lazy: window.FM pronto a call-time
    const params = () => ({ dataset: ds.value, indirizzo: ind.value.trim().toUpperCase(), classe: cls.value.trim(), materia: mat.value.trim().toUpperCase() });
    const setStatus = (t) => { status.textContent = t; };
    const enable = (on) => { bSave.disabled = bFmt.disabled = bDel.disabled = !on; };
    const valid = (p) => p.indirizzo && p.classe && p.materia;
    async function load() {
        const p = params();
        if (!valid(p)) { setStatus("⚠ Compila indirizzo/classe/materia"); return; }
        setStatus("Carico…");
        try {
            const j = await window.FM.DomUtils.fetchJson(`/api/risdoc/curriculum-options?${  new URLSearchParams(p)}`);
            const arr = Array.isArray(j) ? j : [];
            ed.value = JSON.stringify(arr, null, 2);
            enable(true);
            setStatus(`✓ Caricato (${  arr.length  } opzioni). Origine: override istituto → globale → file.`);
        } catch (e) { setStatus(`⚠ Errore: ${  e.message}`); }
    }
    async function save() {
        let opts;
        try { opts = JSON.parse(ed.value); } catch { setStatus("⚠ JSON non valido"); return; }
        if (!Array.isArray(opts)) { setStatus("⚠ Atteso un array JSON"); return; }
        const p = params();
        if (!valid(p)) { setStatus("⚠ Compila indirizzo/classe/materia"); return; }
        const body = { ...p, options: opts };
        if (scope.value === "0") body.institute_id = 0;
        setStatus("Salvo…");
        try {
            const j = await window.FM.DomUtils.fetchJson("/api/risdoc/curriculum-options", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": await csrf() },
                body: JSON.stringify(body),
            });
            setStatus(j.ok ? (`✓ Salvato (istituto ${  j.institute_id  }, ${  j.count  } opz)`) : (`⚠ ${  j.error || "richiesta non riuscita"}`));
        } catch (e) { setStatus(`⚠ Errore: ${  e.message}`); }
    }
    async function del() {
        if (!confirm("Eliminare l'override per questa chiave?")) return;
        const p = params();
        const body = { ...p };
        if (scope.value === "0") body.institute_id = 0;
        setStatus("Elimino…");
        try {
            const j = await window.FM.DomUtils.fetchJson("/api/risdoc/curriculum-options/delete", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": await csrf() },
                body: JSON.stringify(body),
            });
            setStatus(j.ok ? "✓ Override eliminato (torna al default)" : "Nessun override da eliminare");
        } catch (e) { setStatus(`⚠ Errore: ${  e.message}`); }
    }
    qs("fm-co-load").addEventListener("click", load);
    bSave.addEventListener("click", save);
    bDel.addEventListener("click", del);
    bFmt.addEventListener("click", () => { try { ed.value = JSON.stringify(JSON.parse(ed.value), null, 2); } catch { /* ignore */ } });
})();
}

// ── blocco 3 ──
{
(function () {
    let mounted = false;
    function mountSc() {
        const el = document.getElementById("fm-sc-admin-editor");
        if (!el || !window.FM?.ShortcutsEditor) return false;
        window.FM.ShortcutsEditor.mount(el, { admin: true });
        mounted = true;
        return true;
    }
    document.querySelectorAll(".fm-admin-tab").forEach((t) => t.addEventListener("click", () => {
        if (t.dataset.tab !== "shortcuts" || mounted) return;
        if (!mountSc()) { let n = 0; const iv = setInterval(() => { if (mountSc() || ++n > 40) clearInterval(iv); }, 100); }
    }));
    document.getElementById("fm-sc-admin-refresh")?.addEventListener("click", () => { mounted = false; mountSc(); });
})();
}

// ── blocco 4 ──
{
// Estrazione PDF: carica l'iframe (preset globale) la PRIMA volta che il tab
// diventa attivo — sia per click sia se la pagina apre già su #pdf-import.
(function () {
    const loadFrame = () => {
        const f = document.querySelector('[data-fm-pdfimport-frame]');
        if (f && !f.src && f.dataset.src) f.src = f.dataset.src;
    };
    document.querySelectorAll('.fm-admin-tab').forEach((t) => t.addEventListener('click', () => {
        if (t.dataset.tab === 'pdf-import') loadFrame();
    }));
    const maybeLoad = () => {
        const panel = document.querySelector('[data-panel="pdf-import"]');
        if (location.hash === '#pdf-import' || (panel && !panel.hidden)) loadFrame();
    };
    maybeLoad();
    window.addEventListener('hashchange', maybeLoad);
})();
}
