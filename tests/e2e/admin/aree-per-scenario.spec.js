// @ts-check
/**
 * Le aree del pannello di amministrazione seguono lo scenario di esercizio.
 *
 * Un'area che nello scenario attivo non ha effetto sta fuori dal menu e dalla
 * dashboard, ma la pagina resta raggiungibile con un avviso in testa, e il
 * pannello Deployment elenca le aree inattive con il motivo (piano
 * classi-credenziali-scenari, B; la matrice è in `App\Support\AdminAreas`).
 *
 * Lo scenario è uno stato di tutta l'istanza, come i contenuti d'istanza
 * delle prove `@istanza`: non lo si cambia da una prova. Il ritorno allo
 * scenario 1 è bloccato finché ci sono utenti attivi, quindi una prova che lo
 * cambiasse non potrebbe rimettere le cose a posto. Qui si legge lo scenario
 * dal distintivo che il pannello mostra su ogni pagina e si verifica che
 * menu, avvisi, riquadri ed elenco delle aree inattive dicano tutti la stessa
 * cosa per QUELLO scenario, nei due versi: le aree che devono esserci ci
 * sono, quelle che non devono esserci mancano. In integrazione continua e in
 * sviluppo l'istanza è nello scenario 1; le altre due colonne della matrice
 * sono coperte da `tests/Unit/Support/AdminAreasTest.php`.
 *
 * La matrice qui sotto è copiata apposta dalla classe: se le due divergono,
 * la prova lo dice.
 */
const { test, expect } = require("../support/test");

/** In quali scenari ogni area è attiva (App\Support\AdminAreas::AREAS). */
const ATTIVA_IN = {
    registrations: [2, 3],
    // ADR-041 — gli incarichi decidono anche le sezioni dei docenti, in ogni scenario.
    sections: [1, 2, 3],
    student_registration: [3],
    class_credentials: [1, 2],
    students_without_section: [3],
};

/** Come il pannello Deployment chiama le aree nell'elenco delle inattive. */
const ETICHETTA = {
    registrations: "Registrazioni docenti",
    sections: "Sezioni e incarichi",
    student_registration: "Registrazione studenti",
    class_credentials: "Credenziali di classe",
    students_without_section: "Studenti senza sezione",
};

/**
 * Lo scenario che il pannello dichiara, e se l'amministratore è anche super:
 * le pagine delle sezioni e del Deployment, e le voci di menu, sono sue.
 * @param {import("@playwright/test").Page} adminPage
 */
async function scenarioDelPannello(adminPage) {
    await adminPage.goto("/admin/dashboard");
    const distintivo = adminPage.locator(".fm-tb-actions [data-scenario]");
    await expect(distintivo, "il pannello dice in quale scenario è l'istanza").toHaveCount(1);
    // Visibile, non solo presente: il 13 settembre 2026 il distintivo c'era nel
    // markup e una regola CSS lo nascondeva a desktop e tablet, e una prova
    // che contava soltanto passava lo stesso.
    await expect(distintivo, "e lo mostra, a questa larghezza").toBeVisible();
    const n = Number(await distintivo.getAttribute("data-scenario"));
    expect([1, 2, 3], "uno dei tre scenari").toContain(n);
    await expect(distintivo, "e lo dice anche a parole").toContainText(`Scenario ${n}`);
    const superAdmin = (await adminPage.locator('.fm-tb-actions [data-role="super"]').count()) > 0;
    return { n, superAdmin };
}

/** @param {keyof typeof ATTIVA_IN} area @param {number} n */
const attiva = (area, n) => ATTIVA_IN[area].includes(n);

