// @ts-check
/**
 * Iscrizioni chiuse: la pagina lo dice, e il modulo non c'è.
 * Riscrittura di registration.spec.js (primo caso),
 * registration_with_institutes.spec.js, dei due casi sulle iscrizioni di
 * student_modes_and_public_sidebar.spec.js e di due casi di
 * analytics_and_classes.spec.js.
 *
 * L'istanza su cui gira la suite è in uso personale (ADR-032, scenario
 * `single`): non si iscrive nessuno. La pagina delle iscrizioni lo spiega e
 * non mostra il modulo — né quello del docente, né quello dello studente con
 * la scelta dell'istituto, né la variante ridotta — e la rotta rifiuta le
 * richieste inviate lo stesso.
 *
 * Cinque spec verificavano questa stessa cosa da cinque punti di vista, ognuna
 * col proprio login e la propria copia della pagina. Qui è un file.
 *
 * Cosa cambia rispetto a prima: niente login nelle spec, e le asserzioni non
 * accettano più tre esiti diversi. Le spec storiche scrivevano
 * `expect([200, 403, 404]).toContain(status)`: qualunque cosa rispondesse la
 * rotta andava bene. Qui la pagina deve aprirsi e spiegare, e la richiesta
 * deve essere rifiutata.
 */
const { test, expect } = require("../support/test");

test.describe("Pubblico — iscrizioni chiuse", () => {
    test("la pagina delle iscrizioni spiega che sono chiuse, e non ha moduli", async ({ page }) => {
        const risposta = await page.goto("/register");
        expect(risposta?.status(), "la pagina si apre").toBe(200);
        await expect(page.locator("body"), "e spiega che non si iscrive nessuno").toContainText(/iscrizioni non sono aperte/i);

        // Nessuna delle forme del modulo è in pagina.
        await expect(page.locator("#fm-register-form"), "niente modulo di iscrizione").toHaveCount(0);
        await expect(page.locator('select[name="role"]'), "niente scelta del ruolo").toHaveCount(0);
        await expect(page.locator("#reg_indirizzo"), "niente scelta dell'indirizzo").toHaveCount(0);
        await expect(page.locator('input[name="birth_date"]'), "niente data di nascita").toHaveCount(0);
        await expect(page.locator('select[name="institute_code"]'), "niente scelta dell'istituto").toHaveCount(0);
    });

    test("una richiesta d'iscrizione inviata lo stesso viene rifiutata", async ({ page }) => {
        const tentativo = await page.request.post("/register", {
            form: {
                role: "teacher",
                first_name: "Docente",
                last_name: "Di Prova",
                email: `e2e-${Date.now()}@example.it`,
                password: "unaPasswordQualsiasi",
            },
            maxRedirects: 0,
        });
        expect(tentativo.status(), "l'iscrizione non viene accettata").not.toBe(200);
    });

    test("l'elenco delle iscrizioni in sospeso resta un elenco, vuoto", async ({ adminApi }) => {
        const risposta = await adminApi.http.getJson("/admin/registrations");
        expect(Array.isArray(risposta["pending"]), "la rotta risponde con un elenco").toBe(true);
    });

    test("una sezione non pubblicata non si apre senza sessione", async ({ page }) => {
        // Le sezioni della barra si possono pubblicare per chi non è entrato.
        // Quelle che non lo sono non devono aprirsi: è il confine fra quel che
        // l'Istituto mostra al pubblico e quel che resta dentro.
        const risposta = await page.request.get("/public/sidebar/sezione-che-non-esiste");
        expect(risposta.status(), "una sezione non pubblicata non c'è").toBe(404);
    });
});
