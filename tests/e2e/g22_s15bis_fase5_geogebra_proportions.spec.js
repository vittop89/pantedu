/**
 * G22.S15.bis Fase 5 — Test diagnostica dimensioni rendered del wrapper
 * GeoGebra fuori da edit-mode (caso utente "prova 2").
 *
 * Misura: width/height computati di .fm-geogebra-wrap e del suo SVG, e
 * width del container parent. Stampa così possiamo capire perché il cap
 * CSS max-width:60% / max-height:360px non basta.
 */
const { test, expect } = require("@playwright/test");

const USERNAME = "superadmin";
const PASSWORD = (process.env.E2E_TEACHER_PASS || "");

test("Diagnose: rendered GeoGebra wrap proportions in 'prova 2'", async ({ page }) => {
    test.setTimeout(60000);
    page.on("dialog", async d => { await d.accept(); });
    page.on("console", msg => {
        if (msg.text().includes("fm-ggb") || msg.text().includes("viewBox")) {
            console.log("[browser]:", msg.text());
        }
    });

    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");
    // Dismiss cookie banner se presente
    const acceptBtn = page.locator("button").filter({ hasText: /Accetta|Acconsento|OK/i }).first();
    if (await acceptBtn.count() > 0) { await acceptBtn.click().catch(() => {}); await page.waitForTimeout(300); }

    // Cerca l'esercizio "prova 2" — non sappiamo a priori il path. Provo
    // alcuni pattern comuni per il docente di test.
    const candidates = [
        "/studio/esercizio/sc/3/MAT/2",
        "/studio/esercizio/sc/3/MAT/1",
    ];
    let opened = false;
    for (const url of candidates) {
        await page.goto(url);
        await page.waitForLoadState("networkidle").catch(() => {});
        await page.waitForTimeout(800);
        const ggbCount = await page.locator(".fm-geogebra-wrap").count();
        console.log(`URL ${url} → ${ggbCount} GeoGebra wrappers`);
        if (ggbCount > 0) { opened = true; break; }
    }
    expect(opened, "almeno un GeoGebra wrap visibile").toBe(true);

    const dims = await page.evaluate(() => {
        const wraps = Array.from(document.querySelectorAll(".fm-geogebra-wrap"));
        return wraps.map(w => {
            const svg = w.querySelector("svg");
            const wrapRect = w.getBoundingClientRect();
            const svgRect = svg?.getBoundingClientRect();
            const cs = getComputedStyle(w);
            const csv = svg ? getComputedStyle(svg) : null;
            const parent = w.parentElement;
            const parentRect = parent?.getBoundingClientRect();
            const parentCs = parent ? getComputedStyle(parent) : null;
            return {
                wrap_w: Math.round(wrapRect.width),
                wrap_h: Math.round(wrapRect.height),
                svg_w: svgRect ? Math.round(svgRect.width) : null,
                svg_h: svgRect ? Math.round(svgRect.height) : null,
                parent_tag: parent?.tagName,
                parent_class: parent?.className,
                parent_w: parentRect ? Math.round(parentRect.width) : null,
                wrap_inline_style: w.getAttribute("style") || "",
                svg_inline_style: svg?.getAttribute("style") || "",
                wrap_data_width: w.getAttribute("data-ggb-width") || "",
                svg_attr_width: svg?.getAttribute("width") || "",
                svg_attr_height: svg?.getAttribute("height") || "",
                svg_viewbox: svg?.getAttribute("viewBox") || "",
                wrap_max_w_css: cs.maxWidth,
                wrap_max_h_css: cs.maxHeight,
                wrap_display: cs.display,
                svg_w_css: csv?.width,
                svg_h_css: csv?.height,
                svg_max_h_css: csv?.maxHeight,
            };
        });
    });
    console.log("== GeoGebra wrap diagnostics ==");
    dims.forEach((d, i) => console.log(`#${i}:`, JSON.stringify(d, null, 2)));

    // Espande eventuali problem collapsible
    await page.evaluate(() => {
        document.querySelectorAll(".fm-groupcollex.fm-collapsible:not(.active)").forEach(p => p.click?.());
        document.querySelectorAll(".fm-collapsible:not(.active)").forEach(p => p.classList.add("active"));
    });
    await page.waitForTimeout(400);

    // Verifica se onInit è stato chiamato e se la function esiste
    const initState = await page.evaluate(() => {
        return {
            FM_keys: Object.keys(window.FM || {}),
            fnPresent: typeof window.FM?.fixGeoGebraSvgViewBox,
            ggbWrapsCount: document.querySelectorAll(".fm-geogebra-wrap").length,
            svgsBeforeFix: Array.from(document.querySelectorAll(".fm-geogebra-wrap svg")).map(s => ({
                hasViewBox: s.hasAttribute("viewBox"),
                w: s.getAttribute("width"), h: s.getAttribute("height"),
            })),
        };
    });
    console.log("Init state:", JSON.stringify(initState, null, 2));

    const innerSvg = await page.evaluate(() => {
        const w = document.querySelector(".fm-geogebra-wrap");
        const svg = w?.querySelector("svg");
        return {
            wrap_visible: w ? !!(w.offsetWidth && w.offsetHeight) : false,
            svg_innerHTML_len: svg?.innerHTML?.length || 0,
            svg_first_child_tag: svg?.firstElementChild?.tagName || "",
            svg_has_g_paths: svg ? svg.querySelectorAll("path,line,rect,text").length : 0,
            svg_outerHTML_first200: svg?.outerHTML?.substring(0, 300) || "",
            svg_viewBox_attr: svg?.getAttribute("viewBox") || "MISSING",
            svg_preserveAspect: svg?.getAttribute("preserveAspectRatio") || "MISSING",
        };
    });
    console.log("== SVG content check ==", JSON.stringify(innerSvg, null, 2));

    // Apri TUTTI i collapsible affinché il wrap sia in viewport visibile
    await page.evaluate(() => {
        document.querySelectorAll(".fm-collapsible").forEach(c => c.classList.add("active"));
        document.querySelectorAll(".fm-groupcollex .toggleSign").forEach(s => {
            if (s.textContent === "+") s.click();
        });
    });
    await page.waitForTimeout(1000);
    // Forza un re-fix dopo expand (se SVG appena renderizzato)
    await page.evaluate(() => window.FM?.fixGeoGebraSvgViewBox?.(document));
    await page.waitForTimeout(300);
    // Screenshot del wrap per verifica visiva
    const wrap = page.locator(".fm-geogebra-wrap").first();
    await wrap.scrollIntoViewIfNeeded();
    await page.waitForTimeout(500);
    // FullPage include lo scroll completo → mostra il wrap effettivo
    await page.screenshot({ path: "test-results/ggb-fullpage.png", fullPage: true });
    await wrap.screenshot({ path: "test-results/ggb-wrap-rendered.png" });

    // Diagnosi visibilità: posizione + opacity del wrap e del SVG
    const visDebug = await page.evaluate(() => {
        const w = document.querySelector(".fm-geogebra-wrap");
        const svg = w?.querySelector("svg");
        const r = w?.getBoundingClientRect();
        const cs = w ? getComputedStyle(w) : null;
        const svgCs = svg ? getComputedStyle(svg) : null;
        // Trova primi child con stroke colorato
        const colored = svg ? Array.from(svg.querySelectorAll("[stroke]")).filter(e => {
            const s = e.getAttribute("stroke");
            return s && s !== "none" && s !== "transparent";
        }).slice(0, 5).map(e => ({ tag: e.tagName, stroke: e.getAttribute("stroke"), fill: e.getAttribute("fill") })) : [];
        return {
            wrap_rect: r ? { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) } : null,
            wrap_visibility: cs?.visibility,
            wrap_opacity: cs?.opacity,
            wrap_overflow: cs?.overflow,
            svg_visibility: svgCs?.visibility,
            svg_opacity: svgCs?.opacity,
            colored_first5: colored,
        };
    });
    console.log("VIS:", JSON.stringify(visDebug, null, 2));

    // Aggiunge bordo rosso + scroll esplicito al wrap
    await page.evaluate(() => {
        const w = document.querySelector(".fm-geogebra-wrap");
        if (w) {
            w.style.border = "3px solid red";
            w.style.background = "yellow";
            w.scrollIntoView({ block: "center" });
        }
    });
    await page.waitForTimeout(500);
    // Screenshot esplicito coordinate del wrap
    const box = await page.evaluate(() => {
        const w = document.querySelector(".fm-geogebra-wrap");
        if (!w) return null;
        const r = w.getBoundingClientRect();
        return { x: r.x, y: r.y, w: r.width, h: r.height };
    });
    console.log("Wrap box for screenshot:", JSON.stringify(box));
    if (box) {
        await page.screenshot({
            path: "test-results/ggb-wrap-bordered.png",
            clip: { x: Math.max(0, box.x - 20), y: Math.max(0, box.y - 20),
                    width: box.w + 40, height: box.h + 40 },
        });
    }
    // Forza un viewport-wide screenshot
    await page.screenshot({ path: "test-results/ggb-viewport.png" });
    // Estrai HTML del .fm-collection contenitore
    const collexHtml = await page.evaluate(() => {
        const w = document.querySelector(".fm-geogebra-wrap");
        const collex = w?.closest(".fm-collection");
        return collex ? {
            collexClass: collex.className,
            collexInnerHTML_first1000: collex.innerHTML.substring(0, 1000),
            collexRect: (() => { const r = collex.getBoundingClientRect(); return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) }; })(),
            wrapRect: (() => { const r = w.getBoundingClientRect(); return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) }; })(),
        } : null;
    });
    console.log("COLLEX:", JSON.stringify(collexHtml, null, 2));
});
