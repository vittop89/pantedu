// @ts-check
/**
 * Spostare i materiali da una classe a un'altra, dalla pagina dell'area
 * docente.
 *
 * I materiali nascono con la classe della barra laterale; da qui si spostano
 * a blocchi in un'altra classe fra quelle spuntate nel profilo. La prova crea
 * un esercizio nella terna della suite, lo trova nell'elenco della sua
 * classe, lo sposta in un'altra classe dello stesso corso, e verifica che sia
 * là e non più qui — e che «Sposta» senza aver scelto niente non sposti
 * niente. La seconda prova fa lo stesso con una verifica di più varianti: la
 * pagina ne mostra una voce sola, e le varianti si spostano tutte (ADR-037).
 * Esercizio e verifica li cancellano le factory a fine prova.
 */
const { test, expect } = require("../support/test");

/**
 * La classe della terna e un'altra dello stesso corso, fra quelle spuntate.
 * Dal 15/9/2026 (ADR-042) un anno esiste una volta per corso: la classe della
 * terna si cerca con il suo indirizzo, altrimenti la «2» dell'artistico passa
 * per quella dello scientifico e l'elenco non ha il contenuto appena creato.
 * @param {any} teacherApi
 * @param {{ terna: { classe: string, indirizzo: string } }} env
 */
async function dueClassi(teacherApi, env) {
    const cat = await teacherApi.curriculum.completo();
    /** @type {Array<{ id: number, code: string, active: boolean, indirizzo?: string|null }>} */
    const classi = (cat.curriculum.classi ?? []).filter((/** @type {{active: boolean}} */ c) => c.active);
    const da = classi.find((c) => c.code === env.terna.classe && (!c.indirizzo || c.indirizzo === env.terna.indirizzo));
    expect(da, `la classe della terna «${env.terna.classe}» è fra quelle spuntate`).toBeTruthy();
    if (!da) throw new Error("classe di partenza assente");
    const a = classi.find((c) => c.id !== da.id && (c.indirizzo ?? null) === (da.indirizzo ?? null));
    expect(a, "una seconda classe dello stesso corso, per non cambiare corso ai materiali").toBeTruthy();
    if (!a) throw new Error("classe di arrivo assente");
    return { da, a };
}

test.describe("Area docente — sposta di classe", () => {
    test("un contenuto si sposta a blocchi in un'altra classe, e senza scelte non si sposta niente", async ({ teacherPage, teacherApi, contentFactory, env }) => {
        const { da, a } = await dueClassi(teacherApi, env);

        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1 });
        const casella = () => teacherPage.locator(`input[name="contenuti[]"][value="${esercizio.id}"]`);

        await teacherPage.goto(`/area-docente/sposta-di-classe?classe=${da.id}`);
        await expect(casella(), "il contenuto è nell'elenco della sua classe").toBeVisible({ timeout: 30_000 });
        await expect(teacherPage.locator("#sposta-invia"), "senza scelte il bottone è chiuso").toBeDisabled();

        // Senza spuntare niente, dal server: la pagina lo dice e non sposta.
        const risposta = await teacherApi.http.send("POST", "/area-docente/sposta-di-classe", {
            form: { classe_da: String(da.id), classe_a: String(a.id) },
        });
        expect(risposta.ok, "il server risponde con la pagina").toBe(true);
        await teacherPage.goto(`/area-docente/sposta-di-classe?classe=${da.id}`);
        await expect(casella(), "il contenuto è ancora nella classe di partenza").toBeVisible();

        await casella().check();
        await teacherPage.locator("#sposta-classe-a").selectOption(String(a.id));
        await expect(teacherPage.locator("#sposta-invia"), "con una scelta il bottone conta").toHaveText("Sposta 1 elemento");
        await teacherPage.locator("#sposta-invia").click();
        // Il riepilogo prima di spostare (15/9/2026): da dove a dove, poi «Sposta».
        const riepilogo = teacherPage.locator("dialog.fm-avviso-inc");
        await expect(riepilogo, "prima di spostare la pagina chiede conferma").toContainText("Spostare 1 contenuto da");
        await riepilogo.locator("[data-fm-conferma]").click();

        await expect(teacherPage.locator(".fm-alert--success"), "la pagina conferma").toContainText("1 contenuto spostato", { timeout: 30_000 });
        await expect(teacherPage.locator(".fm-alert--success"), "e dice da dove a dove").toContainText(`da «${da.code}`);
        await expect(teacherPage, "e mostra la classe di arrivo").toHaveURL(new RegExp(`classe=${a.id}$`));
        await expect(casella(), "dove il contenuto ora compare").toBeVisible();

        await teacherPage.goto(`/area-docente/sposta-di-classe?classe=${da.id}`);
        await expect(casella(), "e non è più in quella di partenza").toHaveCount(0);
        const dettaglio = await teacherApi.content.get(esercizio.id);
        expect(dettaglio.content.classe, "l'API dice la classe nuova").toBe(a.code);
    });

    test("una verifica compare una volta sola e si sposta con tutte le sue varianti", async ({ teacherPage, teacherApi, verificaFactory, naming, env }) => {
        const { da, a } = await dueClassi(teacherApi, env);
        const { indirizzo, classe, materia } = env.terna;
        const salvata = await verificaFactory.batch({
            title: naming.unique("sposta-verifica"),
            versions: ["A"],
            overrides: {
                selectedIIS: indirizzo, selectedCLS: classe, selectedMATER: materia,
                indirizzo, classe, materia, sezione: "NOR", nPrint: 1, nPrintDSA: 0, nPrintDIS: 0,
            },
        });
        const varianti = salvata.docs.map((d) => Number(d.id)).sort((x, y) => x - y);
        expect(varianti.length, "almeno due varianti").toBeGreaterThanOrEqual(2);
        const casella = (/** @type {number} */ id) => teacherPage.locator(`input[name="verifiche[]"][value="${id}"]`);

        await teacherPage.goto(`/area-docente/sposta-di-classe?classe=${da.id}`);
        await expect(casella(Number(varianti[0])), "una casella per la verifica").toBeVisible({ timeout: 30_000 });
        for (const id of varianti.slice(1)) {
            await expect(casella(id), "e non una per variante").toHaveCount(0);
        }

        await casella(Number(varianti[0])).check();
        await teacherPage.locator("#sposta-classe-a").selectOption(String(a.id));
        await teacherPage.locator("#sposta-invia").click();
        await teacherPage.locator("dialog.fm-avviso-inc [data-fm-conferma]").click();
        await expect(teacherPage.locator(".fm-alert--success"), "la pagina conferma").toContainText("1 verifica spostata", { timeout: 30_000 });

        /** @type {{ posti: Array<{ principale: boolean, classe: string|null, varianti: number }> }} */
        const elenco = await teacherApi.http.getJson(`/api/verifica/${varianti[0]}/pubblicazioni`);
        const principale = elenco.posti.find((p) => p.principale);
        expect(principale?.classe, "la principale è nella classe di arrivo").toBe(a.code);
        expect(principale?.varianti, "per tutte le varianti").toBe(varianti.length);
    });
});
