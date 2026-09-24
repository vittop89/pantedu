/**
 * Le liste annidate quando si salva, dentro al browser.
 * Riscrittura di g23_real_save_flow.spec.js.
 *
 * Salvando un campo che contiene una lista a più livelli, la struttura deve
 * arrivare al server così com'è: due punti in cima, e dentro il primo un
 * elenco suo. Il campo può presentarsi in tre forme — quella pulita che
 * produce l'editor, quella con i segni e gli involucri che rende il server, e
 * quella riletta dopo un caricamento — e tutte e tre devono dare lo stesso
 * risultato.
 *
 * Il difetto che questa spec ha trovato: nella forma resa dal server, i segni
 * dei punti («a.», «b.», «i.») venivano raccolti come se fossero testo scritto
 * dal docente, e finivano dentro il contenuto.
 *
 * Cosa cambia rispetto a prima: la password letta dall'ambiente non c'è più, il
 * banco lo prepara la fixture, e non serve più aprire una copia particolare del
 * dump locale — il test costruisce da sé i tre campi.
 */
const { test, expect } = require("../../support/test");


test("la lista annidata arriva intera al salvataggio, in tutte e tre le forme", async ({ bancoEditor }) => {
    const page = bancoEditor.page;

    // Il banco: tre campi costruiti a mano, uno per forma.
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
});
