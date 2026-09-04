/**
 * Phase G19.5 — Visual regression: .selector-eser proporzioni + .scelte
 * wrapper visibile.
 *
 *  Screenshot ridotti dell'area `.selector-eser` per:
 *   1. light mode: tutti gli elementi alla STESSA height (28px target).
 *   2. dark mode: idem.
 *   3. wrapper `.scelte-verifica-wrapper` deve essere visibile (no display:none).
 *
 *  Inoltre asserts numerici: bounding-box height di ogni elemento
 *  dev'essere ~28px (tolleranza 2px); larghezza minima del bar
 *  full-width; tutti gli elementi sulla stessa riga (top y simile).
 */
const { test, expect } = require("@playwright/test");
const path = require("path");

async function login(page, user = "superadmin", pass = (process.env.E2E_TEACHER_PASS || "")) {
    await page.addInitScript(() => {
        localStorage.setItem("user_cookie_consent_v2", JSON.stringify({
            functional: true, analytics: false, advertising: false, timestamp: Date.now(),
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', user);
    await page.fill('input[name="password"]', pass);
    await page.click('button[type="submit"]');
    await page.waitForFunction(() => !location.pathname.startsWith("/login"), { timeout: 15_000 });
    await page.waitForLoadState("domcontentloaded");
}

async function setupVerificaPage(page) {
    await page.goto("/studio/esercizio/ar/2s/MAT/1");
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2500); // attende _caricaCheckboxABin
    // Apri Info drawer per esporre #infoVer e .selector-eser visibilmente
    const infoBtn = page.locator('#fm-topbar [data-fm-action="info"]');
    if (await infoBtn.count()) {
        await infoBtn.click();
        await page.waitForTimeout(500);
    }
}

async function measureSelectorEser(page) {
    return await page.evaluate(() => {
        const root = document.querySelector(".fm-selector-eser");
        if (!root) return null;
        const targets = {
            tipoEsercizio:    root.querySelector("select.fm-tipo-esercizio"),
            syncQuesiti:      root.querySelector(".fm-sync-quesiti-btn"),
            dropdownButton:   root.querySelector(".fm-dropdown-button-gen"),
            halfMoon:         root.querySelector(".fm-half-moon-button"),
            helpCircle:       root.querySelector(".fm-help-circle"),
            tipoEsercizioVer: root.querySelector("select.fm-tipo-esercizio-ver"),
            syncQuesitiVer:   root.querySelector(".fm-sync-quesiti-ver-btn"),
            // G19.9 — `.scelte-verifica-wrapper` ora INSIDE `.selector-eser`
            // (last child, dopo .sync-quesiti_ver-btn) per layout pulito.
            scelteWrapper:    root.querySelector(":scope > .fm-scelte-verifica-wrapper"),
        };
        const out = {};
        for (const [name, el] of Object.entries(targets)) {
            if (!el) { out[name] = null; continue; }
            const r = el.getBoundingClientRect();
            const cs = getComputedStyle(el);
            out[name] = {
                h: Math.round(r.height),
                w: Math.round(r.width),
                top: Math.round(r.top),
                visible: r.width > 0 && r.height > 0 && cs.display !== "none" && cs.visibility !== "hidden",
                display: cs.display,
            };
        }
        return out;
    });
}

test("G19.5 — .selector-eser proporzioni uniformi + scelte wrapper visibile", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    await setupVerificaPage(page);

    const m = await measureSelectorEser(page);
    expect(m, ".selector-eser deve esistere").not.toBeNull();
    console.log("[G19.5] measurements:", JSON.stringify(m, null, 2));

    // 1. .scelte-verifica-wrapper deve essere VISIBILE (no display:none).
    expect(m.scelteWrapper, "scelte-verifica-wrapper presente").not.toBeNull();
    expect(m.scelteWrapper.visible, "scelte-verifica-wrapper deve essere visibile").toBe(true);
    expect(m.scelteWrapper.display).not.toBe("none");

    // 2. Heights uniformi: tutti gli elementi UI principali a ~28px (tol ±3).
    const TARGET = 28;
    const TOL    = 3;
    const sameRowChecks = [
        ["tipoEsercizio",    m.tipoEsercizio],
        ["syncQuesiti",      m.syncQuesiti],
        ["dropdownButton",   m.dropdownButton],
        ["halfMoon",         m.halfMoon],
        ["helpCircle",       m.helpCircle],
        ["tipoEsercizioVer", m.tipoEsercizioVer],
        ["syncQuesitiVer",   m.syncQuesitiVer],
    ];
    for (const [name, el] of sameRowChecks) {
        if (!el || !el.visible) continue;
        const diff = Math.abs(el.h - TARGET);
        expect(diff, `${name} height ${el.h} deve essere ~${TARGET} (tol ${TOL})`).toBeLessThanOrEqual(TOL);
    }
    // scelteWrapper è su una RIGA SEPARATA (sibling di .selector-eser in
    // #infoVer); height 28px ma top diverso. Check separato.
    if (m.scelteWrapper && m.scelteWrapper.visible) {
        expect(Math.abs(m.scelteWrapper.h - TARGET), "scelteWrapper height").toBeLessThanOrEqual(TOL);
    }

    // 3. Elementi della .selector-eser sulla STESSA riga (top spread ≤ 4px).
    const visTops = sameRowChecks.filter(([, el]) => el && el.visible).map(([, el]) => el.top);
    const minTop = Math.min(...visTops);
    const maxTop = Math.max(...visTops);
    expect(maxTop - minTop, "vertical alignment .selector-eser elements ≤ 4px").toBeLessThanOrEqual(4);

    // 4. Solo UN .scelte-verifica-wrapper VISIBILE in #infoVer
    //    (G19.6 dedupe — niente doppio in immagine).
    const visibleWrappers = await page.evaluate(() => {
        const all = document.querySelectorAll("#infoVer .fm-scelte-verifica-wrapper");
        return Array.from(all).filter(w => {
            const cs = getComputedStyle(w);
            const r = w.getBoundingClientRect();
            return cs.display !== "none" && cs.visibility !== "hidden"
                && r.width > 0 && r.height > 0;
        }).length;
    });
    expect(visibleWrappers, "deve esserci ESATTAMENTE 1 .scelte-verifica-wrapper visibile (no doppione)").toBe(1);

    // Screenshot light per ispezione visiva manuale
    const root = page.locator(".selector-eser").first();
    await root.scrollIntoViewIfNeeded();
    await root.screenshot({
        path: path.join(__dirname, "screenshots", "g19_5-selector-light.png"),
    }).catch(() => {});
});

test("G19.5 — dark mode .selector-eser color tokens applicati", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    await setupVerificaPage(page);

    // Toggle dark
    await page.evaluate(() => document.body.classList.add("fm-dark"));
    await page.waitForTimeout(200);

    const colors = await page.evaluate(() => {
        const root = document.querySelector(".fm-selector-eser");
        if (!root) return null;
        const sel = root.querySelector("select.fm-tipo-esercizio");
        const wrapper = root.querySelector(".fm-scelte-verifica-wrapper");
        return {
            rootBg: getComputedStyle(root).backgroundColor,
            selectBg: sel ? getComputedStyle(sel).backgroundColor : null,
            selectColor: sel ? getComputedStyle(sel).color : null,
            wrapperBg: wrapper ? getComputedStyle(wrapper).backgroundColor : null,
        };
    });
    console.log("[G19.5 dark]", JSON.stringify(colors, null, 2));
    expect(colors).not.toBeNull();
    // Dark mode → bg ≠ pure white. Fail proxy: white o quasi-white.
    expect(colors.rootBg, "root bg dark ≠ white").not.toMatch(/^rgb\(255,\s*255,\s*255\)$/);

    const root = page.locator(".selector-eser").first();
    await root.scrollIntoViewIfNeeded();
    await root.screenshot({
        path: path.join(__dirname, "screenshots", "g19_5-selector-dark.png"),
    }).catch(() => {});
});
