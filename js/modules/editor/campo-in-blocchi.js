/**
 * Il serializzatore dei campi dell'editor: dal DOM reso al campo, e dal campo
 * ai blocchi del contratto.
 *
 * Due direzioni:
 *   - all'apertura dell'editor, `sorgenteDelContenitore` legge un contenitore
 *     reso da ContractRenderer (.fm-collection, .fm-sol, .fm-giustsol, l'intro
 *     di un gruppo) e ne fa il sorgente che va nel campo;
 *   - al salvataggio, `blocchiDalCampo` legge il campo (il `<div
 *     contenteditable>` con i marcatori TikZ e GeoGebra) e ne fa i blocchi
 *     `{type, ...}` che finiscono nel contratto.
 *
 * Spostato alla lettera da features/checkin-handlers.js il 19 settembre 2026,
 * per poterlo provare da solo (tests/js-unit/tikz-nel-salvataggio.test.js):
 * il monolite non esporta niente e caricarlo in una prova vuol dire caricare
 * mezzo editor. Nel monolite restano gli alias con i nomi di prima.
 */

import { sanitizeBlockContent } from "../security/html-sanitize-client.js";
import { senzaNonce } from "./inline-blocks-markers.js";

/** G22.S15 — Estrae il "raw source" del contenuto da un container DOM
 *  (.fm-collection, .fm-sol, .fm-giustsol). Combina:
 *    - .fm-text/.fm-latex/.fm-badge → data-raw o textContent
 *    - [data-tikz-body]             → ricostruisce <script type="text/tikz">
 *      via attributi data-tikz-tagopen e data-tikz-body (URL-encoded).
 *    - script[type=text/tikz]       → outerHTML senza il nonce (script ancora
 *      non renderizzato a SVG)
 *  Risultato: stringa adatta a `_collapseTikzBlocks` per estrarre i blocchi
 *  TikZ in markers + _tikzBlocks.
 *
 *  Una figura TikZ, quando il docente apre l'editor, può trovarsi in tre
 *  stati, e tutti e tre devono dare lo stesso sorgente:
 *
 *    1. `<script type="text/tikz">` — il gruppo era chiuso e il render pigro
 *       non è ancora partito, oppure la figura è appena stata inserita;
 *    2. l'SVG reso, con `data-tikz-tagopen`/`data-tikz-body` addosso;
 *    3. il riquadro rosso d'errore, quando il servizio TeX non ha risposto —
 *       da oggi con gli stessi due attributi dell'SVG.
 *
 *  Il caso 3 prima finiva nel ramo generico qui sotto, che scende nei figli e
 *  ne raccoglie il testo: il 18 settembre 2026 il contratto della verifica 75
 *  si è ritrovato «[TikZ render error]\nErrore di rete…» al posto di una
 *  figura. Per questo il riconoscimento ora guarda `[data-tikz-body]` e non
 *  più `svg[data-tikz-hash]`: prende l'SVG, il `<div>` che lo avvolge quando
 *  la risposta non ha una radice `<svg>`, e il riquadro d'errore. */
/** Variante di _extractRawWithTikz che ESCLUDE il `<strong class="fm-sol-label">`
 *  ("SOLUZIONE"/"GIUSTIFICAZIONE") emesso dal renderer come visual label —
 *  non parte del content editabile. */
function _extractRawWithoutLabel(container) {
    if (!container) return "";
    const clone = container.cloneNode(true);
    clone.querySelectorAll(".fm-sol-label").forEach((s) => s.remove());
    return _extractRawWithTikz(clone);
}

