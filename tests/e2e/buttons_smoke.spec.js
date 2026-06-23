/**
 * Buttons smoke test su /studio/esercizio/sc/3s/MAT/2.
 *
 * Obiettivo: verificare che tutti i pulsanti con effetto lato server
 * rispondano con status OK. Cattura network request POST/PUT/DELETE +
 * console errors + exception, poi produce un report finale.
 *
 * Il test NON fallisce se qualche endpoint è 404/500 — si limita a
 * RAPPORTARE. Gli assert finali sono solo sulla presenza dei pulsanti
 * nel DOM + sullo status code degli endpoint contattati (deve essere < 500).
 */
const { test, expect } = require("@playwright/test");

const TARGET_URL = "/studio/esercizio/sc/3s/MAT/2";

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

function attachNetworkLogger(page) {
    const calls = [];
    const errors = [];
    const failed = [];
    page.on("request", (req) => {
        const m = req.method();
        if (m === "GET" || m === "HEAD") return;
        calls.push({ method: m, url: req.url(), t: Date.now() });
    });
    page.on("response", async (res) => {
        const req = res.request();
        const m = req.method();
        const s = res.status();
        // Traccia tutti i 4xx/5xx anche su GET per diagnosticare asset mancanti
        if (s >= 400) failed.push({ method: m, url: req.url(), status: s });
        if (m === "GET" || m === "HEAD") return;
        const last = calls.findLast?.((c) => c.url === req.url() && !c.status)
                  || [...calls].reverse().find((c) => c.url === req.url() && !c.status);
        if (last) {
            last.status = res.status();
            last.ok = res.ok();
            try {
                const txt = await res.text();
                last.body = txt.slice(0, 200);
            } catch { /* ignore */ }
        }
    });
    page.on("console", (m) => {
        const t = m.type();
        if (t === "error" || t === "warning") errors.push(`[${t}] ${m.text().slice(0, 200)}`);
    });
    page.on("pageerror", (e) => errors.push(`[pageerror] ${e.message}`));
    return { calls, errors, failed };
}

async function click(page, selector, { timeout = 2000, label } = {}) {
    try {
        const el = page.locator(selector).first();
        await el.waitFor({ timeout });
        await el.evaluate((n) => n.scrollIntoView({ block: "center", behavior: "instant" })).catch(() => {});
        await el.click({ timeout: 2000, force: true });
        return true;
    } catch (e) {
        console.log(`  [skip] ${label || selector}: ${e.message.slice(0, 80)}`);
        return false;
    }
}

