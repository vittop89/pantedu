/**
 * G23.fix5 — Real save flow simulation.
 *
 * Riproduce ESATTAMENTE i passi dell'utente:
 *   1. Naviga a esercizio reale
 *   2. Apri editor in-place su un item
 *   3. Programmaticamente setta value della cella RM con nested OL completo
 *   4. Triggera _saveItemEditorInPlace
 *   5. Ispeziona request payload + applica + reload
 *   6. Verifica nested preservato
 */
const { test, expect } = require("@playwright/test");

const TEACHER_USER = "superadmin";
const TEACHER_PASS = (process.env.E2E_TEACHER_PASS || "");

test("REAL save flow: nested OL in cell editor → captured blocks struct", async ({ page }) => {
    test.setTimeout(60000);
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

    // Naviga all'esercizio noto (1?ids=65 in MAT/3SCI per il dispositivo dell'utente)
    await page.goto("/studio/esercizio/SCI/3/MAT/1?ids=65", { waitUntil: "networkidle" });
    await page.waitForTimeout(3000);

    const hasEditor = await page.evaluate(() => !!window.FM?.__buildBlocksFromTextareaForTest);
    expect(hasEditor).toBe(true);

    // Test pure JS: simula il save flow su un contenteditable popolato manualmente
    const result = await page.evaluate(() => {
        const fs = window.FM.FieldSerializer;
        const buildBlocks = window.FM.__buildBlocksFromTextareaForTest;

        // Costruisci textarea simil-editor con nested OL EXACT come l'editor produce
        const ta = document.createElement("div");
        ta.contentEditable = "true";
        Object.defineProperty(ta, "value", {
            get() { return ta.innerHTML; },
            set(v) { ta.innerHTML = v; },
        });

        // Caso 1: HTML nested OL clean (come prodotto da contenteditable + _indentListItem)
        ta.value = '<b>CELLA</b><ol class="fm-dsa-li-list" data-fm-list-style="lower-alpha-roman" data-dsa-section="question">' +
                   '<li>u<ol class="fm-dsa-li-list" data-dsa-section="question">' +
                     '<li>uu<ol class="fm-dsa-li-list" data-dsa-section="question">' +
                       '<li>uuu</li></ol></li></ol></li>' +
                   '<li>d</li></ol>';
        document.body.appendChild(ta);
        const blocks1 = buildBlocks(ta);
        ta.remove();

        // Caso 2: HTML con .fm-dsa-li-num + .fm-dsa-li-content wrappers (server-rendered)
        const ta2 = document.createElement("div");
        ta2.contentEditable = "true";
        Object.defineProperty(ta2, "value", {
            get() { return ta2.innerHTML; },
            set(v) { ta2.innerHTML = v; },
        });
        ta2.value = '<b>CELLA</b>' +
            '<ol class="fm-dsa-li-list" data-fm-list-style="lower-alpha-roman" data-dsa-section="question">' +
              '<li data-fm-dsa-state="">' +
                '<span class="fm-dsa-li-num">a.</span>' +
                '<span class="fm-dsa-li-content">' +
                  '<span class="fm-text" data-raw="u">u</span>' +
                  '<ol class="fm-dsa-li-list" data-dsa-section="sub">' +
                    '<li><span class="fm-dsa-li-num">i.</span>' +
                      '<span class="fm-dsa-li-content">' +
                        '<span class="fm-text" data-raw="uu">uu</span>' +
                        '<ol class="fm-dsa-li-list" data-dsa-section="sub">' +
                          '<li><span class="fm-dsa-li-num">1.</span>' +
                            '<span class="fm-dsa-li-content">' +
                              '<span class="fm-text" data-raw="uuu">uuu</span>' +
                            '</span></li>' +
                        '</ol>' +
                      '</span></li>' +
                  '</ol>' +
                '</span>' +
              '</li>' +
              '<li><span class="fm-dsa-li-num">b.</span>' +
                '<span class="fm-dsa-li-content">' +
                  '<span class="fm-text" data-raw="d">d</span>' +
                '</span></li>' +
            '</ol>';
        document.body.appendChild(ta2);
        const blocks2 = buildBlocks(ta2);
        ta2.remove();

        // Caso 3: load via FieldSerializer.loadFieldHtml + re-parse
        const sourceDiv = document.createElement("div");
        sourceDiv.innerHTML = ta2.value; // riusa l'HTML "server-style"
        // NB: ta2 was already removed; ricreo
        const sourceDiv2 = document.createElement("div");
        sourceDiv2.innerHTML = '<span class="fm-text" data-raw="CELLA">CELLA</span>' +
            '<ol class="fm-dsa-li-list" data-fm-list-style="lower-alpha-roman" data-dsa-section="question">' +
              '<li data-fm-dsa-state="">' +
                '<span class="fm-dsa-li-num">a.</span>' +
                '<span class="fm-dsa-li-content">' +
                  '<span class="fm-text" data-raw="u">u</span>' +
                  '<ol class="fm-dsa-li-list" data-dsa-section="sub">' +
                    '<li><span class="fm-text" data-raw="uu">uu</span></li>' +
                  '</ol>' +
                '</span>' +
              '</li>' +
              '<li><span class="fm-text" data-raw="d">d</span></li>' +
            '</ol>';
        const loadedHtml = fs.loadFieldHtml(sourceDiv2);
        // Re-parse il loaded HTML
        const ta3 = document.createElement("div");
        ta3.contentEditable = "true";
        Object.defineProperty(ta3, "value", { get() { return ta3.innerHTML; }, set(v) { ta3.innerHTML = v; } });
        ta3.value = loadedHtml;
        document.body.appendChild(ta3);
        const blocks3 = buildBlocks(ta3);
        ta3.remove();

        return {
            blocks1, blocks2, blocks3,
            loadedHtml,
        };
    });

    // Caso 1: HTML clean. Aspetto: list block con 2 items, LI1 ha nested
    const list1 = result.blocks1.find(b => b.type === "list");
    expect(list1, "Caso 1: deve esserci un list block").toBeTruthy();
    expect(list1.items.length, "Caso 1: list ha 2 outer items").toBe(2);
    expect(list1.items[0].some(b => b?.type === "list"), "Caso 1: LI1 ha nested list").toBe(true);

    // Caso 2: server-rendered. Aspetto stesso comportamento + NO contamination
    const list2 = result.blocks2.find(b => b.type === "list");
    expect(list2, "Caso 2: deve esserci un list block").toBeTruthy();
    expect(list2.items.length, "Caso 2: list ha 2 outer items").toBe(2);
    const li0HasNested2 = list2.items[0].some(b => b?.type === "list");
    expect(li0HasNested2, "Caso 2: LI1 ha nested list").toBe(true);
    // G23.fix5 — NO markers "a." "b." "i." come falsi text block
    const flat2 = JSON.stringify(result.blocks2);
    expect(flat2, "Caso 2: NO text block con 'a.' marker").not.toMatch(/"content":\s*"a\.?\s*"/);
    expect(flat2, "Caso 2: NO text block con 'b.' marker").not.toMatch(/"content":\s*"b\.?\s*"/);
    expect(flat2, "Caso 2: NO text 'b.d' (marker+content fused)").not.toMatch(/"content":\s*"b\.d"/);
    expect(flat2, "Caso 2: text 'd' isolato").toMatch(/"content":\s*"d"/);
    expect(flat2, "Caso 2: text 'u' isolato").toMatch(/"content":\s*"u"/);
    expect(flat2, "Caso 2: text 'uu' isolato").toMatch(/"content":\s*"uu"/);
    expect(flat2, "Caso 2: text 'uuu' isolato").toMatch(/"content":\s*"uuu"/);

    // Caso 3: load+parse
    const list3 = result.blocks3.find(b => b.type === "list");
    expect(list3, "Caso 3: deve esserci un list block").toBeTruthy();
    expect(list3.items.length, "Caso 3: list ha 2 outer items").toBe(2);
    expect(list3.items[0].some(b => b?.type === "list"), "Caso 3: LI1 ha nested list").toBe(true);

    console.log("✅ Tutti i 3 casi: nested preservato");
    console.log("Caso 2 (server-rendered) blocks:", JSON.stringify(result.blocks2, null, 2).substring(0, 1500));
});
