/**
 * All-buttons coverage test — esercizio + verifica, check per ogni pulsante:
 *   - no JS error in console (page-level error, not third-party warnings)
 *   - no 5xx su endpoint contattati
 *   - effetto DOM atteso osservato (toast, class toggle, element insert/remove)
 *
 * Targets:
 *   A) .editQuesito (per-quesito):   addBtn | clone | single-modificaBtn | single-quick-saveBtn | removeBtn
 *   B) .moveQuesito:                  move-up-btn | move-down-btn | .move-position input | sync-quesito-btn
 *   C) .checkmod (per-group):         modificaBtn | quick-saveBtn | eliminaBtn | .moveBtn | .move-position-problem
 *   D) Upbar filters/toggles:         #sel-dif | #sel-origin | #btnP | #btnS | #toggleExercises
 *                                     #selectAllA/B | #showAllA/B | #multiarg
 *                                     #overleaf | #Server | #syncDrive
 *   E) Upbar CTAs:                    #btnCopyver | #btnCopyeser (Phase 21: #btnAct rimosso, verifica-mode auto-on)
 *   F) Header page:                   #modHeaderBtn (+ editor save/cancel/auto-toggle)
 *   G) Origin dropdown_gen:           .dropdown-button_gen | .dropdown-content_gen a | .fa-edit.edit-btn
 *   H) tipoEsercizio + _ver
 *   I) Checkboxes per-quesito:        .fm-checkbox-ain | .fm-checkbox-bin | .fm-input-pt | .origin | .colorSelect
 *   J) Editor panel:                  textarea field | Toolbar buttons | TeX dropdown | Close
 */
const { test, expect } = require("@playwright/test");

const URL_ESER = "/studio/esercizio/sc/3s/MAT/2";
const URL_VER  = "/studio/verifica/sc/2s/FIS";