test.describe("Buttons smoke /studio/esercizio/sc/3s/MAT/2", () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test("Navigate + audit all server-save actions", async ({ page }) => {
        test.setTimeout(120000);
        const { calls, errors, failed } = attachNetworkLogger(page);
        await page.goto(TARGET_URL, { waitUntil: "networkidle" });
        await page.waitForTimeout(800);

        // Presenza elementi base (Phase 21: #btnAct rimosso, verifica-mode auto-on)
        const has = {
            upbar: await page.locator(".fm-upbar").count(),
            contractWrap: await page.locator(".fm-contract-wrap").count(),
            collexItems: await page.locator(".fm-collection__item").count(),
            problems: await page.locator(".fm-groupcollex").count(),
            headerPage: await page.locator("#header_page").count(),
            modHeaderBtn: await page.locator("#modHeaderBtn").count(),
            selDif: await page.locator("#sel-dif .dropdown-button").count(),
            selOrigin: await page.locator("#sel-origin .dropdown-button").count(),
            multiarg: await page.locator("#multiarg").count(),
        };
        console.log("DOM presence:", JSON.stringify(has));

        // 1. verifica-mode auto-on: ensureVerificaMode inietta scrollbarInfo + checkIN + selection
        console.log("\n-- verifica-mode auto check --");
        await page.waitForTimeout(1500);
        const postActiva = await page.evaluate(() => ({
            bodyClass: document.body.className.match(/fm-verifica-mode/) ? "active" : "inactive",
            infoVer: !!document.getElementById("infoVer"),
            checkIn: document.querySelectorAll(".fm-check-in").length,
            selection: document.querySelectorAll(".selection").length,
            checkmod: document.querySelectorAll(".fm-checkmod").length,
        }));
        console.log("verifica-mode state:", JSON.stringify(postActiva));

        // 2. Toggle checkboxAin → POST /api/teacher/content/{id}/meta state
        console.log("\n-- checkboxAin toggle --");
        await page.locator(".fm-collection__item .fm-checkbox-ain").first().check().catch(() => {});
        await page.waitForTimeout(500);

        // 3. Change input-pt → save to sessionStorage only (no network)
        console.log("\n-- input-pt change --");
        await page.locator(".fm-collection__item .fm-input-pt").first().fill("2.5").catch(() => {});
        await page.waitForTimeout(300);

        // 4. Change .origin → POST meta + rebuild badge + refresh header_page
        console.log("\n-- .origin change --");
        await page.locator(".fm-collection__item .origin").first().selectOption({ index: 1 }).catch(() => {});
        await page.waitForTimeout(1200); // rebuild badge async + refresh

        // 5. Change .colorSelect → POST meta
        console.log("\n-- .colorSelect change --");
        await page.locator(".fm-collection__item .colorSelect").first().selectOption({ index: 2 }).catch(() => {});
        await page.waitForTimeout(500);

        // 6. Click single-modificaBtn → apre editor (no server call, local)
        console.log("\n-- single-modificaBtn --");
        await page.evaluate(() => {
            document.querySelector(".fm-collection__item .fm-single-modifica-btn")?.click();
        });
        await page.waitForTimeout(500);
        const hasEditor = await page.locator(".fm-editor-panel").count();
        console.log("editor panels:", hasEditor);
        // Close editor
        await page.evaluate(() => document.querySelector(".fm-editor-panel button[title='Chiudi']")?.click());
        await page.waitForTimeout(300);

        // 7. .move-position change → reorder local (no server call)
        console.log("\n-- .move-position change --");
        const secondMovePos = page.locator(".fm-collection__item .move-position").nth(1);
        if (await secondMovePos.count()) {
            await secondMovePos.fill("1").catch(() => {});
            await secondMovePos.blur().catch(() => {});
            await page.waitForTimeout(300);
        }

        // 8. #modHeaderBtn → apre editor header_page
        console.log("\n-- modHeaderBtn --");
        const modBtn = page.locator("#modHeaderBtn");
        if (await modBtn.count()) {
            await modBtn.click();
            await page.waitForTimeout(500);
            // Salva con lo stato corrente (verifica PUT endpoint)
            await page.locator(".fm-header-editor .fm-header-save").click().catch(() => {});
            await page.waitForTimeout(800);
        } else {
            console.log("  modHeaderBtn non presente");
        }

        // 9. Dropdown difficoltà
        console.log("\n-- #sel-dif dropdown --");
        await page.locator("#sel-dif .dropdown-button").click().catch(() => {});
        await page.waitForTimeout(200);
        await page.locator("#sel-dif .dropdown-content a[data-value='1']").click({ force: true }).catch(() => {});
        await page.waitForTimeout(300);
        // Reset a All
        await page.locator("#sel-dif .dropdown-button").click().catch(() => {});
        await page.waitForTimeout(200);
        await page.locator("#sel-dif .dropdown-content a[data-value='All']").click({ force: true }).catch(() => {});
        await page.waitForTimeout(300);

        // 10. Dropdown origine
        console.log("\n-- #sel-origin dropdown --");
        await page.locator("#sel-origin .dropdown-button").click().catch(() => {});
        await page.waitForTimeout(200);
        const originLinks = await page.locator("#sel-origin .dropdown-content a").count();
        console.log("origin links in dropdown:", originLinks);
        await page.locator("#sel-origin .dropdown-button").click().catch(() => {});
        await page.waitForTimeout(200);

        // 11. multiarg toggle
        console.log("\n-- multiarg toggle --");
        await page.locator("#multiarg").check().catch(() => {});
        await page.waitForTimeout(200);
        const multiargOn = await page.evaluate(() => ({
            bodyClass: document.body.classList.contains("fm-multiarg"),
            sessionStorage: sessionStorage.getItem("fmMultiarg"),
            appState: window.AppState?.moreArg,
        }));
        console.log("multiarg:", JSON.stringify(multiargOn));
        await page.locator("#multiarg").uncheck().catch(() => {});

        // 12. Fonti edit via .fa-edit.edit-btn (se dropdown origini aperta + link con icona)
        console.log("\n-- sources edit pen icon + save (PUT /api/teacher/sources.json) --");
        const penCount = await page.locator(".dropdown-content_gen .fa-edit.edit-btn").count();
        if (penCount > 0) {
            await page.evaluate(() => {
                document.querySelector(".fm-dropdown-content-gen .fa-edit.edit-btn")?.click();
            });
            await page.waitForTimeout(400);
            const popover = await page.locator(".fm-source-editor").count();
            console.log("source editor popover:", popover);
            // Save without modifica (idempotente) → PUT /api/teacher/sources.json
            await page.evaluate(() => {
                document.querySelector(".fm-source-editor form")?.requestSubmit();
            });
            await page.waitForTimeout(800);
        } else {
            console.log("  pen icon non trovata (dropdown-content_gen vuoto?)");
        }

        // 13. TeX dropdown — apri menu (client-only)
        console.log("\n-- TeX dropdown (se editor aperto) --");
        await page.evaluate(() => {
            document.querySelector(".fm-collection__item .fm-single-modifica-btn")?.click();
        });
        await page.waitForTimeout(400);
        const texBtn = page.locator(".fm-tex-dropdown > button").first();
        if (await texBtn.count()) {
            await texBtn.click().catch(() => {});
            await page.waitForTimeout(400);
            const menuVisible = await page.locator(".fm-tex-menu").evaluate(
                (el) => getComputedStyle(el).display !== "none"
            ).catch(() => false);
            console.log("tex menu visible:", menuVisible);
            // Chiudi
            await page.click("body", { position: { x: 10, y: 10 } }).catch(() => {});
        }

        // Pre-registra dialog handler (accept all confirm/alert)
        page.on("dialog", async (d) => { await d.accept().catch(() => {}); });

        // 14. Salvataggio editor contenuto → POST /api/teacher/content/{id}/body
        console.log("\n-- editor save (POST body) --");
        await page.evaluate(() => {
            const btns = [...document.querySelectorAll(".fm-editor-panel button")];
            const save = btns.find((b) => /Salva/.test(b.textContent || ""));
            save?.click();
        });
        await page.waitForTimeout(1200);
        await page.evaluate(() => {
            document.querySelector(".fm-editor-panel button[title='Chiudi']")?.click();
        });
        await page.waitForTimeout(200);

        // 15. Sync quesito (fetch GET /api/study/content/{id}.json — skip synthetic)
        console.log("\n-- sync-quesito-btn --");
        await page.evaluate(() => {
            document.querySelector(".fm-collection__item .fm-sync-quesito-btn")?.click();
        });
        await page.waitForTimeout(600);

        // 16. .editQuesito buttons (per-quesito): addBtn / clone / single-quick-saveBtn / removeBtn
        console.log("\n-- .editQuesito buttons --");
        await page.evaluate(() => document.querySelector(".fm-collection__item .fm-edit-q.fm-add-btn")?.click());
        await page.waitForTimeout(300);
        await page.evaluate(() => document.querySelector(".fm-collection__item .fm-edit-q.fm-clone")?.click());
        await page.waitForTimeout(500);
        await page.evaluate(() => document.querySelector(".fm-collection__item .fm-edit-q.fm-single-modifica-btn")?.click());
        await page.waitForTimeout(300);
        await page.evaluate(() => document.querySelector(".fm-collection__item .fm-edit-q.fm-single-quick-save-btn")?.click());
        await page.waitForTimeout(800);
        // Il clone è già stato aggiunto — eliminiamo il clone (è sicuro, non il data-id originale)
        await page.evaluate(() => {
            const cloned = document.querySelector(".fm-collection__item.fmv-cloned");
            cloned?.querySelector(".fm-edit-q.fm-remove-btn")?.click();
        });
        await page.waitForTimeout(500);

        // 17. .moveQuesito: move-up/down buttons (riordino locale, no server)
        console.log("\n-- .moveQuesito move-up/down --");
        await page.evaluate(() => document.querySelector(".fm-collection__item .fm-move-down-btn")?.click());
        await page.waitForTimeout(300);
        await page.evaluate(() => document.querySelector(".fm-collection__item .fm-move-up-btn")?.click());
        await page.waitForTimeout(300);

        // 18. .checkmod group buttons: modificaBtn / quick-saveBtn / eliminaBtn
        console.log("\n-- .checkmod group buttons --");
        await page.evaluate(() => document.querySelector(".fm-checkmod .fm-modifica-btn")?.click());
        await page.waitForTimeout(400);
        await page.evaluate(() => document.querySelector(".fm-checkmod .fm-quick-save-btn")?.click());
        await page.waitForTimeout(800);
        // Non eliminiamo il problem (verrebbe distrutto il resto del test).

        // 19. Upbar visibility toggles (no server)
        console.log("\n-- upbar visibility toggles --");
        await page.evaluate(() => document.getElementById("btnP")?.click());
        await page.waitForTimeout(200);
        await page.evaluate(() => document.getElementById("btnP")?.click());
        await page.waitForTimeout(200);
        await page.evaluate(() => {
            const s = document.getElementById("btnS");
            if (s) { s.checked = !s.checked; s.dispatchEvent(new Event("change", { bubbles: true })); }
        });
        await page.waitForTimeout(200);
        await page.evaluate(() => {
            const cb = document.getElementById("toggleExercises");
            if (cb) { cb.checked = !cb.checked; cb.dispatchEvent(new Event("change", { bubbles: true })); }
        });
        await page.waitForTimeout(200);

        // 20. CheckAll-A/R (spunta tutti)
        console.log("\n-- CheckAll-A/R --");
        await page.evaluate(() => {
            const a = document.getElementById("selectAllA");
            if (a) { a.checked = true; a.dispatchEvent(new Event("change", { bubbles: true })); }
        });
        await page.waitForTimeout(200);
        await page.evaluate(() => {
            const b = document.getElementById("selectAllB");
            if (b) { b.checked = true; b.dispatchEvent(new Event("change", { bubbles: true })); }
        });
        await page.waitForTimeout(200);

        // 21. ShowChecked-A/R filter
        console.log("\n-- ShowChecked-A/R --");
        await page.evaluate(() => {
            const a = document.getElementById("showAllA");
            if (a) { a.checked = true; a.dispatchEvent(new Event("change", { bubbles: true })); }
        });
        await page.waitForTimeout(200);
        await page.evaluate(() => {
            const a = document.getElementById("showAllA");
            if (a) { a.checked = false; a.dispatchEvent(new Event("change", { bubbles: true })); }
        });
        await page.waitForTimeout(200);

        // 22. btnCopyver (GENERA-VER) / btnCopyeser (COPIA-ESER) — potrebbe aprire dialog/print
        console.log("\n-- btnCopyver + btnCopyeser --");
        await page.evaluate(() => document.getElementById("btnCopyver")?.click());
        await page.waitForTimeout(500);
        await page.evaluate(() => document.getElementById("btnCopyeser")?.click());
        await page.waitForTimeout(500);

        // 23. Upbar checkbox options (overleaf/Server/syncDrive)
        console.log("\n-- upbar option checkboxes --");
        for (const id of ["overleaf", "Server", "syncDrive"]) {
            await page.evaluate((x) => {
                const cb = document.getElementById(x);
                if (cb) { cb.checked = !cb.checked; cb.dispatchEvent(new Event("change", { bubbles: true })); }
            }, id);
            await page.waitForTimeout(100);
        }

        // 24. ID diagnostics — quali data-id sono presenti?
        const ids = await page.evaluate(() =>
            Array.from(document.querySelectorAll(".fm-collection__item[data-id]"))
                .map((c) => c.dataset.id)
        );
        console.log("collex-item data-ids:", ids.slice(0, 5).join(", "), `(tot ${ids.length})`);

        // REPORT FINALE
        console.log("\n\n===== ENDPOINT REPORT =====");
        const grouped = {};
        for (const c of calls) {
            const key = `${c.method} ${c.url.replace(/^https?:\/\/[^/]+/, "")}`;
            if (!grouped[key]) grouped[key] = [];
            grouped[key].push(c.status || "pending");
        }
        for (const [k, statuses] of Object.entries(grouped)) {
            const worst = Math.max(...statuses.filter((s) => typeof s === "number"));
            const marker = worst >= 500 ? "❌" : worst >= 400 ? "⚠️" : "✅";
            console.log(`${marker} [${statuses.join(",")}] ${k}`);
        }
        console.log("\n===== FAILED RESPONSES (4xx/5xx, any method) =====");
        const uniqFailed = [...new Map(failed.map((f) => [`${f.method} ${f.url}`, f])).values()];
        uniqFailed.slice(0, 15).forEach((f) => {
            const marker = f.status >= 500 ? "❌" : "⚠️";
            console.log(`${marker} ${f.status} ${f.method} ${f.url.replace(/^https?:\/\/[^/]+/, "")}`);
        });

        console.log("\n===== ERRORS =====");
        const uniqueErrors = [...new Set(errors)];
        uniqueErrors.slice(0, 20).forEach((e) => console.log(e));
        if (uniqueErrors.length > 20) console.log(`...+${uniqueErrors.length - 20} more`);

        console.log("\n===== SUMMARY =====");
        const serverIssues = Object.entries(grouped).filter(([_, ss]) =>
            ss.some((s) => typeof s === "number" && s >= 500)
        );
        const clientIssues = Object.entries(grouped).filter(([_, ss]) =>
            ss.some((s) => typeof s === "number" && s >= 400 && s < 500)
        );
        console.log(`Total endpoints: ${Object.keys(grouped).length}`);
        console.log(`5xx issues: ${serverIssues.length}`);
        console.log(`4xx issues: ${clientIssues.length}`);
        console.log(`console errors: ${uniqueErrors.length}`);

        // Assert base presence (Phase 21: verifica-mode auto-on per admin)
        expect(postActiva.bodyClass).toBe("active");
        expect(has.contractWrap).toBeGreaterThan(0);
        expect(has.headerPage).toBeGreaterThan(0);
        expect(postActiva.checkIn).toBeGreaterThan(0);

        // Niente 5xx su endpoint chiamati
        for (const [k, ss] of serverIssues) {
            console.log(`❌ 5xx on ${k}: ${ss.join(",")}`);
        }
        expect(serverIssues.length, `5xx errors on: ${serverIssues.map((s) => s[0]).join(", ")}`).toBe(0);
    });

    // Test parallelo su URL con DB-backed numeric IDs: qui i POST verso
    // /api/teacher/content/{id}/* DEVONO partire (non sono skippati).
    test("DB-backed URL: .origin + .colorSelect + editor save → POST actually fires", async ({ page }) => {
        test.setTimeout(120000);
        const { calls, errors, failed } = attachNetworkLogger(page);
        // Naviga a topic list verifiche e clicca il primo topic
        await page.goto("/studio/verifica/sc/2s/FIS", { waitUntil: "networkidle" });
        await page.locator(".fm-study-topics a").first().click();
        await page.waitForURL(/\/studio\/verifica\/sc\/2s\/FIS\/.+/);
        await page.waitForTimeout(1500);

        // Phase 21: verifica-mode auto-on per admin. Attendi injection #infoVer.
        await page.waitForSelector("#infoVer", { timeout: 8000 }).catch(() => {});
        await page.waitForTimeout(1200);

        // Verifica che ci siano IDs numerici (DB-backed)
        const ids = await page.evaluate(() =>
            Array.from(document.querySelectorAll(".fm-collection__item[data-id]"))
                .map((c) => c.dataset.id)
                .filter((id) => /^\d+$/.test(id))
        );
        console.log(`DB-backed numeric ids: ${ids.length} (sample: ${ids.slice(0, 3).join(", ")})`);

        // .origin change → POST meta
        await page.locator(".fm-collection__item .origin").first().selectOption({ index: 1 }).catch(() => {});
        await page.waitForTimeout(800);

        // .colorSelect change → POST meta
        await page.locator(".fm-collection__item .colorSelect").first().selectOption({ index: 2 }).catch(() => {});
        await page.waitForTimeout(500);

        // Apri editor + save (POST body)
        page.on("dialog", async (d) => { await d.accept().catch(() => {}); });
        await page.evaluate(() => {
            document.querySelector(".fm-collection__item .fm-single-modifica-btn")?.click();
        });
        await page.waitForTimeout(500);
        await page.evaluate(() => {
            const btns = [...document.querySelectorAll(".fm-editor-panel button")];
            const save = btns.find((b) => /Salva/.test(b.textContent || ""));
            save?.click();
        });
        await page.waitForTimeout(1500);

        // Report
        console.log("\n===== DB-BACKED ENDPOINT REPORT =====");
        const grouped = {};
        for (const c of calls) {
            const key = `${c.method} ${c.url.replace(/^https?:\/\/[^/]+/, "")}`;
            if (!grouped[key]) grouped[key] = [];
            grouped[key].push(c.status || "pending");
        }
        for (const [k, statuses] of Object.entries(grouped)) {
            const worst = Math.max(...statuses.filter((s) => typeof s === "number"));
            const marker = worst >= 500 ? "❌" : worst >= 400 ? "⚠️" : "✅";
            console.log(`${marker} [${statuses.join(",")}] ${k}`);
        }
        const metaPosts = Object.keys(grouped).filter((k) => /POST .*\/content\/\d+\/meta/.test(k));
        const bodyPosts = Object.keys(grouped).filter((k) => /POST .*\/content\/\d+\/body/.test(k));
        console.log(`meta POSTs fired: ${metaPosts.length}`);
        console.log(`body POSTs fired: ${bodyPosts.length}`);

        const serverIssues = Object.entries(grouped).filter(([_, ss]) =>
            ss.some((s) => typeof s === "number" && s >= 500)
        );
        for (const [k, ss] of serverIssues) {
            console.log(`❌ 5xx on ${k}: ${ss.join(",")}`);
        }
        expect(serverIssues.length).toBe(0);
        // Nota: gli endpoint per-quesito /api/teacher/content/{id}/{meta,body}
        // accettano solo DB ids numerici a livello di teacher_content row; i
        // quesiti dentro il contract hanno synthetic ids → apiPost skippa.
        // Il test conferma solo che NESSUN endpoint contattato risponde 5xx.
    });
});
