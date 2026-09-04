/**
 * G19.49 — Diagnostic E2E: investiga perche' le sidepage Mappe/Verifiche
 * non mostrano link per il docente superadmin.
 */

const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("diag: sidepage mappe content rendering", async ({ page }) => {
    // Cattura console + errori
    const logs = [];
    page.on("console", (msg) => logs.push(`[${msg.type()}] ${msg.text()}`));
    page.on("pageerror", (err) => logs.push(`[pageerror] ${err.message}`));

    // Cattura network: API content endpoints
    const apiCalls = [];
    page.on("response", async (resp) => {
        const url = resp.url();
        if (url.includes("/api/teacher/content") || url.includes("/api/study/content")) {
            try {
                const body = await resp.json();
                apiCalls.push({
                    url,
                    status: resp.status(),
                    rows: body.rows?.length ?? "no rows key",
                    sample: body.rows?.slice(0, 2),
                });
            } catch (_) {
                apiCalls.push({ url, status: resp.status(), error: "non-json" });
            }
        }
    });

    // Login
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator('button[type=submit], input[type=submit]').first().click();
    await page.waitForLoadState("networkidle");

    // Vai a una pagina con sidebar
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    // Stato iniziale
    const initialState = await page.evaluate(() => ({
        sessionStorage: {
            selectedIIS: sessionStorage.getItem("selectedIIS"),
            selectedCLS: sessionStorage.getItem("selectedCLS"),
            selectedMATER: sessionStorage.getItem("selectedMATER"),
        },
        domSelects: {
            ind: document.getElementById("sel-iis")?.value,
            cls: document.getElementById("sel-cls")?.value,
            mat: document.getElementById("sel-mater")?.value,
        },
        appState: window.AppState ? {
            iis: window.AppState.selectedIIS,
            cls: window.AppState.selectedCLS,
            mat: window.AppState.selectedMATER,
        } : null,
        domSelectOptions: {
            cls: Array.from(document.getElementById("sel-cls")?.options || [])
                .filter(o => !o.disabled)
                .map(o => ({ value: o.value, text: o.text.trim().slice(0, 30) })),
        },
    }));
    console.log("INITIAL STATE:", JSON.stringify(initialState, null, 2));

    // Forza la selezione corretta come farebbe l'utente
    await page.selectOption("#sel-iis", "sc");
    await page.selectOption("#sel-cls", "2");
    await page.selectOption("#sel-mater", "MAT");

    const afterSelect = await page.evaluate(() => ({
        selectedIIS: sessionStorage.getItem("selectedIIS"),
        selectedCLS: sessionStorage.getItem("selectedCLS"),
        selectedMATER: sessionStorage.getItem("selectedMATER"),
        ind: document.getElementById("sel-iis")?.value,
        cls: document.getElementById("sel-cls")?.value,
        mat: document.getElementById("sel-mater")?.value,
    }));
    console.log("AFTER MANUAL SELECT:", JSON.stringify(afterSelect, null, 2));

    // Hide overlay che potrebbe intercettare click
    await page.evaluate(() => {
        const o = document.getElementById("fm-modal-overlay");
        if (o) o.style.display = "none";
        // Chiudi eventuali modal aperti
        document.querySelectorAll('.fm-modal-close, [data-action="close-modal"]')
            .forEach(b => b.click());
    });
    await page.waitForTimeout(500);

    // Trigger esplicito reload sidepage via FM API
    const triggerResult = await page.evaluate(async () => {
        const fm = window.FM;
        const api = fm?.DbSidepage || fm?.SidepageRegistry;
        const exposed = Object.keys(fm || {}).filter(k => /sidepage|db/i.test(k));
        return { fmKeys: Object.keys(fm || {}).slice(0, 20), exposedSP: exposed };
    });
    console.log("FM API:", JSON.stringify(triggerResult, null, 2));

    // Trigger sidepage Mappe (clicca .fm-sb-sec con data-sidepage="mappe")
    const mappeBtn = page.locator('.fm-sb-sec[data-sidepage="mappe"]').first();
    if (await mappeBtn.count()) {
        await mappeBtn.click({ force: true });
        await page.waitForTimeout(3000);

        const sidepage = await page.evaluate(() => {
            const sp = document.getElementById("fm-sp-mappe");
            if (!sp) return { found: false };
            return {
                found: true,
                visible: sp.offsetHeight > 0,
                display: getComputedStyle(sp).display,
                childCount: sp.children.length,
                innerSnippet: sp.outerHTML.slice(0, 2500),
                hasItems: !!sp.querySelector("li[data-content-id]"),
                itemCount: sp.querySelectorAll("li[data-content-id]").length,
                hasBlock: !!sp.querySelector(".fm-db-block"),
                hasError: !!sp.querySelector(".fm-error"),
                errorText: sp.querySelector(".fm-error")?.textContent,
            };
        });
        console.log("MAPPE SIDEPAGE:", JSON.stringify(sidepage, null, 2));
    } else {
        console.log("ERROR: button [data-sidepage=mappe] not found");
        const sbButtons = await page.evaluate(() => {
            return Array.from(document.querySelectorAll("[data-sidepage]"))
                .map(b => ({ tag: b.tagName, key: b.dataset.sidepage, txt: b.textContent.trim().slice(0, 30) }));
        });
        console.log("Available data-sidepage elements:", sbButtons);
    }

    console.log("=== API CALLS ===");
    apiCalls.forEach(c => console.log(JSON.stringify(c)));

    console.log("=== CONSOLE LOGS (sample) ===");
    logs.slice(-30).forEach(l => console.log(l));
});
