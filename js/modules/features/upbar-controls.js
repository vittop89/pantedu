/**
 * Phase 15 — Upbar controls moderni (vanilla, no jQuery).
 *
 * Ripristina handler funzionali master per tutti i btn dell'upbar:
 *   - #btnP            → HideAll Probl (collapse tutti i .fm-collapsible)
 *   - #btnS            → HideAll Soluz (toggle .fm-sol/.fm-giustsol/details[open])
 *   - #toggleExercises → HideAll Eser (toggle .fm-collection__item)
 *   - #sel-dif         → filtra .fm-collection__item per diff{N} class
 *   - #showAllA/B      → ShowChecked-A/R: filtra .fm-groupcollex per .fm-checkbox-ain/Bin:checked
 *   - #selectAllA/B    → CheckAll-A/R: spunta tutti i .fm-checkbox-ain/Bin
 *
 * I checkbox A/B sono iniettati dal .selection template (UIComp._caricaCheckboxABin)
 * all'auto-attivazione di verifica-mode (Phase 21: ensureVerificaMode su
 * body.fm-admin-access). Gli handler usano delegation per operare sui DOM futuri.
 *
 * Idempotente: binding persistenti via dataset.fmbound.
 */

import { fetchJson, fetchCsrf } from "../core/dom-utils.js";

function initUpbarControls() {
    bindCollapseAll();
    bindSolutionsToggle();
    bindExercisesToggle();
    bindDifficultyFilter();
    bindShowCheckedA();
    bindShowCheckedB();
    bindCheckAllA();
    bindCheckAllB();
    bindOriginFilter();
    populateOriginDropdown();
    syncEsercizioMultiargBody();
    bindDropdownToggle();
    bindTipoEsercizio();
    bindOriginGenDropdown();
    bindHeaderPageEdit();
    syncHeaderPageEditBtn();
}

// Phase 20 — ri-sync body.fm-esercizio-multiarg ad ogni navigate (URL change)
window.addEventListener("fm:navigated", syncEsercizioMultiargBody);

/** Phase 16 — template personale #header_page, cache via FM.store + memo-fetch dedup. */
async function loadTeacherHeaderPage({ force = false } = {}) {
    const st = window.FM?.store;
    if (!force) {
        const cached = st?.get("cache.headerPage");
        if (cached) return cached;
    }
    let data = { html: "", auto_citations: true };
    try {
        // memoFetchJson dedup chiamate parallele (MutationObserver triggers
        // renderHeaderPageBody per ogni body class flip = 4× fetch).
        data = await window.FM.memoFetchJson("/api/teacher/header-page.json", { force });
    } catch { /* ignore */ }
    st?.set("cache.headerPage", data);
    return data;
}

async function saveTeacherHeaderPage(payload) {
    const j = await fetchJson("/api/teacher/header-page.json", {
        method: "PUT",
        headers: { "Content-Type": "application/json",
                   "X-CSRF-Token": document.querySelector('meta[name="csrf-token"]')?.content || "" },
        body: JSON.stringify(payload),
    });
    if (j?.error) throw new Error(j?.message || j?.error || "richiesta non riuscita");
    window.FM?.store?.set("cache.headerPage", payload);
    window.FM?.invalidateMemo?.("/api/teacher/header-page.json");
    return j;
}

async function renderHeaderPageBody() {
    const bodies = document.querySelectorAll("#header_page .fm-header-body");
    if (!bodies.length) return;
    const data = await loadTeacherHeaderPage();
    bodies.forEach((el) => { el.innerHTML = data.html || ""; });
    // Dopo render del body → aggrega le citazioni (rispetta auto_citations).
    try { await window.FM?.refreshHeaderPageCitations?.(); } catch (_) {}
}

// Phase 25.Q.16 — switch endpoint header-page in base al ruolo:
//   teacher/admin → /api/teacher/header-page.json (proprio template)
//   student/guest → /api/study/header-page.json (template del docente
//                   di riferimento dell'istituto)
// Override fetch() per redirigere automaticamente lo studente.
(function patchHeaderPageEndpointForRole() {
    if (typeof window === "undefined" || !window.fetch) return;
    const origFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
        const role = document.body?.dataset?.fmRole || "guest";
        if ((role !== "teacher" && role !== "admin") && typeof input === "string"
            && input.startsWith("/api/teacher/header-page.json")) {
            const newUrl = input.replace("/api/teacher/header-page.json", "/api/study/header-page.json");
            return origFetch(newUrl, init);
        }
        return origFetch(input, init);
    };
})();

