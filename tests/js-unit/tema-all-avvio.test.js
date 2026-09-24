import { describe, it, expect, beforeEach, afterEach } from "vitest";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Il tema all'avvio: una politica, scritta in un posto (revisione
 * architetturale del 23/9/2026, A-50).
 *
 * Si decideva in sei punti con quattro politiche. Alla prima visita, con il
 * sistema in chiaro, views/partials/head.php diceva «chiaro» e lo script in
 * cima al body di views/layout/app.php diceva «scuro» (nessuna scelta salvata
 * = scuro): la pagina restava scura finché il JavaScript dell'app non la
 * correggeva. Ora la politica sta in js/tema-iniziale.js, che il PHP mette in
 * linea nel <head> e l'app importa; gli altri leggono la sua decisione.
 *
 * La politica: la scelta salvata vince ("1" scuro, "0" chiaro); senza una
 * scelta, la preferenza del sistema; se il browser non la dice, scuro.
 */

const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const POLITICA = readFileSync(join(RADICE, "js/tema-iniziale.js"), "utf8");

/** Lo script in cima al body di app.php, tolto il nonce di PHP. */
function scriptDelBodyDiApp() {
    const vista = readFileSync(join(RADICE, "views/layout/app.php"), "utf8");
    const m = vista.match(/<script<\?= \\App\\Support\\Csp::attributo\(\) \?>>([^<]*fm-dark[^<]*)<\/script>/);
    expect(m, "lo script del tema in cima al body di app.php").not.toBeNull();
    return m[1];
}

const esegui = (codice) => new Function(codice)();

let matchMediaOriginale;
let storageOriginale;

/** Il browser: la scelta salvata e la preferenza del sistema. */
function browser({ salvata, sistema }) {
    localStorage.clear();
    if (salvata === "eccezione") {
        Object.defineProperty(window, "localStorage", {
            configurable: true,
            get() { throw new DOMException("negato", "SecurityError"); },
        });
    } else if (salvata !== null) {
        localStorage.setItem("fm_dark_mode", salvata);
    }
    if (sistema === "assente") {
        window.matchMedia = undefined;
    } else {
        window.matchMedia = (q) => ({ media: q, matches: q === "(prefers-color-scheme: dark)" && sistema === "scuro" });
    }
}

const radice = () => document.documentElement;

beforeEach(() => {
    matchMediaOriginale = window.matchMedia;
    storageOriginale = Object.getOwnPropertyDescriptor(window, "localStorage");
    radice().removeAttribute("data-theme");
    radice().classList.remove("fm-dark-pre");
    document.body.classList.remove("fm-dark");
    delete window.fmTemaScuro;
});

afterEach(() => {
    // Il localStorage vero torna al suo posto, se una prova l'ha coperto.
    if (storageOriginale) Object.defineProperty(window, "localStorage", storageOriginale);
    else delete window.localStorage;
    window.matchMedia = matchMediaOriginale;
});

describe("la politica (js/tema-iniziale.js)", () => {
    it.each([
        ["scelta scura, sistema chiaro", { salvata: "1", sistema: "chiaro" }, true],
        ["scelta chiara, sistema scuro", { salvata: "0", sistema: "scuro" }, false],
        ["prima visita, sistema chiaro", { salvata: null, sistema: "chiaro" }, false],
        ["prima visita, sistema scuro", { salvata: null, sistema: "scuro" }, true],
        ["prima visita, il browser non dice la preferenza", { salvata: null, sistema: "assente" }, true],
        ["storage negato, sistema chiaro", { salvata: "eccezione", sistema: "chiaro" }, false],
        ["valore sconosciuto, sistema chiaro", { salvata: "forse", sistema: "chiaro" }, false],
    ])("%s → scuro: %s", (_caso, stato, scuro) => {
        browser(stato);
        esegui(POLITICA);

        expect(window.fmTemaScuro()).toBe(scuro);
        expect(radice().getAttribute("data-theme")).toBe(scuro ? "dark" : "light");
        expect(radice().classList.contains("fm-dark-pre")).toBe(scuro);
        expect(document.body.classList.contains("fm-dark")).toBe(scuro);
    });

    it("rieseguita (in linea e poi dal bundle) non cambia la decisione", () => {
        browser({ salvata: null, sistema: "chiaro" });
        esegui(POLITICA);
        esegui(POLITICA);
        expect(radice().getAttribute("data-theme")).toBe("light");
        expect(document.body.classList.contains("fm-dark")).toBe(false);
    });
});

describe("chi arriva dopo legge la decisione, non ne prende un'altra", () => {
    it("prima visita con il sistema in chiaro: il body di app.php resta chiaro", () => {
        browser({ salvata: null, sistema: "chiaro" });
        esegui(POLITICA);
        document.body.classList.remove("fm-dark"); // il body nuovo, prima del suo script
        esegui(scriptDelBodyDiApp());
        expect(document.body.classList.contains("fm-dark")).toBe(false);
    });

    it("scelta scura: il body di app.php diventa scuro", () => {
        browser({ salvata: "1", sistema: "chiaro" });
        esegui(POLITICA);
        document.body.classList.remove("fm-dark");
        esegui(scriptDelBodyDiApp());
        expect(document.body.classList.contains("fm-dark")).toBe(true);
    });

    it("solo js/tema-iniziale.js legge la scelta salvata e la preferenza del sistema", () => {
        const cerca = (schema) => {
            try {
                return execFileSync("git", ["grep", "--untracked", "-lE", schema, "--", "js", "views", "app"], {
                    cwd: RADICE, encoding: "utf8",
                }).split("\n").filter(Boolean);
            } catch (e) {
                if (e.status === 1) return [];
                throw e;
            }
        };
        expect(cerca("getItem\\([\"']fm_dark_mode[\"']\\)")).toEqual(["js/tema-iniziale.js"]);
        expect(cerca("matchMedia\\([\"']\\(prefers-color-scheme")).toEqual(["js/tema-iniziale.js"]);
    });

    it("le due impaginazioni dell'app mettono la politica in linea nel <head>", () => {
        for (const vista of ["views/partials/head.php", "views/layout/shell.php"]) {
            const testo = readFileSync(join(RADICE, vista), "utf8");
            const head = testo.slice(0, testo.indexOf("</head>") > 0 ? testo.indexOf("</head>") : undefined);
            expect(head, vista).toContain("\\App\\Support\\TemaIniziale::script()");
        }
    });
});
