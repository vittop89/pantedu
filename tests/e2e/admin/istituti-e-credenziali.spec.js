// @ts-check
/**
 * Istituti e credenziali d'accesso alle risorse riservate.
 * Riscrittura di institutes_credentials.spec.js.
 *
 * Chi non ha un account nella piattaforma — uno studente, un genitore — apre
 * le risorse riservate con una coppia di credenziali che il docente ha creato
 * apposta. Questi test seguono quella catena: il docente crea la credenziale,
 * chi non è autenticato la usa, e l'applicazione registra il permesso nella
 * sessione dicendo da dove viene.
 *
 * Il riquadro che chiede quelle credenziali compare solo a chi non ha già una
 * sessione: a un docente o a un amministratore no.
 *
 * Cosa cambia rispetto a prima: niente login nelle spec, niente `fetch` scritto
 * dentro a `page.evaluate` (erano dieci), niente schermate salvate su disco, e
 * la credenziale creata viene cancellata — prima restava.
 *
 * Un caso della spec storica non è stato ripreso: la creazione di un istituto
 * da parte dell'amministratore. Verificava soltanto che l'elenco continuasse a
 * rispondere («list.ok è vero»), con un ramo `if` vuoto per il caso di
 * fallimento; e soprattutto non esiste una rotta per cancellare un istituto,
 * quindi ogni giro ne avrebbe lasciato uno nel database. È la voce 46 del
 * debito.
 */
const { test, expect } = require("../support/test");

test.describe("Amministrazione — istituti e credenziali d'accesso", () => {
    test("l'elenco pubblico degli istituti risponde", async ({ page }) => {
        const risposta = await page.request.get("/api/institutes");
        expect(risposta.ok(), "la rotta pubblica risponde").toBe(true);
        const corpo = await risposta.json();
        expect(corpo.ok, "con esito positivo").toBe(true);
        expect(Array.isArray(corpo.institutes), "e un elenco di istituti").toBe(true);
    });

    test("una credenziale creata dal docente apre le risorse riservate", async ({ teacherApi, page, naming, cleanup }) => {
        const utenza = naming.unique("accesso").toLowerCase().replace(/-/g, "_");
        const password = "Pa55!accesso_e2e";
        // ADR-044 — l'etichetta la compone il server; il docente sceglie solo
        // un'aggiunta (qui unica, per non scontrarsi con altre sue credenziali).
        const aggiunta = `A${Date.now().toString(36).slice(-7)}`.toUpperCase();

        const creata = await teacherApi.http.postForm("/api/teacher/credentials", {
            aggiunta,
            username: utenza,
            password,
        });
        expect(creata["ok"], "la credenziale viene creata").toBe(true);
        const identificativo = Number(creata["id"] ?? /** @type {any} */ (creata).credential?.id ?? 0);
        const etichetta = String(creata["label"] ?? "");
        expect(etichetta, "con l'etichetta composta dal server").toMatch(new RegExp(`_${aggiunta}$`));

        cleanup.add("teacher", `cancella la credenziale «${etichetta}»`, async () => {
            const elenco = await teacherApi.http.getJson("/api/teacher/credentials");
            const righe = /** @type {{ id: number, access_username: string }[]} */ (elenco["credentials"] ?? []);
            const mia = righe.find((c) => c.access_username === utenza);
            const id = identificativo || mia?.id;
            if (id) await teacherApi.http.send("POST", `/api/teacher/credentials/${id}/delete`, { form: {} });
        });

        const elenco = await teacherApi.http.getJson("/api/teacher/credentials");
        const righe = /** @type {{ access_username: string }[]} */ (elenco["credentials"]);
        expect(righe.some((c) => c.access_username === utenza), "compare nell'elenco del docente").toBe(true);

        // Da qui in poi si è chi non ha un account: la pagina anonima.
        await page.goto("/?home=1");
        const accesso = await page.request.post("/api/access/student-login", {
            form: {
                username: utenza,
                password,
                _csrf: (await (await page.request.get("/auth/csrf")).json()).token,
            },
        });
        expect(accesso.status(), "l'accesso riesce").toBe(200);
        const esito = await accesso.json();
        expect(esito.ok, "con esito positivo").toBe(true);
        expect(esito.grant?.label, "e il permesso porta l'etichetta composta dal server").toBe(etichetta);

        const stato = await (await page.request.get("/api/access/status")).json();
        expect(stato.grant, "il permesso resta nella sessione").toBeTruthy();
        expect(stato.grant.label, "con la stessa etichetta").toBe(etichetta);
    });

    test("credenziali sbagliate non aprono niente", async ({ page }) => {
        await page.goto("/?home=1");
        const tentativo = await page.request.post("/api/access/student-login", {
            form: {
                username: "utenza_che_non_esiste",
                password: "sbagliata",
                _csrf: (await (await page.request.get("/auth/csrf")).json()).token,
            },
        });
        expect(tentativo.status(), "l'applicazione rifiuta").toBe(401);
        expect((await tentativo.json()).error, "e dice perché").toBe("invalid_credentials");
    });

    test("il riquadro delle credenziali non compare a chi ha già una sessione", async ({ page, adminPage }) => {
        // Senza sessione la barra laterale è quella minima: il riquadro non
        // viene reso affatto (fase 25.R.2.1).
        await page.goto("/?home=1");
        await expect(page.locator("#fm-resource-auth"), "a chi non è entrato non serve, e non c'è").toHaveCount(0);

        await adminPage.goto("/?home=1");
        await adminPage.waitForFunction(() => !!window.FM?.["initResourceAuth"], null, { timeout: 30_000 });
        const riquadro = adminPage.locator("#fm-resource-auth");
        if (await riquadro.count()) {
            await expect(riquadro, "a chi è già entrato resta nascosto").toBeHidden();
        }
    });

    test("ogni pannello della barra porta il proprio comando di modifica", async ({ homeDocente, teacherPage }) => {
        await homeDocente.vaiA();
        await teacherPage.waitForFunction(() => !!window.FM?.["bindSidebarEditButtons"], null, { timeout: 30_000 });

        for (const pannello of ["fm-sp-mappe", "fm-sp-lab", "fm-sp-eser", "fm-sp-verif", "fm-sp-bes", "fm-sp-risdoc"]) {
            await expect(
                teacherPage.locator(`#${pannello} .js-edit-section`),
                `il pannello ${pannello} ha il proprio comando di modifica`,
            ).toHaveCount(1);
        }
    });
});