/** Phase 16 — Inietta `#modHeaderBtn` dentro ogni `#header_page` quando il
 *  docente è admin OR fm-verifica-mode è attivo. Permette edit in-place del
 *  template personale (salvato su /api/teacher/header-page.json). */
function syncHeaderPageEditBtn() {
    const active = document.body.classList.contains("fm-verifica-mode")
                || document.body.classList.contains("fm-admin-access");
    document.querySelectorAll("#header_page").forEach((hp) => {
        let btn = hp.querySelector("#modHeaderBtn");
        if (active && !btn) {
            btn = document.createElement("button");
            btn.type = "button";
            btn.id = "modHeaderBtn";
            btn.title = "Modifica intestazione personale";
            btn.innerHTML = '✏️ <span>Modifica</span>';
            hp.prepend(btn);
        } else if (!active && btn) {
            btn.remove();
        }
    });
    // Render iniziale del body (se vuoto)
    document.querySelectorAll("#header_page .fm-header-body:empty").forEach(() => {
        renderHeaderPageBody().catch(() => {});
    });
}

/** Edit mode: click `#modHeaderBtn` → textarea con HTML + checkbox
 *  `auto_citations` + save button. Click save → PUT e re-render. */
function bindHeaderPageEdit() {
    if (document.documentElement.dataset.fmHeaderEditBound === "1") return;
    document.documentElement.dataset.fmHeaderEditBound = "1";
    new MutationObserver(syncHeaderPageEditBtn).observe(document.body, {
        attributes: true, attributeFilter: ["class"],
    });

    document.addEventListener("click", async (e) => {
        const modBtn = e.target.closest("#modHeaderBtn");
        if (modBtn) {
            e.preventDefault();
            const hp = modBtn.closest("#header_page");
            if (!hp) return;
            await openHeaderEditor(hp);
            return;
        }
    });
}

async function openHeaderEditor(hp) {
    if (hp.querySelector(".fm-header-editor")) return; // già aperto
    const data = await loadTeacherHeaderPage({ force: true });
    const body = hp.querySelector(".fm-header-body");
    if (body) body.style.display = "none";
    hp.classList.add("fm-header-editing");

    const editor = document.createElement("div");
    editor.className = "fm-header-editor";
    editor.innerHTML = `
        <label class="fm-header-field">
            <span>HTML intestazione (tag ammessi: p, br, strong, em, u, ul, ol, li, a)</span>
            <textarea class="fm-header-html" rows="5"></textarea>
        </label>
        <label class="fm-header-auto">
            <input type="checkbox" class="fm-header-auto-cb">
            <span>Inserisci automaticamente le fonti bibliografiche degli esercizi</span>
        </label>
        <div class="fm-header-actions">
            <button type="button" class="fm-header-cancel">Annulla</button>
            <button type="button" class="fm-header-save">Salva</button>
        </div>
    `;
    hp.appendChild(editor);
    const ta = editor.querySelector(".fm-header-html");
    const auto = editor.querySelector(".fm-header-auto-cb");
    ta.value = data.html || "";
    auto.checked = data.auto_citations !== false;

    const close = () => {
        editor.remove();
        if (body) body.style.display = "";
        hp.classList.remove("fm-header-editing");
    };
    editor.querySelector(".fm-header-cancel").addEventListener("click", close);
    editor.querySelector(".fm-header-save").addEventListener("click", async () => {
        const payload = { html: ta.value, auto_citations: auto.checked };
        try {
            await saveTeacherHeaderPage(payload); // setta store.cache.headerPage
            dispatchToast("Intestazione salvata", "ok");
            close();
            await renderHeaderPageBody();
        } catch (err) {
            dispatchToast(`Errore salvataggio: ${err.message}`, "err");
        }
    });
}

/** Phase 16 — click-toggle per `.dropdown_gen` (dentro `.selector-eser` di
 *  #infoVer). Sostituisce il legacy hover-only che collassava infoVer al
 *  cessare dell'hover. Persiste lo stato "open" tramite `.fm-origin-open`:
 *  l'infoVer resta espansa finché il dropdown è aperto.
 *
 *  Click su `.dropdown-content_gen a` → aggiorna label del button + POST
 *  origine sui `.fm-groupcollex` con `.checkboxA/B` spuntate (replica master
 *  /origins/change-origin_problem.php). In refactor useremo il nuovo endpoint
 *  teacher-scoped (placeholder: dispatch evento per admin-ui). */
