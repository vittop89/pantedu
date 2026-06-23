/**
 * Phase 25.A3 — Estratto da section-edit-mode.js (1003 LOC → 4 moduli).
 *
 * Modal popup CREATE/EDIT teacher_content (legacy backward-compat) +
 * tutte le API REST collegate (create/update/delete/setVisibility/fetchRow)
 * + helper per layout="exercises" seed e populateTemplatePicker.
 *
 * Esposto:
 *   - openModal({ type, mode, sidepage, row?, preCategory?, preSubject? })
 *   - closeModal()
 *   - buildModalHtml({ type, mode, row, preCategory })
 *   - extractFields(form, type, existingMeta?)
 *   - createContent(type, fields, preSubject?)
 *   - updateContent(id, fields)
 *   - deleteContent(id)
 *   - setVisibility(id, action)
 *   - fetchRow(id)
 *   - refreshSidepage(type)
 *   - exercisesSeedPt()
 *
 * ADR-024 — modal UNICO cross-categoria. Il fork da template istituzionale
 * (prima in sidepage-modal-instance.js, solo risdoc/bes) è ora una via di
 * questo stesso modal (doc_mode=fork), disponibile in ogni categoria.
 * createForkInstance() incapsula il POST /instances. sidepage-modal-instance.js
 * è stato rimosso.
 */

import { byKey as sidepageByKey, byType as sidepageByType } from "./sidepage-registry.js";
import { escHtml, escAttr, parseMeta as safeMeta, fetchCsrf } from "../core/dom-utils.js";
// 2026-05-24 Fase 5b — share-grants-popup convertito a dynamic import
// per dedupe il chunk con verifica-detail-modal.js (era INEFFECTIVE_
// DYNAMIC_IMPORT warning: static qui + dynamic là forzava il modulo
// nel bundle parent invece di chunk separato).
// Caricato lazy al click sul btn "🎯 Avanzato" (interactive only).

/**
 * ADR-028 — nasconde in CREATE i radio "Modello documento" (doc_mode) che
 * mappano su un content_type non consentito dalle capability del docente.
 * Mapping doc_mode→type coerente con createType in submit:
 *   exercises→esercizio · custom→document · fork→fork · (link/upload/drawio)→mappa.
 * Se le caps non sono note (SINGLE / pre-load) non filtra nulla.
 */
function gateDocModesByCaps(modal) {
    const C = window.FM?.Caps;
    if (!C || typeof C.canDocType !== "function") return;
    const typeOf = (m) => m === "exercises" ? "esercizio"
                        : m === "custom"    ? "document"
                        : m === "fork"      ? "fork"
                        : "mappa"; // link / upload / drawio_native
    const radios = [...modal.querySelectorAll('input[name="doc_mode"]')];
    if (radios.length === 0) return;
    let firstVisible = null;
    let anyChecked = false;
    radios.forEach((r) => {
        const allowed = C.canDocType(typeOf(r.value));
        const wrap = r.closest("label") || r.parentElement;
        if (!allowed) {
            // setProperty important: la label ha display:flex !important nel CSS,
            // un inline "display:none" semplice verrebbe ignorato.
            if (wrap) wrap.style.setProperty("display", "none", "important");
            r.disabled = true;
            r.checked = false;
        } else {
            if (!firstVisible) firstVisible = r;
            if (r.checked) anyChecked = true;
        }
    });
    // Garantisce un modo valido selezionato (il default poteva essere nascosto).
    if (firstVisible && !anyChecked) firstVisible.checked = true;
    // Se NESSUN modo è consentito, avvisa (raro: profilo senza tipi creabili qui).
    if (!firstVisible) {
        const fs = modal.querySelector('input[name="doc_mode"]')?.closest("fieldset, .fm-modal-docmode, form");
        if (fs && !fs.querySelector(".fm-modal-nocaps")) {
            const note = document.createElement("p");
            note.className = "fm-modal-nocaps fm-muted";
            note.style.cssText = "color:var(--fm-c-warning);font-size:12px;margin:6px 0";
            note.textContent = "Il tuo profilo non consente di creare documenti in questa sezione.";
            fs.prepend(note);
        }
    }
}

