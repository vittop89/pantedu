/**
 * G19.49 — E2E live test: verifica che `/api/teacher/sync-local-bundle`
 * produca path nella struttura mirror Drive sync:
 *   `{istituto}/{indirizzo}/{classe}/{materia}/verifiche/{titolo}/{version_folder}/{filename}`
 *   `{istituto}/{indirizzo}/{classe}/{materia}/mappe/{filename}`
 *
 * Requires: docente superadmin logged-in con almeno 1 verifica e
 * 1 mappa salvata. Test letto-only: non modifica DB.
 */

const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

async function login(page) {
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await Promise.all([
        page.waitForURL(/.*\/(area-docente|home|index|admin).*|^[^?#]*$/),
        page.locator('button[type=submit], input[type=submit]').first().click(),
    ]);
}

test.describe("G19.49 — Local bundle path mirror Drive structure", () => {
    test("verifica + mappa path = {ist}/{ind}/{cls}/{materia}/{kind}/...", async ({ page }) => {
        await login(page);

        // Chunk 0 = primi N file (manifest aggregato)
        const resp = await page.request.get("/api/teacher/sync-local-bundle?offset=0&limit=50");
        expect(resp.ok()).toBeTruthy();
        const j = await resp.json();
        expect(j.ok).toBe(true);
        expect(j.total).toBeGreaterThan(0);

        const VER_RE = /^[^/]+\/[^/]+\/[^/]+\/[^/]+\/verifiche\/[^/]+(\/[^/]+)?\/[^/]+$/;
        const MAP_RE = /^[^/]+\/[^/]+\/[^/]+\/[^/]+\/mappe\/[^/]+$/;

        let badVer = 0, badMap = 0;
        const verSamples = [], mapSamples = [];
        for (const f of j.files) {
            if (f.type === "verifica-tex" || f.type === "verifica-pdf") {
                if (!VER_RE.test(f.path)) badVer++;
                if (verSamples.length < 3) verSamples.push(f.path);
            } else if (f.type === "mappa") {
                if (!MAP_RE.test(f.path)) badMap++;
                if (mapSamples.length < 3) mapSamples.push(f.path);
            }
        }
        console.log("Verifica path samples:", verSamples);
        console.log("Mappa path samples:", mapSamples);
        expect(badVer, "verifica path shape errato").toBe(0);
        expect(badMap, "mappa path shape errato").toBe(0);

        // Almeno il primo segmento deve essere un istituto code (non vuoto, no "/")
        if (j.files.length > 0) {
            const firstSeg = j.files[0].path.split("/")[0];
            expect(firstSeg).toMatch(/^[A-Za-z0-9_.-]+$/);
            expect(firstSeg.length).toBeGreaterThan(0);
        }
    });
});
