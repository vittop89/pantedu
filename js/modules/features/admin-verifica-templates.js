/**
 * G20.0 Phase 9 — Admin templates verifiche file-tree editor.
 *
 * UI:
 *   - Scope dropdown: _default | {institute_code}
 *   - File tree sidebar: texCommon/, versioni/, griglie/
 *   - Editor textarea: read cascade (override > default), write override
 *
 * API consumed:
 *   GET    /api/admin/verifica/scopes
 *   GET    /api/admin/verifica/files?scope=
 *   GET    /api/admin/verifica/files/read?scope=&path=
 *   POST   /api/admin/verifica/files/write  (json {scope,path,content})
 *   POST   /api/admin/verifica/files/copy-from-default  (json {scope,path})
 *   POST   /api/admin/verifica/files/delete  (json {scope,path})
 */
(function () {
    "use strict";

    let currentScope = "_default";
    let currentPath = null;
    let currentContent = "";   // contenuto effettivo nel textarea
    let isOverride = false;
    let dirty = false;

    const byId = (id) => document.getElementById(id);

    // CSRF canonico centralizzato (cache 60s). Lazy: window.FM è pronto quando
    // getCsrf viene chiamato dentro gli handler (non al load dello script).
    const getCsrf = (...a) => window.FM.DomUtils.fetchCsrf(...a);

    function setFeedback(msg, kind = "info") {
        const fb = byId("fm-vfiles-feedback");
        if (!fb) return;
        fb.textContent = msg;
        fb.style.color = kind === "error" ? "#b91c1c"
                       : kind === "ok"    ? "#15803d" : "";
    }

    function setStatus(msg) {
        const el = byId("fm-vfiles-status");
        if (el) el.textContent = msg;
    }

    async function loadScopes() {
        const r = await fetch("/api/admin/verifica/scopes", { credentials: "same-origin" });
        const j = await r.json();
        const sel = byId("fm-vfiles-scope");
        if (!j.ok) {
            sel.innerHTML = `<option value="_default">_default</option>`;
            return;
        }
        sel.innerHTML = (j.scopes || []).map(s =>
            `<option value="${escapeAttr(s.code)}">${escapeHtml(s.label)}</option>`
        ).join("");
        sel.value = currentScope;
    }

    async function loadTree() {
        const tree = byId("fm-vfiles-tree");
        tree.innerHTML = '<p class="fm-muted">Caricamento file…</p>';
        const r = await fetch(`/api/admin/verifica/files?scope=${encodeURIComponent(currentScope)}`,
                              { credentials: "same-origin" });
        const j = await r.json();
        if (!j.ok) {
            tree.innerHTML = `<p style="color:#b91c1c;">Errore: ${j.error || r.status}</p>`;
            return;
        }
        // Group by top folder (texCommon/versioni/griglie)
        const groups = { texCommon: [], versioni: [], griglie: [] };
        for (const f of j.files) {
            const top = f.path.split("/")[0];
            if (groups[top]) groups[top].push(f);
        }
        const ICON = (name) => name.endsWith(".sty") ? "📜"
                              : name.endsWith(".tex") ? "📄" : "📋";
        const isDefaultScope = currentScope === "_default";
        const renderItems = (items) => items.map(f => {
            const lastSeg = f.path.split("/").slice(-1)[0];
            const indent = (f.path.match(/\//g) || []).length - 1;
            const padding = "  ".repeat(indent);
            // Badge variant-aware: in _default mostra solo se file presente;
            // in scope istituto distingue "personalizzato" vs "comune".
            let badge = "";
            let badgeTitle = "";
            if (isDefaultScope) {
                badge = f.is_override ? "✓" : "·";
                badgeTitle = f.is_override ? "modello comune presente" : "file mancante";
            } else {
                badge = f.is_override ? "🟢" : (f.has_default ? "·" : "❌");
                badgeTitle = f.is_override
                    ? "personalizzato per questo istituto"
                    : (f.has_default ? "usa il modello comune" : "mancante anche nel modello comune");
            }
            return `<div class="fm-vfile" data-path="${escapeAttr(f.path)}" title="${escapeAttr(f.path)}"
                         style="padding:4px 6px;cursor:pointer;border-radius:3px;display:flex;justify-content:space-between;align-items:center;">
                <span>${padding}${ICON(lastSeg)} ${escapeHtml(lastSeg)}</span>
                <span style="font-size:11px;" title="${escapeAttr(badgeTitle)}">${badge}</span>
            </div>`;
        }).join("");
        const legenda = isDefaultScope
            ? `<strong>Legenda:</strong> ✓ presente · · da creare`
            : `<strong>Legenda:</strong> 🟢 personalizzato per questo istituto · · usa il modello comune`;
        tree.innerHTML = `
            <div style="margin-bottom:12px;font-size:11px;color:#666;">
                ${legenda}
            </div>
            <h4 style="margin:8px 0 4px;">📁 Elementi comuni</h4>
            ${renderItems(groups.texCommon)}
            <h4 style="margin:12px 0 4px;">📁 Modelli verifica</h4>
            ${renderItems(groups.versioni)}
            <h4 style="margin:12px 0 4px;">📁 Griglie di valutazione</h4>
            ${renderItems(groups.griglie)}
        `;
        tree.querySelectorAll(".fm-vfile").forEach(el => {
            el.addEventListener("click", () => selectFile(el.dataset.path));
            el.addEventListener("mouseenter", () => el.style.background = "#eff6ff");
            el.addEventListener("mouseleave", () => el.style.background = "");
        });
    }

    async function selectFile(path) {
        if (dirty && !await window.FM.Dialog.confirm("Hai modifiche non salvate in questo file. Vuoi proseguire e perderle?")) return;
        currentPath = path;
        byId("fm-vfiles-current-path").textContent = path;
        const r = await fetch(`/api/admin/verifica/files/read?scope=${encodeURIComponent(currentScope)}&path=${encodeURIComponent(path)}`,
                              { credentials: "same-origin" });
        const j = await r.json();
        if (!j.ok) {
            setFeedback(`Errore lettura: ${j.error || r.status}`, "error");
            return;
        }
        currentContent = j.content || "";
        isOverride = !!j.is_override;
        byId("fm-vfiles-textarea").value = currentContent;
        const isDefault = currentScope === "_default";
        if (isDefault) {
            byId("fm-vfiles-current-status").innerHTML = isOverride
                ? '<strong style="color:#15803d;">📂 Modifichi il modello comune (vale per tutti gli istituti)</strong>'
                : '<strong style="color:#666;">⚠ File ancora non presente — salvalo per crearlo</strong>';
        } else {
            byId("fm-vfiles-current-status").innerHTML = isOverride
                ? '<strong style="color:#15803d;">🟢 Versione personalizzata per questo istituto</strong>'
                : '<strong style="color:#666;">📋 Stai vedendo il modello comune. Salva per personalizzarlo solo per questo istituto.</strong>';
        }
        dirty = false;
        byId("fm-vfiles-save").disabled = false;
        byId("fm-vfiles-copy-from-default").disabled = isDefault || !j.has_default;
        byId("fm-vfiles-delete").disabled = isDefault || !isOverride;
        setFeedback(`File caricato (${currentContent.length} caratteri)`, "ok");
    }

    async function saveCurrent() {
        if (!currentPath) return;
        const content = byId("fm-vfiles-textarea").value;
        const csrf = await getCsrf();
        const r = await fetch("/api/admin/verifica/files/write", {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({ scope: currentScope, path: currentPath, content, _csrf: csrf }),
        });
        const j = await r.json();
        if (!r.ok || !j.ok) {
            setFeedback(`Errore nel salvataggio: ${j.error || r.status}`, "error");
            return;
        }
        setFeedback(`✓ Salvato (${j.bytes} caratteri)`, "ok");
        dirty = false;
        await loadTree();
        await selectFile(currentPath);
    }

    async function deleteCurrent() {
        if (!currentPath) return;
        if (!await window.FM.Dialog.confirm(`Cancellare la personalizzazione di "${currentPath}" per questo istituto?\nIl file tornera' a usare il modello comune.`)) return;
        const csrf = await getCsrf();
        const r = await fetch("/api/admin/verifica/files/delete", {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({ scope: currentScope, path: currentPath, _csrf: csrf }),
        });
        const j = await r.json();
        if (!r.ok || !j.ok) {
            setFeedback(`Errore: ${j.error || r.status}`, "error");
            return;
        }
        setFeedback(`✓ Tornato al modello comune`, "ok");
        await loadTree();
        await selectFile(currentPath);
    }

    async function copyFromDefault() {
        if (!currentPath) return;
        const csrf = await getCsrf();
        const r = await fetch("/api/admin/verifica/files/copy-from-default", {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({ scope: currentScope, path: currentPath, _csrf: csrf }),
        });
        const j = await r.json();
        if (!r.ok || !j.ok) {
            setFeedback(`Errore: ${j.error || r.status}`, "error");
            return;
        }
        setFeedback(`✓ Copia del modello comune pronta da modificare`, "ok");
        await loadTree();
        await selectFile(currentPath);
    }

    // G22.S15.bis Fase 5+ — usa helper canonici da core/dom-utils.
    const escapeHtml = window.FM?.DomUtils?.escHtml
        || ((s) => String(s ?? "").replace(/[&<>"']/g, (c) =>
            ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c])));
    const escapeAttr = window.FM?.DomUtils?.escAttr || escapeHtml;

    function activateTab(name) {
        document.querySelectorAll(".fm-admin-tab").forEach(t => {
            const active = t.dataset.tab === name;
            t.classList.toggle("fm-admin-tab--active", active);
            t.setAttribute("aria-selected", active ? "true" : "false");
        });
        document.querySelectorAll(".fm-admin-tabpanel").forEach(p => {
            const active = p.dataset.panel === name;
            p.classList.toggle("fm-admin-tabpanel--active", active);
            p.hidden = !active;
        });
        try { history.replaceState(null, "", "#" + name); } catch (_) {}
    }
    document.querySelectorAll(".fm-admin-tab").forEach(tab => {
        tab.addEventListener("click", () => activateTab(tab.dataset.tab));
    });
    const initial = (location.hash || "").replace("#", "");
    if (initial && document.querySelector(`.fm-admin-tab[data-tab="${initial}"]`)) {
        activateTab(initial);
    }

    async function bootstrap() {
        if (!byId("fm-vfiles-scope")) return; // verifiche tab non presente
        await loadScopes();
        byId("fm-vfiles-scope").addEventListener("change", async (e) => {
            currentScope = e.target.value;
            currentPath = null;
            byId("fm-vfiles-textarea").value = "";
            byId("fm-vfiles-current-path").textContent = "Seleziona un file dalla sidebar";
            byId("fm-vfiles-current-status").textContent = "";
            await loadTree();
        });
        byId("fm-vfiles-textarea").addEventListener("input", () => { dirty = true; });
        byId("fm-vfiles-save").addEventListener("click", saveCurrent);
        byId("fm-vfiles-copy-from-default").addEventListener("click", copyFromDefault);
        byId("fm-vfiles-delete").addEventListener("click", deleteCurrent);
        await loadTree();
        setStatus("");
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", bootstrap, { once: true });
    } else {
        bootstrap();
    }
})();
