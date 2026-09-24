// @ts-check
/**
 * Credenziali di classe dal profilo del docente.
 *
 * Negli scenari 1 e 2 gli studenti non hanno un account: entrano con una
 * credenziale che il docente crea dal proprio profilo. Il riquadro elenca le
 * credenziali e le governa senza ricaricare la pagina: dopo ogni azione
 * rilegge l'elenco dal server (piano classi-credenziali-scenari, C).
 *
 * Il difetto che la prima prova sorveglia: il 6 settembre 2026 la riga nuova
 * compariva solo dopo un ricaricamento, e «Spegni» sembrava volere due clic,
 * perché la lista tornava dalla cache del service worker invece che dalla
 * rete. La correzione sta in due posti — l'URL della rilettura cambia a ogni
 * chiamata, e dal 7 settembre tutte le GET sotto /api/ sono network-first —
 * e la prova guarda il risultato, non il meccanismo: la riga c'è subito, lo
 * stato cambia al primo clic, e il server dice la stessa cosa.
 *
 * Dal 19 settembre 2026 (ADR-044):
 * - il modulo dice le regole di username, password e aggiunta, e il fumetto
 *   del browser le ripete (prima: nessun testo, «Please match the requested
 *   format»);
 * - l'username è unico su tutta la piattaforma: un doppione è un 400 con una
 *   frase, non più un 500 con il testo SQL, e un altro docente non può
 *   riusarlo (prima: accettato, e gli studenti finivano nel portachiavi del
 *   primo);
 * - una scadenza passata è rifiutata, anche dalla riga;
 * - l'etichetta la compone il server: classe, indirizzo, materie in ordine
 *   alfabetico, aggiunta. Il modulo ne mostra l'anteprima; la riga offre
 *   «Rigenera etichetta».
 *
 * Le credenziali create dall'interfaccia si cancellano dall'API, anche se la
 * prova fallisce.
 */
const { test, expect } = require("../support/test");

/**
 * Un'aggiunta unica, di al massimo dieci caratteri: lettere, cifre e trattino,
 * come vuole la regola.
 * @param {string} prefisso
 */
function aggiuntaUnica(prefisso) {
    return `${prefisso}${Date.now().toString(36).slice(-7)}`.toUpperCase();
}

/**
 * L'etichetta attesa, composta come la compone il server: qui si scrive a mano
 * di proposito, perché la prova non deve usare lo stesso codice che controlla.
 * @param {string} perimetro «TUTTE» o «<CLASSE>_<INDIRIZZO>»
 * @param {readonly string[]} materie
 * @param {string} aggiunta
 */
function etichettaAttesa(perimetro, materie, aggiunta) {
    const sigle = [...new Set(materie.map((m) => m.toUpperCase()))].sort();
    return [perimetro, ...(sigle.length ? [sigle.join("-")] : []), ...(aggiunta ? [aggiunta.toUpperCase()] : [])].join("_");
}

