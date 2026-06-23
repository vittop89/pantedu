/**
 * Model sync — riconciliazione fork ↔ master institutional (ADR-026).
 *
 * Quando un docente forka un modello in teacher_content, salva uno SNAPSHOT del
 * master in `metadata.model_body_pt_baseline` + `metadata.model_synced_at`. Se
 * poi il super-admin aggiorna il master, il fork resta congelato. Questo
 * modulo fornisce:
 *
 *   isOutdated(masterUpdatedAtISO, syncedAtISO) → bool
 *       Confronto timestamp: master più recente del fork?
 *
 *   threeWayMerge(baseline, masterNew, current) → { merged, decisions }
 *       Diff per "sezione" (intervallo body_pt che inizia con un
 *       sectionHeader). Per ogni sezione, decisione 3-way:
 *
 *       - baseline == current  &&  masterNew != baseline  → UPDATED dal modello
 *       - current != baseline  &&  masterNew == baseline  → KEPT (modificata dal docente)
 *       - current != baseline  &&  masterNew != baseline  → CONFLICT (conservata
 *         la versione del docente con marker)
 *       - section esiste solo in masterNew (admin l'ha aggiunta) → ADDED dal modello
 *       - section esiste solo in current (docente l'ha aggiunta) → KEPT
 *       - section esiste solo in baseline (cancellata da entrambi) → DROPPED
 *
 *   sectionKey(headerBlock) → string
 *       Chiave d'identità per matching sezioni: preferenza `name` (carry
 *       attribute schema), fallback al titolo normalizzato.
 */

/** Confronto timestamp ISO. Se uno dei due manca → false (no badge). */
export function isOutdated(masterUpdatedAtISO, syncedAtISO) {
    if (!masterUpdatedAtISO || !syncedAtISO) return false;
    const m = new Date(masterUpdatedAtISO).getTime();
    const s = new Date(syncedAtISO).getTime();
    if (!Number.isFinite(m) || !Number.isFinite(s)) return false;
    // Tolleranza 1s per scarti di clock fra DB e client.
    return m > s + 1000;
}

/** Spezza un body_pt in sezioni: array di { key, title, blocks: [...] }.
 *  Una "sezione" parte da un sectionHeader e dura fino al prossimo
 *  sectionHeader. I blocchi pre-header (eventuali) vanno in una pseudo-sezione
 *  con key="__preamble__".
 *
 *  Robustezza: l'index posizionale fa parte del fallback chiave per evitare
 *  collisioni quando text/title sono vuoti (es. master derivato dallo schema
 *  senza carry name). Così sezioni "vuote" in stessa posizione tra current e
 *  master matchano comunque. */
export function splitSections(bodyPt) {
    const sections = [];
    let sectionIdx = 0;
    let current = { key: "__preamble__", title: "", blocks: [] };
    for (const b of (Array.isArray(bodyPt) ? bodyPt : [])) {
        // ADR-030 — il blocco ternaStore (valori per-classe) NON è struttura:
        // escluderlo dalle sezioni così non falsa il diff/merge col modello.
        if (b && b._type === "ternaStore") continue;
        if (b && b._type === "sectionHeader") {
            if (current.blocks.length) sections.push(current);
            const title = sectionTitle(b, sectionIdx);
            current = { key: sectionKey(b, sectionIdx), title, blocks: [b] };
            sectionIdx++;
        } else {
            current.blocks.push(b);
        }
    }
    if (current.blocks.length) sections.push(current);
    return sections;
}

/** Chiave identità sezione: nome carry > titolo normalizzato > posizione.
 *  fallbackIdx (posizione DOM) garantisce univocità quando text/title sono
 *  vuoti (header schema-derived). */
export function sectionKey(headerBlock, fallbackIdx) {
    if (!headerBlock) return `i:${fallbackIdx ?? "x"}`;
    if (typeof headerBlock.name === "string" && headerBlock.name) return `n:${headerBlock.name}`;
    const raw = headerBlock.text || headerBlock.title || "";
    const t = String(raw).trim().toLowerCase().replace(/\s+/g, "-");
    if (t) return `t:${t}`;
    return `i:${fallbackIdx ?? "x"}`;
}

