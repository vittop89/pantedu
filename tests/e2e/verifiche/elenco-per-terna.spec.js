// @ts-check
/**
 * L'elenco delle verifiche rispetta il filtro per indirizzo e classe.
 * Riscrittura di g20_05_verifica_list_scope.spec.js.
 *
 * Due cose imparate riscrivendola:
 *   - nell'elenco il titolo porta in coda la variante («titolo — A_NOR»);
 *   - l'indirizzo viene normalizzato al codice canonico del curriculum: si
 *     salva con `sc` e si rilegge `SCI`. La spec storica filtrava per `sc` e
 *     passava lo stesso, perché nel database c'erano verifiche vecchie salvate
 *     quando i codici brevi erano ancora in uso, e perché controllava solo che
 *     le righe trovate avessero i valori giusti: con zero righe non verificava
 *     niente. Qui la verifica viene creata dal test, quindi il filtro deve
 *     trovarla per forza.
 */
const { test, expect } = require("../support/test");

test.describe("Verifiche — elenco filtrato per terna", () => {
    test("il filtro restituisce le verifiche della terna, e solo quelle", async ({ verificaFactory, teacherApi, naming }) => {
        const titolo = naming.unique("elenco-terna");
        await verificaFactory.batch({
            title: titolo,
            versionLabel: "g20",
            versions: ["A"],
            overrides: { selectedIIS: "sc", selectedCLS: "2", indirizzo: "sc", classe: "2" },
        });

        const tutte = await teacherApi.verifica.list();
        expect(tutte.ok).toBe(true);
        const nostre = tutte.items.filter((i) => (i.title ?? "").startsWith(titolo));
        expect(nostre.length, "le varianti appena salvate sono nell'elenco completo").toBeGreaterThan(0);

        // Il codice con cui l'app le ha registrate, non quello con cui le ho salvate.
        const voce = /** @type {{ indirizzo?: string, classe?: string|number }} */ (nostre[0]);
        const indirizzo = String(voce.indirizzo ?? "");
        const classe = String(voce.classe ?? "");
        expect(indirizzo, "indirizzo normalizzato").toBeTruthy();

        /** @type {{ ok: boolean, items: ReadonlyArray<{ title?: string, indirizzo?: string, classe?: string|number }> }} */
        const dellaTerna = await teacherApi.http.getJson("/api/verifica/list", { indirizzo, classe });
        expect(dellaTerna.ok).toBe(true);
        expect(
            dellaTerna.items.some((i) => (i.title ?? "").startsWith(titolo)),
            `il filtro ${indirizzo}/${classe} trova la verifica`,
        ).toBe(true);
        for (const riga of dellaTerna.items) {
            expect(String(riga.indirizzo), `indirizzo di «${riga.title}»`).toBe(indirizzo);
            expect(String(riga.classe), `classe di «${riga.title}»`).toBe(classe);
        }

        /** @type {{ ok: boolean, items: ReadonlyArray<{ title?: string }> }} */
        const altraClasse = await teacherApi.http.getJson("/api/verifica/list", { indirizzo, classe: "5" });
        expect(altraClasse.ok).toBe(true);
        expect(
            altraClasse.items.some((i) => (i.title ?? "").startsWith(titolo)),
            "un'altra classe non la elenca",
        ).toBe(false);
    });
});
