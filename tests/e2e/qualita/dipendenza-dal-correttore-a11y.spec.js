// @ts-check
/**
 * Quanto le pagine dipendono dal correttore a runtime dei nomi accessibili.
 * Voce 79 del debito.
 *
 * `js/modules/a11y/form-labels.js` è una rete: dopo il rendering assegna un
 * `aria-label` ai controlli che non ne hanno, e marca come non-lista le liste
 * che non lo sono. È utile — parecchi controlli qui nascono a runtime, e senza
 * la rete non avrebbero nome per nessuno — ma ha un effetto collaterale
 * scomodo: axe scansiona a pagina assestata, quindi trova tutto a posto anche
 * dove il markup non nomina niente. La prova di accessibilità passa, e la
 * dipendenza non si vede.
 *
 * Si è vista una volta sola, per caso: un giro notturno ha scansionato
 * `/admin/waf/config` dentro la finestra prima del passaggio della rete e ha
 * trovato sette `label` e due `select-name` di gravità critical. Quel pannello
 * è stato corretto all'origine. Il resto no, perché non si sapeva quanto
 * fosse.
 *
 * Questa prova lo misura. Il modulo adesso marca `data-a11y-patched` su ciò
 * che rattoppa — non su ciò che esamina, che è quello che faceva `data-a11y-named`
 * e che non serviva a niente per contarli — quindi il censimento è una query.
 *
 * **Non è una prova di accessibilità**: non dice che una pagina è sbagliata.
 * Dice che, su quella pagina, tot controlli sono nominati solo dalla rete — e
 * per loro la finestra di ottocento millisecondi c'è davvero anche per una
 * persona che usa uno screen reader, non solo per axe. Il numero è un debito
 * da pagare a poco a poco; il tetto in `dipendenza-a11y.json` fa sì che si
 * possa solo scendere.
 */
const {
    test,
    expect,
    preparaPagina,
    attendiDomFermo,
    PAGINE_PUBBLICHE,
    PAGINE_DEL_DOCENTE,
    PAGINE_DELL_AMMINISTRAZIONE,
} = require("../support/test");
const fs = require("node:fs");
const path = require("node:path");

const TETTI = path.join(__dirname, "dipendenza-a11y.json");

/**
 * Quello che la rete ha rattoppato su questa pagina, in forma leggibile.
 *
 * Di ogni elemento si tiene il tipo di violazione evitata, il nome che la rete
 * ha inventato e abbastanza markup per ritrovarlo: chi andrà a correggere
 * all'origine deve poter partire da qui senza rifare la misura.
 *
 * @param {import("@playwright/test").Page} pagina
 * @returns {Promise<{ tipo: string, nome: string, dove: string }[]>}
 */
async function rattoppi(pagina) {
    return pagina.evaluate(() => {
        const identifica = (/** @type {Element} */ el) => {
            const tag = el.tagName.toLowerCase();
            const id = el.id ? `#${el.id}` : "";
            const nome = el.getAttribute("name") ? `[name="${el.getAttribute("name")}"]` : "";
            const classi = typeof el.className === "string" && el.className
                ? "." + el.className.trim().split(/\s+/).slice(0, 3).join(".")
                : "";
            const contenitore = el.closest("[id]");
            const dentro = contenitore && contenitore !== el ? ` dentro #${contenitore.id}` : "";
            return `${tag}${id}${nome}${classi}${dentro}`;
        };
        return Array.from(document.querySelectorAll("[data-a11y-patched]")).map((el) => ({
            tipo: el.getAttribute("data-a11y-patched") || "?",
            nome: el.getAttribute("aria-label") || "(struttura)",
            dove: identifica(el),
        }));
    });
}

/** I tetti registrati: per ogni percorso, quanti rattoppi sono ancora tollerati. */
function leggiTetti() {
    try {
        return JSON.parse(fs.readFileSync(TETTI, "utf8"));
    } catch {
        return {};
    }
}

/**
 * Misura una pagina e la confronta col suo tetto.
 *
 * Fallisce solo se il numero sale: significa che è stato aggiunto un controllo
 * che nasce senza nome. Se scende, lo dice e chiede di abbassare il tetto —
 * altrimenti il tetto smette di proteggere quello che si è appena guadagnato.
 *
 * @param {import("@playwright/test").Page} pagina
 * @param {string} percorso
 * @param {string | undefined} attendi
 */
async function misura(pagina, percorso, attendi) {
    await preparaPagina(pagina, percorso, attendi);
    await misuraQui(pagina, percorso);
}

