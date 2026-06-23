/**
 * Phase PDF-Import — modale (popup) per configurare provider LLM (admin).
 *
 * Per ogni provider si imposta la CHIAVE (salvata server-side, mai rimandata al
 * client) e, opzionale, un MODELLO (override del default .env). Include una
 * mini-guida con i link per reperire chiave e model-ID. Costruito con elementi/
 * textContent (niente innerHTML su dati), niente alert/confirm nativi.
 */
import { getProviderKeys, saveProviderKey, clearProviderKey, getProviderModels } from "./api.js";

const PROVIDERS = [
    { id: "anthropic", label: "Claude (Anthropic)", modelPlaceholder: "claude-opus-4-8 (vuoto = default)" },
    { id: "openai", label: "OpenAI", modelPlaceholder: "gpt-4o (vuoto = default)" },
    { id: "qwen", label: "Qwen (Alibaba Model Studio)", modelPlaceholder: "qwen-vl-max (vuoto = default)" },
    { id: "openrouter", label: "OpenRouter", modelPlaceholder: "es. openai/gpt-4o-mini (vision, veloce)" },
    { id: "ollama", label: "Ollama (LLM locale)", modelPlaceholder: "es. qwen2.5vl:7b", localUrl: true },
];

// Modelli vision suggeriti per provider (selector). "Altro…" → input libero.
const MODELS = {
    anthropic: ["claude-opus-4-8", "claude-sonnet-4-6", "claude-3-5-haiku-latest"],
    openai: ["gpt-4o", "gpt-4o-mini"],
    qwen: ["qwen-vl-max", "qwen-vl-plus", "qwen2.5-vl-72b-instruct"],
    openrouter: [
        "openai/gpt-4o-mini", "openai/gpt-4o",
        "anthropic/claude-3.5-sonnet", "anthropic/claude-3-haiku",
        "qwen/qwen2.5-vl-72b-instruct",
    ],
    ollama: ["qwen2.5vl:7b", "qwen2.5vl:3b", "llama3.2-vision:11b", "llava:13b", "minicpm-v"],
};

// Mini-guida: dove prendere chiave e modelli per ciascun provider.
const GUIDES = {
    anthropic: { key: "console.anthropic.com → Settings → API keys", models: "modelli: claude-opus-4-8, claude-sonnet-4-6" },
    openai: { key: "platform.openai.com/api-keys", models: "modelli vision: gpt-4o, gpt-4o-mini" },
    qwen: { key: "modelstudio.console.alibabacloud.com → API-KEY", models: "modelli: qwen-vl-max, qwen-vl-plus, qwen2.5-vl-72b-instruct" },
    openrouter: { key: "openrouter.ai/keys (chiave sk-or-v1-…)", models: "model-ID (vendor/model) da openrouter.ai/models — VELOCI: openai/gpt-4o-mini, openai/gpt-4o, anthropic/claude-3.5-sonnet, qwen/qwen2.5-vl-72b-instruct (evita i 'plus', lenti)" },
    ollama: { key: "LLM in LOCALE: avvia Ollama (ollama.com) sul tuo PC e metti il Base URL (di solito http://127.0.0.1:11434). Nessuna chiave, nessun costo, dati in casa.", models: "scarica un modello VISION: `ollama pull qwen2.5vl` — poi metti qui es. qwen2.5vl:7b, llava, llama3.2-vision" },
};

function el(tag, props = {}, children = []) {
    const e = document.createElement(tag);
    Object.assign(e, props);
    for (const c of [].concat(children)) {
        if (c == null) continue;
        e.appendChild(typeof c === "string" ? document.createTextNode(c) : c);
    }
    return e;
}

