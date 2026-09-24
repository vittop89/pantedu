// @ts-check
/**
 * Modifiche ai modelli: di chi sono e chi le vede.
 * Riscrittura di risdoc_dual_role_isolation.spec.js e
 * risdoc_real_users_isolation.spec.js.
 *
 * Su uno stesso modello dell'Istituto convivono tre cose diverse:
 *  - la modifica dell'Istituto, che vale per tutti e la fa un amministratore;
 *  - la modifica personale del docente sul documento base;
 *  - la modifica personale su una copia («istanza») che il docente si è fatto.
 * Devono restare separate. Se si sovrapponessero, un docente vedrebbe il lavoro
 * di un altro, o se lo vedrebbe cancellare da una modifica dell'Istituto.
 *
 * Il primo test è il caso di chi ha due ruoli insieme, docente e
 * amministratore: è lo stesso account, ma le due modifiche prendono strade
 * diverse e non si toccano.
 *
 * Cosa cambia rispetto a prima: niente login nella spec e nessun contesto di
 * browser aperto e chiuso a mano (le tre sessioni sono fixture), le pulizie
 * sono registrate — prima stavano in un `finally` che, se il test falliva
 * prima, lasciava le modifiche nel database — e i marcatori hanno il nome
 * unico della suite invece di un timestamp scritto a mano.
 */
const { test, expect } = require("../support/test");

/** Primo modello dell'Istituto, con i percorsi dei suoi file. */
async function primoModello(/** @type {any} */ adminApi, /** @type {any} */ teacherApi) {
    const { id } = await teacherApi.risdoc.unTemplate({ origin: "risdoc" });
    return { id, ...(await adminApi.risdoc.detail(id)) };
}

/** Aggiunge un marcatore allo schema del modello, lasciando il resto com'è. */
async function marcaSchema(/** @type {any} */ api, /** @type {any} */ modello, /** @type {string} */ marcatore) {
    const schema = JSON.parse(await api.risdoc.schema(modello.id));
    const salvato = await api.risdoc.saveInstitutionalOverride(modello.id, {
        kind: "schema",
        path: modello.schema_path,
        content: JSON.stringify({ ...schema, _marcatore_di_prova: marcatore }),
    });
    expect(salvato.ok, `modifica dell'Istituto allo schema → ${salvato.status}`).toBe(true);
}

