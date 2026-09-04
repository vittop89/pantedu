/** Diagnose: errore querySelector all'apertura editor templates. */
const { test, expect } = require("@playwright/test");

const USERNAME = "superadmin";
const PASSWORD = (process.env.E2E_TEACHER_PASS || "");

test("Open /area-docente/templates → trace error", async ({ page }) => {
    test.setTimeout(60000);

    const errors = [];
    page.on("pageerror", e => errors.push({ msg: e.message, stack: e.stack }));
    page.on("console", msg => {
        if (msg.type() === "error" || msg.text().includes("error") || msg.text().includes("failed")) {
            console.log("[browser-console]:", msg.type(), msg.text());
        }
    });

    page.on("dialog", async d => {
        console.log("[dialog]:", d.message());
        // Stack trace dell'errore: dump current JS errors prima di accettare
        await d.dismiss().catch(() => {});
    });

    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    // Init script: hook PRIMA del load della pagina
    await page.addInitScript(() => {
        window.__fmTrace = [];
        const orig = window.alert;
        window.alert = (msg) => {
            window.__fmTrace.push({ alert: msg, stack: new Error().stack });
            // non chiamare orig per evitare blocchi
        };
        const ce = console.error;
        console.error = (...args) => {
            window.__fmTrace.push({ console_error: args.map(a => {
                if (a instanceof Error) return `${a.message}\n${a.stack}`;
                return String(a);
            }).join(" | ") });
            ce(...args);
        };
        // Catch unhandled errors with stack
        window.addEventListener("error", (e) => {
            window.__fmTrace.push({ error: e.message, stack: e.error?.stack, file: e.filename, line: e.lineno, col: e.colno });
        });
    });

    // PATH UTENTE: visita una pagina con topbar attivo (esercizio), poi clicca
    // il bottone "Editor" che apre l'iframe modale a /area-docente/templates
    await page.goto("/studio/esercizio/sc/3/MAT/2");
    await page.waitForLoadState("networkidle").catch(() => {});
    await page.waitForTimeout(1500);

    // Click bottone Editor in topbar
    const clicked = await page.evaluate(() => {
        const btn = document.querySelector('[data-fm-action="editor"]');
        if (!btn) return "NO_BTN";
        btn.click();
        return "CLICKED";
    });
    console.log("Editor btn:", clicked);
    await page.waitForTimeout(4000);  // attesa apertura iframe + auto-open editor inside
    const traceState = await page.evaluate(() => {
        const iframe = document.querySelector('.fm-vd-templates-iframe, iframe');
        let iframeTrace = null, iframeAlerts = null;
        if (iframe?.contentWindow) {
            try {
                iframeTrace = iframe.contentWindow.__fmTrace || [];
                iframeAlerts = (iframeTrace || []).filter(t => t.alert);
            } catch (e) { iframeTrace = "X-ORIGIN-BLOCK: " + e.message; }
        }
        return {
            trace: window.__fmTrace,
            iframePresent: !!iframe,
            iframeSrc: iframe?.src,
            iframeTrace, iframeAlerts,
            modalOpen: !!document.querySelector(".fm-vp-modal"),
            iframeModalOpen: iframe?.contentDocument?.querySelector(".fm-vp-modal") ? true : null,
            FM_keys: Object.keys(window.FM || {}).filter(k => k.toLowerCase().includes("preview")),
        };
    });
    console.log("State:", JSON.stringify(traceState, null, 2));
    const trace = traceState.trace;
    console.log("== Frontend trace ==");
    trace?.forEach((t, i) => {
        console.log(`#${i}:`, JSON.stringify(t, null, 2).substring(0, 1500));
    });

    console.log("== ERRORS DURING OPEN ==");
    errors.forEach((e, i) => {
        console.log(`#${i}:`, e.msg);
        console.log(`Stack:`, e.stack?.substring(0, 1500));
    });
});
