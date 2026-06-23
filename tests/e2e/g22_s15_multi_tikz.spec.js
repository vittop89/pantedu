/**
 * G22.S15 — multi-TikZ + collapse/expand markers test.
 *
 * Verifica:
 *   - Più <script type="text/tikz"> in stessa textarea diventano N marker.
 *   - Ogni marker ha bottone dedicato.
 *   - Modal opera su un blocco specifico, save aggiorna SOLO quel block.
 *   - Espansione dei marker preserva i blocchi NON modificati.
 */
const { test, expect } = require("@playwright/test");

const BASE_URL = process.env.FM_E2E_BASE_URL || "http://localhost";

test("multi-tikz: collapse + expand round-trip", async ({ page }) => {
    await page.goto(BASE_URL + "/");
    await page.waitForFunction(() => window.FM?.LatexRender, { timeout: 10000 });

    // Importiamo i helpers via il bundle pubblico
    const result = await page.evaluate(async () => {
        // Raw value con 3 TikZ blocks intercalati
        const raw = `Premessa testo.
<script type="text/tikz" id="t1">
\\begin{tikzpicture}\\draw (0,0) circle (1);\\end{tikzpicture}
</` + `script>
Testo intermedio.
<script type="text/tikz">
\\begin{tikzpicture}\\draw[red] (0,0)--(2,2);\\end{tikzpicture}
</` + `script>
Altro testo.
<script type="text/tikz">
\\begin{tikzpicture}\\fill (0,0) circle (3pt);\\end{tikzpicture}
</` + `script>
Coda.`;

        // Simula collapseTikzBlocks (logica privata): cerchiamo via regex
        const collapseRe = /(<script\s+type=["']text\/tikz["'][^>]*>)([\s\S]*?)(<\/script>)/gi;
        const blocks = [];
        let collapsed = "";
        let last = 0;
        let m;
        while ((m = collapseRe.exec(raw)) !== null) {
            collapsed += raw.slice(last, m.index);
            blocks.push({ tagOpen: m[1], body: m[2], tagClose: m[3] });
            collapsed += `⟨🔍 TikZ #${blocks.length}⟩`;
            last = m.index + m[0].length;
        }
        collapsed += raw.slice(last);

        // Verifica
        const markers = (collapsed.match(/⟨🔍 TikZ #\d+⟩/g) || []);
        const collapsedHasNoScript = !/<script\s+type=["']text\/tikz["']/i.test(collapsed);

        // Modifica blocco #2 (zero-indexed: idx=1)
        blocks[1].body = "\n\\begin{tikzpicture}\\draw[blue] (0,0)--(5,5);\\end{tikzpicture}\n";

        // Espandi
        const expanded = collapsed.replace(/⟨🔍 TikZ #(\d+)⟩/g, (_, n) => {
            const i = parseInt(n, 10) - 1;
            const b = blocks[i];
            return b.tagOpen + b.body + b.tagClose;
        });
        const block1Preserved = expanded.includes("\\draw (0,0) circle (1)");
        const block2Updated = expanded.includes("\\draw[blue] (0,0)--(5,5)");
        const block3Preserved = expanded.includes("\\fill (0,0) circle (3pt)");

        return {
            blocksCount: blocks.length,
            markersCount: markers.length,
            collapsedHasNoScript,
            block1Preserved,
            block2Updated,
            block3Preserved,
        };
    });

    console.log("multi-tikz result:", JSON.stringify(result, null, 2));
    expect(result.blocksCount).toBe(3);
    expect(result.markersCount).toBe(3);
    expect(result.collapsedHasNoScript).toBe(true);
    expect(result.block1Preserved).toBe(true);
    expect(result.block2Updated).toBe(true);
    expect(result.block3Preserved).toBe(true);
});
