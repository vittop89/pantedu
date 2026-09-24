// @ts-check
/**
 * Il catalogo si spunta, non si scrive (ADR-035), e i selettori della barra
 * si scelgono in ordine.
 *
 * Nel profilo, «Curriculum dell'istituto attivo» elenca il vocabolario della
 * scuola con una casella per voce: le classi sotto il loro corso, le materie
 * dopo almeno una classe. Togliere una spunta la toglie subito anche dai
 * selettori della barra, senza ricaricare la pagina. Nella barra la classe si
 * sceglie dopo l'indirizzo e la materia dopo la classe: finché il livello a
 * monte è vuoto, quello a valle è chiuso.
 *
 * La prova spegne una classe che il docente ha già e la riaccende a fine
 * prova, anche se qualcosa va storto: non lascia il docente senza la classe.
 */
const { test, expect } = require("../support/test");

test.describe("Area docente — il catalogo a caselle e la catena dei selettori", () => {
    test("il profilo elenca il vocabolario della scuola da spuntare, e una spunta tolta sparisce dalla barra", async ({ teacherPage, teacherApi, cleanup }) => {
        const cat = await teacherApi.curriculum.completo();
        expect(cat.ok, "il catalogo risponde").toBe(true);
        const voc = cat.institute_vocabolario;
        expect(voc, "il vocabolario della scuola viaggia insieme alle spunte").toBeTruthy();
        expect(voc?.classi.length ?? 0, "la scuola ha delle classi a catalogo").toBeGreaterThan(0);
        expect(voc?.indirizzi.length ?? 0, "e degli indirizzi").toBeGreaterThan(0);

        const accese = (cat.curriculum.classi ?? []).filter((c) => c.active);
        expect(accese.length, "il docente ha almeno una classe spuntata").toBeGreaterThan(0);
        const classe = accese[accese.length - 1];
        if (!classe) throw new Error("nessuna classe spuntata");
        cleanup.add("teacher", `riaccende la classe «${classe.code}»`, async () => {
            await teacherApi.curriculum.spunta(classe.id, true);
        });

        await teacherPage.goto("/area-docente/profilo");
        await teacherPage.locator('#fm-curr-tabs .fm-subtab[data-kind="classi"]').click();
        const pannello = teacherPage.locator('.fm-curr-panel[data-panel="classi"]');
        await expect(pannello.locator("[data-catalogo-lista]"), "la scheda delle classi è un elenco di caselle").toBeVisible({ timeout: 30_000 });
        // 14/9/2026 — senza un avviso, sotto il conto compariva la parola «null»:
        // replaceChildren scrive come testo quello che non è un nodo.
        expect(
            await pannello.evaluate((p) => Array.from(p.childNodes).some((n) => n.nodeType === Node.TEXT_NODE && (n.textContent ?? "").trim() === "null")),
            "sotto il conto delle classi non c'è la parola «null»",
        ).toBe(false);
        // Le classi compaiono sotto il loro corso, anni compresi (ADR-042), e
        // solo per gli indirizzi spuntati. Le altre classi della scuola restano
        // fuori finché il loro indirizzo non è spuntato.
        const indirizziAccesi = new Set((cat.curriculum.indirizzi ?? []).filter((i) => i.active).map((i) => i.code));
        const attese = (voc?.classi ?? []).filter((c) => !c.indirizzo || indirizziAccesi.has(c.indirizzo)).length;
        expect(
            await pannello.locator("[data-catalogo-lista] input[data-spunta]").count(),
            "una casella per classe della scuola sotto gli indirizzi spuntati, non solo per le classi già spuntate",
        ).toBe(attese);
        expect(attese, "e più di quelle spuntate: il vocabolario è della scuola").toBeGreaterThanOrEqual(accese.length);
        // Sigla e corso: «3» dello scientifico e «3» dell'artistico sono due caselle.
        const suaCasella = `[data-catalogo-lista] input[data-spunta][data-code="${classe.code}"][data-indirizzo="${classe.indirizzo ?? ""}"]`;
        const casella = pannello.locator(suaCasella);
        await expect(casella, "la classe del docente è spuntata").toBeChecked();
        await expect(pannello.locator(`[data-nome][data-id="${classe.id}"]`), "accanto c'è il suo nome personale").toBeVisible();

        // Nella barra, scelto il suo indirizzo, la classe c'è.
        const indirizzo = teacherPage.locator("#sel-iis");
        if (classe.indirizzo) {
            await indirizzo.selectOption(classe.indirizzo);
        } else {
            await indirizzo.selectOption({ index: 1 });
        }
        await expect(teacherPage.locator(`#sel-cls option[value="${classe.code}"]`), "la barra la offre").toHaveCount(1);

        await casella.uncheck();
        await expect(
            pannello.locator(suaCasella),
            "la casella resta, spenta",
        ).not.toBeChecked({ timeout: 15_000 });
        await expect
            .poll(async () => (await teacherApi.curriculum.completo()).curriculum.classi?.find((c) => c.id === classe.id)?.active, {
                message: "l'API la dice spenta, non cancellata",
                timeout: 15_000,
            })
            .toBe(false);
        await expect(teacherPage.locator(`#sel-cls option[value="${classe.code}"]`), "e la barra non la offre più, senza ricaricare").toHaveCount(0, { timeout: 15_000 });

        await pannello.locator(suaCasella).check();
        await expect
            .poll(async () => (await teacherApi.curriculum.completo()).curriculum.classi?.find((c) => c.id === classe.id)?.active, {
                message: "riaccesa: la stessa riga, non una nuova",
                timeout: 15_000,
            })
            .toBe(true);
        await expect(teacherPage.locator(`#sel-cls option[value="${classe.code}"]`), "e la barra la offre di nuovo").toHaveCount(1, { timeout: 15_000 });
    });

    test("nella barra la classe si sceglie dopo l'indirizzo e la materia dopo la classe", async ({ teacherPage }) => {
        await teacherPage.goto("/?home=1");
        // Una sessione senza scelte: è il caso in cui i selettori sceglievano da soli.
        await teacherPage.evaluate(() => sessionStorage.clear());
        await teacherPage.reload();

        const indirizzo = teacherPage.locator("#sel-iis");
        const classe = teacherPage.locator("#sel-cls");
        const materia = teacherPage.locator("#sel-mater");
        await expect(indirizzo).toBeVisible();
        expect(
            await indirizzo.locator('option[value]:not([value=""])').count(),
            "per questo caso serve un docente con più di un indirizzo, altrimenti la scelta è obbligata",
        ).toBeGreaterThan(1);

        await expect(indirizzo, "nessun indirizzo scelto al posto del docente").toHaveValue("");
        await expect(classe, "senza indirizzo la classe è chiusa").toBeDisabled();
        await expect(materia, "e la materia pure").toBeDisabled();

        await indirizzo.selectOption({ index: 1 });
        const scelto = await indirizzo.inputValue();
        expect(scelto).not.toBe("");
        await expect(classe, "scelto l'indirizzo, la classe si apre").toBeEnabled();
        await expect(materia, "la materia no: manca la classe").toBeDisabled();
        const fuoriCorso = await classe
            .locator('option[value]:not([value=""])')
            .evaluateAll((opzioni, corso) => opzioni.filter((o) => o.dataset["indirizzo"] && o.dataset["indirizzo"] !== corso).length, scelto);
        expect(fuoriCorso, "le classi offerte sono solo quelle del corso scelto (o valide per tutti)").toBe(0);
        expect(
            await classe.locator('option[value]:not([value=""])').count(),
            "e ce n'è almeno una",
        ).toBeGreaterThan(0);

        await classe.selectOption({ index: 1 });
        await expect(materia, "scelta la classe, si apre la materia").toBeEnabled();

        // Tornare sul segnaposto dell'indirizzo richiude tutto: non resta una
        // classe di un corso che non è più scelto.
        await teacherPage.evaluate(() => {
            const sel = /** @type {HTMLSelectElement} */ (document.getElementById("sel-iis"));
            sel.selectedIndex = 0;
            sel.dispatchEvent(new Event("change", { bubbles: true }));
        });
        await expect(classe).toBeDisabled();
        await expect(materia).toBeDisabled();
    });
});
