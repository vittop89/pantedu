/**
 * G24.faseC-dropdown-view — UI building per il dropdown "TeX ▾" della
 * toolbar globale: header snippet comuni + sezioni TikZ + workspace
 * groups dinamici + actions wsActions.
 *
 * 4 funzioni interconnesse:
 *   buildTexDropdownGlobal — entry point: <button> + <menu> + bind events
 *   loadTexGroupsGlobal    — fetch via texWorkspace + render gruppi
 *   renderTexGroup         — header gruppo + items list (chevron expand)
 *   renderTexItem          — row item con 📋/🔍 insert + 🗑️ delete
 *
 * Lazy menu: groups caricati al primo click del button (`btn.click()`).
 * Position: `position:fixed` per bypass stacking context (.fm-groupcollex
 * con transform creano nuovi stacking → menu z-index confinato sotto).
 *
 * Dipendenze (DI factory):
 *   - texWorkspace          (service: invalidate, getCached, etc.)
 *   - makeSectionLabel      (UI label separator)
 *   - escapeHtml            (HTML escape for user-controlled names)
 *   - apiPost               (POST /tikz/workspace/group|element/delete)
 *   - confirmDialog         (popup conferma delete)
 *   - toast                 (UI feedback)
 *   - inlineSnippets        ([label, fn] array: snippet inline LaTeX)
 *   - tikzActions           ({ insertCode, openManager, openTemplateFiller })
 *   - getCrud               (async () → CRUD dialogs API; lazy-loaded):
 *                            { openNewOrImportDialog, resetWorkspaceFull,
 *                              openTexNewElementInWorkspace, openGroupRenameDialog,
 *                              openTexInsertEditor, openFillerForTemplateRow }
 */

