/**
 * Bonifica lato client per i blocchi TikZ e GeoGebra nel mirror JS di
 * ContractRenderer::renderBlocks (`js/modules/features/checkin-handlers.js`,
 * `BLOCK_RENDERERS.tikz`/`.geogebra`).
 *
 * Il PHP passa il corpo TikZ da `TikzScriptValidator::sanitize()` e l'SVG
 * GeoGebra da `SvgSanitizer::sanitize()` (enshrined/svg-sanitize) prima di
 * scriverli nell'HTML del contratto — quello che arriva agli studenti è
 * sempre bonificato. Il mirror JS, che aggiorna il DOM subito dopo un
 * salvataggio (senza ricaricare la pagina), non applicava NESSUNA delle due
 * bonifiche: revisione architetturale del 23/9/2026, rilievo A-20 (voce 125
 * del registro del debito). Uno script TikZ o un SVG GeoGebra incollati (o
 * appena creati nel dialog, che esporta l'SVG direttamente dall'applet nel
 * browser, senza passare dal server) restavano non bonificati nella sessione
 * del docente fino al prossimo ricaricamento della pagina — dove il render
 * server, questo sì bonificato, prende il sopravvento.
 *
 * Defense-in-depth, non autoritativa (come `html-sanitize-client.js` e
 * `js/modules/risdoc/pt/html-sanitizer.js` in questo stesso repository): il
 * server resta la fonte di verità per ciò che arriva agli studenti. Qui si
 * chiude solo la finestra della sessione del docente.
 *
 * `sanitizeGeogebraSvg` NON riusa la libreria PHP (enshrined/svg-sanitize:
 * nessun equivalente Node nel progetto): riparsa l'SVG con `DOMParser` in
 * modalità **XML** (`image/svg+xml`, non `text/html` — quella abbasserebbe
 * gli attributi camelCase come `viewBox`/`preserveAspectRatio`, rompendo la
 * figura) e cammina l'albero con una LISTA BIANCA di elementi di disegno.
 * Una prima versione a espressioni regolari (23/9/2026) si aggirava con
 * `<animate>`/`<set>` che riscrivono un attributo a runtime, entità
 * numeriche (`&#106;avascript:`), spazi/tabulazioni dentro lo schema URI, e
 * `<style>` con `@import`: misurato eseguendo quella versione sui vettori,
 * vedi tests/js-unit/tikz-geogebra-client-sanitize.test.js. La lista bianca
 * (positiva, non negativa) è più severa della libreria server su alcuni punti
 * (qui `<style>` sparisce sempre, `href`/`xlink:href` accettano solo un
 * frammento interno `#id`) e non prova a coprire ogni caso che la libreria
 * server copre: sufficiente per il rischio descritto (la sessione del
 * docente prima del ricaricamento), non un sostituto della bonifica
 * autoritativa.
 */

/**
 * Mirror di `TikzScriptValidator::sanitize()`: neutralizza `</` letterale nel
 * corpo TikZ (idempotente — un `</` già preceduto da escape `<\/` non viene
 * ritoccato) così un `\end{tikzpicture}</script><script>...` incollato non
 * chiude in anticipo lo `<script type="text/tikz">` che lo contiene.
 * @param {string} script
 * @returns {string}
 */
export function escapeTikzScriptClosing(script) {
    const s = String(script ?? "");
    if (s === "") return s;
    return s.replace(/<\/(?!\\)/g, "<\\/");
}

const SVG_NS = "http://www.w3.org/2000/svg";

/** Elementi di disegno SVG ammessi (lista bianca — tutto il resto si toglie,
 *  sottoalbero incluso: `script`, `foreignObject`, `animate`/`animate*`/
 *  `set` che riscrivono un attributo a runtime, `style`, `image`, `a`,
 *  `iframe`, …). Casing esatto (XML è case-sensitive: `clipPath` non è
 *  `clippath`). */
const ALLOWED_ELEMENTS = new Set([
    "svg", "g", "path", "rect", "circle", "ellipse", "line", "polyline", "polygon",
    "text", "tspan", "defs", "clipPath", "linearGradient", "radialGradient", "stop",
    "marker", "pattern", "title", "desc", "symbol", "use",
]);

/** Valore d'attributo pronto per un confronto sicuro: le entità (anche
 *  numeriche, `&#106;avascript:`) le ha già decodificate il parser XML
 *  leggendo `.value`; qui si tolgono solo spazi/tabulazioni/controlli
 *  (`java\tscript:`) e si abbassa il caso. */
