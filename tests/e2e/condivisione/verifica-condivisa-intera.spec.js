// @ts-check
/**
 * Una verifica si condivide intera, e il collega la recupera (14/9/2026).
 *
 * Una verifica sono le sue varianti: con lo stesso titolo, SOL, NOR, DSA e DIS.
 * Fino a questa correzione il pulsante di condivisione cambiava una variante
 * sola, il pool elencava ogni variante come una voce, e «Recupera» mandava
 * l'id della verifica all'API dei contenuti, che recuperava un altro contenuto
 * o niente. Qui: il docente condivide da una variante, e la verifica compare
 * intera e una volta sola nel suo elenco e nel pool del collega; il ritiro da
 * una variante la toglie tutta; il collega la recupera nel suo account, e il
 * proprietario non può recuperare la propria.
 *
 * Le varianti le salva e le cancella la factory; la copia del collega la
 * cancella il registro di pulizia del collega.
 */
const { test, expect } = require("../support/test");

test.describe("Condivisione — una verifica intera", () => {
    test("condivisa da una variante vale per tutte, nel pool compare una volta, e il ritiro la toglie tutta", async ({ verificaFactory, teacherApi, teacher2Api, naming }) => {
        const titolo = naming.unique("verifica-intera");
        const batch = await verificaFactory.batch({ title: titolo, versions: ["A"], versionLabel: "intera" });
        const ids = batch.docs.map((d) => Number(d.id));
        expect(ids.length, "più di una variante").toBeGreaterThan(1);

        const condivisione = await teacherApi.verifica.setPoolSharing(Number(ids[0]), true);
        expect(condivisione.status, condivisione.text.slice(0, 200)).toBe(200);
        expect(condivisione.body?.varianti, "la condivisione vale per tutte le varianti").toBe(ids.length);

        const mie = (await teacherApi.share.myShares()).items.filter((i) => i.source === "verifica_documents" && i.title === titolo);
        expect(mie.length, "nei miei condivisi, una voce").toBe(1);
        expect([...(mie[0]?.ids ?? [])].sort((a, b) => a - b), "con tutte le varianti").toEqual([...ids].sort((a, b) => a - b));

        const pool = await teacher2Api.share.poolMaterials({ content_type: "verifica_doc" });
        const voci = pool.items.filter((i) => i.source === "verifica_documents" && i.title === titolo);
        expect(voci.length, "nel pool del collega, una voce").toBe(1);
        expect(voci[0]?.varianti).toBe(ids.length);

        const ritiro = await teacherApi.share.unshare([{ source: "verifica_documents", id: Number(ids[ids.length - 1]) }]);
        expect(ritiro.ok).toBe(true);
        expect(ritiro.total, "ritirata da una variante, ritirate tutte").toBe(ids.length);
        const dopo = await teacher2Api.share.poolMaterials({ content_type: "verifica_doc" });
        expect(dopo.items.filter((i) => i.source === "verifica_documents" && i.title === titolo), "e il collega non la vede più").toEqual([]);
    });

    test("il collega recupera la verifica condivisa nel suo account, il proprietario no", async ({ verificaFactory, teacherApi, teacher2Api, cleanup, naming }) => {
        const titolo = naming.unique("verifica-da-recuperare");
        const batch = await verificaFactory.batch({ title: titolo, versions: ["A"], versionLabel: "recupero" });
        const ids = batch.docs.map((d) => Number(d.id));
        const condivisione = await teacherApi.verifica.setPoolSharing(Number(ids[0]), true);
        expect(condivisione.status, condivisione.text.slice(0, 200)).toBe(200);

        const voce = (await teacher2Api.share.poolMaterials({ content_type: "verifica_doc" })).items
            .find((i) => i.source === "verifica_documents" && i.title === titolo);
        expect(voce, "il collega vede la verifica nel pool").toBeTruthy();
        if (!voce) return;

        // Il posto: una scuola del collega con indirizzo, classe e materia spuntati.
        /** @type {{ scuole: Array<{ id: number, indirizzi: Array<{id:number,code:string}>, classi: Array<{id:number,code:string,indirizzo:string|null}>, materie: Array<{id:number,code:string}> }> }} */
        const luoghi = await teacher2Api.http.getJson("/api/teacher/pubblicazioni/luoghi");
        /** @type {{ indirizzo: number, classe: number, materia: number } | null} */
        let posto = null;
        for (const s of luoghi.scuole) {
            for (const ind of s.indirizzi) {
                const cls = s.classi.find((c) => !c.indirizzo || c.indirizzo.toUpperCase() === ind.code.toUpperCase());
                const mat = s.materie.find((m) => m.code === voce.subject_code) ?? s.materie[0];
                if (cls && mat) { posto = { indirizzo: ind.id, classe: cls.id, materia: mat.id }; break; }
            }
            if (posto) break;
        }
        // Era un `test.skip` fino al 23/9/2026 (A-29): la semina della CI fa
        // spuntare al collega le voci della prima scuola.
        expect(posto, "il collega di prova ha spuntato indirizzo, classe e materia in una scuola").not.toBeNull();
        if (!posto) return;

        const proprio = await teacherApi.http.send("POST", `/api/teacher/pool/recover-verifica/${voce.id}`, {
            form: { target_subject_id: String(posto.materia), target_indirizzo_id: String(posto.indirizzo), target_classe_id: String(posto.classe) },
        });
        expect(proprio.status, "il proprietario non recupera la propria verifica").toBeGreaterThanOrEqual(400);

        const recupero = await teacher2Api.http.send("POST", `/api/teacher/pool/recover-verifica/${voce.id}`, {
            form: { target_subject_id: String(posto.materia), target_indirizzo_id: String(posto.indirizzo), target_classe_id: String(posto.classe) },
        });
        expect(recupero.status, recupero.text.slice(0, 300)).toBe(200);
        /** @type {{ ok: boolean, new_id: number, varianti: number }} */
        const esito = /** @type {any} */ (recupero.body);
        cleanup.add("teacher2", "cancella la verifica recuperata", async () => {
            const via = await teacher2Api.http.send("POST", `/api/verifica/${esito.new_id}/delete`, { form: {} });
            if (!via.ok && via.status !== 404) throw new Error(`cancellazione della copia: ${via.status}`);
        });
        expect(esito.varianti, "tutte le varianti del pacchetto").toBe(ids.length);

        /** @type {{ items: Array<{ id: number|string, title?: string }> }} */
        const sue = await teacher2Api.http.getJson("/api/verifica/list");
        const copie = sue.items.filter((i) => String(i.title ?? "").startsWith(`${titolo} (importata da`));
        expect(copie.length, "la copia è nell'elenco del collega, con il titolo che dice da dove viene").toBe(ids.length);
        for (const c of copie) {
            expect(ids, "e sono righe sue, nuove").not.toContain(Number(c.id));
        }
    });
});
