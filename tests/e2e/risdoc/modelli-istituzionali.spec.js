// @ts-check
/**
 * Modelli dell'Istituto: chi li vede, chi ne cambia il corpo di partenza.
 * Riscrittura di risdoc_pt_template_picker.spec.js e
 * risdoc_template_visibility_default.spec.js.
 *
 * I modelli sono documenti dell'Istituto, senza proprietario. Ogni docente
 * autenticato li vede, anche chi non amministra niente: è da lì che parte per
 * costruire i propri. Il loro corpo di partenza, però, lo cambia solo un
 * amministratore — e questo test è quello che se ne accorge se un giorno il
 * controllo dovesse allentarsi.
 *
 * Cosa cambia rispetto a prima: niente login nella spec e nessun secondo
 * contesto di browser aperto a mano per l'amministratore (le due sessioni sono
 * fixture), il corpo di prova viene ripristinato dal registro di pulizia anche
 * se il test fallisce a metà, e il caso «il docente non vede i modelli» non
 * viene più saltato in silenzio quando le credenziali del secondo docente non
 * funzionano: se non funzionano è un errore.
 */
const { test, expect } = require("../support/test");

const CORPO_DI_PARTENZA = [
    { _type: "sectionHeader", level: 1, text: "TITOLO_DEL_MODELLO" },
    { _type: "block", style: "normal", children: [{ _type: "span", text: "CORPO_DEL_MODELLO", marks: [] }] },
];

test.describe("Risdoc — modelli dell'Istituto", () => {
    test("il corpo di partenza salvato dall'amministratore torna nell'elenco dei modelli", async ({ teacherApi, adminApi, cleanup }) => {
        const modello = await teacherApi.risdoc.unTemplate({ origin: "risdoc" });

        const salvato = await adminApi.risdoc.saveBodyPt(modello.id, CORPO_DI_PARTENZA);
        expect(salvato.ok, `salvataggio del corpo di partenza → ${salvato.status}`).toBe(true);
        cleanup.add("admin", `svuota il corpo di partenza del modello ${modello.id}`, async () => {
            await adminApi.risdoc.saveBodyPt(modello.id, "");
        });

        const conCorpo = await teacherApi.risdoc.templates({ origin: "risdoc", withBodyPt: true });
        const trovato = conCorpo.find((m) => m.id === modello.id);
        expect(trovato, "il modello è nell'elenco").toBeTruthy();
        const corpo = /** @type {any[]} */ (trovato?.body_pt);
        expect(Array.isArray(corpo), "il corpo arriva come lista di blocchi").toBe(true);
        expect(corpo[0]?.text ?? corpo[0]?.title, "il primo blocco è quello salvato").toBe("TITOLO_DEL_MODELLO");
    });

    test("il corpo di partenza al docente è negato", async ({ teacherApi }) => {
        const modello = await teacherApi.risdoc.unTemplate({ origin: "risdoc" });

        const tentativo = await teacherApi.risdoc.saveBodyPt(modello.id, CORPO_DI_PARTENZA);
        expect(tentativo.ok, "il docente non può cambiare il modello dell'Istituto").toBe(false);
        expect(tentativo.status, "e l'app lo dice con un divieto esplicito").toBe(403);
    });

    test("anche un docente senza poteri di amministrazione vede i modelli", async ({ teacher2Api }) => {
        const modelli = await teacher2Api.risdoc.templates({ origin: "risdoc" });
        expect(modelli.length, "almeno un modello dell'Istituto è visibile").toBeGreaterThanOrEqual(1);
    });

    test("la pagina del curriculum è aperta ai docenti, non ai soli amministratori", async ({ teacherApi }) => {
        const risposta = await teacherApi.http.request.get("/admin/curriculum");
        expect(risposta.status(), "la pagina risponde al docente").toBe(200);
    });
});
