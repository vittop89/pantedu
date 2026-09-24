// @ts-check
/**
 * L'accesso per la classe, col portachiavi di credenziali.
 *
 * Uno studente senza account entra da /accesso-classe con la credenziale che
 * il docente gli ha dato. Se ha più docenti sul sito, inserisce una
 * credenziale per ciascuno e le tiene insieme: il portachiavi (piano
 * classi-credenziali-scenari, C). Ogni credenziale si può togliere da sola,
 * dalla pagina o dal banner nella barra laterale della home; una credenziale
 * spenta dal docente cade da sola, con un avviso, invece di far sparire i
 * contenuti in silenzio.
 *
 * Le credenziali le creano le factory dei due docenti, via API, e le
 * cancellano alla fine anche se la prova fallisce. Lo studente è la pagina
 * anonima: non ha un account, e non deve averlo.
 *
 * L'istanza della suite è nello scenario 1 (ADR-032): c'è solo l'autore, e
 * dal 19/9/2026, col portachiavi già aperto, la pagina non parla più di «la
 * credenziale di un altro docente» — ma il modulo per aggiungerne un'altra
 * c'è (20/9/2026): il portachiavi ne tiene più d'una anche dello stesso
 * docente, e senza il modulo la pagina sarebbe un vicolo cieco. Il titolo
 * degli scenari 2 e 3 sta in
 * tests/Integration/AltroDocentePerScenarioTest.php: lo scenario vale per
 * tutta l'istanza, e cambiarlo qui lo cambierebbe a tutte le prove.
 *
 * Il caso della credenziale sbagliata via API sta in
 * admin/istituti-e-credenziali; qui c'è il modulo, che deve dirlo a chi
 * legge.
 */
const { test, expect } = require("../support/test");

/**
 * Il portachiavi come lo vede il server, in questa sessione.
 * @param {import("@playwright/test").Page} page
 */
async function portachiaviDelServer(page) {
    const risposta = await page.request.get("/api/access/status");
    expect(risposta.ok(), "lo stato del portachiavi risponde").toBe(true);
    /** @type {{ grants: { label: string }[] }} */
    const stato = await risposta.json();
    return stato.grants.map((g) => g.label);
}

