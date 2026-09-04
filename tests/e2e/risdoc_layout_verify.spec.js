/**
 * U-layout e2e — verifica che il layout risdoc view sia corretto:
 *   1. sidebar visibile (non coperta da .header legacy)
 *   2. toolbar minimale visibile sopra il contenuto
 *   3. nessun gap vuoto significativo tra toolbar e contenuto
 *   4. .header legacy ha position:static (non fixed)
 *   5. page-container ha padding-top = 0
 */
const { test, expect } = require("@playwright/test");

async function login(page) {
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
        sessionStorage.clear();
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

test.describe("risdoc view layout", () => {
    test("sidebar + toolbar + gap-free layout", async ({ page }) => {
        await login(page);

        // pick first risdoc template
        const api = await page.request.get("/api/risdoc/templates?origin=risdoc");
        const tid = (await api.json()).templates?.[0]?.id;
        expect(tid).toBeTruthy();

        await page.goto(`/risdoc/view/${tid}`, { waitUntil: "domcontentloaded" });
        await page.waitForSelector(".fm-risdoc-view .header", { timeout: 10000 });
        // Attendi stabilità layout (risdoc.js applica dynamic padding; poi il
        // mio !important override vince, ma serve tempo per layout settle).
        await page.waitForFunction(() => {
            const pc = document.querySelector(".page-container");
            if (!pc) return false;
            const pt = parseFloat(getComputedStyle(pc).paddingTop);
            return pt < 5; // pronto = padding-top azzerato
        }, { timeout: 8000 });
        await page.waitForTimeout(500);

        // 1. Shell sidebar presente
        const sidebar = page.locator(".fm-sidebar, #fm-sidebar, .sidebar").first();
        const hasSidebar = await sidebar.count() > 0;
        console.log("sidebar locator count:", await sidebar.count());

        // 2. Toolbar minimale presente + visibile
        const toolbar = page.locator(".fm-risdoc-toolbar");
        await expect(toolbar).toBeVisible();
        const toolbarBox = await toolbar.boundingBox();
        console.log("toolbar box:", toolbarBox);
        expect(toolbarBox.height).toBeGreaterThan(20);

        // 3. .header legacy position NON deve essere fixed
        const headerPos = await page.evaluate(() => {
            const h = document.querySelector(".fm-risdoc-view .header");
            if (!h) return null;
            return {
                position:  getComputedStyle(h).position,
                top:       getComputedStyle(h).top,
                zIndex:    getComputedStyle(h).zIndex,
                boundingRect: h.getBoundingClientRect(),
            };
        });
        console.log("header position:", JSON.stringify(headerPos, null, 2));
        expect(headerPos).not.toBeNull();
        expect(headerPos.position).not.toBe("fixed");

        // 4. .page-container padding-top deve essere 0
        const pcPaddingTop = await page.evaluate(() => {
            const pc = document.querySelector(".fm-risdoc-view .page-container");
            if (!pc) return null;
            return parseFloat(getComputedStyle(pc).paddingTop);
        });
        console.log("page-container padding-top:", pcPaddingTop);
        expect(pcPaddingTop).not.toBeNull();
        expect(pcPaddingTop).toBeLessThanOrEqual(1);

        // 5. GAP: misura distanza tra il bottom della toolbar e il top
        //    del primo elemento visibile dentro il main content.
        const gap = await page.evaluate(() => {
            const tb = document.querySelector(".fm-risdoc-toolbar");
            const main = document.querySelector("#fm-risdoc-content");
            if (!tb || !main) return null;
            const tbBottom = tb.getBoundingClientRect().bottom;
            // Cerca primo child element visibile del main
            const children = Array.from(main.children);
            for (const c of children) {
                const r = c.getBoundingClientRect();
                if (r.height > 0) {
                    return {
                        toolbarBottom: Math.round(tbBottom),
                        firstChildTop: Math.round(r.top),
                        firstChildTag: c.tagName + "." + c.className.split(" ")[0],
                        gap: Math.round(r.top - tbBottom),
                    };
                }
            }
            return null;
        });
        console.log("GAP analysis:", JSON.stringify(gap, null, 2));
        expect(gap).not.toBeNull();

        // DIAG: enumera tutti i wrapper/contenitori tra toolbar e page-container
        // per capire cosa occupa lo spazio.
        const diag = await page.evaluate(() => {
            const tb = document.querySelector(".fm-risdoc-toolbar");
            const pc = document.querySelector(".page-container");
            if (!tb || !pc) return null;
            const tbBottom = tb.getBoundingClientRect().bottom;
            const pcTop = pc.getBoundingClientRect().top;
            // Walk up from page-container to body, log each element's box
            const boxes = [];
            let el = pc;
            while (el && el !== document.body) {
                const r = el.getBoundingClientRect();
                const cs = getComputedStyle(el);
                boxes.push({
                    tag: el.tagName + "." + (el.className || "").split(" ").filter(Boolean).join(".") + (el.id ? "#" + el.id : ""),
                    top: Math.round(r.top), bottom: Math.round(r.bottom),
                    h: Math.round(r.height), pt: cs.paddingTop, pb: cs.paddingBottom,
                    mt: cs.marginTop, mb: cs.marginBottom, display: cs.display,
                });
                el = el.parentElement;
            }
            return { tbBottom: Math.round(tbBottom), pcTop: Math.round(pcTop), boxes };
        });
        console.log("DIAG walk ancestors page-container:");
        console.log(JSON.stringify(diag, null, 2));

        // DIAG 2: enumera TUTTI i children DIRETTI di main#fm-risdoc-content
        const childrenInfo = await page.evaluate(() => {
            const main = document.querySelector("#fm-risdoc-content");
            if (!main) return null;
            return Array.from(main.childNodes).map((n, i) => {
                if (n.nodeType === 3) { // text
                    const t = n.textContent;
                    return { idx: i, type: "text", len: t.length, preview: t.trim().slice(0, 40) };
                }
                if (n.nodeType !== 1) return { idx: i, type: "node" + n.nodeType };
                const r = n.getBoundingClientRect();
                const cs = getComputedStyle(n);
                return {
                    idx: i, type: "el",
                    tag: n.tagName + "." + (n.className || "").split(" ").filter(Boolean).join(".") + (n.id ? "#" + n.id : ""),
                    top: Math.round(r.top), bottom: Math.round(r.bottom),
                    h: Math.round(r.height), w: Math.round(r.width),
                    pos: cs.position, display: cs.display, mt: cs.marginTop, pt: cs.paddingTop,
                };
            });
        });
        console.log("CHILDREN main#fm-risdoc-content:");
        console.log(JSON.stringify(childrenInfo, null, 2));
        expect(gap.gap, `Gap tra toolbar e contenuto = ${gap.gap}px (atteso < 30)`).toBeLessThan(30);

        // 6. .header NON copre la sidebar (se sidebar esiste, header.left >= sidebar.right)
        if (hasSidebar) {
            const overlap = await page.evaluate(() => {
                const sb = document.querySelector(".fm-sidebar, #fm-sidebar, .sidebar");
                const h = document.querySelector(".fm-risdoc-view .header");
                if (!sb || !h) return null;
                const s = sb.getBoundingClientRect();
                const hh = h.getBoundingClientRect();
                const sVisible = getComputedStyle(sb).display !== "none" && s.width > 0;
                return {
                    sidebarVisible: sVisible,
                    sidebarRect:  { left: Math.round(s.left), right: Math.round(s.right), width: Math.round(s.width) },
                    headerRect:   { left: Math.round(hh.left), right: Math.round(hh.right), width: Math.round(hh.width) },
                    overlap: sVisible && (hh.left < s.right) && (hh.right > s.left),
                };
            });
            console.log("sidebar-header overlap:", JSON.stringify(overlap, null, 2));
            // Questo check scatta solo se la sidebar è effettivamente visibile
            if (overlap && overlap.sidebarVisible) {
                expect(overlap.overlap, `Header overlap sidebar? ${JSON.stringify(overlap)}`).toBe(false);
            }
        }

        console.log("\n=== ALL LAYOUT CHECKS PASSED ===");
    });
});
