import { describe, it, expect, vi, afterEach } from "vitest";

/**
 * Il rendering automatico dei TikZ non esplode se il suo timer scatta
 * quando la pagina non c'è più (23/9/2026).
 *
 * Importato, tikz-render-client.js programma un giro di rendering a 50 ms.
 * In CI il timer è scattato dopo lo smontaggio dell'ambiente di una prova
 * che importava il modulo: `document is not defined` in `_renderVisible`,
 * errore non gestito e Vitest rosso con tutte le prove verdi. Qui il timer
 * si fa scattare a mano, con `document` tolto: prima lanciava, ora no.
 */
describe("tikz-render-client: il timer dopo la pagina", () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it("non lancia se document non c'è più quando scatta", async () => {
        vi.useFakeTimers();
        vi.resetModules();
        await import("../../js/modules/editor/tikz-render-client.js");
        vi.stubGlobal("document", undefined);
        expect(() => vi.advanceTimersByTime(200)).not.toThrow();
    });
});