export function createDropdownView(deps) {
    const {
        texWorkspace, makeSectionLabel, escapeHtml, apiPost,
        confirmDialog, toast, inlineSnippets, tikzActions, getCrud,
    } = deps;

    let menuSeq = 0;   // id univoci per il pattern disclosure (aria-controls)

    function buildTexDropdownGlobal(getPanel) {
        const wrap = document.createElement("div");
        wrap.className = "fm-tex-dropdown";
        wrap.style.cssText = "position:relative;display:inline-block;z-index:99950";
        const btn = document.createElement("button");
        btn.type = "button"; btn.textContent = "TeX ▾";
        btn.style.cssText = "padding:4px 10px;background:#3a3a3a;color:#ddd;border:1px solid #555;border-radius:3px;cursor:pointer;font:12px/1.2 system-ui";
        const menu = document.createElement("div");
        menu.className = "fm-tex-menu";
        menu.style.cssText = "display:none;position:absolute;top:100%;left:0;background:#1e1e1e;color:#ddd;border:1px solid #444;border-radius:5px;box-shadow:0 6px 16px rgba(0,0,0,0.6);z-index:99950;min-width:300px;max-height:520px;overflow-y:auto;padding:6px 0;font:12px/1.3 system-ui";

        // A11y (WCAG 2.1.1 + 4.1.2) — pattern "disclosure": il bottone annuncia
        // lo stato aperto/chiuso e controlla il menu; il menu è raggiungibile da
        // tastiera (tutti i controlli interni sono <button>). Il menu è montato
        // su document.body (vedi sotto, per il bypass dello stacking context):
        // sta quindi FUORI dall'ordine di tab naturale → gestiamo il focus a mano.
        const menuId = "fm-tex-menu-" + (++menuSeq);
        menu.id = menuId;
        menu.setAttribute("role", "group");
        menu.setAttribute("aria-label", "Strumenti TeX");
        btn.setAttribute("aria-haspopup", "true");
        btn.setAttribute("aria-expanded", "false");
        btn.setAttribute("aria-controls", menuId);
        const setOpen = (open) => {
            menu.style.display = open ? "block" : "none";
            btn.setAttribute("aria-expanded", open ? "true" : "false");
        };
        const closeMenu = () => setOpen(false);
        // Esposto sull'elemento così i controlli interni (renderTexGroup/
        // renderTexItem, in altro scope) chiudono mantenendo aria-expanded in sync.
        menu.__fmClose = closeMenu;
        menu.addEventListener("keydown", (e) => {
            if (e.key === "Escape") { e.preventDefault(); closeMenu(); btn.focus(); }
        });
        // Tab-out del menu (focus esce e non torna al bottone) → chiudi.
        menu.addEventListener("focusout", (e) => {
            if (!menu.contains(e.relatedTarget) && e.relatedTarget !== btn) closeMenu();
        });

        menu.appendChild(makeSectionLabel("Snippet comuni"));
        const chips = document.createElement("div");
        chips.style.cssText = "display:flex;flex-wrap:wrap;gap:4px;padding:4px 10px";
        inlineSnippets.forEach(([label, fn]) => {
            const c = document.createElement("button");
            c.type = "button"; c.textContent = label;
            c.style.cssText = "padding:3px 8px;background:#2a3a5a;border:1px solid #4a6a9a;border-radius:3px;cursor:pointer;font:11px/1.2 Consolas,monospace;color:#9bc1ff";
            c.addEventListener("click", (e) => { e.preventDefault(); fn(getPanel()); closeMenu(); });
            chips.appendChild(c);
        });
        menu.appendChild(chips);

        // === Sezione TikZ (codice grezzo / template / manager) ===
        menu.appendChild(makeSectionLabel("Diagramma TikZ"));
        const tikzRowG = document.createElement("div");
        tikzRowG.style.cssText = "display:flex;flex-wrap:wrap;gap:4px;padding:4px 10px";
        [
            ["🔍 TikZ codice",   "Apri editor codice grezzo (CM6 + folding)",       "code"],
            ["📋 TikZ modulare", "Genera TikZ via form (schema studio del segno)",  "schema-modulare"],
            ["⚙️ Gestisci",       "Modal avanzato: sidebar blocchi + edit per-block", "manage"],
        ].forEach(([label, title, kind]) => {
            const b = document.createElement("button");
            b.type = "button"; b.textContent = label; b.title = title;
            b.style.cssText = "padding:4px 10px;background:#2a4a2a;border:1px solid #66bb6a;border-radius:3px;cursor:pointer;font:11px/1.2 system-ui;color:#a5d6a7";
            b.addEventListener("click", (e) => {
                e.preventDefault(); closeMenu();
                const panel = getPanel();
                const ta = panel?._focusedTextarea || panel?.querySelector?.(".fm-editor-field");
                if (!ta) return;
                if (kind === "code")              tikzActions.insertCode(ta);
                else if (kind === "schema-modulare") tikzActions.openTemplateFiller(ta, "schema-modulare", null);
                else if (kind === "manage")       tikzActions.openManager(ta);
            });
            tikzRowG.appendChild(b);
        });
        menu.appendChild(tikzRowG);

        menu.appendChild(makeSectionLabel("Il mio menu"));
        const groups = document.createElement("div");
        groups.className = "fm-tex-groups";
        menu.appendChild(groups);
        const loading = document.createElement("div");
        loading.style.cssText = "padding:6px 12px;color:#999;font-style:italic";
        loading.textContent = "Caricamento workspace...";
        groups.appendChild(loading);

        // Workspace actions
        menu.appendChild(makeSectionLabel("Azioni workspace"));
        const wsActions = document.createElement("div");
        wsActions.style.cssText = "display:flex;flex-wrap:wrap;gap:4px;padding:4px 10px";
        const refreshAfter = async () => {
            texWorkspace.invalidate();
            await loadTexGroupsGlobal(groups, getPanel, menu);
        };
        [
            ["➕ Nuovo / Importa", "Crea da zero o importa multi-select dalla libreria admin", "#1e3a1e", async () => (await getCrud()).openNewOrImportDialog(refreshAfter)],
            ["🔄 Reset workspace", "Sostituisci TUTTO il tuo menu con la copia attuale del libro admin (perdita modifiche)", "#3a1e1e", async () => (await getCrud()).resetWorkspaceFull(refreshAfter)],
        ].forEach(([label, title, bg, fn]) => {
            const b = document.createElement("button");
            b.type = "button"; b.textContent = label; b.title = title;
            b.style.cssText = `padding:4px 10px;background:${bg};border:1px solid #555;border-radius:3px;cursor:pointer;font:11px/1.2 system-ui;color:#ddd`;
            b.addEventListener("click", (e) => { e.preventDefault(); closeMenu(); fn(); });
            wsActions.appendChild(b);
        });
        menu.appendChild(wsActions);

        let loaded = false;
        // Porting del menu a document.body con position:fixed per bypass
        // stacking context dei .fm-groupcollex.fm-problem-editing (sticky/transform).
        menu.style.position = "fixed";
        document.body.appendChild(menu);
        function positionMenu() {
            const rect = btn.getBoundingClientRect();
            menu.style.top  = `${rect.bottom + 2  }px`;
            menu.style.left = `${rect.left  }px`;
        }
        btn.addEventListener("click", async (e) => {
            e.preventDefault();
            if (menu.style.display === "none") {
                positionMenu();
                setOpen(true);
                if (!loaded) { loaded = true; await loadTexGroupsGlobal(groups, getPanel, menu); }
                // Sposta il focus nel menu (è in body, fuori dal tab order).
                const first = menu.querySelector("button");
                if (first) first.focus();
            } else closeMenu();
        });
        window.addEventListener("scroll", () => { if (menu.style.display !== "none") positionMenu(); }, true);
        window.addEventListener("resize", () => { if (menu.style.display !== "none") positionMenu(); });
        document.addEventListener("click", (e) => {
            if (!wrap.contains(e.target) && !menu.contains(e.target)) closeMenu();
        });
        wrap.appendChild(btn);  // menu NON appended a wrap (sta in body)
        return wrap;
    }

    async function loadTexGroupsGlobal(container, getPanel, menu) {
        container.innerHTML = "";
        let data;
        try {
            data = await texWorkspace.load();
        } catch (e) {
            const err = document.createElement("div");
            err.style.cssText = "padding:6px 12px;color:#c02a2a;font-style:italic";
            err.textContent = `Errore: ${e.message}`;
            container.appendChild(err);
            return;
        }
        if (!data || typeof data !== "object") return;
        const refreshMenu = async () => {
            await texWorkspace.refresh();
            await loadTexGroupsGlobal(container, getPanel, menu);
        };
        Object.entries(data).forEach(([groupKey, items]) => {
            if (!Array.isArray(items) || !items.length) return;
            const group = renderTexGroup(groupKey, items, getPanel, menu, container, refreshMenu);
            container.appendChild(group);
        });
    }

    function renderTexGroup(groupKey, items, getPanel, menu, container, refreshMenu) {
        const name = groupKey.replace(/^gruppo-/, "");
        const group = document.createElement("div");
        group.className = "fm-tex-group"; group.dataset.group = groupKey;
        group.style.cssText = "border-top:1px solid #333";

        const hdr = document.createElement("div");
        hdr.style.cssText = "display:flex;align-items:center;gap:4px;padding:4px 8px 4px 12px;background:transparent";

        const hdrBtn = document.createElement("button");
        hdrBtn.type = "button";
        hdrBtn.style.cssText = "flex:1;text-align:left;border:none;background:transparent;cursor:pointer;font:600 12px/1.3 system-ui;color:#ddd;padding:4px 0";
        hdrBtn.innerHTML = `<span class="fm-chevron">▶</span> ${escapeHtml(name)} <span style="color:#999;font-weight:normal">(${items.length})</span>`;

        const list = document.createElement("div");
        list.style.cssText = "display:none;background:#222";

        hdrBtn.addEventListener("click", (e) => {
            e.preventDefault();
            const chev = hdrBtn.querySelector(".fm-chevron");
            if (list.style.display === "none") { list.style.display = "block"; chev.textContent = "▼"; }
            else { list.style.display = "none"; chev.textContent = "▶"; }
        });

        const reloadGroups = () => {
            if (menu && menu.__fmClose) menu.__fmClose();
        };

        const mkActionBtn = (icon, title, color, handler) => {
            const b = document.createElement("button");
            b.type = "button"; b.textContent = icon; b.title = title;
            b.style.cssText = `padding:2px 6px;background:${color};border:1px solid #555;border-radius:3px;cursor:pointer;font:13px/1 system-ui;color:#ddd`;
            b.addEventListener("click", (e) => { e.preventDefault(); e.stopPropagation(); handler(); });
            return b;
        };

        hdr.appendChild(hdrBtn);
        hdr.appendChild(mkActionBtn("➕", `Aggiungi nuovo elemento al gruppo "${name}"`, "#1e3a1e", async () => {
            const crud = await getCrud();
            await crud.openTexNewElementInWorkspace({ groupKey, refreshMenu });
        }));
        hdr.appendChild(mkActionBtn("✏️", `Rinomina gruppo "${name}"`, "#2a3a5a", async () => {
            const crud = await getCrud();
            await crud.openGroupRenameDialog(groupKey, items);
            reloadGroups();
        }));
        hdr.appendChild(mkActionBtn("🗑️", `Elimina intero gruppo "${name}" dal tuo menu`, "#3a1e1e", async () => {
            const ok = await confirmDialog({
                title: "Elimina gruppo",
                message: `Eliminare l'intero gruppo "${name}" con i suoi ${items.length} elementi DAL TUO MENU?\nI defaults admin restano intatti, puoi reimportare in seguito.`,
                confirmLabel: "Elimina gruppo",
                danger: true,
            });
            if (!ok) return;
            const res = await apiPost("/tikz/workspace/group/delete", { groupKey });
            if (res?.success === true || res?.ok === true) {
                toast("Gruppo eliminato dal tuo menu", "ok");
                texWorkspace.invalidate();
                group.remove();
                if (typeof refreshMenu === "function") await refreshMenu();
            } else {
                toast(`Errore: ${res?.error || "?"}`, "err");
            }
        }));

        items.forEach((it, idx) => {
            const row = renderTexItem(groupKey, it, idx, getPanel, menu, group, refreshMenu);
            list.appendChild(row);
        });

        group.appendChild(hdr);
        group.appendChild(list);
        return group;
    }

    function renderTexItem(groupKey, it, idx, getPanel, menu, groupEl, refreshMenu) {
        const tplKnown = /\\schemaModulare\b|\\schemaModulareCore\b/.test(it.content || "");

        const row = document.createElement("div");
        row.style.cssText = "display:flex;align-items:center;gap:2px;padding:0 6px 0 16px;background:#222";

        const lbl = document.createElement("span");
        lbl.textContent = it.label || `(elemento ${idx})`;
        lbl.title = tplKnown ? "Schema modulare. Usa 📋 per inserire via form, 🔍 per il codice grezzo."
                             : "Usa 🔍 per inserire come blocco TikZ codice grezzo.";
        lbl.style.cssText = "flex:1;text-align:left;padding:4px;font:12px/1.3 system-ui;color:#bbb;cursor:default;user-select:text";

        // 🔍 — apre CM6 modal con il content pre-popolato.
        const codeBtn = document.createElement("button");
        codeBtn.type = "button"; codeBtn.textContent = "🔍";
        codeBtn.title = "Apri in CM6 + preview (modifica → ➕ inserisci, 💾 salva mio predefinito, 🔄 reset)";
        codeBtn.style.cssText = "padding:2px 6px;background:#2a4a2a;border:1px solid #66bb6a;border-radius:3px;cursor:pointer;font:11px/1 system-ui;color:#a5d6a7";
        codeBtn.addEventListener("click", async (e) => {
            e.preventDefault(); e.stopPropagation();
            if (menu && menu.__fmClose) menu.__fmClose();
            const crud = await getCrud();
            await crud.openTexInsertEditor(getPanel, {
                initialType: it.type || "tikz",
                initialCode: it.content || "",
                title: `Codice — ${it.label || "elemento"}`,
                groupKey,
                label: it.label || "",
                isOverride: !!it._override,
                refreshMenu,
            });
        });

        const tplBtn = document.createElement("button");
        tplBtn.type = "button"; tplBtn.textContent = "📋";
        tplBtn.title = tplKnown ? "Template Filler — modifica valori, ➕ inserisci, 💾 salva mio predefinito, 🔄 reset"
                                : "Versione modulare non disponibile per questo template";
        const dim = tplKnown ? 1 : 0.4;
        tplBtn.style.cssText = `padding:2px 6px;background:#2a4a2a;border:1px solid #66bb6a;border-radius:3px;cursor:${tplKnown ? "pointer" : "not-allowed"};font:11px/1 system-ui;color:#a5d6a7;opacity:${dim}`;
        tplBtn.addEventListener("click", async (e) => {
            e.preventDefault(); e.stopPropagation();
            if (menu && menu.__fmClose) menu.__fmClose();
            if (!tplKnown) {
                const msg = `Versione modulare non disponibile per "${it.label || "questo template"}". Chiedi all'admin di aggiungere il supporto modulare a questo elemento.`;
                toast(msg, "warn");
                return;
            }
            const crud = await getCrud();
            await crud.openFillerForTemplateRow(getPanel, groupKey, it, refreshMenu);
        });

        const originBadge = document.createElement("span");
        if (it._origin) {
            originBadge.textContent = "📚";
            originBadge.title = `Importato dalla libreria admin (origine: ${it._origin}). Modificabile come ogni altro elemento del tuo menu.`;
            originBadge.style.cssText = "padding:0 4px;color:#9bc1ff;font:11px/1 system-ui;cursor:help";
        }

        const delBtn = document.createElement("button");
        delBtn.type = "button"; delBtn.textContent = "🗑️";
        delBtn.title = "Elimina elemento dal tuo menu";
        delBtn.style.cssText = "padding:2px 6px;background:#3a1e1e;border:1px solid #c02a2a;border-radius:3px;cursor:pointer;font:11px/1 system-ui";
        delBtn.addEventListener("click", async (e) => {
            e.preventDefault(); e.stopPropagation();
            const ok = await confirmDialog({
                title: "Elimina elemento",
                message: `Eliminare l'elemento "${it.label || "(senza nome)"}" DAL TUO MENU?\nIl default admin resta intatto, puoi reimportare in seguito da "📚 Libreria admin".`,
                confirmLabel: "Elimina",
                danger: true,
            });
            if (!ok) return;
            const res = await apiPost("/tikz/workspace/element/delete", {
                groupKey, label: it.label || "",
            });
            if (res?.success === true || res?.ok === true) {
                toast("Elemento eliminato dal tuo menu", "ok");
                texWorkspace.invalidate();
                row.remove();
                if (typeof refreshMenu === "function") await refreshMenu();
            } else {
                toast(`Errore: ${res?.error || "?"}`, "err");
            }
        });

        row.appendChild(lbl);
        if (it._origin) row.appendChild(originBadge);
        row.appendChild(tplBtn);
        row.appendChild(codeBtn);
        row.appendChild(delBtn);
        return row;
    }

    return { buildTexDropdownGlobal };
}
