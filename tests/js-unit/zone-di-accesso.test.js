import { describe, it, expect } from "vitest";
import { haZona } from "../../js/modules/core/zone-di-accesso.js";

/**
 * La zona si legge da `data-fm-zones`, che scrive il server (Auth::zone), e mai
 * dal ruolo (14/9/2026). I due versi: la zona c'è, la zona non c'è.
 */
function corpo(attributi) {
    const b = document.createElement("body");
    Object.assign(b.dataset, attributi);
    return b;
}

describe("haZona", () => {
    it("trova una zona fra quelle scritte dal server", () => {
        const b = corpo({ fmZones: "public student teacher" });
        expect(haZona("teacher", b)).toBe(true);
        expect(haZona("public", b)).toBe(true);
    });

    it("non trova una zona che il server non ha scritto", () => {
        const b = corpo({ fmZones: "public student teacher" });
        expect(haZona("admin", b)).toBe(false);
        expect(haZona("istituto", b)).toBe(false);
    });

    it("non si fida del ruolo: con data-fm-role e senza zone non c'è nessuna zona", () => {
        for (const ruolo of ["administrator", "admin", "teacher"]) {
            expect(haZona("teacher", corpo({ fmRole: ruolo }))).toBe(false);
        }
    });

    it("regge spazi in più, attributo vuoto e body assente", () => {
        expect(haZona("teacher", corpo({ fmZones: "  public   teacher " }))).toBe(true);
        expect(haZona("teacher", corpo({ fmZones: "" }))).toBe(false);
        expect(haZona("teacher", null)).toBe(false);
        expect(haZona("teach", corpo({ fmZones: "teacher" })), "una parte del nome non basta").toBe(false);
    });
});