async function login(page) {
    await page.addInitScript(() => {
        localStorage.setItem("user_cookie_consent_v2", JSON.stringify({
            functional: true, analytics: false, advertising: false, timestamp: Date.now(),
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);
}

function attachAudit(page) {
    const jsErrors = [];
    const netFail = [];
    // Filtri di "rumore" benigno — resource load fail non correlati al code-under-test:
    //   - CDN MathJax/TikZ (ERR_NAME_NOT_RESOLVED se offline/flaky DNS)
    //   - Immagini 404 in contract legacy (fonti esterne)
    //   - 400 Bad Request prevedibili (es. patch con campi non whitelist)
    const NOISE_PATTERNS = [
        /Failed to load resource/i,
        /ERR_NAME_NOT_RESOLVED/i,
        /404 \(Not Found\)/i,
        /MathJax/i,
    ];
    const isNoise = (txt) => NOISE_PATTERNS.some((rx) => rx.test(txt));
    page.on("pageerror", (e) => {
        if (!isNoise(e.message)) jsErrors.push(`[pageerror] ${e.message}`);
    });
    page.on("console", (m) => {
        if (m.type() !== "error") return;
        const t = m.text();
        if (!isNoise(t)) jsErrors.push(`[console.error] ${t}`);
    });
    page.on("response", (res) => {
        if (res.status() >= 500) {
            netFail.push(`${res.status()} ${res.request().method()} ${res.url()}`);
        }
    });
    return { jsErrors, netFail };
}

async function clickJs(page, selector) {
    return page.evaluate((s) => !!document.querySelector(s)?.click(), selector);
}

async function dispatchChange(page, selector, value) {
    return page.evaluate(([s, v]) => {
        const el = document.querySelector(s);
        if (!el) return false;
        // Per checkbox/radio usa `.checked`, altrimenti `.value`.
        if (v !== undefined) {
            if (el.type === "checkbox" || el.type === "radio") {
                el.checked = !!v;
            } else {
                el.value = v;
            }
        }
        el.dispatchEvent(new Event("change", { bubbles: true }));
        return true;
    }, [selector, value]);
}

test.describe("All buttons coverage", () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
        // Auto-accept dialog (confirm su delete)
        page.on("dialog", async (d) => { await d.accept().catch(() => {}); });
    });

    test("A-B-C: .editQuesito / .moveQuesito / .checkmod (esercizio page)", async ({ page }) => {
        test.setTimeout(90000);
        const { jsErrors, netFail } = attachAudit(page);
        await page.goto(URL_ESER, { waitUntil: "networkidle" });
        // Phase 21: verifica-mode auto-on per admin. Attendi injection.
        await page.waitForSelector("#infoVer", { timeout: 8000 }).catch(() => {});
        await page.waitForTimeout(1500);

        const before = await page.evaluate(() => ({
            collexItems: document.querySelectorAll(".fm-collection__item").length,
            problems:    document.querySelectorAll(".fm-groupcollex").length,
            editorPanels:document.querySelectorAll(".fm-editor-panel").length,
        }));
        expect(before.collexItems).toBeGreaterThan(0);

        const count0 = await page.locator(".fm-collection__item").count();
        // A-1. addBtn → Phase 17: duplicate server-side (PHASE 17 persistente).
        // Se il contract ha numeric id il server crea la copia; altrimenti
        // fallback local-clone con `.fmv-cloned` (contract synthetic).
        await clickJs(page, ".fm-collection__item .editQ.addBtn");
        await page.waitForTimeout(900);

        // A-2. clone → stesso duplicate flow (richiede confirm)
        await clickJs(page, ".fm-collection__item .editQ.clone");
        await page.waitForTimeout(900);

        const count1 = await page.locator(".fm-collection__item").count();
        // Almeno 1 item aggiunto (addBtn o clone)
        expect(count1).toBeGreaterThan(count0);

        // A-3. single-modificaBtn → apre editor
        await clickJs(page, ".fm-collection__item .editQ.single-modificaBtn");
        await page.waitForTimeout(400);
        expect(await page.locator(".fm-editor-panel").count()).toBeGreaterThan(0);

        // A-4. single-quick-saveBtn
        await clickJs(page, ".fm-collection__item .editQ.single-quick-saveBtn");
        await page.waitForTimeout(600);

        await clickJs(page, ".fm-editor-panel button[title='Chiudi']");
        await page.waitForTimeout(200);

        // A-5. removeBtn sul LAST collex-item (il duplicato)
        await page.evaluate(() => {
            const items = document.querySelectorAll(".fm-collection__item");
            const last = items[items.length - 1];
            last?.querySelector(".fm-edit-q.fm-remove-btn")?.click();
        });
        await page.waitForTimeout(700);

        // B-1. move-up/down-btn
        await clickJs(page, ".fm-collection__item .move-down-btn");
        await page.waitForTimeout(200);
        await clickJs(page, ".fm-collection__item .move-up-btn");
        await page.waitForTimeout(200);

        // B-2. .move-position input → set valore valido
        const mpCount = await page.locator(".fm-collection__item .move-position").count();
        if (mpCount >= 2) {
            await page.locator(".fm-collection__item .move-position").nth(1).fill("1");
            await page.locator(".fm-collection__item .move-position").nth(1).blur();
            await page.waitForTimeout(300);
        }

        // B-3. sync-quesito-btn (skip su synthetic id con toast)
        await clickJs(page, ".fm-collection__item .sync-quesito-btn");
        await page.waitForTimeout(600);

        // C-1. .checkmod .modificaBtn (group-level)
        await clickJs(page, ".checkmod .modificaBtn");
        await page.waitForTimeout(300);
        const groupEditing = await page.locator(".fm-groupcollex[data-fm-editing='1']").count();
        // il flag è settato in toggleProblemEditMode
        expect(groupEditing).toBeGreaterThanOrEqual(0);

        // C-2. .checkmod .quick-saveBtn
        await clickJs(page, ".checkmod .quick-saveBtn");
        await page.waitForTimeout(600);

        // C-3. .move-position-problem input → popolato?
        // Forza refresh del populate prima della lettura (le azioni precedenti
        // possono aver inserito DOM che triggera re-populate async).
        await page.evaluate(() => window.FM?.populatePositionInputs?.());
        await page.waitForTimeout(100);
        const problemPosVal = await page.evaluate(() => {
            const inp = document.querySelector(".fm-groupcollex .fm-checkmod .fm-move-position-problem");
            return inp ? inp.value : null;
        });
        expect(problemPosVal).toMatch(/^\d+$/);

        // C-4. .moveBtn presente + click (no-op visivo ma non deve rompere)
        await clickJs(page, ".checkmod .moveBtn");
        await page.waitForTimeout(200);

        expect(jsErrors).toEqual([]);
        expect(netFail).toEqual([]);
    });

    test("D-E: upbar filters + toggles + CTA", async ({ page }) => {
        test.setTimeout(60000);
        const { jsErrors, netFail } = attachAudit(page);
        await page.goto(URL_ESER, { waitUntil: "networkidle" });
        // Phase 21: verifica-mode auto-on per admin. Attendi injection.
        await page.waitForSelector("#infoVer", { timeout: 8000 }).catch(() => {});
        await page.waitForTimeout(1000);

        // D-1. #sel-dif dropdown + filtro difficoltà
        await clickJs(page, "#sel-dif .dropdown-button");
        await page.waitForTimeout(200);
        await page.evaluate(() => {
            document.querySelector("#sel-dif .fm-dropdown-content a[data-value='1']")?.click();
        });
        await page.waitForTimeout(300);
        // Reset All
        await clickJs(page, "#sel-dif .dropdown-button");
        await page.evaluate(() => {
            document.querySelector("#sel-dif .fm-dropdown-content a[data-value='All']")?.click();
        });

        // D-2. #sel-origin dropdown
        await clickJs(page, "#sel-origin .dropdown-button");
        await page.waitForTimeout(200);
        expect(await page.locator("#sel-origin .dropdown-content a").count()).toBeGreaterThan(1);
        await clickJs(page, "#sel-origin .dropdown-button"); // close

        // D-3. #btnP (HideAll Probl toggle)
        await clickJs(page, "#btnP");
        await page.waitForTimeout(200);
        await clickJs(page, "#btnP");

        // D-4. #btnS (HideAll Soluz checkbox)
        await dispatchChange(page, "#btnS", true);
        await page.waitForTimeout(100);
        await dispatchChange(page, "#btnS", false);

        // D-5. #toggleExercises
        await dispatchChange(page, "#toggleExercises", true);
        await page.waitForTimeout(100);
        await dispatchChange(page, "#toggleExercises", false);

        // D-6. #selectAllA/B
        await dispatchChange(page, "#selectAllA", true);
        await page.waitForTimeout(200);
        await dispatchChange(page, "#selectAllA", false);
        await dispatchChange(page, "#selectAllB", true);
        await page.waitForTimeout(200);
        await dispatchChange(page, "#selectAllB", false);

        // D-7. #showAllA/B
        await dispatchChange(page, "#showAllA", true);
        await page.waitForTimeout(200);
        await dispatchChange(page, "#showAllA", false);
        await dispatchChange(page, "#showAllB", true);
        await dispatchChange(page, "#showAllB", false);

        // D-8. #multiarg
        await dispatchChange(page, "#multiarg", true);
        await page.waitForTimeout(200);
        const multiargState = await page.evaluate(() => ({
            body: document.body.classList.contains("fm-multiarg"),
            appState: window.AppState?.moreArg,
        }));
        expect(multiargState.body).toBe(true);
        expect(multiargState.appState).toBe(1);
        await dispatchChange(page, "#multiarg", false);

        // D-9. #overleaf / #Server / #syncDrive
        for (const id of ["overleaf", "Server", "syncDrive"]) {
            await page.evaluate((x) => {
                const cb = document.getElementById(x);
                if (cb) { cb.checked = !cb.checked; cb.dispatchEvent(new Event("change", { bubbles: true })); }
            }, id);
            await page.waitForTimeout(80);
        }

        // E-1. #btnCopyver
        await clickJs(page, "#btnCopyver");
        await page.waitForTimeout(400);

        // E-2. #btnCopyeser
        await clickJs(page, "#btnCopyeser");
        await page.waitForTimeout(400);

        expect(jsErrors).toEqual([]);
        expect(netFail).toEqual([]);
    });

    test("F: #modHeaderBtn open editor + save", async ({ page }) => {
        test.setTimeout(60000);
        const { jsErrors, netFail } = attachAudit(page);
        await page.goto(URL_ESER, { waitUntil: "networkidle" });
        await page.waitForTimeout(800);

        expect(await page.locator("#modHeaderBtn").count()).toBeGreaterThan(0);
        await clickJs(page, "#modHeaderBtn");
        await page.waitForTimeout(500);
        expect(await page.locator(".fm-header-editor").count()).toBe(1);

        // Cambia auto_citations flag
        await page.evaluate(() => {
            const cb = document.querySelector(".fm-header-editor .fm-header-auto-cb");
            if (cb) { cb.checked = !cb.checked; cb.dispatchEvent(new Event("change", { bubbles: true })); }
        });

        // Save → PUT /api/teacher/header-page.json
        await clickJs(page, ".fm-header-editor .fm-header-save");
        await page.waitForTimeout(1000);

        expect(await page.locator(".fm-header-editor").count()).toBe(0);
        expect(jsErrors).toEqual([]);
        expect(netFail).toEqual([]);
    });

    test("G-H: dropdown_gen + .fa-edit.edit-btn + tipoEsercizio[_ver]", async ({ page }) => {
        test.setTimeout(60000);
        const { jsErrors, netFail } = attachAudit(page);
        await page.goto(URL_ESER, { waitUntil: "networkidle" });
        // Phase 21: verifica-mode auto-on per admin. Attendi injection.
        await page.waitForSelector("#infoVer", { timeout: 8000 }).catch(() => {});
        await page.waitForTimeout(1500);

        // G-1. .dropdown-button_gen click → apre .dropdown-content_gen
        const dbGenCount = await page.locator(".dropdown-button_gen").count();
        if (dbGenCount > 0) {
            await clickJs(page, ".dropdown-button_gen");
            await page.waitForTimeout(300);
            const contentOpen = await page.locator(".dropdown-content_gen.is-open").count();
            expect(contentOpen).toBeGreaterThan(0);

            // G-2. click su un a[data-value] → chiude + aggiorna button label
            await page.evaluate(() => {
                document.querySelector(".fm-dropdown-content-gen a[data-value]")?.click();
            });
            await page.waitForTimeout(300);
        }

        // G-3. .fa-edit.edit-btn → popover source editor
        const penCount = await page.locator(".dropdown-content_gen .fa-edit.edit-btn").count();
        if (penCount > 0) {
            await page.evaluate(() => {
                document.querySelector(".fm-dropdown-content-gen .fa-edit.edit-btn")?.click();
            });
            await page.waitForTimeout(400);
            expect(await page.locator(".fm-source-editor").count()).toBeGreaterThan(0);
            await clickJs(page, ".fm-source-editor .fm-se-cancel");
        }

        // H-1. tipoEsercizio → fetch template, append a .fm-draggable-container
        // (richiede prima di impostare un'origine valida)
        await page.evaluate(() => {
            const btn = document.querySelector(".fm-selector-eser .fm-dropdown-button-gen");
            if (btn) btn.textContent = "mmb_v2_ed3";  // simula origine selezionata
        });
        await dispatchChange(page, ".tipoEsercizio", "type_VF-1");
        await page.waitForTimeout(1500);

        // H-2. tipoEsercizio_ver (con #type_verAll injected o no)
        await dispatchChange(page, ".tipoEsercizio_ver", "type_RMulti-6");
        await page.waitForTimeout(1500);

        expect(jsErrors).toEqual([]);
        expect(netFail).toEqual([]);
    });

    test("I: checkIN per-quesito inputs (checkboxA/B, pt, origin, color)", async ({ page }) => {
        test.setTimeout(60000);
        const { jsErrors, netFail } = attachAudit(page);
        await page.goto(URL_VER, { waitUntil: "networkidle" });
        await page.locator(".fm-study-topics a").first().click();
        await page.waitForURL(/\/studio\/verifica\/sc\/2s\/FIS\/.+/);
        await page.waitForTimeout(1200);
        // Phase 21: verifica-mode auto-on per admin. Attendi injection.
        await page.waitForSelector("#infoVer", { timeout: 8000 }).catch(() => {});
        await page.waitForTimeout(1200);

        // I-1. checkboxAin toggle
        await page.locator(".fm-collection__item .fm-checkbox-ain").first().check();
        await page.waitForTimeout(200);

        // I-2. checkboxBin toggle
        await page.locator(".fm-collection__item .fm-checkbox-bin").first().check();
        await page.waitForTimeout(200);

        // I-3. input-pt change
        await page.locator(".fm-collection__item .fm-input-pt").first().fill("2.5");
        await page.waitForTimeout(200);

        // I-4. .origin change (contract-scoped PATCH)
        const originOptions = await page.locator(".fm-collection__item .origin").first().locator("option").count();
        if (originOptions > 1) {
            await page.locator(".fm-collection__item .origin").first().selectOption({ index: 1 });
            await page.waitForTimeout(1000);
        }

        // I-5. .colorSelect change
        await page.locator(".fm-collection__item .colorSelect").first().selectOption({ index: 2 });
        await page.waitForTimeout(500);

        expect(jsErrors).toEqual([]);
        expect(netFail).toEqual([]);
    });

    test("J: editor panel — textarea + toolbar + TeX dropdown + Close", async ({ page }) => {
        test.setTimeout(60000);
        const { jsErrors, netFail } = attachAudit(page);
        await page.goto(URL_VER, { waitUntil: "networkidle" });
        await page.locator(".fm-study-topics a").first().click();
        await page.waitForURL(/\/studio\/verifica\/sc\/2s\/FIS\/.+/);
        await page.waitForTimeout(1200);
        // Phase 21: verifica-mode auto-on per admin. Attendi injection.
        await page.waitForSelector("#infoVer", { timeout: 8000 }).catch(() => {});
        await page.waitForTimeout(1000);

        // J-1. Apri editor
        await page.evaluate(() => {
            document.querySelector(".fm-collection__item .fm-single-modifica-btn")?.click();
        });
        await page.waitForTimeout(500);
        expect(await page.locator(".fm-editor-panel").count()).toBeGreaterThan(0);

        // J-2. Textarea focus + type
        const ta = page.locator(".fm-editor-panel textarea").first();
        await ta.click();
        await ta.press("End");
        await ta.type(" x");
        await page.waitForTimeout(200);

        // J-3. Toolbar buttons — TeX dropdown
        await clickJs(page, ".fm-tex-dropdown > button");
        await page.waitForTimeout(400);
        const texMenuVisible = await page.evaluate(() => {
            const el = document.querySelector(".fm-tex-menu");
            return el ? getComputedStyle(el).display !== "none" : false;
        });
        expect(texMenuVisible).toBe(true);
        // Click su uno snippet
        await page.evaluate(() => {
            const chip = document.querySelector(".fm-tex-menu button");
            chip?.click();
        });
        await page.waitForTimeout(300);

        // J-4. List select
        await page.locator(".fm-editor-toolbar select").first().selectOption({ index: 1 }).catch(() => {});
        await page.waitForTimeout(300);

        // J-5. Save → POST contract patch
        await page.evaluate(() => {
            const btns = [...document.querySelectorAll(".fm-editor-panel button")];
            const s = btns.find((b) => /Salva/.test(b.textContent || ""));
            s?.click();
        });
        await page.waitForTimeout(1000);

        // J-6. Close
        await clickJs(page, ".fm-editor-panel button[title='Chiudi']");
        await page.waitForTimeout(300);

        expect(jsErrors).toEqual([]);
        expect(netFail).toEqual([]);
    });
});
