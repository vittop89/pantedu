// @ts-check
/**
 * «Dove vale» e «Duplica in…» (ADR-037, fase 2).
 *
 * Un contenuto può valere in più posti, anche in un'altra scuola del docente,
 * con uno stato per posto: si corregge una volta. «Duplica in…» invece ne fa
 * una copia indipendente. La prima prova passa dalle API e guarda l'effetto
 * dove conta, cioè nella barra dell'altra scuola; la seconda usa la finestra
 * vera: pubblica, toglie e duplica dal modale.
 *
 * Il posto si sceglie fra quelli che il docente ha spuntato. Se non ce n'è
 * uno diverso da quello del contenuto la prova fallisce: la semina della CI
 * (`tools/ci/seed_e2e_database.php`) mette il docente di prova in due scuole
 * con le voci spuntate in tutte e due. Fino al 23/9/2026 qui c'era un
 * `test.skip`: un cambio della semina avrebbe spento in silenzio la prova
 * dell'isolamento fra scuole (revisione architetturale, A-29).
 */
const { test, expect } = require("../support/test");

/**
 * Un posto diverso da quello del contenuto: prima un'altra scuola, poi
 * un'altra classe della stessa scuola.
 * @param {Array<{id:number, nome:string, indirizzi:Array<{id:number,code:string}>, classi:Array<{id:number,code:string,indirizzo:string|null}>, materie:Array<{id:number,code:string}>}>} scuole
 * @param {number} scuolaAttiva
 * @param {{indirizzo:string, classe:string, materia:string}} terna
 */
function unAltroPosto(scuole, scuolaAttiva, terna) {
    const ordinate = [...scuole].sort((a, b) => Number(a.id === scuolaAttiva) - Number(b.id === scuolaAttiva));
    for (const s of ordinate) {
        for (const indirizzo of s.indirizzi) {
            for (const classe of s.classi) {
                if (classe.indirizzo && classe.indirizzo.toUpperCase() !== indirizzo.code.toUpperCase()) continue;
                const materia = s.materie[0];
                if (!materia) continue;
                const stessoPosto = s.id === scuolaAttiva && indirizzo.code === terna.indirizzo && classe.code === terna.classe;
                if (!stessoPosto) return { scuola: s, indirizzo, classe, materia };
            }
        }
    }
    return null;
}

