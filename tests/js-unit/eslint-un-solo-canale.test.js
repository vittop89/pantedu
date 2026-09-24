// @vitest-environment node
import { describe, it, expect } from "vitest";
import { ESLint } from "eslint";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Un solo canale per il JavaScript (revisione architetturale del 23/9/2026,
 * R-13): le regole ESLint che lo tengono fermo, provate nei due versi. Una
 * regola provata solo sul codice pulito può essere una regola che non scatta
 * mai.
 */
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const eslint = new ESLint({ cwd: RADICE });

/** I messaggi di `no-restricted-syntax` per un testo finto messo in `file`. */
async function divieti(codice, file) {
    const [esito] = await eslint.lintText(codice, { filePath: join(RADICE, file) });
    return esito.messages.filter((m) => m.ruleId === "no-restricted-syntax").map((m) => m.message);
}

describe("A-18: dove la rete passa da wafFetch, una fetch diretta è un errore", () => {
    const CLIENT = [
        "js/modules/core/api.js",
        "js/modules/features/checkin-handlers.js",
        "js/modules/features/import-bundle-flow.js",
        "js/modules/features/shortcuts-editor.js",
        "js/entries/teacher-dashboard.js",
    ];

    it.each(CLIENT)("%s: fetch() e window.fetch() scattano", async (file) => {
        const trovati = await divieti(
            "export async function a() { await fetch('/x'); await window.fetch('/y'); }\n",
            file,
        );
        expect(trovati).toHaveLength(2);
        expect(trovati[0]).toContain("wafFetch");
    });

    it.each(CLIENT)("%s: wafFetch non scatta, e i divieti su jQuery restano", async (file) => {
        const trovati = await divieti(
            "import { wafFetch } from './dom-utils.js';\nexport async function a() { await wafFetch('/x'); $.ajax({}); }\n",
            file,
        );
        expect(trovati).toHaveLength(1);
        expect(trovati[0]).toContain("jQuery");
    });

    it("un modulo non ancora passato a wafFetch non scatta (il divieto è per file, non ovunque)", async () => {
        expect(await divieti("export async function a() { await fetch('/x'); }\n",
            "js/modules/features/admin-risdoc.js")).toEqual([]);
    });

    it("i file veri, oggi, non hanno fetch dirette", async () => {
        const esiti = await eslint.lintFiles(CLIENT);
        const trovati = esiti.flatMap((e) => e.messages
            .filter((m) => m.ruleId === "no-restricted-syntax")
            .map((m) => `${e.filePath}:${m.line} ${m.message}`));
        expect(trovati).toEqual([]);
    });
});

describe("A-21: l'escape HTML non si riscrive fuori da core/dom-utils.js", () => {
    const FILE = "js/modules/features/un-modulo-qualsiasi.js";

    it.each([
        ["una funzione chiamata escapeHtml", "function escapeHtml(s) { return String(s); }\n"],
        ["una freccia chiamata escHtml", "const escHtml = (s) => String(s);\n"],
        ["il nome delle voci dell'amministrazione", "function fmEscapeHtml(s) { return String(s); }\n"],
        ["un ripiego su window.FM", "const escapeHtml = window.FM?.DomUtils?.escHtml || ((s) => String(s));\n"],
        ["la tabella scritta a mano, con un nome breve",
            "const esc = (s) => String(s).replace(/[&<>]/g, (c) => ({ \"&\": \"&amp;\", \"<\": \"&lt;\", \">\": \"&gt;\" }[c]));\n"],
        ["la catena di replace", "const _e = (s) => String(s).replace(/&/g, \"&amp;\").replace(/</g, \"&lt;\");\n"],
        ["replaceAll", "export const x = (s) => s.replaceAll(\"&\", \"&amp;\");\n"],
    ])("scatta su %s", async (_nome, codice) => {
        const trovati = await divieti(codice, FILE);
        expect(trovati.length).toBeGreaterThan(0);
        expect(trovati[0]).toContain("A-21");
    });

    it.each([
        ["l'import da dom-utils", "import { escHtml, escAttr as escapeAttr } from '../core/dom-utils.js';\nexport const a = escHtml('x') + escapeAttr('y');\n"],
        ["un alias dell'import", "import { escHtml } from '../core/dom-utils.js';\nexport const escapeHtml = escHtml;\n"],
        ["un gestore di tastiera chiamato esc", "const esc = (e) => { if (e.key === 'Escape') close(); };\nfunction close() {}\ndocument.addEventListener('keydown', esc);\n"],
        ["la decodifica (il verso opposto)", "export const d = (s) => s.replace(/&amp;/g, '&');\n"],
    ])("non scatta su %s", async (_nome, codice) => {
        expect(await divieti(codice, FILE)).toEqual([]);
    });

    it("dom-utils, dove l'escape vive, non scatta; i divieti su jQuery restano anche lì", async () => {
        expect(await divieti("export function escHtml(s) { return String(s).replace(/&/g, \"&amp;\"); }\n",
            "js/modules/core/dom-utils.js")).toEqual([]);
        expect(await divieti("$.ajax({});\n", "js/modules/core/dom-utils.js")).toHaveLength(1);
    });

    it("nei file veri sotto js/ oggi non ci sono copie", async () => {
        const esiti = await eslint.lintFiles(["js"]);
        const trovati = esiti.flatMap((e) => e.messages
            .filter((m) => m.ruleId === "no-restricted-syntax" && m.message.includes("A-21"))
            .map((m) => `${e.filePath}:${m.line}`));
        expect(trovati).toEqual([]);
    });
});