function bindOriginGenDropdown() {
    if (document.documentElement.dataset.fmOriginGenBound === "1") return;
    document.documentElement.dataset.fmOriginGenBound = "1";

    document.addEventListener("click", (e) => {
        // 1) Click sul button → toggle open
        const btn = e.target.closest(".fm-dropdown-button-gen");
        if (btn) {
            e.preventDefault();
            const dd = btn.closest(".fm-dropdown-gen");
            if (!dd) return;
            // Il content è SIBLING del .dropdown_gen nel DOM legacy.
            const content = dd.nextElementSibling?.classList?.contains("dropdown-content_gen")
                ? dd.nextElementSibling
                : dd.parentNode?.querySelector(".fm-dropdown-content-gen");
            if (!content) return;
            const open = !content.classList.contains("fm-is-open");
            closeAllOriginGen();
            if (open) {
                content.classList.add("fm-is-open");
                content.style.display = "block";
                const infoVer = document.getElementById("infoVer");
                infoVer?.classList.add("fm-origin-open");
                // G19.9 — posiziona il popup INSIDE `.selector-eser` (parent
                // relative) PROPRIO SOTTO il `.dropdown_gen` button, invece di
                // sticking a `left: 0` (che lo metteva sotto `+ nuovo esercizio`).
                positionDropdownContentGen(dd, content);
            }
            return;
        }
        // 2) Click su un link origin → aggiorna button, chiudi dropdown,
        //    emetti evento per i consumer (change-origin su problem checked).
        const link = e.target.closest(".fm-dropdown-content-gen a[data-value]");
        if (link) {
            e.preventDefault();
            const origin = link.dataset.value;
            const content = link.closest(".fm-dropdown-content-gen");
            const btnGen = content?.previousElementSibling?.querySelector?.(".dropdown-button_gen")
                        || document.querySelector(".fm-selector-eser .fm-dropdown-button-gen");
            if (btnGen) btnGen.textContent = origin;
            closeAllOriginGen();
            // Emetti evento: consumer decide se POSTare per-problem ai checked.
            window.dispatchEvent(new CustomEvent("fm:origin-selected", { detail: { origin } }));
            return;
        }
        // 3) Click fuori → chiudi
        if (!e.target.closest(".fm-dropdown-gen, .fm-dropdown-content-gen")) {
            closeAllOriginGen();
        }
    });
}

function closeAllOriginGen() {
    document.querySelectorAll(".fm-dropdown-content-gen.fm-is-open").forEach((el) => {
        el.classList.remove("fm-is-open");
        el.style.removeProperty("display");
        el.style.removeProperty("left");
        el.style.removeProperty("top");
    });
    const infoVer = document.getElementById("infoVer");
    infoVer?.classList.remove("fm-origin-open");
}

/** G19.9 — posiziona `.dropdown-content_gen` SOTTO il `.dropdown_gen`
 *  button, NON a `left:0` del `.selector-eser` (che lo metteva sotto
 *  `+ nuovo esercizio`).
 *
 *  Il popup è absolute positioned dentro `.selector-eser` (relative
 *  parent). Calcoliamo `offsetLeft` del button rispetto al parent e
 *  applichiamo come `left` inline (vince sul CSS rule `left:0`). */
function positionDropdownContentGen(dropdownGen, content) {
    const parent = content.offsetParent;
    if (!parent) return;
    // Bounding rects relative al parent (`.selector-eser`).
    const parentRect = parent.getBoundingClientRect();
    const ddRect = dropdownGen.getBoundingClientRect();
    const left = Math.max(0, ddRect.left - parentRect.left);
    const top  = (ddRect.bottom - parentRect.top) + 4;
    content.style.left = left + "px";
    content.style.top  = top + "px";
}

/** Phase 16 — handler moderno per i select `.tipoEsercizio` (nuovo esercizio)
 *  e `.tipoEsercizio_ver` (nuovo esercizio dentro una verifica). Sostituisce
 *  il monolite legacy `$(document).on("change", ".tipoEsercizio", ...)` che
 *  chiamava /modelli_eser.php + /save_new_exercise.php.
 *
 *  Flusso:
 *    1. Valida origine (label del `.dropdown-button_gen` sibling).
 *    2. Fetch `/modelli_eser.php`, estrai `#<typeID>`, clona e assegna ID
 *       unico `<topic>-<typeID>_<N>-or_<origin>` — replica legacy.
 *    3. Inserisci (prepend) nel `.DraggableContainer_ver` (verifica) o nel
 *       `.fm-draggable-container` (eser).
 *    4. MathJax typeset + emetti `fm:new-exercise-added` per persistenza. */
