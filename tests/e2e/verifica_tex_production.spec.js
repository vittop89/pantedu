/**
 * G22.S3 — E2E full pipeline produzione TEX verifiche (eser → .tex → cache).
 *
 * Coverage:
 *   1. saveTexBatch produce 8 varianti con contenuti coerenti per tipo
 *      (SOL/NOR/DSA/DIS × A/B): griglia/misure/footer corretti per variant.
 *   2. Filtro versions: versions=['A'] → solo varianti A_*; ['R'] → solo B_*.
 *   3. Conflict + force=1: secondo save con stesso (title+version_label)
 *      ritorna 409, third con ?force=1 sovrascrive.
 *   4. G22.S2 cache PDF content-addressed: salva → upload PDF → ri-salva
 *      stesso TEX → compile della NUOVA row ritorna cache_hit=true senza
 *      chiamare il VPS.
 *
 * Pre-req:
 *   - XAMPP up su http://pantedu.local
 *   - Utente superadmin registrato + approvato (teacher)
 *   - DB con migration 030 applicata (tex_sha256 column + index)
 */

const { test, expect } = require("@playwright/test");
const path = require("path");
const fs = require("fs");

const TEACHER_USER = "superadmin";
const TEACHER_PASS = (process.env.E2E_TEACHER_PASS || "");