export function openModal({ type, mode, sidepage: _sidepage, row, preCategory, preSubject, sectionKey = "", allowedTypes = [], templateOrigin = "", templateGroups = [] }) {
    closeModal();
    const modal = document.createElement("div");
    modal.className = "fm-modal-backdrop";
    modal.innerHTML = buildModalHtml({ type, mode, row, preCategory });
    if (preSubject) modal.dataset.fmPreSubject = preSubject;
    if (sectionKey) modal.dataset.fmSectionKey = sectionKey;
    const openedAt = Date.now();
    modal.dataset.fmOpenedAt = String(openedAt);
    document.body.appendChild(modal);

    // ADR-028 — gating UI per capability: in CREATE nasconde i "Modello
    // documento" che il docente non può creare (coerente con doc_types +
    // l'enforcement server in store()). PRIMA del toggle così seleziona un
    // modo valido. In SINGLE/pre-load = permissivo (nessun limite).
    if (mode === "create") gateDocModesByCaps(modal);

    // Phase G3.b/review — UNIFICATO doc_mode toggle: una funzione sola
    // mostra la sezione corrispondente al radio selezionato, nascondendo
    // tutte le altre. Funziona per mappa (5 opzioni) + altri tipi (2).
    wireDocModeToggle(modal);
    // ADR-027 — niente selettore content_type: il tipo deriva dal "Modello
    // documento" (doc_mode) scelto, uguale in ogni pannello.
    if (modal.querySelector('input[name="doc_mode"][value="custom"]')) {
        populateTemplatePicker(modal, type, templateOrigin, templateGroups);
    }
    // ADR-024 — fork picker cross-categoria (tutti i template istituzionali).
    if (modal.querySelector('input[name="doc_mode"][value="fork"]')) {
        populateForkPicker(modal, preCategory);
    }
    // Migration 069 — scope fan-out: toggle lista classi + populate da my-classes.
    wireScopePicker(modal);
    // ADR-027 — i modi upload/drawio (Modello documento) sono disponibili in
    // OGNI pannello in create, quindi il wiring va sempre attivato.
    if (mode === "create") {
        wireMappaUpload(modal);
        wireMappaDrawioEmbed(modal);
    }

    // Phase G7 — wiring bottone "Apri editor drawio" in EDIT mode mappa
    // con blob locale. Click → openDrawioEditor mode='edit'. Su save
    // chiude modal teacher_content + refresh sidepage.
    const drawioEditBtn = modal.querySelector(".fm-modal-drawio-edit-btn");
    if (drawioEditBtn) {
        drawioEditBtn.addEventListener("click", async () => {
            const cid = parseInt(drawioEditBtn.dataset.fmContentId, 10);
            if (!cid) return;
            try {
                if (!window.FM?.DrawioEditor?.openDrawioEditor) {
                    alert("Editor drawio non caricato. Ricarica la pagina.");
                    return;
                }
                const result = await window.FM.DrawioEditor.openDrawioEditor({
                    contentId: cid, mode: "edit"
                });
                if (result?.saved) {
                    closeModal();
                    refreshSidepage(type);
                }
            } catch (err) {
                console.error("[drawio-edit-from-modal]", err);
                window.FM?.ToastManager?.show?.("error", "Editor drawio",
                    err.message || String(err), 4000);
            }
        });
    }

    // G22.S25 — Bottone "🎯 Avanzato" → popup grants espliciti.
    const grantsBtn = modal.querySelector('.fm-modal-grants-open');
    if (grantsBtn) {
        grantsBtn.addEventListener("click", async () => {
            const cid = parseInt(grantsBtn.dataset.fmContentId, 10);
            const src = grantsBtn.dataset.fmContentSource || "teacher_content";
            const title = modal.querySelector('input[name="title"]')?.value || "";
            if (cid > 0) {
                const mod = await import("./share-grants-popup.js");
                mod.openShareGrantsPopup({ source: src, id: cid, title });
            }
        });
    }

    // G22.S22/S25 — Toggle "Condividi con colleghi" autosave.
    // Delega a share-client.toggleSharePool (centralizza CSRF + URL + parse).
    const sharePoolCb = modal.querySelector('input[data-fm-share-pool-id]');
    if (sharePoolCb) {
        sharePoolCb.addEventListener("change", async () => {
            const cid = parseInt(sharePoolCb.dataset.fmSharePoolId, 10);
            if (!cid) return;
            const enabled = sharePoolCb.checked;
            sharePoolCb.disabled = true;
            try {
                const { toggleSharePool } = await import("./share/share-client.js");
                await toggleSharePool("teacher_content", cid, enabled);
                window.FM?.SyncPanel?.notify?.("Pool", "ok",
                    enabled ? "✓ Condiviso con colleghi" : "✓ Condivisione rimossa", 2500);
            } catch (err) {
                sharePoolCb.checked = !enabled;
                window.FM?.SyncPanel?.notify?.("Pool", "error",
                    "Errore: " + err.message, 4000);
            } finally {
                sharePoolCb.disabled = false;
            }
        });
    }

    // Phase 20 — grace period 300ms: ignora click accidentali sul backdrop
    modal.addEventListener("click", (e) => {
        const isBackdrop = e.target === modal;
        const isCancelBtn = e.target.classList.contains("fm-modal-cancel");
        if (!isBackdrop && !isCancelBtn) return;
        if (isBackdrop && (Date.now() - openedAt) < 300) {
            e.stopPropagation();
            return;
        }
        closeModal();
    });

    const form = modal.querySelector(".fm-modal-form");
    form?.addEventListener("submit", async (e) => {
        e.preventDefault();
        const err = modal.querySelector(".fm-modal-error");
        try {
            const docModeSel = form.querySelector('input[name="doc_mode"]:checked')?.value || "";
            // ADR-027 — in CREATE il content_type deriva dal "Modello documento"
            // (doc_mode), NON dal pannello: ogni pannello crea qualsiasi formato.
            //   link/upload/drawio_native → mappa · exercises → esercizio · custom → document
            // In EDIT si mantiene il content_type esistente. (Opzione A / migr 078:
            // 'risdoc' collassato in 'document'.)
            const createType = (docModeSel === "exercises") ? "esercizio"
                             : (docModeSel === "custom") ? "document"
                             : "mappa";
            const effType = (mode === "create") ? createType : type;

            // upload/drawio_native: blob via /api/maps (ancorato alla sezione).
            if (mode === "create" && (docModeSel === "upload" || docModeSel === "drawio_native")) {
                await createMappaWithBlob(form, docModeSel, preSubject || "", sectionKey);
                closeModal();
                if (sectionKey) refreshSidepageByKey(sectionKey); else refreshSidepage("mappa");
                return;
            }
            // ADR-026 — template seed schema-driven → deriva il PT prima di extractFields.
            await ensureTemplateSeedPt(modal);
            const fields = extractFields(form, effType, mode === "edit" ? safeMeta(row || {}) : {});
            // Topic / NumArg degli ESERCIZI: deve essere 'numero.numero' (es. 1.0).
            // È la chiave di raggruppamento/instradamento del topic; il server
            // svuota i valori non conformi → il doc finisce in «(senza topic)» e
            // perde le sezioni (Esercizi/Verifiche). Auto-fix intero "1"→"1.0";
            // blocca con messaggio chiaro se vuoto/non numerico (no doc rotto muto).
            if (mode === "create" && effType === "esercizio") {
                let tp = String(fields.topic || "").trim();
                if (/^\d+$/.test(tp)) tp = `${tp}.0`;
                if (!/^\d+\.\d+$/.test(tp)) {
                    if (err) err.textContent = "Topic / NumArg: usa il formato numero.numero (es. 1.0, 2.3). "
                        + "Per i documenti di esercizi è obbligatorio — senza, il documento finisce in «(senza topic)» e perde le sezioni.";
                    form.querySelector('input[name="topic"]')?.focus();
                    return;
                }
                fields.topic = tp;
            }
            // Update chirurgico (create/edit, sidepage db subject-grouped). Il
            // pannello target è risolto per-ramo dalla sezione effettiva
            // (sectionKey in create, _sidepage in edit), non solo dal type.
            const def = sidepageByType(effType);
            const hasBodyPt = Array.isArray(fields.metadata?.body_pt) && fields.metadata.body_pt.length > 0;
            const docRolesStr = Array.isArray(fields.metadata?.doc_roles)
                ? fields.metadata.doc_roles.join("") : "";

            if (mode === "create") {
                const newId = await createContent(effType, fields, preSubject || "", sectionKey);
                closeModal();
                // 2026-06-09 — INSERT chirurgico del solo nuovo item (no full
                // reload del pannello): prima si rifaceva refreshSidepageByKey,
                // che ri-fetchava+ri-renderizzava TUTTO il pannello (sembrava un
                // reload di sidebar) e la navigazione che ne seguiva interrompeva
                // la sync Drive ("Sync interrotta dal cambio pagina"). Come l'EDIT,
                // facciamo l'update mirato quando il loader è db subject-grouped.
                const insDef = (sectionKey && sidepageByKey(sectionKey)) || def;
                const isDbLoader = !!insDef && insDef.loader === "db";
                const subjIns = preSubject || document.getElementById("sel-mater")?.value || "";
                const rowIns = { id: newId, topic: fields.topic, title: fields.title,
                                 has_body_pt: hasBodyPt, doc_roles: docRolesStr };
                let inserted = false;
                if (newId && isDbLoader && insDef.group !== "category") {
                    // subject-grouped → blocco materia
                    inserted = !!window.FM?.dbSidepageInsertItem?.({
                        sidepageKey: insDef.key, type: effType, subj: subjIns, row: rowIns });
                } else if (newId && isDbLoader && insDef.group === "category") {
                    // category-grouped (verif/document) → blocco della categoria
                    // di creazione (preCategory dal "+", fallback a metadata/default).
                    const catIns = preCategory || fields.metadata?.category || insDef.defaultCategory || "";
                    inserted = !!window.FM?.dbSidepageInsertCategoryItem?.({
                        sidepageKey: insDef.key, type: effType, category: catIns, subj: subjIns, row: rowIns });
                }
                // Fallback (loader risdoc, o blocco non renderizzato) → refresh del
                // pannello della sezione (loader unico per section_id).
                if (!inserted) {
                    if (sectionKey) refreshSidepageByKey(sectionKey); else refreshSidepage(effType);
                }
                // Phase 24.78 — naviga il PANNELLO al nuovo documento appena creato.
                // Prima si aggiornava solo la sidebar e il panel restava sul vecchio
                // contenuto ("non si carica subito nel panel" → serviva un reload).
                // Attende che il refresh inserisca la <li> col linkref, poi lo clicca
                // (SPA navigation via DOMManager bindLinkrefClick).
                if (newId) {
                    const tryNav = (attempts) => {
                        // Phase 25 fix — i loader db usano `a.linkref` (SPA nav), ma il
                        // loader risdoc/bes rende `<a>` senza quella classe → l'auto-
                        // apertura non scattava (il doc restava solo nel panel). Match
                        // su .linkref se presente, altrimenti su qualunque <a> della <li>.
                        const li = document.querySelector(`li[data-content-id="${newId}"]`);
                        const link = li?.querySelector("a.linkref") || li?.querySelector("a[href]");
                        if (link) { link.click(); return; }
                        if (attempts > 0) setTimeout(() => tryNav(attempts - 1), 200);
                    };
                    setTimeout(() => tryNav(10), 250);
                }
            } else if (row?._isTemplate) {
                // Phase 24.74 — modifica META del template istituzionale (super-admin)
                // dallo STESSO modal: niente più editor schema in nuova pagina. Lo
                // schema vero si edita da /admin/templates (✏️ Schema).
                await updateTemplateMeta(row.id, { argomento: fields.title, num_arg: fields.topic });
                const spKey = _sidepage?.dataset?.sidepage || "";
                closeModal();
                if (spKey && window.FM?.reloadSidepageByKey) window.FM.reloadSidepageByKey(spKey);
                else refreshSidepage(type);
            } else {
                await updateContent(row.id, fields);
                closeModal();
                // 2026-06-09 — opera sul pannello ATTUALE (_sidepage), non su
                // quello derivato dal type: in sezioni custom/multi-tipo
                // (ADR-027) o per i loader risdoc/bes il pannello del type di
                // default ≠ quello in cui vive l'item → surgicalUpdateItem non
                // trovava la <li> e il fallback ricaricava il pannello sbagliato
                // (il blocco visualizzato restava stantio).
                const spKey = _sidepage?.dataset?.sidepage
                           || (_sidepage?.id || "").replace(/^fm-sp-/, "");
                const updDef = (spKey && sidepageByKey(spKey)) || def;
                // 2026-06-10 — anche i loader category (verif/document) sono ora
                // chirurgici in EDIT: surgicalUpdateItem è category-aware (usa
                // subject_code della row + suffisso "· SUBJ"). Prima erano esclusi
                // → ogni modifica nel pannello Verifiche faceva un full refresh.
                const canSurg = !!updDef && updDef.loader === "db";
                const updKey = spKey || updDef?.key;
                let done = false;
                if (canSurg && fields.visibility === "archived") {
                    // archiviato → sparisce dalla sidepage
                    done = window.FM?.dbSidepageRemoveItem?.({ sidepageKey: updKey, id: row.id });
                } else if (canSurg) {
                    done = window.FM?.dbSidepageUpdateItem?.({
                        sidepageKey: updKey, type,
                        row: { id: row.id, topic: fields.topic, title: fields.title,
                               has_body_pt: hasBodyPt, doc_roles: docRolesStr,
                               subject_code: row.subject_code || row.subject || "" },
                    });
                }
                if (!done) {
                    if (spKey) refreshSidepageByKey(spKey); else refreshSidepage(type);
                }
            }
        } catch (ex) {
            if (err) err.textContent = ex.message || String(ex);
        }
    });

    setTimeout(() => modal.querySelector("input[autofocus]")?.focus(), 50);
}

