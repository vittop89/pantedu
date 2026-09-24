// @ts-check
/**
 * «Dove vale» per le verifiche (ADR-037, fase 3).
 *
 * Una verifica arriva agli studenti dove è PUBBLICATA, non perché è condivisa
 * con i colleghi: prima le due cose erano la stessa casella. La prima prova
 * passa dalle API del docente: il posto principale nasce in bozza per tutte
 * le varianti, condividere con i colleghi non lo cambia, lo stato si sceglie,
 * e per un altro docente la verifica non esiste. La seconda usa il modale
 * della verifica: cambia lo stato e fa una copia indipendente, poi controlla
 * sul server.
 *
 * La terza prova entra come la classe, con una credenziale di classe vera: la
 * verifica compare nell'elenco di studio solo mentre è pubblicata, e
 * condividerla con i colleghi non basta. Fino al 14/9/2026 l'ospite con la
 * credenziale riceveva 401 da tutte le API di studio, e questa prova non si
 * poteva scrivere. Lo studente con account lo provano le PHPUnit
 * (VerifichePubblicazioniTest): la suite non ne ha uno.
 *
 * Le verifiche nascono dalla factory nella terna della sessione, che ne
 * registra la cancellazione; le copie si cancellano per titolo a fine prova.
 */
const { test, expect } = require("../support/test");

/** Le opzioni della factory per una verifica di due varianti nella terna data. */
function nellaTerna(/** @type {{indirizzo:string, classe:string, materia:string}} */ terna) {
    const { indirizzo, classe, materia } = terna;
    return {
        versions: ["A"],
        overrides: {
            selectedIIS: indirizzo, selectedCLS: classe, selectedMATER: materia,
            indirizzo, classe, materia, sezione: "NOR", nPrint: 1, nPrintDSA: 0, nPrintDIS: 0,
        },
    };
}

