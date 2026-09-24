import { describe, it, expect, afterEach } from "vitest";
import { execFileSync } from "node:child_process";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { confirmDialog } from "../../js/modules/editor/tex-dropdown-helpers.js";

/**
 * Il dialogo di conferma scuro è uno (revisione architetturale del 23/9/2026,
 * A-21). Ce n'erano tre copie quasi uguali — editor/tex-dropdown-helpers.js,
 * entries/tikz-template-filler.js (`confirmAsync`), features/admin-tikz-
 * templates.js — ognuna con il suo escape: due con una copia locale, una con
 * `escAttr`. Qui si prova quello che resta, e che le copie non tornino.
 */

const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");

afterEach(() => { document.body.replaceChildren(); });

const dialogo = () => document.getElementById("fm-confirm-dialog");
const premi = (tasto) => document.dispatchEvent(new KeyboardEvent("keydown", { key: tasto }));

describe("confirmDialog", () => {
    it("mostra titolo, messaggio ed etichette come testo, non come HTML", async () => {
        const esito = confirmDialog({
            title: "<img src=x onerror=alert(1)>",
            message: "Eliminare «<b>Moto</b>»?",
            confirmLabel: "Sì & basta",
            cancelLabel: "No",
        });
        const d = dialogo();
        expect(d).not.toBeNull();
        expect(d.querySelector("img")).toBeNull();
        expect(d.querySelector("b")).toBeNull();
        expect(d.textContent).toContain("<img src=x onerror=alert(1)>");
        expect(d.textContent).toContain("Eliminare «<b>Moto</b>»?");
        expect(d.querySelector('[data-act="ok"]').textContent).toBe("Sì & basta");
        premi("Escape");
        await esito;
    });

    it("OK conferma, Annulla e il clic fuori no, e il dialogo sparisce", async () => {
        let esito = confirmDialog();
        dialogo().querySelector('[data-act="ok"]').click();
        await expect(esito).resolves.toBe(true);
        expect(dialogo()).toBeNull();

        esito = confirmDialog();
        dialogo().querySelector('[data-act="cancel"]').click();
        await expect(esito).resolves.toBe(false);

        esito = confirmDialog();
        dialogo().click();
        await expect(esito).resolves.toBe(false);
    });

    it("Invio conferma, Esc annulla", async () => {
        let esito = confirmDialog();
        premi("Enter");
        await expect(esito).resolves.toBe(true);

        esito = confirmDialog();
        premi("Escape");
        await expect(esito).resolves.toBe(false);
    });
});

describe("le copie del dialogo non tornano", () => {
    it("un solo modulo definisce il dialogo di conferma scuro", () => {
        let trovati = "";
        try {
            trovati = execFileSync("git", ["grep", "--untracked", "-lE",
                "function (confirmDialog|confirmAsync)\\b", "--", "js"], { cwd: RADICE, encoding: "utf8" });
        } catch (e) {
            if (e.status !== 1) throw e;
        }
        expect(trovati.split("\n").filter(Boolean)).toEqual(["js/modules/editor/tex-dropdown-helpers.js"]);
    });
});
