// @ts-check
/**
 * Percorsi del pacchetto che il docente sincronizza sul proprio computer.
 * Riscrittura di g19_49_local_bundle_paths.spec.js.
 *
 * Ogni file del pacchetto locale ha un percorso parlante, costruito con il
 * codice dell'Istituto, l'indirizzo, la classe e la materia: le verifiche
 * sotto `.../verifiche/<titolo>/`, le mappe sotto `.../mappe/`. È così che il
 * docente ritrova i propri file sul disco senza consultare il database.
 */
const { test, expect } = require("../support/test");

/** {istituto}/{indirizzo}/{classe}/{materia}/verifiche/{titolo}[/{versione}]/{file} */
const PERCORSO_VERIFICA = /^[^/]+\/[^/]+\/[^/]+\/[^/]+\/verifiche\/[^/]+(\/[^/]+)?\/[^/]+$/;
/** {istituto}/{indirizzo}/{classe}/{materia}/mappe/{file} */
const PERCORSO_MAPPA = /^[^/]+\/[^/]+\/[^/]+\/[^/]+\/mappe\/[^/]+$/;

test.describe("Verifiche — pacchetto sincronizzato in locale", () => {
    test("i percorsi seguono Istituto, indirizzo, classe e materia", async ({ teacherApi }) => {
        /** @type {{ ok: boolean, total: number, files: ReadonlyArray<{ path: string, type: string }> }} */
        const manifesto = await teacherApi.http.getJson("/api/teacher/sync-local-bundle", { offset: "0", limit: "50" });
        expect(manifesto.ok).toBe(true);
        expect(manifesto.total, "il docente ha dei file da sincronizzare").toBeGreaterThan(0);

        const verifiche = manifesto.files.filter((f) => f.type === "verifica-tex" || f.type === "verifica-pdf");
        const mappe = manifesto.files.filter((f) => f.type === "mappa");

        const verificheFuoriPosto = verifiche.filter((f) => !PERCORSO_VERIFICA.test(f.path));
        expect(verificheFuoriPosto.map((f) => f.path), "verifiche con percorso non conforme").toEqual([]);

        const mappeFuoriPosto = mappe.filter((f) => !PERCORSO_MAPPA.test(f.path));
        expect(mappeFuoriPosto.map((f) => f.path), "mappe con percorso non conforme").toEqual([]);

        const primo = manifesto.files[0];
        expect(primo, "almeno un file nel manifesto").toBeDefined();
        if (!primo) return;
        const codiceIstituto = primo.path.split("/")[0] ?? "";
        expect(codiceIstituto, "il percorso comincia con il codice dell'Istituto").toMatch(/^[A-Za-z0-9_.-]+$/);
    });
});