test.describe("Verifiche — Dove vale", () => {
    test("il posto principale nasce in bozza per tutte le varianti, condividere non lo pubblica, e per un altro docente non esiste", async ({
        verificaFactory, teacherApi, teacher2Api, naming, env,
    }) => {
        const http = teacherApi.http;
        const salvata = await verificaFactory.batch({ title: naming.unique("dove-vale-verifica"), ...nellaTerna(env.terna) });
        const varianti = salvata.docs.map((d) => Number(d.id));
        expect(varianti.length, "almeno due varianti").toBeGreaterThanOrEqual(2);

        /** @returns {Promise<{ id: number, principale: boolean, stato: string, varianti: number } | undefined>} */
        const principale = async () => {
            /** @type {{ ok: boolean, verifica: { varianti: number }, posti: Array<{ id: number, principale: boolean, stato: string, varianti: number }> }} */
            const elenco = await http.getJson(`/api/verifica/${varianti[0]}/pubblicazioni`);
            expect(elenco.ok).toBe(true);
            expect(elenco.verifica.varianti, "la verifica conta tutte le sue varianti").toBe(varianti.length);
            return elenco.posti.find((p) => p.principale);
        };
        const prima = await principale();
        expect(prima, "la verifica ha il suo posto principale").toBeTruthy();
        if (!prima) return;
        expect(prima.stato, "e nasce in bozza").toBe("draft");
        expect(prima.varianti, "per tutte le varianti").toBe(varianti.length);

        for (const id of varianti) {
            /** @type {{ ok: boolean, shared_with_pool: boolean }} */
            const condivisa = await http.postForm(`/api/verifica/${id}/share-pool`, { enabled: "1" });
            expect(condivisa.ok && condivisa.shared_with_pool, `la variante ${id} è condivisa davvero`).toBe(true);
        }
        expect((await principale())?.stato, "condivisa con i colleghi, resta in bozza").toBe("draft");

        /** @type {{ ok: boolean, varianti: number }} */
        const pubblicata = await http.postForm(`/api/verifica/${varianti[0]}/pubblicazioni/${prima.id}/stato`, { stato: "published" });
        expect(pubblicata.varianti, "lo stato cambia per tutte le varianti").toBe(varianti.length);
        expect((await principale())?.stato).toBe("published");

        const altro = await teacher2Api.http.send("GET", `/api/verifica/${varianti[0]}/pubblicazioni`);
        expect(altro.status, "per un altro docente la verifica non esiste (S1)").toBe(404);
        const tentativo = await teacher2Api.http.send("POST", `/api/verifica/${varianti[0]}/pubblicazioni/${prima.id}/stato`, { form: { stato: "draft" } });
        expect(tentativo.status, "e non ne cambia lo stato").toBe(404);
        expect((await principale())?.stato, "che resta quello scelto dal proprietario").toBe("published");
    });

    test("la classe con la credenziale vede la verifica solo mentre è pubblicata; condividerla non basta", async ({
        page, accessoClasse, credentialFactory, verificaFactory, teacherApi, naming, env,
    }) => {
        const http = teacherApi.http;
        const salvata = await verificaFactory.batch({ title: naming.unique("verifica-per-la-classe"), ...nellaTerna(env.terna) });
        const varianti = salvata.docs.map((d) => Number(d.id));
        /** @type {{ posti: Array<{ id: number, principale: boolean }> }} */
        const elenco = await http.getJson(`/api/verifica/${varianti[0]}/pubblicazioni`);
        const principale = elenco.posti.find((p) => p.principale);
        expect(principale, "la verifica ha il suo posto principale").toBeTruthy();
        if (!principale) return;

        const credenziale = await credentialFactory.create({ indirizzo: env.terna.indirizzo, classe: env.terna.classe });
        await accessoClasse.vaiA();
        await accessoClasse.entra(credenziale.username, credenziale.password);
        const visteDallaClasse = async () => {
            const r = await page.request.get("/api/study/verifica/list", {
                params: { indirizzo: env.terna.indirizzo, classe: env.terna.classe },
                headers: { Accept: "application/json" },
            });
            expect(r.status(), "l'ospite con la credenziale riceve l'elenco").toBe(200);
            /** @type {{ items: Array<{ id: number|string }> }} */
            const j = await r.json();
            return j.items.map((i) => Number(i.id)).filter((id) => varianti.includes(id)).sort((a, b) => a - b);
        };
        expect(await visteDallaClasse(), "in bozza la classe non la vede").toEqual([]);

        const condivisa = await teacherApi.verifica.setPoolSharing(Number(varianti[0]), true);
        expect(condivisa.status, condivisa.text.slice(0, 200)).toBe(200);
        expect(await visteDallaClasse(), "condivisa con i colleghi, ancora no").toEqual([]);

        await http.postForm(`/api/verifica/${varianti[0]}/pubblicazioni/${principale.id}/stato`, { stato: "published" });
        expect(await visteDallaClasse(), "pubblicata, la classe vede tutte le varianti").toEqual([...varianti].sort((a, b) => a - b));

        await http.postForm(`/api/verifica/${varianti[0]}/pubblicazioni/${principale.id}/stato`, { stato: "draft" });
        expect(await visteDallaClasse(), "tornata in bozza, sparisce").toEqual([]);
    });

    test("dal modale si cambia lo stato e si fa una copia indipendente", async ({
        verificaFactory, contentFactory, teacherApi, homeDocente, studioEsercizio, teacherPage, cleanup, naming, env,
    }) => {
        const http = teacherApi.http;
        const { indirizzo, classe, materia } = env.terna;
        const titolo = naming.unique("modale-dove-vale");
        const salvata = await verificaFactory.batch({ title: titolo, ...nellaTerna(env.terna) });
        const varianti = salvata.docs.map((d) => Number(d.id));

        /** @type {{ scuole: Array<{ id: number, indirizzi: Array<{id:number,code:string}>, classi: Array<{id:number,code:string,indirizzo:string|null}>, materie: Array<{id:number,code:string}> }> }} */
        const luoghi = await http.getJson("/api/teacher/pubblicazioni/luoghi");
        const scuola = luoghi.scuole.find((s) => s.id === Number(env.discovered.scuolaId));
        const voce = (/** @type {Array<{id:number,code:string}>} */ elenco, /** @type {string} */ codice) =>
            elenco.find((v) => v.code.toUpperCase() === codice.toUpperCase()) || null;
        // ADR-042 — un anno esiste una volta per corso: la classe della terna è
        // quella del suo indirizzo. Cercata per sola sigla, a seconda dell'ordine
        // prendeva la «2» dell'artistico, che sotto lo scientifico non c'è
        // (giro su main del 15/9/2026, 35015474642).
        const posto = scuola && {
            indirizzo: voce(scuola.indirizzi, indirizzo),
            classe: voce(scuola.classi.filter((c) => !c.indirizzo || c.indirizzo.toUpperCase() === indirizzo.toUpperCase()), classe),
            materia: voce(scuola.materie, materia),
        };
        // Era un `test.skip` fino al 23/9/2026 (A-29): la terna la spunta la
        // semina della CI, e se manca la prova fallisce dicendo che cosa ha
        // trovato.
        expect(Boolean(posto && posto.indirizzo && posto.classe && posto.materia),
            `il docente di prova ha spuntato la terna ${indirizzo}/${classe}/${materia} nella scuola ${env.discovered.scuolaId}: `
            + JSON.stringify({ scuole: luoghi.scuole.map((s) => s.id), posto })).toBe(true);
        if (!scuola || !posto || !posto.indirizzo || !posto.classe || !posto.materia) return;

        cleanup.add("teacher", "cancella le copie della verifica", async () => {
            /** @type {{ items: Array<{ id: number|string, title?: string }> }} */
            const j = await http.getJson("/api/verifica/list");
            const copie = j.items.filter((i) => String(i.title ?? "").startsWith(`${titolo} (copia`));
            // Cancellare una variante cancella tutto il suo pacchetto: le altre
            // rispondono 404, ed è l'esito atteso.
            for (const c of copie) {
                const esito = await http.send("POST", `/api/verifica/${Number(c.id)}/delete`, { form: {} });
                if (!esito.ok && esito.status !== 404) {
                    throw new Error(`POST /api/verifica/${c.id}/delete → ${esito.status}: ${esito.text.slice(0, 200)}`);
                }
            }
        });

        const esercizio = await contentFactory.exercise({ terna: env.terna, groups: 1, itemsPerGroup: 1, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await homeDocente.scegliTerna(indirizzo, classe, materia);
        const pannello = await homeDocente.apriSidepage("verifiche");
        await homeDocente.attendiVerificheGenerate(pannello);
        const riga = pannello.locator("li[data-fm-content-kind='verifica']").filter({ hasText: titolo }).first();
        await expect(riga, "la verifica è nel pannello").toBeVisible({ timeout: 30_000 });
        await riga.locator(".fm-vd-link").click();

        const doveVale = teacherPage.locator("#fm-vd-detail-modal .fm-vd-detail-dove-vale");
        const principale = doveVale.locator('li.fm-dove-vale-riga[data-principale="1"]');
        await expect(principale, "il modale mostra il posto principale").toHaveCount(1, { timeout: 30_000 });
        await principale.locator("select").selectOption("published");
        await expect(doveVale.locator(".fm-dove-vale-riscontro"), "il modale conferma").toContainText("Stato aggiornato");

        await expect.poll(async () => {
            /** @type {{ posti: Array<{ principale: boolean, stato: string }> }} */
            const e = await http.getJson(`/api/verifica/${varianti[0]}/pubblicazioni`);
            return e.posti.find((p) => p.principale)?.stato;
        }, { message: "e il server dice lo stesso" }).toBe("published");

        const copia = doveVale.locator(".fm-dove-vale-duplica");
        await copia.locator('select[name="fm-pub-copia-scuola"]').selectOption(String(scuola.id));
        await copia.locator('select[name="fm-pub-copia-indirizzo"]').selectOption(String(posto.indirizzo.id));
        await copia.locator('select[name="fm-pub-copia-classe"]').selectOption(String(posto.classe.id));
        await copia.locator('select[name="fm-pub-copia-materia"]').selectOption(String(posto.materia.id));
        await copia.getByRole("button", { name: "Duplica qui" }).click();
        await expect(doveVale.locator(".fm-dove-vale-riscontro"), "il modale conferma la copia").toContainText("Copia creata");

        /** @type {{ items: Array<{ id: number|string, title?: string }> }} */
        const dopo = await http.getJson("/api/verifica/list");
        const copie = dopo.items.filter((i) => String(i.title ?? "").startsWith(`${titolo} (copia) — `));
        expect(copie.length, "la copia ha tutte le varianti, con il suo titolo").toBe(varianti.length);
        for (const c of copie) {
            expect(varianti, "e sono righe nuove").not.toContain(Number(c.id));
        }
        const primaCopia = copie[0];
        if (!primaCopia) return;
        /** @type {{ posti: Array<{ principale: boolean, stato: string }> }} */
        const postiCopia = await http.getJson(`/api/verifica/${Number(primaCopia.id)}/pubblicazioni`);
        expect(postiCopia.posti.find((p) => p.principale)?.stato, "la copia nasce in bozza").toBe("draft");
    });
});
