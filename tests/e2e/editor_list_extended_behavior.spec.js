/**
 * Logica wysiwyg ESTESA per inserimento lista:
 *   - Caret in testo grezzo nel field root (no wrapper) → tutta la riga
 *     fra <br> boundary diventa <li>
 *   - Selezione che attraversa 2 righe diverse (anche solo pezzi) → 2 <li>
 *   - Undo (Ctrl+Z) ripristina lo stato pre-insertList
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

async function login(page) {
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
    });
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.locator("button[type=submit]").first().click(),
    ]);
    await page.goto("/?home=1", { waitUntil: "networkidle" });
}

test("Caso A: caret in testo grezzo (no wrapper) → tutta la riga diventa <li>", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        // Testo grezzo: due righe separate da <br>, no <div> wrapper
        field.innerHTML = "Riga uno con testo<br>Riga due<br>Riga tre";
        // Caret in mezzo a "Riga uno" (offset 5 nel primo text node)
        const firstText = field.firstChild;
        const range = document.createRange();
        range.setStart(firstText, 5);
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        field.focus();

        window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, "ol");

        const ol = field.querySelector("ol.fm-dsa-li-list");
        return {
            html: field.innerHTML,
            olLiCount: ol?.querySelectorAll("li").length || 0,
            firstLiText: ol?.querySelector("li")?.textContent || "",
            // Riga 2 e 3 ancora presenti FUORI dalla lista
            hasRiga2OutsideList: field.innerHTML.includes("Riga due"),
            hasRiga3OutsideList: field.innerHTML.includes("Riga tre"),
        };
    });
    expect(r.olLiCount, "1 li per la riga corrente").toBe(1);
    expect(r.firstLiText.trim(), "li contiene 'Riga uno con testo' integralmente").toBe("Riga uno con testo");
    expect(r.hasRiga2OutsideList, "Riga 2 ancora nel field (fuori lista)").toBe(true);
    expect(r.hasRiga3OutsideList, "Riga 3 ancora nel field (fuori lista)").toBe(true);
});

test("Caso B: selezione attraversa 2 righe (testo grezzo) → 2 <li>", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.innerHTML = "Prima riga<br>Seconda riga<br>Terza riga";
        // Selezione che parte da "Prima" (offset 2) e arriva a "Seconda" (offset 4)
        const textNodes = Array.from(field.childNodes).filter((n) => n.nodeType === 3);
        const range = document.createRange();
        range.setStart(textNodes[0], 2);
        range.setEnd(textNodes[1], 4);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        field.focus();

        window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, "ul");

        const ul = field.querySelector("ul.fm-dsa-li-list");
        const lis = ul ? Array.from(ul.querySelectorAll("li")) : [];
        return {
            ulCount: field.querySelectorAll("ul").length,
            liCount: lis.length,
            liTexts: lis.map((li) => li.textContent.trim()),
            terzaRimasta: field.innerHTML.includes("Terza riga"),
        };
    });
    expect(r.ulCount).toBe(1);
    expect(r.liCount, "2 li per le 2 righe attraversate").toBe(2);
    expect(r.liTexts[0], "primo li ha la riga INTERA, non solo la selezione").toBe("Prima riga");
    expect(r.liTexts[1], "secondo li ha la riga INTERA").toBe("Seconda riga");
    expect(r.terzaRimasta, "Terza riga preserved").toBe(true);
});

test("Caso C: undo Ctrl+Z dopo insertList ripristina stato pre-azione", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(async () => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.innerHTML = "Stato iniziale";
        // Force initial save
        window.FM.__UndoManagerForTest.save(field);

        const range = document.createRange();
        range.selectNodeContents(field);
        range.collapse(false);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        field.focus();

        // Insert list
        window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, "ol");
        const afterInsert = field.innerHTML;

        // Simula Ctrl+Z
        const undone = window.FM.__UndoManagerForTest.undo(field);
        const afterUndo = field.innerHTML;

        // Redo
        const redone = window.FM.__UndoManagerForTest.redo(field);
        const afterRedo = field.innerHTML;

        return {
            afterInsert,
            afterUndo,
            afterRedo,
            undone,
            redone,
        };
    });
    expect(r.undone, "undo() returns true").toBe(true);
    expect(r.afterInsert, "after insert ha <ol>").toMatch(/<ol[^>]*class="fm-dsa-li-list"/);
    expect(r.afterUndo, "dopo undo NIENTE <ol>").not.toMatch(/<ol[^>]*class="fm-dsa-li-list"/);
    expect(r.afterUndo, "dopo undo torna 'Stato iniziale'").toContain("Stato iniziale");
    expect(r.redone, "redo() returns true").toBe(true);
    expect(r.afterRedo, "redo riporta lo <ol>").toMatch(/<ol[^>]*class="fm-dsa-li-list"/);
});

test("Caso E: lista deve apparire IN POSIZIONE delle righe selezionate (non in fondo)", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        // 5 righe: a / b / c / d / s
        field.innerHTML = "a<br>b<br>c<br>d<br>s";

        // Selezione su b+c (righe 1 e 2)
        const textNodes = Array.from(field.childNodes).filter((n) => n.nodeType === 3);
        const range = document.createRange();
        range.setStart(textNodes[1], 0);  // inizio "b"
        range.setEnd(textNodes[2], 1);    // fine "c"
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        field.focus();

        window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, "ol");

        const ol = field.querySelector("ol.fm-dsa-li-list");
        const lis = ol ? Array.from(ol.querySelectorAll("li")) : [];

        // Ordine atteso nel field: a, <ol>b, c</ol>, d, s
        // Itera children top-level del field
        const topChildren = Array.from(field.childNodes).map((n) => {
            if (n.nodeType === Node.TEXT_NODE) return { kind: "text", text: n.textContent };
            if (n.tagName === "BR") return { kind: "br" };
            if (n.tagName === "OL") return { kind: "ol", liCount: n.querySelectorAll("li").length };
            return { kind: n.tagName };
        });

        return {
            liCount: lis.length,
            liTexts: lis.map((l) => l.textContent.trim()),
            topChildren,
        };
    });

    expect(r.liCount, "2 li per b+c").toBe(2);
    expect(r.liTexts).toEqual(["b", "c"]);
    // L'<ol> deve apparire IN MEZZO al field, non in fondo
    const olIdx = r.topChildren.findIndex((c) => c.kind === "ol");
    const sIdx = r.topChildren.findIndex((c) => c.kind === "text" && c.text === "s");
    expect(olIdx, "ol esiste").toBeGreaterThan(-1);
    expect(sIdx, "'s' esiste").toBeGreaterThan(-1);
    expect(olIdx, "ol PRIMA di 's' (in mezzo)").toBeLessThan(sIdx);

    // Verifica anche che 'a' e 'd' siano FUORI dalla lista, prima e dopo
    const aIdx = r.topChildren.findIndex((c) => c.kind === "text" && c.text === "a");
    const dIdx = r.topChildren.findIndex((c) => c.kind === "text" && c.text === "d");
    expect(aIdx, "'a' presente").toBeGreaterThanOrEqual(0);
    expect(dIdx, "'d' presente").toBeGreaterThanOrEqual(0);
    expect(aIdx, "'a' prima della lista").toBeLessThan(olIdx);
    expect(dIdx, "'d' dopo la lista").toBeGreaterThan(olIdx);
});

test("Caso D: keyboard shortcut Ctrl+Z su contenteditable triggers undo", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(async () => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.innerHTML = "Iniziale";
        window.FM.__UndoManagerForTest.save(field);

        const range = document.createRange();
        range.selectNodeContents(field);
        range.collapse(false);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        field.focus();

        window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, "ol");
        const after = field.innerHTML;

        // Dispatch Ctrl+Z keyboard event
        field.dispatchEvent(new KeyboardEvent("keydown", {
            key: "z",
            ctrlKey: true,
            bubbles: true,
            cancelable: true,
        }));

        return {
            after,
            afterCtrlZ: field.innerHTML,
        };
    });
    expect(r.after, "after insert ha <ol>").toMatch(/<ol[^>]*class="fm-dsa-li-list"/);
    expect(r.afterCtrlZ, "Ctrl+Z ripristina stato pre-insert").toContain("Iniziale");
    expect(r.afterCtrlZ, "Ctrl+Z rimuove <ol>").not.toMatch(/<ol[^>]*class="fm-dsa-li-list"/);
});
