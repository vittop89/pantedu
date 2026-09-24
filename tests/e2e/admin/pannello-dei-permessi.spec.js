// @ts-check
/**
 * Il pannello «Gestisci template»: chi vede un modello, e chi in più.
 *
 * Segnalazione dell'utente (21/9/2026), con schermata: nel riquadro dell'ambito
 * si vedevano tutti e tre i controlli insieme (il menu, l'elenco degli Istituti
 * e il campo del codice), ognuno a tutta larghezza, e dentro i menu il testo
 * usciva tagliato sopra e sotto. In più il bottone «Salva ambito» non mandava
 * la motivazione che il registro degli accessi privilegiati pretende: il
 * salvataggio non sarebbe passato.
 *
 * Tre cose misurate qui, tutte dal browser vero:
 *   1. si vede un controllo per volta, quello che serve all'ambito scelto;
 *   2. il testo dentro i controlli ci sta (l'altezza utile regge la riga);
 *   3. cambiare chi vede il modello si salva davvero, e la pagina lo racconta.
 *
 * Il modello torna visibile a tutti nella pulizia, anche se la prova fallisce.
 */
const { test, expect } = require("../support/test");

/** L'altezza utile di un controllo regge la sua riga di testo? */
async function testoNonTagliato(/** @type {any} */ controllo, /** @type {string} */ chi) {
    const m = await controllo.evaluate((/** @type {HTMLElement} */ el) => {
        const s = getComputedStyle(el);
        const num = (/** @type {string} */ v) => parseFloat(v) || 0;
        return {
            utile: el.clientHeight - num(s.paddingTop) - num(s.paddingBottom),
            riga: s.lineHeight === "normal" ? num(s.fontSize) * 1.2 : num(s.lineHeight),
        };
    });
    expect(
        m.utile,
        `${chi}: dentro restano ${m.utile.toFixed(1)}px per una riga alta ${m.riga.toFixed(1)}px → testo tagliato`,
    ).toBeGreaterThanOrEqual(m.riga - 0.5);
}

test.describe("Amministrazione — pannello dei permessi di un modello", () => {
    test("un controllo per volta, e il testo non esce tagliato", async ({ adminPage }) => {
        await adminPage.goto("/admin/templates");
        await adminPage.locator('button[data-action="manage"]').first().click();

        const pannello = adminPage.locator(".fm-ar-inline-panel--detail");
        await expect(pannello).toBeVisible();
        const ambito = pannello.locator(".fm-ar-ambito");
        const menu = ambito.locator("select").first();
        const istituti = ambito.locator("select").nth(1);
        const codice = ambito.locator('input[type="text"]');

        // Di partenza il modello è aperto a tutti: nessun dettaglio da chiedere.
        await expect(menu).toBeVisible();
        await expect(istituti, "l'elenco degli Istituti non serve con «tutti»").toBeHidden();
        await expect(codice, "il codice non serve con «tutti»").toBeHidden();

        await testoNonTagliato(menu, "il menu dell'ambito");

        // Un Istituto: compare il suo elenco, e solo quello.
        await menu.selectOption("institute");
        await expect(istituti).toBeVisible();
        await expect(codice).toBeHidden();
        await testoNonTagliato(istituti, "l'elenco degli Istituti");

        // Un indirizzo: compare il campo del codice, e l'elenco sparisce.
        await menu.selectOption("indirizzo");
        await expect(codice).toBeVisible();
        await expect(istituti).toBeHidden();
        await testoNonTagliato(codice, "il campo del codice");
        await expect(codice, "il campo dice che cosa vuole").toHaveAttribute("placeholder", /indirizzo/i);

        await menu.selectOption("classe");
        await expect(codice).toBeVisible();
        await expect(codice).toHaveAttribute("placeholder", /classe/i);

        // E i controlli stanno su una riga, non uno sopra l'altro a tutta pagina.
        const larghezza = await ambito.evaluate((el) => {
            // Larghezza zero = nascosto: `getBoundingClientRect` sta su ogni
            // Element, mentre `offsetParent` no (e il controllo dei tipi delle
            // spec, che gira in CI, lo fa notare).
            const visibili = [...el.querySelectorAll("select, input, button")]
                .map((c) => c.getBoundingClientRect().width)
                .filter((w) => w > 0);
            return Math.max(...visibili) / el.getBoundingClientRect().width;
        });
        expect(larghezza, "nessun controllo prende tutta la riga da solo").toBeLessThan(0.9);
    });

    test("il form «Nuovo template» si apre e si chiude", async ({ adminPage }) => {
        // Gemello dello stesso guasto, gia' in produzione e mai segnalato: il
        // form dichiara `display: flex`, e una dichiarazione del foglio di
        // stile batte sempre il `[hidden]` del browser — quindi non si
        // chiudeva né col bottone né con «Annulla».
        await adminPage.goto("/admin/templates");
        const form = adminPage.locator("form[data-action='new-template-form']");
        await expect(form, "di partenza è chiuso").toBeHidden();

        await adminPage.locator("button[data-action='new-template-toggle']").click();
        await expect(form, "il bottone lo apre").toBeVisible();

        await form.locator("button[data-action='new-template-cancel']").click();
        await expect(form, "«Annulla» lo chiude").toBeHidden();

        // E l'interruttore chiude anche da sé.
        await adminPage.locator("button[data-action='new-template-toggle']").click();
        await expect(form).toBeVisible();
        await adminPage.locator("button[data-action='new-template-toggle']").click();
        await expect(form).toBeHidden();
    });

    test("chi vede il modello si cambia davvero, e la pagina lo dice", async ({ adminPage, adminApi, cleanup }) => {
        await adminPage.goto("/admin/templates");
        const riga = adminPage.locator("tr[data-template-id]").first();
        const id = Number(await riga.getAttribute("data-template-id"));
        expect(id, "un modello da cui partire").toBeGreaterThan(0);
        cleanup.add("admin", `rimette il modello ${id} visibile a tutti`, async () => {
            await adminApi.risdoc.setVisibilityScope(id, { scope: "public" });
        });

        await riga.locator('button[data-action="manage"]').click();
        const pannello = adminPage.locator(".fm-ar-inline-panel--detail");
        await expect(pannello.locator(".fm-ar-chi-vede")).toContainText(/tutti i docenti/i);

        await pannello.locator(".fm-ar-ambito select").first().selectOption("denied");
        await pannello.getByRole("button", { name: /salva ambito/i }).click();

        // Il pannello si rilegge dal server: se il salvataggio fosse stato
        // rifiutato (per esempio senza la motivazione per il registro degli
        // accessi privilegiati) la frase resterebbe quella di prima.
        await expect(pannello.locator(".fm-ar-chi-vede"), "la frase nuova viene dal dato salvato")
            .not.toContainText(/tutti i docenti/i, { timeout: 15_000 });

        // E la pastiglia nell'elenco lo dice senza aprire niente. «solo su
        // invito» e non «nessuno»: le spunte 👁, i collaboratori ✎ e gli
        // amministratori lo vedono comunque, e scrivere «nessuno» sarebbe falso.
        await expect(riga.locator(".fm-ar-pill", { hasText: "solo su invito" })).toBeVisible();
    });
});
