// @ts-check
/**
 * Le bozze si riconoscono nella barra laterale del docente (16/9/2026).
 *
 * Richiesta dell'utente: messo in bozza, un documento cambia stile nella
 * sidebar e mostra un simbolo che richiami la non visibilità pubblica
 * (js/modules/features/segno-bozza.js). Qui il giro vero, per i due loader
 * (Esercizi: db-sidepage.js; BES/DSA: risdoc-sidepage.js): una bozza ha il
 * segno; pubblicata dal modale lo perde, rimessa in bozza lo riprende.
 *
 * Il contenuto nasce dall'API con la cancellazione registrata subito.
 */
const { test, expect } = require("../support/test");

/** @type {ReadonlyArray<[import("../support/test").SidepageKey, string, string]>} [page object, section_key, tipo] */
const CASI = [
    ["esercizi", "eser", "esercizio"],
    ["besDsa", "bes", "document"],
];

for (const [pagina, sezione, tipo] of CASI) {
    test(`${sezione}: una bozza ha il suo segno, e lo perde e lo riprende dal modale`, async ({ homeDocente, teacherApi, cleanup, env }, testInfo) => {
        const page = homeDocente.page;
        const titolo = `e2e-bozza-${sezione}-${testInfo.workerIndex}-${Date.now()}`;
        const creato = await teacherApi.content.create({
            type: tipo, section_key: sezione,
            subject: env.terna.materia, indirizzo: env.terna.indirizzo, classe: env.terna.classe,
            topic: "9.7", title: titolo, visibility: "draft",
        });
        const id = creato.id;
        cleanup.add("teacher", `contenuto ${id} (${sezione})`, async () => {
            const r = await teacherApi.content.delete(id);
            if (!r.ok) throw new Error(`cancellazione di ${id}: ${r.status} ${r.text}`);
        });

        await homeDocente.vaiA();
        await homeDocente.scegliTerna(env.terna.indirizzo, env.terna.classe, env.terna.materia);
        const pannello = await homeDocente.apriSidepage(pagina);
        const voce = pannello.locator(`li[data-content-id="${id}"]`);

        /**
         * @param {boolean} atteso
         * @param {string} perche
         */
        const conSegno = async (atteso, perche) => {
            if (atteso) {
                await expect(voce, perche).toHaveClass(/(^|\s)fm-item--bozza(\s|$)/, { timeout: 15_000 });
                await expect(voce.locator(".fm-item-bozza svg"), "l'occhio barrato si vede").toBeVisible();
                await expect(voce.getByRole("link"), "il lettore di schermo sente «bozza»").toHaveAccessibleName(`Bozza, non visibile: ${titolo}`);
            } else {
                await expect(voce, perche).not.toHaveClass(/(^|\s)fm-item--bozza(\s|$)/, { timeout: 15_000 });
                await expect(voce.locator(".fm-item-bozza")).toHaveCount(0);
                await expect(voce.getByRole("link")).toHaveAccessibleName(titolo);
            }
        };

        /**
         * Apre ✎, sceglie la visibilità e salva.
         *
         * @param {"published"|"draft"} valore
         */
        const cambiaVisibilita = async (valore) => {
            if (!(await voce.locator(".fm-item-edit").isVisible())) {
                const blocco = pannello.locator("ul.fm-db-block", { has: page.locator(`li[data-content-id="${id}"]`) });
                await blocco.locator(".js-edit-section").click();
                await expect(blocco).toHaveAttribute("data-edit-active", "1");
            }
            await voce.locator(".fm-item-edit").click();
            const modale = page.locator(".fm-modal-backdrop").last();
            await expect(modale.locator('input[name="title"]')).toHaveValue(titolo, { timeout: 15_000 });
            await modale.locator('select[name="visibility"]').selectOption(valore);
            const salvataggio = page.waitForResponse((r) => r.request().method() === "POST"
                && new URL(r.url()).pathname === `/api/teacher/content/${id}/update`);
            await modale.locator('button[type="submit"]').click();
            const salvato = await salvataggio;
            expect(salvato.ok(), `salvataggio: ${salvato.status()} ${await salvato.text()}`).toBe(true);
            await expect(modale).toBeHidden();
            const riga = /** @type {Record<string, unknown>} */ (/** @type {unknown} */ ((await teacherApi.content.get(id)).content));
            expect(riga.visibility).toBe(valore);
        };

        await expect(voce, `il contenuto ${id} compare nella sidepage ${sezione}`).toBeAttached({ timeout: 30_000 });
        await conSegno(true, "una bozza ha il segno");
        await cambiaVisibilita("published");
        await conSegno(false, "pubblicata, il segno sparisce");
        await cambiaVisibilita("draft");
        await conSegno(true, "di nuovo bozza, il segno torna");
    });
}