export function closeModal() {
    document.querySelector(".fm-modal-backdrop")?.remove();
}

export function buildModalHtml({ type, mode, row, preCategory }) {
    const r = row || {};
    const meta = safeMeta(r);
    const isMappa = type === "mappa";
    const isCreate = mode === "create";
    // Phase 24.74 — row sintetica di un TEMPLATE istituzionale (super-admin):
    // il modal mostra solo titolo/topic + modello read-only, niente audience.
    const isTemplate = !!r._isTemplate;
    const titleDefault = escHtml(r.title || "");
    const topicDefault = escHtml(r.topic || "");
    const hrefDefault  = escHtml(meta.mappa?.href || meta.url || "");
    const visibility   = r.visibility || "draft";
    // Migration 069 — scope di pubblicazione (chi vede il documento).
    const publishScope = r.publish_scope || "class";
    // ADR-028 — mostra solo le opzioni entro la visibilità massima del docente
    // (coerente con l'enforcement server in store()). In SINGLE / pre-load le
    // caps sono permissive → tutte e 3 le opzioni. Tiene sempre l'opzione già
    // salvata (evita di nasconderla se il profilo è cambiato dopo).
    const _scopeAllowed = (s) =>
        (window.FM?.Caps?.visibilityAllowed?.(s) ?? true) || s === publishScope;
    const scopeOptionsHtml = [
        ["class",   "Solo questa classe (la sezione corrente)"],
        ["classes", "Più classi (scegli sotto) — anche di altri indirizzi/istituti"],
        ["general", "Tutti gli studenti della stessa materia"],
    ].filter(([v]) => _scopeAllowed(v))
     .map(([v, label]) => `<option value="${v}" ${publishScope === v ? "selected" : ""}>${label}</option>`)
     .join("");

    // Phase G3.b/review — link generico (URL qualsiasi: drawio, Drive, sito,
    // PDF, ecc.). Sempre visibile, required SOLO quando doc_mode=link.
    const hrefLabel       = "Link esterno (opzionale)";
    const hrefPlaceholder = "https://... (drawio, Drive, sito, PDF, ecc.)";

    // Phase 25 fix — RADICE del "creo in modelli → finisce in risorse": il modal
    // riceve il `type` GREZZO del pannello ("risdoc"/"bes"), NON l'effType
    // collassato "document". Con `type === "document"` soltanto, per risdoc/bes
    // showCategory era false → l'input hidden `category` (= la partizione scelta
    // dal "+") non veniva MAI inserito → categoria persa → ogni documento
    // ricadeva nel default. risdoc/bes sono famiglie document category-grouped:
    // devono preservare la categoria esattamente come "document".
    const showCategory = type === "document" || type === "risdoc" || type === "bes";
    const selectedKey = (preCategory || meta.category || "").trim();
    const categoryHtml = (showCategory && selectedKey)
        ? `<input type="hidden" name="category" value="${escAttr(selectedKey)}">`
        : "";

    // Phase G3.b/review — UNIFICATO "Modello documento": un solo fieldset
    // con tutte le opzioni applicabili al tipo. Ogni radio espone "subito
    // sotto" la sua sezione (drop-zone, drawio button, template picker)
    // come gia' fa layout=custom.
    //
    // Per type=mappa create: 5 opzioni (link, upload, drawio_native,
    //   exercises, custom).
    // Per altri tipi create: 2 opzioni (exercises, custom — comportamento
    //   legacy).
    // Per edit mode: solo le 2 PT (exercises/custom) — link/upload/drawio
    //   non ha senso post-create (la modifica del blob avviene via editor
    //   drawio inline G4).
    // ADR-027 — in CREATE ogni pannello mostra TUTTI i "Modello documento".
    const showDocMode = isCreate || ["document", "esercizio", "verifica", "mappa"].includes(type);
    const currentLayout = (meta.layout === "exercises" || meta.layout === "custom") ? meta.layout : "exercises";
    const isForkFamily = (type === "document");

    // ADR-024 — default per CREATE rispetta l'intento primario della categoria
    // ma TUTTE le vie restano disponibili (modal unico):
    //   mappa            → 'link' (default storico)
    //   document         → 'fork' (uso primario: forkare template istituzionali)
    //   esercizio/verifica → 'custom' (PT libero = WebContent)
    // In EDIT mode niente fork/mappa: si mantiene il layout corrente.
    let defaultDocMode;
    if (isMappa && isCreate)       defaultDocMode = "link";
    else if (!isCreate)            defaultDocMode = currentLayout;
    else                           defaultDocMode = "custom"; // ADR-026: fork→custom (templateSeed)

    const mappaCreateOpts = isCreate ? `
                    <label class="fm-modal-layout__opt">
                        <input type="radio" name="doc_mode" value="link" ${defaultDocMode === "link" ? "checked" : ""}>
                        <span>🔗 <strong>Link esterno</strong> — usa il campo "Link esterno" sopra (drawio, Drive, sito, PDF, ecc.)</span>
                    </label>

                    <label class="fm-modal-layout__opt">
                        <input type="radio" name="doc_mode" value="upload" ${defaultDocMode === "upload" ? "checked" : ""}>
                        <span>⬆ <strong>Carica file</strong> — PDF, drawio, PNG, XML, HTML (max 50MB)</span>
                    </label>
                    <div class="fm-modal-doc-section" data-fm-doc-mode="upload" hidden>
                        <div class="fm-drop-zone" tabindex="0">
                            <p style="margin:6px 0;color:#475569">
                                ⬆ Trascina qui il file o
                                <button type="button" class="fm-btn fm-btn--ghost fm-btn--sm fm-drop-pick">scegli</button>
                            </p>
                            <p class="fm-drop-status" style="font-size:12px;color:#64748b;margin:0">
                                Nessun file selezionato.
                            </p>
                            <input type="file" name="map_file"
                                   accept=".drawio,.xml,.pdf,.png,.jpg,.jpeg,.html,application/xml,application/pdf,image/png,image/jpeg,text/html"
                                   style="display:none">
                        </div>
                    </div>

                    <label class="fm-modal-layout__opt">
                        <input type="radio" name="doc_mode" value="drawio_native" ${defaultDocMode === "drawio_native" ? "checked" : ""}>
                        <span>✏️ <strong>Nuova mappa drawio (vuota)</strong> — apri editor drawio</span>
                    </label>
                    <div class="fm-modal-doc-section" data-fm-doc-mode="drawio_native" hidden>
                        <p style="font-size:12px;color:#475569;margin:6px 0">
                            Apri l'editor drawio. Quando salvi, l'XML torna qui automaticamente.
                        </p>
                        <button type="button" class="fm-btn fm-btn--primary fm-modal-drawio-open">✏️ Apri editor drawio</button>
                        <p class="fm-modal-drawio-status" style="font-size:12px;color:#64748b;margin:6px 0">
                            Nessun salvataggio ancora.
                        </p>
                        <input type="hidden" name="map_xml" value="">
                    </div>
` : "";

    // ADR-024 — modal UNICO cross-categoria: l'opzione "Fork da template
    // istituzionale" (prima in openInstanceModal, solo risdoc/bes) è ora
    // disponibile in OGNI categoria in create mode (eccetto mappa). Permette
    // p.es. di forkare un template dalla categoria Eser. Il submit branch-a
    // su doc_mode=fork → POST /instances (vedi createForkInstance).
    // ADR-026 — "Fork da template istituzionale" INTEGRATO nel custom: il
    // radio "Personalizzabile" ha già "Parti da template istituzionale
    // (opzionale)" (templateSeed, popolato da /api/risdoc/templates), che copre
    // lo stesso bisogno. Niente più modo "fork" separato → un solo percorso.
    const showFork = false;
    const forkOpts = showFork ? `
                    <label class="fm-modal-layout__opt">
                        <input type="radio" name="doc_mode" value="fork" ${defaultDocMode === "fork" ? "checked" : ""}>
                        <span>🔱 <strong>Fork da template istituzionale</strong> — copia un modello standard (modifica isolata, reset disponibile)</span>
                    </label>
                    <div class="fm-modal-doc-section fm-modal-fork-pick" data-fm-doc-mode="fork" hidden>
                        <div class="fm-modal-fork-role" role="radiogroup" aria-label="Ruolo documento">
                            <span class="fm-modal-fork-role__lbl">Ruolo</span>
                            <label><input type="radio" name="doc_role" value="D" checked> 👨‍🏫 <strong>D</strong> Docente</label>
                            <label><input type="radio" name="doc_role" value="C"> 🎯 <strong>C</strong> Coordinatore</label>
                            <label><input type="radio" name="doc_role" value="R"> 🧭 <strong>R</strong> Referente</label>
                        </div>
                        <label>Categoria template
                            <select name="template_category" class="fm-modal-fork-cat" style="width:100%">
                                <option value="" selected>— tutte le categorie —</option>
                            </select>
                        </label>
                        <label>Template istituzionale
                            <select name="template_id" class="fm-modal-fork-tpl" style="width:100%">
                                <option value="" selected>— seleziona un template —</option>
                            </select>
                        </label>
                        <p class="fm-muted" style="font-size:11px;margin:4px 0 0;line-height:1.4">
                            Il <strong>Titolo</strong> sopra sarà l'etichetta del documento;
                            <strong>Topic/NumArg</strong> il numero (opzionale).
                        </p>
                    </div>` : "";

    const ptOpts = showDocMode ? `
                    ${forkOpts}
                    <label class="fm-modal-layout__opt">
                        <input type="radio" name="doc_mode" value="exercises" ${defaultDocMode === "exercises" ? "checked" : ""}>
                        <span>📋 <strong>Stile esercizi</strong> — sezioni predefinite "Esercizi per studenti" e "Verifiche"</span>
                    </label>
                    <label class="fm-modal-layout__opt">
                        <input type="radio" name="doc_mode" value="custom" ${defaultDocMode === "custom" ? "checked" : ""}>
                        <span>✏️ <strong>Personalizzabile</strong> — PT editor libero (toolbar + sticky head)</span>
                    </label>
                    <div class="fm-modal-doc-section fm-modal-template-pick" data-fm-doc-mode="custom" data-fm-pick="custom" hidden>
                        <span style="color:#475569;font-weight:600;font-size:12px">Parti da template istituzionale (opzionale)</span>
                        <select name="templateSeed" class="fm-modal-template-select" style="width:100%">
                            <option value="" selected>— vuoto (PT editor libero) —</option>
                        </select>
                        <div class="fm-modal-roles" role="radiogroup" aria-label="Ruolo del documento (D/C/R)">
                            <span class="fm-modal-roles__lbl">
                                Ruolo (chip nel sidepage)
                                <span class="fm-modal-roles__hint">— facoltativo. Scegli un ruolo</span>
                            </span>
                            <div class="fm-modal-roles__opts">
                                <label class="fm-modal-roles__opt">
                                    <input type="radio" name="doc_roles" value="" checked>
                                    <span class="fm-modal-roles__txt">Nessuno</span>
                                </label>
                                <label class="fm-modal-roles__opt">
                                    <input type="radio" name="doc_roles" value="D">
                                    <span class="fm-modal-roles__txt"><strong>D</strong> Docente</span>
                                </label>
                                <label class="fm-modal-roles__opt">
                                    <input type="radio" name="doc_roles" value="C">
                                    <span class="fm-modal-roles__txt"><strong>C</strong> Coordinatore</span>
                                </label>
                                <label class="fm-modal-roles__opt">
                                    <input type="radio" name="doc_roles" value="R">
                                    <span class="fm-modal-roles__txt"><strong>R</strong> Referente</span>
                                </label>
                            </div>
                        </div>
                    </div>
` : "";
    // G23 Sprint 8 — radio page_doc rimossa. "Personalizzabile" (custom)
    // include nativamente i 5 block types G23 accessibili via toolbar PT
    // dopo toggle topbar HTML↔Edit (data-fm-action="toggle-edit").

    // Phase 24.74 — in EDIT il "Modello documento" NON è modificabile: il
    // formato di un elemento esistente è fisso. Si mostra in SOLA LETTURA la
    // tipologia (Stile esercizi / Personalizzabile). Vale per ogni pannello,
    // istanze e template istituzionali inclusi. Resta editabile solo il chip
    // RUOLO (D/C/R) per i documenti personalizzabili. L'hidden `doc_mode`
    // preserva il layout per extractFields (legge via fd.get).
    const modelLabelRO = isMappa
        ? `🗺 <strong>Mappa</strong> — link / file / drawio`
        : currentLayout === "custom"
            ? `✏️ <strong>Personalizzabile</strong> — PT editor libero`
            : `📋 <strong>Stile esercizi</strong> — sezioni predefinite “Esercizi per studenti” e “Verifiche”`;
    const curRole = (Array.isArray(meta.doc_roles) && meta.doc_roles.length)
        ? String(meta.doc_roles[0]) : "";
    const roleEditRO = (currentLayout === "custom" && !isTemplate) ? `
                    <div class="fm-modal-roles" role="radiogroup" aria-label="Ruolo del documento (D/C/R)" style="margin-top:8px">
                        <span class="fm-modal-roles__lbl">Ruolo (chip nel sidepage)
                            <span class="fm-modal-roles__hint">— facoltativo</span></span>
                        <div class="fm-modal-roles__opts">
                            <label class="fm-modal-roles__opt"><input type="radio" name="doc_roles" value="" ${curRole === "" ? "checked" : ""}><span class="fm-modal-roles__txt">Nessuno</span></label>
                            <label class="fm-modal-roles__opt"><input type="radio" name="doc_roles" value="D" ${curRole === "D" ? "checked" : ""}><span class="fm-modal-roles__txt"><strong>D</strong> Docente</span></label>
                            <label class="fm-modal-roles__opt"><input type="radio" name="doc_roles" value="C" ${curRole === "C" ? "checked" : ""}><span class="fm-modal-roles__txt"><strong>C</strong> Coordinatore</span></label>
                            <label class="fm-modal-roles__opt"><input type="radio" name="doc_roles" value="R" ${curRole === "R" ? "checked" : ""}><span class="fm-modal-roles__txt"><strong>R</strong> Referente</span></label>
                        </div>
                    </div>` : "";

    const docModeHtml = !showDocMode ? "" : (isCreate ? `
                <fieldset class="fm-modal-layout">
                    <legend style="font-size:12px;color:#475569;font-weight:600">Modello documento</legend>
                    ${mappaCreateOpts}
                    ${ptOpts}
                </fieldset>` : `
                <fieldset class="fm-modal-layout fm-modal-layout--ro">
                    <legend style="font-size:12px;color:#475569;font-weight:600">Modello documento</legend>
                    <div class="fm-modal-layout__ro" style="padding:6px 8px;font-size:13px;color:var(--fm-c-text,#1e293b);background:rgba(148,163,184,.12);border-radius:6px">${modelLabelRO}</div>
                    <input type="hidden" name="doc_mode" value="${escAttr(currentLayout)}">
                    ${roleEditRO}
                </fieldset>`);

    // Phase G3.b/review — campo Link esterno SEMPRE visibile (per ogni tipo,
    // create o edit). Funziona da fonte URL per doc_mode=link nelle mappe e
    // da campo opzionale "url esterno" per gli altri tipi. Required handling
    // gestito via JS (wireDocModeToggle: required quando doc_mode=link).
    // Phase 24.74 — il "Link esterno" ha senso SOLO in creazione (è la fonte
    // URL per doc_mode=link, p.es. mappe) o per le mappe in edit. In modifica
    // di esercizio/verifica/document è irrilevante → rimosso.
    const externalLinkHtml = (!isTemplate && (isCreate || isMappa)) ? `
                <label>${hrefLabel}
                    <input type="url" name="href" value="${hrefDefault}"
                           placeholder="${hrefPlaceholder}">
                </label>` : "";

    // Phase G7 — per mappe drawio in EDIT mode con map_blob_path popolato:
    // bottone "✎ Apri editor drawio" che lancia l'overlay full-screen
    // (drawio-editor.js mode='edit'). Save → POST /api/maps/{id}/update
    // (in-place, optimistic concurrency). Mostrato dentro il modal
    // teacher_content normale, sopra Visibilita'.
    // G22.S22/S25 — Toggle "Condividi con colleghi" (shared_with_pool) +
    // bottone "🎯 Avanzato" per grants espliciti (docente/gruppo).
    const sharedWithPool = !!r.shared_with_pool;
    const sharePoolHtml = (!isCreate && r.id) ? `
                <div style="display:flex;gap:8px;align-items:flex-start;background:rgba(99,102,241,0.08);padding:8px 10px;border-radius:6px;border-left:3px solid #6366f1">
                    <label style="flex:1;display:flex;gap:8px;align-items:flex-start;cursor:pointer">
                        <input type="checkbox" name="shared_with_pool"
                               data-fm-share-pool-id="${r.id}"
                               ${sharedWithPool ? "checked" : ""}
                               style="margin-top:3px">
                        <span style="font-size:13px;line-height:1.4">
                            🤝 <strong>Condividi con tutti i colleghi del tuo istituto</strong>
                            <br>
                            <span class="fm-muted" style="font-size:11px">
                                Toggle rapido. Per limitare la condivisione a docenti o gruppi specifici, usa "Avanzato".
                            </span>
                        </span>
                    </label>
                    <button type="button" class="fm-btn fm-btn--xs fm-modal-grants-open"
                            data-fm-content-source="teacher_content"
                            data-fm-content-id="${r.id}"
                            style="align-self:flex-start;white-space:nowrap"
                            title="Condividi con docenti specifici o gruppi personali">
                        🎯 Avanzato…
                    </button>
                </div>` : "";

    const hasMapBlob = isMappa && !isCreate && !!r.map_blob_path;
    const drawioEditHtml = hasMapBlob ? `
                <div class="fm-modal-drawio-edit-wrap">
                    <p class="fm-modal-drawio-edit-info">
                        🗺 <strong>Editor drawio</strong> per modificare il contenuto della mappa (XML del .drawio).
                    </p>
                    <button type="button" class="fm-btn fm-btn--primary fm-modal-drawio-edit-btn"
                            data-fm-content-id="${r.id}">✎ Apri editor drawio</button>
                </div>` : "";

    // Phase 24.74 — blocco "destinatari/visibilità". NON si applica ai template
    // istituzionali (si editano solo titolo/topic/categoria) → gata in return.
    const audienceHtml = `
                <label>Visibilità verso i tuoi studenti
                    <select name="visibility">
                        <option value="draft"      ${visibility === "draft"      ? "selected" : ""}>Bozza (solo tu, gli studenti non lo vedono)</option>
                        <option value="published"  ${visibility === "published"  ? "selected" : ""}>Pubblicato (gli studenti della sezione lo vedono in /studio)</option>
                        <option value="archived"   ${visibility === "archived"   ? "selected" : ""}>Archiviato (nascosto a tutti, dati conservati)</option>
                    </select>
                </label>
                <p class="fm-muted" style="font-size:11px;margin:-4px 0 8px 0;line-height:1.4">
                    <strong>Archiviato</strong> ≠ cancellato: il contenuto sparisce da liste tue e degli studenti
                    ma resta in DB. Lo riattivi rimettendolo su "Bozza" o "Pubblicato". Per eliminare davvero, usa il cestino 🗑.
                </p>
                <label>Chi può vederlo (quando pubblicato)
                    <select name="publish_scope" class="fm-modal-scope-select">
                        ${scopeOptionsHtml}
                    </select>
                </label>
                <div class="fm-modal-target-classes" ${publishScope === "classes" ? "" : "hidden"}>
                    <p class="fm-muted" style="font-size:11px;margin:0 0 4px 0">Seleziona le classi destinatarie:</p>
                    <div class="fm-modal-class-list" data-content-id="${escHtml(String(r.id || ""))}"
                         data-targets='${escHtml(JSON.stringify(Array.isArray(r.target_classes) ? r.target_classes : []))}'
                         style="max-height:160px;overflow:auto;border:1px solid var(--fm-border,#ccc);border-radius:6px;padding:6px;font-size:13px">
                        <em class="fm-muted">Carico le tue classi…</em>
                    </div>
                </div>
                ${sharePoolHtml}`;

    return `
        <div class="fm-modal">
            <div class="fm-modal-header">
                <span>${mode === "create" ? "➕ Crea" : "✎ Modifica"} <code>${escHtml(isTemplate ? "template" : type)}</code></span>
            </div>
            <form class="fm-modal-form" enctype="multipart/form-data">
                <label>Titolo
                    <input type="text" name="title" maxlength="255" value="${titleDefault}" required autofocus>
                </label>
                <label>Topic / NumArg
                    <input type="text" name="topic" maxlength="128" value="${topicDefault}"
                           placeholder="es. '1.0', 'Sistemi lineari'">
                </label>
                ${externalLinkHtml}
                ${docModeHtml}
                ${drawioEditHtml}
                ${isTemplate ? "" : audienceHtml}
                ${categoryHtml}
                <div class="fm-modal-error"></div>
                <div class="fm-modal-actions">
                    <button type="button" class="fm-btn fm-modal-cancel">Annulla</button>
                    <button type="submit" class="fm-btn fm-btn--primary">
                        ${mode === "create" ? "Crea" : "Salva"}
                    </button>
                </div>
            </form>
        </div>`;
}

