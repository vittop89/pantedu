/**
 * Teacher print client (Phase 8e MVP).
 *
 * Espone window.FM.PrintClient.printTexForTeacher(selection, variant)
 * che invia la selezione esercizi a /teacher/print, riceve il file
 * .tex come blob e triggera download nel browser.
 *
 * `selection` è un oggetto plain seguendo lo schema di
 * App\Services\TexBuilder\Selection — vedi PHP per la documentazione.
 */

import { Api } from "../core/api.js";

const VARIANTS = ["normal", "dsa", "dyslexic"];

async function printTexForTeacher(selection, variant = "normal") {
    if (!selection || typeof selection !== "object") {
        throw new Error("selection_invalid");
    }
    if (!VARIANTS.includes(variant)) variant = "normal";

    const payload = { ...selection, variant };
    const { blob, filename } = await Api.postBlob("/teacher/print", payload);

    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename || "verifica.tex";
    document.body.appendChild(a);
    a.click();
    setTimeout(() => {
        URL.revokeObjectURL(url);
        a.remove();
    }, 1000);
    return { ok: true, filename: a.download };
}

export const PrintClient = { printTexForTeacher, VARIANTS };

window.FM = window.FM || {};
window.FM.PrintClient = PrintClient;
