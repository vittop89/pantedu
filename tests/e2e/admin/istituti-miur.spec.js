// @ts-check
/**
 * Caricamento dell'elenco delle scuole del MIUR.
 * Riscrittura di admin_institutes_miur.spec.js.
 *
 * L'amministratore aggiorna l'anagrafica delle scuole caricando i file che il
 * MIUR pubblica. Il controllo che conta è quello che rifiuta un file che non
 * sia quello giusto: caricare per sbaglio un JSON qualsiasi non deve
 * sovrascrivere l'anagrafica, e infatti l'applicazione lo scarta prima di
 * scrivere niente.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente stampe di
 * console (erano cinque, più il rovescio finale del registro della pagina),
 * niente schermata salvata su disco, e le due richieste si mandano dal client
 * invece che con un `fetch` scritto dentro a `page.evaluate`.
 */
const { test, expect } = require("../support/test");

test.describe("Amministrazione — anagrafica delle scuole", () => {
    test("la pagina ha la sezione del caricamento, con i suoi due campi", async ({ adminPage }) => {
        const risposta = await adminPage.goto("/admin/institutes");
        expect(risposta?.status(), "la pagina si apre").toBeLessThan(400);

        await expect(adminPage.locator("#miur-schools"), "la sezione delle scuole MIUR").toBeVisible({ timeout: 30_000 });
        const modulo = adminPage.locator("#fm-miur-form");
        await expect(modulo, "il modulo di caricamento").toBeAttached();
        await expect(modulo, "che accetta file").toHaveAttribute("enctype", "multipart/form-data");
        await expect(
            modulo.locator('input[type="file"]'),
            "due file: le scuole statali e le paritarie",
        ).toHaveCount(2);
    });

    test("senza file, e con un file che non è quello giusto, il caricamento viene rifiutato", async ({ adminApi }) => {

        const senzaFile = await adminApi.http.send("POST", "/admin/institutes/miur/update", {
            form: {},
            headers: { "X-Requested-With": "XMLHttpRequest" },
        });
        expect(senzaFile.body?.["error"], "senza file l'applicazione lo dice").toBe("no_file");

        // Un JSON valido ma che non è l'elenco del MIUR: manca il grafo.
        const finto = JSON.stringify({ foo: "x".repeat(2000) });
        const token = await adminApi.http.csrf();
        // La richiesta grezza (multipart) non passa dal client della suite:
        // la motivazione, che la rotta pretende dal 23/9/2026 (A-69), va qui.
        const risposta = await adminApi.http.request.post("/admin/institutes/miur/update", {
            headers: {
                "X-CSRF-Token": token, "X-Requested-With": "XMLHttpRequest",
                "X-Audit-Reason": "Esecuzione della suite end-to-end automatica",
            },
            multipart: {
                _csrf: token,
                statali_file: { name: "finto.json", mimeType: "application/json", buffer: Buffer.from(finto, "utf8") },
            },
        });
        const esito = await risposta.json();
        expect(esito.error, "un file che non è l'elenco del MIUR viene scartato").toBe("not_miur_graph_json");
    });
});
