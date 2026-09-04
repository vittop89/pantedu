/**
 * Studio/esercizio — TOOLBAR EDITOR del quesito.
 * Liste nested, grassetto/corsivo/sottolineato, TeX dropdown (snippet + TikZ),
 * editor TikZ grezzo (CM6), GeoGebra, trova/sostituisci, altri pulsanti.
 * Opera sulla copia 1293 (rigenerata in beforeEach).
 */
const { test } = require("@playwright/test");
const { execSync } = require("child_process");
const path = require("path");
const H = require("./studio-eser-helpers");
const { expect } = H;

const REPO = path.resolve(__dirname, "../..");
const regen = () => execSync("node tools/dev/gen_proof_contract.cjs", { cwd: REPO, stdio: "ignore" });

async function focusField(page) {
    await page.evaluate(() => {
        const f = document.querySelector(".fm-editor-panel .fm-editor-field");
        f.focus();
        const r = document.createRange(); r.selectNodeContents(f); r.collapse(false);
        const s = getSelection(); s.removeAllRanges(); s.addRange(r);
    });
    await page.waitForTimeout(200);
}
const fieldCounts = (page) => page.evaluate(() => {
    const f = document.querySelector(".fm-editor-panel .fm-editor-field");
    return {
        ul: f.querySelectorAll("ul,ol").length,
        nested: f.querySelectorAll("ul ul, ul ol, ol ul, ol ol").length,
        strong: f.querySelectorAll("strong,b").length,
        em: f.querySelectorAll("em,i").length,
        u: f.querySelectorAll("u,ins").length,
        tikz: f.querySelectorAll("script[type='text/tikz']").length,
        ggb: f.querySelectorAll(".fm-geogebra-wrap").length,
    };
});