test.describe("Risdoc — le modifiche ai modelli restano di chi le fa", () => {
    test("la modifica del docente e quella dell'Istituto non si toccano", async ({ adminApi, teacherApi, naming, cleanup }) => {
        const modello = await primoModello(adminApi, teacherApi);
        const marcaturaDocente = naming.unique("docente").toUpperCase();
        const marcaturaIstituto = naming.unique("istituto").toUpperCase();

        const suaModifica = await adminApi.risdoc.saveOverride(modello.id, {
            kind: "html",
            path: modello.html_file,
            body: `<!-- ${marcaturaDocente} -->`,
            instance_key: "",
        });
        expect(suaModifica.ok, `modifica personale → ${suaModifica.status}`).toBe(true);
        cleanup.add("admin", "toglie la modifica personale al modello", async () => {
            await adminApi.risdoc.deleteOverride(modello.id, { kind: "html", path: modello.html_file, instance_key: "" });
        });

        await marcaSchema(adminApi, modello, marcaturaIstituto);
        cleanup.add("admin", "toglie la modifica dell'Istituto allo schema", async () => {
            await adminApi.risdoc.deleteInstitutionalOverride(modello.id, { kind: "schema", path: modello.schema_path });
        });

        const file = await adminApi.risdoc.file(modello.id, { kind: "html", path: modello.html_file });
        expect(file.body?.["source"], "il file arriva dalla modifica personale").toBe("override");
        expect(String(file.body?.["body"]), "con dentro la sua marcatura").toContain(marcaturaDocente);
        expect(String(file.body?.["body"]), "e non quella dell'Istituto").not.toContain(marcaturaIstituto);

        const schema = await adminApi.risdoc.schema(modello.id);
        expect(schema, "lo schema porta la marcatura dell'Istituto").toContain(marcaturaIstituto);
        expect(schema, "e non quella personale").not.toContain(marcaturaDocente);
    });

    test("una modifica dell'Istituto non cancella quella fatta su una copia personale", async ({ adminApi, teacherApi, naming, cleanup }) => {
        const modello = await primoModello(adminApi, teacherApi);
        const marcaturaCopia = naming.unique("copia").toUpperCase();
        const marcaturaIstituto = naming.unique("istituto").toUpperCase();

        const chiave = await adminApi.risdoc.createInstance(modello.id, `Copia di prova ${marcaturaCopia}`);
        cleanup.add("admin", `cancella la copia ${chiave} del modello`, async () => {
            await adminApi.risdoc.deleteInstance(modello.id, chiave);
        });

        const modificaCopia = await adminApi.risdoc.saveOverride(modello.id, {
            kind: "html",
            path: modello.html_file,
            body: `<!-- ${marcaturaCopia} -->`,
            instance_key: chiave,
        });
        expect(modificaCopia.ok, `modifica della copia → ${modificaCopia.status}`).toBe(true);

        await marcaSchema(adminApi, modello, marcaturaIstituto);
        cleanup.add("admin", "toglie la modifica dell'Istituto allo schema", async () => {
            await adminApi.risdoc.deleteInstitutionalOverride(modello.id, { kind: "schema", path: modello.schema_path });
        });

        const nellaCopia = await adminApi.risdoc.file(modello.id, { kind: "html", path: modello.html_file, instanceKey: chiave });
        expect(String(nellaCopia.body?.["body"]), "la copia conserva la propria modifica").toContain(marcaturaCopia);
        expect(await adminApi.risdoc.schema(modello.id), "e l'Istituto la sua").toContain(marcaturaIstituto);

        const nelBase = await adminApi.risdoc.file(modello.id, { kind: "html", path: modello.html_file });
        expect(String(nelBase.body?.["body"] ?? ""), "il documento base non ha preso quella della copia").not.toContain(marcaturaCopia);
    });

    test("due docenti sullo stesso modello vedono ognuno le proprie modifiche", async ({ adminApi, teacherApi, teacher2Api, naming, cleanup }) => {
        const modello = await primoModello(adminApi, teacherApi);
        const marcaturaIstituto = naming.unique("istituto").toUpperCase();
        const marcaturaPrimo = naming.unique("primo").toUpperCase();
        const marcaturaSecondo = naming.unique("secondo").toUpperCase();

        await marcaSchema(adminApi, modello, marcaturaIstituto);
        cleanup.add("admin", "toglie la modifica dell'Istituto allo schema", async () => {
            await adminApi.risdoc.deleteInstitutionalOverride(modello.id, { kind: "schema", path: modello.schema_path });
        });

        // La modifica dell'Istituto la vedono tutti: è il punto di partenza comune.
        expect(await teacherApi.risdoc.schema(modello.id), "il primo docente vede lo schema dell'Istituto").toContain(marcaturaIstituto);
        expect(await teacher2Api.risdoc.schema(modello.id), "e anche il secondo").toContain(marcaturaIstituto);

        const primo = await teacherApi.risdoc.saveOverride(modello.id, {
            kind: "html", path: modello.html_file, body: `<!-- ${marcaturaPrimo} -->`, instance_key: "",
        });
        expect(primo.ok, `modifica del primo docente → ${primo.status}`).toBe(true);
        cleanup.add("teacher", "toglie la modifica del primo docente", async () => {
            await teacherApi.risdoc.deleteOverride(modello.id, { kind: "html", path: modello.html_file, instance_key: "" });
        });

        const chiave = await teacher2Api.risdoc.createInstance(modello.id, `Copia del secondo ${marcaturaSecondo}`);
        cleanup.add("teacher2", `cancella la copia ${chiave} del secondo docente`, async () => {
            await teacher2Api.risdoc.deleteInstance(modello.id, chiave);
        });
        const secondo = await teacher2Api.risdoc.saveOverride(modello.id, {
            kind: "html", path: modello.html_file, body: `<!-- ${marcaturaSecondo} -->`, instance_key: chiave,
        });
        expect(secondo.ok, `modifica del secondo docente → ${secondo.status}`).toBe(true);

        const perIlPrimo = await teacherApi.risdoc.file(modello.id, { kind: "html", path: modello.html_file });
        expect(perIlPrimo.body?.["source"], "il primo legge la propria modifica").toBe("override");
        expect(String(perIlPrimo.body?.["body"]), "con la sua marcatura").toContain(marcaturaPrimo);
        expect(String(perIlPrimo.body?.["body"]), "e non quella dell'altro").not.toContain(marcaturaSecondo);

        const perIlSecondo = await teacher2Api.risdoc.file(modello.id, { kind: "html", path: modello.html_file, instanceKey: chiave });
        expect(perIlSecondo.body?.["source"], "il secondo legge la propria").toBe("override");
        expect(String(perIlSecondo.body?.["body"]), "con la sua marcatura").toContain(marcaturaSecondo);
        expect(String(perIlSecondo.body?.["body"]), "e non quella dell'altro").not.toContain(marcaturaPrimo);

        // Il documento base del secondo docente non ha modifiche: né sue né altrui.
        // Il file di partenza non esiste più su disco (i modelli vivono nel
        // database), quindi la risposta può dire che non c'è: quel che conta è
        // che non arrivi da una modifica e non contenga marcature.
        const baseDelSecondo = await teacher2Api.risdoc.file(modello.id, { kind: "html", path: modello.html_file });
        expect(baseDelSecondo.body?.["source"] ?? null, "nessuna modifica sul suo documento base").not.toBe("override");
        const corpoBase = String(baseDelSecondo.body?.["body"] ?? "");
        expect(corpoBase, "niente marcature del primo docente").not.toContain(marcaturaPrimo);
        expect(corpoBase, "né della propria copia").not.toContain(marcaturaSecondo);
    });

    test("un docente senza poteri di amministrazione non può modificare per tutti", async ({ teacher2Api }) => {
        const modello = await teacher2Api.risdoc.unTemplate({ origin: "risdoc" });

        const tentativo = await teacher2Api.risdoc.saveInstitutionalOverride(modello.id, {
            kind: "schema",
            path: "schemas/risdoc/test.json",
            content: JSON.stringify({ modificato: true }),
        });
        expect(tentativo.status, "l'app risponde con un divieto esplicito").toBe(403);
    });

    test("le copie personali di un docente non compaiono a un altro", async ({ adminApi, teacherApi, teacher2Api, naming, cleanup }) => {
        const modello = await primoModello(adminApi, teacherApi);
        const chiave = await teacherApi.risdoc.createInstance(modello.id, naming.unique("solo-mia"));
        cleanup.add("teacher", `cancella la copia ${chiave}`, async () => {
            await teacherApi.risdoc.deleteInstance(modello.id, chiave);
        });

        const sue = await teacherApi.risdoc.instances(modello.id);
        expect(
            (sue.body?.instances ?? []).some((i) => i.instance_key === chiave),
            "chi l'ha fatta la vede",
        ).toBe(true);

        const altrui = await teacher2Api.risdoc.instances(modello.id);
        expect(
            (altrui.body?.instances ?? []).some((i) => i.instance_key === chiave),
            "l'altro docente no",
        ).toBe(false);
    });
});
