// @ts-check
/**
 * Un contenuto sta nella scuola in cui è pubblicato (ADR-037, fase 1).
 *
 * Il docente di prova lavora in due scuole. Fino al 13 settembre 2026 un
 * contenuto si trovava per sigle: nato nella prima scuola come SCI/2/MAT,
 * compariva anche nella seconda a chi chiedeva SCI/2/MAT, perché né la barra
 * del docente né le pagine di studio guardavano la scuola. Con le
 * pubblicazioni la scuola c'è: nella scuola del contenuto lo si trova,
 * nell'altra no. La prova guarda i due versi, con le stesse sigle e lo stesso
 * docente.
 *
 * Cambiare istituto attivo cambia la sessione del docente, che le prove
 * condividono: la scuola di partenza si rimette in ogni caso, anche se la
 * prova fallisce, prima di tutto il resto della pulizia.
 */
const { test, expect } = require("../support/test");

test.describe("Area docente — i contenuti stanno nella loro scuola", () => {
    test("un contenuto nato in una scuola non si trova nell'altra scuola del docente, con le stesse sigle", async ({ teacherApi, contentFactory, cleanup, naming, env }) => {
        const http = teacherApi.http;
        /** @type {{ institutes: Array<{ id: number|string, code: string }> }} */
        const collegati = await http.getJson("/api/teacher/institutes");
        const scuole = collegati.institutes.map((i) => Number(i.id)).filter((id) => id > 0);
        // Era un `test.skip` fino al 23/9/2026 (A-29): la semina della CI
        // collega il docente di prova a due scuole, e se smette la prova deve
        // dirlo, non sparire.
        expect(scuole.length, "il docente di prova è collegato ad almeno due scuole").toBeGreaterThanOrEqual(2);

        /** @type {{ current_institute_id: number|null }} */
        const corrente = await http.getJson("/api/tenant/current");
        const partenza = Number(corrente.current_institute_id || scuole[0]);
        const altra = scuole.find((id) => id !== partenza);
        expect(altra, "un'altra scuola del docente").toBeTruthy();

        const rimetti = async () => {
            await http.postForm("/api/tenant/switch", { institute_id: String(partenza) });
        };
        cleanup.add("teacher", "rimetti la scuola attiva di partenza", rimetti);

        await http.postForm("/api/tenant/switch", { institute_id: String(partenza) });
        const terna = env.terna;
        const documento = await contentFactory.document({ title: naming.unique("per-scuola"), visibility: "published", terna });

        /** @param {string} url @param {Record<string,string>} parametri */
        const idDi = async (url, parametri) => {
            /** @type {{ rows: Array<{ id: number|string }> }} */
            const j = await http.getJson(url, parametri);
            return j.rows.map((r) => Number(r.id));
        };
        const barra = () => idDi("/api/teacher/content", {
            indirizzo: terna.indirizzo, classe: terna.classe, subject: terna.materia, type: "document", limit: "500",
        });
        const studio = () => idDi("/api/study/content.json", {
            type: "document", ind: terna.indirizzo, cls: terna.classe, subject: terna.materia, limit: "500",
        });

        expect(await barra(), "nella sua scuola la barra lo elenca").toContain(documento.id);
        expect(await studio(), "e la pagina di studio lo mostra").toContain(documento.id);

        await http.postForm("/api/tenant/switch", { institute_id: String(altra) });
        expect(await barra(), "nell'altra scuola, con le stesse sigle, la barra non lo elenca").not.toContain(documento.id);
        expect(await studio(), "né la pagina di studio lo mostra").not.toContain(documento.id);

        await rimetti();
        expect(await barra(), "tornati nella sua scuola, c'è di nuovo").toContain(documento.id);
    });
});
