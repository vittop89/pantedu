// @ts-check
/**
 * File dei modelli delle verifiche: a chi appartengono e come si sovrascrivono.
 * Riscrittura di g20_10_area_docente_templates.spec.js e
 * g20_07_templates_institute_scope.spec.js.
 *
 * I file TeX delle verifiche arrivano al docente per gradi: c'è una versione
 * di partenza, poi quella dell'Istituto, poi la sua. Quando il docente ne
 * salva una, l'applicazione la mette sopra le altre; cancellandola si torna a
 * quella sotto, senza perdere niente. È il meccanismo che permette a un
 * docente di cambiare l'intestazione delle proprie verifiche senza toccare
 * quelle dei colleghi.
 *
 * L'istituto a cui i file appartengono lo decide la sessione, non chi chiama:
 * un suggerimento nell'indirizzo può al più confermarlo, mai cambiarlo. È il
 * secondo test, che sorveglia il confine.
 *
 * Cosa cambia rispetto a prima: niente login né gettone di sicurezza a mano,
 * niente stampe di console, e il file di prova viene ripristinato dal registro
 * di pulizia anche se il test si ferma a metà (prima la cancellazione era
 * l'ultima riga: fallendo prima, l'override restava addosso al docente).
 */
const { test, expect } = require("../support/test");

const FILE_DI_PROVA = "texCommon/intestazione.tex";

test.describe("Area docente — file dei modelli", () => {
    test("la pagina dei modelli si apre, e l'elenco dei file dice di chi sono", async ({ teacherApi, teacherPage }) => {
        const elenco = await teacherApi.http.getJson("/api/teacher/verifica/files");
        expect(elenco["ok"], "l'elenco risponde").toBe(true);
        expect(Number(elenco["teacher_id"]), "dice di quale docente sono").toBeGreaterThan(0);
        expect(elenco["institute_code"], "e di quale istituto").toBeTruthy();
        expect(/** @type {unknown[]} */ (elenco["files"]).length, "e non è vuoto").toBeGreaterThan(0);

        await teacherPage.goto("/area-docente/templates");
        const schede = teacherPage.locator(".fm-area-docente-nav__tab");
        await expect(schede.first(), "la barra delle sezioni").toBeVisible({ timeout: 30_000 });
        expect(await schede.count(), "con almeno tre sezioni").toBeGreaterThanOrEqual(3);
        await expect(teacherPage.locator(".fm-area-docente-nav__tab--active"), "si apre sui modelli").toContainText("modelli");
        await expect(teacherPage.locator("#fm-tvf-open"), "col comando che apre l'editor").toBeVisible();
        await expect(teacherPage.locator("#fm-tvf-status"), "e la riga di stato").toBeAttached();
    });

    test("un suggerimento nell'indirizzo non cambia l'istituto dei file", async ({ teacherApi }) => {
        const senzaSuggerimento = await teacherApi.http.getJson("/api/teacher/verifica/files");
        const istituto = String(senzaSuggerimento["institute_code"]);
        expect(istituto, "l'istituto della sessione").toBeTruthy();

        const conIlSuo = await teacherApi.http.getJson("/api/teacher/verifica/files", { institute: istituto });
        expect(conIlSuo["institute_code"], "confermandolo non cambia niente").toBe(istituto);

        const conUnAltro = await teacherApi.http.getJson("/api/teacher/verifica/files", { institute: "ZZZZ99999X" });
        expect(conUnAltro["ok"], "la richiesta viene servita").toBe(true);
        expect(conUnAltro["institute_code"], "ma l'istituto resta quello della sessione").toBe(istituto);
    });

    test("salvando un file il docente si fa la propria copia, e cancellandola torna quella di prima", async ({ teacherApi, cleanup }) => {
        const contenuto = "% file di prova della suite E2E\n\\noindent Prova";

        const scritto = await teacherApi.http.send("POST", "/api/teacher/verifica/files/write", {
            json: { path: FILE_DI_PROVA, content: contenuto },
        });
        expect(scritto.ok, `salvataggio → ${scritto.status}`).toBe(true);
        cleanup.add("teacher", `toglie la copia personale di ${FILE_DI_PROVA}`, async () => {
            await teacherApi.http.send("POST", "/api/teacher/verifica/files/delete", { json: { path: FILE_DI_PROVA } });
        });

        const suo = await teacherApi.http.getJson("/api/teacher/verifica/files/read", { path: FILE_DI_PROVA });
        expect(suo["is_mine"], "il file letto è quello del docente").toBe(true);
        expect(suo["content"], "col contenuto appena salvato").toBe(contenuto);

        const cancellato = await teacherApi.http.send("POST", "/api/teacher/verifica/files/delete", {
            json: { path: FILE_DI_PROVA },
        });
        expect(cancellato.ok, `cancellazione → ${cancellato.status}`).toBe(true);

        const dopo = await teacherApi.http.getJson("/api/teacher/verifica/files/read", { path: FILE_DI_PROVA });
        expect(dopo["is_mine"], "il file non è più suo").toBe(false);
        expect(dopo["content"], "e torna quello di prima").not.toBe(contenuto);
    });
});
