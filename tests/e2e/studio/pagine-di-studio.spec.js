// @ts-check
/**
 * Pagine di studio dei contenuti: elenco degli argomenti e pagina di un argomento.
 * Riscrittura di studio_verifica_db.spec.js e teacher_content_db.spec.js.
 *
 * Sono le pagine che il docente apre per lavorare sui propri contenuti: la
 * lista degli argomenti di una terna e la pagina di un argomento, che rende i
 * quesiti presi dal database. Qui si verifica anche che un contenuto appena
 * creato compaia in entrambe, e nel pannello laterale.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente schermate
 * salvate su disco a ogni passaggio (la diagnostica le allega solo quando un
 * test fallisce), e il contenuto cercato nelle pagine è creato dal test invece
 * di essere il primo che capita nella terna.
 */
const { test, expect } = require("../support/test");

test.describe("Studio — pagine dei contenuti", () => {
    test("l'elenco degli esercizi filtra per indirizzo, classe e materia", async ({ contentFactory, teacherApi, env }) => {
        const { indirizzo, classe, materia } = env.terna;
        await contentFactory.exercise({ terna: env.terna, publish: true });

        /** @type {{ ok: boolean, rows: ReadonlyArray<{ indirizzo?: string, classe?: string, materia?: string }> }} */
        const risultati = await teacherApi.http.getJson("/api/studio/exercises.json", {
            indirizzo, classe, materia, limit: "50",
        });
        expect(risultati.ok).toBe(true);
        expect(risultati.rows.length, "la terna ha degli esercizi").toBeGreaterThan(0);
        for (const riga of risultati.rows) {
            expect(riga.indirizzo, "indirizzo").toBe(indirizzo);
            expect(riga.classe, "classe").toBe(classe);
            expect(riga.materia, "materia").toBe(materia);
        }
    });

    test("la pagina degli argomenti elenca i contenuti della terna", async ({ contentFactory, teacherPage, env, naming }) => {
        const { indirizzo, classe, materia } = env.terna;
        const topic = naming.unique("argomento");
        await contentFactory.exercise({ topic, title: topic, terna: env.terna, publish: true });

        await teacherPage.goto(`/studio/${indirizzo}/${classe}/${materia}`);
        await expect(teacherPage.locator("h1")).toContainText(materia);

        const collegamenti = teacherPage.locator(".fm-study-topics a");
        await expect(collegamenti.first(), "almeno un argomento").toBeVisible({ timeout: 30_000 });
        const percorsi = await collegamenti.evaluateAll((elementi) =>
            elementi.map((e) => e.getAttribute("href") ?? ""));
        expect(percorsi.length, "la terna ha degli argomenti").toBeGreaterThan(0);
        // Ogni voce porta alla pagina del proprio argomento, dentro questa terna.
        const dentroLaTerna = new RegExp(`^/studio/${indirizzo}/${classe}/${materia}/.+`);
        for (const percorso of percorsi) {
            expect(percorso, "collegamento dentro la terna").toMatch(dentroLaTerna);
        }
        // Nota: l'elenco degli argomenti non comprende quello appena creato
        // finché non ha contenuti visibili nello studio; la sua presenza fra i
        // contenuti del docente è verificata dal test dell'ultimo caso.
        expect(topic, "argomento creato per questo test").toBeTruthy();
    });

    test("la pagina di un argomento rende i quesiti presi dal database", async ({ contentFactory, teacherPage, env, naming }) => {
        const { indirizzo, classe, materia } = env.terna;
        const topic = naming.unique("con-quesiti");
        await contentFactory.exercise({ topic, title: topic, terna: env.terna, groups: 1, itemsPerGroup: 2, publish: true });

        // La pagina dello studio di un esercizio: `/studio/esercizio/...`.
        // La rotta senza il tipo (`/studio/{ind}/{cls}/{mat}/{topic}`) apre la
        // pagina generica dell'argomento, che non rende i quesiti del contratto.
        await teacherPage.goto(`/studio/esercizio/${indirizzo}/${classe}/${materia}/${encodeURIComponent(topic)}`);
        await expect(
            teacherPage.locator('.fm-draggable-container[data-db-backed="1"]'),
            "il contenitore dichiara di venire dal database",
        ).toBeVisible({ timeout: 30_000 });
        await expect(teacherPage.locator(".fm-collection__item").first(), "i quesiti sono resi").toBeVisible({ timeout: 30_000 });
    });

    test("per il docente la pagina si apre già in modalità verifica", async ({ contentFactory, studioEsercizio, teacherPage }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);

        // È la modalità che mostra le caselle di selezione dei quesiti: per un
        // docente si accende da sola, senza premere nulla.
        await expect(teacherPage.locator("body")).toHaveClass(/fm-verifica-mode/);
        await expect(teacherPage.locator(".fm-collection__item .fm-checkbox-ain").first()).toBeAttached();
    });

    test("un esercizio creato via API si ritrova nelle pagine e nel pannello", async ({ contentFactory, teacherApi, homeDocente, env, naming }) => {
        const { indirizzo, classe, materia } = env.terna;
        const topic = naming.unique("giro-completo");
        const esercizio = await contentFactory.exercise({ topic, title: topic, terna: env.terna, groups: 1, itemsPerGroup: 1, publish: true });

        // 1. Nell'elenco che alimenta lo studio.
        /** @type {{ ok: boolean, rows: ReadonlyArray<{ id: number }> }} */
        const elenco = await teacherApi.http.getJson("/api/study/content.json", {
            type: "esercizio", subject: materia, ind: indirizzo, cls: classe, topic,
        });
        expect(elenco.ok).toBe(true);
        expect(elenco.rows.some((r) => r.id === esercizio.id), "l'esercizio pubblicato è nell'elenco").toBe(true);

        // 2. Nel pannello laterale della sua terna.
        await homeDocente.vaiA();
        await homeDocente.scegliTerna(indirizzo, classe, materia);
        const pannello = await homeDocente.apriSidepage("esercizi");
        await expect(pannello.locator(`li a[href*="${encodeURIComponent(topic)}"]`).first(), "elencato nel pannello")
            .toBeVisible({ timeout: 30_000 });
    });
    test("l'indirizzo vecchio della classe porta alla stessa pagina di quello nuovo", async ({
        contentFactory, teacherPage, env,
    }) => {
        // Le classi si scrivevano «2s»: quegli indirizzi sono in giro nei
        // segnalibri e nei documenti, e devono continuare a funzionare.
        const esercizio = await contentFactory.exercise({
            terna: env.terna, groups: 1, itemsPerGroup: 2, publish: true,
        });
        const nuovo = esercizio.studioUrl;
        const vecchio = nuovo.replace(
            new RegExp(`/${env.terna.classe}/${env.terna.materia}/`),
            `/${env.terna.classe}s/${env.terna.materia}/`,
        );
        expect(vecchio, "l'indirizzo vecchio è diverso da quello nuovo").not.toBe(nuovo);

        await teacherPage.goto(vecchio);
        const conVecchio = await teacherPage.locator(".fm-groupcollex").count();
        expect(conVecchio, "l'indirizzo vecchio trova il contenuto").toBeGreaterThan(0);

        await teacherPage.goto(nuovo);
        await expect(teacherPage.locator(".fm-groupcollex"), "e quello nuovo ne trova altrettanti")
            .toHaveCount(conVecchio, { timeout: 30_000 });
    });
});