test.describe("Area docente — Dove vale e Duplica in…", () => {
    test("pubblicato anche in un altro posto, il contenuto si trova lì con le voci di quel posto; tolto, non più", async ({ teacherApi, contentFactory, cleanup, naming, env }) => {
        const http = teacherApi.http;
        /** @type {{ current_institute_id: number|string|null }} */
        const corrente = await http.getJson("/api/tenant/current");
        const partenza = Number(corrente.current_institute_id);
        cleanup.add("teacher", "rimetti la scuola attiva di partenza", async () => {
            await http.postForm("/api/tenant/switch", { institute_id: String(partenza) });
        });
        /** @type {{ scuole: any[] }} */
        const luoghi = await http.getJson("/api/teacher/pubblicazioni/luoghi");
        const posto = unAltroPosto(luoghi.scuole, partenza, env.terna);
        expect(posto, "il docente di prova ha spuntato un posto diverso da quello del contenuto (semina: seconda scuola)").not.toBeNull();
        if (posto === null) return;

        const documento = await contentFactory.document({ title: naming.unique("dove-vale"), visibility: "published", terna: env.terna });
        /** @type {{ ok: boolean, id: number }} */
        const aggiunta = await http.postForm(`/api/teacher/content/${documento.id}/pubblicazioni`, {
            scuola: String(posto.scuola.id), indirizzo: String(posto.indirizzo.id),
            classe: String(posto.classe.id), materia: String(posto.materia.id), stato: "published",
        });
        expect(aggiunta.ok, "la pubblicazione è aggiunta").toBe(true);

        const barra = async () => {
            /** @type {{ rows: Array<{ id: number|string }> }} */
            const j = await http.getJson("/api/teacher/content", {
                indirizzo: posto.indirizzo.code, classe: posto.classe.code, subject: posto.materia.code, type: "document", limit: "500",
            });
            return j.rows.map((r) => Number(r.id));
        };
        await http.postForm("/api/tenant/switch", { institute_id: String(posto.scuola.id) });
        expect(await barra(), "nel posto nuovo, con le sue voci, la barra lo elenca").toContain(documento.id);

        await http.postForm(`/api/teacher/pubblicazioni/${aggiunta.id}/togli`, {});
        expect(await barra(), "tolta la pubblicazione, non più").not.toContain(documento.id);
    });

    test("dal modale si pubblica anche altrove, si toglie e si duplica", async ({ teacherPage, teacherApi, contentFactory, homeDocente, cleanup, env }) => {
        const http = teacherApi.http;
        /** @type {{ current_institute_id: number|string|null }} */
        const corrente = await http.getJson("/api/tenant/current");
        /** @type {{ scuole: any[] }} */
        const luoghi = await http.getJson("/api/teacher/pubblicazioni/luoghi");
        const posto = unAltroPosto(luoghi.scuole, Number(corrente.current_institute_id), env.terna);
        expect(posto, "il docente di prova ha spuntato un posto diverso da quello del contenuto (semina: seconda scuola)").not.toBeNull();
        if (posto === null) return;

        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1 });

        await homeDocente.vaiA();
        await homeDocente.scegliTerna(env.terna.indirizzo, env.terna.classe, env.terna.materia);
        const pannello = await homeDocente.apriSidepage("esercizi");
        await homeDocente.attivaModificaSezione(pannello);
        const riga = pannello.locator(`li[data-content-id="${esercizio.id}"]`);
        await expect(riga, "l'esercizio è nel pannello").toBeAttached({ timeout: 30_000 });
        await riga.locator(".fm-item-edit").first().click();

        const finestra = teacherPage.locator(".fm-modal-backdrop").last();
        const doveVale = finestra.locator(".fm-dove-vale-box");
        await expect(doveVale.locator("li.fm-dove-vale-riga"), "c'è il posto principale").toHaveCount(1, { timeout: 30_000 });
        await expect(doveVale.locator('li.fm-dove-vale-riga[data-principale="1"]')).toContainText("principale");

        const nuova = doveVale.locator(".fm-dove-vale-aggiungi");
        await nuova.locator('select[name="fm-pub-nuova-scuola"]').selectOption(String(posto.scuola.id));
        await nuova.locator('select[name="fm-pub-nuova-indirizzo"]').selectOption(String(posto.indirizzo.id));
        await nuova.locator('select[name="fm-pub-nuova-classe"]').selectOption(String(posto.classe.id));
        await nuova.locator('select[name="fm-pub-nuova-materia"]').selectOption(String(posto.materia.id));
        await nuova.getByRole("combobox", { name: "Stato nel posto nuovo" }).selectOption("published");
        await nuova.getByRole("button", { name: "Pubblica anche qui" }).click();
        await expect(doveVale.locator(".fm-dove-vale-riscontro"), "il modale conferma").toContainText("Pubblicazione aggiunta");
        await expect(doveVale.locator("li.fm-dove-vale-riga"), "due posti").toHaveCount(2);

        // ADR-037, fase 4c — l'esercizio è in bozza: il posto pubblicato è
        // sospeso, e il pannello segue la visibilità del modale mentre cambia.
        const altroPosto = doveVale.locator('li.fm-dove-vale-riga[data-principale="0"]');
        const visibilita = finestra.locator('select[name="visibility"]');
        await expect(visibilita, "l'esercizio della suite nasce in bozza").toHaveValue("draft");
        await expect(altroPosto.locator(".fm-dove-vale-sospeso"), "in bozza il posto pubblicato è sospeso").toHaveText("sospeso");
        await expect(doveVale.locator(".fm-dove-vale-nota-sospesi"), "e il pannello dice perché").toContainText("in bozza");
        await visibilita.selectOption("published");
        await expect(altroPosto.locator(".fm-dove-vale-sospeso"), "pubblicato, non è più sospeso").toHaveCount(0);
        await expect(doveVale.locator(".fm-dove-vale-nota-sospesi")).toHaveCount(0);
        await visibilita.selectOption("draft");
        await expect(altroPosto.locator(".fm-dove-vale-sospeso"), "di nuovo in bozza, di nuovo sospeso").toHaveCount(1);

        /** @type {{ pubblicazioni: Array<{ id: number, principale: boolean }> }} */
        const elenco = await http.getJson(`/api/teacher/content/${esercizio.id}/pubblicazioni`);
        expect(elenco.pubblicazioni.length, "e il server dice lo stesso").toBe(2);

        await doveVale.locator('li.fm-dove-vale-riga[data-principale="0"]').getByRole("button", { name: "Togli" }).click();
        await expect(doveVale.locator("li.fm-dove-vale-riga"), "tolta, resta la principale").toHaveCount(1);

        const copia = doveVale.locator(".fm-dove-vale-duplica");
        await copia.locator('select[name="fm-pub-copia-scuola"]').selectOption(String(posto.scuola.id));
        await copia.locator('select[name="fm-pub-copia-indirizzo"]').selectOption(String(posto.indirizzo.id));
        await copia.locator('select[name="fm-pub-copia-classe"]').selectOption(String(posto.classe.id));
        await copia.locator('select[name="fm-pub-copia-materia"]').selectOption(String(posto.materia.id));
        const risposta = teacherPage.waitForResponse((r) => r.request().method() === "POST"
            && r.url().includes(`/api/teacher/content/${esercizio.id}/duplica`));
        await copia.getByRole("button", { name: "Duplica qui" }).click();
        /** @type {{ ok: boolean, id: number|string }} */
        const duplicata = await (await risposta).json();
        const idCopia = Number(duplicata.id);
        // Per id, non per titolo: la copia sta nel posto scelto, che può essere
        // un'altra scuola, e l'elenco dei contenuti mostra solo quelli della
        // scuola attiva (ADR-037, fase 1). Il 14/9/2026 in CI, dove il docente
        // di prova ha due scuole, la ricerca per titolo non la trovava e la
        // pulizia la lasciava nel database.
        if (idCopia > 0) {
            cleanup.add("teacher", "cancella la copia", async () => {
                await http.postForm(`/api/teacher/content/${idCopia}/delete`, {});
            });
        }
        await expect(doveVale.locator(".fm-dove-vale-riscontro"), "il modale conferma la copia").toContainText("Copia creata");
        expect(idCopia, "la copia è un contenuto nuovo").toBeGreaterThan(0);
        expect(idCopia).not.toBe(esercizio.id);

        /** @type {{ content: { title: string } }} */
        const laCopia = await http.getJson(`/api/teacher/content/${idCopia}`);
        expect(laCopia.content.title, "la copia esiste, con il suo titolo").toBe(`${esercizio.title} (copia)`);
        /** @type {{ pubblicazioni: Array<{ principale: boolean, scuola_id: number, classe_id: number|null }> }} */
        const posti = await http.getJson(`/api/teacher/content/${idCopia}/pubblicazioni`);
        const principale = posti.pubblicazioni.find((p) => p.principale);
        expect([principale?.scuola_id, principale?.classe_id], "e sta nel posto scelto").toEqual([posto.scuola.id, posto.classe.id]);
    });
});