export function extractFields(form, type, existingMeta = {}) {
    const fd = new FormData(form);
    const title = (fd.get("title") || "").trim();
    const topic = (fd.get("topic") || "").trim();
    const href  = (fd.get("href")  || "").trim();
    const visibility = fd.get("visibility") || "draft";
    const category = (fd.get("category") || "").trim();
    // Phase G3.b/review — radio unificato `doc_mode`. Per back-compat con il
     // resto del codice (meta.layout consumato da TexBuilder/PT pipeline)
     // mappiamo doc_mode ∈ {exercises, custom} → layout, altre opzioni
     // (link/upload/drawio_native) sono mappa-specific e non producono
     // layout PT.
    const docMode = (fd.get("doc_mode") || fd.get("layout") || "").trim();
    const layout = (docMode === "exercises" || docMode === "custom") ? docMode : "";
    const templateSeed = (fd.get("templateSeed") || "").trim();
    const fields = { title, topic, visibility };
    // Migration 069 — scope di pubblicazione + target del fan-out.
    const publishScope = (fd.get("publish_scope") || "class").trim();
    fields.publish_scope = publishScope;
    if (publishScope === "classes") {
        // Coppie "indirizzo|classe" → [{indirizzo, classe}].
        fields.target_classes = fd.getAll("target_class")
            .map((v) => {
                const [indirizzo, classe] = String(v).split("|");
                return { indirizzo: indirizzo || "", classe: classe || "" };
            })
            .filter((t) => t.indirizzo && t.classe);
    } else {
        fields.target_classes = [];
    }
    const meta = {};
    if (layout === "exercises" || layout === "custom") meta.layout = layout;
    // D/C/R role (radio mutuamente esclusivo in Personalizzabile). Salvato in
    // metadata.doc_roles come array (0 o 1 lettera valida). Il sidepage legge
    // questa property per emettere la chip fm-item-role nella sidebar del
    // documento (vedi sidepage-inline-actions). Radio "Nessuno" (value="")
    // → array vuoto → nessun chip. Array per back-compat col render multi.
    if (layout === "custom") {
        const allowed = ["D", "C", "R"];
        const picked = fd.getAll("doc_roles")
            .map((v) => String(v || "").toUpperCase())
            .filter((v) => allowed.includes(v));
        const unique = [...new Set(picked)].sort((a, b) => allowed.indexOf(a) - allowed.indexOf(b));
        if (unique.length > 0) meta.doc_roles = unique;
    }
    if (templateSeed) {
        const seedId = parseInt(templateSeed, 10) || null;
        meta.template_seed_id = seedId;
        // ADR-026 model-sync: alias model_template_id + snapshot baseline +
        // timestamp di sync. Identico alla path createForkInstance così il
        // badge "modello aggiornato" + reset/merge funzionano anche per i fork
        // creati dal modal custom+templateSeed.
        if (seedId) {
            meta.model_template_id = seedId;
            meta.model_synced_at = new Date().toISOString();
        }
    }
    if (href) {
        if (type === "mappa") meta.mappa = { href, display: "show" };
        else                  meta.url   = href;
    }
    if (category) meta.category = category;

    if (existingMeta.body_pt && Array.isArray(existingMeta.body_pt)) {
        meta.body_pt = existingMeta.body_pt;
    } else if (layout === "exercises") {
        meta.body_pt = exercisesSeedPt();
    } else if (layout === "custom") {
        if (templateSeed) {
            const modal = form.closest(".fm-modal-backdrop");
            const opt = modal?.querySelector(`.fm-modal-template-select option[value="${templateSeed}"]`);
            if (opt?.dataset?.bodyPt) {
                try {
                    const body = JSON.parse(opt.dataset.bodyPt);
                    if (Array.isArray(body)) {
                        meta.body_pt = body;
                        // ADR-026 model-sync: snapshot baseline = master a tempo
                        // di fork. Servirà al merge 3-way per riconoscere quali
                        // sezioni il docente avrà modificato.
                        meta.model_body_pt_baseline = body;
                    }
                } catch { /* fallback empty */ }
            }
        }
        // ADR-024 (rework editor a card) — seed = una SEZIONE pulita
        // (sectionHeader + paragrafo) invece di uno staticContent HTML grezzo:
        // così, aprendo Modifica, la prima sezione ha lo STESSO stile risdoc
        // (heading blu editabile + corpo) come le nuove sezioni, non una
        // textarea HTML. Non più "pagina vuota": il heading è sempre visibile.
        if (!meta.body_pt) {
            meta.body_pt = [
                { _type: "sectionHeader", title: title || "Nuova sezione", level: 2 },
                { _type: "block", style: "normal", children: [{ _type: "span", text: "", marks: [] }] },
            ];
        }
    }

    if (Object.keys(meta).length > 0) fields.metadata = meta;
    return fields;
}

