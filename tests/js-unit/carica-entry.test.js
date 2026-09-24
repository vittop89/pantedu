import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { execFileSync } from "node:child_process";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Un caricatore solo per le entry di Vite (revisione architetturale del
 * 23/9/2026, A-46).
 *
 * Tredici copie in nove file leggevano il manifest e importavano l'entry;
 * tre non avevano né cache-bust né controllo degli errori, e dopo un rilascio
 * un manifest vecchio faceva fallire l'import in silenzio: il pulsante non
 * faceva niente. Qui si prova il caricatore unico e si fissa che le copie non
 * tornino.
 */

const { notify } = vi.hoisted(() => ({ notify: vi.fn() }));
vi.mock("../../js/modules/ui/sync-panel.js", () => ({ notify }));

import { caricaEntry } from "../../js/modules/core/carica-entry.js";

const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const MANIFEST = {
    "js/entries/geogebra-editor.js": { file: "assets/geogebra-editor-NUOVO.js" },
};

let richieste;
let erroriInConsole;

function risposta(stato, corpo) {
    return new Response(JSON.stringify(corpo), { status: stato, headers: { "Content-Type": "application/json" } });
}

beforeEach(() => {
    richieste = [];
    notify.mockClear();
    erroriInConsole = vi.spyOn(console, "error").mockImplementation(() => {});
    vi.stubGlobal("fetch", vi.fn(async (url, opzioni = {}) => {
        richieste.push({ url: String(url), opzioni });
        return risposta(200, MANIFEST);
    }));
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

/** Aspetta l'avviso, che arriva da un import dinamico del pannello. */
async function avvisoArrivato() {
    await vi.waitFor(() => expect(notify).toHaveBeenCalled());
    const [titolo, tipo, testo] = notify.mock.calls.at(-1);
    return { titolo, tipo, testo };
}

describe("caricaEntry", () => {
    it("legge il manifest fresco e importa il file con l'impronta", async () => {
        const importa = vi.fn(async () => {});

        await caricaEntry("js/entries/geogebra-editor.js", { importa });

        expect(richieste).toHaveLength(1);
        expect(richieste[0].url).toMatch(/^\/build\/manifest\.json\?t=\d+$/);
        expect(richieste[0].opzioni.cache).toBe("no-store");
        expect(importa).toHaveBeenCalledWith("/build/assets/geogebra-editor-NUOVO.js");
        expect(notify).not.toHaveBeenCalled();
    });

    it("se l'entry è già pronta non chiede niente alla rete", async () => {
        const importa = vi.fn(async () => {});

        await caricaEntry("js/entries/geogebra-editor.js", { pronto: () => true, importa });

        expect(richieste).toEqual([]);
        expect(importa).not.toHaveBeenCalled();
    });

    it("due richieste contemporanee della stessa entry fanno un caricamento solo", async () => {
        const importa = vi.fn(async () => {});

        await Promise.all([
            caricaEntry("js/entries/geogebra-editor.js", { importa }),
            caricaEntry("js/entries/geogebra-editor.js", { importa }),
        ]);

        expect(richieste).toHaveLength(1);
        expect(importa).toHaveBeenCalledOnce();
    });

    it("manifest vecchio dopo un rilascio: il file non c'è più, e non finisce in silenzio", async () => {
        const importa = vi.fn(async (url) => {
            throw new TypeError(`Failed to fetch dynamically imported module: ${url}`);
        });

        await expect(caricaEntry("js/entries/geogebra-editor.js", { importa }))
            .rejects.toThrow("Failed to fetch dynamically imported module");

        expect(erroriInConsole).toHaveBeenCalled();
        const avviso = await avvisoArrivato();
        expect(avviso.tipo).toBe("error");
        expect(avviso.testo).toContain("geogebra-editor");
        expect(avviso.testo).toContain("ricarica la pagina");
    });

    it("un'entry che il manifest non conosce è un errore che la nomina", async () => {
        await expect(caricaEntry("js/entries/non-esiste.js", { importa: vi.fn() }))
            .rejects.toThrow("js/entries/non-esiste.js non è nel manifest");
        expect((await avvisoArrivato()).testo).toContain("non-esiste");
    });

    it("un manifest che non arriva è un errore con lo stato HTTP", async () => {
        vi.stubGlobal("fetch", vi.fn(async () => risposta(404, {})));

        await expect(caricaEntry("js/entries/geogebra-editor.js", { importa: vi.fn() }))
            .rejects.toThrow("manifest HTTP 404");
        expect((await avvisoArrivato()).tipo).toBe("error");
    });

    it("un modulo arrivato che non si registra è un errore, non un pulsante muto", async () => {
        await expect(caricaEntry("js/entries/geogebra-editor.js", {
            pronto: () => false, importa: vi.fn(async () => {}),
        })).rejects.toThrow("non si è registrato");
        expect((await avvisoArrivato()).tipo).toBe("error");
    });

    it("dopo un fallimento, la volta dopo si riprova davvero", async () => {
        const importa = vi.fn()
            .mockRejectedValueOnce(new Error("rete giù"))
            .mockResolvedValueOnce(undefined);

        await expect(caricaEntry("js/entries/geogebra-editor.js", { importa })).rejects.toThrow("rete giù");
        await expect(caricaEntry("js/entries/geogebra-editor.js", { importa })).resolves.toBeUndefined();
        expect(importa).toHaveBeenCalledTimes(2);
    });
});

describe("le copie del caricatore non tornano", () => {
    it("il manifest di Vite lo legge solo core/carica-entry.js", () => {
        let trovati = "";
        try {
            trovati = execFileSync("git", ["grep", "--untracked", "-l", "/build/manifest.json", "--", "js"], {
                cwd: RADICE, encoding: "utf8",
            });
        } catch (e) {
            if (e.status !== 1) throw e; // 1 = nessun riscontro
        }
        expect(trovati.split("\n").filter(Boolean)).toEqual(["js/modules/core/carica-entry.js"]);
    });
});
