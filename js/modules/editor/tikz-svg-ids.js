/**
 * Id degli SVG dei TikZ resi dal server: rinomina e prefissi unici.
 *
 * Funzioni pure, separate da tikz-render-client.js il 23/9/2026 perché le
 * prove le importino senza avviare il rendering automatico di quel modulo
 * (tests/js-unit/tikz-svg-id-isolation.test.js). tikz-render-client.js le
 * importa e le riesporta.
 */

/**
 * Counter monotonico per generare prefix unico per ogni SVG embedded.
 * Reset implicito al page reload (module-level state).
 */
let _svgInstanceCounter = 0;

/**
 * Rinomina tutti gli `id` interni di un SVG e i relativi riferimenti.
 *
 * Background: `dvisvgm --no-fonts` emette IDs generici (`g0-1`, `g1-2`,
 * `page1`, `cp0`, ...) sia per i `<path>` defs sia per `<clipPath>` ecc.
 * Quando MULTIPLE SVG sono inline nello stesso documento, gli IDs collidono
 * e il browser resolve `xlink:href='#g1-1'` al PRIMO match nel DOM →
 * glifo wrong di un altro SVG (es. matrix mostra "P;" invece di "-5").
 *
 * Fix: rinomina tutti gli `id='X'` in `id='<prefix>_X'` e tutti i
 * riferimenti `#X` (`xlink:href`, `href`, `url(#X)`) coerentemente.
 *
 * @param {string} svgString — SVG source come stringa
 * @param {string} prefix    — prefix unico per questa istanza (es. `tkAB12_3`)
 * @returns {string} SVG con IDs prefissati
 */
export function renameSvgIds(svgString, prefix) {
  if (!svgString || !prefix) return svgString;
  return svgString
    // id='X' → id='<prefix>_X'
    .replace(/(\bid=['"])([^'"]+)(['"])/g,
      (_m, p1, id, p3) => `${p1}${prefix}_${id}${p3}`)
    // href / xlink:href / "#X" → "#<prefix>_X" (singolo regex cattura entrambi)
    .replace(/((?:xlink:)?href=['"]#)([^'"]+)(['"])/g,
      (_m, p1, id, p3) => `${p1}${prefix}_${id}${p3}`)
    // url(#X) → url(#<prefix>_X)  (per fill, stroke, clip-path, mask, filter)
    .replace(/(url\(#)([^)]+)(\))/g,
      (_m, p1, id, p3) => `${p1}${prefix}_${id}${p3}`);
}

/**
 * Genera un prefix unico per un'istanza SVG. Combinazione hash + counter
 * garantisce unicità anche tra due SVG identici (stesso hash, due copie
 * embed nella pagina = collisione se solo hash).
 */
export function makeSvgPrefix(hash) {
  return `tk${(hash || '').substring(0, 6)}_${_svgInstanceCounter++}`;
}
