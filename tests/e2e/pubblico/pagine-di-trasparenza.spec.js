// @ts-check
/**
 * Le pagine che spiegano come sono trattati i dati.
 * Riscrittura di gdpr_trust_pages.spec.js, gdpr_parent_consent.spec.js e
 * gdpr_signup_minor_flow.spec.js.
 *
 * Tre pagine pubbliche, raggiungibili senza account: le misure tecniche di
 * sicurezza (art. 32), il riepilogo dei propri diritti con il rimando al
 * Garante, e l'informativa. Devono rimandarsi l'una all'altra e al modulo per
 * scrivere al responsabile della protezione dei dati: se un rimando si rompe,
 * chi cerca come esercitare un diritto non lo trova, ed è esattamente quel che
 * l'articolo 12 vuole evitare.
 *
 * L'ultimo caso riguarda il consenso del genitore: la pagina si apre con un
 * collegamento personale ricevuto per posta, e con un collegamento non valido
 * deve dire di no in modo comprensibile — una pagina, non un errore grezzo.
 * Il percorso completo (collegamento valido, conferma, revoca) richiederebbe di
 * creare in anticipo un account di studente minorenne, che in questa istanza
 * non esiste: le iscrizioni sono chiuse. È annotato nel registro del debito.
 *
 * Cosa cambia rispetto a prima: tre spec diventano una, e le asserzioni non
 * accettano più due esiti («400 oppure 404»): l'applicazione risponde in un
 * modo solo, ed è quello che il test scrive.
 */
const { test, expect } = require("../support/test");

test.describe("Pubblico — pagine di trasparenza", () => {
    test("la pagina della sicurezza elenca le misure e cita l'articolo 32", async ({ page }) => {
        const risposta = await page.request.get("/security");
        expect(risposta.ok(), "la pagina si apre senza account").toBe(true);
        const html = await risposta.text();

        expect(html, "il titolo").toContain("Sicurezza tecnica");
        expect(html, "la cifratura dei dati a riposo").toContain("AES-256-GCM");
        expect(html, "la cancellazione per distruzione della chiave").toContain("Crypto-shredding");
        expect(html, "il costo dell'impronta delle password").toContain("bcrypt cost 12");
        expect(html, "il trasporto obbligatoriamente cifrato").toContain("HSTS");
        expect(html, "e l'articolo che le richiede").toContain("Art. 32 GDPR");
        expect(html, "col rimando a chi scrivere").toContain("/dpo-contact");
    });

    test("il riepilogo dei diritti rimanda al Garante e all'accesso", async ({ page }) => {
        const risposta = await page.request.get("/privacy/your-data");
        expect(risposta.ok(), "la pagina si apre senza account").toBe(true);
        const html = await risposta.text();

        expect(html, "il titolo").toContain("I tuoi dati");
        expect(html, "gli articoli che riguardano i diritti").toMatch(/Art\.\s*(15|17|20|15-22)/);
        expect(html, "il rimando all'autorità di controllo").toContain("garanteprivacy.it");
        // Senza sessione la pagina non può mostrare i dati di nessuno: invita
        // a entrare.
        expect(html, "e l'invito a entrare, per chi vuole i propri").toContain("/login");
    });

    test("l'informativa è resa come pagina, e parla dei minori", async ({ page }) => {
        const risposta = await page.request.get("/privacy/informativa");
        expect(risposta.ok(), "la pagina si apre senza account").toBe(true);
        const html = await risposta.text();

        expect(html, "il testo è reso in pagina, non servito grezzo").toMatch(/<h1[^>]*>/);
        expect(html, "il titolo").toContain("Informativa Privacy");
        expect(html, "e la sezione sul consenso dei minori").toContain("Art. 8");
    });

    test("le tre pagine si rimandano l'una all'altra", async ({ page }) => {
        for (const indirizzo of ["/security", "/privacy/your-data"]) {
            const html = await (await page.request.get(indirizzo)).text();
            expect(html, `da ${indirizzo} si arriva alla sicurezza`).toContain('href="/security"');
            expect(html, `da ${indirizzo} si arriva al riepilogo dei diritti`).toContain('href="/privacy/your-data"');
            expect(html, `da ${indirizzo} si arriva al modulo per il responsabile`).toContain('href="/dpo-contact"');
        }
    });

    test("un collegamento al consenso del genitore non valido risponde con una pagina, non con un errore grezzo", async ({ page }) => {
        const risposta = await page.request.get("/parent-consent/collegamento-non-valido");
        expect(risposta.status(), "l'applicazione dice che non c'è").toBe(404);

        const html = await risposta.text();
        expect(html, "ma lo dice con una pagina").toContain("<!DOCTYPE html>");
        expect(html, "dell'applicazione").toContain("Pantedu");
        expect(html, "spiegando che il collegamento non vale").toContain("Token non valido");
    });

    test("confermare con un collegamento non valido non fa niente", async ({ page }) => {
        const risposta = await page.request.post("/parent-consent/collegamento-non-valido", {
            form: { action: "confirm" },
        });
        expect(risposta.status(), "la conferma su un collegamento inesistente non passa").toBe(400);
    });

    test("le iscrizioni chiuse non raccolgono dati di minori", async ({ page }) => {
        await page.goto("/register");
        await expect(page.locator("body"), "la pagina dice che non si iscrive nessuno")
            .toContainText(/iscrizioni non sono aperte/i);
        await expect(
            page.locator('input[name="birth_date"], input[name="parent_email"], input[name="accept_tos"]'),
            "e non chiede data di nascita, contatto del genitore né accettazione",
        ).toHaveCount(0);

        const tentativo = await page.request.post("/register", {
            form: {
                role: "student",
                first_name: "Minore",
                last_name: "Di Prova",
                email: `e2e-minore-${Date.now()}@example.it`,
                password: "unaPasswordQualsiasi",
                birth_date: "2015-01-01",
                parent_email: "genitore@example.it",
                accept_tos: "1",
            },
            maxRedirects: 0,
        });
        expect(tentativo.status(), "e una richiesta inviata lo stesso non viene accettata").not.toBe(200);
    });
});
