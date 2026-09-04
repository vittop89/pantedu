/**
 * G22.S25 — Browser-level: Marco apre l'esercizio recuperato "Sistemi lineari
 * (importata da {{OPERATORE_NOME}})" e DEVE vedere la verifica correlata
 * iniettata in #type_verAll.
 *
 * Diagnostico (user feedback: "non vedo niente"):
 *  - Cattura console errors, network 4xx/5xx
 *  - Verifica risposta API /api/study/related-verifiche.html lato browser
 *  - Verifica DOM finale (#type_verAll + .fm-contract-wrap data-id=2738)
 *  - Salva screenshot e snapshot HTML in caso di failure
 */
import { test, expect } from "@playwright/test";
import fs from "fs";
import path from "path";

const MARCO_USER = "marco.rossi";
const MARCO_PASS = (process.env.E2E_TEACHER_PASS || "");

async function dismissCookies(page) {
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
    });
}

async function login(page, username, password) {
    await page.goto("/login");
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', password);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);
}

test("Marco vede verifica correlata in #type_verAll su Sistemi lineari recuperata", async ({ page }) => {
    const consoleErrors = [];
    const networkErrors = [];
    const apiResponses = [];

    page.on("console", (m) => {
        if (m.type() === "error") consoleErrors.push(m.text());
    });
    page.on("response", async (r) => {
        const url = r.url();
        if (url.includes("/api/study/related-verifiche.html")) {
            apiResponses.push({
                url,
                status: r.status(),
                body: await r.text().catch(() => "<read_failed>"),
            });
        }
        if (url.includes("Elementi_Riservati.html")) {
            console.log(`→ Elementi_Riservati response: ${r.status()} ${url}`);
        }
        if (r.status() >= 400) {
            networkErrors.push(`${r.status()} ${url}`);
        }
    });

    await dismissCookies(page);
    await login(page, MARCO_USER, MARCO_PASS);

    // Trova id contenuto Marco "Sistemi lineari (importata...)" via API
    const listRes = await page.request.get(
        "/api/study/content.json?type=esercizio&subject=MAT&indirizzo=SCI&classe=2"
    );
    expect(listRes.ok(), `content.json failed: ${listRes.status()}`).toBeTruthy();
    const listJson = await listRes.json();
    const rows = listJson.rows || [];
    const marcoSistemi = rows.find((r) =>
        /Sistemi lineari/.test(r.title) && r.teacher_id === 140
    );
    expect(marcoSistemi, `nessun Sistemi lineari per Marco. Rows: ${rows.map(r => `#${r.id} t=${r.teacher_id} "${r.title}"`).join("; ")}`).toBeTruthy();

    // Naviga alla pagina /studio/esercizio/SCI/2/MAT/{topic}
    // Il topic in DB è "2.0" → topic-page URL usa raw topic
    // Try multiple URL forms: il routing topicPage usa il topic raw
    const topicSlug = encodeURIComponent("2.0");
    const url = `/studio/esercizio/SCI/2/MAT/${topicSlug}`;
    console.log("→ navigating to:", url);
    await page.goto(url, { waitUntil: "domcontentloaded" });

    // Attendi che il main renderer monti almeno un contract di esercizio
    await page.waitForSelector(".fm-contract-render", { timeout: 15000 });

    // Dump titles presenti sulla pagina
    const titles = await page.$$eval(
        ".fm-contract-render .fm-titolo h1, .fm-pagestyle .fm-titolo h1",
        (els) => els.map((e) => e.textContent?.trim())
    );
    console.log("→ esercizio titles sulla pagina:", titles);

    // G22.S25.diag — wait per .selector-eser injection (async via $.get)
    await page.waitForSelector(".fm-topbar__zone--eser .selector-eser", { timeout: 8000 }).catch(() => {});

    // G22.S25.diag — dump topbar state per debug "fm-topbar__zone--eser non c'è"
    const topbarState = await page.evaluate(() => {
        const tb = document.getElementById("fm-topbar");
        const eserZone = document.querySelector(".fm-topbar__zone--eser");
        const selectorEser = document.querySelector(".fm-selector-eser");
        const upbar = document.querySelector(".fm-upbar");
        return {
            bodyClasses: [...document.body.classList],
            bodyHTML: document.body.className,
            topbarPresent: !!tb,
            topbarHidden: tb?.hidden,
            topbarAdmin: tb?.dataset?.fmAdmin,
            eserZonePresent: !!eserZone,
            eserZoneHTML: eserZone ? eserZone.innerHTML.slice(0, 200) : null,
            selectorEserPresent: !!selectorEser,
            upbarPresent: !!upbar,
            upbarHTML: upbar ? upbar.outerHTML.slice(0, 300) : null,
            hasProblem: !!document.querySelector(".fm-groupcollex"),
            hasFmDbStudy: !!document.querySelector(".fm-db-study"),
        };
    });
    console.log("→ TOPBAR STATE:");
    console.log(JSON.stringify(topbarState, null, 2));

    // Verifica-builder dovrebbe chiamare /api/study/related-verifiche.html?subject=MAT&title=...
    // Attendi la risposta (max 10s) — se non chiama, è bug del front-end
    await page.waitForFunction(
        () => !!document.getElementById("type_verAll"),
        { timeout: 15000 }
    ).catch(() => {});

    // Log che cosa è arrivato dall'API
    console.log("→ API calls a related-verifiche.html:", apiResponses.length);
    for (const r of apiResponses) {
        console.log(`  status=${r.status} url=${r.url}`);
        console.log(`  body (first 500): ${r.body.slice(0, 500)}`);
    }
    console.log("→ network errors 4xx/5xx:", networkErrors);
    console.log("→ console errors:", consoleErrors);

    // Verifica finale DOM
    const typeVerAll = await page.$("#type_verAll");
    if (!typeVerAll) {
        // Salva snapshot di diagnosi
        const html = await page.content();
        const dir = "tests/e2e-results";
        fs.mkdirSync(dir, { recursive: true });
        fs.writeFileSync(path.join(dir, "marco_no_typeverall.html"), html);
        await page.screenshot({ path: path.join(dir, "marco_no_typeverall.png"), fullPage: true });
        throw new Error("Nessun #type_verAll renderizzato. HTML+PNG salvati in tests/e2e-results/");
    }

    const wraps = await page.$$("#type_verAll .fm-contract-wrap[data-id]");
    const wrapIds = [];
    for (const w of wraps) {
        wrapIds.push(await w.getAttribute("data-id"));
    }
    console.log("→ #type_verAll .fm-contract-wrap ids:", wrapIds);

    expect(wraps.length, "almeno una verifica correlata renderizzata").toBeGreaterThan(0);
    expect(wrapIds, "wrap deve includere id 2738 (verifica di Marco)").toContain("2738");
});
