/**
 * Quando l'utente apre l'editor inline su un .fm-collection con liste server-rendered,
 * il textarea (ora <div contenteditable>) DEVE preservare la struttura <ol>/<ul>
 * con preset, NON appiattire tutto a testo plain.
 *
 * Test: simula DOM .fm-collection come emesso da ContractRenderer, chiama
 * _extractRawWithTikz, verifica che il risultato è HTML valido con <ol>+preset.
 */
const { test, expect } = require("../../support/test");

test("_extractRawWithTikz preserva <ol> + preset (no flat text)", async ({ bancoEditor }) => {
    const page = bancoEditor.page;
    const r = await page.evaluate(() => {
        // Simula .fm-collection emesso da ContractRenderer
        const collex = document.createElement("div");
        collex.className = "fm-collection";
        collex.innerHTML = `
            <span class="fm-text" data-raw="Domanda iniziale">Domanda iniziale</span>
            <ol class="fm-dsa-li-list" data-dsa-section="question" data-fm-list-style="star-circle">
                <li data-fm-dsa-state="">
                    <span class="fm-dsa-li-buttons"><button>F</button><button>GF</button></span>
                    <span class="fm-dsa-li-num">★</span>
                    <span class="fm-dsa-li-content"><span class="fm-text" data-raw="primo punto">primo punto</span></span>
                </li>
                <li data-fm-dsa-state="">
                    <span class="fm-dsa-li-buttons"><button>F</button><button>GF</button></span>
                    <span class="fm-dsa-li-num">★</span>
                    <span class="fm-dsa-li-content"><span class="fm-text" data-raw="secondo punto">secondo punto</span></span>
                </li>
            </ol>
            <span class="fm-text" data-raw="Dopo lista.">Dopo lista.</span>
        `;
        document.body.appendChild(collex);

        // Devo accedere a _extractRawWithTikz; lo espongo via window.FM
        // se necessario. Per il test simulo via _buildBlocksFromTextarea + handler:
        // OPPURE chiamo direttamente la funzione (non esposta).
        // Per ora uso un test indiretto: apro un editor reale.

        // Usa buildSection con initial value generato da _extractRawWithTikz.
        // Espongo helper:
        if (!window.FM.__extractRawWithTikzForTest) {
            return { error: "_extractRawWithTikz non esposto" };
        }
        const initial = window.FM.__extractRawWithTikzForTest(collex);
        return { initial };
    });

    expect(r.error, JSON.stringify(r)).toBeUndefined();
    // Il valore iniziale del textarea DEVE preservare la struttura <ol>
    expect(r.initial, "preserva <ol class=fm-dsa-li-list>").toMatch(/<ol[^>]*class="fm-dsa-li-list"/);
    expect(r.initial, "preserva data-fm-list-style=star-circle").toContain('data-fm-list-style="star-circle"');
    // F/GF buttons + .fm-dsa-li-num NON devono essere nel sorgente edit
    expect(r.initial, "no F/GF buttons (UI server-only)").not.toContain("fm-dsa-li-buttons");
    expect(r.initial, "no .fm-dsa-li-num").not.toContain("fm-dsa-li-num");
    expect(r.initial, "no .fm-dsa-li-content (unwrap)").not.toContain("fm-dsa-li-content");
    // Contenuto raw preservato
    expect(r.initial, "primo punto").toContain("primo punto");
    expect(r.initial, "secondo punto").toContain("secondo punto");
    // Testi prima e dopo
    expect(r.initial, "Domanda iniziale").toContain("Domanda iniziale");
    expect(r.initial, "Dopo lista").toContain("Dopo lista");
});