// ─────── API ───────

export async function createContent(type, fields, preSubject = "", sectionKey = "") {
    const ind  = document.getElementById("sel-iis")?.value || "";
    const cls  = document.getElementById("sel-cls")?.value || "";
    const subj = preSubject || document.getElementById("sel-mater")?.value || "";
    if (!ind || !cls || !subj) throw new Error("Seleziona indirizzo, classe e materia prima.");
    const csrf = await fetchCsrf();
    const fd = new URLSearchParams();
    fd.set("_csrf", csrf);
    fd.set("type", type);
    // ADR-027 Step 5-6 — sezione di creazione (ancoraggio section_id + validazione).
    if (sectionKey) fd.set("section_key", sectionKey);
    fd.set("subject", subj);
    fd.set("indirizzo", ind);
    fd.set("classe", cls);
    fd.set("topic", fields.topic);
    fd.set("title", fields.title);
    fd.set("visibility", fields.visibility);
    // Migration 069 — scope di pubblicazione + target del fan-out.
    if (fields.publish_scope) fd.set("publish_scope", fields.publish_scope);
    if (fields.target_classes) fd.set("target_classes", JSON.stringify(fields.target_classes));
    if (fields.metadata) fd.set("metadata", JSON.stringify(fields.metadata));
    const r = await fetch("/api/teacher/content", {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: fd.toString(),
    });
    const j = await r.json();
    if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
    window.FM?.ToastManager?.show?.("success", "Creato", `id=${j.id} · ${fields.visibility}`, 3000);
    // Phase 25.A4 — invalida ETag cache su mutate.
    window.FM?.clearTeacherContentCache?.();
    return j.id;
}