function _extractRawWithTikz(container) {
    if (!container) return "";
    // Ogni part = {raw, inline}. Inline = fm-text/fm-latex/fm-badge/textNode.
    // Block = TikZ/GeoGebra/ol/ul/fallback recursive.
    // Separator: inline↔inline = " " (con dedup whitespace e prima di
    // punteggiatura per evitare " ," " ." ); altro boundary = "\n".
    const parts = [];
    for (const node of container.childNodes) {
        if (node.nodeType === 3) {
            const t = (node.textContent || "").trim();
            if (t) parts.push({ raw: node.textContent, inline: true });
            continue;
        }
        if (node.nodeType !== 1) continue;
        const el = node;
        if (el.matches?.(".fm-text, .fm-latex, .fm-badge")) {
            const raw = el.dataset?.raw;
            if (raw) parts.push({ raw, inline: true });
            else parts.push({ raw: (el.textContent || "").trim(), inline: true });
        } else if (el.matches?.("[data-tikz-body]")) {
            const tagOpen = senzaNonce(
                decodeURIComponent(el.getAttribute("data-tikz-tagopen") || '<script type="text/tikz">'),
            );
            const body    = decodeURIComponent(el.getAttribute("data-tikz-body") || "");
            parts.push({ raw: `${tagOpen + body}</` + `script>`, inline: false });
        } else if (el.matches?.("script[type^='text/tikz']")) {
            parts.push({ raw: senzaNonce(el.outerHTML), inline: false });
        } else if (el.matches?.(".fm-geogebra-wrap")) {
            parts.push({ raw: el.outerHTML, inline: false });
        } else if (el.matches?.("ol, ul")) {
            // Lista (DSA o standard): emetti outerHTML preservando struttura.
            // Rimuove le UI DSA (F/GF buttons + .fm-dsa-li-num) e unwrappa
            // .fm-dsa-li-content per avere markup pulito nel contenteditable.
            parts.push({ raw: _cleanListForEditor(el), inline: false });
        } else {
            parts.push({ raw: _extractRawWithTikz(el), inline: false });
        }
    }
    // Join smart: inline-inline = " " (salvo whitespace/punteggiatura adiacente);
    // altri = "\n".
    let out = "";
    for (let i = 0; i < parts.length; i++) {
        const p = parts[i];
        if (i === 0) { out = p.raw; continue; }
        const prev = parts[i - 1];
        let sep;
        if (prev.inline && p.inline) {
            // Skip spazio se prev finisce whitespace o p inizia con whitespace/punteggiatura
            if (/\s$/.test(prev.raw) || /^[\s,.;:!?)\]]/.test(p.raw)) sep = "";
            else sep = " ";
        } else {
            sep = "\n";
        }
        out += sep + p.raw;
    }
    return out.trim();
}

/**
 * Pulisce un <ol>/<ul> server-rendered per l'edit-mode contenteditable:
 *   1. Rimuove .fm-dsa-li-buttons (UI F/GF) — non parte del sorgente
 *   2. Rimuove .fm-dsa-li-num (marker numerico testuale) — sostituito da CSS
 *   3. Unwrap .fm-dsa-li-content (sposta children al posto dello span)
 *   4. Sostituisce .fm-text/.fm-latex con il loro data-raw (text node)
 *
 * Il risultato è un <ol class="fm-dsa-li-list" data-fm-list-style="..."> con
 * <li> contenenti SOLO il sorgente (raw text + nested liste). I marker visivi
 * vengono ri-applicati dal CSS list-style + ::marker dell'editor.
 */
function _cleanListForEditor(listEl) {
    const clone = listEl.cloneNode(true);
    clone.querySelectorAll(".fm-dsa-li-buttons, .fm-dsa-li-num").forEach((n) => n.remove());
    clone.querySelectorAll(".fm-dsa-li-content").forEach((span) => {
        const parent = span.parentNode;
        while (span.firstChild) parent.insertBefore(span.firstChild, span);
        span.remove();
    });
    clone.querySelectorAll(".fm-text, .fm-latex").forEach((span) => {
        const raw = span.getAttribute("data-raw");
        if (raw === null) return;
        // Parse raw come HTML: il `data-raw` può contenere inline tag
        // (<b>/<i>/<u>/...). Se lo sostituiamo con un TEXT node, l'outerHTML
        // li escaperebbe (`&lt;b&gt;`) → editor mostra tag come testo letterale.
        const tmp = document.createElement("template");
        tmp.innerHTML = raw;
        const frag = tmp.content;
        const parent = span.parentNode;
        if (!parent) return;
        // Inserisce ogni child del fragment prima dello span, poi rimuove
        while (frag.firstChild) parent.insertBefore(frag.firstChild, span);
        span.remove();
    });
    // data-fm-dsa-state attribute non serve in edit mode
    clone.querySelectorAll("[data-fm-dsa-state]").forEach((li) => {
        li.removeAttribute("data-fm-dsa-state");
    });
    return clone.outerHTML;
}

