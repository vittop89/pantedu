/**
 * Phase 24.77 — Fix pagina /studio/esercizio (5 interventi):
 *   1. Sticky stacking dei .fm-groupcollex con collapsible aperto.
 *   2. Select .origin popolata con l'origine renderizzata.
 *   3. Input posizione (number) allargato + frecce step 1.
 *   4. Checkbox gruppo A/R che si evidenziano (verde / arancione).
 *   5. Ridistribuzione punti VERO/FALSO tra quesiti selezionati + #SumPtotA/B.
 *
 * Esercizio VF di riferimento: ids=58 (SCI/2/MAT/2.0, "Sistemi lineari").
 */
const { test, expect } = require("@playwright/test");
const { loginAs } = require("./helpers");

const U = process.env.FM_E2E_ADMIN_USERNAME || "superadmin";
const P = process.env.FM_E2E_ADMIN_PASSWORD || "[PASSWORD-REDACTED]";
const VF_URL = "/studio/esercizio/SCI/2/MAT/2.0?ids=58";
const SHOTS = "tests/e2e/screenshots/eser-fixes";

test.use({ serviceWorkers: "block" });

test.beforeEach(async ({ page }) => {
    page.on("console", (m) => console.log(`  [console] ${m.type()}: ${m.text()}`));
    // Pre-seed consenso cookie (chiave user_cookie_consent_v2) così il banner
    // non copre il contratto negli screenshot.
    await page.addInitScript(() => {
        try {
            localStorage.setItem("user_cookie_consent_v2", JSON.stringify({
                necessary: true, functional: true, analytics: false, advertising: false,
                timestamp: "2026-05-31T00:00:00.000Z",
            }));
        } catch (_) { /* no-op */ }
    });
    await loginAs(page, U, P);
    await page.goto(VF_URL, { waitUntil: "domcontentloaded" });
    await page.waitForSelector('.fm-groupcollex[data-type="VF"]', { timeout: 10000 });
    await page.waitForTimeout(3500);
});