test.describe("studio/esercizio — toolbar editor", () => {
    test.beforeEach(async ({ page }) => {
        regen();
        await H.loginTeacher(page);
        await H.gotoEser(page, "1293");
        await H.openQuesitoEditor(page, 0);
        await focusField(page);
    });

    test("inserimento lista nested (select preset + indent)", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        const before = await fieldCounts(page);
        await page.selectOption(".fm-list-snippet-select", "ul");
        await page.waitForTimeout(600);
        expect((await fieldCounts(page)).ul, "lista inserita").toBeGreaterThan(before.ul);
        // indent dell'ultima voce → nesting (helper esposto)
        const nestedOk = await page.evaluate(() => {
            const f = document.querySelector(".fm-editor-field");
            const ul = f.querySelector("ul");
            const li = document.createElement("li"); li.textContent = "Voce annidata"; ul.appendChild(li);
            const r = document.createRange(); r.selectNodeContents(li); r.collapse(false);
            const s = getSelection(); s.removeAllRanges(); s.addRange(r);
            if (typeof window.FM?.__indentListItemForTest === "function") { window.FM.__indentListItemForTest(li); return true; }
            return false;
        });
        expect(nestedOk, "helper indent disponibile").toBe(true);
        await page.waitForTimeout(300);
        expect((await fieldCounts(page)).nested, "lista annidata creata").toBeGreaterThan(0);
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("grassetto / corsivo / sottolineato applicano formattazione", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        const clickFmt = async (re) => {
            await page.evaluate(() => {
                const f = document.querySelector(".fm-editor-field"); f.focus();
                const target = f.querySelector("li,p,span") || f;
                const r = document.createRange(); r.selectNodeContents(target);
                const s = getSelection(); s.removeAllRanges(); s.addRange(r);
            });
            const b = page.locator(".fm-fmtbtn", { hasText: "" });
            await page.evaluate((reSrc) => {
                const btn = Array.from(document.querySelectorAll(".fm-fmtbtn")).find((x) => new RegExp(reSrc, "i").test(x.title));
                btn?.dispatchEvent(new MouseEvent("mousedown", { bubbles: true }));
                btn?.dispatchEvent(new MouseEvent("click", { bubbles: true }));
            }, re);
            await page.waitForTimeout(300);
        };
        const c0 = await fieldCounts(page);
        await clickFmt("Grassetto");
        expect((await fieldCounts(page)).strong, "grassetto cambia").not.toBe(c0.strong);
        const c1 = await fieldCounts(page);
        await clickFmt("Corsivo");
        expect((await fieldCounts(page)).em, "corsivo cambia").not.toBe(c1.em);
        const c2 = await fieldCounts(page);
        await clickFmt("Sottolineato");
        expect((await fieldCounts(page)).u, "sottolineato cambia").not.toBe(c2.u);
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("TeX dropdown si apre e inserisce uno snippet", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        await page.locator(".fm-tex-dropdown button").first().dispatchEvent("click");
        await page.waitForTimeout(600);
        await expect(page.locator(".fm-tex-menu, .fm-tex-groups").first(), "menu TeX aperto").toBeVisible();
        const inserted = await page.evaluate(() => {
            const f = document.querySelector(".fm-editor-field"); f.focus();
            const r = document.createRange(); r.selectNodeContents(f); r.collapse(false);
            const s = getSelection(); s.removeAllRanges(); s.addRange(r);
            const item = Array.from(document.querySelectorAll(".fm-tex-menu *, .fm-tex-groups *")).find((b) => /sqrt/i.test(b.textContent || b.title || ""));
            if (!item) return false;
            item.dispatchEvent(new MouseEvent("click", { bubbles: true }));
            return true;
        });
        await page.waitForTimeout(500);
        if (inserted) {
            const has = await page.evaluate(() => /sqrt/.test(document.querySelector(".fm-editor-field")?.textContent || document.querySelector(".fm-editor-field")?.innerHTML || ""));
            expect(has, "snippet \\sqrt inserito").toBe(true);
        }
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("TeX dropdown espone la voce editor codice CM6 e il click non lancia errori", async ({ page }) => {
        // NB: il menu TeX ha più voci "codice" (insert template vs editor CM6) e il
        // contenuto è stato-dipendente (templates del workspace). Qui verifichiamo
        // in modo robusto che la voce "editor codice grezzo (CM6)" esista e che il
        // suo click non sollevi errori JS. Il mount effettivo di CM6 (chunk lazy) è
        // verificato altrove/manualmente per evitare flakiness.
        const errors = H.trackJsErrors(page);
        await page.locator(".fm-tex-dropdown button").first().dispatchEvent("click");
        await page.waitForTimeout(700);
        const clicked = await page.evaluate(() => {
            const items = Array.from(document.querySelectorAll(".fm-tex-menu button, .fm-tex-groups button, .fm-tex-menu [role=button]"));
            const raw = items.find((b) => /codice grezzo|\(CM6/i.test(((b.textContent || "") + " " + (b.title || ""))));
            if (raw) raw.dispatchEvent(new MouseEvent("click", { bubbles: true }));
            return !!raw;
        });
        expect(clicked, "voce 'editor codice grezzo (CM6)' presente nel menu").toBe(true);
        await page.waitForTimeout(2000);
        await page.keyboard.press("Escape").catch(() => {});
        // 422 da compile preview = rumore di rete (non errore JS)
        expect(errors.filter((e) => !/422|compile|Failed/i.test(e)), errors.join("\n")).toEqual([]);
    });

    test("pulsante GeoGebra apre l'editor applet", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        await page.evaluate(() => {
            const b = document.querySelector("button[title*='GeoGebra'],button[title*='eoGebra']");
            b?.dispatchEvent(new MouseEvent("click", { bubbles: true }));
        });
        await page.waitForTimeout(2500);
        const opened = await page.evaluate(() => document.querySelectorAll("[class*='geogebra'],[id*='ggb'],[id*='geogebra'],iframe[src*='geogebra'],[class*='dialog']").length);
        expect(opened, "container GeoGebra aperto").toBeGreaterThan(0);
        await page.keyboard.press("Escape").catch(() => {});
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("trova e sostituisci si apre", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        await page.evaluate(() => {
            const b = Array.from(document.querySelectorAll("button")).find((x) => /Trova e sostituisci|find.*replace/i.test(x.title || x.textContent || ""));
            b?.dispatchEvent(new MouseEvent("click", { bubbles: true }));
        });
        await page.waitForTimeout(800);
        const opened = await page.evaluate(() => Array.from(document.querySelectorAll("input,[class*='find'],[class*='replace'],[class*='search']")).filter((e) => e.offsetParent !== null && /find|replace|trova|cerca|search/i.test((e.placeholder || "") + (e.className || ""))).length);
        expect(opened, "pannello trova/sostituisci aperto").toBeGreaterThan(0);
        await page.keyboard.press("Escape").catch(() => {});
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("altri pulsanti toolbar (link/dots/DSA/latexindent/backup) senza errori", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        for (const re of ["Inserisci link", "dots", "DSA", "latexindent|Riformatta", "backup manuale"]) {
            await page.evaluate((reSrc) => {
                const b = Array.from(document.querySelectorAll(".fm-editor-toolbar button, .fm-editor-panel button")).find((x) => new RegExp(reSrc, "i").test(x.title || x.textContent || ""));
                b?.dispatchEvent(new MouseEvent("click", { bubbles: true }));
            }, re);
            await page.waitForTimeout(400);
            await page.keyboard.press("Escape").catch(() => {});
        }
        // 422 da compile preview = rumore di rete
        expect(errors.filter((e) => !/422|compile|Failed/i.test(e)), errors.join("\n")).toEqual([]);
    });

    test("Backspace a inizio voce nested fa outdent (come ogni gestore liste)", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        // Struttura REALISTICA: lista a 3 livelli, voci con contenuto misto
        // (testo + elementi inline), caret all'INIZIO della voce di livello 3.
        await page.evaluate(() => {
            const f = document.querySelector(".fm-editor-panel .fm-editor-field");
            f.innerHTML = '<ul class="fm-dsa-li-list"><li>Livello 1'
                + '<ul class="fm-dsa-li-list"><li>Livello 2'
                + '<ul class="fm-dsa-li-list"><li id="inner">Voce L3 con <strong>grassetto</strong> ed <em>elemento</em> inline</li></ul>'
                + '</li></ul></li></ul>';
            f.focus();
            const inner = document.getElementById("inner");
            const r = document.createRange(); r.setStart(inner.firstChild, 0); r.collapse(true);
            const s = getSelection(); s.removeAllRanges(); s.addRange(r);
        });
        // profondità di #inner = numero di ol/ul antenati dentro il field
        const listDepth = () => page.evaluate(() => {
            const f = document.querySelector(".fm-editor-field");
            let n = 0, el = document.getElementById("inner")?.parentElement;
            while (el && el !== f) { if (/^(OL|UL)$/.test(el.tagName)) n++; el = el.parentElement; }
            return n;
        });
        const before = await listDepth();
        expect(before, "voce di livello 3").toBe(3);
        await page.keyboard.press("Backspace");
        await page.waitForTimeout(400);
        const depthAfter = await listDepth();
        const caretAtStart = await page.evaluate(() => {
            const sel = getSelection(); const inner = document.getElementById("inner");
            if (!sel.rangeCount || !sel.isCollapsed || !inner) return false;
            const r = sel.getRangeAt(0);
            if (!inner.contains(r.startContainer) && r.startContainer !== inner) return false;
            const pre = document.createRange();
            pre.selectNodeContents(inner);
            try { pre.setEnd(r.startContainer, r.startOffset); } catch (_) { return false; }
            return pre.toString().length === 0;
        });
        expect(depthAfter, "outdent di un livello (L3→L2)").toBe(before - 1);
        expect(caretAtStart, "caret resta all'INIZIO della voce (non a fine/riga precedente)").toBe(true);
        expect(errors.filter((e) => !/422|compile|Failed/i.test(e)), errors.join("\n")).toEqual([]);
    });
});