function normalizeAttrValue(v) {
    return String(v ?? "").replace(/[\s\x00-\x1f]/g, "").toLowerCase();
}

function valorePericoloso(v) {
    const n = normalizeAttrValue(v);
    // eslint-disable-next-line no-script-url -- confronto di stringa normalizzata, non un URL assegnato.
    return n.startsWith("javascript:") || n.startsWith("data:") || n.startsWith("vbscript:");
}

/** `style` pericoloso: gli schemi già esclusi altrove, o un `url(...)` che
 *  non punta a un frammento interno (`url(#gradiente)` è legittimo — i
 *  gradienti GeoGebra funzionano così — un `url(http://…)` o `url(data:…)`
 *  no). Tutto o niente: un `style` sospetto si toglie per intero, non si
 *  prova a ripulirlo dichiarazione per dichiarazione. */
function styleEPericoloso(raw) {
    const n = normalizeAttrValue(raw);
    // eslint-disable-next-line no-script-url -- confronto di stringa normalizzata, non un URL assegnato.
    if (n.includes("javascript:") || n.includes("expression(") || n.includes("vbscript:")) return true;
    const urlRe = /url\(\s*(['"]?)([^'")]*)\1\s*\)/gi;
    let m;
    while ((m = urlRe.exec(raw)) !== null) {
        if (!m[2].trim().startsWith("#")) return true;
    }
    return false;
}

/** Bonifica gli attributi di UN elemento già ammesso dalla lista bianca:
 *  toglie ogni `on*`; `href`/`xlink:href` (qualunque namespace/casing —
 *  confronto su `localName` abbassato) solo se punta a un frammento interno
 *  `#…` (così `<use href="#id">` resta, `<use href="//host/x.svg#y">` no);
 *  `style` pericoloso tolto per intero; qualunque altro attributo il cui
 *  valore comincia con uno schema pericoloso, tolto a prescindere dal nome
 *  (copre un `xlink:HREF` scritto maiuscolo o un attributo non previsto). */
function bonificaAttributi(el) {
    for (const attr of Array.from(el.attributes)) {
        const ln = attr.localName.toLowerCase();
        if (ln.startsWith("on")) { el.removeAttribute(attr.name); continue; }
        if (ln === "href") {
            if (!String(attr.value ?? "").trim().startsWith("#")) el.removeAttribute(attr.name);
            continue;
        }
        if (ln === "style") {
            if (styleEPericoloso(attr.value)) el.removeAttribute(attr.name);
            continue;
        }
        if (valorePericoloso(attr.value)) el.removeAttribute(attr.name);
    }
}

/** Cammina l'albero (già verificato radice `<svg>`) e toglie ogni elemento
 *  fuori lista bianca o fuori dal namespace SVG, sottoalbero incluso;
 *  bonifica gli attributi di quelli che restano. */
function bonificaAlbero(root) {
    bonificaAttributi(root);
    for (const child of Array.from(root.childNodes)) {
        if (child.nodeType === Node.COMMENT_NODE) { child.remove(); continue; }
        if (child.nodeType !== Node.ELEMENT_NODE) continue; // testo: si tiene
        if (child.namespaceURI !== SVG_NS || !ALLOWED_ELEMENTS.has(child.localName)) {
            child.remove();
            continue;
        }
        bonificaAlbero(child);
    }
}

/**
 * Bonifica l'SVG GeoGebra prima di inserirlo nel DOM: parsing XML vero
 * (`image/svg+xml`) con lista bianca di elementi e attributi. Un SVG
 * malformato o senza radice `<svg>` diventa stringa vuota — niente figura è
 * meglio di una figura non bonificata.
 * @param {string} svg
 * @returns {string}
 */
export function sanitizeGeogebraSvg(svg) {
    const s = String(svg ?? "");
    if (s === "") return s;
    let doc;
    try {
        doc = new DOMParser().parseFromString(s, "image/svg+xml");
    } catch {
        return "";
    }
    if (doc.getElementsByTagName("parsererror").length > 0) return "";
    const root = doc.documentElement;
    if (!root || root.namespaceURI !== SVG_NS || root.localName !== "svg") return "";
    bonificaAlbero(root);
    return new XMLSerializer().serializeToString(root);
}