/** Titolo display per la UI. Mai vuoto: fallback "Sezione N". */
export function sectionTitle(headerBlock, idx) {
    if (!headerBlock) return `Sezione ${(idx ?? 0) + 1}`;
    const raw = headerBlock.text || headerBlock.title || "";
    const t = String(raw).trim();
    return t || `Sezione ${(idx ?? 0) + 1}`;
}

/** Hash leggero (string) di un array di blocchi PT — per uguaglianza by-value. */
function blocksHash(blocks) {
    try { return JSON.stringify(blocks); } catch { return ""; }
}

/** Firma STRUTTURALE/VISIVA di una sezione per la DECISIONE di merge: usa
 *  visualHash (ignora carry metadata invisibili: name/options_source/cid/…) così
 *  un fork che differisce dal master SOLO per metadati NON viene scambiato per
 *  "modificato dal docente" (→ le modifiche del modello entrano correttamente).
 *  Prima si usava blocksHash (JSON grezzo) → falsi positivi → merge no-op. */
function sectionSig(blocks) {
    return (Array.isArray(blocks) ? blocks : []).map(visualHash).join("|");
}

/**
 * Merge 3-way fra baseline (master a tempo di fork), masterNew (master ora),
 * current (fork ora). Ritorna { merged, decisions }.
 *
 * decisions è un array di { key, title, action, detail? } in ordine di rendering.
 * action ∈ { "kept", "kept-teacher-added", "updated", "added", "dropped",
 *           "conflict", "unchanged" }.
 */
export function threeWayMerge(baseline, masterNew, current) {
    const B = splitSections(baseline);
    const M = splitSections(masterNew);
    const C = splitSections(current);
    const idxB = new Map(B.map((s, i) => [s.key, i]));
    const idxM = new Map(M.map((s, i) => [s.key, i]));
    const idxC = new Map(C.map((s, i) => [s.key, i]));
    const allKeys = [];
    const seen = new Set();
    // Ordine: priorità a current (preserva l'ordine che il docente vede),
    // poi inserisce le sezioni nuove di masterNew (non ancora in current).
    for (const s of C) { if (!seen.has(s.key)) { allKeys.push(s.key); seen.add(s.key); } }
    for (const s of M) { if (!seen.has(s.key)) { allKeys.push(s.key); seen.add(s.key); } }
    for (const s of B) { if (!seen.has(s.key)) { allKeys.push(s.key); seen.add(s.key); } }

    const merged = [];
    const decisions = [];
    for (const key of allKeys) {
        const inB = idxB.has(key);
        const inM = idxM.has(key);
        const inC = idxC.has(key);
        const sB = inB ? B[idxB.get(key)] : null;
        const sM = inM ? M[idxM.get(key)] : null;
        const sC = inC ? C[idxC.get(key)] : null;
        const title = sC?.title || sM?.title || sB?.title || "";

        // currentBlocks / masterBlocks sempre presenti quando disponibili —
        // il modale UI usa questi per "▼ Confronta" side-by-side.
        const cur = sC ? sC.blocks : null;
        const mst = sM ? sM.blocks : null;
        if (inC && inM && inB) {
            // DECISIONE su firma VISIVA (no metadati/id) — vedi sectionSig.
            const teacherChanged = sectionSig(sC.blocks) !== sectionSig(sB.blocks);
            const adminChanged   = sectionSig(sM.blocks) !== sectionSig(sB.blocks);
            if (!teacherChanged && adminChanged) {
                merged.push(...sM.blocks);
                decisions.push({ key, title, action: "updated", detail: "Adottata versione del modello (tu non l'avevi modificata).",
                    currentBlocks: cur, masterBlocks: mst });
            } else if (teacherChanged && !adminChanged) {
                merged.push(...sC.blocks);
                decisions.push({ key, title, action: "kept", detail: "Tua modifica preservata (il modello non era cambiato).",
                    currentBlocks: cur, masterBlocks: mst });
            } else if (teacherChanged && adminChanged) {
                merged.push(...sC.blocks);
                decisions.push({ key, title, action: "conflict", detail: "Conflitto: sia tu che il modello avete modificato. Conservata la tua versione.",
                    currentBlocks: cur, masterBlocks: mst });
            } else {
                merged.push(...sC.blocks);
                decisions.push({ key, title, action: "unchanged",
                    currentBlocks: cur, masterBlocks: mst });
            }
        } else if (inC && !inB && !inM) {
            merged.push(...sC.blocks);
            decisions.push({ key, title, action: "kept-teacher-added", detail: "Sezione aggiunta da te: conservata.",
                currentBlocks: cur });
        } else if (inM && !inB && !inC) {
            merged.push(...sM.blocks);
            decisions.push({ key, title, action: "added", detail: "Nuova sezione del modello aggiunta.",
                masterBlocks: mst });
        } else if (inC && inB && !inM) {
            merged.push(...sC.blocks);
            decisions.push({ key, title, action: "kept", detail: "Il modello ha rimosso questa sezione, ma è stata conservata nel tuo documento.",
                currentBlocks: cur });
        } else if (inM && inB && !inC) {
            decisions.push({ key, title, action: "dropped", detail: "L'avevi rimossa: non reintrodotta.",
                masterBlocks: mst });
        } else if (inB && !inM && !inC) {
            decisions.push({ key, title, action: "dropped" });
        }
    }
    return { merged, decisions };
}

