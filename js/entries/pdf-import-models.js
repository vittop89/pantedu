/**
 * Phase PDF-Import — pagina "Modelli per operazione" (/teacher/pdf-import/models).
 *
 * Tre sezioni distinte per non essere caotica:
 *   1) Impostazioni  — toggle cache risposte LLM.
 *   2) Modelli       — provider + modello per ogni operazione (compatto).
 *   3) Prompt        — prompt di sistema, raggruppati e COLLASSATI (le soluzioni,
 *                      11 tipi, stanno in un unico gruppo richiudibile).
 */
import { getProviderOperations, saveProviderOperation, saveProviderPrompt, toggleCache, getProviderModels } from "../modules/pdf-import/api.js";
import { openKeyModal } from "../modules/pdf-import/key-modal.js";

const PROV_LABELS = {
    anthropic: "Claude (Anthropic)", openai: "OpenAI", qwen: "Qwen",
    openrouter: "OpenRouter", ollama: "Ollama",
};

// Etichette brevi dei gruppi di prompt (per operazione del modello).
const OP_LABELS = {
    extraction: "Estrazione", difficulty: "Difficoltà", numbers: "Numeri",
    topics: "Argomento", translation: "Traduzione", solutions: "Soluzioni AI",
};

function el(tag, props = {}, children = []) {
    const e = document.createElement(tag);
    Object.assign(e, props);
    for (const c of [].concat(children)) if (c != null) e.appendChild(typeof c === "string" ? document.createTextNode(c) : c);
    return e;
}

