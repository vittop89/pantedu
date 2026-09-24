// @ts-check
/**
 * Accessibilità delle pagine riservate (WCAG 2.2 AA).
 * Riscrittura di a11y_authenticated.spec.js.
 *
 * Il controllo automatico non dice se una pagina è accessibile — quello lo dice
 * una persona — ma riconosce le violazioni che si possono riconoscere da sole:
 * un'immagine senza descrizione, un campo senza etichetta, un contrasto sotto
 * la soglia, un comando che non si raggiunge da tastiera. Sono quelle che
 * rendono una pagina inutilizzabile a chi usa uno screen reader, e sono anche
 * quelle che rientrano negli obblighi di legge: qui si fermano le violazioni
 * gravi e critiche.
 *
 * La spec storica cominciava con `test.skip(!USER || !PASS)`: senza le
 * credenziali nell'ambiente passava senza aver guardato niente, e queste dieci
 * pagine non venivano mai controllate. Ora le sessioni sono fixture — se
 * mancano le credenziali la suite non parte affatto — e ogni pagina è un test
 * suo: se una fallisce si sa quale, e le altre nove girano lo stesso.
 *
 * Ha bisogno del database, quindi non gira in integrazione continua: lì gira
 * `accessibilita-pubblica.spec.js`.
 *
 * Un secondo fatto, imparato l'8 settembre 2026: parecchie di queste pagine
 * si compongono dopo il caricamento, con una lettura al server. Scansionare a
 * `domcontentloaded` voleva dire, a volte, guardare il posto dove il pannello
 * non era ancora arrivato — e non trovare niente da segnalare. Il pannello
 * delle anomalie di `/admin/waf/config` aveva sette campi senza etichetta e
 * due select senza nome, gravità critical, e questa prova passava lo stesso:
 * l'ha trovato un giro notturno, non lei. Adesso si aspetta che il documento
 * abbia smesso di cambiare, e dove si sa cosa deve comparire lo si dichiara
 * (`attendi`), che è più preciso di qualunque attesa a tempo.
 *
 * Un fatto imparato riscrivendo: il controllo misura il contrasto fra testo e
 * sfondo, e a metà di una dissolvenza il colore che misura è una mescolanza fra
 * quello di partenza e quello d'arrivo — il contrasto risulta insufficiente su
 * elementi che a riposo sono a norma. La spec storica aspettava novecento
 * millisecondi fissi; qui si aspetta che nessuna animazione sia in corso,
 * saltando quelle senza fine come gli indicatori di caricamento.
 */
const {
    test,
    expect,
    preparaPagina,
    PAGINE_DEL_DOCENTE,
    PAGINE_DELL_AMMINISTRAZIONE,
} = require("../support/test");
const AxeBuilder = require("@axe-core/playwright").default;

/** Le regole del controllo: WCAG 2.0, 2.1 e 2.2 fino al livello AA, più le buone pratiche. */
const REGOLE = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22a", "wcag22aa", "best-practice"];

/** Apre la pagina, la analizza e riporta le violazioni gravi in forma leggibile. */
async function violazioniGravi(
    /** @type {import("@playwright/test").Page} */ pagina,
    /** @type {string} */ percorso,
    /** @type {string | undefined} */ attendi,
) {
    await preparaPagina(pagina, percorso, attendi);

    const esito = await new AxeBuilder({ page: pagina }).withTags(REGOLE).analyze();
    return esito.violations
        .filter((v) => v.impact === "critical" || v.impact === "serious")
        .map((v) => `${v.id} (${v.impact}, ${v.nodes.length} elementi): ${v.description}\n    ${v.nodes[0]?.html?.slice(0, 160) ?? ""}`);
}

test.describe("Qualità — accessibilità delle pagine del docente", () => {
    for (const { percorso, nome, attendi } of PAGINE_DEL_DOCENTE) {
        test(`la pagina «${nome}» non ha violazioni gravi`, async ({ teacherPage }) => {
            const violazioni = await violazioniGravi(teacherPage, percorso, attendi);
            expect(violazioni, `violazioni su ${percorso}:\n${violazioni.join("\n")}`).toEqual([]);
        });
    }
});

test.describe("Qualità — accessibilità delle pagine dell'amministrazione", () => {
    for (const { percorso, nome, attendi } of PAGINE_DELL_AMMINISTRAZIONE) {
        test(`la pagina «${nome}» non ha violazioni gravi`, async ({ adminPage }) => {
            const violazioni = await violazioniGravi(adminPage, percorso, attendi);
            expect(violazioni, `violazioni su ${percorso}:\n${violazioni.join("\n")}`).toEqual([]);
        });
    }
});