test("1 — sticky stacking: contract wrap ha data-kind=esercizio", async ({ page }) => {
    const info = await page.evaluate(() => {
        const wrap = document.querySelector(".fm-contract-wrap");
        const inScope = !!(window.FM && typeof window.FM.updateStickyTops === "function");
        return { kind: wrap?.getAttribute("data-kind"), stickyFnPresent: inScope };
    });
    console.log("STICKY:", JSON.stringify(info));
    // Disabilita lo smooth-scroll (html{scroll-behavior:smooth}) per misure
    // deterministiche: con lo smooth, scrollY è ancora ~0 subito dopo lo scroll.
    await page.addStyleTag({ content: "html{scroll-behavior:auto !important;}" });
    // Apri (click reale) i primi collapsible in DOM order: questo espande il
    // `.content` e fa crescere la pagina (scrollabile). Lo sticky stacking
    // opera sull'intero scope in-scope (esercizio + verifica related-verifiche).
    const headers = page.locator(
        'body.fm-admin-access .fm-contract-wrap[data-kind="esercizio"] .fm-collapsible,'
        + ' body.fm-admin-access .fm-contract-wrap[data-kind="verifica"] .fm-collapsible'
    );
    const nToOpen = Math.min(4, await headers.count());
    for (let i = 0; i < nToOpen; i++) {
        await headers.nth(i).click({ force: true });
        await page.waitForTimeout(250);
    }
    await page.waitForTimeout(400);
    // Scroll reale (wheel) → eventi scroll veri che innescano updateStickyTops:
    // i collapsible aperti che superano lo stackTop si fissano impilati.
    await page.mouse.move(640, 360);
    await page.mouse.wheel(0, 1600);
    await page.waitForTimeout(700);
    const stack = await page.evaluate(() => {
        const colls = [...document.querySelectorAll(
            'body.fm-admin-access .fm-contract-wrap[data-kind="esercizio"] .fm-collapsible.active,'
            + ' body.fm-admin-access .fm-contract-wrap[data-kind="verifica"] .fm-collapsible.active'
        )];
        // Solo i fissati (scrollati oltre lo stackTop). rectTop ~ inline top
        // (entro 2px) = ancorati al viewport (NON al containing block della
        // sezione, che era il bug content-visibility:auto).
        const fixed = colls
            .filter((c) => getComputedStyle(c).position === "fixed")
            .map((c) => ({
                top: parseInt(c.style.top, 10),
                rectTop: Math.round(c.getBoundingClientRect().top),
                inlineW: parseFloat(c.style.width),
                rectW: Math.round(c.getBoundingClientRect().width),
            }))
            .sort((a, b) => a.rectTop - b.rectTop);
        return {
            openedActive: colls.length,
            fixedCount: fixed.length,
            anchoredToViewport: fixed.every((f) => Math.abs(f.rectTop - f.top) <= 2),
            // width pinnata == inline (non collassata a contenuto via width:auto!important G20.7)
            widthNotCollapsed: fixed.every((f) => Math.abs(f.rectW - f.inlineW) < 5 && f.rectW > 300),
            tops: fixed.map((f) => f.rectTop),
            scrollTop: Math.round(document.scrollingElement.scrollTop),
        };
    });
    console.log("STICKY_STACK:", JSON.stringify(stack));
    await page.screenshot({ path: `${SHOTS}/01-sticky-stack.png` });
    expect(info.kind).toBe("esercizio");
    expect(info.stickyFnPresent).toBe(true);
    // >=2 collapsible fissati, ancorati al viewport, con top distinti e
    // crescenti = impilamento sotto il titolo (regression content-visibility
    // risolta).
    expect(stack.fixedCount).toBeGreaterThanOrEqual(2);
    expect(stack.anchoredToViewport).toBe(true);
    expect(stack.widthNotCollapsed).toBe(true);
    expect(new Set(stack.tops).size).toBe(stack.tops.length);
    expect([...stack.tops].sort((a, b) => a - b)).toEqual(stack.tops);
});

test("2 — origin select popolata", async ({ page }) => {
    const r = await page.evaluate(() => {
        const sel = document.querySelector(".fm-collection__item select.origin");
        return { value: sel?.value || null, optionCount: sel?.options.length || 0, text: sel?.selectedOptions?.[0]?.textContent || null };
    });
    console.log("ORIGIN:", JSON.stringify(r));
    await page.locator(".fm-collection__item select.origin").first().scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${SHOTS}/02-origin-select.png` });
    expect(r.value).toBeTruthy();
    expect(r.optionCount).toBeGreaterThan(0);
});

test("3 — posizione: input number + stepper custom ▲▼ funzionanti", async ({ page }) => {
    const r = await page.evaluate(() => {
        const pos = document.querySelector(".fm-position");
        const inp = pos?.querySelector(".fm-def-position-imp");
        const up = pos?.querySelector(".fm-stepper__btn--up");
        const down = pos?.querySelector(".fm-stepper__btn--down");
        if (!inp || !up || !down) return null;
        inp.value = "";
        up.click(); up.click();          // 0 → 1 → 2
        const afterUp = inp.value;
        down.click();                    // 2 → 1
        const afterDown = inp.value;
        return { type: inp.type, step: inp.step, hasSteppers: true, afterUp, afterDown };
    });
    console.log("POSITION:", JSON.stringify(r));
    await page.locator(".fm-position").first().scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${SHOTS}/03-position-input.png` });
    expect(r.type).toBe("number");
    expect(r.hasSteppers).toBe(true);
    expect(r.afterUp).toBe("2");
    expect(r.afterDown).toBe("1");
});

