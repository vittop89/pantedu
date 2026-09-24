// @ts-check
/**
 * Sincronizzazione dei contenuti: le destinazioni e il registro di quel che è
 * andato storto.
 * Riscrittura di g22_s15bis_fase5_dashboard_sync.spec.js,
 * g22_s15bis_fase5_github_sync.spec.js, g22_s15bis_fase5_vps_smoke.spec.js e
 * g22_s15bis_fase5_bundle_modelli.spec.js.
 *
 * Il docente può portare i propri file fuori dall'applicazione in tre modi:
 * su Drive, dove l'installazione lo accende (ADR-038), in una cartella del suo
 * computer, su GitHub. Ogni sincronizzazione
 * lascia una riga in un registro tenuto nel browser, che di solito mostra i
 * soli errori: è lì che si va a vedere quando qualcosa non è arrivato.
 *
 * Il pacchetto per la cartella locale deve contenere anche i modelli TeX, non
 * i soli contenuti: senza, chi compila in locale non ha i fogli di stile.
 *
 * Cosa cambia rispetto a prima: niente login nelle spec, niente stampe, delle
 * dodici attese a tempo non resta nulla, e le quattro spec — di cui una,
 * `vps_smoke`, verificava la stessa cosa di un'altra — sono una.
 */
const { test, expect } = require("../support/test");

/** Righe di prova del registro, con un esito buono e uno cattivo per destinazione. */
const RIGHE = [
    { ts: "2026-05-08T08:00:00.000Z", target: "drive", kind: "ok", message: "Fine drive. 5 OK · 0 errori" },
    { ts: "2026-05-08T08:01:00.000Z", target: "drive", kind: "error", message: "verifica #11: rate_limited" },
    { ts: "2026-05-08T08:02:00.000Z", target: "local", kind: "ok", message: "Fine local. 8 OK · 0 errori" },
    { ts: "2026-05-08T08:03:00.000Z", target: "local", kind: "error", message: "intestazione.tex: write denied" },
    { ts: "2026-05-08T08:04:00.000Z", target: "github", kind: "ok", message: "pantedu-backup: 3 pushate, 0 errori" },
    { ts: "2026-05-08T08:05:00.000Z", target: "github", kind: "error", message: "verifica #99: github_pat_unauthorized" },
];

/** Scrive le righe nel registro del browser e avvisa la pagina. */
async function scriviRegistro(/** @type {import("@playwright/test").Page} */ page, /** @type {ReadonlyArray<Record<string, string>>} */ righe) {
    await page.evaluate((valori) => {
        localStorage.setItem("fm:syncLog", JSON.stringify(valori));
        window.dispatchEvent(new CustomEvent("fm:sync-log-updated"));
    }, righe);
}