function bindTipoEsercizio() {
    if (document.documentElement.dataset.fmTipoEsBound === "1") return;
    document.documentElement.dataset.fmTipoEsBound = "1";
    document.addEventListener("change", async (e) => {
        const sel = e.target.closest(".fm-tipo-esercizio, .fm-tipo-esercizio-ver");
        if (!sel) return;
        const value = sel.value;
        if (!value) return;
        const isVer = sel.classList.contains("fm-tipo-esercizio-ver");
        const originBtn = sel.closest(".fm-selector-eser")?.querySelector(".fm-dropdown-button-gen");
        let origin = (originBtn?.textContent || "").trim();
        if (isVer) origin = "personal";
        if (!origin || origin === "Seleziona origine") {
            sel.value = "";
            dispatchToast("Seleziona un'origine prima di aggiungere l'esercizio", "warn");
            return;
        }
        try {
            await addExerciseFromTemplate(value, origin, isVer);
        } catch (err) {
            console.warn("[tipoEsercizio] fail", err);
            dispatchToast(`Errore aggiunta esercizio: ${err.message || err}`, "err");
        } finally {
            sel.value = "";
        }
    }, true);
}

/** Phase 20 — Server-driven add: crea gruppo via
 *  POST /api/teacher/content/{id}/group/add, il backend seeda items
 *  dal template personale del docente + risponde con HTML render
 *  (ContractRenderer). Niente più fetch legacy /modelli_eser.php +
 *  clone HTML + inject controls manuali: il server emette già il
 *  markup completo (.checkIN + .checkmod + .selection + moveBtn).
 *
 *  Container targeting:
 *    - isVer=true  → `.fm-contract-wrap[data-kind="verifica"] .fm-contract-render`
 *                     (dentro `#type_verAll` su /studio/esercizio, o il wrapper
 *                      principale su /studio/verifica/...).
 *    - isVer=false → il primo `.fm-contract-render` NON dentro `#type_verAll`.
 *  Fallback: `.fm-draggable-container` diretto. */
