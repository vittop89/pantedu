// @ts-check
/**
 * I filtri della ricerca contenuti restano impostati per tutta la sessione.
 * Riscrittura di g22_s25_dashboard_search_persistence.spec.js, fetta 3.
 *
 * Il pannello del docente ricorda tipo, materia, testo cercato e la spunta
 * «archiviati» nella memoria di sessione del browser, così un ricaricamento
 * non fa ripartire la ricerca da zero.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente rimozione a
 * mano di un pannello dei cookie che non compare più, e al posto dell'attesa
 * fissa di mezzo secondo dopo il ricaricamento ci sono le asserzioni sui campi,
 * che aspettano da sole. La spec vive fra quelle dell'area docente: è dove sta
 * la funzione, anche se il nome storico la collocava fra quelle di condivisione.
 */
const { test, expect } = require("../support/test");

const CHIAVE_MEMORIA = "fm-dash-ex-search-filters";

test.describe("Area docente — ricerca dei contenuti", () => {
    test("i filtri impostati sopravvivono al ricaricamento della pagina", async ({ teacherPage }) => {
        await teacherPage.goto("/teacher/dashboard#panoramica");

        const modulo = teacherPage.locator("#fm-ex-form");
        await expect(modulo).toBeVisible();
        const materia = modulo.locator('select[name="subject"]');
        // Le materie arrivano dal curriculum: si aspetta che la tendina sia popolata.
        await expect.poll(async () => materia.locator("option").count()).toBeGreaterThan(1);

        await modulo.locator('select[name="type"]').selectOption("esercizio");
        await materia.selectOption("MAT");
        await modulo.locator('input[name="q"]').fill("sistemi");
        await modulo.locator('input[name="archived"]').check();

        const salvato = await teacherPage.evaluate((chiave) => sessionStorage.getItem(chiave), CHIAVE_MEMORIA);
        expect(salvato, "i filtri sono stati memorizzati").toBeTruthy();
        const stato = JSON.parse(String(salvato));
        expect(stato.type).toBe("esercizio");
        expect(stato.subject).toBe("MAT");
        expect(stato.q).toBe("sistemi");
        expect(stato.archived).toBe("1");

        await teacherPage.reload();
        await expect(modulo).toBeVisible();
        await expect.poll(async () => materia.locator("option").count()).toBeGreaterThan(1);

        await expect(modulo.locator('select[name="type"]')).toHaveValue("esercizio");
        await expect(materia).toHaveValue("MAT");
        await expect(modulo.locator('input[name="q"]')).toHaveValue("sistemi");
        await expect(modulo.locator('input[name="archived"]')).toBeChecked();
    });

    // 14/9/2026 — la ricerca guardava solo la scuola attiva senza mostrarla, e
    // offriva le classi di tutte le scuole: una classe dell'altra scuola dava
    // zero risultati. Il docente di prova lavora in due scuole con classi diverse.
    test("chi ha più scuole sceglie dove cercare, e ogni scuola porta le sue classi", async ({ teacherPage, teacherApi, teacher2Page }) => {
        const elenco = await teacherApi.http.getJson("/api/teacher/institutes");
        const scuole = /** @type {{ id: number }[]} */ (elenco["institutes"] ?? []);
        expect(scuole.length, "il docente di prova lavora in almeno due scuole").toBeGreaterThanOrEqual(2);
        const [prima, seconda] = scuole;
        if (!prima || !seconda) throw new Error("mancano le due scuole");

        await teacherPage.goto("/teacher/dashboard#panoramica");
        const modulo = teacherPage.locator("#fm-ex-form");
        const scuola = modulo.locator('select[name="institute_id"]');
        await expect(scuola, "la scelta della scuola compare").toBeVisible();
        await expect(scuola.locator("option"), "le sue scuole, più «Tutte le mie scuole»").toHaveCount(scuole.length + 1);

        const classe = modulo.locator('select[name="classe"]');
        /**
         * Le classi che il menu offre per quella scuola.
         *
         * 21/9/2026 — prima si aspettava la **risposta di rete** e si leggeva
         * subito il menu: ma la pagina riempie le voci dopo, quando la sua
         * `fetch` si risolve. Nella suite intera la lettura arrivava prima del
         * riempimento e tornava le voci della scuola di prima, quindi le due
         * liste risultavano uguali e la prova falliva; lanciata da sola
         * passava. Adesso si aspetta il **menu**, e lo si confronta con quello
         * che la stessa risposta dichiara: così la prova dice anche che il
         * menu mostra esattamente le classi di quella scuola, non altre.
         *
         * @param {string} valore
         */
        const classiCon = async (valore) => {
            const voci = teacherPage.waitForResponse((r) => r.url().includes("/api/teacher/curriculum?") && r.url().includes(`institute_id=${valore}`));
            await scuola.selectOption(valore);
            const risposta = await voci;
            const corpo = /** @type {{curriculum?: {classi?: {code: string, active?: boolean}[]}}} */ (await risposta.json());
            const attese = [...new Set((corpo.curriculum?.classi ?? [])
                .filter((e) => e.active !== false)
                .map((e) => e.code))];
            await expect
                .poll(async () => (await classe.locator("option").evaluateAll((os) => os.map((o) => o.getAttribute("value")))).filter(Boolean),
                    { message: `il menu mostra le classi della scuola ${valore}` })
                .toEqual(attese);
            return attese;
        };
        const classiPrima = await classiCon(String(prima.id));
        const classiSeconda = await classiCon(String(seconda.id));
        expect(classiPrima.length + classiSeconda.length, "le scuole hanno classi").toBeGreaterThan(0);
        expect(classiSeconda, "cambiando scuola cambiano le classi offerte").not.toEqual(classiPrima);

        const cercata = teacherPage.waitForRequest((r) => r.url().includes("/api/teacher/content?") && r.url().includes(`institute_id=${seconda.id}`));
        await modulo.locator('button[type="submit"]').click();
        await cercata;
        await expect(teacherPage.locator("#fm-ex-results"), "la ricerca risponde").not.toContainText("Caricamento", { timeout: 15_000 });

        await scuola.selectOption("tutte");
        const dappertutto = teacherPage.waitForResponse((r) => r.url().includes("/api/teacher/content?") && r.url().includes("institute_id=tutte"));
        await modulo.locator('button[type="submit"]').click();
        expect((await dappertutto).status(), "«Tutte le mie scuole» risponde").toBe(200);

        // Una scuola che non è del docente: il server rifiuta, non usa la scuola attiva.
        const elenco2 = await teacher2Page.request.get("/api/teacher/institutes");
        const scuole2 = new Set(((await elenco2.json()).institutes ?? []).map((/** @type {{ id: number }} */ s) => s.id));
        const nonSua = scuole.find((s) => !scuole2.has(s.id))?.id ?? 999_999_999;
        const rifiuto = await teacher2Page.request.get(`/api/teacher/content?institute_id=${nonSua}`);
        expect(rifiuto.status(), "un'altra scuola: 403").toBe(403);
    });
});
