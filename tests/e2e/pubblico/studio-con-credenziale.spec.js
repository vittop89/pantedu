// @ts-check
/**
 * Si studia con la credenziale di classe (14/9/2026).
 *
 * Uno studente senza account entra da /accesso-classe con la credenziale che
 * il docente ha creato per la sua classe. Fino a questa data le pagine e le
 * API di studio stavano dietro l'autenticazione degli account: l'ospite
 * riceveva 401 da tutte e la pagina di studio lo mandava al login, quindi la
 * modalità non mostrava niente. Qui: l'ospite vede il contenuto pubblicato del
 * docente e non la sua bozza; senza credenziale, dopo l'uscita o con la
 * credenziale spenta dal docente non entra più.
 *
 * I contenuti e la credenziale li crea la suite e li cancella il registro di
 * pulizia; gli errori di console e le risposte 4xx della pagina li raccoglie la
 * diagnostica della suite.
 */
const { test, expect } = require("../support/test");

test.describe("Pubblico — studio con la credenziale di classe", () => {
    test("con la credenziale si vede il pubblicato del docente per la classe, e non la bozza", async ({ page, accessoClasse, credentialFactory, contentFactory, naming, env }) => {
        const { indirizzo, classe, materia } = env.terna;
        const pubblicato = await contentFactory.exercise({ terna: env.terna, title: naming.unique("studio-pubblicato"), groups: 1, itemsPerGroup: 1, publish: true });
        const bozza = await contentFactory.exercise({ terna: env.terna, title: naming.unique("studio-bozza"), groups: 1, itemsPerGroup: 1 });
        const credenziale = await credentialFactory.create({ indirizzo, classe });

        await accessoClasse.vaiA();
        await accessoClasse.entra(credenziale.username, credenziale.password);

        const api = await page.request.get("/api/study/content.json", {
            params: { type: "esercizio", indirizzo, classe, subject: materia, limit: "500" },
            headers: { Accept: "application/json" },
        });
        expect(api.status(), "l'API di studio risponde all'ospite con la credenziale").toBe(200);
        /** @type {{ rows: Array<{ id: number|string }> }} */
        const j = await api.json();
        const ids = j.rows.map((r) => Number(r.id));
        expect(ids, "c'è l'esercizio pubblicato").toContain(pubblicato.id);
        expect(ids, "non c'è la bozza").not.toContain(bozza.id);

        const risposta = await page.goto(`/studio/esercizio/${indirizzo}/${classe}/${materia}`);
        expect(risposta?.status(), "la pagina di studio si apre").toBe(200);
        expect(new URL(page.url()).pathname, "e non manda al login").not.toBe("/login");
        await expect(page.locator("main, #content, body").first(), "l'elenco mostra il pubblicato").toContainText(pubblicato.title);
        await expect(page.locator("body"), "e non la bozza").not.toContainText(bozza.title);
    });

    test("senza credenziale, dopo l'uscita o con la credenziale spenta non si entra", async ({ page, accessoClasse, credentialFactory, teacherApi, env }) => {
        const { indirizzo, classe, materia } = env.terna;
        const studio = async () => (await page.request.get("/api/study/topics.json", {
            params: { type: "esercizio", indirizzo, classe, subject: materia },
            headers: { Accept: "application/json" },
        })).status();

        expect(await studio(), "senza credenziale: 401").toBe(401);
        const pagina = await page.request.get(`/studio/esercizio/${indirizzo}/${classe}/${materia}`, { maxRedirects: 0 });
        expect(pagina.status(), "la pagina manda al login").toBe(302);
        expect(pagina.headers()["location"] ?? "", "al login").toContain("/login");

        const prima = await credentialFactory.create({ indirizzo, classe });
        await accessoClasse.vaiA();
        await accessoClasse.entra(prima.username, prima.password);
        expect(await studio(), "con la credenziale: si entra").toBe(200);
        await accessoClasse.vaiA();
        await accessoClasse.esciDa(prima.label);
        expect(await studio(), "dopo l'uscita: di nuovo 401").toBe(401);

        const seconda = await credentialFactory.create({ indirizzo, classe });
        await accessoClasse.vaiA();
        await accessoClasse.entra(seconda.username, seconda.password);
        expect(await studio()).toBe(200);
        const spenta = await teacherApi.credentials.toggle(seconda.id, false);
        expect(spenta.active, "il docente la spegne").toBe(false);
        expect(await studio(), "spenta dal docente: 401").toBe(401);
    });
});
