/**
 * G23.fix7 — Reproduce user's Enter-outdent bug.
 *
 * Scenario: contenteditable con OL nested 3 livelli + LI vuoto al livello 2:
 *
 *   <ol class="fm-dsa-li-list">
 *     <li>u
 *       <ol class="fm-dsa-li-list">
 *         <li>uu
 *           <ol class="fm-dsa-li-list">
 *             <li>uuu</li>
 *             <li>{caret-empty}</li>   ← user prema Enter qui due volte
 *           </ol>
 *         </li>
 *       </ol>
 *     </li>
 *   </ol>
 *
 * Atteso: Enter twice → outdent due livelli → "2." at top level + struttura
 * nested preservata sopra.
 *
 * Bug osservato: markers cambiano (level 1 "a." → "1.", level 2 "i." → "a.").
 */
const { test, expect } = require("@playwright/test");

test("DIAGNOSE Enter+Enter on empty nested LI → DOM after", async ({ page }) => {
    test.setTimeout(60000);
    await page.goto("/");
    await page.waitForFunction(() => typeof window.FM?.__indentListItemForTest === "function", { timeout: 10000 });

    const result = await page.evaluate(async () => {
        // Build the editor field with the test structure
        const field = document.createElement("div");
        field.contentEditable = "true";
        field.id = "fm-test-field";
        // Replicate _makeEditableField shim
        Object.defineProperty(field, "value", {
            get() { return field.innerHTML; },
            set(v) { field.innerHTML = v; },
        });
        field.innerHTML = `<ol class="fm-dsa-li-list" data-dsa-section="question">
            <li>u
                <ol class="fm-dsa-li-list" data-dsa-section="question">
                    <li>uu
                        <ol class="fm-dsa-li-list" data-dsa-section="question">
                            <li>uuu</li>
                            <li id="empty-li"></li>
                        </ol>
                    </li>
                </ol>
            </li>
        </ol>`;
        document.body.appendChild(field);

        // Attach list key handlers (Tab/Enter)
        // Apparently the test exposes _outdentListItem/_indentListItem
        const outdentForTest = window.FM?.__outdentListItemForTest;
        const _findEnclosingLi = (node, root) => {
            let n = node;
            while (n && n !== root) {
                if (n.nodeType === 1 && n.tagName === "LI") return n;
                n = n.parentNode;
            }
            return null;
        };
        // Simulate Enter on empty LI: call outdent with removeIfEmpty=true
        const emptyLi = field.querySelector("#empty-li");

        // Snapshot before
        const before = field.outerHTML.replace(/\s+/g, " ");

        // First Enter (outdent empty li)
        if (typeof outdentForTest === "function") {
            outdentForTest(emptyLi, field, true);
        }
        const afterFirst = field.outerHTML.replace(/\s+/g, " ");

        // Second Enter — caret should now be in the newBlock div
        // Find what the cursor would be in
        const caretAfterFirst = field.querySelector("div");
        // For second Enter, the handler check: enclosing LI is LI[uu] (which has nested OL with uuu)
        // LI[uu].textContent !== "" so NO outdent. Browser default Enter would split block.
        // But we can simulate by calling outdent on LI[uu] manually if user's expected behavior
        let afterSecond = "";
        let secondAction = "";
        if (caretAfterFirst) {
            const enclosingLi = _findEnclosingLi(caretAfterFirst, field);
            const isEmpty = enclosingLi && enclosingLi.textContent.trim() === "" && !enclosingLi.querySelector("ol, ul");
            secondAction = enclosingLi
                ? `LI text="${enclosingLi.textContent.trim().substring(0,20)}" isEmpty=${isEmpty}`
                : "no LI";
            // If second Enter handler would NOT outdent (because LI[uu] is not empty),
            // browser fallback: split the div. Simulate by inserting <br>+newDiv after caretAfterFirst.
            const newDiv = document.createElement("div");
            newDiv.appendChild(document.createElement("br"));
            caretAfterFirst.parentNode.insertBefore(newDiv, caretAfterFirst.nextSibling);
            afterSecond = field.outerHTML.replace(/\s+/g, " ");
        }
        field.remove();
        return { before, afterFirst, afterSecond, secondAction };
    });

    console.log("=== BEFORE ===");
    console.log(result.before);
    console.log("=== AFTER FIRST ENTER (outdent removeIfEmpty) ===");
    console.log(result.afterFirst);
    console.log("=== SECOND ENTER ACTION ===");
    console.log(result.secondAction);
    console.log("=== AFTER SECOND ENTER (simulated browser default) ===");
    console.log(result.afterSecond);

    // G23.fix7 — after first Enter on nested empty LI, expect NEW LI sibling
    // of grandLi (LI[uu]), NOT a <div>. Markers preserved.
    expect(result.afterFirst).not.toContain("empty-li"); // emptyLi removed
    // No <div><br></div> inside OL (HTML invalido)
    expect(result.afterFirst).not.toMatch(/<ol[^>]*>(?:(?!<\/ol>)[\s\S])*<div><br>/);
    // Should have new empty LI at level 1 (sibling of LI[uu])
    expect(result.afterFirst).toMatch(/<li>uu[\s\S]*?<\/li>\s*<li><\/li>/);
});

test("REPRO complete flow: 3-level nested + Enter twice → 2 outdents → top level LI", async ({ page }) => {
    await page.goto("/");
    await page.waitForFunction(() => typeof window.FM?.__outdentListItemForTest === "function", { timeout: 10000 });

    const result = await page.evaluate(() => {
        const field = document.createElement("div");
        field.contentEditable = "true";
        field.innerHTML = `<ol class="fm-dsa-li-list">` +
            `<li>u<ol class="fm-dsa-li-list">` +
              `<li>uu<ol class="fm-dsa-li-list">` +
                `<li>uuu</li>` +
                `<li id="empty3"></li>` +
              `</ol></li>` +
            `</ol></li>` +
        `</ol>`;
        document.body.appendChild(field);
        const outdent = window.FM.__outdentListItemForTest;

        // Enter 1: outdent empty3 from level 3 to level 2
        const empty3 = field.querySelector("#empty3");
        outdent(empty3, field, true);
        // Trova il nuovo LI vuoto al livello 2 (dovrebbe essere sibling di LI[uu])
        const li_uu = Array.from(field.querySelectorAll("li")).find(li => li.firstChild?.textContent === "uu");
        const newEmpty2 = li_uu?.nextElementSibling;
        const afterEnter1 = field.outerHTML.replace(/\s+/g, " ");

        // Enter 2: outdent newEmpty2 from level 2 to level 1 (top)
        if (newEmpty2 && newEmpty2.tagName === "LI" && newEmpty2.textContent.trim() === "") {
            outdent(newEmpty2, field, true);
        }
        const afterEnter2 = field.outerHTML.replace(/\s+/g, " ");

        field.remove();
        return { afterEnter1, afterEnter2 };
    });

    console.log("After Enter 1:", result.afterEnter1);
    console.log("After Enter 2:", result.afterEnter2);

    // Dopo 2 Enter: deve esserci un LI vuoto al top level (sibling di LI[u])
    expect(result.afterEnter2).toMatch(/<ol[^>]*>\s*<li>u[\s\S]*?<\/li>\s*<li><\/li>/);
    // NO div invalidi
    expect(result.afterEnter2).not.toMatch(/<ol[^>]*>(?:(?!<\/ol>)[\s\S])*<div>/);
    // Struttura nested preservata
    expect(result.afterEnter2).toContain("uuu");
    expect(result.afterEnter2).toContain("uu");
});