/** G22.S15 — Costruisce un array di blocchi `{type, ...}` dal textarea.
 *  Output compatibile col contract-schema:
 *    - text chunks → {type:'text', content:'...'}
 *    - tikz scripts → {type:'tikz', script:'...', tex_packages:..., tikz_libs:...,
 *                       data_template_id:..., data_template_data:...}
 *  Se non ci sono blocchi TikZ, ritorna [{type:'text', content:<value>}] (uguale
 *  al wrapping che il backend faceva ma client-side).
 */
function _buildBlocksFromTextarea(ta) {
    const tikzBlocks = ta._tikzBlocks || [];
    const ggbBlocks  = ta._geogebraBlocks || [];
    // Strip ZWS placeholder usato da _wrapAsElement (collapsed wrap → garantisce
    // caret dentro l'inline element). Char invisibile, no significato semantico.
    // Anche tag inline vuoti residuali da split: <b></b>, <i></i>, ecc.
    // Strip <br> residui-only: contenteditable vuoto inserisce auto un <br>
    // come placeholder DOM (Firefox + Chrome); senza questa pulizia il save
    // emette `<br>` letterale e ContractRenderer lo escapa in data-raw.
    let value = (ta.value || "")
        .replace(/​/g, "")
        .replace(/<(b|strong|i|em|u|s|sub|sup)>\s*<\/\1>/gi, "");
    // Caso "campo vuoto": solo whitespace + <br>(/) → considera vuoto
    if (/^(\s|<br\s*\/?>)*$/i.test(value)) value = "";

    // G23.fix5 — Pre-clean: strippa DSA wrappers (.fm-dsa-li-num,
    // .fm-dsa-li-buttons) + unwrap .fm-dsa-li-content. Se l'editor textarea
    // contiene questi span (copy-paste da render server, oppure re-open con
    // stato non-canonico), il parser includerebbe "a." "b." come falsi text
    // block CONCATENATI al content reale (es. "d" → "b.d"). Idempotente:
    // se assenti, no-op zero costo.
    if (/fm-dsa-li-(num|buttons|content)/.test(value) || /fm-text\b|fm-latex\b/.test(value)) {
        const cleaner = document.createElement("div");
        cleaner.innerHTML = value;
        cleaner.querySelectorAll(".fm-dsa-li-buttons, .fm-dsa-li-num").forEach(n => n.remove());
        cleaner.querySelectorAll(".fm-dsa-li-content").forEach(span => {
            const parent = span.parentNode;
            if (!parent) return;
            while (span.firstChild) parent.insertBefore(span.firstChild, span);
            span.remove();
        });
        // Replace fm-text/fm-latex with data-raw value (preserve LaTeX source)
        cleaner.querySelectorAll(".fm-text[data-raw]").forEach(span => {
            const raw = span.getAttribute("data-raw") || "";
            const tmp = document.createElement("template");
            tmp.innerHTML = raw;
            const parent = span.parentNode;
            if (!parent) return;
            while (tmp.content.firstChild) parent.insertBefore(tmp.content.firstChild, span);
            span.remove();
        });
        cleaner.querySelectorAll(".fm-latex[data-raw]").forEach(span => {
            const raw = span.getAttribute("data-raw") || "";
            const replacement = document.createElement("span");
            replacement.className = "fm-latex";
            replacement.setAttribute("data-raw", raw);
            replacement.innerHTML = raw;
            span.replaceWith(replacement);
        });
        cleaner.querySelectorAll("[data-fm-dsa-state]").forEach(li => {
            li.removeAttribute("data-fm-dsa-state");
        });
        value = cleaner.innerHTML;
    }

    // Step 1: split su marker TikZ/GeoGebra (esistente).
    const markerRe = /(⟨🔍 TikZ #(\d+)⟩|⟨📐 GeoGebra #(\d+)⟩)/g;
    markerRe.lastIndex = 0;
    const segments = [];
    let last = 0;
    let m;
    while ((m = markerRe.exec(value)) !== null) {
        if (m.index > last) segments.push({ kind: "text", text: value.slice(last, m.index) });
        if (m[2] !== undefined) {
            const idx = parseInt(m[2], 10) - 1;
            const blk = tikzBlocks[idx];
            if (blk) segments.push({ kind: "block", block: _tikzBlockToContractBlock(blk) });
        } else if (m[3] !== undefined) {
            const idx = parseInt(m[3], 10) - 1;
            const blk = ggbBlocks[idx];
            if (blk) segments.push({ kind: "block", block: {
                type: "geogebra",
                ggb_b64: blk.ggb_b64 || "",
                svg:     blk.svg || "",
                label:   blk.label || "",
                width:   blk.width || "",
            } });
        }
        last = m.index + m[0].length;
    }
    if (last < value.length) segments.push({ kind: "text", text: value.slice(last) });

    // Step 2: per ogni text segment, parsa <ol>/<ul> in block list strutturati.
    // Pattern: lista innermost-first via parser DOMParser (supporta nested).
    const blocks = [];
    for (const seg of segments) {
        if (seg.kind === "block") { blocks.push(seg.block); continue; }
        const text = seg.text;
        if (!text.trim()) continue;
        // Quick check: contiene <ol>/<ul>?
        if (!/<\s*(ol|ul)\b/i.test(text)) {
            blocks.push({ type: "text", content: text });
            continue;
        }
        // Parse via DOMParser per gestire nesting correttamente.
        try {
            const parser = new DOMParser();
            const doc = parser.parseFromString(`<div>${text}</div>`, "text/html");
            const root = doc.body.firstChild;
            const out = [];
            _flattenChildrenToBlocks(root, out);
            for (const b of out) blocks.push(b);
        } catch (e) {
            blocks.push({ type: "text", content: text });
        }
    }
    // G24.phase4 — Client pre-sanitize: scan blocks text/list e applica
    // sanitizeBlockContent ai text content che contengono tag inline.
    // Defense-in-depth: server è authoritative (HtmlSanitizer), questo è
    // UX hint per ridurre payload malicious in transit (paste untrusted).
    _sanitizeBlocksClientSide(blocks);
    return blocks;
}

/** G24.phase4 — Walker che applica sanitizeBlockContent a text content
 *  con inline HTML, ricorsivo su list items. Mutates in-place. */
function _sanitizeBlocksClientSide(blocks) {
    if (!Array.isArray(blocks)) return;
    for (const b of blocks) {
        if (!b || typeof b !== "object") continue;
        if (b.type === "text" && typeof b.content === "string"
            && /<(b|strong|i|em|u|s|sub|sup|a|span)\b/i.test(b.content)) {
            b.content = sanitizeBlockContent(b.content);
        } else if (b.type === "list" && Array.isArray(b.items)) {
            for (const item of b.items) _sanitizeBlocksClientSide(item);
        }
    }
}

/**
 * Itera i child nodes di `root` producendo blocchi contract:
 * - text node con whitespace → ignorato
 * - text node con contenuto → block text
 * - <ol>/<ul> → block list (recursive su <li>)
 * - altri tag → estrai textContent come text block (fallback safe)
 */
function _flattenChildrenToBlocks(root, out) {
    let textBuf = "";
    const flushText = () => {
        if (textBuf.trim()) out.push({ type: "text", content: textBuf.trim() });
        textBuf = "";
    };
    // Tag inline preservati nel content del block text (HTML markup mantenuto
    // per roundtrip post-save → server Sanitizer convertirà a LaTeX).
    const INLINE_PRESERVE = /^(b|strong|i|em|u|s|sub|sup|a)$/i;

    for (const child of root.childNodes) {
        if (child.nodeType === Node.TEXT_NODE) {
            textBuf += child.textContent;
            continue;
        }
        if (child.nodeType !== Node.ELEMENT_NODE) continue;
        const tag = child.tagName.toLowerCase();
        if (tag === "ol" || tag === "ul") {
            flushText();
            out.push(_olUlToListBlock(child));
        } else if (tag === "br") {
            textBuf += "\n";
        } else if (child.classList?.contains("fm-latex")) {
            // Span semantico LaTeX: emette block dedicato `{type:'latex'}`.
            // Senza unwrap, l'outerHTML del span verrebbe storato come testo
            // letterale → corruzione roundtrip (escape doppio).
            flushText();
            out.push({ type: "latex", content: child.getAttribute("data-raw") || child.textContent || "" });
        } else if (child.classList?.contains("fm-text") || child.classList?.contains("fm-badge")) {
            // Span semantico testo/badge: unwrappa al valore data-raw originale.
            // Evita che lo span markup contamini il content del text block parent
            // (al re-save lo span verrebbe ri-emesso doppio dal renderer → loop).
            textBuf += child.getAttribute("data-raw") || child.textContent || "";
        } else if (child.classList?.contains("giustifica")) {
            // G23.fix16 — Span `.giustifica` IGNORATO durante capture dell'intro.
            // È un field SEPARATO nel group editor (sezione dedicata), non
            // inline nel testo. Server emette span dedicato leggendo
            // `g.giustifica` string del contract.
            // (revert fix15: niente block type 'giustifica')
        } else if (INLINE_PRESERVE.test(tag)) {
            // Inline format/styling: preserva outerHTML così il content
            // del block text round-trippa correttamente (Sanitizer lato
            // server converte <b>/<i>/<u>/<a> in LaTeX).
            textBuf += child.outerHTML;
        } else if (tag === "span") {
            // G23 — <span> generico (incluso .fm-dsa-li-content residuo): se
            // contiene OL/UL nested, recurse via _flattenChildrenToBlocks
            // (preserva struttura lista). Altrimenti serialize inline.
            if (child.querySelector("ol, ul")) {
                _flattenChildrenToBlocks(child, out);
                // Non aggiungere a textBuf: i blocks già pushati via recursion.
                // Ma le text node inline del span vanno catturate.
                // _flattenChildrenToBlocks chiama flushText interno per ogni OL/UL.
            } else {
                textBuf += _serializeInlinePreserving(child);
            }
        } else if (tag === "div" || tag === "p") {
            // Block container (creato dal browser su Enter in contenteditable):
            // recurse per preservare tag inline interni + newline finale.
            // textContent perderebbe <b>/<i>/<u> dentro al <div>.
            // G23 — se contiene OL/UL, recurse anche per liste.
            if (child.querySelector("ol, ul")) {
                _flattenChildrenToBlocks(child, out);
            } else {
                textBuf += `${_serializeInlinePreserving(child)}\n`;
            }
        } else {
            textBuf += child.textContent;
        }
    }
    flushText();
}

/** Serializza ricorsivamente il subtree preservando tag inline (b/i/u/...)
 *  e convertendo <br>/<div>/<p> in newline. Usato per <div>/<p> generati
 *  dal browser quando l'utente preme Enter in contenteditable. */
function _serializeInlinePreserving(node) {
    const INLINE_PRESERVE = /^(b|strong|i|em|u|s|sub|sup|a)$/i;
    let buf = "";
    for (const child of node.childNodes) {
        if (child.nodeType === Node.TEXT_NODE) {
            buf += child.textContent;
            continue;
        }
        if (child.nodeType !== Node.ELEMENT_NODE) continue;
        const tag = child.tagName.toLowerCase();
        if (tag === "br") { buf += "\n"; continue; }
        // Span semantici fm-text/fm-latex/fm-badge: unwrap al data-raw value
        // (NO outerHTML, NO markup leak roundtrip).
        if (child.classList?.contains("fm-text") || child.classList?.contains("fm-badge") ||
            child.classList?.contains("fm-latex")) {
            buf += child.getAttribute("data-raw") || child.textContent || "";
            continue;
        }
        if (INLINE_PRESERVE.test(tag)) { buf += child.outerHTML; continue; }
        if (tag === "span") {
            // <span> generico: unwrap, no semantic
            buf += _serializeInlinePreserving(child);
            continue;
        }
        if (tag === "div" || tag === "p") {
            buf += `${_serializeInlinePreserving(child)}\n`;
            continue;
        }
        buf += child.textContent;
    }
    return buf;
}

function _olUlToListBlock(el) {
    const block = {
        type: "list",
        ordered: el.tagName.toLowerCase() === "ol",
        items: [],
    };
    const styleType = el.getAttribute("type");
    if (styleType) block.list_style = styleType;
    const startAttr = el.getAttribute("start");
    if (startAttr) block.start = parseInt(startAttr, 10) || 1;
    const sec = el.getAttribute("data-dsa-section");
    if (sec) block.dsa_section = sec;
    // PRESET stile gerarchico (Google Docs-like): preserva attribute per
    // round-trip editor → save → render.
    const preset = el.getAttribute("data-fm-list-style");
    if (preset) block.list_preset = preset;
    for (const li of el.children) {
        if (li.tagName.toLowerCase() !== "li") continue;
        const itemBlocks = [];
        _flattenChildrenToBlocks(li, itemBlocks);
        block.items.push(itemBlocks);
    }
    return block;
}

/** Trasforma un _tikzBlocks[i] (`{tagOpen, body, tagClose}`) in un block
 *  contract `{type:'tikz', script, tex_packages?, tikz_libs?, data_template_id?,
 *  data_template_data?}`. Estrae attributi dal tagOpen via regex. */
function _tikzBlockToContractBlock(blk) {
    const out = {
        type: "tikz",
        script: (blk.body || "").replace(/^\n+/, "").replace(/\n+$/, ""),
    };
    const tagOpen = blk.tagOpen || "";
    const pkg  = tagOpen.match(/data-tex-packages=(["'])([\s\S]*?)\1/i);
    const lib  = tagOpen.match(/data-tikz-libraries=(["'])([\s\S]*?)\1/i);
    const tid  = tagOpen.match(/data-template-id=(["'])([\s\S]*?)\1/i);
    const tdat = tagOpen.match(/data-template-data=(["'])([\s\S]*?)\1/i);
    if (pkg)  out.tex_packages    = _daAttributo(pkg[2]);
    if (lib)  out.tikz_libs       = _daAttributo(lib[2]);
    if (tid)  out.data_template_id   = _daAttributo(tid[2]);
    if (tdat) out.data_template_data = _daAttributo(tdat[2]);
    return out;
}

/** Il valore di un attributo come sta nell'HTML torna al valore che era nel
 *  contratto. ContractRenderer scrive gli attributi con htmlspecialchars, e
 *  qui si legge la stringa HTML, non il DOM: senza questo passaggio
 *  `tex_packages` tornava `{&quot;amsmath&quot;:&quot;&quot;}` invece di
 *  `{"amsmath":""}`, e a ogni salvataggio la deformazione cresceva di un giro
 *  (`&amp;quot;`, poi `&amp;amp;quot;`…). `&amp;` per ultimo, o si decodifica
 *  due volte. */
function _daAttributo(valore) {
    return String(valore ?? "")
        .replace(/&quot;/g, '"')
        .replace(/&#0?39;/g, "'")
        .replace(/&lt;/g, "<")
        .replace(/&gt;/g, ">")
        .replace(/&amp;/g, "&");
}

export {
    _extractRawWithTikz as sorgenteDelContenitore,
    _extractRawWithoutLabel as sorgenteSenzaEtichetta,
    _buildBlocksFromTextarea as blocchiDalCampo,
    _tikzBlockToContractBlock as bloccoTikzDalMarcatore,
};
