// @ts-check
/**
 * Scorciatoie da tastiera nell'editor e bozza salvata da sola.
 * Riscrittura di due dei tre casi di g19_15_editor_enhancements.spec.js
 * (il terzo, la disposizione delle schede della creazione guidata, è passato a
 * `studio/creazione-esercizio.spec.js`, dove sta la finestra che le contiene).
 *
 * Il campo dell'editor è un `contenteditable`: Ctrl+B e Ctrl+I marcano la
 * selezione. Le scorciatoie del sorgente TeX (Ctrl+M, Tab, parentesi che si
 * chiudono da sole) valgono solo sul campo testuale, e l'applicazione le salta
 * di proposito sul campo visuale.
 *
 * La bozza: mentre si scrive, l'editor salva da solo in IndexedDB così che una
 * chiusura accidentale non perda il lavoro.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente attese a tempo
 * (dei 2500 millisecondi fissi prima di ogni apertura non resta nulla), e
 * soprattutto la bozza non viene più scritta dal test — prima la spec la
 * infilava in IndexedDB da sé e poi verificava di ritrovarla, cioè verificava
 * IndexedDB, non il salvataggio automatico. Ora si scrive nel campo e si
 * aspetta che sia l'applicazione a salvarla.
 */
const { test, expect } = require("../support/test");

test.describe("Editor — scorciatoie e bozze", () => {
    test("Ctrl+B e Ctrl+I marcano il testo selezionato", async ({ contentFactory, studioEsercizio, teacherPage }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);

        const pannello = await studioEsercizio.apriEditorQuesito(studioEsercizio.gruppo(0), 0);
        const campo = pannello.locator(".fm-editor-field").first();
        await expect(campo, "il campo è stato arricchito dall'editor").toHaveAttribute("data-fm-enhanced", "1");

        const scriviESeleziona = async (/** @type {string} */ testo) => {
            await campo.click();
            await campo.evaluate((el, valore) => {
                el.textContent = valore;
                const intervallo = document.createRange();
                intervallo.selectNodeContents(el);
                const selezione = window.getSelection();
                selezione?.removeAllRanges();
                selezione?.addRange(intervallo);
            }, testo);
        };

        await scriviESeleziona("ciao");
        await teacherPage.keyboard.press("Control+B");
        await expect
            .poll(async () => campo.innerHTML(), { message: "grassetto sulla selezione", timeout: 10_000 })
            .toMatch(/<(b|strong)>ciao<\/(b|strong)>|\\textbf\{ciao\}/);

        await scriviESeleziona("hello");
        await teacherPage.keyboard.press("Control+I");
        await expect
            .poll(async () => campo.innerHTML(), { message: "corsivo sulla selezione", timeout: 10_000 })
            .toMatch(/<(i|em)>hello<\/(i|em)>|\\textit\{hello\}/);
    });

    test("mentre si scrive, l'editor salva da solo una bozza", async ({ contentFactory, studioEsercizio, teacherPage }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);

        const pannello = await studioEsercizio.apriEditorQuesito(studioEsercizio.gruppo(0), 0);
        await expect
            .poll(async () => teacherPage.evaluate(() => !!window.FM?.["EditorDraft"]), { message: "l'editor espone le bozze", timeout: 15_000 })
            .toBe(true);

        // La chiave sotto cui l'editor salva la bozza la scrive lui stesso sul
        // pannello: leggerla da lì evita di indovinarla (e in questo caso
        // l'avremmo indovinata male, vedi la voce 42 del debito).
        await expect(pannello, "il pannello dichiara la chiave della bozza").toHaveAttribute("data-fm-draft-key", /.+/);
        const chiave = await pannello.getAttribute("data-fm-draft-key");

        const testo = `bozza automatica ${Date.now()}`;
        const campo = pannello.locator(".fm-editor-field").first();
        await campo.click();
        await teacherPage.keyboard.type(testo);

        // Il salvataggio è ritardato di qualche secondo dopo l'ultimo tasto:
        // non si aspetta un tempo, si aspetta che la bozza compaia.
        const leggiBozza = async () =>
            teacherPage.evaluate(async (id) => {
                const apertura = indexedDB.open("fm-editor-drafts", 1);
                const db = await new Promise((risolvi, rifiuta) => {
                    apertura.onsuccess = () => risolvi(apertura.result);
                    apertura.onerror = () => rifiuta(apertura.error);
                });
                if (!db.objectStoreNames.contains("drafts")) { db.close(); return null; }
                const bozza = await new Promise((risolvi) => {
                    const richiesta = db.transaction("drafts", "readonly").objectStore("drafts").get(id);
                    richiesta.onsuccess = () => risolvi(richiesta.result ?? null);
                    richiesta.onerror = () => risolvi(null);
                });
                db.close();
                return bozza ? JSON.stringify(bozza.fields ?? {}) : null;
            }, chiave);

        await expect
            .poll(leggiBozza, { message: "la bozza salvata dall'editor contiene quel che si è scritto", timeout: 30_000 })
            .toContain(testo);

        // Non si lascia in giro: la bozza vive nel browser del docente.
        await teacherPage.evaluate(async (id) => {
            const bozze = /** @type {{ drop?: (chiave: string) => Promise<void> }} */ (window.FM?.["EditorDraft"]);
            if (typeof bozze?.drop === "function") await bozze.drop(String(id));
        }, chiave);
    });
});
