/**
 * G24.faseC-crud-dialogs — CRUD dialogs per workspace TikZ del docente.
 *
 * 7 dialog async che chiamano `/tikz/workspace/*` endpoints + lazy-load
 * il bundle `tex-element-editor.js` (CM6 + preview) o
 * `tikz-template-filler.js` al primo use.
 *
 * Funzioni esposte:
 *   openGroupRenameDialog       — rinomina gruppo workspace
 *   openTexElementEditor        — editor CM6 mode new/edit
 *   openTexInsertEditor         — editor CM6 mode insert (➕ Aggiungi / ↩ Reset)
 *   resetWorkspaceFull          — reset all workspace ai default admin
 *   openNewOrImportDialog       — dialog "Nuovo / Importa" con 2 tab
 *   openTexNewElementInWorkspace — editor mode new pre-popolato
 *   openFillerForTemplateRow    — filler per row template DB
 *
 * Dipendenze (DI factory):
 *   - toast
 *   - confirmDialog
 *   - escapeHtml
 *   - apiPost
 *   - texWorkspace (service)
 *   - findFocusedTextarea
 *   - insertIntoQuesito
 *   - extractTemplateData
 */

/** Lazy-load FM dialog via manifest entry. Duplicato in block-dialogs;
 *  candidate per estrazione futura in shared util. */
async function loadFmDialogEntry(entryKey, fmKey) {
    if (window.FM?.[fmKey]) return;
    const cacheBust = `?t=${Date.now()}`;
    const res = await fetch(`/build/manifest.json${cacheBust}`, {
        credentials: "same-origin", cache: "no-store",
    });
    if (!res.ok) throw new Error(`manifest HTTP ${res.status} — npm run build`);
    const manifest = await res.json();
    const entry = manifest[entryKey];
    if (!entry) throw new Error(`entry ${entryKey} assente`);
    await import(/* @vite-ignore */ `/build/${entry.file}`);
    if (!window.FM?.[fmKey]) throw new Error(`bundle non popola FM.${fmKey}`);
}

