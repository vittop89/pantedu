// @ts-check
/**
 * Difese in piedi e rotte tolte davvero.
 * Riscrittura di full_audit.spec.js, legacy_modernization.spec.js e dei casi
 * rimasti di smoke.spec.js (gli altri sono in `studio/pagina-pubblica`).
 *
 * I casi su moduli, contratti delle API e navigazione fra le pagine sono
 * passati a `qualita/contratti-delle-api` e `qualita/pagine-e-navigazione`
 * quando `interactions.spec.js` è stata riscritta: verificavano le stesse
 * cose.
 *
 * È il giro di controllo che si fa dopo un cambiamento largo: la pagina
 * iniziale si apre senza errori in console e con i moduli dell'applicazione al
 * loro posto, le pagine dell'amministrazione e del docente rispondono, la
 * ricerca degli esercizi filtra davvero, le mutazioni senza gettone di
 * sicurezza vengono bloccate, e gli indirizzi delle pagine PHP che non
 * esistono più rispondono che non esistono più — non li serve nessuno, e
 * soprattutto non li serve il vecchio codice.
 *
 * Cosa cambia rispetto a prima: niente login nelle spec, niente
 * `addInitScript` per il consenso ai cooki (lo dà la configurazione della
 * suite per tutti), e la diagnostica comune al posto della raccolta di errori
 * scritta a mano.
 *
 * Un caso è stato eliminato senza sostituto: quello che si chiamava «Ulid
 * genera stringhe distinte» e chiedeva due volte il gettone di sicurezza
 * verificando solo che entrambe le richieste rispondessero. Il suo commento lo
 * ammetteva («sanity minimale»): non guardava nessun identificativo e non
 * poteva accorgersi di una collisione. Voce 49 del debito.
 */
const { test, expect } = require("../support/test");

test.describe("Qualità — pagine e moduli", () => {
    test("il cruscotto del docente porta la ricerca fra i propri contenuti", async ({ adminPage }) => {
        await adminPage.goto("/area-docente/dashboard");
        await expect(
            adminPage.locator(".fm-title").filter({ hasText: /Ricerca nei tuoi contenuti/i }).first(),
            "la ricerca è in pagina",
        ).toBeAttached({ timeout: 30_000 });
    });

    test("la ricerca degli esercizi filtra per materia", async ({ adminPage, env }) => {
        await adminPage.goto("/exercises");
        await adminPage.locator('select[name="materia"]').selectOption(env.terna.materia);
        await adminPage.getByRole("button", { name: /cerca/i }).click();
        await expect(adminPage.locator("#fm-ex-results"), "arrivano i risultati").toContainText(/risultati/i, { timeout: 30_000 });
    });
});

test.describe("Qualità — difese e rotte tolte", () => {
    test("una mutazione senza gettone di sicurezza viene bloccata", async ({ page, adminPage }) => {
        const senzaSessione = await page.request.post("/check/password", { form: { password: "x" }, maxRedirects: 0 });
        expect([301, 302, 401, 403], `senza sessione ha risposto ${senzaSessione.status()}`).toContain(senzaSessione.status());

        // Con la sessione ma senza gettone: il controllo scatta lo stesso.
        const senzaGettone = await adminPage.request.post("/check/password", { form: { password: "x" } });
        const corpo = await senzaGettone.text();
        const bloccata = senzaGettone.status() === 403
            || senzaGettone.status() === 419
            || (senzaGettone.status() === 500 && /CSRF token invalid|csrf_invalid/i.test(corpo));
        expect(bloccata, `stato ${senzaGettone.status()}: ${corpo.slice(0, 200)}`).toBe(true);
    });

    test("le vecchie pagine PHP non esistono più", async ({ page, adminPage }) => {
        // Un file PHP che non c'è lo ferma nginx, prima dell'applicazione
        // (`try_files $uri =404`), e così il router dello sviluppo. Fino al
        // 14/9/2026 la suite girava su `php -S`, la richiesta arrivava
        // all'applicazione e qui ci si aspettava 410: la produzione non l'ha
        // mai risposto.
        const vecchia = await adminPage.request.get("/eser/sc/eser_sc2s/MAT/2.0_MAT-Sistemi_lineari-sc2s.php");
        expect(vecchia.status(), "gli esercizi in PHP non si servono").toBe(404);

        // Le vecchie cartelle arrivano all'applicazione, che dichiara la rotta
        // tolta. Con la sessione aperta: senza, la richiesta finisce sulla
        // pagina di accesso e non arriva mai a quel controllo.
        const cartella = await adminPage.request.get("/eser/sc/eser_sc2s/MAT/2.0_MAT-Sistemi_lineari-sc2s", { maxRedirects: 0 });
        expect(cartella.status(), "le vecchie cartelle degli esercizi rispondono che non ci sono più").toBe(410);

        const accesso = await page.request.get("/log/auth/login.php", { maxRedirects: 0 });
        expect(accesso.status(), "e il vecchio ingresso di accesso nemmeno").toBe(404);
    });

    test("le finestre usano i nomi nuovi, e il banner dei cookie non c'è più", async ({ page }) => {
        await page.goto("/?home=1");

        for (const identificativo of ["fm-modal-overlay", "fm-license-modal", "fm-author-modal"]) {
            await expect(page.locator(`#${identificativo}`), `${identificativo} è in pagina`).toBeAttached({ timeout: 30_000 });
        }
        for (const vecchio of ["modal-overlay", "license-info-modal", "cookie-consent-modal", "fm-cookie-modal", "author-banner"]) {
            await expect(page.locator(`#${vecchio}`), `il vecchio ${vecchio} è sparito`).toHaveCount(0);
        }
    });
});