test.describe("Area docente — sincronizzazione", () => {
    // ADR-038 (14/9/2026): Drive si offre solo dove l'installazione lo accende.
    // La suite vede lo stato del server su cui gira (in CI è spento): gli altri
    // stati, nei due versi, li provano drive-stato.test.js, barra-drive.test.js
    // e DriveStatiTest.
    test("il cruscotto mostra le destinazioni che l'installazione offre, e il registro", async ({ teacherPage, teacherApi }) => {
        const drive = await teacherApi.http.getJson("/teacher/drive/status.json");
        expect(["acceso", "spento", "guasto"], "il server dichiara lo stato di Drive").toContain(drive["istanza"]);

        await teacherPage.goto("/teacher/dashboard");

        await expect(teacherPage.locator("#fm-local-section"), "sezione della cartella locale").toBeVisible({ timeout: 30_000 });
        const sezioneDrive = teacherPage.locator("#fm-drive-section");
        if (drive["istanza"] === "spento" && !drive["connected"]) {
            await expect(sezioneDrive, "con Drive spento e nessun collegamento, Drive non si offre").toHaveCount(0);
        } else {
            await expect(sezioneDrive, "sezione Drive").toBeVisible();
            await expect(sezioneDrive.locator(".fm-drive-label"), "che dice il suo stato").not.toHaveText("Verifico stato…");
        }
        await expect(teacherPage.locator("#fm-github-section"), "sezione GitHub").toBeVisible();
        await expect(teacherPage.locator("#fm-sync-log-section"), "registro delle sincronizzazioni").toBeVisible();

        await expect(teacherPage.locator("#fm-local-folder-pick"), "comando per scegliere la cartella").toBeVisible();
        await expect(teacherPage.locator("#fm-github-configure"), "comando per configurare GitHub").toBeEnabled();
        await expect(teacherPage.locator("#fm-github-section"), "che spiega come si configura")
            .toContainText("Personal Access Token");
        await expect(
            teacherPage.locator("#fm-github-status .fm-drive-label"),
            "e dichiara lo stato del collegamento",
        ).not.toBeEmpty();
    });

    test("il registro mostra le tre destinazioni, e il filtro sceglie che cosa vedere", async ({ teacherPage }) => {
        await teacherPage.goto("/teacher/dashboard");
        await expect(teacherPage.locator("#fm-sync-log-section")).toBeVisible({ timeout: 30_000 });
        await scriviRegistro(teacherPage, RIGHE);

        const registro = teacherPage.locator("#fm-sync-log-list");

        // Di suo il registro mostra i soli errori: uno per destinazione.
        await expect(registro, "l'errore su Drive").toContainText("rate_limited");
        await expect(registro, "quello sulla cartella locale").toContainText("write denied");
        await expect(registro, "quello su GitHub").toContainText("github_pat_unauthorized");
        await expect(registro, "e non gli esiti buoni").not.toContainText("Fine drive. 5 OK");

        await teacherPage.locator("#fm-sync-log-filter").selectOption("all");
        await expect(registro, "con «tutti» compaiono anche gli esiti buoni").toContainText("Fine drive. 5 OK");
        await expect(registro, "di ogni destinazione").toContainText("Fine local. 8 OK");
        await expect(registro, "compresa GitHub").toContainText("pantedu-backup");
        for (const destinazione of ["drive", "local", "github"]) {
            await expect(registro, `ogni riga dice a quale destinazione appartiene: ${destinazione}`)
                .toContainText(`[${destinazione}]`);
        }
    });

    test("il registro si può svuotare, e chiede conferma", async ({ teacherPage }) => {
        await teacherPage.goto("/teacher/dashboard");
        await expect(teacherPage.locator("#fm-sync-log-section")).toBeVisible({ timeout: 30_000 });
        await scriviRegistro(teacherPage, RIGHE);
        await expect(teacherPage.locator("#fm-sync-log-list")).toContainText("rate_limited");

        await teacherPage.locator("#fm-sync-log-clear").click();
        // La conferma è una finestra dell'applicazione, non quella del browser.
        await teacherPage.getByRole("dialog").getByRole("button", { name: /^\s*OK\s*$/ }).click();
        await expect(teacherPage.locator("#fm-sync-log-list"), "col filtro degli errori il registro è pulito")
            .toContainText("Nessun errore");
        await teacherPage.locator("#fm-sync-log-filter").selectOption("all");
        await expect(teacherPage.locator("#fm-sync-log-list"), "e non c'è più niente nemmeno guardando tutto")
            .toContainText("Nessuna sincronizzazione registrata");
    });

    test("la barra della sessione porta i comandi delle destinazioni offerte, più quello che le fa tutte", async ({
        contentFactory,
        studioEsercizio,
        teacherPage,
        teacherApi,
    }) => {
        const drive = await teacherApi.http.getJson("/teacher/drive/status.json");
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);

        await expect(teacherPage.locator(".fm-session-local-sync"), "verso la cartella locale").toBeVisible({ timeout: 30_000 });
        await expect(
            teacherPage.locator(".fm-session-banner--teacher"),
            "la barra sa lo stato di Drive che dice il server",
        ).toHaveAttribute("data-fm-drive", String(drive["istanza"]));
        const comandoDrive = teacherPage.locator(".fm-session-drive-sync");
        if (drive["istanza"] === "acceso") {
            await expect(comandoDrive, "verso Drive").toBeVisible();
        } else {
            await expect(comandoDrive, "con Drive non acceso il comando di Drive non c'è").toHaveCount(0);
        }
        const github = teacherPage.locator(".fm-session-github-sync");
        await expect(github, "verso GitHub").toBeVisible();
        await expect(github, "e il comando di GitHub è attivo").toBeEnabled();
        await expect(teacherPage.locator(".fm-session-sync-all"), "e tutte insieme").toBeVisible();
    });

    test("il collegamento con GitHub dichiara il proprio stato", async ({ teacherApi }) => {
        const stato = await teacherApi.bundle.githubStatus();
        expect(stato.ok, "la rotta risponde").toBe(true);
        expect(typeof stato.configured, "e dice se il collegamento è configurato").toBe("boolean");
    });

    test("il pacchetto per la cartella locale contiene anche i modelli TeX", async ({ teacherApi }) => {
        const percorsi = await teacherApi.bundle.localBundlePaths();
        const modelliComuni = percorsi.filter((p) => /\/modelli\/texCommon\//.test(p));
        const modelliRisorse = percorsi.filter((p) => /\/modelli\/risdoc\//.test(p));

        expect(modelliComuni.length, "i file comuni dei modelli ci sono").toBeGreaterThan(0);
        expect(modelliRisorse.length, "e quelli delle risorse docente").toBeGreaterThan(0);
        // Ogni percorso comincia con l'Istituto: è la cartella che il docente
        // si ritrova sul computer.
        for (const percorso of [...modelliComuni, ...modelliRisorse]) {
            expect(percorso, "il percorso comincia dall'Istituto").toMatch(/^[^/]+\/modelli\/(texCommon|risdoc)\//);
        }
    });
});