test.describe("Area docente — credenziali di classe", () => {
    test("una credenziale creata dal profilo compare subito in elenco, e «Spegni» commuta al primo clic", async ({ pannelloCredenziali, teacherApi, naming, cleanup }) => {
        await pannelloCredenziali.vaiA();

        const utenza = naming.unique("accesso").toLowerCase().replace(/-/g, "_");
        const aggiunta = aggiuntaUnica("P");
        cleanup.add("teacher", `cancella la credenziale «${utenza}»`, async () => {
            const mia = (await teacherApi.credentials.list()).find((c) => c.access_username === utenza);
            if (mia) await teacherApi.credentials.delete(Number(mia.id));
        });
        // Il modulo propone tutte le materie spuntate dal docente, già spuntate.
        const materie = await pannelloCredenziali.caselleMaterie.evaluateAll((caselle) => caselle.map((c) => /** @type {HTMLInputElement} */ (c).value));
        const attesa = etichettaAttesa("TUTTE", materie, aggiunta);

        await pannelloCredenziali.crea({ username: utenza, password: "Pa55!profilo_e2e", aggiunta, perimetro: "tutte" });

        const riga = pannelloCredenziali.riga(utenza);
        await expect(riga, "la riga compare senza ricaricare la pagina").toHaveCount(1);
        await expect(riga, "attiva appena creata").toHaveAttribute("data-active", "1");
        await expect(pannelloCredenziali.etichetta(utenza), "con l'etichetta composta dal server").toHaveText(attesa);
        await expect(riga, "e valida per tutte le classi").toContainText("tutte");
        await expect(riga.getByLabel(/^Scadenza di/), "con una scadenza già scritta: fine anno scolastico").not.toHaveValue("");
        await expect(pannelloCredenziali.riscontro, "il modulo conferma").toContainText("Credenziale creata");

        await pannelloCredenziali.comando(utenza, "Spegni").click();
        await expect(riga, "spenta al primo clic").toHaveAttribute("data-active", "0");
        await expect(riga, "e la riga lo dice").toContainText("spenta");
        await expect(pannelloCredenziali.comando(utenza, "Attiva"), "il comando è diventato «Attiva»").toBeVisible();
        const spenta = (await teacherApi.credentials.list()).find((c) => c.access_username === utenza);
        expect(Number(spenta?.active), "e il server la dà spenta").toBe(0);

        await pannelloCredenziali.comando(utenza, "Attiva").click();
        await expect(riga, "riaccesa al primo clic").toHaveAttribute("data-active", "1");
        const riaccesa = (await teacherApi.credentials.list()).find((c) => c.access_username === utenza);
        expect(Number(riaccesa?.active), "e il server la dà attiva").toBe(1);
        expect(riaccesa?.label, "e la stessa etichetta").toBe(attesa);
    });

    test("il modulo dice le regole di username, password e aggiunta, e il browser le ripete", async ({ pannelloCredenziali }) => {
        await pannelloCredenziali.vaiA();

        const aiutoUsername = await pannelloCredenziali.aiutoDi(pannelloCredenziali.campoUsername);
        await expect(aiutoUsername, "la regola dell'username si legge sotto il campo").toBeVisible();
        await expect(aiutoUsername).toContainText("lettere senza accenti");
        await expect(aiutoUsername).toContainText("trattino basso");
        await expect(aiutoUsername, "con l'invito a non usare nomi").toContainText("Non usare nomi di persone");
        const aiutoPassword = await pannelloCredenziali.aiutoDi(pannelloCredenziali.campoPassword);
        await expect(aiutoPassword, "e quella della password").toContainText("senza spazi");
        const aiutoAggiunta = await pannelloCredenziali.aiutoDi(pannelloCredenziali.campoAggiunta);
        await expect(aiutoAggiunta, "e quella dell'aggiunta").toContainText("trattino");

        // Fuori regola: il fumetto del browser dice la regola.
        await pannelloCredenziali.campoUsername.fill("non valido");
        const fumettoUsername = await pannelloCredenziali.messaggioDelBrowser(pannelloCredenziali.campoUsername);
        expect(fumettoUsername, "username con uno spazio").toContain("lettere senza accenti");
        expect(fumettoUsername).toContain("trattino basso");
        await pannelloCredenziali.campoPassword.fill("🙂🙂🙂");
        expect(await pannelloCredenziali.messaggioDelBrowser(pannelloCredenziali.campoPassword), "password con le emoji").toContain("senza spazi");
        await pannelloCredenziali.campoAggiunta.fill("a b");
        expect(await pannelloCredenziali.messaggioDelBrowser(pannelloCredenziali.campoAggiunta), "aggiunta con lo spazio").toContain("trattino");

        // Verso opposto: corretti, il campo torna valido e il fumetto tace.
        await pannelloCredenziali.campoUsername.fill("abc_1");
        expect(await pannelloCredenziali.messaggioDelBrowser(pannelloCredenziali.campoUsername), "username nella regola").toBe("");
        await pannelloCredenziali.campoPassword.fill("Pa55!ok");
        expect(await pannelloCredenziali.messaggioDelBrowser(pannelloCredenziali.campoPassword), "password nella regola").toBe("");
        await pannelloCredenziali.campoAggiunta.fill("gruppo-b");
        expect(await pannelloCredenziali.messaggioDelBrowser(pannelloCredenziali.campoAggiunta), "aggiunta nella regola").toBe("");
    });

    test("il modulo rifiuta un username fuori formato senza creare niente", async ({ pannelloCredenziali, teacherApi }) => {
        await pannelloCredenziali.vaiA();
        const prima = (await teacherApi.credentials.list()).length;
        // Uno spazio non è ammesso: il modulo lo ferma prima di inviare.
        await pannelloCredenziali.crea({ username: "non valido", password: "Pa55!profilo_e2e", perimetro: "tutte" });
        await expect(pannelloCredenziali.riga("non valido"), "nessuna riga con quell'username").toHaveCount(0);
        expect((await teacherApi.credentials.list()).length, "e il server non ha creato niente").toBe(prima);
    });

    test("l'etichetta la compone il server: l'anteprima, la riga e l'elenco dicono la stessa cosa", async ({ pannelloCredenziali, teacherApi, naming, cleanup }) => {
        await pannelloCredenziali.vaiA();
        // Le materie che il modulo offre: quelle spuntate dal docente nell'istituto attivo.
        const disponibili = await pannelloCredenziali.caselleMaterie.evaluateAll((caselle) => caselle.map((c) => /** @type {HTMLInputElement} */ (c).value));
        expect(disponibili.length, "il docente di prova ha materie spuntate nell'istituto").toBeGreaterThan(0);
        // Due materie, nell'ordine inverso: il server le rimette in ordine alfabetico.
        const scelte = disponibili.slice(-2).reverse();
        const aggiunta = aggiuntaUnica("g-");
        const attesa = etichettaAttesa("TUTTE", scelte, aggiunta);
        const utenza = naming.unique("etichetta").toLowerCase().replace(/-/g, "_");
        cleanup.add("teacher", `cancella la credenziale «${utenza}»`, async () => {
            const mia = (await teacherApi.credentials.list()).find((c) => c.access_username === utenza);
            if (mia) await teacherApi.credentials.delete(Number(mia.id));
        });

        const perIlServer = (await teacherApi.credentials.materieDisponibili()).map((m) => m.code);
        expect([...disponibili].sort(), "una casella per ogni materia spuntata, come dice il server").toEqual([...perIlServer].sort());
        for (const casella of await pannelloCredenziali.caselleMaterie.all()) {
            await expect(casella, "tutte spuntate in partenza").toBeChecked();
        }
        await pannelloCredenziali.compila({ username: utenza, password: "Pa55!etichetta_e2e", aggiunta: aggiunta.toLowerCase(), materie: scelte, perimetro: "tutte" });
        await expect(pannelloCredenziali.anteprima, "l'anteprima è quella del server, in maiuscolo").toHaveText(attesa);

        await pannelloCredenziali.modulo.getByRole("button", { name: "Crea" }).click();
        await expect(pannelloCredenziali.etichetta(utenza), "la riga porta la stessa etichetta").toHaveText(attesa);
        const creata = (await teacherApi.credentials.list()).find((c) => c.access_username === utenza);
        expect(creata?.label, "e il server la conserva").toBe(attesa);
        expect(creata?.materie, "con le parti, per ricomporla").toBe([...scelte].sort().join(","));
    });

    test("«Rigenera etichetta» dalla riga ricompone l'etichetta, e non ne copia una già usata", async ({ pannelloCredenziali, credentialFactory, teacherApi }) => {
        const prima = await credentialFactory.create();
        const seconda = await credentialFactory.create();
        const nuova = aggiuntaUnica("R");

        await pannelloCredenziali.vaiA();
        await pannelloCredenziali.rigeneraEtichetta(seconda.username, [], nuova.toLowerCase());
        await expect(pannelloCredenziali.etichetta(seconda.username), "la riga ha l'etichetta nuova").toHaveText(`TUTTE_${nuova}`);
        await expect(pannelloCredenziali.riscontro, "e il modulo lo dice").toContainText("Nuova etichetta");

        // Verso opposto: la stessa etichetta della prima credenziale è rifiutata.
        await pannelloCredenziali.rigeneraEtichetta(seconda.username, [], prima.aggiunta);
        await expect(pannelloCredenziali.riscontro, "con l'invito a usare un'aggiunta").toContainText("Hai già una credenziale con questa etichetta");
        const elenco = await teacherApi.credentials.list();
        expect(elenco.find((c) => Number(c.id) === seconda.id)?.label, "e la seconda tiene la sua").toBe(`TUTTE_${nuova}`);
        expect(elenco.find((c) => Number(c.id) === prima.id)?.label, "come la prima").toBe(prima.label);
    });

    test("una scadenza passata dalla riga è rifiutata con una frase, non con un codice", async ({ pannelloCredenziali, credentialFactory, teacherApi }) => {
        const credenziale = await credentialFactory.create();
        await pannelloCredenziali.vaiA();
        const scadenza = pannelloCredenziali.riga(credenziale.username).getByLabel(/^Scadenza di/);
        const primaDi = await scadenza.inputValue();
        await scadenza.fill("2020-01-01");
        await expect(pannelloCredenziali.riscontro, "il modulo spiega").toContainText("La scadenza è già passata");
        const riga = (await teacherApi.credentials.list()).find((c) => Number(c.id) === credenziale.id);
        expect(riga?.expires_at, "e il server tiene quella di prima").toBe(primaDi);
    });

    test("il server rifiuta un username già usato, anche da un altro docente, senza il testo del database", async ({ credentialFactory, teacherApi, teacher2Api }) => {
        const prima = await credentialFactory.create();

        const stessoDocente = await teacherApi.credentials.create({ username: prima.username, password: "Pa55!doppio_e2e", aggiunta: aggiuntaUnica("D") });
        expect(stessoDocente.status, "lo stesso docente: 400, non 500").toBe(400);
        expect(stessoDocente.body?.error).toBe("username_in_uso");
        expect(stessoDocente.body?.detail, "e niente testo SQL nella risposta").toBeUndefined();

        const maiuscolo = await teacherApi.credentials.create({ username: prima.username.toUpperCase(), password: "Pa55!doppio_e2e", aggiunta: aggiuntaUnica("M") });
        expect(maiuscolo.body?.error, "anche cambiando maiuscole e minuscole").toBe("username_in_uso");

        const altroDocente = await teacher2Api.credentials.create({ username: prima.username, password: "Pa55!doppio_e2e", aggiunta: aggiuntaUnica("A") });
        expect(altroDocente.status, "un altro docente non lo riusa").toBe(400);
        expect(altroDocente.body?.error).toBe("username_in_uso");

        const scaduta = await teacherApi.credentials.create({ username: `${prima.username}_s`.slice(0, 64), password: "Pa55!doppio_e2e", expires_at: "2020-01-01" });
        expect(scaduta.status, "una scadenza passata è rifiutata").toBe(400);
        expect(scaduta.body?.error).toBe("scadenza_passata");
    });

    test("un'etichetta mandata dal client si ignora: vale quella composta dal server", async ({ teacherApi, naming, cleanup }) => {
        const utenza = naming.unique("libera").toLowerCase().replace(/-/g, "_");
        const aggiunta = aggiuntaUnica("L");
        const creata = await teacherApi.http.send("POST", "/api/teacher/credentials", {
            form: { label: "Prof Rossi 3A mattina", username: utenza, password: "Pa55!libera_e2e", aggiunta },
        });
        const corpo = /** @type {{ ok?: boolean, id?: number, label?: string }} */ (creata.body ?? {});
        if (corpo.id) cleanup.add("teacher", `cancella la credenziale «${utenza}»`, () => teacherApi.credentials.delete(Number(corpo.id)));
        expect(creata.status, "creata").toBe(200);
        expect(corpo.label, "l'etichetta è composta").toMatch(new RegExp(`^TUTTE(_[A-Z0-9-]+)?_${aggiunta}$`));
        expect(corpo.label, "e non contiene il testo libero").not.toContain("Rossi");
    });

    test("una credenziale non si crea su una scuola che non è del docente, né su una classe che la scuola non ha", async ({ teacherApi, naming }) => {
        // ADR-037, S2 e S3: l'istituto e la classe arrivano dal client e non si
        // credono. Fino al 13 settembre 2026 una scuola qualsiasi passava, e una
        // classe sconosciuta lasciava la credenziale valida per tutte le classi.
        const base = { password: "Pa55!perimetro_e2e" };
        const altrui = naming.unique("altrui").toLowerCase().replace(/-/g, "_");
        const ignota = naming.unique("ignota").toLowerCase().replace(/-/g, "_");

        const scuolaAltrui = await teacherApi.credentials.create({ ...base, username: altrui, institute_id: "999999" });
        expect(scuolaAltrui.status, "una scuola non collegata è rifiutata").toBe(400);
        expect(scuolaAltrui.body?.error).toBe("invalid_institute");

        const classeIgnota = await teacherApi.credentials.create({ ...base, username: ignota, indirizzo: "SCI", classe: "9Z" });
        expect(classeIgnota.status, "una classe che il catalogo non ha è rifiutata").toBe(400);
        expect(classeIgnota.body?.error).toBe("invalid_classe");

        const mie = await teacherApi.credentials.list();
        expect(mie.some((c) => c.access_username === altrui || c.access_username === ignota), "nessuna delle due è stata scritta").toBe(false);
    });

    test("una credenziale creata dall'API è già in elenco quando il profilo si apre", async ({ pannelloCredenziali, credentialFactory }) => {
        // Il verso opposto della prima prova: l'elenco che il profilo mostra è
        // quello del server, non una copia tenuta dal browser.
        const creata = await credentialFactory.create();
        await pannelloCredenziali.vaiA();
        const riga = pannelloCredenziali.riga(creata.username);
        await expect(riga, "la credenziale creata altrove è nell'elenco").toHaveCount(1);
        await expect(pannelloCredenziali.etichetta(creata.username), "con la sua etichetta").toHaveText(creata.label);
        await expect(pannelloCredenziali.comando(creata.username, "Spegni"), "attiva, quindi si può spegnere").toBeVisible();
    });
});
