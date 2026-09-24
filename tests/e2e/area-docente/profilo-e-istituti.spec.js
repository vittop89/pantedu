// @ts-check
/**
 * Profilo del docente: istituti collegati, curriculum, e l'istituto attivo.
 * Riscrittura di g20_08_profilo.spec.js, g20_09_profilo_curriculum_tabs.spec.js,
 * g20_11_institute_link_feedback.spec.js, g20_07_sidebar_istituto.spec.js e
 * g20_10_sidebar_cls_scope.spec.js.
 *
 * Un docente può insegnare in più scuole. Il profilo elenca quelle a cui è
 * collegato e lascia gestire il proprio curriculum — indirizzi, classi,
 * materie — scuola per scuola. Nella barra laterale un selettore dice quale
 * sia l'istituto attivo, e da quello dipende tutto il resto: cambiandolo, il
 * selettore delle classi deve mostrare le classi di quella scuola e non la
 * somma di tutte, che è il difetto che questi test sorvegliano.
 *
 * Cosa cambia rispetto a prima: niente login nelle spec, niente
 * `page.evaluate` per nascondere finestre e banner o per cambiare i selettori,
 * niente stampe di console, e il codice dell'istituto non è più scritto nel
 * test: si legge da quelli che il profilo elenca.
 */
const { test, expect } = require("../support/test");

/** Codici degli istituti collegati, letti dal selettore della barra. */
async function istitutiCollegati(/** @type {import("@playwright/test").Page} */ page) {
    const selettore = page.locator("#sel-istituto");
    await expect(selettore, "il selettore degli istituti è in pagina").toBeAttached({ timeout: 30_000 });
    return selettore.locator("option").evaluateAll((opzioni) =>
        opzioni.map((o) => ({
            codice: /** @type {HTMLOptionElement} */ (o).value,
            etichetta: (o.textContent ?? "").trim(),
        })));
}

/**
 * Cambiare istituto dal selettore cambia la sessione del docente, che le prove
 * condividono: da ADR-037 (13/9/2026) un contenuto sta nella scuola in cui è
 * pubblicato, e una prova lasciata nell'altra scuola fa cercare alle
 * successive le pagine dove non ci sono. La scuola di partenza si rimette in
 * ogni caso, anche se la prova fallisce.
 * @param {import("../support/test").TeacherApi} teacherApi
 * @param {import("../support/test").CleanupRegistry} cleanup
 */
async function rimettiLaScuolaAllaFine(teacherApi, cleanup) {
    /** @type {{ current_institute_id: number|string|null }} */
    const corrente = await teacherApi.http.getJson("/api/tenant/current");
    const partenza = corrente.current_institute_id;
    if (partenza === null || partenza === undefined) return;
    cleanup.add("teacher", "rimetti la scuola attiva di partenza", async () => {
        await teacherApi.http.postForm("/api/tenant/switch", { institute_id: String(partenza) });
    });
}