export async function updateContent(id, fields) {
    const csrf = await fetchCsrf();
    const fd = new URLSearchParams();
    fd.set("_csrf", csrf);
    fd.set("title", fields.title);
    fd.set("topic", fields.topic);
    fd.set("visibility", fields.visibility);
    // Migration 069 — scope di pubblicazione + target del fan-out.
    if (fields.publish_scope) fd.set("publish_scope", fields.publish_scope);
    if (fields.target_classes) fd.set("target_classes", JSON.stringify(fields.target_classes));
    if (fields.metadata) fd.set("metadata", JSON.stringify(fields.metadata));
    const r = await fetch(`/api/teacher/content/${id}/update`, {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: fd.toString(),
    });
    const j = await r.json();
    if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
    window.FM?.ToastManager?.show?.("success", "Salvato", `id=${id}`, 2500);
    // Phase 25.A4 — invalida ETag cache su mutate.
    window.FM?.clearTeacherContentCache?.();
}

// Phase 24.74 — salva la META di un TEMPLATE istituzionale (super-admin) dallo
// stesso modal: title→argomento, topic→num_arg. Endpoint admin esistente.
export async function updateTemplateMeta(id, { argomento, num_arg }) {
    const csrf = await fetchCsrf();
    const fd = new URLSearchParams({ _csrf: csrf });
    if (argomento != null) fd.set("argomento", String(argomento));
    if (num_arg != null) fd.set("num_arg", String(num_arg));
    const r = await fetch(`/api/admin/risdoc/templates/${id}/meta`, {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: fd.toString(),
    });
    const j = await r.json().catch(() => ({}));
    if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
    window.FM?.ToastManager?.show?.("success", "Template salvato", `id=${id}`, 2500);
}

export async function deleteContent(id) {
    const csrf = await fetchCsrf();
    const fd = new URLSearchParams({ _csrf: csrf });
    const r = await fetch(`/api/teacher/content/${id}/delete`, {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: fd.toString(),
    });
    const j = await r.json();
    window.FM?.ToastManager?.show?.(j.ok ? "success" : "error",
        j.ok ? "Eliminato" : "Errore", j.ok ? `id=${id}` : (j.error || "fail"), 2500);
    // Phase 25.A4 — invalida ETag cache su mutate.
    if (j.ok) window.FM?.clearTeacherContentCache?.();
    return !!j.ok;
}

export async function setVisibility(id, action /* publish | unpublish */) {
    const csrf = await fetchCsrf();
    const fd = new URLSearchParams({ _csrf: csrf });
    const r = await fetch(`/api/teacher/content/${id}/${action}`, {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: fd.toString(),
    });
    const j = await r.json();
    window.FM?.ToastManager?.show?.(j.ok ? "success" : "error",
        j.ok ? "Visibilità" : "Errore",
        j.ok ? `id=${id} → ${j.visibility || action}` : (j.error || "fail"), 2500);
    // Phase 25.A4 — invalida ETag cache su mutate.
    if (j.ok) window.FM?.clearTeacherContentCache?.();
}

export async function fetchRow(id) {
    const r = await fetch(`/api/teacher/content/${id}`, { credentials: "same-origin" });
    if (!r.ok) return null;
    const j = await r.json();
    return j.content || null;
}

export function refreshSidepage(type) {
    let def = sidepageByType(type);
    if (!def && type === "strcomp") def = sidepageByKey("bes");
    if (!def) return;
    refreshSidepageDef(def);
}

// ADR-027 — refresh del pannello per CHIAVE sezione (loader unico per section_id).
export function refreshSidepageByKey(key) {
    const def = sidepageByKey(key);
    if (def) refreshSidepageDef(def);
}

function refreshSidepageDef(def) {
    if (def.loader === "risdoc") {
        const spec = { panelId: def.panel, origin: def.origin, categories: def.categories };
        window.FM?.RisdocSidepage?.loadSidepage?.(def.key, spec);
        return;
    }
    window.FM?.loadDbSidepageContent?.(def.key, def.type);
}

// ADR-026 — risolve il seed PT del template selezionato quando non ha body_pt
// pre-salvato (modello schema-driven): fetch schema → sectionSchemaToPt →
// scrive opt.dataset.bodyPt. Idempotente (no-op se già risolto o nessun seed).
async function ensureTemplateSeedPt(modal) {
    const sel = modal?.querySelector(".fm-modal-template-select");
    const tid = (sel?.value || "").trim();
    if (!tid) return;
    const opt = sel.selectedOptions?.[0];
    if (!opt || opt.dataset.bodyPt || opt.dataset.needsSchema !== "1") return;
    try {
        const r = await fetch(`/api/risdoc/templates/${encodeURIComponent(tid)}/schema`, { credentials: "same-origin" });
        if (!r.ok) return;
        const schema = await r.json();
        const sections = Array.isArray(schema.sections) ? schema.sections : [];
        const { sectionSchemaToPt } = await import("../risdoc/pt/section-to-pt.js");
        const body = [];
        for (const s of sections) {
            if (Array.isArray(s.default)) { body.push(...s.default); continue; }
            try {
                const pt = sectionSchemaToPt(s, {}, {});
                if (Array.isArray(pt)) body.push(...pt);
            } catch { /* sezione non convertibile → skip */ }
        }
        if (body.length) opt.dataset.bodyPt = JSON.stringify(body);
    } catch { /* fallback: extractFields userà il seed-sezione vuoto */ }
}

// Migration 069 — scope fan-out: toggle della lista classi al cambio del
// select publish_scope + populate lazy da /api/teacher/my-classes.
function wireScopePicker(modal) {
    const sel = modal.querySelector(".fm-modal-scope-select");
    if (!sel) return;
    const box = modal.querySelector(".fm-modal-target-classes");
    const list = modal.querySelector(".fm-modal-class-list");
    let loaded = false;

    const sync = () => {
        const isClasses = sel.value === "classes";
        if (box) box.hidden = !isClasses;
        if (isClasses && !loaded) {
            loaded = true;
            populateClassList(list);
        }
    };
    sel.addEventListener("change", sync);
    // Se apre già su 'classes' (edit), popola subito.
    if (sel.value === "classes") sync();
}

// Renderizza le checkbox delle classi del docente (value="indirizzo|classe"),
// pre-spuntando i target correnti (data-targets).
async function populateClassList(list) {
    if (!list) return;
    let preset = [];
    try { preset = JSON.parse(list.dataset.targets || "[]"); } catch { /* ignore */ }
    const presetKeys = new Set(
        preset.map((t) => `${t.indirizzo || ""}|${t.classe || ""}`)
    );
    let classes = [];
    try {
        const res = await fetch("/api/teacher/my-classes", { credentials: "same-origin" });
        const j = await res.json();
        classes = j.classes || [];
    } catch { /* silent */ }
    if (classes.length === 0) {
        list.innerHTML = '<em class="fm-muted">Nessuna classe trovata. Crea prima un documento per una classe.</em>';
        return;
    }
    list.innerHTML = classes.map((c) => {
        const ind = String(c.indirizzo || "");
        const cls = String(c.classe || "");
        const key = `${ind}|${cls}`;
        const checked = presetKeys.has(key) ? "checked" : "";
        return `<label style="display:block;padding:2px 0">
            <input type="checkbox" name="target_class" value="${escHtml(key)}" ${checked}>
            ${escHtml(ind)} · ${escHtml(cls)}
        </label>`;
    }).join("");
}

