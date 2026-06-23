/**
 * Phase G19.12 — Wizard "Crea esercizio".
 *
 * Sostituisce il flow legacy a 3 step:
 *   1. Pick `.tipoEsercizio` o `.tipoEsercizio_ver` (decide target)
 *   2. (Solo per esercizi) Pick origin via `.dropdown_gen` "Seleziona origine"
 *   3. Click `.half-moon-button` per confermare
 *
 *  Il wizard accorpa tutto in un modal flottante con stato chiaro:
 *    1. **Target** radio: Esercizi (visibili agli studenti) | Verifica (test)
 *    2. **Tipo** radio cards: Collect | RM | VF
 *    3. **Origine** select autocomplete (auto-suggest l'ultima usata,
 *       sessionStorage `fm-last-origin`)
 *    4. **Categoria** (opzionale): titolo del gruppo (es. "Sistemi lineari")
 *    5. Submit → POST `/api/teacher/content/{id}/group/add` (back-compat con
 *       `addExerciseFromTemplate` esistente in upbar-controls.js)
 *
 *  Per Target=Verifica:
 *   - Se la verifica correlata NON esiste, il wizard chiede conferma e
 *     auto-crea il contract (POST `/api/teacher/content` type=verifica).
 *   - Origine forced a "personal" (replicando la semantica legacy).
 *
 *  Auth/CSRF: il wizard riusa fetchCsrf da core/dom-utils + il middleware
 *  CSRF dei content endpoints. Optimistic locking via `If-Match: "v{N}"`.
 */

import { esc, fetchJson, fetchCsrf } from "../core/dom-utils.js";

const MODAL_ID = "fm-exercise-wizard-modal";
const STORAGE_LAST_ORIGIN = "fm-last-origin";

function toast(kind, msg) {
    if (window.FM?.ToastManager?.show) {
        window.FM.ToastManager.show(kind, kind === "err" ? "Errore" : "OK", msg, 3500);
    } else {
        console.info(`[exercise-wizard] ${kind}: ${msg}`);
    }
}

/** Rileva origini disponibili da sources.json del docente.
 *  Usa memoFetchJson per dedup + TTL cache cross-modulo. */
async function listAvailableOrigins() {
    let common;
    try {
        common = await window.FM.memoFetchJson("/api/teacher/sources.json");
    } catch (_) { /* ignore */ }
    const out = [];
    const sources = common?.sources || {};
    for (const code of Object.keys(sources)) {
        const s = sources[code] || {};
        const label = s.title || code;
        out.push({ code, label });
    }
    out.sort((a, b) => a.code.localeCompare(b.code));
    return out;
}

function getLastOrigin() {
    try { return sessionStorage.getItem(STORAGE_LAST_ORIGIN) || ""; }
    catch (_) { return ""; }
}

function setLastOrigin(origin) {
    try { sessionStorage.setItem(STORAGE_LAST_ORIGIN, origin); } catch (_) {}
}

/** Trova il container target corrente (replica logica
 *  upbar-controls.findTargetContainer). */
function findTargetContainer(isVer) {
    if (isVer) {
        return document.querySelector('.fm-contract-wrap[data-kind="verifica"] .fm-contract-render')
            || document.querySelector('#type_verAll .fm-contract-render')
            || document.querySelector('#type_verAll');
    }
    const all = document.querySelectorAll(".fm-contract-render");
    for (const el of all) {
        if (!el.closest("#type_verAll")) return el;
    }
    return null;
}

/** Esiste già una verifica correlata per il topic corrente? */
function hasVerificaForTopic() {
    return !!document.querySelector('#type_verAll .fm-contract-wrap[data-id]');
}