test.describe("Pubblico — accesso per la classe col portachiavi", () => {
    test("una credenziale apre l'accesso, la pagina non parla di un altro docente, e una seconda si aggiunge al portachiavi", async ({ page, accessoClasse, credentialFactory, teacher2CredentialFactory }) => {
        const prima = await credentialFactory.create();
        const seconda = await teacher2CredentialFactory.create();

        await accessoClasse.vaiA();
        await expect(accessoClasse.portachiavi, "senza credenziali non c'è portachiavi").toHaveCount(0);
        await expect(accessoClasse.pulsanteInvio, "e il modulo dice «Entra»").toHaveText("Entra");

        await accessoClasse.entra(prima.username, prima.password);
        expect(await portachiaviDelServer(page), "la sessione ha la prima credenziale").toEqual([prima.label]);

        await accessoClasse.vaiA();
        await expect(accessoClasse.portachiavi, "la pagina mostra il portachiavi").toBeVisible();
        expect(await accessoClasse.credenziali(), "con la credenziale inserita").toEqual([prima.label]);
        // Scenario 1: un altro docente non c'è, e la pagina non ne parla; il
        // modulo però resta, perché una seconda credenziale è legittima.
        await expect(accessoClasse.page.locator("body"), "nessuna parola su «un altro docente»").not.toContainText("un altro docente");
        await expect(accessoClasse.page.getByRole("heading", { name: "Aggiungi un'altra credenziale" }), "si aggiunge un'altra credenziale").toBeVisible();
        await expect(accessoClasse.modulo, "col suo modulo").toBeVisible();
        await expect(accessoClasse.pulsanteInvio, "e il modulo adesso aggiunge").toHaveText("Aggiungi");
        await expect(accessoClasse.page.getByRole("link", { name: "Vai ai materiali" }), "restano i materiali").toBeVisible();

        await accessoClasse.entra(seconda.username, seconda.password);
        await accessoClasse.vaiA();
        expect(await accessoClasse.credenziali(), "le due credenziali stanno insieme, dalla più vecchia").toEqual([prima.label, seconda.label]);
        expect(await portachiaviDelServer(page), "e il server dice lo stesso").toEqual([prima.label, seconda.label]);
        // ADR-044 — le etichette le compone il server: per tutte le classi
        // cominciano con «TUTTE», finiscono con l'aggiunta, e non hanno spazi.
        for (const c of [prima, seconda]) {
            expect(c.label, "etichetta composta").toMatch(new RegExp(`^TUTTE(_[A-Z0-9-]+)?_${c.aggiunta}$`));
        }
    });

    test("l'etichetta nel portachiavi dice classe, indirizzo e materie, con i nomi delle materie al passaggio del mouse", async ({ page, accessoClasse, credentialFactory, teacherApi, env }) => {
        // ADR-044 — prima l'etichetta era testo libero del docente: in
        // produzione dichiarava una sezione mentre la credenziale valeva per
        // l'anno. Adesso comincia con la classe e l'indirizzo del perimetro.
        const { indirizzo, classe } = env.terna;
        const [materia] = await teacherApi.credentials.materieDisponibili();
        if (materia === undefined) throw new Error("il docente di prova non ha materie spuntate nell'istituto");
        const credenziale = await credentialFactory.create({ indirizzo, classe, materie: [materia.code] });
        expect(credenziale.label, "classe, indirizzo, materia e aggiunta, senza spazi")
            .toBe(`${classe}_${indirizzo}_${materia.code}_${credenziale.aggiunta}`.toUpperCase());

        await accessoClasse.vaiA();
        await accessoClasse.entra(credenziale.username, credenziale.password);
        await accessoClasse.vaiA();
        expect(await accessoClasse.credenziali(), "il portachiavi mostra l'etichetta composta").toEqual([credenziale.label]);
        await expect(page.locator(".fm-keychain__label strong", { hasText: credenziale.label }), "e il nome della materia per esteso")
            .toHaveAttribute("title", /^Materie: .+/);
    });

    test("l'uscita da una sola credenziale lascia le altre, dalla pagina e dalla barra laterale", async ({ page, accessoClasse, portachiaviBarra, credentialFactory, teacher2CredentialFactory }) => {
        const prima = await credentialFactory.create();
        const seconda = await teacher2CredentialFactory.create();
        await accessoClasse.vaiA();
        await accessoClasse.entra(prima.username, prima.password);
        await accessoClasse.vaiA();
        await accessoClasse.entra(seconda.username, seconda.password);

        // Dalla pagina: via la prima, resta la seconda.
        await accessoClasse.vaiA();
        await accessoClasse.esciDa(prima.label);
        expect(await accessoClasse.credenziali(), "la seconda resta").toEqual([seconda.label]);
        expect(await portachiaviDelServer(page), "e il server ha solo quella").toEqual([seconda.label]);

        // Nella barra laterale della home il banner mostra quel che resta, e
        // l'«Esci» della riga dei comandi svuota il portachiavi.
        await portachiaviBarra.vaiAllaHome();
        await expect(portachiaviBarra.portachiavi, "il banner della barra mostra il portachiavi").toBeVisible();
        await expect(portachiaviBarra.voce(seconda.label), "con la credenziale rimasta").toHaveCount(1);
        await expect(portachiaviBarra.voce(prima.label), "e non quella tolta").toHaveCount(0);
        await portachiaviBarra.esciDaTutte();
        expect(await portachiaviDelServer(page), "la sessione non ha più credenziali").toEqual([]);
    });

    test("dal banner della barra si esce anche da una credenziale sola", async ({ page, accessoClasse, portachiaviBarra, credentialFactory, teacher2CredentialFactory }) => {
        const prima = await credentialFactory.create();
        const seconda = await teacher2CredentialFactory.create();
        await accessoClasse.vaiA();
        await accessoClasse.entra(prima.username, prima.password);
        await accessoClasse.vaiA();
        await accessoClasse.entra(seconda.username, seconda.password);

        await portachiaviBarra.vaiAllaHome();
        await expect(portachiaviBarra.voce(prima.label), "il banner elenca la prima").toHaveCount(1);
        await expect(portachiaviBarra.voce(seconda.label), "e la seconda").toHaveCount(1);
        await portachiaviBarra.esciDa(seconda.label);
        expect(await accessoClasse.credenziali(), "si torna alla pagina dell'accesso, con la prima").toEqual([prima.label]);
        expect(await portachiaviDelServer(page), "e la sessione ha solo quella").toEqual([prima.label]);

        // Con una credenziale sola il banner non offre l'uscita dalla singola:
        // basta quella da tutte.
        await portachiaviBarra.vaiAllaHome();
        await expect(portachiaviBarra.voce(prima.label), "la prima è nel banner").toHaveCount(1);
        await expect(portachiaviBarra.voce(prima.label).getByRole("button", { name: "Esci" }), "senza il proprio «Esci»").toHaveCount(0);
    });

    test("una credenziale spenta dal docente cade dal portachiavi, con un avviso", async ({ page, accessoClasse, credentialFactory, teacherApi }) => {
        const credenziale = await credentialFactory.create();
        await accessoClasse.vaiA();
        await accessoClasse.entra(credenziale.username, credenziale.password);
        expect(await portachiaviDelServer(page), "entrato").toEqual([credenziale.label]);

        await teacherApi.credentials.toggle(credenziale.id, false);

        await accessoClasse.vaiA();
        const avvisi = await accessoClasse.avvisi();
        expect(avvisi.some((a) => a.includes(credenziale.label) && /disattivata/.test(a)), `un avviso dice che «${credenziale.label}» è stata disattivata: ${JSON.stringify(avvisi)}`).toBe(true);
        await expect(accessoClasse.portachiavi, "il portachiavi è vuoto, quindi non c'è").toHaveCount(0);
        await expect(accessoClasse.pulsanteInvio, "e il modulo torna a «Entra»").toHaveText("Entra");
        expect(await portachiaviDelServer(page), "il server non la tiene più").toEqual([]);

        // Verso opposto: con la credenziale riaccesa si rientra.
        await teacherApi.credentials.toggle(credenziale.id, true);
        await accessoClasse.entra(credenziale.username, credenziale.password);
        expect(await portachiaviDelServer(page), "riaccesa, apre di nuovo").toEqual([credenziale.label]);
    });

    test("il modulo dice quando la credenziale non è valida", async ({ page, accessoClasse }) => {
        await accessoClasse.vaiA();
        await accessoClasse.tentaConCredenzialiSbagliate("utenza_che_non_esiste", "sbagliata");
        await expect(accessoClasse.errore, "con parole per chi legge").toContainText(/non valida/i);
        expect(await portachiaviDelServer(page), "e la sessione resta vuota").toEqual([]);
    });
});