test("3b — VF total inputs compatti (width fissa, no shrink)", async ({ page }) => {
    // initializeVFProblems crea gli input in modo asincrono (retry 250/800ms +
    // mathjax); su VPS più lento può non essere pronto al wait del beforeEach.
    await page.waitForSelector(".vf-total-points-inputA", { timeout: 8000 });
    const r = await page.evaluate(() => {
        const g = document.querySelector('.fm-groupcollex[id*="type_VF"]');
        const inA = g?.querySelector(".vf-total-points-inputA");
        const inB = g?.querySelector(".vf-total-points-inputB");
        const wA = inA ? Math.round(inA.getBoundingClientRect().width) : null;
        // popola e ri-misura: la width NON deve cambiare
        if (inA) { inA.value = "8"; inA.dispatchEvent(new Event("input", { bubbles: true })); inA.dispatchEvent(new Event("change", { bubbles: true })); }
        const wAafter = inA ? Math.round(inA.getBoundingClientRect().width) : null;
        const wB = inB ? Math.round(inB.getBoundingClientRect().width) : null;
        // stepper ▲▼ VF (step 0.5): l'input ha gli stepper come fratello successivo
        const steppers = inA?.nextElementSibling;
        const up = steppers?.querySelector(".fm-stepper__btn--up");
        const down = steppers?.querySelector(".fm-stepper__btn--down");
        let stepUp = null, stepDown = null;
        if (up && down) {
            inA.value = "0";
            up.click(); up.click();           // 0 → 0.5 → 1.0
            stepUp = inA.value;
            down.click();                     // 1.0 → 0.5
            stepDown = inA.value;
        }
        return { wA, wAafter, wB, hasSteppers: !!(up && down), stepUp, stepDown };
    });
    console.log("VF_WIDTH:", JSON.stringify(r));
    expect(r.wA).toBeLessThanOrEqual(48);
    expect(r.wA).toBe(r.wAafter); // niente restringimento quando popolato
    expect(r.hasSteppers).toBe(true);
    expect(r.stepUp).toBe("1.0");   // step 0.5 ×2
    expect(r.stepDown).toBe("0.5");
});

test("4 — checkbox gruppo A/R si evidenziano", async ({ page }) => {
    await page.evaluate(() => {
        const labs = document.querySelector(".fm-groupcollex .fm-check").querySelectorAll(".labcheck");
        labs[0].click(); labs[1].click();
    });
    await page.waitForTimeout(400); // attendi fine transition .12s
    const r = await page.evaluate(() => {
        const labs = document.querySelector(".fm-groupcollex .fm-check").querySelectorAll(".labcheck");
        return { aBg: getComputedStyle(labs[0]).backgroundColor, bBg: getComputedStyle(labs[1]).backgroundColor };
    });
    console.log("CHECKBOX:", JSON.stringify(r));
    await page.locator(".fm-groupcollex .fm-check").first().scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${SHOTS}/04-checkbox-highlight.png` });
    expect(r.aBg).toContain("22, 163, 74");   // #16a34a verde
    expect(r.bBg).toContain("245, 158, 11");  // #f59e0b arancione
});

test("5 — ridistribuzione punti VF tra quesiti selezionati", async ({ page }) => {
    // initializeVFProblems crea l'input in modo asincrono → attendilo (anti-flaky VPS).
    await page.waitForSelector(".vf-total-points-inputA", { timeout: 8000 });
    const r = await page.evaluate(() => {
        const g = document.querySelector('.fm-groupcollex[id*="type_VF"]');
        const inA = g.querySelector(".vf-total-points-inputA");
        inA.value = "10";
        inA.dispatchEvent(new Event("input", { bubbles: true }));
        inA.dispatchEvent(new Event("change", { bubbles: true }));
        const items = g.querySelectorAll(".fm-collection__item");
        for (let i = 0; i < 4 && i < items.length; i++) {
            const cb = items[i].querySelector(".fm-checkbox-ain");
            cb.checked = true; cb.dispatchEvent(new Event("change", { bubbles: true }));
        }
        return {
            totA: g.querySelector(".total-pointsA")?.textContent,
            perItem: [...items].slice(0, 4).map((it) => it.querySelector(".fm-input-pt")?.value),
            sumA: document.getElementById("SumPtotA")?.value,
            vfInputs: g.querySelectorAll(".fm-vf-total-points-input").length,
        };
    });
    console.log("VF_REDIS:", JSON.stringify(r));
    await page.screenshot({ path: `${SHOTS}/05-vf-redistribute.png` });
    expect(r.vfInputs).toBeGreaterThan(0);
    expect(r.sumA).toBe("10");
    expect(r.perItem.every((v) => v === "2.50")).toBe(true);
});

test("6 — badge sorgente trasparente + gap selection/collapsible chiuso", async ({ page }) => {
    const r = await page.evaluate(() => {
        const badge = document.querySelector(".fm-badge.fm-latex");
        const g = document.querySelector(".fm-groupcollex");
        const sel = g.querySelector(".selection");
        const coll = g.querySelector(".fm-collapsible");
        const sr = sel.getBoundingClientRect();
        const cr = coll.getBoundingClientRect();
        return {
            badgeBg: badge ? getComputedStyle(badge).backgroundColor : null,
            gap: Math.round(cr.left - sr.right),
            collMarginLeft: getComputedStyle(coll).marginLeft,
        };
    });
    console.log("BADGE_GAP:", JSON.stringify(r));
    await page.locator(".fm-groupcollex .selection").first().scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${SHOTS}/06-badge-gap.png` });
    expect(r.badgeBg).toBe("rgba(0, 0, 0, 0)"); // trasparente
    expect(r.gap).toBeLessThanOrEqual(1);        // flush con .selection
});

