/**
 * G19.30 DEBUG — diagnostica centering modal `.fm-vd-detail`.
 *
 * Verifica:
 *  - viewport size
 *  - body scrollHeight (se > viewport → c'e' overflow)
 *  - ancestor con transform/filter/perspective che creerebbero un
 *    containing block per `position: fixed`
 *  - bounding rect del modal una volta aperto
 *  - screenshot full-page + viewport-only per confronto visivo
 */
const { test, expect } = require("@playwright/test");
const path = require("path");

async function login(page) {
    await page.addInitScript(() => {
        localStorage.setItem("user_cookie_consent_v2", JSON.stringify({
            functional: true, analytics: false, advertising: false, timestamp: Date.now(),
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
    await page.click('button[type="submit"]');
    await page.waitForFunction(() => !location.pathname.startsWith("/login"), { timeout: 15_000 });
    await page.waitForLoadState("domcontentloaded");
}

test("G19.30 DEBUG — centering modal verifica", async ({ page }) => {
    test.setTimeout(60_000);
    await page.setViewportSize({ width: 1400, height: 800 });
    await login(page);
    await page.goto("/studio/esercizio/ar/2s/MAT/1");
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(3500);

    // Apri sidepage Verifiche
    const verifBtn = page.locator('.fm-sb-sec[data-sidepage="verif"]');
    if (await verifBtn.count()) {
        await verifBtn.first().click().catch(() => {});
        await page.waitForTimeout(1500);
    }

    // Diag PRE-modal
    const preDiag = await page.evaluate(() => {
        const out = {
            viewport: { w: window.innerWidth, h: window.innerHeight },
            body: {
                scrollWidth: document.body.scrollWidth,
                scrollHeight: document.body.scrollHeight,
                clientWidth: document.body.clientWidth,
                clientHeight: document.body.clientHeight,
            },
            html: {
                scrollHeight: document.documentElement.scrollHeight,
                clientHeight: document.documentElement.clientHeight,
            },
            scroll: { x: window.scrollX, y: window.scrollY },
        };
        // Ancestor chain con transform/filter/perspective/contain (creano CB per fixed)
        const ancestors = [];
        let el = document.body;
        while (el && el !== document.documentElement.parentElement) {
            const cs = getComputedStyle(el);
            const interesting = {
                tag: el.tagName,
                id: el.id || null,
                cls: el.className || null,
                transform: cs.transform !== "none" ? cs.transform : null,
                filter: cs.filter !== "none" ? cs.filter : null,
                backdropFilter: cs.backdropFilter !== "none" ? cs.backdropFilter : null,
                perspective: cs.perspective !== "none" ? cs.perspective : null,
                willChange: cs.willChange !== "auto" ? cs.willChange : null,
                contain: cs.contain !== "none" ? cs.contain : null,
                position: cs.position,
            };
            // Solo se ha qualche flag
            if (interesting.transform || interesting.filter || interesting.backdropFilter
                || interesting.perspective || interesting.willChange || interesting.contain) {
                ancestors.push(interesting);
            }
            el = el.parentElement;
        }
        out.fixedContainingBlocks = ancestors;
        return out;
    });
    console.log("[DIAG PRE]", JSON.stringify(preDiag, null, 2));

    // Trova un link verifica e clicca per aprire popup
    const link = page.locator(".fm-vd-block .fm-vd-link").first();
    const cnt = await link.count();
    console.log(`[DIAG] verifica links count: ${cnt}`);
    if (cnt === 0) {
        console.log("[DIAG] no verifica → genera 1 con saveTex");
        const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
        await page.request.post("/api/verifica/save-tex", {
            data: {
                title: "DEBUG MODAL",
                materia: "MAT",
                selectedIIS: "ar", selectedCLS: "2", selectedMATER: "MAT",
                verTitle: "DEBUG MODAL",
                problems: [{ problemId: "1", titolo_quesito: "x" }],
            },
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
        });
        await page.reload();
        await page.waitForTimeout(2500);
        if (await page.locator('.fm-sb-sec[data-sidepage="verif"]').count()) {
            await page.locator('.fm-sb-sec[data-sidepage="verif"]').first().click();
            await page.waitForTimeout(1500);
        }
    }

    // Apri direttamente via API debug del modulo
    const opened = await page.evaluate(async () => {
        // Trova un id verifica reale via API
        const r = await fetch("/api/verifica/list", { credentials: "same-origin" });
        const j = await r.json();
        const items = j?.items || [];
        if (!items.length) return { error: "no verifiche in DB" };
        const id = items[0].id;
        // Apri modal via API esposta
        if (window.FM?.VerificaDetailModal?.openDetailModal) {
            await window.FM.VerificaDetailModal.openDetailModal(id);
            return { id, opened: true };
        }
        return { id, opened: false, error: "openDetailModal non esposta" };
    });
    console.log("[DIAG opened]", JSON.stringify(opened));
    await page.waitForTimeout(1200);

    // Diag POST-modal
    const postDiag = await page.evaluate(() => {
        const m = document.getElementById("fm-vd-detail-modal");
        if (!m) return { error: "modal non trovato" };
        const detail = m.querySelector(".fm-vd-detail");
        const rect = m.getBoundingClientRect();
        const detailRect = detail?.getBoundingClientRect() || null;
        const cs = getComputedStyle(m);
        return {
            viewport: { w: window.innerWidth, h: window.innerHeight },
            scroll: { x: window.scrollX, y: window.scrollY },
            modal: {
                rect: { x: rect.x, y: rect.y, w: rect.width, h: rect.height },
                computed: {
                    position: cs.position,
                    top: cs.top, left: cs.left, right: cs.right, bottom: cs.bottom,
                    width: cs.width, height: cs.height,
                    display: cs.display,
                    alignItems: cs.alignItems,
                    justifyContent: cs.justifyContent,
                    zIndex: cs.zIndex,
                    overflow: cs.overflow,
                },
                inlineStyle: m.style.cssText,
                offsetParent: m.offsetParent ? m.offsetParent.tagName + "#" + m.offsetParent.id : null,
            },
            detail: detailRect ? {
                rect: { x: detailRect.x, y: detailRect.y, w: detailRect.width, h: detailRect.height },
            } : null,
            // Centra correttamente?
            isCentered: detailRect
                ? Math.abs((detailRect.x + detailRect.width / 2) - window.innerWidth / 2) < 5
                  && Math.abs((detailRect.y + detailRect.height / 2) - window.innerHeight / 2) < 5
                : null,
        };
    });
    console.log("[DIAG POST]", JSON.stringify(postDiag, null, 2));

    // Screenshot diagnostici
    const dir = path.join(__dirname, "screenshots");
    await page.screenshot({
        path: path.join(dir, "g19_30-debug-viewport.png"),
        fullPage: false,
    });
    await page.screenshot({
        path: path.join(dir, "g19_30-debug-fullpage.png"),
        fullPage: true,
    });

    expect(postDiag.modal, "modal exists").toBeTruthy();
    if (postDiag.detail) {
        const cx = postDiag.detail.rect.x + postDiag.detail.rect.w / 2;
        const cy = postDiag.detail.rect.y + postDiag.detail.rect.h / 2;
        console.log(`[DIAG] modal center: (${cx}, ${cy}) — viewport center: (${postDiag.viewport.w/2}, ${postDiag.viewport.h/2})`);
    }

    // G19.31 — toggle dark theme + screenshot per verifica visuale
    await page.evaluate(() => document.body.classList.add("fm-dark"));
    await page.waitForTimeout(300);
    await page.screenshot({
        path: path.join(dir, "g19_31-modal-dark.png"),
        fullPage: false,
    });
    await page.evaluate(() => document.body.classList.remove("fm-dark"));
    await page.waitForTimeout(300);
    await page.screenshot({
        path: path.join(dir, "g19_31-modal-light.png"),
        fullPage: false,
    });
});