/** UI: costruisce la modal del wizard. */
function buildModal(origins) {
    const m = document.createElement("div");
    m.id = MODAL_ID;
    m.className = "fm-modal-backdrop fm-exercise-wizard-modal";
    const lastOrigin = getLastOrigin();
    const verificaExists = hasVerificaForTopic();

    const originsOpts = origins.length === 0
        ? '<option value="" disabled selected>Nessuna origine disponibile — aggiungi una fonte prima</option>'
        : origins.map(o => {
            const sel = o.code === lastOrigin ? " selected" : "";
            return `<option value="${esc(o.code)}"${sel}>${esc(o.code)} — ${esc(o.label)}</option>`;
        }).join("");

    m.innerHTML = `
        <div class="fm-modal fm-exercise-wizard" role="dialog" aria-modal="true" aria-labelledby="fm-ew-title">
            <button type="button" class="fm-modal-close" data-action="close" aria-label="Chiudi">×</button>
            <h3 id="fm-ew-title">Crea gruppo di esercizi</h3>
            <p class="fm-muted fm-ew-intro">
                Un "gruppo" è un <code>.fm-groupcollex</code> contenente uno o più quesiti
                (collex-item). Sceglie qui dove va, che tipologia di quesiti ospita
                e da quale fonte derivano.
            </p>

            <div class="fm-ew-section">
                <div class="fm-ew-section-title">1. Dove va il gruppo?</div>
                <label class="fm-ew-radio-card">
                    <input type="radio" name="target" value="esercizio" checked>
                    <span class="fm-ew-card-icon">📚</span>
                    <span class="fm-ew-card-body">
                        <strong>Esercizi</strong>
                        <span class="fm-muted">Visibili agli studenti nello studio del topic</span>
                    </span>
                </label>
                <label class="fm-ew-radio-card">
                    <input type="radio" name="target" value="verifica">
                    <span class="fm-ew-card-icon">📝</span>
                    <span class="fm-ew-card-body">
                        <strong>Verifica</strong>
                        <span class="fm-muted">${verificaExists
                            ? "Aggiunge alla verifica correlata esistente"
                            : "Crea una nuova verifica per questo topic (richiesta conferma)"}</span>
                    </span>
                </label>
            </div>

            <div class="fm-ew-section">
                <div class="fm-ew-section-title">2. Tipologia quesiti del gruppo</div>
                <label class="fm-ew-radio-card">
                    <input type="radio" name="type" value="type_Collect-1" checked>
                    <span class="fm-ew-card-icon">📝</span>
                    <span class="fm-ew-card-body">
                        <strong>Collezione</strong>
                        <span class="fm-muted">Quesiti standard: enunciato + soluzione libera</span>
                    </span>
                </label>
                <label class="fm-ew-radio-card">
                    <input type="radio" name="type" value="type_RMulti-6">
                    <span class="fm-ew-card-icon">🔘</span>
                    <span class="fm-ew-card-body">
                        <strong>Risposta multipla</strong>
                        <span class="fm-muted">Quesiti con 4 opzioni (1 corretta)</span>
                    </span>
                </label>
                <label class="fm-ew-radio-card">
                    <input type="radio" name="type" value="type_VF-1">
                    <span class="fm-ew-card-icon">✅</span>
                    <span class="fm-ew-card-body">
                        <strong>Vero / Falso</strong>
                        <span class="fm-muted">Affermazioni con giustificazione</span>
                    </span>
                </label>
            </div>

            <div class="fm-ew-section fm-ew-origin-section">
                <div class="fm-ew-section-title">3. Origine dei quesiti</div>
                <select id="fm-ew-origin" class="fm-ew-select">
                    ${originsOpts}
                </select>
                <p class="fm-muted fm-ew-hint">
                    L'origine identifica la fonte (libro/edizione) dei quesiti del gruppo.
                    Per le verifiche viene forzata a <strong>"personal"</strong>.
                </p>
            </div>

            <div class="fm-ew-section">
                <div class="fm-ew-section-title">4. Titolo del gruppo (opzionale)</div>
                <input type="text" id="fm-ew-category" class="fm-ew-input"
                    placeholder="es. Sistemi lineari, Equazioni di 2° grado...">
                <p class="fm-muted fm-ew-hint">
                    Diventa il titolo della <code>.fm-collapsible</code> visibile sopra
                    il gruppo. Lascia vuoto per usare il default ("Nuovo esercizio").
                </p>
            </div>

            <div class="fm-modal-actions">
                <button type="button" class="fm-btn" data-action="close">Annulla</button>
                <button type="button" class="fm-btn fm-btn-primary" data-action="create">Crea</button>
            </div>
        </div>`;
    return m;
}

function close(modal) {
    if (!modal) return;
    modal.classList.remove("fm-modal--visible");
    setTimeout(() => modal.remove(), 150);
}

/** Auto-create verifica contract se non esiste. Replica
 *  upbar-controls.autoCreateVerificaContainer ma senza il window.confirm()
 *  (la conferma è già nel wizard tramite radio + visual hint). */
async function autoCreateVerifica() {
    const m = location.pathname.match(/^\/studio\/esercizio\/([^/]+)\/([^/]+)\/([^/]+)\/(.+)$/);
    if (!m) {
        toast("err", "Verifica auto-create supportata solo su /studio/esercizio/...");
        return null;
    }
    const [, ind, cls, subj] = m;
    const title = document.querySelector(".fm-contract-render .fm-titolo h1")?.textContent?.trim()
               || document.querySelector(".fm-pagestyle .fm-titolo h1")?.textContent?.trim();
    if (!title) {
        toast("err", "Title topic non trovato");
        return null;
    }
    const csrf = await fetchCsrf();
    const fd = new URLSearchParams();
    fd.set("_csrf", csrf);
    fd.set("type", "verifica");
    fd.set("indirizzo", ind);
    fd.set("classe", cls);
    // G24.fix — il controller store() legge $req->post['subject'] (NON
    // 'subject_code'): mandare 'subject_code' lasciava subject vuoto →
    // invalid_subject_code. Allineato a createContent (sidepage-modal-content).
    fd.set("subject", subj);
    fd.set("title", title);
    try {
        const j = await fetchJson("/api/teacher/content", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: fd.toString(),
        });
        if (!j.ok) {
            toast("err", `Verifica auto-create fallita: ${j?.error || "errore"}`);
            return null;
        }
        // Reload verifica correlata (riusa flow esistente verifica-builder)
        if (typeof window.FM?.reloadRelatedVerifica === "function") {
            await window.FM.reloadRelatedVerifica();
        }
        return findTargetContainer(true);
    } catch (e) {
        toast("err", `Errore di rete: ${e.message || e}`);
        return null;
    }
}

