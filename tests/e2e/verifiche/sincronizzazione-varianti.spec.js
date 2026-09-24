// @ts-check
/**
 * Modifiche ai sorgenti che si propagano fra le varianti della stessa verifica.
 * Riscrittura di g27_batch_sync_propagation.spec.js.
 *
 * Le varianti (A e B, con e senza soluzioni) condividono i file comuni, come
 * il preambolo TikZ: modificarlo in una deve allinearlo in tutte. I file
 * propri di una variante, invece, si propagano solo alla variante gemella
 * dell'altra versione: modificare il documento principale della A senza
 * soluzioni tocca la B senza soluzioni, non quelle con le soluzioni.
 */
const { test, expect } = require("../support/test");

test.describe("Verifiche — allineamento dei sorgenti fra varianti", () => {
    test("il preambolo TikZ modificato in una variante si allinea in tutte le altre", async ({ verificaFactory, teacherApi, naming }) => {
        const batch = await verificaFactory.batch({
            title: naming.unique("allineamento"),
            versionLabel: "g27",
            overrides: { dsa: false, nPrintDSA: 0, nPrintDIS: 0 },
        });
        const soluzione = batch.docs.find((d) => d.variant === "A_SOL");
        expect(soluzione, "variante A con soluzioni").toBeDefined();
        if (!soluzione) return;
        const sorelle = batch.docs.filter((d) => d.id !== soluzione.id);
        expect(sorelle.length, "almeno una variante sorella").toBeGreaterThan(0);

        const files = await teacherApi.verifica.texFiles(soluzione.id);
        const preambolo = files.find((f) => f.path === "versioni/tikz_preamble.tex");
        expect(preambolo, "preambolo TikZ").toBeDefined();
        if (!preambolo) return;

        const marcatore = `% marcatore di prova ${Date.now().toString(36)}`;
        const nuovo = `${preambolo.content}\n${marcatore}`;
        const salvataggio = await teacherApi.verifica.saveTexFiles(
            soluzione.id,
            files.map((f) => ({ path: f.path, content: f.path === preambolo.path ? nuovo : f.content })),
        );
        expect(salvataggio.synced_siblings, "le sorelle allineate").toBe(sorelle.length);

        for (const sorella of sorelle) {
            const suo = await teacherApi.verifica.texFile(sorella.id, "versioni/tikz_preamble.tex");
            expect(suo.content, `preambolo allineato nella variante ${sorella.variant}`).toBe(nuovo);
        }
    });

    test("il documento principale si propaga solo alla variante gemella", async ({ verificaFactory, teacherApi, naming }) => {
        const batch = await verificaFactory.batch({
            title: naming.unique("gemella"),
            versionLabel: "g27",
            overrides: { dsa: false, nPrintDSA: 0, nPrintDIS: 0 },
        });
        const senzaSoluzioniA = batch.docs.find((d) => d.variant === "A_NOR");
        const senzaSoluzioniB = batch.docs.find((d) => d.variant === "B_NOR");
        const conSoluzioniA = batch.docs.find((d) => d.variant === "A_SOL");
        expect(senzaSoluzioniA && senzaSoluzioniB && conSoluzioniA, "le tre varianti coinvolte").toBeTruthy();
        if (!senzaSoluzioniA || !senzaSoluzioniB || !conSoluzioniA) return;

        const files = await teacherApi.verifica.texFiles(senzaSoluzioniA.id);
        const principale = files.find((f) => f.path === "versioni/main_NOR.tex");
        expect(principale, "documento principale della variante").toBeDefined();
        if (!principale) return;

        const marcatore = `% modifica della variante A ${Date.now().toString(36)}`;
        const salvataggio = await teacherApi.verifica.saveTexFiles(
            senzaSoluzioniA.id,
            files.map((f) => ({ path: f.path, content: f.path === principale.path ? `${principale.content}\n${marcatore}` : f.content })),
        );
        expect(salvataggio.synced_siblings ?? 0).toBeGreaterThanOrEqual(1);

        const gemella = await teacherApi.verifica.texFile(senzaSoluzioniB.id, "versioni/main_NOR.tex");
        expect(gemella.content, "la gemella riceve la modifica").toContain(marcatore);

        const conSoluzioni = await teacherApi.verifica.texFile(conSoluzioniA.id, "versioni/main_SOL.tex");
        expect(conSoluzioni.content, "la variante con le soluzioni non è toccata").not.toContain(marcatore);
    });
});