async function login(page) {
    await page.addInitScript(() => {
        localStorage.setItem("user_cookie_consent_v2", JSON.stringify({
            functional: true, analytics: false, advertising: false, timestamp: Date.now(),
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER_USER);
    await page.fill('input[name="password"]', TEACHER_PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);
}

async function fetchCsrf(page) {
    const r = await page.request.get("/auth/csrf");
    return (await r.json()).token;
}

function basePayload(overrides = {}) {
    return {
        version: "A",
        verTitle: `VERIFICA G22 ${Date.now()}`,
        selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/ar/ar2s/MAT/1",
            problemId: "problem-200", position: 1, type: "Collect",
            text: "Calcola le seguenti espressioni:",
            items: [
                { html: "Esercizio 1: \\(x^2 + 1\\)", points: 4.0, includeSolution: false },
                { html: "Esercizio 2: \\(\\sin(\\pi)\\)", points: 3.0, includeSolution: false },
            ],
        }],
        materia: "MAT",
        title: "VERIFICA G22 PIPELINE",
        dsa: true, compensa: true,
        includeGriglia: true, includeMisure: true,
        nPrint: 25, nPrintDSA: 1, nPrintDIS: 1,
        tipologia: "scritto",
        ...overrides,
    };
}

async function saveBatch(page, csrf, payload, queryString = "") {
    const url = "/api/verifica/save-tex-batch" + queryString;
    return page.request.post(url, {
        data: payload,
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
}

async function deleteAllVerifiche(page, csrf) {
    // Cleanup defensive: lista + delete uno-a-uno per evitare leak fra test.
    const list = await page.request.get("/api/verifica/list");
    if (!list.ok()) return;
    const body = await list.json();
    for (const d of (body.docs || [])) {
        await page.request.post(`/api/verifica/${d.id}/delete`, {
            headers: { "X-CSRF-Token": csrf },
        });
    }
}

test.describe("G22 verifica TeX production pipeline", () => {
    test.setTimeout(60_000);

    test.beforeEach(async ({ page }) => {
        await login(page);
        const csrf = await fetchCsrf(page);
        await deleteAllVerifiche(page, csrf);
    });

    test("8 varianti — contenuti sezioni coerenti per tipo", async ({ page }) => {
        const csrf = await fetchCsrf(page);
        const r = await saveBatch(page, csrf, basePayload({
            verTitle: "G22 contenuti coerenti",
            title:    "G22 contenuti coerenti",
            version_label: "v1",
        }));
        expect(r.status()).toBe(200);
        const body = await r.json();
        expect(body.ok).toBe(true);
        expect(body.docs).toHaveLength(8);

        const variants = body.docs.map(d => d.variant).sort();
        expect(variants).toEqual([
            "A_DIS", "A_DSA", "A_NOR", "A_SOL",
            "B_DIS", "B_DSA", "B_NOR", "B_SOL",
        ]);

        // Fetch ogni .tex e verifica sezioni per kind.
        const texByVariant = {};
        for (const d of body.docs) {
            const tr = await page.request.get(d.tex_url);
            expect(tr.ok()).toBeTruthy();
            texByVariant[d.variant] = await tr.text();
        }

        // SOL: niente griglia/misure/footer (legacy logic)
        for (const v of ["A_SOL", "B_SOL"]) {
            expect(texByVariant[v]).not.toContain("Griglia di Valutazione");
        }
        // NOR/DSA/DIS: griglia presente
        for (const v of ["A_NOR", "A_DSA", "A_DIS", "B_NOR", "B_DSA", "B_DIS"]) {
            expect(texByVariant[v]).toContain("Griglia di Valutazione");
        }
        // DIS: font OpenDyslexic
        for (const v of ["A_DIS", "B_DIS"]) {
            expect(texByVariant[v]).toContain("OpenDyslexic");
        }
        // DSA con compensa=true: footer Compensazione orale
        for (const v of ["A_DSA", "A_DIS", "B_DSA", "B_DIS"]) {
            expect(texByVariant[v]).toContain("Compensazione orale");
        }
        // NOR senza compensa=true&dsa=variant: niente footer
        expect(texByVariant.A_NOR).not.toContain("Compensazione orale");
    });

    test("filtro versions=['A'] produce solo varianti A_*", async ({ page }) => {
        const csrf = await fetchCsrf(page);
        const r = await saveBatch(page, csrf, basePayload({
            verTitle: "G22 versions A only",
            title:    "G22 versions A only",
            versions: ["A"],
            version_label: "v1",
        }));
        expect(r.status()).toBe(200);
        const body = await r.json();
        expect(body.ok).toBe(true);
        const variants = body.docs.map(d => d.variant).sort();
        expect(variants.every(v => v.startsWith("A_"))).toBe(true);
        expect(variants.length).toBe(4); // SOL+NOR+DSA+DIS
    });

    test("conflict 409 + force=1 sovrascrive", async ({ page }) => {
        const csrf = await fetchCsrf(page);
        const payload = basePayload({
            verTitle: "G22 conflict test",
            title:    "G22 conflict test",
            version_label: "v1",
        });

        const first = await saveBatch(page, csrf, payload);
        expect(first.status()).toBe(200);
        const firstBody = await first.json();
        const firstIds = firstBody.docs.map(d => d.id).sort();

        // Stessa label → 409 conflict.
        const dup = await saveBatch(page, csrf, payload);
        expect(dup.status()).toBe(409);
        const dupBody = await dup.json();
        expect(dupBody.error).toBe("verifica_version_conflict");
        expect(dupBody.conflict.existing_ids.length).toBeGreaterThan(0);

        // force=1 → sovrascrive (delete vecchie + insert nuove).
        const force = await saveBatch(page, csrf, payload, "?force=1");
        expect(force.status()).toBe(200);
        const forceBody = await force.json();
        expect(forceBody.ok).toBe(true);
        const newIds = forceBody.docs.map(d => d.id).sort();
        // ID diversi: le row vecchie sono state cancellate, ricreate con id nuovi.
        expect(newIds).not.toEqual(firstIds);
    });

    test("G22.S2 cache PDF — secondo save stesso TEX riusa PDF cached", async ({ page }) => {
        const csrf = await fetchCsrf(page);

        // 1. Primo save → 8 varianti con sha256 popolato.
        const first = await saveBatch(page, csrf, basePayload({
            verTitle: "G22.S2 cache test",
            title:    "G22.S2 cache test",
            version_label: "cache-v1",
        }));
        expect(first.status()).toBe(200);
        const firstDocs = (await first.json()).docs;
        const firstA_NOR = firstDocs.find(d => d.variant === "A_NOR");
        expect(firstA_NOR).toBeDefined();

        // 2. Upload un PDF "finto" (magic %PDF- + payload minimal).
        const fakePdf = Buffer.concat([
            Buffer.from("%PDF-1.4\n", "ascii"),
            Buffer.from("1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n", "ascii"),
            Buffer.from("2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n", "ascii"),
            Buffer.from("xref\n0 3\n0000000000 65535 f\n", "ascii"),
            Buffer.from("trailer<</Size 3/Root 1 0 R>>\nstartxref\n0\n%%EOF\n", "ascii"),
        ]);
        const upload = await page.request.post(
            `/api/verifica/${firstA_NOR.id}/pdf?filename=cache-test.pdf`,
            {
                data: fakePdf,
                headers: {
                    "Content-Type": "application/pdf",
                    "X-CSRF-Token": csrf,
                },
            }
        );
        expect(upload.status()).toBe(200);

        // 3. Secondo save con label DIVERSA (evita conflict) → stesso TEX,
        //    quindi stesso sha256. Nuove row in DB.
        const second = await saveBatch(page, csrf, basePayload({
            verTitle: "G22.S2 cache test",
            title:    "G22.S2 cache test",
            version_label: "cache-v2",
        }));
        expect(second.status()).toBe(200);
        const secondDocs = (await second.json()).docs;
        const secondA_NOR = secondDocs.find(d => d.variant === "A_NOR");
        expect(secondA_NOR.id).not.toBe(firstA_NOR.id);

        // 4. Compile della nuova row → deve hit cache (stesso teacher,
        //    stesso sha256, PDF gia' allegato alla row v1).
        const compile = await page.request.post(
            `/api/verifica/${secondA_NOR.id}/compile`,
            {
                data: {},
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": csrf,
                },
            }
        );
        expect(compile.status()).toBe(200);
        const compileBody = await compile.json();
        expect(compileBody.ok).toBe(true);
        expect(compileBody.compile?.cache_hit).toBe(true);
        expect(compileBody.compile?.engine).toBe("cache");
        expect(compileBody.compile?.duration_ms).toBe(0);
    });

    test("G22.S2 cache miss — TEX diverso non hit", async ({ page }) => {
        const csrf = await fetchCsrf(page);

        // Primo save: payload A.
        const first = await saveBatch(page, csrf, basePayload({
            verTitle: "G22.S2 miss A",
            title:    "G22.S2 miss A",
            version_label: "v1",
        }));
        expect(first.status()).toBe(200);
        const firstA_NOR = (await first.json()).docs.find(d => d.variant === "A_NOR");

        // Upload PDF al primo doc.
        const fakePdf = Buffer.from("%PDF-1.4\nminimal\n%%EOF\n", "ascii");
        await page.request.post(
            `/api/verifica/${firstA_NOR.id}/pdf?filename=miss-test.pdf`,
            {
                data: fakePdf,
                headers: { "Content-Type": "application/pdf", "X-CSRF-Token": csrf },
            }
        );

        // Secondo save: payload B (testo problema diverso → sha256 diverso).
        const second = await saveBatch(page, csrf, basePayload({
            verTitle: "G22.S2 miss B",
            title:    "G22.S2 miss B",
            version_label: "v1",
            problems: [{
                filePath: "/eser/ar/ar2s/MAT/1",
                problemId: "problem-201", position: 1, type: "Collect",
                text: "DIVERSO: risolvi:",
                items: [
                    { html: "Item alternativo", points: 5.0, includeSolution: false },
                ],
            }],
        }));
        expect(second.status()).toBe(200);
        const secondA_NOR = (await second.json()).docs.find(d => d.variant === "A_NOR");

        // Compile → no cache hit. Esito dipende da config VPS:
        //   - VPS configurato → ok=true cache_hit=false (real compile)
        //   - VPS disabilitato → 503 tex_compile_disabled
        // In entrambi i casi NON deve essere cache_hit=true.
        const compile = await page.request.post(
            `/api/verifica/${secondA_NOR.id}/compile`,
            {
                data: {},
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            }
        );
        const compileBody = await compile.json();
        if (compile.status() === 200 && compileBody.ok) {
            expect(compileBody.compile?.cache_hit).toBe(false);
        } else {
            // VPS down/disabled — cache miss conferma comunque il branching.
            expect([503, 502, 422]).toContain(compile.status());
        }
    });
});
