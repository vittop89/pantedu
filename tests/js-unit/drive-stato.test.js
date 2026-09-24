import { describe, it, expect, beforeEach } from "vitest";
import { descriviDrive, driveAcceso, motivoDelFermo, MOTIVI, RITORNI, testoPer } from "../../js/modules/features/drive-stato.js";

/**
 * Lo stato di Drive per il docente (ADR-038, 14/9/2026).
 *
 * La suite end-to-end vede un solo stato, quello dell'installazione su cui
 * gira (in CI Drive è spento). Qui ci sono tutti, ognuno nei due versi: il
 * comando c'è quando deve esserci, e non c'è quando non deve.
 */

describe("descriviDrive", () => {
    it("Drive spento e nessun collegamento: nessun comando", () => {
        const d = descriviDrive({ ok: true, istanza: "spento", connected: false });
        expect(d.azioni).toEqual([]);
        expect(d.testo).toContain("non è attivo");
    });

    it("Drive spento con un collegamento di prima: si può solo disconnettere", () => {
        const d = descriviDrive({ ok: true, istanza: "spento", connected: true, email: "a@example.invalid" });
        expect(d.azioni).toEqual(["disconnetti"]);
        expect(d.azioni, "collegarsi non si offre").not.toContain("collega");
        expect(d.testo).toContain("a@example.invalid");
    });

    it("Drive guasto: lo dice, e non offre di collegarsi", () => {
        expect(descriviDrive({ istanza: "guasto", connected: false }).azioni).toEqual([]);
        expect(descriviDrive({ istanza: "guasto", connected: false }).testo).toContain("amministratore");
        expect(descriviDrive({ istanza: "guasto", connected: true }).azioni).toEqual(["disconnetti"]);
    });

    it("Drive acceso e nessun collegamento: si offre di collegarsi", () => {
        const d = descriviDrive({ istanza: "acceso", connected: false });
        expect(d.azioni).toEqual(["collega"]);
        expect(d.stato).toBe("disconnected");
    });

    it("collegamento attivo: disconnettere, e l'ultima sincronizzazione", () => {
        const d = descriviDrive({ istanza: "acceso", connected: true, stato: "attivo", last_sync_at: "2026-09-14 03:40:37" });
        expect(d.stato).toBe("connected");
        expect(d.azioni).toEqual(["disconnetti"]);
        expect(d.testo).toContain("2026-09-14 03:40:37");
        expect(descriviDrive({ istanza: "acceso", connected: true, stato: "attivo" }).testo).toContain("mai sincronizzato");
    });

    it("da ricollegare: il motivo, da quando, e il comando per ricollegare", () => {
        const d = descriviDrive({
            istanza: "acceso", connected: true, stato: "da_ricollegare", motivo: "accesso_revocato", dal: "2026-09-14 03:40:37",
        });
        expect(d.stato).toBe("warning");
        expect(d.azioni).toEqual(["ricollega", "disconnetti"]);
        expect(d.testo).toContain(MOTIVI.accesso_revocato);
        expect(d.testo).toContain("2026-09-14 03:40:37");
        const permessi = descriviDrive({ istanza: "acceso", connected: true, stato: "da_ricollegare", motivo: "permessi_insufficienti" });
        expect(permessi.testo).toContain(MOTIVI.permessi_insufficienti);
    });

    it("un collegamento attivo non chiede di ricollegare; un motivo sconosciuto non lascia il testo vuoto", () => {
        expect(descriviDrive({ istanza: "acceso", connected: true, stato: "attivo" }).azioni).not.toContain("ricollega");
        const ignoto = descriviDrive({ istanza: "acceso", connected: true, stato: "da_ricollegare", motivo: "boh" });
        expect(ignoto.testo).toContain("Google ha rifiutato il collegamento");
    });

    it("senza risposta, o senza lo stato dell'installazione, vale spento", () => {
        expect(descriviDrive(null).azioni).toEqual([]);
        expect(descriviDrive({ ok: true, connected: false }).azioni, "un server di prima non offre di collegarsi").toEqual([]);
    });
});

describe("driveAcceso", () => {
    beforeEach(() => {
        document.body.innerHTML = "";
    });

    function barra(stato) {
        const div = document.createElement("div");
        div.className = "fm-session-banner fm-session-banner--teacher";
        if (stato !== undefined) div.dataset.fmDrive = stato;
        document.body.appendChild(div);
    }

    it("sì solo se il server ha scritto acceso", () => {
        barra("acceso");
        expect(driveAcceso()).toBe(true);
    });

    it("no con spento, guasto, attributo assente o barra assente", () => {
        for (const stato of ["spento", "guasto", undefined]) {
            document.body.innerHTML = "";
            barra(stato);
            expect(driveAcceso(), String(stato)).toBe(false);
        }
        document.body.innerHTML = "";
        expect(driveAcceso(), "senza barra").toBe(false);
        expect(driveAcceso(null)).toBe(false);
    });
});

describe("motivoDelFermo e i ritorni da Google", () => {
    it("ogni fermo ha la sua frase, e quello da ricollegare dice dove", () => {
        expect(motivoDelFermo("drive_da_ricollegare", "accesso_revocato")).toContain("Ricollega");
        expect(motivoDelFermo("drive_da_ricollegare", "accesso_revocato")).toContain(MOTIVI.accesso_revocato);
        expect(motivoDelFermo("drive_not_connected")).toContain("Collega Drive");
        expect(motivoDelFermo("drive_spento")).toContain("non è attivo");
        expect(motivoDelFermo("drive_guasto")).toContain("incompleta");
        expect(motivoDelFermo("altro")).toContain("altro");
    });

    it("i ritorni nuovi dal server hanno un messaggio", () => {
        for (const chiave of ["connected", "denied", "error", "non_disponibile", "permessi"]) {
            expect(testoPer(RITORNI, chiave), chiave).toBeTruthy();
        }
    });

    it("una parola dell'indirizzo che non è un ritorno non trova niente, nemmeno fra le proprietà di ogni oggetto", () => {
        for (const chiave of ["constructor", "__proto__", "toString", "hasOwnProperty", "boh", "", null, 1]) {
            expect(testoPer(RITORNI, chiave), String(chiave)).toBeNull();
        }
        expect(motivoDelFermo("drive_da_ricollegare", "constructor")).toContain("Google ha rifiutato il collegamento");
    });
});