/**
 * Merge additivo 2-way per fork senza baseline (fork pre-feature model-sync):
 * aggiunge SOLO le sezioni nuove del master alla fine del fork. Non tocca
 * mai le sezioni esistenti del docente (no rischio di sovrascrivere).
 *
 * Ritorna { merged, decisions } compatibile con threeWayMerge.
 * Limite: senza baseline non possiamo distinguere "docente ha modificato"
 * vs "il master è cambiato", quindi conserviamo sempre il fork.
 */
export function additiveMerge(masterNew, current) {
    const M = splitSections(masterNew);
    const C = splitSections(current);
    const idxM = new Map(M.map((s) => [s.key, s]));
    const keysC = new Set(C.map((s) => s.key));
    const merged = [...(Array.isArray(current) ? current : [])];
    const decisions = [];

    // Sezioni esistenti del docente. Senza baseline non sappiamo CHI ha
    // modificato cosa, ma possiamo almeno rilevare se il contenuto del master
    // DIFFERISCE da quello del fork → marker informativo + masterBlocks per
    // l'opzione "adotta dal modello" lato UI.
    for (const s of C) {
        if (s.key === "__preamble__") continue;
        const sM = idxM.get(s.key);
        if (sM && sectionSig(sM.blocks) !== sectionSig(s.blocks)) {
            decisions.push({
                key: s.key, title: s.title, action: "kept-master-differs",
                detail: "Il modello ha modifiche in questa sezione; la tua versione resta preservata. Usa 'Adotta dal modello' per sostituirla.",
                currentBlocks: s.blocks, masterBlocks: sM.blocks,
            });
        } else {
            // Sezione invariata: SIA currentBlocks SIA masterBlocks (sM se
            // esiste, altrimenti specchio di s) — così la modale può mostrare
            // entrambe le colonne col medesimo contenuto, evitando il
            // "(vuota)" asimmetrico + l'highlight rosso fantasma.
            decisions.push({
                key: s.key, title: s.title, action: "kept",
                detail: "Sezione invariata rispetto al modello (o nessuna differenza rilevabile senza baseline).",
                currentBlocks: s.blocks,
                masterBlocks: sM ? sM.blocks : s.blocks,
            });
        }
    }
    // Sezioni nuove del master: append in coda.
    for (const s of M) {
        if (s.key === "__preamble__") continue;
        if (keysC.has(s.key)) continue;
        merged.push(...s.blocks);
        decisions.push({
            key: s.key, title: s.title, action: "added",
            detail: "Nuova sezione del modello — aggiunta in coda.",
            masterBlocks: s.blocks,
        });
    }
    return { merged, decisions };
}

