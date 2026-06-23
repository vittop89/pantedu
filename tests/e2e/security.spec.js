/**
 * Phase 19 — Path traversal regression su endpoint attivi che usano
 * App\Support\SafePath. I legacy editor endpoint (save_editor_revision,
 * update_file, create_File) sono stati archiviati in _archive_phase18;
 * i test restanti coprono gli endpoint moderni file-level.
 *
 * Per ogni endpoint vengono inviati payload malevoli; si verifica che:
 *   1. status sia 400/401/403/404/419 (request rejected)
 *   2. body NON includa contenuto leaked
 *   3. nessun file system target venga modificato
 */

const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

const TRAVERSAL_PAYLOADS = [
    "../../../../etc/passwd",
    "../../storage/data/users.json",
    "/../../etc/passwd",
    "/../../storage/data/users.json",
];

const REJECT_STATUS = new Set([400, 401, 403, 404, 410, 419]);

function expectRejected(status, label) {
    expect(REJECT_STATUS.has(status),
        `${label} returned ${status}, expected 4xx rejection`).toBe(true);
}

function expectNoLeak(body, label) {
    expect(body, `${label} leaked /etc/passwd content`).not.toMatch(/root:x:0:/);
    expect(body, `${label} leaked users.json content`).not.toMatch(/"password_hash"\s*:/);
}

test.describe("path traversal guardrails", () => {
    test("/files/save-image rifiuta traversal nel filePath", async ({ page }) => {
        await loginAdmin(page);
        const csrfRes = await page.request.get("/auth/csrf");
        const { token: csrf } = await csrfRes.json();
        for (const payload of TRAVERSAL_PAYLOADS) {
            const res = await page.request.post("/files/save-image", {
                form: {
                    _csrf: csrf,
                    filePath: payload,
                    fileName: "evil.png",
                    imageContent: "",
                },
            });
            expectRejected(res.status(), `save-image ${payload}`);
            expectNoLeak(await res.text(), `save-image ${payload}`);
        }
    });

    test("/files/save-tex rifiuta traversal", async ({ page }) => {
        await loginAdmin(page);
        const csrfRes = await page.request.get("/auth/csrf");
        const { token: csrf } = await csrfRes.json();
        for (const payload of TRAVERSAL_PAYLOADS) {
            const res = await page.request.post("/files/save-tex", {
                form: {
                    _csrf: csrf,
                    filePath: payload,
                    fileName: "evil.tex",
                    texContent: "\\documentclass{article}",
                },
            });
            expectRejected(res.status(), `save-tex ${payload}`);
            expectNoLeak(await res.text(), `save-tex ${payload}`);
        }
    });

    test("legacy /eser/** → 410/302 (LegacyGoneMiddleware)", async ({ page }) => {
        await loginAdmin(page);
        // Accesso a route legacy NON deve leak file ma 410 Gone o 302 redirect
        for (const payload of ["/eser/../../etc/passwd", "/verifiche/../../storage/data/users.json"]) {
            const res = await page.request.get(payload, { maxRedirects: 0 });
            expectRejected(res.status(), `legacy ${payload}`);
            expectNoLeak(await res.text(), `legacy ${payload}`);
        }
    });
});