(async function init() {
    const root = document.querySelector("[data-fm-models-page]");
    if (!root) return;
    try { if (localStorage.getItem("fm_dark_mode") === "1") document.body.classList.add("fm-dark"); } catch (_) { /* noop */ }

    // scope: 'global' (preset condiviso, solo admin) o 'personal' (override docente),
    // dall'URL ?scope= (i tab in /admin/templates e /area-docente/templates lo passano).
    const SCOPE = new URLSearchParams(location.search).get("scope") || "personal";

    // In iframe (tab dei template) il link "← Torna all'import" non serve.
    const embedded = window.self !== window.top;
    if (embedded) root.querySelectorAll("[data-fm-back]").forEach((a) => a.remove());

    const list = root.querySelector("[data-fm-models-list]");
    const msgEl = root.querySelector("[data-fm-models-msg]");
    const setMsg = (t, err = false) => {
        if (!msgEl) return;
        msgEl.hidden = false; msgEl.textContent = t;
        msgEl.classList.toggle("fm-pdfimport__status--error", !!err);
    };

    let data;
    try { data = await getProviderOperations(SCOPE); }
    catch (e) { list.textContent = "Errore nel caricamento: " + (e?.message || "?"); return; }
    const isGlobal = data.scope === "global";

    const ops = data.operations || {};
    const providers = data.available || [];
    const prompts = data.prompts || {};

    // Datalist coi modelli vision LIVE di OpenRouter (autocomplete nel campo modello).
    let orModels = [];
    try { const r = await getProviderModels("openrouter"); orModels = (r.models || []).map((m) => m.id); } catch (_) { /* noop */ }
    const dl = el("datalist", { id: "fm-or-models" });
    for (const m of orModels) dl.appendChild(el("option", { value: m }));
    root.appendChild(dl);

    // Quali prompt appartengono a ciascuna operazione (le soluzioni ne hanno 11).
    const PROMPT_MAP = {
        extraction:  ["extraction"],
        difficulty:  ["difficulty"],
        numbers:     ["numbers"],
        topics:      ["topics"],
        translation: ["translation"],
        solutions:   ["solutions_algebra", "solutions_fratta", "solutions_irrazionale", "solutions_valore_assoluto", "solutions_sistema", "solutions_disequazione", "solutions_esponenziale", "solutions_logaritmica", "solutions_physics", "solutions_theory", "solutions_vf"],
    };

    // Titolo di sezione.
    const sectionTitle = (t, sub) => el("div", { style: "margin:1.6rem 0 .6rem" }, [
        el("div", { className: "fm-pdfimport__oplabel", style: "font-size:1.05rem;font-weight:700" }, [t]),
        sub ? el("div", { className: "fm-pdfimport__sub", style: "margin:.15rem 0 0" }, [sub]) : null,
    ]);

    // Blocco prompt (textarea + Salva + Ripristina) per una chiave.
    const promptBlock = (key) => {
        const pinfo = prompts[key];
        if (!pinfo) return null;
        const det = el("details", { className: "fm-pdfimport__promptbox" });
        const summaryText = () => "Prompt: " + (pinfo.label || key) + (pinfo.overridden ? "  ✎ personalizzato" : "");
        const sum = el("summary", {}, [summaryText()]);
        det.appendChild(sum);
        const ta = el("textarea", { className: "fm-pdfimport__input", value: pinfo.value || "", rows: 12 });
        ta.style.width = "100%"; ta.style.fontFamily = "ui-monospace, monospace"; ta.style.fontSize = ".8rem";
        const saveB = el("button", { type: "button", className: "fm-pdfimport__btn fm-pdfimport__btn--primary fm-pdfimport__btn--sm", textContent: "Salva prompt" });
        const resetB = el("button", { type: "button", className: "fm-pdfimport__btn fm-pdfimport__btn--sm", textContent: "Ripristina default" });
        saveB.addEventListener("click", async () => {
            saveB.disabled = true; setMsg("Salvataggio prompt…");
            try { await saveProviderPrompt(key, ta.value, SCOPE); pinfo.overridden = true; sum.textContent = summaryText(); setMsg("✓ Prompt salvato: " + (pinfo.label || key)); }
            catch (e) { setMsg("Errore: " + (e?.message || "salvataggio fallito"), true); }
            saveB.disabled = false;
        });
        resetB.addEventListener("click", async () => {
            resetB.disabled = true;
            try { await saveProviderPrompt(key, "", SCOPE); ta.value = pinfo.default || ""; pinfo.overridden = false; sum.textContent = summaryText(); setMsg("↺ Default ripristinato: " + (pinfo.label || key)); }
            catch (e) { setMsg("Errore: " + (e?.message || "ripristino fallito"), true); }
            resetB.disabled = false;
        });
        det.appendChild(el("div", { className: "fm-pdfimport__log-body" }, [
            ta, el("div", { className: "fm-pdfimport-modal__actions" }, [saveB, resetB]),
        ]));
        return det;
    };

    // Riga modello (provider + modello + salva) per un'operazione.
    const modelRow = (op, info) => {
        const provSel = el("select", { className: "fm-pdfimport__select" });
        provSel.appendChild(el("option", { value: "", textContent: "(provider dell'import)" }));
        for (const p of providers) provSel.appendChild(el("option", { value: p, textContent: PROV_LABELS[p] || p }));
        provSel.value = info.provider || "";

        const modelInp = el("input", {
            type: "text", className: "fm-pdfimport__input", autocomplete: "off",
            placeholder: "(modello default del provider)", value: info.model || "",
        });
        modelInp.setAttribute("list", "fm-or-models");

        const saveBtn = el("button", {
            type: "button", className: "fm-pdfimport__btn fm-pdfimport__btn--primary fm-pdfimport__btn--sm",
            textContent: "Salva",
        });
        saveBtn.addEventListener("click", async () => {
            saveBtn.disabled = true; setMsg("Salvataggio…");
            try { await saveProviderOperation(op, provSel.value, modelInp.value.trim(), SCOPE); setMsg("✓ Modello salvato: " + (info.label || op)); }
            catch (e) { setMsg("Errore: " + (e?.message || "salvataggio fallito"), true); }
            saveBtn.disabled = false;
        });
        return el("div", { className: "fm-pdfimport__opcard" }, [
            el("div", { className: "fm-pdfimport__oplabel", textContent: info.label || op }),
            el("div", { className: "fm-pdfimport__oprow" }, [provSel, modelInp, saveBtn]),
        ]);
    };

    list.replaceChildren();

    // Banner di scope: chiarisce SE si modifica il preset globale (admin) o il
    // proprio override (docente). Le chiavi API sono SEMPRE personali.
    const banner = el("div", { className: "fm-pdfimport__notice fm-pdfimport__notice--info", style: "margin:0 0 1rem" });
    if (isGlobal) {
        banner.append(
            el("strong", { textContent: "Preset globale (admin). " }),
            document.createTextNode("Modelli e prompt che salvi qui sono il DEFAULT per TUTTI i docenti. Ogni docente può poi personalizzarli per sé. Le chiavi API restano personali di ciascuno."),
        );
    } else {
        banner.append(
            el("strong", { textContent: "Le tue impostazioni personali. " }),
            document.createTextNode("Modelli e prompt partono dal preset condiviso dell'istituto: modificali solo per te (svuota un campo per tornare al preset). La chiave API e la cache sono solo tue."),
        );
    }
    list.appendChild(banner);

    // ── 1) IMPOSTAZIONI: chiave API + cache (solo personale). ───────────────
    if (!isGlobal) {
        list.appendChild(sectionTitle("Impostazioni"));
        // Chiave API personale (stesso popup della pagina di import).
        const keyBtn = el("button", { type: "button", className: "fm-pdfimport__btn fm-pdfimport__btn--sm", textContent: "⚙ Chiavi LLM (le tue)" });
        keyBtn.addEventListener("click", () => openKeyModal());
        list.appendChild(el("div", { className: "fm-pdfimport__opcard", style: "flex-direction:row;align-items:center;gap:.6rem" }, [
            keyBtn,
            el("div", { className: "fm-pdfimport__sub", style: "margin:0" }, ["La chiave API del provider è SOLO tua (cifrata). Configurala qui o dalla pagina di import."]),
        ]));
        const cacheCb = el("input", { type: "checkbox" });
        cacheCb.checked = !!data.cache_enabled;
        cacheCb.addEventListener("change", async () => {
            cacheCb.disabled = true;
            try { await toggleCache(cacheCb.checked); setMsg(cacheCb.checked ? "✓ Cache ATTIVA (non ri-paga lo stesso PDF+modello)" : "Cache disattivata"); }
            catch (e) { setMsg("Errore: " + (e?.message || "?"), true); cacheCb.checked = !cacheCb.checked; }
            cacheCb.disabled = false;
        });
        list.appendChild(el("label", { className: "fm-pdfimport__opcard", style: "flex-direction:row;align-items:center;gap:.6rem;cursor:pointer" }, [
            cacheCb,
            el("div", {}, [
                el("div", { className: "fm-pdfimport__oplabel", textContent: "Cache risposte LLM" }),
                el("div", { className: "fm-pdfimport__sub", style: "margin:0", textContent: "Se attiva, ri-estrarre lo stesso PDF con lo stesso modello/prompt NON ri-chiama l'LLM (0 token). Utile per i test." }),
            ]),
        ]));
    }

    // ── 2) MODELLI per operazione (compatto, niente prompt qui). ────────────
    list.appendChild(sectionTitle("Modelli per operazione", "Provider + modello per ogni operazione (vuoto = default dell'import)."));
    const modelsWrap = el("div", { className: "fm-pdfimport__ops" });
    for (const [op, info] of Object.entries(ops)) modelsWrap.appendChild(modelRow(op, info));
    list.appendChild(modelsWrap);

    // ── 3) PROMPT di sistema (raggruppati + collassati). ────────────────────
    list.appendChild(sectionTitle("Prompt di sistema", "Modificabili senza deploy (vuoto = ripristina default). I prompt soluzioni sono raccolti in un unico gruppo."));
    const promptsWrap = el("div", { className: "fm-pdfimport__ops" });
    for (const [op, keys] of Object.entries(PROMPT_MAP)) {
        const blocks = keys.map(promptBlock).filter(Boolean);
        if (!blocks.length) continue;
        if (blocks.length === 1) {
            promptsWrap.appendChild(blocks[0]); // singolo → box diretto
        } else {
            // gruppo (es. Soluzioni: 11 tipi) → un solo <details> contenitore.
            const grp = el("details", { className: "fm-pdfimport__promptbox fm-pdfimport__promptgroup" });
            grp.appendChild(el("summary", {}, [`${OP_LABELS[op] || op} — ${blocks.length} prompt per tipo di esercizio`]));
            grp.appendChild(el("div", { className: "fm-pdfimport__log-body", style: "display:flex;flex-direction:column;gap:.5rem" }, blocks));
            promptsWrap.appendChild(grp);
        }
    }
    list.appendChild(promptsWrap);
})();