/**
 * Hydration delle options_source dinamiche per il diff visivo.
 *
 * Il fork (de-hydrated) ha solo `{name, options_source, items:[selezioniDocente]}`
 * mentre il master appena salvato dall'admin ha gli `items` ESPANSI con
 * tutto il framework + le sue selezioni. Senza questa funzione la colonna
 * del docente apparirebbe vuota.
 *
 * 1. Walk ricorsivo blocchi (current + master) per raccogliere TUTTE le
 *    options_source uniche (keyed by JSON.stringify).
 * 2. Fetch parallelo via fetchSchemaOptions (carry framework per
 *    indirizzo/classe/disciplina).
 * 3. Re-build ogni checkboxGroup via checkboxGroupToPt preservando lo
 *    state-per-side. Idempotente: usa dehydrateDynamicOptions prima.
 *
 * Ritorna { current, master } con i blocchi hydrated. Su errore di fetch
 * lascia i blocchi originali (non blocca la modale).
 */
export async function hydrateForDiff(currentBlocks, masterBlocks, state) {
    const sFetcher = await import("../../components/risdoc/_options-fetcher.js");
    const sPt = await import("./pt/section-to-pt.js");
    const { fetchSchemaOptions } = sFetcher;
    const { checkboxGroupToPt, dehydrateDynamicOptions } = sPt;

    // 1) raccolta options_source uniche da entrambi i side
    const sources = new Map(); // key=JSON.stringify(opts_src) → { item, key }
    const collect = (blocks) => {
        for (const b of (Array.isArray(blocks) ? blocks : [])) {
            if (b && b._type === "checkboxGroup" && b.options_source) {
                const key = JSON.stringify(b.options_source);
                if (!sources.has(key)) sources.set(key, { options_source: b.options_source, name: b.name || "" });
            }
        }
    };
    collect(currentBlocks);
    collect(masterBlocks);
    if (sources.size === 0) {
        return { current: currentBlocks, master: masterBlocks };
    }

    // 2) fetch parallelo
    const dyn = {};
    await Promise.all([...sources.values()].map(async (it) => {
        try { dyn[JSON.stringify(it.options_source)] = await fetchSchemaOptions(it, state || {}); }
        catch { dyn[JSON.stringify(it.options_source)] = []; }
    }));

    // 3) re-build checkboxGroup SEMPRE dal framework canonico (così entrambi i
    //    side hanno struttura grouped identica con solo lo state-per-side
    //    diverso). Importante: dehydrateDynamicOptions ha già collassato gli
    //    items espansi in 1 cg compatto con selezioni; checkboxGroupToPt
    //    ri-espande dal framework dyn[key].
    const rebuildSide = (blocks) => {
        if (!Array.isArray(blocks)) return blocks;
        const compact = dehydrateDynamicOptions(blocks);
        const out = [];
        for (const b of compact) {
            if (b && b._type === "checkboxGroup" && b.options_source) {
                const opts = dyn[JSON.stringify(b.options_source)];
                if (Array.isArray(opts) && opts.length) {
                    const curItems = Array.isArray(b.items) ? b.items : [];
                    const selected = curItems
                        .filter((it) => it.state === "x" || it.checked)
                        .map((it) => it.value ?? it.label).filter(Boolean);
                    const field = { name: b.name || "", type: "checkbox-group", options_source: b.options_source };
                    const rebuilt = checkboxGroupToPt(field, selected, dyn);
                    if (Array.isArray(rebuilt) && rebuilt.length) { out.push(...rebuilt); continue; }
                }
            }
            out.push(b);
        }
        return out;
    };
    return { current: rebuildSide(currentBlocks), master: rebuildSide(masterBlocks) };
}

/**
 * Normalizza un blocco PT eliminando i campi che NON contribuiscono al
 * rendering visivo (carry attributes: name/fieldType/options_source/columnKeys/
 * fieldName/renderMode, marks vuoti, ordinamento attributi). Usato come
 * hash "visuale" per il diff: due blocchi con stessa apparenza ma metadata
 * diversi (es. fork vs master con name="abilita" vs "ABILITA_3_1") risultano
 * UGUALI nel diff → niente "delta fantasma".
 */
