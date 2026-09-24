/**
 * Il mirror JS di ContractRenderer::renderBlocks (`BLOCK_RENDERERS` in
 * `js/modules/features/checkin-handlers.js`) aggiorna il DOM subito dopo un
 * salvataggio, senza ricaricare la pagina. I renderer `tikz`/`geogebra` non
 * bonificavano né il corpo TikZ né l'SVG GeoGebra come fa il PHP: revisione
 * architetturale del 23/9/2026, rilievo A-20 (voce 125 del registro del
 * debito). Uno script o un SVG incollati (o appena creati nel dialog
 * GeoGebra, che esporta l'SVG dall'applet senza passare dal server) restavano
 * non bonificati nella sessione del docente fino al prossimo ricaricamento.
 *
 * Questa prova esercita `BLOCK_RENDERERS` DIRETTAMENTE (esportato apposta da
 * checkin-handlers.js): non basta provare `tikz-geogebra-client-sanitize.js`
 * da solo, perché il difetto era che il renderer non lo chiamava.
 */
import { describe, it, expect } from "vitest";
import { BLOCK_RENDERERS } from "../../js/modules/features/checkin-handlers.js";

describe("BLOCK_RENDERERS.tikz — neutralizza </  nel corpo", () => {
    it("un </script> incollato nel sorgente TikZ non chiude in anticipo lo script wrapper", () => {
        const html = BLOCK_RENDERERS.tikz({
            script: "\\end{tikzpicture}</script><script>alert(1)</script>",
        });
        // Il body non deve contenere un `</script>` non-escapato PRIMA della
        // chiusura vera del wrapper: da solo un `.not.toContain("</script>")`
        // fallirebbe sempre (la chiusura vera esiste), quindi si conta quante
        // sequenze `</script` compaiono SENZA lo backslash di escape davanti.
        const nonEscapate = (html.match(/(?<!\\)<\/script\b/gi) || []).length;
        expect(nonEscapate, html).toBe(1); // solo la chiusura vera del wrapper
    });

    it("un corpo TikZ senza </  resta identico (nessun escape superfluo)", () => {
        const html = BLOCK_RENDERERS.tikz({ script: "\\draw (0,0) -- (1,1);" });
        expect(html).toContain("\\draw (0,0) -- (1,1);");
    });
});

describe("BLOCK_RENDERERS.geogebra — bonifica l'SVG prima di inserirlo", () => {
    it("uno <script> dentro l'SVG non arriva nel DOM", () => {
        const html = BLOCK_RENDERERS.geogebra({
            svg: '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><circle r="1"/></svg>',
        });
        expect(html).not.toContain("<script");
        expect(html).toContain("<circle");
    });

    it("un onload sull'SVG non arriva nel DOM", () => {
        const html = BLOCK_RENDERERS.geogebra({
            svg: '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect/></svg>',
        });
        expect(html).not.toMatch(/\son\w+\s*=/i);
    });

    it("un SVG pulito arriva invariato, attributi camelCase compresi", () => {
        const pulito = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" preserveAspectRatio="xMidYMid"><circle r="1"/></svg>';
        const html = BLOCK_RENDERERS.geogebra({ svg: pulito });
        expect(html).toContain(pulito);
    });
});
