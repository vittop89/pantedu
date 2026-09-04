/**
 * G23.fix6 — Diagnostic for double "a. a." marker in RM cell.
 *
 * Apre l'esercizio reale dell'utente (1?ids=65), entra in edit mode,
 * inserisce un list ol-alpha nella prima cella, ispeziona TUTTO il DOM
 * (cell editor + live preview) per identificare la fonte del double marker.
 */
const { test, expect } = require("@playwright/test");

const TEACHER_USER = "superadmin";
const TEACHER_PASS = (process.env.E2E_TEACHER_PASS || "");

test("DIAGNOSE: insert ol-alpha in cell → inspect double marker source", async ({ page }) => {
    test.setTimeout(120000);
    await page.addInitScript(() => {
        localStorage.setItem("user_cookie_consent_v2", JSON.stringify({
            functional: true, analytics: false, advertising: false, timestamp: Date.now(),
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER_USER);
    await page.fill('input[name="password"]', TEACHER_PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 15000 }),
        page.click('button[type="submit"]'),
    ]);

    // Naviga alla URL esatta
    await page.goto("/studio/esercizio/SCI/3/MAT/1?ids=65", { waitUntil: "networkidle" });
    await page.waitForTimeout(3000);

    // Trova RM item ed entra in edit mode
    const rmItem = page.locator(".fm-collection__item").filter({ has: page.locator(".fm-rm-table") }).first();
    const exists = await rmItem.count();
    test.skip(exists === 0, "Nessun RM item in pagina");

    // Click edit button via JS (evita pointer intercept)
    await page.evaluate(() => {
        const rmItems = Array.from(document.querySelectorAll(".fm-collection__item"))
            .filter(it => it.querySelector(".fm-rm-table"));
        const btn = rmItems[0]?.querySelector(".fm-single-modifica-btn");
        if (btn) btn.click();
    });
    await page.waitForSelector(".fm-editor-panel", { timeout: 10000 });
    await page.waitForTimeout(2000);

    // Snap iniziale
    await page.screenshot({ path: "tests/e2e/screenshots/g23/diag-01-editor-open.png", fullPage: false });

    // Click cell editor 0,0 via JS
    await page.evaluate(() => {
        const ta = document.querySelector('.fm-editor-field[data-field="rm-cell-r0c0"]');
        if (ta) {
            ta.focus();
            ta.innerHTML = "";
        }
    });
    await page.waitForTimeout(300);

    // Trova e click list dropdown ol-alpha
    // Il dropdown List ha class fm-list-dropdown probabilmente
    const dump1 = await page.evaluate(() => {
        const buttons = Array.from(document.querySelectorAll("button, select"));
        return buttons.map(b => ({
            tag: b.tagName,
            text: (b.textContent || "").trim().substring(0, 40),
            classList: b.className,
            id: b.id,
        })).filter(b => /list|ol|elenco/i.test(b.text + b.classList));
    });
    console.log("Possible list buttons:", JSON.stringify(dump1, null, 2));

    // Inserisci direttamente via API JS (più affidabile dello UI)
    await page.evaluate(() => {
        const ta = document.querySelector('.fm-editor-field[data-field="rm-cell-r0c0"]');
        if (ta) {
            ta.focus();
            // Simula insertListSnippet
            if (typeof window.FM?.__insertListSnippetForTest === "function") {
                const panel = ta.closest(".fm-editor-panel");
                if (panel) panel._focusedTextarea = ta;
                window.FM.__insertListSnippetForTest(panel || document.body, "ol-alpha");
            }
        }
    });
    await page.waitForTimeout(800);

    await page.screenshot({ path: "tests/e2e/screenshots/g23/diag-02-after-list-insert.png", fullPage: false });

    // Inspect cell editor + live preview cell
    const inspect = await page.evaluate(() => {
        const cellEditor = document.querySelector('.fm-editor-field[data-field="rm-cell-r0c0"]');
        const liveCell = document.querySelector(".fm-rm-tables-wrap td.rm-option[data-row='0'][data-col='0']");
        return {
            cellEditorHTML: cellEditor?.outerHTML?.substring(0, 1500),
            cellEditorInner: cellEditor?.innerHTML?.substring(0, 800),
            liveCellHTML: liveCell?.outerHTML?.substring(0, 2000),
            liveCellTextContent: liveCell?.textContent?.trim(),
            // Controllo: quante "a." appaiono testualmente?
            liveCellAMarkerCount: (liveCell?.textContent?.match(/a\./g) || []).length,
            liveCellMarkerSpans: liveCell?.querySelectorAll(".fm-dsa-li-num").length || 0,
            // ::marker pseudo support detection (Chrome 86+)
            liEffectiveDisplay: (() => {
                const li = liveCell?.querySelector("li");
                if (!li) return null;
                return getComputedStyle(li).display;
            })(),
            liListStyleType: (() => {
                const ol = liveCell?.querySelector("ol");
                if (!ol) return null;
                return getComputedStyle(ol).listStyleType;
            })(),
        };
    });
    console.log("=== INSPECT ===");
    console.log(JSON.stringify(inspect, null, 2));

    // Verifica esplicita
    if (inspect.liveCellAMarkerCount > 1) {
        console.log("🚨 DOUBLE MARKER DETECTED in live preview");
    } else {
        console.log("✓ Single marker in live preview");
    }
});