test.describe("Area docente — profilo e istituti", () => {
    test("il profilo elenca gli istituti a cui il docente è collegato", async ({ teacherPage }) => {
        await teacherPage.goto("/area-docente/profilo");
        await expect(teacherPage.getByRole("heading", { level: 1 }), "la pagina del profilo").toContainText("Profilo");

        const tabella = teacherPage.locator("#fm-profile-current");
        await expect(tabella, "l'elenco si popola").not.toContainText("Caricamento", { timeout: 30_000 });
        const righe = tabella.locator("tbody tr");
        await expect(righe.first(), "almeno una scuola collegata").toBeVisible({ timeout: 30_000 });

        // Il codice meccanografico è la prima colonna: è quello che identifica
        // la scuola, e coincide con quel che offre il selettore della barra.
        const codici = await righe.locator("td:first-child").allTextContents();
        expect(codici.map((c) => c.trim()).filter(Boolean).length, "ogni riga porta il proprio codice")
            .toBe(codici.length);
    });

    test("le schede del curriculum si aprono una per volta, ognuna con le caselle del suo vocabolario", async ({ teacherPage }) => {
        await teacherPage.goto("/area-docente/profilo");

        const indirizzi = teacherPage.locator('.fm-curr-panel[data-panel="indirizzi"]');
        await expect(indirizzi.locator("[data-catalogo-lista]"), "la scheda degli indirizzi si apre per prima")
            .toBeVisible({ timeout: 30_000 });

        await teacherPage.locator('#fm-curr-tabs .fm-subtab[data-kind="classi"]').click();
        const classi = teacherPage.locator('.fm-curr-panel[data-panel="classi"]');
        await expect(classi, "la scheda delle classi").toBeVisible();
        await expect(classi.locator("[data-catalogo-lista]"), "con le classi della scuola da spuntare").toBeVisible();
        expect(
            await classi.locator("input[data-spunta]:checked").count(),
            "e quelle già spuntate dal docente",
        ).toBeGreaterThan(0);

        await teacherPage.locator('#fm-curr-tabs .fm-subtab[data-kind="materie"]').click();
        const materie = teacherPage.locator('.fm-curr-panel[data-panel="materie"]');
        await expect(materie, "la scheda delle materie").toBeVisible();
        await expect(materie.locator("[data-catalogo-lista]"), "con le sue caselle").toBeVisible();
        await expect(indirizzi, "e quella degli indirizzi si chiude").toBeHidden();
        await expect(teacherPage.locator("#fm-profile-current"), "l'elenco delle scuole resta in vista").toBeVisible();
    });

    test("ricollegare una scuola già collegata lo dice, invece di fingere un collegamento nuovo", async ({ teacherPage }) => {
        await teacherPage.goto("/area-docente/profilo");
        await expect(teacherPage.locator("#fm-profile-current"), "l'elenco si popola")
            .not.toContainText("Caricamento", { timeout: 30_000 });

        // Si cerca una scuola per nome e si sceglie una voce che l'elenco
        // segnala come già collegata.
        const primoCodice = (await teacherPage.locator("#fm-profile-current tbody tr td:first-child").first().textContent() ?? "").trim();
        expect(primoCodice, "c'è una scuola già collegata da cui partire").toBeTruthy();
        await teacherPage.locator("#fm-profile-search").fill(primoCodice);

        const giaCollegata = teacherPage.locator(".fm-ac__list:not([hidden]) .fm-ac__item")
            .filter({ has: teacherPage.locator(".fm-ac__note") })
            .first();
        await expect(giaCollegata, "la ricerca la segnala come già collegata").toBeVisible({ timeout: 30_000 });
        await giaCollegata.click();
        await teacherPage.locator("#fm-profile-add-btn").click();

        const risposta = teacherPage.locator("#fm-profile-add-feedback");
        await expect(risposta, "l'applicazione dice che era già collegata").toContainText(/già collegat/i, { timeout: 30_000 });
        await expect(risposta, "e non annuncia un collegamento nuovo").not.toContainText("✓ collegato");
    });

    test("il selettore della barra cambia l'istituto attivo, e lo ricorda", async ({ teacherPage, teacherApi, cleanup }) => {
        await rimettiLaScuolaAllaFine(teacherApi, cleanup);
        await teacherPage.goto("/");
        const istituti = await istitutiCollegati(teacherPage);
        expect(istituti.length, "il docente ha almeno una scuola").toBeGreaterThan(0);

        const ultimo = istituti[istituti.length - 1];
        expect(ultimo, "c'è almeno un istituto nel selettore").toBeTruthy();
        const scelto = String(ultimo?.codice);
        await teacherPage.locator("#sel-istituto").selectOption(scelto);
        await expect
            .poll(
                async () => teacherPage.evaluate(() => ({
                    attivo: /** @type {{ activeInstituteCode?: string }} */ (window.FM?.["AppState"])?.activeInstituteCode,
                    ricordato: sessionStorage.getItem("activeInstituteCode"),
                })),
                { message: "l'istituto scelto diventa quello attivo e resta ricordato", timeout: 15_000 },
            )
            .toEqual({ attivo: scelto, ricordato: scelto });
    });

    test("le classi nel selettore sono quelle dell'istituto attivo, non la somma di tutti", async ({ teacherPage, teacherApi, cleanup }) => {
        await rimettiLaScuolaAllaFine(teacherApi, cleanup);
        await teacherPage.goto("/");
        const istituti = await istitutiCollegati(teacherPage);
        expect(istituti.length, "per questo caso serve un docente con più scuole").toBeGreaterThan(1);

        for (const istituto of istituti) {
            await teacherPage.locator("#sel-istituto").selectOption(istituto.codice);
            // Il cambio passa dal server: si ricarica per leggere il curriculum nuovo.
            await expect
                .poll(async () => teacherPage.evaluate(() => sessionStorage.getItem("activeInstituteCode")), { timeout: 15_000 })
                .toBe(istituto.codice);
            await teacherPage.reload();

            const classi = await teacherPage
                .locator("#sel-cls option[value]:not([disabled])")
                .evaluateAll((opzioni) => opzioni.map((o) => /** @type {HTMLOptionElement} */ (o).value).filter(Boolean));

            // I codici delle classi sono brevi (1..5) e si ripetono da una
            // scuola all'altra: se le classi fossero sommate, qui si vedrebbero
            // doppioni. È il modo in cui questo test riconosce la somma.
            expect(new Set(classi).size, `classi ripetute in «${istituto.etichetta}»: ${classi.join(", ")}`).toBe(classi.length);
            expect(classi.length, `troppe classi in «${istituto.etichetta}»: sembra la somma di più scuole`).toBeLessThanOrEqual(5);
        }
    });
});
