/**
 * La motivazione che accompagna le mutazioni amministrative.
 *
 * Da quando AUDIT_REASON_MODE è `enforce`, una motivazione più corta di dieci
 * caratteri fa respingere la richiesta con un 400. L'helper deve quindi
 * garantire quel minimo: se il contesto passato dal chiamante è troppo scarno,
 * lo completa invece di lasciar rompere il pannello.
 */
import { describe, it, expect } from "vitest";
import { auditReason, auditHeaders } from "../../js/modules/core/audit-reason.js";

describe("auditReason", () => {
    it("tiene il contesto quando basta da solo", () => {
        expect(auditReason("Creazione template istituzionale"))
            .toBe("Creazione template istituzionale");
    });

    it("accoda la nota dell'utente al contesto", () => {
        expect(auditReason("Rifiuto revisione #12", "formule sbagliate"))
            .toBe("Rifiuto revisione #12 — formule sbagliate");
    });

    it("non lascia mai passare meno di dieci caratteri", () => {
        // Sotto i dieci il middleware risponde 400: meglio una motivazione
        // completata d'ufficio che un pannello che smette di funzionare.
        expect(auditReason("ok").length).toBeGreaterThanOrEqual(10);
        expect(auditReason("").length).toBeGreaterThanOrEqual(10);
        expect(auditReason(null, "").length).toBeGreaterThanOrEqual(10);
    });

    it("resta entro il massimo accettato dal middleware", () => {
        expect(auditReason("x".repeat(400)).length).toBe(255);
    });

    it("usa la sola nota se il contesto manca", () => {
        expect(auditReason("", "cambio richiesto dalla segreteria"))
            .toBe("cambio richiesto dalla segreteria");
    });

    it("ignora spazi a vuoto attorno ai pezzi", () => {
        expect(auditReason("  Modifica scheda  ", "  numero 3  "))
            .toBe("Modifica scheda — numero 3");
    });
});

describe("auditHeaders", () => {
    it("mette insieme content-type, CSRF e motivazione", () => {
        const h = auditHeaders("tok123", "Modifica configurazione WAF");
        expect(h["X-CSRF-Token"]).toBe("tok123");
        expect(h["X-Audit-Reason"]).toBe("Modifica configurazione WAF");
        expect(h["Content-Type"]).toBe("application/x-www-form-urlencoded");
    });

    it("accetta un content-type diverso", () => {
        expect(auditHeaders("t", "Salvataggio file modello", "", "application/json")["Content-Type"])
            .toBe("application/json");
    });
});
