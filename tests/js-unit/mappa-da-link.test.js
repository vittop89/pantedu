import { describe, it, expect } from "vitest";
import { readFileSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { eLinkDrive, messaggioErroreLink } from "../../js/modules/features/mappa-da-link.js";
import { buildModalHtml } from "../../js/modules/features/sidepage-modal-content.js";

/**
 * Tre segnalazioni dell'utente sul modale di creazione (15/9/2026).
 *
 * 1. Un drawio su Drive incollato in «🔗 Link esterno» nasceva come solo link, e
 *    la pagina diceva «orphan»: un link di Drive adesso si importa. Qui la parte
 *    del browser: quali link vanno a `/api/maps` con `mode=link`.
 * 2. «Nuova mappa drawio (vuota)»: l'editor si apriva sotto il modale.
 * 3. Il campo del link stava sopra, sempre visibile, lontano dalla sua spunta.
 */
const ID = "1AbCdEfGhIjKlMnOpQrStUvWxYz_-012";
const DRIVE = `https://drive.google.com/uc?id=${ID}&export=download`;
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");

describe("eLinkDrive", () => {
    it("il link di «Pubblica link» di diagrams.net e le forme di Drive", () => {
        for (const link of [
            `https://viewer.diagrams.net/?tags=%7B%7D&lightbox=1&highlight=0000ff&edit=_blank&layers=1&nav=1&title=fx.drawio&dark=auto#U${encodeURIComponent(DRIVE)}`,
            `https://drive.google.com/file/d/${ID}/view?usp=sharing`,
            `https://drive.google.com/open?id=${ID}`,
            DRIVE,
            `https://app.diagrams.net/#G${ID}`,
            `https://viewer.diagrams.net/?url=${encodeURIComponent(DRIVE)}`,
        ]) {
            expect(eLinkDrive(link), link).toBe(true);
        }
    });

    it("un sito, un PDF, un drawio inline o un indirizzo finto restano collegamenti", () => {
        for (const link of [
            "https://example.org/mappa.drawio",
            "https://viewer.diagrams.net/?lightbox=1#R7ZZNj5swEIb",
            `https://drive.google.com.example.org/file/d/${ID}`,
            `javascript:alert(1)//drive.google.com/file/d/${ID}`,
            "https://drive.google.com/open?id=corto",
            "",
        ]) {
            expect(eLinkDrive(link), link).toBe(false);
        }
    });

    it("gli errori del server diventano frasi, gli altri no", () => {
        expect(messaggioErroreLink("link_non_pubblico")).toContain("Chiunque abbia il link");
        expect(messaggioErroreLink("create_failed")).toBeNull();
    });

    it("«Carica file» con un file che non è un drawio: una frase, e non rimanda più a «Carica file»", () => {
        // 23/9/2026 (A-3): il server risponde drawio_non_valido per tutto ciò
        // che non è un XML drawio (app/Services/Maps/FileDrawio.php).
        expect(messaggioErroreLink("drawio_non_valido")).toContain(".drawio");
        expect(messaggioErroreLink("link_non_drawio")).not.toContain("Carica file");
    });
});

describe("il modale di creazione", () => {
    const dom = (html) => {
        const d = document.createElement("div");
        d.innerHTML = html;
        return d;
    };

    it("il campo del link sta subito sotto la spunta «🔗 Link esterno», e non più sopra", () => {
        const m = dom(buildModalHtml({ type: "mappa", mode: "create", row: {} }));
        const campi = m.querySelectorAll('input[name="href"]');
        expect(campi).toHaveLength(1);
        const sezione = campi[0].closest(".fm-modal-doc-section");
        expect(sezione?.dataset.fmDocMode).toBe("link");
        const spunta = m.querySelector('input[name="doc_mode"][value="link"]').closest("label");
        expect(spunta.nextElementSibling).toBe(sezione);
        expect(m.textContent).not.toContain("usa il campo \"Link esterno\" sopra");
    });

    it("«Carica file» propone solo i file drawio, come il server (23/9/2026)", () => {
        const m = dom(buildModalHtml({ type: "mappa", mode: "create", row: {} }));
        const file = m.querySelector('input[name="map_file"]');
        expect(file?.getAttribute("accept")?.split(",")).toEqual([".drawio", ".xml", "application/xml", "text/xml"]);
        const spunta = m.querySelector('input[name="doc_mode"][value="upload"]').closest("label");
        expect(spunta.textContent).toContain("drawio");
        expect(spunta.textContent).not.toMatch(/PDF|PNG|HTML/);
    });

    it("modificando una mappa, dove le spunte non ci sono, il campo resta", () => {
        const m = dom(buildModalHtml({ type: "mappa", mode: "edit", row: { id: 7, metadata_json: JSON.stringify({ mappa: { href: "https://example.org/x" } }) } }));
        const campo = m.querySelector('input[name="href"]');
        expect(campo).not.toBeNull();
        expect(campo.closest(".fm-modal-doc-section")).toBeNull();
    });
});

describe("l'editor drawio", () => {
    const zIndex = (file, selettore) => {
        const css = readFileSync(join(RADICE, "css", "modules", file), "utf8");
        const blocco = css.match(new RegExp(`${selettore.replace(".", "\\.")}\\s*\\{([^}]*)\\}`));
        return Number((blocco?.[1].match(/z-index:\s*(\d+)/) || [])[1]);
    };

    it("si apre sopra il modale da cui parte", () => {
        const editor = zIndex("_drive-integration.css", ".fm-drawio-overlay");
        const modale = zIndex("_db-sidepage.css", ".fm-modal-backdrop");
        expect(Number.isFinite(editor) && Number.isFinite(modale)).toBe(true);
        expect(editor).toBeGreaterThan(modale);
    });
});
