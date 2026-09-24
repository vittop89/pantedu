// @ts-check
/**
 * Le lettere accentate di una mappa arrivano intatte: alla creazione, alla
 * lettura dal link firmato e dopo un salvataggio dell'editor.
 *
 * Nel 2025 il vecchio sito passava i drawio per `mb_convert_encoding($v,
 * 'UTF-8', 'auto')`: su un file lungo con pochi accenti PHP sceglieva ASCII e
 * ogni lettera accentata diventava «??». Così 107 mappe hanno perso gli accenti
 * (wiki/domains/mappe/mappe-overview.md, «Caratteri persi»). Il disegno di
 * questa prova è fatto apposta come quelli: 200 KB di solo ASCII e qualche
 * lettera accentata alla fine, cioè la forma in cui «auto» sbaglia.
 *
 * In Pantedu oggi passa: il difetto era nel codice del vecchio sito. La prova
 * serve a far diventare rossa la suite se una conversione del genere entra nel
 * percorso delle mappe. Controprova fatta in locale e non registrata: con
 * `mb_convert_encoding($xml, 'UTF-8', 'auto')` in MapsController::update la
 * prova deve fallire al confronto dopo il salvataggio.
 *
 * Il gettone arriva dal vero /auth/csrf (il client lo prende da sé); la mappa
 * ha un nome unico e il registro di pulizia la cancella anche se la prova
 * fallisce.
 */
const { test, expect } = require("../support/test");

const TESTO = "città è più 30 °C l’acqua 5 € COS'È? e ??? mm";

/** Un drawio con 200 KB di ASCII prima del testo accentato, come le mappe del 2025. */
function drawio(/** @type {string} */ testo) {
    let celle = "";
    for (let i = 2; celle.length < 200_000; i++) {
        celle += `<mxCell id="c${i}" value="nodo della mappa senza accenti" vertex="1" parent="1">`
            + `<mxGeometry x="10" y="${i}" width="120" height="40" as="geometry"/></mxCell>`;
    }
    const valore = testo.replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;");
    return '<mxfile host="e2e"><diagram id="d1" name="Pagina-1"><mxGraphModel><root>'
        + '<mxCell id="0"/><mxCell id="1" parent="0"/>' + celle
        + `<mxCell id="t" value="${valore}" vertex="1" parent="1"><mxGeometry as="geometry"/></mxCell>`
        + "</root></mxGraphModel></diagram></mxfile>";
}

test.describe("Mappe — le lettere accentate", () => {
    test("un disegno lungo con pochi accenti torna byte per byte, anche dopo il salvataggio dell'editor", async ({
        contentFactory, teacherApi,
    }) => {
        const primo = drawio(TESTO);
        const mappa = await contentFactory.map({ xml: primo });

        const letto = await teacherApi.maps.scarica(mappa.id);
        expect(letto.equals(Buffer.from(primo, "utf8")), "alla creazione: gli stessi byte").toBe(true);
        expect(letto.toString("utf8")).toContain("città è più 30 °C l’acqua 5 €");

        const contenuto = await teacherApi.content.get(mappa.id);
        const versione = Number(contenuto.content.map_version);
        expect(versione, "la versione che l'editor manda al salvataggio").toBeGreaterThan(0);

        const secondo = drawio(`${TESTO} — perché sì, già`);
        const salvata = await teacherApi.maps.aggiorna(mappa.id, secondo, versione);
        expect(salvata.status, salvata.text.slice(0, 200)).toBe(200);
        expect(salvata.body?.map_version).toBe(versione + 1);

        const riletto = await teacherApi.maps.scarica(mappa.id);
        expect(riletto.equals(Buffer.from(secondo, "utf8")), "dopo il salvataggio: gli stessi byte").toBe(true);
        expect(riletto.includes(Buffer.from("citt??", "utf8")), "nessuna lettera diventata «??»").toBe(false);

        // Un editor rimasto indietro di una versione non sovrascrive.
        const vecchio = await teacherApi.maps.aggiorna(mappa.id, primo, versione);
        expect(vecchio.status).toBe(409);
        expect(vecchio.body?.error).toBe("version_conflict");
        expect(vecchio.body?.server_version).toBe(versione + 1);
        const ancora = await teacherApi.maps.scarica(mappa.id);
        expect(ancora.equals(Buffer.from(secondo, "utf8")), "il 409 non ha toccato il disegno").toBe(true);
    });
});
