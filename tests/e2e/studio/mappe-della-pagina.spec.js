// @ts-check
/**
 * Le mappe nella pagina di studio: «Scarica .drawio» e il visualizzatore delle
 * mappe grandi (23/9/2026, revisione architetturale A-19, R-3 passo 3).
 *
 * Il file di ogni mappa arrivava alla pagina dentro due `<script>` in linea
 * scritti da StudyPageRenderer; adesso sta in un'isola JSON
 * (`<script type="application/json" data-fm-mappe-xml>`) e i due
 * comportamenti in js/modules/features/mappe-della-pagina.js, nel bundle. Qui
 * si guarda la pagina vera: il pulsante scarica il file uguale, byte per byte;
 * la cornice di una mappa grande riceve il file quando il visualizzatore manda
 * l'`init`; e il `</script>` nel file (in un CDATA, valido per FileDrawio) non
 * esce dall'isola né esegue niente. Vale con ogni modalità della CSP: il
 * 23/9/2026 è passata anche con quella rigorosa forzata sul server di prova.
 *
 * Il visualizzatore vero sta su viewer.diagrams.net: qui la rotta del browser
 * lo sostituisce con una pagina finta che manda l'`init` e annota il file che
 * riceve, così la prova non dipende dalla rete. Le mappe si creano via API,
 * con nomi unici, e le cancella il registro di pulizia.
 */
const fs = require("fs");
const crypto = require("crypto");
const { test, expect } = require("../support/test");

const OSTILE = "</script><script>window.__ostile = 1</script><!--<script>";

/** Un drawio con il contenuto ostile in un CDATA e qualche carattere da codificare. */
function drawio(/** @type {string} */ pagina) {
    return '<mxfile host="e2e"><diagram id="d" name="Pagina">' + pagina + "</diagram>"
        + "<nota><![CDATA[" + OSTILE + " & ' \" é]]></nota></mxfile>";
}

/** Il finto visualizzatore: manda l'init alla pagina e annota il file ricevuto. */
const FINTO_VISUALIZZATORE = `<!doctype html><title>attesa</title><script>
window.addEventListener("message", function (e) {
    try { var d = JSON.parse(e.data); } catch (_) { return; }
    if (d.action === "load") { window.__xml = d.xml; document.title = "ricevuto:" + d.xml.length; }
});
parent.postMessage(JSON.stringify({ event: "init" }), "*");
</script>`;

/** @param {{ indirizzo: string, classe: string, materia: string }} terna @param {string} topic */
function paginaDellaMappa(terna, topic) {
    return `/studio/mappa/${encodeURIComponent(terna.indirizzo)}/${encodeURIComponent(terna.classe)}`
        + `/${encodeURIComponent(terna.materia)}/${encodeURIComponent(topic)}`;
}

test.describe("Studio — le mappe nella pagina", () => {
    test("«Scarica .drawio» scarica il file uguale, e il </script> del file resta nell'isola", async ({
        contentFactory, teacherPage, env,
    }) => {
        const xml = drawio("piccola");
        const mappa = await contentFactory.map({ xml, terna: env.terna });

        await teacherPage.goto(paginaDellaMappa(env.terna, mappa.topic));
        await expect(teacherPage.locator("script[type='application/json'][data-fm-mappe-xml]")).toHaveCount(1);
        const pulsante = teacherPage.locator(`.fm-mappa-download-btn[data-fm-content-id="${mappa.id}"]`);
        await expect(pulsante).toBeVisible();

        const [scaricato] = await Promise.all([teacherPage.waitForEvent("download"), pulsante.click()]);
        expect(fs.readFileSync(String(await scaricato.path()), "utf8"), "lo stesso file, byte per byte").toBe(xml);
        expect(scaricato.suggestedFilename()).toMatch(/\.drawio$/);
        expect(await teacherPage.evaluate(() => /** @type {any} */ (window).__ostile), "niente eseguito dal file").toBeUndefined();
    });

    test("la cornice di una mappa grande riceve il file all'init del visualizzatore", async ({
        contentFactory, teacherPage, env,
    }) => {
        // Base64 di byte casuali: si comprime poco, e la mappa passa dal ramo
        // embed + postMessage invece che dal frammento #R.
        const xml = drawio(crypto.randomBytes(700 * 1024).toString("base64"));
        const mappa = await contentFactory.map({ xml, terna: env.terna });
        await teacherPage.route("https://viewer.diagrams.net/**", (route) => route.fulfill({
            status: 200, contentType: "text/html", body: FINTO_VISUALIZZATORE,
        }));

        await teacherPage.goto(paginaDellaMappa(env.terna, mappa.topic));
        await expect(teacherPage.locator(`[data-fm-mode="embed-blob"][data-id="${mappa.id}"]`)).toHaveCount(1);
        const cornice = teacherPage.frameLocator(`#fm-mappa-iframe-${mappa.id}`);
        await expect.poll(async () => cornice.locator("title").textContent().catch(() => ""), { timeout: 20_000 })
            .toBe(`ricevuto:${xml.length}`);
        const visualizzatore = teacherPage.frames().find((f) => f.url().startsWith("https://viewer.diagrams.net/"));
        expect(await visualizzatore?.evaluate(() => /** @type {any} */ (window).__xml), "il file intero").toBe(xml);
        expect(await teacherPage.evaluate(() => /** @type {any} */ (window).__ostile)).toBeUndefined();
    });
});