test.describe("Amministrazione — aree del pannello per scenario", () => {
    test("il menu mostra «Sezioni» solo dove le sezioni contano, e «Istituti» sempre", async ({ adminPage }) => {
        const { n, superAdmin } = await scenarioDelPannello(adminPage);
        const menu = adminPage.locator("nav.fm-admin-toolnav");
        await expect(menu, "la barra degli strumenti").toBeVisible();
        // Le due voci sono riservate al super-amministratore: per un
        // amministratore semplice mancano entrambe, in ogni scenario.
        await expect(menu.locator('a[href="/admin/sections"]'), `«Sezioni» nello scenario ${n}`)
            .toHaveCount(superAdmin && attiva("sections", n) ? 1 : 0);
        await expect(menu.locator('a[href="/admin/institutes"]'), "«Istituti» non dipende dallo scenario")
            .toHaveCount(superAdmin ? 1 : 0);
        await expect(menu.locator('a[href="/admin/dashboard"]'), "e la dashboard c'è per tutti").toHaveCount(1);
    });

    test("una pagina inerte lo dice in testa, e il pannello Deployment elenca le aree inattive", async ({ adminPage }) => {
        const { n, superAdmin } = await scenarioDelPannello(adminPage);
        expect(superAdmin, "le pagine di questa prova sono del super-amministratore").toBe(true);

        await adminPage.goto("/admin/sections");
        const avviso = adminPage.locator('[data-inert-area="sections"]');
        await expect(avviso, `l'avviso sulle sezioni nello scenario ${n}`).toHaveCount(attiva("sections", n) ? 0 : 1);
        if (!attiva("sections", n)) {
            await expect(avviso, "dice in quale scenario si è").toContainText(`Inattivo nello scenario ${n}`);
            await expect(avviso.getByRole("link", { name: /pannello Deployment/ }), "e porta al pannello").toHaveAttribute("href", "/admin/system/deployment");
        }

        await adminPage.goto("/admin/system/deployment");
        await expect(adminPage.locator(".fm-tb-actions [data-scenario]"), "il distintivo dice lo stesso scenario").toHaveAttribute("data-scenario", String(n));
        const inattive = adminPage.locator("#aree-inattive tbody tr");
        for (const area of /** @type {(keyof typeof ATTIVA_IN)[]} */ (Object.keys(ATTIVA_IN))) {
            await expect(inattive.filter({ hasText: ETICHETTA[area] }), `«${ETICHETTA[area]}» fra le inattive nello scenario ${n}`)
                .toHaveCount(attiva(area, n) ? 0 : 1);
        }
        await expect(inattive.filter({ hasText: "Istituti" }), "un'area sempre attiva non è mai nell'elenco").toHaveCount(0);
    });

    test("la dashboard e gli strumenti mostrano i riquadri dello scenario", async ({ adminPage, adminApi }) => {
        const { n } = await scenarioDelPannello(adminPage);
        const inAttesa = (await adminApi.notifications()).pending_registrations;

        const riquadri = adminPage.locator(".fm-admin-kpi .fm-tile");
        await expect(riquadri.filter({ hasText: "Credenziali di classe" }), `credenziali di classe nello scenario ${n}`)
            .toHaveCount(attiva("class_credentials", n) ? 1 : 0);
        await expect(riquadri.filter({ hasText: "Studenti senza sezione" }), `studenti senza sezione nello scenario ${n}`)
            .toHaveCount(attiva("students_without_section", n) ? 1 : 0);
        // Le registrazioni contano dove le iscrizioni sono aperte; una
        // richiesta arrivata lo stesso non si nasconde mai.
        await expect(riquadri.filter({ hasText: "Registrazioni in attesa" }), `registrazioni in attesa nello scenario ${n} (${inAttesa} in coda)`)
            .toHaveCount(attiva("registrations", n) || inAttesa > 0 ? 1 : 0);

        await adminPage.goto("/admin");
        const schede = adminPage.getByRole("tablist");
        await expect(schede.getByRole("button", { name: /Registrazioni/ }), `la scheda delle registrazioni nello scenario ${n}`)
            .toHaveCount(attiva("registrations", n) ? 1 : 0);
        await expect(schede.getByRole("button", { name: /Utenti/ }), "la scheda degli utenti c'è sempre").toHaveCount(1);
    });
});
