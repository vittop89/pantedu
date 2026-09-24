import { describe, it, expect } from "vitest";
import { ritentaIl422 } from "../../js/modules/editor/tikz-render-client.js";

/**
 * Un disegno sbagliato non si ritenta (24/9/2026).
 *
 * Il render TikZ ritenta un 422 quando sembra un intoppo del servizio (log
 * corto o senza righe «!»). Dal 24/9 il servizio manda gli errori di pdflatex
 * in `errors`, con un estratto che può essere corto: senza questa regola un
 * errore vero si ritentava cinque volte con l'attesa, per poi mostrarsi
 * uguale. Nei due versi: con errori no, senza errori come prima.
 */
describe("tikz-render-client: quando ritentare un 422", () => {
    it("con gli errori di pdflatex non ritenta, anche se l'estratto è corto", () => {
        const j = { log: "pdflatex ha trovato 1 errore:\n\n! Undefined control sequence.\nl.5 \\x", errors: [{ line: 5, message: "Undefined control sequence." }] };
        expect(j.log.length).toBeLessThan(500);
        expect(ritentaIl422(j)).toBe(false);
    });

    it("senza errori e con un log corto ritenta: è un intoppo del servizio", () => {
        expect(ritentaIl422({ log: "pdflatex TIMEOUT dopo 20s", errors: [] })).toBe(true);
        expect(ritentaIl422({ log: "" })).toBe(true);
    });

    it("con un servizio di prima (niente errors) vale la regola di prima", () => {
        const lungo = "x\n".repeat(300) + "! Paragraph ended before \\pgffor@normal@list was complete.\n";
        expect(ritentaIl422({ log: lungo })).toBe(false);
        expect(ritentaIl422({ log: "y".repeat(800) })).toBe(true);
    });
});