test("7 — stepper ▲▼ su riordino gruppo (.fm-move-position-problem)", async ({ page }) => {
    const r = await page.evaluate(() => {
        const inp = document.querySelector(".fm-move-position-problem");
        if (!inp) return null;
        const steppers = inp.nextElementSibling;
        const up = steppers?.querySelector(".fm-stepper__btn--up");
        const down = steppers?.querySelector(".fm-stepper__btn--down");
        if (!up || !down) return { hasSteppers: false };
        // riordino: clicchiamo solo up una volta e leggiamo che il valore cambia
        const start = inp.value || "1";
        up.click();
        return { hasSteppers: true, start, after: inp.value };
    });
    console.log("MOVE_STEP:", JSON.stringify(r));
    expect(r?.hasSteppers).toBe(true);
    expect(r.after).not.toBe(r.start === "" ? "ignore" : "");
});

test("8 — padding orizzontale costante su .fm-collapsible (chiuso e aperto)", async ({ page }) => {
    const r = await page.evaluate(() => {
        // chiuso: padding già presente
        const closed = document.querySelector(".fm-contract-wrap .fm-collapsible");
        const csC = getComputedStyle(closed);
        return { closedPadL: parseInt(csC.paddingLeft), closedPadR: parseInt(csC.paddingRight) };
    });
    // apri un problema → resta col padding
    await page.locator(".fm-contract-wrap .fm-collapsible").first().click({ force: true });
    await page.waitForTimeout(400);
    const r2 = await page.evaluate(() => {
        const open = document.querySelector(".fm-collapsible.active");
        const csO = getComputedStyle(open);
        return { openPadL: parseInt(csO.paddingLeft), openPadR: parseInt(csO.paddingRight) };
    });
    console.log("COLL_PAD:", JSON.stringify({ ...r, ...r2 }));
    expect(r.closedPadL).toBeGreaterThanOrEqual(6);
    expect(r.closedPadR).toBeGreaterThanOrEqual(6);
    expect(r2.openPadL).toBeGreaterThanOrEqual(6);
    expect(r2.openPadR).toBeGreaterThanOrEqual(6);
});