// Phase 24.50 — popola <select.fm-modal-template-select> con i template
// istituzionali (body_pt pre-salvato OPPURE derivato dallo schema al submit).
async function populateTemplatePicker(modal, type, originOverride = "", groups = []) {
    const sel = modal.querySelector(".fm-modal-template-select");
    if (!sel) return;
    // Phase 24.74 — colonna `origin` RIMOSSA: si filtra per CATEGORY (partizione
    // flat). PRIMA si fetchava per ["risdoc","strcomp"] ma ora l'API ignora il
    // param origin → entrambe le fetch tornavano TUTTI i template ⇒ DUPLICATI.
    // Ora: UN solo fetch + filtro per category (gruppi della sezione, oppure
    // default dedotto dal tipo/override). Dedup per id di sicurezza.
    const groupSet = Array.isArray(groups) ? groups.filter(Boolean) : [];
    const fam = originOverride || ((type === "bes" || type === "strcomp") ? "strcomp" : "risdoc");
    const defaultCats = fam === "strcomp" ? ["altro", "bes"] : ["modelli", "risorse"];
    const catFilter = new Set(groupSet.length ? groupSet : defaultCats);
    let templates = [];
    try {
        const res = await fetch(`/api/risdoc/templates?with_body_pt=1`, { credentials: "same-origin" });
        const j = await res.json();
        const seen = new Set();
        templates = (j.templates || []).filter((t) => {
            const k = String(t.id);
            if (seen.has(k)) return false;           // dedup id
            seen.add(k);
            return catFilter.has(t.category);          // filtro partizione
        });
    } catch (_) { /* silent */ }
    if (templates.length === 0) {
        const opt = document.createElement("option");
        opt.value = "";
        opt.disabled = true;
        opt.textContent = "— nessun template istituzionale disponibile —";
        sel.appendChild(opt);
        return;
    }

    const sortNumArg = (a, b) => {
        const na = parseFloat(a.num_arg), nb = parseFloat(b.num_arg);
        if (!isNaN(na) && !isNaN(nb)) return na - nb;
        return String(a.num_arg).localeCompare(String(b.num_arg));
    };
    const byCat = {};
    for (const t of templates) (byCat[t.category || "ALTRO"] ??= []).push(t);
    for (const cat of Object.keys(byCat)) byCat[cat].sort(sortNumArg);

    for (const cat of Object.keys(byCat)) {
        const og = document.createElement("optgroup");
        og.label = cat;
        for (const t of byCat[cat]) {
            const opt = document.createElement("option");
            opt.value = String(t.id);
            const hasPt = Array.isArray(t.body_pt) && t.body_pt.length > 0;
            const argo = String(t.argomento || "").replace(/_/g, " ");
            const num  = t.num_arg ? `${t.num_arg} ` : "";
            opt.textContent = `${num}${argo}`;
            opt.dataset.tid = String(t.id);
            if (hasPt) {
                // Fast-path: body_pt pre-salvato dall'admin.
                opt.dataset.bodyPt = JSON.stringify(t.body_pt);
            } else {
                // ADR-026 percorso unico: niente body_pt salvato → deriviamo il
                // seed PT dallo SCHEMA del modello al submit (sectionSchemaToPt),
                // come fa RisdocTemplateAdapter.load(). Niente più "(no PT)".
                opt.dataset.needsSchema = "1";
            }
            og.appendChild(opt);
        }
        sel.appendChild(og);
    }
}

// ─────── ADR-024 — fork da template istituzionale (modal unico) ───────

/**
 * Popola i select categoria + template del fork-picker con TUTTI i template
 * istituzionali (cross-categoria: nessun filtro origin). Wire category→template.
 */
async function populateForkPicker(modal, preCategory) {
    const catSel = modal.querySelector(".fm-modal-fork-cat");
    const tplSel = modal.querySelector(".fm-modal-fork-tpl");
    if (!catSel || !tplSel) return;

    let templates = [];
    try {
        const res = await fetch(`/api/risdoc/templates`, { credentials: "same-origin" });
        const j = await res.json();
        templates = j.templates || [];
    } catch (_) { /* silent */ }
    if (templates.length === 0) {
        tplSel.innerHTML = '<option value="">— nessun template disponibile —</option>';
        return;
    }

    const sortNumArg = (a, b) => {
        const na = parseFloat(a.num_arg), nb = parseFloat(b.num_arg);
        if (!isNaN(na) && !isNaN(nb)) return na - nb;
        return String(a.num_arg).localeCompare(String(b.num_arg));
    };
    templates.sort(sortNumArg);
    const cats = [...new Set(templates.map(t => t.category).filter(Boolean))].sort();

    // Popola categorie (mantiene "— tutte —" come prima opzione).
    for (const c of cats) {
        const o = document.createElement("option");
        o.value = c; o.textContent = c;
        catSel.appendChild(o);
    }

    const tplOptionsFor = (cat) => {
        const list = cat ? templates.filter(t => t.category === cat) : templates;
        const opts = ['<option value="">— seleziona un template —</option>'];
        for (const t of list) {
            const argo = String(t.argomento || "").replace(/_/g, " ");
            const num  = t.num_arg ? `${t.num_arg} ` : "";
            const catTag = cat ? "" : ` [${t.category || "?"}]`;
            opts.push(`<option value="${escAttr(t.id)}">${escHtml(`${num}${argo}${catTag}`)}</option>`);
        }
        return opts.join("");
    };

    const preCat = (preCategory && cats.includes(preCategory)) ? preCategory : "";
    if (preCat) catSel.value = preCat;
    tplSel.innerHTML = tplOptionsFor(preCat);

    catSel.addEventListener("change", () => {
        tplSel.innerHTML = tplOptionsFor(catSel.value);
    });
}

/**
 * Crea un'istanza fork dal template selezionato. Usa Titolo come instance_label
 * e Topic/NumArg come numero. POST /api/risdoc/templates/{id}/instances.
 * (Logica ereditata da openInstanceModal, ora assorbita nel modal unico.)
 */
async function createForkInstance(form, _type, _preCategory) {
    const fd = new FormData(form);
    const title  = (fd.get("title") || "").trim();
    const numarg = (fd.get("topic") || "").trim();
    const role   = (fd.get("doc_role") || "D").toString().toUpperCase();
    const tplId  = parseInt(fd.get("template_id") || "0", 10);
    if (!title)  throw new Error("Inserisci un Titolo (etichetta del documento).");
    if (!tplId)  throw new Error("Seleziona un template istituzionale da forkare.");
    const label = numarg !== "" ? `${numarg} ${title}` : title;
    const csrf = await fetchCsrf();
    try { localStorage.setItem(`fm.risdoc.role.${tplId}`, role); } catch {}

    // ADR-026 #2 — Storage unificato: il fork da modello istituzionale ora crea
    // un teacher_content (custom unificato) con body_pt = master del template.
    // Il docente lo edita/salva esattamente come un custom (stesso engine + formato).
    // Backward-compat: in caso di fallimento cade su /instances legacy.
    // Il body_pt è ottenuto via opt.dataset.bodyPt che populateTemplatePicker
    // imposta (master from DB con with_body_pt=1, Step 4 backfill).
    let bodyPt = null;
    try {
        const opt = form.querySelector(`select[name="template_id"] option[value="${tplId}"]`)
                 || document.querySelector(`.fm-modal-fork-tpl option[value="${tplId}"]`)
                 || document.querySelector(`.fm-modal-template-select option[value="${tplId}"]`);
        if (opt?.dataset?.bodyPt) bodyPt = JSON.parse(opt.dataset.bodyPt);
    } catch (_) { /* best-effort */ }

    if (Array.isArray(bodyPt) && bodyPt.length) {
        const subj = document.getElementById("sel-mater")?.value
                  || document.getElementById("sel-subj")?.value || "";
        const ind  = document.getElementById("sel-iis")?.value || "";
        const cls  = document.getElementById("sel-cls")?.value || "";
        const res = await fetch("/api/teacher/content", {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({
                _csrf: csrf,
                type: "risdoc",
                title,
                topic: numarg || title,
                subject: subj,
                indirizzo: ind,
                classe: cls,
                metadata: JSON.stringify({
                    body_pt: bodyPt,
                    model_template_id: tplId,
                    doc_role: role,
                    // ADR-026 model-sync: snapshot del master a tempo di fork +
                    // timestamp dell'ultima sincronizzazione (= ora). Servono per:
                    // - badge "modello aggiornato" (confronto vs master.updated_at)
                    // - merge intelligente 3-way (baseline vs master_new vs current)
                    model_body_pt_baseline: bodyPt,
                    model_synced_at: new Date().toISOString(),
                }),
                visibility: "draft",
            }).toString(),
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok && data.ok) {
            window.FM?.ToastManager?.show?.("success", "Documento creato",
                `${label} (id ${data.id})`, 3000);
            return data.id;
        }
        console.warn("[fork] teacher_content fallito, fallback /instances:", data.error || res.status);
    }

    // Fallback legacy: /instances (risdoc instance store).
    const r = await fetch(`/api/risdoc/templates/${tplId}/instances`, {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ _csrf: csrf, instance_label: label, doc_role: role }).toString(),
    });
    const j = await r.json();
    if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
    window.FM?.ToastManager?.show?.("success", "Istanza creata",
        `${label} (${j.instance_key || "ok"})`, 3000);
    return j.instance_key;
}

