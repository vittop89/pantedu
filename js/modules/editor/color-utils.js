/**
 * G24.refactor5.step7 — Estratto da `features/checkin-handlers.js` (monolite
 * 8400+ LOC). Phase 16 topic color cycle: applica colori `.fm-titolo-quesito`
 * dei `.fm-collection__item` in modo che esercizi con stesso topic abbiano stesso
 * colore, esercizi consecutivi con topic diverso abbiano colore successivo
 * del cycle.
 *
 * Cycle palette (6 colori): white → green → blue → red → purple → orange.
 *
 * Tutte funzioni pure di DOM lettura/scrittura. Riusabili da altri renderer.
 */

export const TOPIC_COLOR_CYCLE = ["white", "green", "blue", "red", "purple", "orange"];

/** Applica `colorName` come background del `.fm-titolo-quesito` dentro `item`.
 *  - text color: black su white, white su altri
 *  - sync `.colorSelect` se presente (UI control)
 *  - annota `dataset.fmvColor` (state mirror) */
export function applyColorToCollexItem(item, colorName) {
    if (!item || !colorName) return;
    const textColor = colorName === "white" ? "black" : "white";
    const titolo = item.querySelector(".fm-titolo-quesito");
    if (titolo) {
        titolo.style.backgroundColor = colorName;
        titolo.style.color = textColor;
    }
    // Sync colorSelect se diverso (loop trigger)
    const sel = item.querySelector(".fm-color-select");
    if (sel && sel.value !== colorName) sel.value = colorName;
    item.dataset.fmvColor = colorName;
}

/** Esegue il cycle colori per tutti i .fm-groupcollex (una volta al load).
 *  - Items con stesso topic (textContent uguale): stesso colore
 *  - Topic cambia → next color nel cycle
 *  - Bg inline preesistente (da ContractRenderer): rispettato, no override */
export function applyTopicColorCycle(root = document) {
    root.querySelectorAll(".fm-groupcollex").forEach((problem) => {
        let cycleIdx = 0;
        let lastTopic = null;
        problem.querySelectorAll(".fm-collection__item").forEach((item) => {
            const titolo = item.querySelector(".fm-titolo-quesito");
            if (!titolo) return;
            const topic = (titolo.textContent || "").trim();
            if (!topic) return;
            // Se il categoria cambia tra items → next color
            if (lastTopic !== null && topic !== lastTopic) cycleIdx++;
            lastTopic = topic;
            // Se il titolo ha già un bg_color esplicito (da ContractRenderer),
            // rispetta quello; altrimenti applica cycle.
            const hasInlineBg = titolo.style.backgroundColor && titolo.style.backgroundColor !== "transparent";
            if (!hasInlineBg) {
                const colorName = TOPIC_COLOR_CYCLE[cycleIdx % TOPIC_COLOR_CYCLE.length];
                applyColorToCollexItem(item, colorName);
            } else {
                // Sync colorSelect con bg esistente (per coerenza UI)
                const sel = item.querySelector(".fm-color-select");
                if (sel) {
                    const rgb = titolo.style.backgroundColor;
                    const name = rgbToColorName(rgb);
                    if (name) sel.value = name;
                }
            }
        });
    });
}

/** Mappa `rgb()` string → color name tra i 6 supportati. Null se no match. */
export function rgbToColorName(rgb) {
    const map = {
        "rgb(255, 255, 255)": "white",
        "rgb(255, 0, 0)":     "red",
        "rgb(0, 128, 0)":     "green",
        "rgb(0, 0, 255)":     "blue",
        "rgb(128, 0, 128)":   "purple",
        "rgb(255, 165, 0)":   "orange",
    };
    return map[rgb] || null;
}

/** Converte `rgb(R, G, B)` → `#RRGGBB`. Passa through `#xxx` direttamente. */
export function rgbToHex(rgb) {
    if (!rgb) return null;
    const m = rgb.match(/rgb\((\d+),\s*(\d+),\s*(\d+)\)/);
    if (!m) return rgb.startsWith("#") ? rgb : null;
    const [, r, g, b] = m;
    return `#${  [r, g, b].map((n) => Number(n).toString(16).padStart(2, "0")).join("")}`;
}
