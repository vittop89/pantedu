// @ts-check
/**
 * La biblioteca dei modelli TikZ: quello che c'è dentro e come ci si mette
 * qualcosa di nuovo.
 * Terza parte della riscrittura di verifiche_studio_smoke.spec.js.
 *
 * I disegni ricorrenti — un piano cartesiano, un circuito, una parabola — non
 * si riscrivono ogni volta: stanno in una biblioteca, divisi in gruppi, e dal
 * menu «TeX ▾» dell'editor si infilano nel quesito. La biblioteca la legge
 * chiunque abbia una sessione; a cambiarla è solo un amministratore.
 *
 * Cosa cambia rispetto a prima: le tre chiamate non passano più da
 * `page.evaluate` con la fetch scritta a mano e il gettone letto a parte —
 * c'è un client — e l'elemento creato viene cancellato anche se il test
 * fallisce a metà.
 */
const { test, expect } = require("../support/test");

/** Il gruppo su cui si lavora: c'è in ogni installazione. */
const GRUPPO = "gruppo-FISICA";
const DISEGNO = "\\begin{tikzpicture}\\draw (0,0) -- (1,1);\\end{tikzpicture}";

test.describe("Editor — biblioteca dei modelli TikZ", () => {
    test("la biblioteca si legge, divisa in gruppi", async ({ adminApi }) => {
        // Il file della biblioteca lo legge solo un amministratore: al docente
        // i modelli arrivano dal menu dell'editor, che passa da un'altra rotta.
        const biblioteca = await adminApi.tikz.library();
        const gruppi = Object.keys(biblioteca);
        expect(gruppi.length, "i gruppi ci sono").toBeGreaterThan(0);
        expect(gruppi, `«${GRUPPO}» è uno di loro`).toContain(GRUPPO);
        expect(Array.isArray(biblioteca[GRUPPO]), "e porta i suoi elementi").toBe(true);
    });

    test("un modello si crea, si rinomina e si cancella", async ({ adminApi, cleanup, naming }) => {
        const primoNome = naming.unique("modello");
        const secondoNome = `${primoNome}-rinominato`;
        // Comunque vada, i due nomi non restano nella biblioteca.
        cleanup.add("admin", `toglie i modelli «${primoNome}» dalla biblioteca`, async () => {
            await adminApi.tikz.remove(GRUPPO, secondoNome);
            await adminApi.tikz.remove(GRUPPO, primoNome);
        });

        const creato = await adminApi.tikz.saveNew({ existingGroup: GRUPPO, label: primoNome, code: DISEGNO });
        expect(creato.success, "la creazione riesce").toBe(true);
        expect(await adminApi.tikz.labels(GRUPPO), "e il modello è nella biblioteca").toContain(primoNome);

        const modificato = await adminApi.tikz.edit({
            groupName: GRUPPO,
            elementLabel: primoNome,
            label: secondoNome,
            code: "\\begin{tikzpicture}\\draw (0,0) -- (2,2);\\end{tikzpicture}",
        });
        expect(modificato.success, "la modifica riesce").toBe(true);
        const dopoLaModifica = await adminApi.tikz.labels(GRUPPO);
        expect(dopoLaModifica, "con il nome nuovo").toContain(secondoNome);
        expect(dopoLaModifica, "e senza quello vecchio").not.toContain(primoNome);

        const cancellato = await adminApi.tikz.remove(GRUPPO, secondoNome);
        expect(cancellato.success, "la cancellazione riesce").toBe(true);
        expect(await adminApi.tikz.labels(GRUPPO), "e la biblioteca torna com'era").not.toContain(secondoNome);
    });

    test("dal menu TeX dell'editor un gruppo si apre e mostra i suoi modelli", async ({
        contentFactory, studioEsercizio, teacherPage,
    }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.apriEditorQuesito(studioEsercizio.gruppo(0), 0);

        await teacherPage.locator("#fm-editor-toolbar-global").getByRole("button", { name: "TeX ▾" }).click();
        const menu = teacherPage.locator(".fm-tex-menu").first();
        await expect(menu, "il menu si apre").toBeVisible({ timeout: 30_000 });

        const gruppi = menu.locator(".fm-tex-group");
        await expect(gruppi.first(), "i gruppi arrivano dalla biblioteca").toBeVisible({ timeout: 30_000 });
        expect(await gruppi.count(), "e sono più d'uno").toBeGreaterThan(1);

        await gruppi.first().locator("button").first().click();
        const elenco = gruppi.first().locator("> *").nth(1);
        await expect(elenco, "aprendo un gruppo compaiono i suoi modelli").toBeVisible({ timeout: 15_000 });
        expect(await elenco.locator("button").count(), "almeno uno").toBeGreaterThan(0);
    });

    // Aprire il menu col mouse NON deve portare via il fuoco da dove si stava
    // scrivendo, e col fuoco fuori dal menu Escape deve chiuderlo lo stesso.
    //
    // Non e' un dettaglio di stile. Finche' il menu prendeva il fuoco a ogni
    // apertura, bastava che qualcun altro lo spostasse — e il campo
    // dell'editor se lo riprende quando finisce di prepararsi — perche'
    // `focusout` chiudesse il menu da solo. Sui computer di GitHub quel
    // recupero arriva prima dell'apertura e non succede niente; sul runner di
    // casa arriva dopo, e la suite notturna e' rimasta rossa dal passaggio.
    test("aperto col mouse il menu TeX non ruba il fuoco, e Escape lo chiude comunque", async ({
        contentFactory, studioEsercizio, teacherPage,
    }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.apriEditorQuesito(studioEsercizio.gruppo(0), 0);

        const campo = teacherPage.locator(".fm-editor-field").first();
        await campo.click();
        await expect(campo, "il fuoco parte dal campo dell'editor").toBeFocused();

        await teacherPage.locator("#fm-editor-toolbar-global")
            .getByRole("button", { name: "TeX ▾" }).click();
        const menu = teacherPage.locator(".fm-tex-menu").first();
        await expect(menu, "il menu si apre").toBeVisible({ timeout: 30_000 });

        // Il fuoco resta sul bottone che si e' cliccato — cosi' fa il browser
        // con qualunque <button>, e va bene. Quello che NON deve succedere e'
        // che finisca **dentro** il menu: e' da li' che partiva la corsa,
        // perche' bastava che qualcun altro lo spostasse perche' `focusout`
        // chiudesse il menu da solo.
        await expect(menu.locator(":focus"), "il fuoco non entra nel menu")
            .toHaveCount(0);

        // Il menu resta aperto anche dopo che i gruppi sono arrivati: e'
        // esattamente la finestra in cui prima si chiudeva da solo.
        await expect(menu.locator(".fm-tex-group").first(), "i gruppi arrivano")
            .toBeVisible({ timeout: 30_000 });
        await expect(menu, "e il menu e' ancora aperto").toBeVisible();

        await teacherPage.keyboard.press("Escape");
        await expect(menu, "Escape lo chiude anche col fuoco fuori").toBeHidden();
    });
});