/** Submit del wizard. Replica `addExerciseFromTemplate` ma col target
 *  scelto dal radio. */
async function submitWizard(modal) {
    const target  = modal.querySelector('input[name="target"]:checked')?.value || "esercizio";
    const typeVal = modal.querySelector('input[name="type"]:checked')?.value || "type_Collect-1";
    const isVer   = target === "verifica";
    let origin    = modal.querySelector("#fm-ew-origin")?.value || "";
    const category = modal.querySelector("#fm-ew-category")?.value?.trim() || "";

    if (isVer) {
        // Replica semantica legacy: per verifica origin = "personal"
        origin = "personal";
    } else if (!origin) {
        toast("warn", "Seleziona un'origine prima di creare un esercizio");
        return;
    }

    let container = findTargetContainer(isVer);
    if (!container && isVer) {
        container = await autoCreateVerifica();
    }
    if (!container) {
        toast("err",
            isVer ? "Container verifica non disponibile"
                  : "Container esercizi non disponibile (controlla la pagina)");
        return;
    }
    const wrap = container.closest(".fm-contract-wrap[data-id]");
    const contractId = wrap?.dataset?.id;
    if (!/^\d+$/.test(contractId || "")) {
        toast("err", "Contratto non persistito: impossibile aggiungere esercizio");
        return;
    }
    const version = parseInt(wrap.dataset.version || "0", 10) || 0;
    const clientId = `local_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`;

    try {
        const csrf = await fetchCsrf();
        const fd = new URLSearchParams();
        fd.set("_csrf", csrf);
        fd.set("type", typeVal.replace(/-\d+$/, "") || "Collect");
        fd.set("clientId", clientId);
        if (origin) fd.set("origin", origin);
        if (category) fd.set("title", category);
        const j = await fetchJson(`/api/teacher/content/${contractId}/group/add`, {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
                "If-Match": `"v${version}"`,
            },
            body: fd.toString(),
        });
        if (!j.ok) {
            toast("err", `Creazione fallita: ${j?.error || "errore"}`);
            return;
        }
        if (wrap && Number.isFinite(j.version)) {
            wrap.dataset.version = String(j.version);
        }
        if (typeof j.html === "string" && j.html.length) {
            const tmp = document.createElement("div");
            tmp.innerHTML = j.html;
            const newNode = tmp.firstElementChild;
            if (newNode) {
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
                if (window.MathJax?.typesetPromise) {
                    try { await window.MathJax.typesetPromise([newNode]); } catch (_) {}
                }
                try { window.FM?.populatePositionInputs?.(); } catch (_) {}
                try { window.FM?.populateOriginSelects?.(); } catch (_) {}
                try { window.FM?.UIComp?.caricaGiust?.(); } catch (_) {}

                // Persiste l'origine per auto-suggest al prossimo wizard
                if (origin) setLastOrigin(origin);

                window.dispatchEvent(new CustomEvent("fm:new-exercise-added", {
                    detail: {
                        id: j.groupId, type: typeVal, origin,
                        scope: isVer ? "verifica" : "esercizio",
                        category, element: newNode,
                    },
                }));
                toast("ok", `Gruppo ${typeVal.replace(/^type_/, "").replace(/-\d+$/, "")} creato in ${target}`);
            }
        }
        close(modal);
    } catch (e) {
        toast("err", `Errore di rete: ${e.message || e}`);
    }
}

function wireModal(modal) {
    modal.addEventListener("click", (e) => {
        const btn = e.target.closest("[data-action]");
        if (!btn) {
            // click su backdrop chiude la modal
            if (e.target === modal) close(modal);
            return;
        }
        if (btn.dataset.action === "close") close(modal);
        else if (btn.dataset.action === "create") submitWizard(modal);
    });
    modal.addEventListener("keydown", (e) => {
        if (e.key === "Escape") close(modal);
        else if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) submitWizard(modal);
    });
}

async function openWizard() {
    document.querySelectorAll(`#${MODAL_ID}`).forEach(close);
    const origins = await listAvailableOrigins();
    const m = buildModal(origins);
    document.body.appendChild(m);
    wireModal(m);
    requestAnimationFrame(() => m.classList.add("fm-modal--visible"));
    // Focus iniziale sul primo radio target per a11y
    setTimeout(() => m.querySelector('input[name="target"]:checked')?.focus(), 50);
}

/** Bind del pulsante `#fm-create-exercise-btn` (idempotente). */
function init() {
    if (document.documentElement.dataset.fmExerciseWizardBound === "1") return;
    document.documentElement.dataset.fmExerciseWizardBound = "1";
    document.addEventListener("click", (e) => {
        const btn = e.target.closest("#fm-create-exercise-btn, .fm-create-eser-btn");
        if (!btn) return;
        e.preventDefault();
        openWizard();
    });
}

init();

window.FM = window.FM || {};
window.FM.openExerciseWizard = openWizard;
