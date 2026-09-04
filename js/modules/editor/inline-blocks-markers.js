/**
 * G24.refactor5.step2 — Estratto da `features/checkin-handlers.js` (monolite
 * 9100+ LOC). Helpers Multi-TikZ + GeoGebra: collapse/expand di blocchi
 * inline in markers `⟨🔍 TikZ #N⟩` / `⟨📐 GeoGebra #N⟩` per evitare di
 * scrollare 200+ righe di sorgente nel textarea dell'editor.
 *
 * Pattern di flusso:
 *   1) Apertura editor: collapseTikz/Geo → markers nel textarea, blocchi
 *      in array side-table (`textarea._tikzBlocks`).
 *   2) Editing user: marker trattato come token opaco (mai modificato).
 *   3) Modal CM6: edit body del blocco specifico → aggiorna side-table.
 *   4) Save commit: expandTikz/Geo → ri-espandi nel value finale al server.
 *
 * Funzioni pure, nessuna dipendenza DOM/runtime.
 */

const _TIKZ_SCRIPT_RE = /(<script\s+type=["']text\/tikz["'][^>]*>)([\s\S]*?)(<\/script>)/gi;
// G27.tikz.collapse.svg — tikz-render-client.js sostituisce il <script> con
// <svg data-tikz-body="ENCODED" data-tikz-tagopen="ENCODED" ...>...</svg>
// (oppure <div ...><svg/></div> se l'SVG e' wrapped). Per round-trip in
// edit-mode dobbiamo riconoscere ANCHE questa forma e collassarla a marker
// (altrimenti l'SVG resta inline come "figura nel codice"). Ricostruiamo lo
// <script> originale dai data-attrs in expandTikzMarkers.
const _TIKZ_SVG_RE = /<(svg|div)\b([^>]*\bdata-tikz-body=["']([^"']*)["'][^>]*)>([\s\S]*?)<\/\1>/gi;
const _TIKZ_MARKER_RE = /⟨🔍 TikZ #(\d+)⟩/g;
export function tikzMarker(idx1based) { return `⟨🔍 TikZ #${idx1based}⟩`; }

// G22.S15.bis Fase 4 — GeoGebra blocks (parallelo ai TikZ).
// HTML format emesso da ContractRenderer:
//   <span class="fm-geogebra-wrap" data-ggb-base64="..." data-ggb-label="...">
//     <svg ...>...</svg>
//   </span>
const _GGB_WRAP_RE   = /(<span\s+class=["']fm-geogebra-wrap["'][^>]*>)([\s\S]*?)(<\/span>)/gi;
const _GGB_MARKER_RE = /⟨📐 GeoGebra #(\d+)⟩/g;
export function ggbMarker(idx1based) { return `⟨📐 GeoGebra #${idx1based}⟩`; }

/** Estrae tutti i `<script type="text/tikz">` da `value`, li sostituisce
 *  con marker `⟨🔍 TikZ #N⟩`.
 *  G27.tikz.collapse.svg — collassa anche `<svg data-tikz-body="...">` (e
 *  `<div data-tikz-body="...">` wrapper) prodotti dal client tikz-render
 *  dopo render: leggiamo `data-tikz-body` (URL-encoded source) e
 *  `data-tikz-tagopen` (URL-encoded tag originale `<script type="text/tikz"
 *  data-...>`); se mancanti, fallback al tag standard. Cosi' l'editor non
 *  mostra mai SVG inline come "figura nel codice".
 *  @return {{collapsed:string, blocks:Array}} */
export function collapseTikzBlocks(value) {
    const blocks = [];
    // Pass 1: collassa <svg data-tikz-body=...> PRIMA dei <script> per
    // evitare che il match svg sotto-cattura uno script gia' presente.
    let collapsed = "";
    let last = 0;
    let m;
    _TIKZ_SVG_RE.lastIndex = 0;
    while ((m = _TIKZ_SVG_RE.exec(value)) !== null) {
        collapsed += value.slice(last, m.index);
        const attrs = m[2] || "";
        const body = decodeURIComponent(m[3] || "");
        const tagOpenMatch = attrs.match(/data-tikz-tagopen=["']([^"']*)["']/i);
        const tagOpen = tagOpenMatch
            ? decodeURIComponent(tagOpenMatch[1])
            : '<script type="text/tikz" data-show-console="true">';
        blocks.push({ tagOpen, body: `\n${body}\n`, tagClose: '</' + 'script>' });
        collapsed += tikzMarker(blocks.length);
        last = m.index + m[0].length;
    }
    collapsed += value.slice(last);

    // Pass 2: collassa eventuali <script type="text/tikz"> ancora presenti
    // (es. nuovi blocchi appena inseriti dal dropdown, non ancora renderizzati).
    let stage2 = "";
    last = 0;
    _TIKZ_SCRIPT_RE.lastIndex = 0;
    while ((m = _TIKZ_SCRIPT_RE.exec(collapsed)) !== null) {
        stage2 += collapsed.slice(last, m.index);
        blocks.push({ tagOpen: m[1], body: m[2], tagClose: m[3] });
        stage2 += tikzMarker(blocks.length);
        last = m.index + m[0].length;
    }
    stage2 += collapsed.slice(last);
    return { collapsed: stage2, blocks };
}

/** G22.S15.bis Fase 4 — Estrae i wrapper GeoGebra (`<span class="fm-geogebra-wrap">`)
 *  da `value` e li sostituisce con marker `⟨📐 GeoGebra #N⟩`. Estrae anche
 *  i `data-ggb-base64` / `data-ggb-label` e l'SVG inner per popolare blocks. */
export function collapseGeoGebraBlocks(value) {
    const blocks = [];
    let collapsed = "";
    let last = 0;
    let m;
    _GGB_WRAP_RE.lastIndex = 0;
    while ((m = _GGB_WRAP_RE.exec(value)) !== null) {
        collapsed += value.slice(last, m.index);
        const tagOpen = m[1];
        const inner   = m[2];
        const ggbMatch = tagOpen.match(/data-ggb-base64=["']([^"']*)["']/i);
        const lblMatch = tagOpen.match(/data-ggb-label=["']([^"']*)["']/i);
        const widMatch = tagOpen.match(/data-ggb-width=["']([^"']*)["']/i);
        const decode = (s) => String(s || "")
            .replace(/&amp;/g, "&")
            .replace(/&lt;/g,  "<")
            .replace(/&gt;/g,  ">")
            .replace(/&quot;/g, '"')
            .replace(/&#039;/g, "'")
            .replace(/&#39;/g,  "'");
        blocks.push({
            ggb_b64: decode(ggbMatch ? ggbMatch[1] : ""),
            label:   decode(lblMatch ? lblMatch[1] : ""),
            width:   decode(widMatch ? widMatch[1] : ""),
            svg:     inner,
        });
        collapsed += ggbMarker(blocks.length);
        last = m.index + m[0].length;
    }
    collapsed += value.slice(last);
    return { collapsed, blocks };
}

/** Espande i marker `⟨🔍 TikZ #N⟩` riconvertendo nei `<script>` originali. */
export function expandTikzMarkers(collapsed, blocks) {
    if (!blocks || !blocks.length) return collapsed;
    return collapsed.replace(_TIKZ_MARKER_RE, (full, n) => {
        const i = parseInt(n, 10) - 1;
        const b = blocks[i];
        return b ? (b.tagOpen + b.body + b.tagClose) : full;
    });
}

/** G22.S15.bis Fase 4 — Espande marker `⟨📐 GeoGebra #N⟩` riconvertendo
 *  nel wrapper HTML originale (span class fm-geogebra-wrap + SVG). */
export function expandGeoGebraMarkers(collapsed, blocks) {
    if (!blocks || !blocks.length) return collapsed;
    const escAttr = (s) => String(s).replace(/[<>&"']/g, (c) => ({"<":"&lt;",">":"&gt;","&":"&amp;",'"':"&quot;","'":"&#39;"}[c]));
    return collapsed.replace(_GGB_MARKER_RE, (full, n) => {
        const i = parseInt(n, 10) - 1;
        const b = blocks[i];
        if (!b) return full;
        const ggb = escAttr(b.ggb_b64 || "");
        const lbl = escAttr(b.label || "");
        const wid = escAttr(b.width || "");
        // Inline style: percentuale → max-width + width sul wrapper.
        // Regola globale CSS `.fm-geogebra-wrap svg{width:100%}` scala lo SVG figlio.
        let style = "";
        const m = (b.width || "").match(/^(\d+(?:\.\d+)?)%$/);
        if (m) style = `max-width:${m[1]}%;width:${m[1]}%;`;
        return `<span class="fm-geogebra-wrap"${
              ggb ? ` data-ggb-base64="${ggb}"` : ""
              }${lbl ? ` data-ggb-label="${lbl}"` : ""
              }${wid ? ` data-ggb-width="${wid}"` : ""
              }${style ? ` style="${style}"` : ""
              }>${b.svg || ""}</span>`;
    });
}