export function visualHash(block) {
    if (block === null || block === undefined) return "";
    if (typeof block !== "object") return JSON.stringify(block);
    const stripKeys = new Set([
        "name", "fieldType", "options_source", "columnKeys", "fieldName",
        "renderMode", "compact",
        // ADR-030/031 — id/flag per-fork invisibili nel render: non sono "modifiche".
        "cid", "binding",
    ]);
    const walk = (v) => {
        if (Array.isArray(v)) return v.map(walk);
        if (v && typeof v === "object") {
            // Sort keys + strip metadata invisibili
            const out = {};
            for (const k of Object.keys(v).sort()) {
                if (stripKeys.has(k)) continue;
                // marks vuoto = stesso di assente
                if (k === "marks" && Array.isArray(v[k]) && v[k].length === 0) continue;
                const w = walk(v[k]);
                if (w === undefined) continue;
                out[k] = w;
            }
            return out;
        }
        return v;
    };
    try { return JSON.stringify(walk(block)); } catch { return ""; }
}

/**
 * Highlight set: ritorna { onlyInCurrent: Set<hash>, onlyInMaster: Set<hash> }
 * dei blocchi presenti in una sola colonna (per visual delta markup).
 * Usa visualHash per ignorare differenze invisibili (carry metadata).
 */
export function computeBlockDelta(currentBlocks, masterBlocks) {
    const hashB = (b) => visualHash(b);
    const setC = new Set((Array.isArray(currentBlocks) ? currentBlocks : []).map(hashB));
    const setM = new Set((Array.isArray(masterBlocks) ? masterBlocks : []).map(hashB));
    const onlyInCurrent = new Set();
    const onlyInMaster  = new Set();
    for (const h of setC) if (!setM.has(h)) onlyInCurrent.add(h);
    for (const h of setM) if (!setC.has(h)) onlyInMaster.add(h);
    return { onlyInCurrent, onlyInMaster, hashB };
}

/**
 * Render plain-text "preview" da un array di blocchi PT (fallback se
 * window.FM.Pt.ptToHtml non disponibile). Best-effort: estrae il testo
 * dei blocchi più comuni; gli sconosciuti vengono dumppati come "[type]".
 */
export function previewBlocksAsText(blocks) {
    const walk = (b) => {
        if (!b || typeof b !== "object") return "";
        switch (b._type) {
            case "sectionHeader": {
                const lvl = "#".repeat(Math.max(1, Math.min(6, b.level || 2)));
                return `${lvl} ${b.text || ""}\n`;
            }
            case "block":
            case "span": {
                const t = Array.isArray(b.children)
                    ? b.children.map(c => (typeof c === "string" ? c : (c?.text || ""))).join("")
                    : (b.text || "");
                return t + "\n";
            }
            case "checkboxGroup": {
                const items = (b.items || []).map((it) => {
                    const mark = it.state === "x" ? "[✓]" : it.state === "_" ? "[•]" : "[ ]";
                    return `  ${mark} ${it.label ?? it.text ?? ""}`;
                }).join("\n");
                const head = b.label || b.name || "Checkbox group";
                return `${head}\n${items}\n`;
            }
            case "table": {
                const rows = (b.rows || []).map(r => "| " + (r.cells || r || [])
                    .map(c => (typeof c === "string" ? c : (c?.value ?? c?.text ?? ""))).join(" | ") + " |").join("\n");
                return `[tabella]\n${rows}\n`;
            }
            default:
                return `[${b._type || "blocco"}]\n`;
        }
    };
    return (Array.isArray(blocks) ? blocks : []).map(walk).join("");
}

/** Adotta i blocchi del master per una sola sezione identificata da key.
 *  Sostituisce in current la slice della sezione corrispondente con masterBlocks
 *  (o la appende se non presente). Idempotente. */
export function adoptSection(current, key, masterBlocks) {
    const C = splitSections(current);
    const targetIdx = C.findIndex((s) => s.key === key);
    if (targetIdx === -1) {
        // Sezione non presente nel fork: appende.
        return [...(Array.isArray(current) ? current : []), ...masterBlocks];
    }
    const out = [];
    for (let i = 0; i < C.length; i++) {
        if (i === targetIdx) out.push(...masterBlocks);
        else out.push(...C[i].blocks);
    }
    return out;
}

