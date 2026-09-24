// @vitest-environment node
import { describe, it, expect } from "vitest";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * I moduli arrivano dal bundle, non grezzi da /js/ (revisione architetturale
 * del 23/9/2026, A-17, R-13 passo 3).
 *
 * Accanto al bundle alcune pagine servivano moduli presi da /js/ così com'erano
 * nel repository: senza impronta nel nome, con un giorno di cache, e con le
 * loro dipendenze in una seconda istanza (su /admin/templates dom-utils e
 * sync-panel giravano due volte). Qui il censimento degli <script> che puntano
 * a /js/modules, /js/components o /js/entries in views/ e app/: ognuno che
 * resta ha il suo perché, e uno che sparisce va tolto dall'elenco, come una
 * voce morta della baseline di PHPStan.
 */
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");

const RIPIEGO = "il ripiego «manifest assente» di bootstrap.js (sviluppo senza build): in produzione il manifest c'è sempre";
const RESTANO = new Map([
    ["views/layout/shell.php → /js/modules/", RIPIEGO],
    ["views/partials/_exercise_assets.php → /js/modules/bootstrap.js", RIPIEGO],
    ["views/risdoc/edit.php → /js/modules/bootstrap.js", RIPIEGO],
    ["app/Controllers/Admin/RisdocAdminController.php → /js/modules/bootstrap.js?v=", RIPIEGO],
    ["views/teacher/templates.php → /js/modules/features/teacher-templates.js",
        "dentro /area-docente/templates, che si raggiunge anche con la navigazione SPA: portarlo nell'entry vuole "
        + "un caricamento al cambio di scheda, e senza una prova E2E di quel percorso resta com'è (voce 12)"],
    ["app/Controllers/Risdoc/TemplateViewController.php → /js/components/risdoc/fm-risdoc-section-navigator.js",
        "script classico, non nell'elenco del rilievo A-17: da decidere a parte (voce 12)"],
    ["app/Services/Study/StudyPageRenderer.php → /js/components/risdoc/fm-risdoc-section-navigator.js",
        "script classico, non nell'elenco del rilievo A-17: da decidere a parte (voce 12)"],
]);

/** Ogni `<script … src="/js/(modules|components|entries)/…">` di views/ e app/, come «file → src». */
function scriptGrezzi() {
    const file = execFileSync("git", ["ls-files", "--cached", "--others", "--exclude-standard", "views", "app"], {
        cwd: RADICE, encoding: "utf8",
    }).split("\n").filter((f) => f.endsWith(".php"));
    const trovati = [];
    for (const percorso of file) {
        const testo = readFileSync(join(RADICE, percorso), "utf8");
        // Nel tag può esserci il nonce scritto da PHP (`<?= …Csp::attributo() ?>`), che contiene un `>`.
        for (const m of testo.matchAll(/<script\b(?:<\?=.*?\?>|[^>])*?\bsrc=\\?["'](\/js\/(?:modules|components|entries)\/[^"'<\s]*)/g)) {
            trovati.push(`${percorso} → ${m[1]}`);
        }
    }
    return trovati;
}

/** La chiave del censimento che copre un riscontro: il src può continuare (versione, parametri, PHP). */
function chiave(riscontro) {
    return [...RESTANO.keys()].find((k) => riscontro.startsWith(k)) ?? null;
}

describe("gli script grezzi da /js/", () => {
    const trovati = scriptGrezzi();

    it("la misura guarda qualcosa", () => {
        expect(trovati.length).toBeGreaterThan(0);
    });

    it("nessuno fuori dal censimento: /admin/templates e /admin/tools prendono i loro moduli dal bundle", () => {
        expect(trovati.filter((r) => chiave(r) === null)).toEqual([]);
    });

    it("ogni voce del censimento esiste ancora", () => {
        const usate = new Set(trovati.map(chiave));
        expect([...RESTANO.keys()].filter((k) => !usate.has(k))).toEqual([]);
    });

    it("le entry delle due pagine importano i loro moduli", () => {
        const templates = readFileSync(join(RADICE, "js/entries/admin-templates.js"), "utf8");
        for (const modulo of ["admin-verifica-templates", "admin-tikz-templates", "admin-options-sources"]) {
            expect(templates, modulo).toMatch(new RegExp(`^import "/js/modules/features/${modulo}\\.js";$`, "m"));
        }
        const strumenti = readFileSync(join(RADICE, "js/entries/admin-tools.js"), "utf8");
        expect(strumenti).toMatch(/^import "\.\.\/modules\/features\/admin-tools\.js";$/m);
        const vite = readFileSync(join(RADICE, "vite.config.js"), "utf8");
        expect(vite).toContain('"admin-tools":');
    });
});