async function addExerciseFromTemplate(typeValue, origin, isVer) {
    let container = findTargetContainer(isVer);
    if (!container && isVer) {
        container = await autoCreateVerificaContainer();
    }
    if (!container) {
        dispatchToast(
            isVer
                ? "Nessuna verifica correlata. Creala via sidepage Verifiche o ritenta."
                : "Container esercizi non trovato",
            "err",
        );
        return;
    }

    const wrap = container.closest(".fm-contract-wrap[data-id]");
    const contractId = wrap?.dataset?.id;
    if (!/^\d+$/.test(contractId || "")) {
        dispatchToast("Contratto non persistito: impossibile aggiungere esercizio", "err");
        return;
    }

    const version = parseInt(wrap.dataset.version || "0", 10) || 0;
    const clientId = `local_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;
    let j;
    try {
        const csrf = await fetchCsrf();
        const fd = new URLSearchParams();
        fd.set("_csrf", csrf);
        fd.set("type", typeValue.replace(/-\d+$/, "") || "Collect");
        fd.set("clientId", clientId);
        j = await fetchJson(`/api/teacher/content/${contractId}/group/add`, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded", "If-Match": `"v${version}"` },
            body: fd.toString(),
        });
        if (!j.ok) {
            dispatchToast(`Creazione fallita: ${j?.error || "errore"}`, "err");
            return;
        }
    } catch (e) {
        dispatchToast(`Errore rete: ${e.message || e}`, "err");
        return;
    }

    if (wrap && Number.isFinite(j.version)) wrap.dataset.version = String(j.version);

    // Inserisce l'HTML del gruppo (reso server-side) in coda ai .fm-groupcollex
    // esistenti — così l'ordine DOM matcha l'ordine DB.
    if (typeof j.html !== "string" || !j.html.length) {
        dispatchToast("Risposta server senza HTML render", "err");
        return;
    }
    const tmp = document.createElement("div");
    tmp.innerHTML = j.html;
    const newNode = tmp.firstElementChild;
    if (!newNode) {
        dispatchToast("HTML render vuoto", "err");
        return;
    }

    const problems = container.querySelectorAll(":scope > .fm-groupcollex");
    const lastProblem = problems[problems.length - 1] || null;
    const titoloEl = container.querySelector(":scope > .fm-titolo");
    if (lastProblem) {
        if (lastProblem.nextSibling) container.insertBefore(newNode, lastProblem.nextSibling);
        else container.appendChild(newNode);
    } else if (titoloEl && titoloEl.nextSibling) {
        container.insertBefore(newNode, titoloEl.nextSibling);
    } else {
        container.appendChild(newNode);
    }

    // Re-init UI per il nuovo nodo: MathJax + position inputs + origin
    // selects + giustifica toggle + VF solution listener.
    if (window.MathJax?.typesetPromise) {
        try { await window.MathJax.typesetPromise([newNode]); } catch (_) { /* ignore */ }
    }
    try { window.FM?.populatePositionInputs?.(); } catch (_) {}
    try { window.FM?.populateOriginSelects?.(); } catch (_) {}
    try { window.FM?.UIComp?.caricaGiust?.(); } catch (_) {}
    try { window.FM?.UIComp?.caricaSol_VF?.(); } catch (_) {}

    window.dispatchEvent(new CustomEvent("fm:new-exercise-added", {
        detail: { id: j.groupId, type: typeValue, origin, scope: isVer ? "verifica" : "esercizio", element: newNode },
    }));
    dispatchToast(`Esercizio ${typeValue} aggiunto (${origin})`, "ok");
}

/**
 * Phase 20 — auto-create verifica contract quando user lancia
 * tipoEsercizio_ver ma non esiste verifica correlata per il topic
 * corrente. Estrae scope+title da URL + h1, conferma con user,
 * POST /api/teacher/content (type=verifica), poi fetch HTML render
 * via loadRelatedVerifica flow.
 */
async function autoCreateVerificaContainer() {
    const m = location.pathname.match(/^\/studio\/esercizio\/([^/]+)\/([^/]+)\/([^/]+)\/(.+)$/);
    if (!m) return null;
    const [, ind, cls, subj] = m;
    const title = document.querySelector(".fm-contract-render .fm-titolo h1")?.textContent?.trim()
               || document.querySelector(".fm-pagestyle .fm-titolo h1")?.textContent?.trim();
    if (!title) return null;

    if (!await window.FM.Dialog.confirm(`Nessuna verifica associata al topic "${title}". Crearne una nuova?`)) return null;

    try {
        const csrf = await fetchCsrf();
        const fd = new URLSearchParams();
        fd.set("_csrf", csrf);
        fd.set("type", "verifica");
        fd.set("subject", subj);
        fd.set("indirizzo", ind);
        fd.set("classe", cls);
        fd.set("topic", title);
        fd.set("title", title);
        fd.set("visibility", "draft");

        const j = await fetchJson("/api/teacher/content", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: fd.toString(),
        });
        if (!j.ok) {
            dispatchToast(`Creazione verifica fallita: ${j.error || "errore"}`, "err");
            return null;
        }
        dispatchToast(`Verifica "${title}" creata (id ${j.id})`, "ok");
        // Trigger re-render related verifiche section
        await window.FM?.loadRelatedVerifica?.();
        // Re-query con force re-fetch
        if (window.FM?.reloadRelatedVerifica) await window.FM.reloadRelatedVerifica();
        return findTargetContainer(true);
    } catch (e) {
        dispatchToast(`Errore creazione: ${e.message || e}`, "err");
        return null;
    }
}

function findTargetContainer(isVer) {
    if (isVer) {
        // Verifica: deve finire DENTRO la verifica correlata (#type_verAll)
        // oppure nel wrapper `[data-kind=verifica]` nel caso di pagina verifica.
        const verWrap = document.querySelector('.fm-contract-wrap[data-kind="verifica"]');
        return verWrap?.querySelector(":scope > .fm-contract-render")
            || verWrap
            || document.getElementById("type_verAll");
    }
    // Esercizio: sempre DENTRO `.fm-draggable-container` (NON dentro #type_verAll).
    // Cerca il .fm-draggable-container a livello pagina (non quello eventualmente
    // wrappato dentro fm-contract-wrap). Fallback: primo .fm-contract-render
    // del wrapper esercizio.
    const drag = [...document.querySelectorAll(".fm-draggable-container")]
        .find((d) => !d.closest("#type_verAll"));
    if (drag) {
        const render = drag.querySelector(".fm-contract-wrap:not([data-kind='verifica']) .fm-contract-render");
        return render || drag;
    }
    const wraps = document.querySelectorAll(".fm-contract-wrap");
    for (const w of wraps) {
        if (w.closest("#type_verAll")) continue;
        if (w.dataset.kind === "verifica") continue;
        return w.querySelector(":scope > .fm-contract-render") || w;
    }
    return null;
}

function dispatchToast(msg, kind = "info") {
    try {
        if (window.ToastManager?.show) {
            // Mappa dei kind brevi → (type supportato da ToastManager.icons, title).
            // ToastManager.icons accetta: loading/success/error/warning/info.
            const map = {
                info:   ["info",    "Info"],
                warn:   ["warning", "Attenzione"],
                err:    ["error",   "Errore"],
                ok:     ["success", "OK"],
            };
            const [t, title] = map[kind] || map.info;
            window.ToastManager.show(t, title, msg, 3500); return;
        }
    } catch (_) { /* fallthrough */ }
    console.log(`[upbar-controls] ${kind}:`, msg);
}

/** Phase 16 — toggle visibilità `.dropdown-content` su click del
 *  `.dropdown-button`. Legacy usava hover (mouseenter/leave) — pattern
 *  brittle su mobile/touch. Click-toggle + click-outside-to-close.
 *
 *  Overflow escape: `.scrollbarUpBar` ha `overflow:hidden` per lo scroll
 *  orizzontale dei controlli upbar, il che clippa il dropdown. Quando
 *  apriamo il dropdown, posizioniamo `.dropdown-content` con
 *  `position:fixed` + coordinate calcolate dal rect del button → il
 *  dropdown ESCAPE dal clipping e può estendersi sopra `#infoVer`.
 *  Al close ripristiniamo gli inline style (fallback al CSS base). */
function bindDropdownToggle() {
    if (document.documentElement.dataset.fmDropdownBound === "1") return;
    document.documentElement.dataset.fmDropdownBound = "1";
    document.addEventListener("click", (e) => {
        const btn = e.target.closest(".dropdown-button");
        if (btn) {
            const dd = btn.closest(".dropdown");
            const content = dd?.querySelector(".fm-dropdown-content");
            if (!content) return;
            e.preventDefault();
            // Chiudi gli altri dropdown aperti + ripristina inline style
            document.querySelectorAll(".fm-dropdown-content.fm-is-open").forEach((el) => {
                if (el !== content) closeDropdownContent(el);
            });
            if (content.classList.contains("fm-is-open")) {
                closeDropdownContent(content);
            } else {
                openDropdownContent(content, btn);
            }
            return;
        }
        // Click-outside: chiudi tutti i dropdown aperti
        if (!e.target.closest(".fm-dropdown-content.fm-is-open") && !e.target.closest(".dropdown-button")) {
            document.querySelectorAll(".fm-dropdown-content.fm-is-open").forEach((el) => closeDropdownContent(el));
        }
    });
    // Riallinea su scroll/resize così il dropdown aperto segue il button.
    const realign = () => {
        document.querySelectorAll(".fm-dropdown-content.fm-is-open").forEach((content) => {
            const btn = content.closest(".dropdown")?.querySelector(".dropdown-button");
            if (btn) positionDropdownContent(content, btn);
        });
    };
    window.addEventListener("scroll", realign, { passive: true });
    window.addEventListener("resize", realign, { passive: true });
}

function openDropdownContent(content, btn) {
    content.classList.add("fm-is-open");
    positionDropdownContent(content, btn);
}

function closeDropdownContent(content) {
    content.classList.remove("fm-is-open");
    content.style.removeProperty("position");
    content.style.removeProperty("top");
    content.style.removeProperty("left");
    content.style.removeProperty("transform");
    content.style.removeProperty("min-width");
    content.style.removeProperty("max-height");
}

function positionDropdownContent(content, btn) {
    const r = btn.getBoundingClientRect();
    content.style.position = "fixed";
    content.style.top = `${Math.round(r.bottom + 2)}px`;
    content.style.left = `${Math.round(r.left + r.width / 2)}px`;
    content.style.transform = "translateX(-50%)";
    content.style.minWidth = `${Math.max(r.width, 80)}px`;
    // Limite verticale: non superare il viewport.
    const available = window.innerHeight - r.bottom - 10;
    content.style.maxHeight = `${Math.max(120, available)}px`;
    content.style.overflowY = "auto";
}

/** Phase 20 — Auto-detect modalità multi-argomento da URL `?ids=N,M,...`
 *  (CSV con 2+ ids) quando siamo in /studio/esercizio/... Applica
 *  `body.fm-esercizio-multiarg` così il CSS nasconde gli esercizi
 *  assegnati agli studenti e mostra solo "Verifiche correlate".
 *
 *  La vecchia checkbox + ARGOMENTI è rimossa: la selezione additive
 *  avviene via Ctrl/Cmd+click nella sidepage (sidepage-highlight). */
function syncEsercizioMultiargBody() {
    const isEsercizio = /^\/studio\/esercizio\//.test(location.pathname);
    let active = false;
    if (isEsercizio) {
        try {
            const ids = new URL(location.href).searchParams.get("ids") || "";
            const parts = ids.split(",").filter((s) => /^\d+$/.test(s));
            active = parts.length >= 2;
        } catch (_) {}
    }
    document.body.classList.toggle("fm-esercizio-multiarg", active);
}

window.FM = window.FM || {};
window.FM.isEsercizioMultiarg = () =>
    document.body.classList.contains("fm-esercizio-multiarg");

function bindCollapseAll() {
    const btn = document.getElementById("btnP");
    if (!btn || btn.dataset.fmbound === "1") return;
    btn.dataset.fmbound = "1";
    let hidden = false;
    btn.addEventListener("click", () => {
        hidden = !hidden;
        document.querySelectorAll(".fm-collapsible").forEach((c) => {
            if (hidden) {
                c.classList.remove("active");
                const content = c.nextElementSibling;
                if (content && content.classList.contains("content")) content.style.maxHeight = "0px";
            } else {
                c.classList.add("active");
                const content = c.nextElementSibling;
                if (content && content.classList.contains("content")) content.style.maxHeight = content.scrollHeight + "px";
            }
        });
        btn.textContent = hidden ? "ShowAll Probl" : "HideAll Probl";
    });
}

function bindSolutionsToggle() {
    const cb = document.getElementById("btnS");
    if (!cb || cb.dataset.fmbound === "1") return;
    cb.dataset.fmbound = "1";
    cb.addEventListener("change", () => {
        const hide = cb.checked;
        document.querySelectorAll(".fm-sol, .fm-giustsol, details.fm-sol").forEach((el) => {
            el.style.display = hide ? "none" : "";
        });
    });
}

function bindExercisesToggle() {
    const cb = document.getElementById("toggleExercises");
    if (!cb || cb.dataset.fmbound === "1") return;
    cb.dataset.fmbound = "1";
    cb.addEventListener("change", () => {
        const hide = cb.checked;
        document.querySelectorAll(".fm-collection__item, .fm-collection").forEach((el) => {
            // Non nascondere se dentro .fm-sol o .fm-giustsol (gestito separatamente)
            if (el.closest(".fm-sol, .fm-giustsol")) return;
            el.style.display = hide ? "none" : "";
        });
    });
}

function bindDifficultyFilter() {
    const dropdown = document.querySelector("#sel-dif .fm-dropdown-content");
    if (!dropdown || dropdown.dataset.fmbound === "1") return;
    dropdown.dataset.fmbound = "1";
    const btnLabel = document.querySelector("#sel-dif .dropdown-button");

    dropdown.addEventListener("click", (e) => {
        const a = e.target.closest("a[data-value]");
        if (!a) return;
        e.preventDefault();
        const val = a.getAttribute("data-value");
        if (btnLabel) btnLabel.textContent = a.textContent.trim() || val;
        const items = document.querySelectorAll(".fm-collection__item");
        items.forEach((it) => {
            if (val === "All") {
                it.style.display = "";
                return;
            }
            const matches = it.classList.contains("diff" + val);
            it.style.display = matches ? "" : "none";
        });
        closeDropdownContent(dropdown);
    });
}

/** ShowChecked-A: filtra .fm-groupcollex/.fm-collection__item per avere .fm-checkbox-ain:checked. */
function bindShowCheckedA() {
    const cb = document.getElementById("showAllA");
    if (!cb || cb.dataset.fmbound === "1") return;
    cb.dataset.fmbound = "1";
    cb.addEventListener("change", () => applyCheckedFilter("A"));
}

/** ShowChecked-R: filtra per .fm-checkbox-bin:checked. */
function bindShowCheckedB() {
    const cb = document.getElementById("showAllB");
    if (!cb || cb.dataset.fmbound === "1") return;
    cb.dataset.fmbound = "1";
    cb.addEventListener("change", () => applyCheckedFilter("B"));
}

/** Applica filtro "mostra solo problemi con checkbox A/B spuntata".
 *  Se showAllA AND showAllB sono entrambi off → mostra tutto.
 *  Se solo uno è on → mostra solo problemi con quel checkbox. Se entrambi →
 *  unione (problemi con A checked O B checked). */
function applyCheckedFilter(_kind) {
    const wantA = document.getElementById("showAllA")?.checked;
    const wantB = document.getElementById("showAllB")?.checked;
    const items = document.querySelectorAll(".fm-collection__item, .fm-groupcollex");
    items.forEach((it) => {
        if (!wantA && !wantB) {
            it.style.display = "";
            return;
        }
        const aChecked = !!it.querySelector(".fm-checkbox-ain:checked");
        const bChecked = !!it.querySelector(".fm-checkbox-bin:checked");
        const show = (wantA && aChecked) || (wantB && bChecked);
        it.style.display = show ? "" : "none";
    });
}

/** CheckAll-A: spunta/despunta tutti i .fm-checkbox-ain nel DOM. */
function bindCheckAllA() {
    const cb = document.getElementById("selectAllA");
    if (!cb || cb.dataset.fmbound === "1") return;
    cb.dataset.fmbound = "1";
    cb.addEventListener("change", () => {
        document.querySelectorAll(".fm-checkbox-ain").forEach((c) => {
            if (c.checked !== cb.checked) {
                c.checked = cb.checked;
                c.dispatchEvent(new Event("change", { bubbles: true }));
            }
        });
    });
}

/** CheckAll-R: spunta/despunta tutti i .fm-checkbox-bin nel DOM. */
function bindCheckAllB() {
    const cb = document.getElementById("selectAllB");
    if (!cb || cb.dataset.fmbound === "1") return;
    cb.dataset.fmbound = "1";
    cb.addEventListener("change", () => {
        document.querySelectorAll(".fm-checkbox-bin").forEach((c) => {
            if (c.checked !== cb.checked) {
                c.checked = cb.checked;
                c.dispatchEvent(new Event("change", { bubbles: true }));
            }
        });
    });
}

/** Carica le origini del docente e popola `#sel-origin .dropdown-content`
 *  con un link per ogni source (stessa lista usata da `.origin` select nei
 *  `.checkIN`). Retry se chiamata con cache vuota (es. login async). */
async function populateOriginDropdown() {
    const root = document.querySelector("#sel-origin .fm-dropdown-content");
    if (!root) return;
    // Se già popolato con >1 elemento (oltre "All"), non ricreare.
    const current = root.querySelectorAll("a[data-value]").length;
    if (current > 1) return;
    try {
        const origins = await window.FM.memoFetchJson("/api/teacher/origins.json");
        if (!Array.isArray(origins) || origins.length === 0) return;
        // Preserva il link "All" esistente
        const allLink = root.querySelector('a[data-value="All"]');
        root.innerHTML = "";
        if (allLink) root.appendChild(allLink);
        else {
            const a0 = document.createElement("a");
            a0.href = "#"; a0.setAttribute("data-value", "All"); a0.textContent = "All";
            root.appendChild(a0);
        }
        for (const o of origins) {
            const a = document.createElement("a");
            a.href = "#";
            a.setAttribute("data-value", o);
            a.textContent = o;
            root.appendChild(a);
        }
    } catch (_) { /* fail silent */ }
}

/** #sel-origin: filtra .fm-collection__item per classe source (es. "mmb_v1_ed3"). */
function bindOriginFilter() {
    const dropdown = document.querySelector("#sel-origin .fm-dropdown-content");
    if (!dropdown || dropdown.dataset.fmbound === "1") return;
    dropdown.dataset.fmbound = "1";
    const btnLabel = document.querySelector("#sel-origin .dropdown-button");

    dropdown.addEventListener("click", (e) => {
        const a = e.target.closest("a[data-value]");
        if (!a) return;
        e.preventDefault();
        const val = a.getAttribute("data-value");
        if (btnLabel) btnLabel.textContent = a.textContent.trim() || val;
        document.querySelectorAll(".fm-collection__item").forEach((it) => {
            if (val === "All") { it.style.display = ""; return; }
            it.style.display = it.classList.contains(val) ? "" : "none";
        });
        closeDropdownContent(dropdown);
    });
}

// Re-init su SPA navigate + cold load
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initUpbarControls);
} else {
    initUpbarControls();
}
window.addEventListener("fm:navigated", initUpbarControls);

window.FM = window.FM || {};
window.FM.initUpbarControls = initUpbarControls;