export async function openKeyModal(onSaved) {
    let status = {};
    try {
        const res = await getProviderKeys();
        status = res.keys || {};
    } catch (_) { status = {}; }

    const overlay = el("div", { className: "fm-pdfimport-modal__overlay" });

    const select = el("select", { className: "fm-pdfimport__select" });
    for (const p of PROVIDERS) {
        const st = status[p.id]?.configured ? ` — impostata (${status[p.id].masked})` : " — non impostata";
        select.appendChild(el("option", { value: p.id, textContent: p.label + st }));
    }
    // Parti dal provider GIÀ configurato (o OpenRouter, il più usato).
    const configured = PROVIDERS.find((p) => status[p.id]?.configured);
    select.value = configured ? configured.id : "openrouter";

    // Campo chiave. readonly all'apertura + autocomplete=new-password per
    // BLOCCARE l'autofill del browser (che riempiva il campo con la password
    // salvata → veniva salvata come chiave!). Si sblocca al focus dell'utente.
    const input = el("input", {
        type: "password", className: "fm-pdfimport__input",
        autocomplete: "new-password", name: "fm-llm-key", readOnly: true,
        placeholder: "Incolla la chiave API (es. sk-or-v1-…)",
    });
    input.addEventListener("focus", () => { input.readOnly = false; }, { once: true });
    const keyLabel = el("label", { className: "fm-pdfimport__label", textContent: "Chiave API" });

    // Modello: SELECT coi modelli del provider + "(default)" + "Altro…".
    const modelSelect = el("select", { className: "fm-pdfimport__select" });
    const modelCustom = el("input", {
        type: "text", className: "fm-pdfimport__input", autocomplete: "off",
        placeholder: "model-ID personalizzato (vendor/model)", hidden: true,
    });
    const guide = el("p", { className: "fm-pdfimport-modal__hint" });
    const msg = el("div", { className: "fm-pdfimport-modal__msg" });

    const current = () => PROVIDERS.find((p) => p.id === select.value) || PROVIDERS[0];
    const syncCustom = () => { modelCustom.hidden = modelSelect.value !== "__custom__"; };
    modelSelect.addEventListener("change", syncCustom);

    // Costruisce le opzioni del select modello da una lista di slug, pre-
    // selezionando il modello IN USO (o "Altro" se custom).
    const populateModels = (provId, list) => {
        const cur = status[provId]?.model || "";
        modelSelect.replaceChildren();
        modelSelect.appendChild(el("option", { value: "", textContent: "(default del server)" }));
        for (const m of list) modelSelect.appendChild(el("option", { value: m, textContent: m }));
        modelSelect.appendChild(el("option", { value: "__custom__", textContent: "Altro (scrivi sotto)…" }));
        if (cur === "") { modelSelect.value = ""; modelCustom.value = ""; }
        else if (list.includes(cur)) { modelSelect.value = cur; modelCustom.value = ""; }
        else { modelSelect.value = "__custom__"; modelCustom.value = cur; }
        syncCustom();
    };

    const refresh = () => {
        const p = current();
        populateModels(p.id, MODELS[p.id] || []); // statica subito (fallback)
        // OpenRouter: carica i modelli VISION LIVE (slug sempre validi).
        if (p.id === "openrouter") {
            getProviderModels("openrouter").then((res) => {
                if (select.value !== "openrouter") return; // provider cambiato
                const live = (res.models || []).map((m) => m.id).filter(Boolean);
                if (live.length) populateModels("openrouter", live);
            }).catch(() => { /* tiene la lista statica */ });
        }
        // chiave: vuota = non cambiarla (se già impostata). Per Ollama lo "slot
        // chiave" contiene il BASE URL locale (nessuna API key).
        input.value = "";
        keyLabel.textContent = p.localUrl ? "Base URL (Ollama locale)" : "Chiave API";
        input.placeholder = status[p.id]?.configured
            ? (p.localUrl ? `Già impostato (${status[p.id].masked}) — vuoto per non cambiare` : `Chiave già impostata (${status[p.id].masked}) — lascia vuoto per non cambiarla`)
            : (p.localUrl ? "http://127.0.0.1:11434" : "Incolla la chiave API (es. sk-or-v1-…)");
        const g = GUIDES[p.id] || {};
        guide.textContent = `Chiave: ${g.key || "—"}.  Modello: ${g.models || "—"}.`;
    };
    select.addEventListener("change", refresh);
    refresh();

    const setMsg = (t, err = false) => { msg.textContent = t; msg.classList.toggle("fm-pdfimport-modal__msg--error", err); };
    const close = () => overlay.remove();

    const saveBtn = el("button", { type: "button", className: "fm-pdfimport__btn fm-pdfimport__btn--primary", textContent: "Salva" });
    saveBtn.addEventListener("click", async () => {
        const key = input.value.trim();
        // Chiave vuota OK se già impostata (si cambia solo il modello).
        if (!key && !status[select.value]?.configured) { setMsg("Inserisci una chiave.", true); return; }
        saveBtn.disabled = true; setMsg("Salvataggio…");
        try {
            const model = modelSelect.value === "__custom__" ? modelCustom.value.trim() : modelSelect.value;
            await saveProviderKey(select.value, key, model);
            setMsg("Salvato. Ricarico la pagina…");
            setTimeout(() => { onSaved ? onSaved() : location.reload(); }, 600);
        } catch (e) {
            setMsg("Errore: " + (e?.message || "salvataggio fallito"), true);
            saveBtn.disabled = false;
        }
    });

    const clearBtn = el("button", { type: "button", className: "fm-pdfimport__btn fm-pdfimport__btn--sm fm-pdfimport__btn--ghost", textContent: "Rimuovi" });
    clearBtn.addEventListener("click", async () => {
        clearBtn.disabled = true; setMsg("Rimozione…");
        try {
            await clearProviderKey(select.value);
            setMsg("Rimossa. Ricarico…");
            setTimeout(() => { onSaved ? onSaved() : location.reload(); }, 600);
        } catch (e) {
            setMsg("Errore: " + (e?.message || "rimozione fallita"), true);
            clearBtn.disabled = false;
        }
    });

    const closeBtn = el("button", { type: "button", className: "fm-pdfimport__btn fm-pdfimport__btn--sm", textContent: "Chiudi" });
    closeBtn.addEventListener("click", close);

    const dialog = el("div", { className: "fm-pdfimport-modal", role: "dialog", "aria-modal": "true" }, [
        el("h2", { className: "fm-pdfimport-modal__title", textContent: "Chiavi & modelli provider LLM" }),
        el("p", { className: "fm-pdfimport-modal__hint", textContent:
            "La chiave è salvata sul server (mai mostrata di nuovo), usata solo lato server. Il modello è opzionale (vuoto = default)." }),
        el("label", { className: "fm-pdfimport__label", textContent: "Provider" }), select,
        guide,
        keyLabel, input,
        el("label", { className: "fm-pdfimport__label", textContent: "Modello (opzionale)" }), modelSelect, modelCustom,
        msg,
        el("div", { className: "fm-pdfimport-modal__actions" }, [saveBtn, clearBtn, closeBtn]),
    ]);
    dialog.setAttribute("aria-label", "Configura provider LLM");
    overlay.appendChild(dialog);
    overlay.addEventListener("click", (e) => { if (e.target === overlay) close(); });
    document.body.appendChild(overlay);
    // NON focalizzare il campo chiave: l'auto-focus rimuoverebbe il readonly e
    // riattiverebbe l'autofill del browser. Focus sul provider (innocuo).
    select.focus();
}