// ─────── Phase G3.b/review — doc_mode toggle unificato ───────

/**
 * Mostra/nasconde le sezioni `.fm-modal-doc-section[data-fm-doc-mode=X]`
 * in base al radio `doc_mode` selezionato. La sezione ".fm-modal-doc-section"
 * con data-fm-doc-mode matchante viene `hidden=false`, le altre `hidden=true`.
 *
 * Il required del campo href (dentro la sezione "link") viene impostato
 * di conseguenza per validazione browser-side.
 */
function wireDocModeToggle(modal) {
    const radios = modal.querySelectorAll('input[name="doc_mode"]');
    if (!radios.length) return;
    const sections = modal.querySelectorAll('.fm-modal-doc-section[data-fm-doc-mode]');
    const hrefInput = modal.querySelector('input[name="href"]');

    function update() {
        const checked = modal.querySelector('input[name="doc_mode"]:checked');
        const value = checked?.value || "";
        sections.forEach((s) => {
            s.hidden = (s.getAttribute("data-fm-doc-mode") !== value);
        });
        if (hrefInput) {
            // href e' required SOLO se doc_mode=link (per type=mappa).
            // Per non-mappa con href fuori dal fieldset, non e' required.
            if (value === "link") hrefInput.setAttribute("required", "");
            else                  hrefInput.removeAttribute("required");
        }
    }
    radios.forEach((r) => r.addEventListener("change", update));
    update();
}

function wireMappaUpload(modal) {
    const dropZone = modal.querySelector(".fm-drop-zone");
    if (!dropZone) return;
    const input  = dropZone.querySelector('input[name="map_file"]');
    const status = dropZone.querySelector(".fm-drop-status");
    const pick   = dropZone.querySelector(".fm-drop-pick");

    function show(file) {
        if (!file) return;
        const kb = (file.size / 1024).toFixed(1);
        status.textContent = `📄 ${file.name} (${kb} KB) — ${file.type || 'tipo sconosciuto'}`;
    }

    pick?.addEventListener("click", () => input.click());
    input?.addEventListener("change", () => show(input.files?.[0]));

    ["dragenter", "dragover"].forEach((ev) =>
        dropZone.addEventListener(ev, (e) => { e.preventDefault(); dropZone.classList.add("fm-drop-zone--over"); })
    );
    ["dragleave", "drop"].forEach((ev) =>
        dropZone.addEventListener(ev, () => dropZone.classList.remove("fm-drop-zone--over"))
    );
    dropZone.addEventListener("drop", (e) => {
        e.preventDefault();
        const file = e.dataTransfer?.files?.[0];
        if (!file || !input) return;
        // Sostituisce la FileList del input con la nostra (anche se input
        // FileList e' read-only, possiamo usare DataTransfer trick).
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        show(file);
    });
}

function wireMappaDrawioEmbed(modal) {
    const openBtn = modal.querySelector(".fm-modal-drawio-open");
    if (!openBtn) return;
    const status = modal.querySelector(".fm-modal-drawio-status");
    const xmlInput = modal.querySelector('input[name="map_xml"]');

    openBtn.addEventListener("click", () => {
        // Phase G3.b/review2 — `embed.diagrams.net` e' il subdomain dedicato
        // all'embedding (frame-ancestors permissivo). `app.diagrams.net` ha
        // frame-ancestors restrittivo (Teams/SharePoint only) e rifiuterebbe
        // l'iframe. Il nostro CSP frame-src include gia' embed.diagrams.net
        // (vedi SecurityHeadersMiddleware). Se il browser blocca CSP qui:
        // PHP opcache potrebbe servire la versione vecchia → Apache restart
        // o attendere opcache.revalidate_freq.
        const overlay = document.createElement("div");
        overlay.className = "fm-drawio-overlay";
        overlay.innerHTML = `
            <iframe class="fm-drawio-iframe" allow="fullscreen"
                src="https://embed.diagrams.net/?embed=1&proto=json&ui=kennedy&dark=0&saveAndExit=1&noSaveBtn=0&libraries=1"></iframe>
            <button type="button" class="fm-drawio-close" aria-label="Chiudi">✖</button>`;
        document.body.appendChild(overlay);

        const iframe = overlay.querySelector("iframe");
        const close = overlay.querySelector(".fm-drawio-close");
        close.addEventListener("click", () => {
            window.removeEventListener("message", onMsg);
            overlay.remove();
        });

        // Bus postMessage embed.diagrams.net (proto=json):
        //   - {event:'init'}       → noi rispondiamo con load XML vuoto
        //   - {event:'save', xml}  → salviamo nel campo nascosto + chiudiamo
        //   - {event:'exit'}       → chiudiamo senza salvare
        function onMsg(e) {
            if (e.source !== iframe.contentWindow) return;
            let data;
            try { data = JSON.parse(e.data); } catch { return; }
            if (data?.event === "init") {
                // Mappa vuota di partenza. Si potrebbe seedare da template.
                iframe.contentWindow.postMessage(JSON.stringify({ action: "load", xml: "" }), "*");
            } else if (data?.event === "save") {
                if (xmlInput) xmlInput.value = data.xml || "";
                if (status) status.textContent = `✅ Mappa salvata (${(data.xml || "").length} char). Pronta per "Crea".`;
                window.removeEventListener("message", onMsg);
                overlay.remove();
            } else if (data?.event === "exit") {
                window.removeEventListener("message", onMsg);
                overlay.remove();
            }
        }
        window.addEventListener("message", onMsg);
    });
}

async function createMappaWithBlob(form, mappaMode, preSubject, sectionKey = "") {
    const ind  = document.getElementById("sel-iis")?.value || "";
    const cls  = document.getElementById("sel-cls")?.value || "";
    const subj = preSubject || document.getElementById("sel-mater")?.value || "";
    if (!ind || !cls || !subj) {
        throw new Error("Seleziona indirizzo, classe e materia prima.");
    }
    const csrf = await fetchCsrf();
    const fd = new FormData();
    fd.set("_csrf", csrf);
    fd.set("mode", mappaMode);
    if (sectionKey) fd.set("section_key", sectionKey);  // ADR-027 — ancoraggio sezione
    fd.set("title",      form.elements.title.value || "");
    fd.set("topic",      form.elements.topic.value || "");
    fd.set("subject",    subj);
    fd.set("indirizzo",  ind);
    fd.set("classe",     cls);
    fd.set("visibility", form.elements.visibility?.value || "draft");

    if (mappaMode === "upload") {
        const file = form.elements.map_file?.files?.[0];
        if (!file) throw new Error("Nessun file selezionato.");
        fd.set("file", file);
    } else if (mappaMode === "drawio_native") {
        const xml = (form.elements.map_xml?.value || "").trim();
        if (!xml) throw new Error("Apri l'editor drawio e salva prima di continuare.");
        fd.set("xml", xml);
    }

    const r = await fetch("/api/maps", {
        method: "POST",
        credentials: "same-origin",
        headers: { "X-CSRF-Token": csrf, "Accept": "application/json" },
        body: fd,
    });
    const j = await r.json();
    if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
    window.FM?.ToastManager?.show?.("success", "Mappa creata",
        `id=${j.id} · ${j.size}B · ${j.origin}`, 3000);
    window.FM?.clearTeacherContentCache?.();
    return j.id;
}

// Phase 24.44 — seed body_pt per layout="exercises".
export function exercisesSeedPt() {
    const block = (text) => ({
        _type: "block",
        style: "normal",
        children: [{ _type: "span", text, marks: [] }],
    });
    const header = (text, level = 1) => ({ _type: "sectionHeader", level, text });
    return [
        header("Esercizi per studenti"),
        block(""),
        header("Verifiche"),
        block(""),
    ];
}
