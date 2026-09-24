// @ts-check
/**
 * Barra del documento risdoc: i comandi che ha e quelli che non ha più.
 * Riscrittura di risdoc_toolbar_buttons.spec.js e risdoc_usertype_export.spec.js.
 *
 * Aprendo un modello dell'Istituto, la pagina porta la barra del componente che
 * rende il documento: la modifica avviene lì dentro, e da lì partono le due
 * esportazioni. Il vecchio interruttore dell'editor di testo ricco resta nel
 * markup ma nascosto, perché quella strada è stata abbandonata.
 *
 * Il secondo test preme i comandi come li premerebbe un docente, su ogni
 * modello che ha un sorgente TeX, e verifica che nessuno risponda con un
 * errore: era il caso in cui il pacchetto tornava 403 solo passando
 * dall'interfaccia, e non dalle chiamate dirette.
 *
 * Cosa cambia rispetto a prima: niente login né contesto di browser aperto a
 * mano, niente `page.evaluate` per nascondere finestre e banner o per riempire
 * i selettori della terna, niente stampe, e delle attese a tempo — due secondi
 * e mezzo per pagina, più un secondo e mezzo dopo ogni comando, su sette
 * pagine — non resta nulla. Gli identificativi dei modelli non sono più scritti
 * nel test.
 */
const { test, expect } = require("../support/test");

/** Modelli che hanno un sorgente TeX: sono quelli con i comandi di esportazione. */
async function modelliConTex(/** @type {any} */ teacherApi, /** @type {any} */ adminApi) {
    const modelli = await teacherApi.risdoc.templates();
    const conTex = [];
    for (const modello of modelli) {
        const scheda = await adminApi.risdoc.detail(modello.id);
        if (scheda.tex_file) conTex.push(modello.id);
    }
    expect(conTex.length, "almeno un modello ha un sorgente TeX").toBeGreaterThanOrEqual(1);
    return conTex;
}

test.describe("Risdoc — barra del documento", () => {
    test("la barra porta modifica ed esportazioni; l'editor di testo ricco resta nascosto", async ({
        teacherApi, adminApi, teacherPage,
    }) => {
        const [primo] = await modelliConTex(teacherApi, adminApi);
        await teacherPage.goto(`/risdoc/view/${primo}`);

        const barra = teacherPage.locator(".fm-doc-topbar").first();
        await expect(barra, "il documento porta la propria barra").toBeVisible({ timeout: 30_000 });
        await expect(barra.locator('[data-action="ptdoc-toggle-edit"]'), "comando di modifica in pagina").toBeVisible();
        await expect(barra.locator('[data-action="ptdoc-tex"]'), "esportazione TeX/PDF").toBeVisible();
        await expect(barra.locator('[data-action="ptdoc-zip"]'), "esportazione del pacchetto").toBeVisible();

        // L'interruttore dell'editor di testo ricco è nel markup ma non si vede.
        const interruttore = teacherPage.locator(".fm-rte-toggle").first();
        if (await interruttore.count()) {
            await expect(interruttore, "l'editor di testo ricco resta nascosto").toBeHidden();
        }
    });

    test("le due esportazioni rispondono, per ogni modello che ha un sorgente TeX", async ({ teacherApi, adminApi }) => {
        const statoModulo = {
            fields: { profilo_classe: "Classe eterogenea, buona partecipazione." },
            state: { classe: "2", sezione: "A", indirizzo: "SCI", disciplina: "MAT" },
        };

        for (const id of await modelliConTex(teacherApi, adminApi)) {
            const pacchetto = await teacherApi.risdoc.export(id, { formState: statoModulo });
            expect(pacchetto.status(), `modello ${id}: il pacchetto risponde`).toBe(200);
            expect(pacchetto.headers()["content-type"], `modello ${id}: è un archivio`).toContain("zip");
            expect(pacchetto.headers()["content-disposition"], `modello ${id}: arriva come allegato`)
                .toContain("attachment");
            const contenuto = await pacchetto.body();
            expect(contenuto.subarray(0, 2).toString("ascii"), `modello ${id}: intestazione ZIP`).toBe("PK");
        }
    });
});