/**
 * Come `misura`, ma sulla pagina com'è adesso: serve dove per arrivare al
 * contenuto generato non basta un indirizzo, ma bisogna crearlo e aprirlo.
 *
 * @param {import("@playwright/test").Page} pagina
 * @param {string} percorso chiave con cui questa situazione sta nei tetti
 */
async function misuraQui(pagina, percorso) {
    const trovati = await rattoppi(pagina);
    const tetto = leggiTetti()[percorso];

    const elenco = trovati.map((r) => `    ${r.tipo}: «${r.nome}» su ${r.dove}`).join("\n");
    test.info().annotations.push({
        type: "dipendenza-a11y",
        description: `${percorso}: ${trovati.length} controlli nominati solo dalla rete${elenco ? "\n" + elenco : ""}`,
    });

    expect(
        tetto,
        `${percorso} non ha un tetto in dipendenza-a11y.json: aggiungilo con ${trovati.length}`,
    ).toBeDefined();

    expect(
        trovati.length,
        `su ${percorso} la rete a runtime nomina ${trovati.length} controlli, il tetto è ${tetto}.\n` +
            `Questi controlli non hanno un nome accessibile nel markup: per chi usa uno screen reader\n` +
            `non ne hanno per i primi ottocento millisecondi, e se la rete non parte non ne hanno affatto.\n` +
            `Vanno nominati all'origine (\`for\`/\`id\`, oppure \`aria-label\` scritto dal generatore).\n${elenco}`,
    ).toBeLessThanOrEqual(tetto);

    if (trovati.length < tetto) {
        expect(
            trovati.length,
            `su ${percorso} i controlli nominati solo dalla rete sono scesi da ${tetto} a ${trovati.length}: ` +
                `abbassa il tetto in dipendenza-a11y.json, altrimenti non protegge il guadagno.`,
        ).toBe(tetto);
    }
}

test.describe("Qualità — dipendenza dal correttore a11y (pagine pubbliche)", () => {
    for (const { percorso, nome, attendi } of PAGINE_PUBBLICHE) {
        test(`la ${nome} non aumenta i controlli nominati solo a runtime`, async ({ page }) => {
            await misura(page, percorso, attendi);
        });
    }
});

test.describe("Qualità — dipendenza dal correttore a11y (pagine del docente)", () => {
    for (const { percorso, nome, attendi } of PAGINE_DEL_DOCENTE) {
        test(`la pagina «${nome}» non aumenta i controlli nominati solo a runtime`, async ({ teacherPage }) => {
            await misura(teacherPage, percorso, attendi);
        });
    }
});

test.describe("Qualità — dipendenza dal correttore a11y (pagine dell'amministrazione)", () => {
    for (const { percorso, nome, attendi } of PAGINE_DELL_AMMINISTRAZIONE) {
        test(`la pagina «${nome}» non aumenta i controlli nominati solo a runtime`, async ({ adminPage }) => {
            await misura(adminPage, percorso, attendi);
        });
    }
});

/**
 * Il posto dove il modulo dice di lavorare.
 *
 * Le pagine di sopra sono composte dal server o da pannelli con markup scritto
 * a mano: lì la rete non ha quasi niente da fare, e infatti non fa niente. Ma
 * il modulo si presenta così: «molti <select>/<input> sono generati (renderer
 * esercizi, sidebar, contenuto salvato) senza nome accessibile». Quelli non
 * stanno in nessuna delle ventuno pagine dell'elenco, perché per vederli
 * bisogna avere del contenuto e aprirlo.
 *
 * Queste due prove ce lo mettono. Sono la parte del censimento che risponde
 * davvero alla domanda della voce 79.
 */
test.describe("Qualità — dipendenza dal correttore a11y (contenuto generato)", () => {
    test("la pagina di studio, con un esercizio aperto, non aumenta i controlli nominati solo a runtime", async ({
        contentFactory,
        studioEsercizio,
        teacherPage,
    }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 2, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.gruppo(0).apri();
        await attendiDomFermo(teacherPage);

        await misuraQui(teacherPage, "studio/esercizio (un gruppo aperto)");
    });

    test("la home del docente non aumenta i controlli nominati solo a runtime", async ({
        homeDocente,
        teacherPage,
    }) => {
        await homeDocente.vaiA();
        await attendiDomFermo(teacherPage);

        await misuraQui(teacherPage, "home del docente");
    });
});
