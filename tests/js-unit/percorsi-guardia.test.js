// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll } from "vitest";
import { execFileSync } from "node:child_process";
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * `tools/ci/no-scritture-nel-repository.mjs` nei due versi, su file finti.
 *
 * La guardia dice che in `app/` una cartella dei DATI non si costruisce dalla
 * radice del CODICE: nel container quella radice è l'immagine, e la scrittura
 * riesce per poi sparire al rilascio. Una guardia così vale solo quanto le
 * forme che riconosce, e il 20 settembre 2026 una revisione indipendente ne ha
 * misurate tre che le sfuggivano:
 *
 *   - `sprintf('%s/storage/…', dirname(__DIR__, 2))`;
 *   - la stessa concatenazione spezzata su due righe (quella che chiede il
 *     linter appena il percorso è lungo);
 *   - `(string)\App\Core\Config::get('app.paths.base', dirname(__DIR__, 2))`,
 *     cioè il nome della classe qualificato — la forma che
 *     `WafAdminController` usava davvero.
 *
 * Ogni prova ha il suo verso opposto: la stessa scrittura fatta bene, o una
 * lettura di un albero versionato, la devono lasciare muta. Un controllo
 * provato in un verso solo può essere una funzione che risponde sempre «va
 * bene».
 */
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const GUARDIA = join(RADICE, "tools", "ci", "no-scritture-nel-repository.mjs");

let cartella;
beforeAll(() => { cartella = mkdtempSync(join(tmpdir(), "percorsi-guardia-")); });
afterAll(() => { rmSync(cartella, { recursive: true, force: true }); });

/** Esegue la guardia su una sonda sola; dice se ha segnalato qualcosa. */
function scatta(nome, corpo) {
    const dir = join(cartella, nome);
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, "Sonda.php"), `<?php\n\nfinal class Sonda\n{\n    public function fai(): void\n    {\n${corpo}\n    }\n}\n`);
    try {
        execFileSync(process.execPath, [GUARDIA], {
            env: { ...process.env, PERCORSI_DIR: dir },
            stdio: "pipe",
        });
        return false;
    } catch (e) {
        return String(e.stderr || "").includes("costruiscono un percorso dei DATI");
    }
}

describe("guardia dei percorsi — le forme che costruiscono i dati dal codice", () => {
    it("scatta sulla concatenazione diretta, su una riga", () => {
        expect(scatta("diretta", "        $f = dirname(__DIR__, 2) . '/storage/sonda/uno.json';\n        file_put_contents($f, 'x');"))
            .toBe(true);
    });

    it("scatta sulla concatenazione spezzata su due righe", () => {
        expect(scatta("due-righe", "        $f = dirname(__DIR__, 2)\n            . '/storage/sonda/due.json';\n        file_put_contents($f, 'x');"))
            .toBe(true);
    });

    it("scatta su sprintf", () => {
        expect(scatta("sprintf", "        $f = sprintf('%s/storage/sonda/tre.json', dirname(__DIR__, 2));\n        file_put_contents($f, 'x');"))
            .toBe(true);
    });

    it("scatta sul nome della classe qualificato", () => {
        expect(scatta("qualificato", "        $base = (string)\\App\\Core\\Config::get('app.paths.base', dirname(__DIR__, 2));\n        file_put_contents($base . '/storage/sonda/fqn.json', 'x');"))
            .toBe(true);
    });

    it("scatta su sprintf a partire da una variabile qualificata", () => {
        expect(scatta("sprintf-var", "        $base = (string)\\App\\Core\\Config::get('app.paths.base', dirname(__DIR__, 2));\n        file_put_contents(sprintf('%s/storage/sonda/q.json', $base), 'x');"))
            .toBe(true);
    });

    it("scatta su DOCUMENT_ROOT", () => {
        expect(scatta("docroot", "        $f = $_SERVER['DOCUMENT_ROOT'] . '/storage/sonda/dr.json';\n        file_put_contents($f, 'x');"))
            .toBe(true);
    });

    it("tace sulla stessa scrittura fatta bene", () => {
        expect(scatta("giusta", "        $f = \\App\\Support\\PercorsiDati::base(dirname(__DIR__, 2)) . '/storage/sonda/ok.json';\n        file_put_contents($f, 'x');"))
            .toBe(false);
        expect(scatta("giusta-sprintf", "        $base = \\App\\Support\\PercorsiDati::base(dirname(__DIR__, 2));\n        file_put_contents(sprintf('%s/storage/sonda/ok.json', $base), 'x');"))
            .toBe(false);
    });

    it("tace sul ripiego documentato, anche quando va a capo", () => {
        expect(scatta("ripiego", "        $dir = \\App\\Core\\Config::get('app.paths.logs', dirname(__DIR__, 2) . '/storage/logs');\n        file_put_contents($dir . '/x.json', 'x');"))
            .toBe(false);
        expect(scatta("ripiego-a-capo", "        $dir = \\App\\Core\\Config::get(\n            'app.paths.storage',\n            dirname(__DIR__, 2) . '/storage',\n        );\n        file_put_contents($dir . '/x.json', 'x');"))
            .toBe(false);
    });

    it("tace sulla lettura di un albero versionato", () => {
        expect(scatta("versionato", "        $f = dirname(__DIR__, 2) . '/views/layout.php';\n        include $f;"))
            .toBe(false);
        expect(scatta("versionato-sprintf", "        $f = sprintf('%s/schemas/pt.json', dirname(__DIR__, 2));\n        include $f;"))
            .toBe(false);
    });

    it("tace sui commenti che spiegano la forma sbagliata", () => {
        // Senza questo passaggio la guardia segnalerebbe le proprie
        // spiegazioni, e una guardia che grida al lupo sulla documentazione
        // viene spenta entro la settimana.
        expect(scatta("commento", "        // prima era dirname(__DIR__, 2) . '/storage/vecchio.json'\n        $f = \\App\\Support\\PercorsiDati::base(dirname(__DIR__, 2)) . '/storage/nuovo.json';\n        file_put_contents($f, 'x');"))
            .toBe(false);
    });

    it("il codice del repository la rispetta", () => {
        expect(() => execFileSync(process.execPath, [GUARDIA], { stdio: "pipe" })).not.toThrow();
    });
});