/** Factory: tutti i 7 dialog bound alle deps. */
export function createCrudDialogs(deps) {
    const {
        toast, confirmDialog, escapeHtml, apiPost,
        texWorkspace, findFocusedTextarea, insertIntoQuesito,
        extractTemplateData,
    } = deps;

    /** Rinomina gruppo. Usa endpoint /tikz/workspace/group/rename. */
    async function openGroupRenameDialog(groupKey, items = null) {
        if (!items) {
            const groups = texWorkspace.getCached() || {};
            items = groups[groupKey] || [];
        }
        if (!items || items.length === 0) {
            try {
                const res = await fetch("/tikz/effective-templates", { credentials: "same-origin", cache: "no-store" });
                if (res.ok) {
                    const data = await res.json();
                    texWorkspace.setCache(data);
                    items = data?.[groupKey] || [];
                }
            } catch (_) {}
        }
        if (!items || items.length === 0) {
            toast("Gruppo vuoto, impossibile rinominare", "warn");
            return false;
        }
        const currentName = groupKey.replace(/^gruppo-/, "");

        return new Promise((resolve) => {
            document.getElementById("fm-group-rename-dialog")?.remove();
            const dlg = document.createElement("div");
            dlg.id = "fm-group-rename-dialog";
            dlg.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:100050;display:flex;align-items:center;justify-content:center;font:13px/1.4 system-ui";
            dlg.innerHTML = `
                <div style="background:#1e1e1e;color:#ddd;border:1px solid #444;border-radius:8px;box-shadow:0 12px 48px rgba(0,0,0,0.6);min-width:420px;max-width:90vw;overflow:hidden">
                    <div style="padding:12px 16px;background:#2a2a2a;border-bottom:1px solid #444;font-weight:600">Rinomina gruppo</div>
                    <div style="padding:18px 16px;display:flex;flex-direction:column;gap:10px">
                        <label style="display:flex;flex-direction:column;gap:4px">
                            <span style="font-size:11px;color:#aaa;text-transform:uppercase;letter-spacing:0.4px">Nome gruppo</span>
                            <input class="fm-grn-input" value="${escapeHtml(currentName)}" autocomplete="off"
                                style="padding:8px 10px;background:#2a2a2a;color:#ddd;border:1px solid #555;border-radius:4px;font:13px Consolas,monospace">
                        </label>
                        <div style="font-size:11px;color:#888">Lo spazio diventa "-" automatico (es: "Studio Funzioni" → gruppo-studio-funzioni).</div>
                    </div>
                    <div style="padding:10px 12px;background:#252525;border-top:1px solid #444;display:flex;gap:8px;justify-content:flex-end">
                        <button data-act="cancel" style="padding:6px 14px;background:#3a3a3a;color:#ddd;border:1px solid #555;border-radius:4px;cursor:pointer">Annulla</button>
                        <button data-act="ok" style="padding:6px 14px;background:#2a5ac7;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600">Salva</button>
                    </div>
                </div>`;
            document.body.appendChild(dlg);

            const input = dlg.querySelector(".fm-grn-input");
            input.focus(); input.select();

            const close = (ok) => {
                const newName = ok ? input.value.trim() : "";
                dlg.remove();
                document.removeEventListener("keydown", esc);
                if (!ok || !newName || newName === currentName) { resolve(false); return; }
                const newKey = newName.startsWith("gruppo-") ? newName
                    : `gruppo-${  newName.toLowerCase().replace(/\s+/g, "-")}`;
                apiPost("/tikz/workspace/group/rename", { oldKey: groupKey, newKey }).then((res) => {
                    if (res?.success === true || res?.ok === true) {
                        toast("Gruppo rinominato", "ok");
                        texWorkspace.invalidate();
                        resolve(true);
                    } else {
                        toast(`Errore rinomina: ${res?.error || "?"}`, "err");
                        resolve(false);
                    }
                }).catch((e) => { toast(`Errore: ${e.message}`, "err"); resolve(false); });
            };
            const esc = (e) => { if (e.key === "Escape") close(false); else if (e.key === "Enter") close(true); };
            document.addEventListener("keydown", esc);
            dlg.addEventListener("click", (e) => {
                const a = e.target?.dataset?.act;
                if (a === "ok") close(true);
                else if (a === "cancel") close(false);
                else if (e.target === dlg) close(false);
            });
        });
    }

    /** Apre l'editor CM6 + preview per creare/modificare un elemento.
     *  mode: "new" → groupKey opzionale o creates new group
     *        "edit" → richiede groupKey + elementLabel
     *  Save endpoints: /tikz/save-new-element o /tikz/edit-element. */
    async function openTexElementEditor({ mode, groupKey = "", elementLabel = "", initialType = "tikz", initialLabel = "", initialCode = "" }) {
        try {
            await loadFmDialogEntry("js/entries/tex-element-editor.js", "openTexElementEditor");
        } catch (e) {
            toast(`Errore caricamento editor: ${e.message}`, "err");
            return false;
        }
        return new Promise((resolve) => {
            window.FM.openTexElementEditor({
                mode, groupKey, initialType, initialLabel, initialCode,
                existingGroups: Object.keys(texWorkspace.getCached() || {}),
                onSave: async ({ type, label, code, groupName, newGroup }) => {
                    let url, payload;
                    if (mode === "new") {
                        payload = {
                            groupName:     newGroup || "",
                            existingGroup: !newGroup ? groupName : "",
                            elementType:   type,
                            label, code,
                        };
                        url = "/tikz/save-new-element";
                    } else {
                        payload = {
                            groupName: groupKey,
                            elementLabel,
                            elementType: type,
                            label, code,
                        };
                        url = "/tikz/edit-element";
                    }
                    const res = await apiPost(url, payload);
                    if (res?.success === true || res?.ok === true) {
                        toast(mode === "new" ? "Elemento creato" : "Elemento aggiornato", "ok");
                        texWorkspace.invalidate();
                        resolve(true);
                        return { ok: true };
                    }
                    const err = res?.error || `HTTP ${res?.status ?? "?"}`;
                    toast(`Errore: ${err}`, "err");
                    return { ok: false, error: err };
                },
                onCancel: () => resolve(false),
            });
        });
    }

    /** Editor CM6 in mode "insert" con toolbar 4-bottoni (➕/💾/🔄/✕).
     *  @param {Function} getPanel  () => panel
     *  @param {{initialType, initialCode, title, groupKey, label, isOverride, refreshMenu}} opts */
    async function openTexInsertEditor(getPanel, { initialType = "tikz", initialCode = "", title = "Codice", groupKey = "", label = "", isOverride = false, refreshMenu = null } = {}) {
        try {
            await loadFmDialogEntry("js/entries/tex-element-editor.js", "openTexElementEditor");
        } catch (e) {
            toast(`Errore caricamento editor: ${e.message}`, "err");
            return false;
        }
        return new Promise((resolve) => {
            window.FM.openTexElementEditor({
                mode: "insert",
                initialType, initialCode, title,
                actions: {
                    isOverride,
                    onAdd: (api) => {
                        const ta = findFocusedTextarea(getPanel);
                        if (!ta) {
                            toast("Apri un quesito in modalità modifica e clicca su un editor prima di aggiungere", "warn");
                            return;
                        }
                        insertIntoQuesito(ta, api.getType(), api.getCode());
                        toast("Aggiunto al quesito", "ok");
                        api.close();
                        resolve(true);
                    },
                    onSavePref: async (api) => {
                        if (!groupKey || !label) { toast("Manca groupKey/label per salvare", "err"); return; }
                        const code = api.getCode();
                        if (!code.trim()) { toast("Codice vuoto", "warn"); return; }
                        const data = extractTemplateData(code);
                        const res = await apiPost("/tikz/workspace/element/save", {
                            groupKey, label, oldLabel: label, type: api.getType(), code,
                            ...(data ? { data } : {}),
                        });
                        if (res?.success === true || res?.ok === true) {
                            toast("Modifica salvata nel tuo menu", "ok");
                            texWorkspace.invalidate();
                            if (typeof refreshMenu === "function") await refreshMenu();
                        } else {
                            toast(`Errore salvataggio: ${res?.error || "?"}`, "err");
                        }
                    },
                    onReset: async (api) => {
                        const ok = await confirmDialog({
                            title: "Ripristina dal default admin",
                            message: `Sostituire "${label}" con la versione attuale del default admin?\nLe tue modifiche andranno perse.`,
                            confirmLabel: "Ripristina",
                            danger: true,
                        });
                        if (!ok) return;
                        const res = await apiPost("/tikz/workspace/import", {
                            sourceGroupKey: groupKey,
                            sourceLabel:    label,
                            targetGroupKey: groupKey,
                            conflict:       "overwrite",
                        });
                        if (res?.success === true || res?.ok === true) {
                            toast("Ripristinato dal default admin", "ok");
                            texWorkspace.invalidate();
                            api.close();
                            if (typeof refreshMenu === "function") await refreshMenu();
                            resolve(true);
                        } else {
                            toast(`Errore reset: ${res?.error || "?"}`, "err");
                        }
                    },
                },
                onCancel: () => resolve(false),
            });
        });
    }

    /** Reset all: sostituisce workspace docente con copia defaults admin. */
    async function resetWorkspaceFull(refreshMenu) {
        const ok = await confirmDialog({
            title: "Reset workspace",
            message: "Sostituire TUTTO il tuo menu con la copia attuale del libro admin?\nGruppi rinominati, elementi modificati, aggiunte personali — TUTTO andrà perso.\nL'operazione è irreversibile.",
            confirmLabel: "Reset workspace",
            cancelLabel: "Annulla",
            danger: true,
        });
        if (!ok) return;
        const res = await apiPost("/tikz/workspace/reset-all", {});
        if (res?.success === true || res?.ok === true) {
            toast("Workspace resettato al default admin", "ok");
            texWorkspace.invalidate();
            if (typeof refreshMenu === "function") await refreshMenu();
        } else {
            toast(`Errore reset: ${res?.error || "?"}`, "err");
        }
    }

    /** Editor mode "new" pre-popolato (groupKey, type tikz, label/code vuoti). */
    async function openTexNewElementInWorkspace({ groupKey = "", refreshMenu = null } = {}) {
        try {
            await loadFmDialogEntry("js/entries/tex-element-editor.js", "openTexElementEditor");
        } catch (e) {
            toast(`Errore caricamento editor: ${e.message}`, "err");
            return false;
        }
        const existingGroups = Object.keys(texWorkspace.getCached() || {});
        return new Promise((resolve) => {
            window.FM.openTexElementEditor({
                mode: "new",
                groupKey,
                existingGroups,
                initialType: "tikz",
                initialLabel: "",
                initialCode: "",
                onSave: async ({ type, label, code, groupName, newGroup }) => {
                    const targetKey = newGroup
                        ? (`gruppo-${  newGroup.toLowerCase().replace(/\s+/g, "-")}`)
                        : (groupName || groupKey);
                    if (!targetKey || !label) {
                        toast("Manca gruppo o nome", "warn");
                        return { ok: false };
                    }
                    const res = await apiPost("/tikz/workspace/element/save", {
                        groupKey: targetKey, label, type, code,
                    });
                    if (res?.success === true || res?.ok === true) {
                        toast("Elemento creato nel tuo menu", "ok");
                        texWorkspace.invalidate();
                        if (typeof refreshMenu === "function") await refreshMenu();
                        resolve(true);
                        return { ok: true };
                    }
                    toast(`Errore: ${res?.error || "?"}`, "err");
                    return { ok: false, error: res?.error };
                },
                onCancel: () => resolve(false),
            });
        });
    }

    /** Filler per row template DB con toolbar 4-bottoni equivalente. */
    async function openFillerForTemplateRow(getPanel, groupKey, item, refreshMenu = null) {
        try {
            await loadFmDialogEntry("js/entries/tikz-template-filler.js", "openTemplateFiller");
        } catch (e) {
            toast(`Errore caricamento filler: ${e.message}`, "err");
            return;
        }
        // initialData: 1) item._data dal server (override docente), 2) marker nel
        // content, 3) null → filler usa defaultData del template.
        let initialData = (item._data && typeof item._data === "object") ? item._data : null;
        if (!initialData) initialData = extractTemplateData(item.content);

        window.FM.openTemplateFiller("schema-modulare", initialData, /*onSave legacy*/ null, {
            title: `Schema modulare — ${item.label || "elemento"}`,
            isOverride: !!item._override,
            groupKey, label: item.label || "",
            onAdd: (tikzString, _data) => {
                const ta = findFocusedTextarea(getPanel);
                if (!ta) {
                    toast("Apri un quesito in modalità modifica e clicca su un editor prima di aggiungere", "warn");
                    return false;
                }
                insertIntoQuesito(ta, "tikz", tikzString);
                toast("Aggiunto al quesito", "ok");
                return true;
            },
            onSavePref: async (tikzString, data) => {
                const res = await apiPost("/tikz/workspace/element/save", {
                    groupKey, label: item.label || "", oldLabel: item.label || "",
                    type: "tikz", code: tikzString, data,
                });
                if (res?.success === true || res?.ok === true) {
                    toast("Modifica salvata nel tuo menu", "ok");
                    texWorkspace.invalidate();
                    if (typeof refreshMenu === "function") await refreshMenu();
                    return true;
                }
                toast(`Errore: ${res?.error || "?"}`, "err");
                return false;
            },
            onReset: async () => {
                const ok = await confirmDialog({
                    title: "Ripristina dal default admin",
                    message: `Sostituire "${item.label}" con la versione attuale del default admin?\nLe tue modifiche andranno perse.`,
                    confirmLabel: "Ripristina", danger: true,
                });
                if (!ok) return false;
                const res = await apiPost("/tikz/workspace/import", {
                    sourceGroupKey: groupKey,
                    sourceLabel:    item.label || "",
                    targetGroupKey: groupKey,
                    conflict:       "overwrite",
                });
                if (res?.success === true || res?.ok === true) {
                    toast("Ripristinato dal default admin", "ok");
                    texWorkspace.invalidate();
                    if (typeof refreshMenu === "function") await refreshMenu();
                    return true;
                }
                toast(`Errore: ${res?.error || "?"}`, "err");
                return false;
            },
        });
    }

    /** Dialog unificato "Nuovo / Importa" con 2 tab (scratch + import multi). */
    async function openNewOrImportDialog(refreshMenu) {
        const FM_TPL_RE = /^%\s*__FM_TPL_DATA__:/m;
        const inferType = (it) => {
            if (it?._data || (typeof it?.content === "string" && FM_TPL_RE.test(it.content))) return "schema";
            return (it?.type || "tikz").toLowerCase();
        };

        const libPromise = fetch("/tikz/admin-library", { credentials: "same-origin", cache: "no-store" })
            .then(r => r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`)))
            .catch(e => { toast(`Libreria non caricata: ${e.message}`, "warn"); return {}; });

        document.getElementById("fm-tikz-newdlg")?.remove();
        const dlg = document.createElement("div");
        dlg.id = "fm-tikz-newdlg";
        dlg.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:100040;display:flex;align-items:center;justify-content:center;font:13px/1.4 system-ui";
        dlg.innerHTML = `
            <div style="background:#1e1e1e;color:#ddd;border:1px solid #444;border-radius:8px;box-shadow:0 12px 48px rgba(0,0,0,0.6);width:760px;max-width:96vw;max-height:88vh;display:flex;flex-direction:column;overflow:hidden">
                <div style="padding:12px 16px;background:#2a2a2a;border-bottom:1px solid #444;font-weight:600;display:flex;align-items:center">
                    <span style="flex:1">➕ Nuovo elemento</span>
                    <button data-act="close" style="padding:4px 10px;background:#3a3a3a;color:#ddd;border:1px solid #555;border-radius:4px;cursor:pointer">✕</button>
                </div>
                <div style="display:flex;border-bottom:1px solid #333;background:#222">
                    <button data-tab="scratch" class="fm-newdlg-tab" style="flex:1;padding:10px 14px;background:transparent;border:none;border-bottom:2px solid #4a8eff;cursor:pointer;font-size:13px;color:#fff;font-weight:600">📝 Da zero</button>
                    <button data-tab="import" class="fm-newdlg-tab" style="flex:1;padding:10px 14px;background:transparent;border:none;border-bottom:2px solid transparent;cursor:pointer;font-size:13px;color:#999">📚 Importa da libreria admin</button>
                </div>
                <div data-body="scratch" style="flex:1;padding:18px 16px;overflow-y:auto;display:flex;flex-direction:column;align-items:flex-start;gap:12px;min-height:240px">
                    <p style="margin:0;color:#aaa;font-size:13px;line-height:1.5">
                        Crea un elemento vuoto in un gruppo nuovo o esistente.<br>
                        L'editor CodeMirror si apre con i campi <strong style="color:#ddd">Tipo</strong>, <strong style="color:#ddd">Gruppo</strong>, <strong style="color:#ddd">Nome</strong>, <strong style="color:#ddd">Codice</strong>.
                    </p>
                    <button data-act="open-scratch" style="padding:8px 18px;background:#2a5ac7;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600">Apri editor →</button>
                </div>
                <div data-body="import" style="flex:1;padding:14px 16px;overflow-y:auto;display:none;flex-direction:column;min-height:300px">
                    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
                        <input type="search" data-role="search" placeholder="Cerca per nome…" style="flex:1;min-width:200px;padding:6px 10px;background:#0f0f0f;border:1px solid #444;border-radius:4px;color:#ddd;font-size:13px">
                        <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:#aaa;cursor:pointer"><input type="checkbox" data-filter="tikz" checked> ✒️ tikz</label>
                        <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:#aaa;cursor:pointer"><input type="checkbox" data-filter="schema" checked> 📋 schema</label>
                        <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:#aaa;cursor:pointer"><input type="checkbox" data-filter="latex" checked> ✏️ latex</label>
                    </div>
                    <div data-role="list" style="flex:1;overflow-y:auto;border:1px solid #333;border-radius:4px;background:#161616;min-height:200px">
                        <p style="text-align:center;padding:20px;color:#888;font-style:italic">Caricamento libreria…</p>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;margin-top:10px;padding-top:10px;border-top:1px solid #333">
                        <span data-role="count" style="flex:1;font-size:12px;color:#888">0 selezionati</span>
                        <button data-act="select-all" style="padding:5px 10px;background:#2a2a2a;color:#ddd;border:1px solid #555;border-radius:4px;cursor:pointer;font-size:12px">Tutti</button>
                        <button data-act="select-none" style="padding:5px 10px;background:#2a2a2a;color:#ddd;border:1px solid #555;border-radius:4px;cursor:pointer;font-size:12px">Nessuno</button>
                        <button data-act="import" disabled style="padding:6px 14px;background:#2a5ac7;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;opacity:0.5">📥 Importa selezionati</button>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(dlg);

        // G26 — vars locali rinominate da $/$$ (riservati jQuery) a qs/qsa.
        const qs = (sel) => dlg.querySelector(sel);
        const qsa = (sel) => Array.from(dlg.querySelectorAll(sel));
        const close = () => { dlg.remove(); document.removeEventListener("keydown", esc); };
        const esc = (e) => { if (e.key === "Escape") close(); };
        document.addEventListener("keydown", esc);
        dlg.addEventListener("click", (e) => {
            if (e.target?.dataset?.act === "close" || e.target === dlg) close();
        });

        // Tab switching
        qsa(".fm-newdlg-tab").forEach(t => t.addEventListener("click", () => {
            const k = t.dataset.tab;
            qsa(".fm-newdlg-tab").forEach(x => {
                const active = (x === t);
                x.style.color = active ? "#fff" : "#999";
                x.style.fontWeight = active ? "600" : "normal";
                x.style.borderBottomColor = active ? "#4a8eff" : "transparent";
            });
            qsa("[data-body]").forEach(b => {
                b.style.display = b.dataset.body === k ? "flex" : "none";
            });
        }));

        // "Da zero"
        qs('[data-act="open-scratch"]').addEventListener("click", () => {
            close();
            openTexNewElementInWorkspace({ groupKey: "", refreshMenu });
        });

        // "Importa": render lazy al primo click sul tab
        let library = null;
        const renderLibrary = async () => {
            if (library !== null) return;
            library = await libPromise;
            const listEl = qs('[data-role="list"]');
            listEl.innerHTML = "";
            if (!library || typeof library !== "object" || Object.keys(library).length === 0) {
                listEl.innerHTML = '<p style="text-align:center;padding:20px;color:#888;font-style:italic">Libreria admin vuota.</p>';
                return;
            }
            for (const [gKey, items] of Object.entries(library)) {
                if (!Array.isArray(items)) continue;
                const gName = gKey.replace(/^gruppo-/, "");
                const gWrap = document.createElement("div");
                gWrap.style.cssText = "border-bottom:1px solid #2a2a2a";
                const ghdr = document.createElement("div");
                ghdr.style.cssText = "padding:6px 12px;background:#1f1f1f;font-weight:600;font-size:13px;color:#ddd";
                ghdr.innerHTML = `📂 ${escapeHtml(gName)} <span style="color:#888;font-weight:normal">(${items.length})</span>`;
                gWrap.appendChild(ghdr);
                items.forEach(it => {
                    const t = inferType(it);
                    const row = document.createElement("label");
                    row.style.cssText = "display:flex;align-items:center;gap:8px;padding:6px 12px 6px 24px;border-bottom:1px solid rgba(255,255,255,0.04);cursor:pointer";
                    row.dataset.gkey = gKey;
                    row.dataset.label = it.label || "";
                    row.dataset.type = t;
                    const typeColor = t === "tikz" ? "#1e3a1e" : (t === "schema" ? "#3a2a1e" : "#1e1b4b");
                    const typeText  = t === "tikz" ? "#a5d6a7" : (t === "schema" ? "#fcd34d" : "#a5b4fc");
                    row.innerHTML = `
                        <input type="checkbox" data-role="cb">
                        <span style="font-size:9px;padding:2px 5px;background:${typeColor};color:${typeText};border-radius:3px;font-weight:600">${t.toUpperCase()}</span>
                        <span style="flex:1;color:#ccc;font-size:12px">${escapeHtml(it.label || "(senza nome)")}</span>
                    `;
                    gWrap.appendChild(row);
                });
                listEl.appendChild(gWrap);
            }
            listEl.addEventListener("change", updateCount);
            updateCount();
        };

        function visibleRows() {
            const q = (qs('[data-role="search"]').value || "").trim().toLowerCase();
            const allowTypes = qsa('[data-filter]:checked').map(c => c.dataset.filter);
            return qsa("label[data-gkey]").filter(r => {
                if (!allowTypes.includes(r.dataset.type)) { r.style.display = "none"; return false; }
                if (q && !r.dataset.label.toLowerCase().includes(q)) { r.style.display = "none"; return false; }
                r.style.display = "flex";
                return true;
            });
        }
        function updateCount() {
            visibleRows();
            const checked = qsa('[data-role="cb"]:checked').filter(c => c.closest("label[data-gkey]").style.display !== "none");
            qs('[data-role="count"]').textContent = `${checked.length} selezionati`;
            const btn = qs('[data-act="import"]');
            btn.disabled = checked.length === 0;
            btn.style.opacity = checked.length === 0 ? "0.5" : "1";
        }
        qs('[data-role="search"]').addEventListener("input", updateCount);
        qsa('[data-filter]').forEach(c => c.addEventListener("change", updateCount));
        qs('[data-act="select-all"]').addEventListener("click", () => {
            visibleRows().forEach(r => r.querySelector('[data-role="cb"]').checked = true);
            updateCount();
        });
        qs('[data-act="select-none"]').addEventListener("click", () => {
            qsa('[data-role="cb"]').forEach(c => c.checked = false);
            updateCount();
        });

        qsa(".fm-newdlg-tab")[1].addEventListener("click", renderLibrary, { once: true });

        // Import multi
        qs('[data-act="import"]').addEventListener("click", async () => {
            const rows = qsa('[data-role="cb"]:checked').map(c => c.closest("label[data-gkey]"))
                .filter(r => r.style.display !== "none");
            if (rows.length === 0) return;
            const btn = qs('[data-act="import"]');
            btn.disabled = true;
            const orig = btn.textContent;
            let ok = 0, fail = 0;
            const conflicts = [];
            for (const r of rows) {
                btn.textContent = `Importando ${ok + fail + 1}/${rows.length}…`;
                const res = await apiPost("/tikz/workspace/import", {
                    sourceGroupKey: r.dataset.gkey,
                    sourceLabel: r.dataset.label,
                    targetGroupKey: r.dataset.gkey,
                    conflict: "abort",
                });
                if (res?.action === "created" || res?.success === true || res?.ok === true) ok++;
                else if (res?.action === "aborted") conflicts.push({ gkey: r.dataset.gkey, label: r.dataset.label });
                else fail++;
            }
            btn.textContent = orig;
            if (conflicts.length > 0) {
                const ow = await window.FM.Dialog.confirm(`${conflicts.length} elemento/i hanno conflitti di nome.\n\nOK = sovrascrivere tutti\nAnnulla = saltare tutti`);
                if (ow) {
                    for (const c of conflicts) {
                        const res = await apiPost("/tikz/workspace/import", {
                            sourceGroupKey: c.gkey, sourceLabel: c.label,
                            targetGroupKey: c.gkey, conflict: "overwrite",
                        });
                        if (res?.success === true || res?.ok === true || res?.action === "created" || res?.action === "overwritten") ok++;
                        else fail++;
                    }
                }
            }
            texWorkspace.invalidate();
            if (typeof refreshMenu === "function") await refreshMenu();
            close();
            toast(fail === 0 ? `${ok} elementi importati` : `${ok} OK, ${fail} falliti`, fail === 0 ? "ok" : "warn");
        });
    }

    return {
        openGroupRenameDialog,
        openTexElementEditor,
        openTexInsertEditor,
        resetWorkspaceFull,
        openTexNewElementInWorkspace,
        openFillerForTemplateRow,
        openNewOrImportDialog,
    };
}
