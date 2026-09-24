/**
 * Le classi del catalogo nel profilo del docente (14/9/2026). Logica pura,
 * provata in tests/js-unit/catalogo-classi.test.js; la pagina è
 * js/entries/area-docente-profilo.js.
 *
 * Nel catalogo di una scuola le classi sono di due specie:
 *   - gli **anni** («1»…«5») di un corso;
 *   - le **sezioni** di un corso (2A, 2B sotto lo scientifico).
 *
 * Dal 15/9/2026 (ADR-042) anche un anno appartiene a un corso: «2» dello
 * scientifico e «2» dell'artistico sono due voci, e si spuntano ognuna sotto il
 * suo indirizzo. Prima l'anno era uno per tutta la scuola e compariva sotto
 * ogni indirizzo spuntato, anche dove il corso non ha quell'anno (la prima
 * sotto «architettura e ambiente»).
 *
 * Una pubblicazione su «2» dello scientifico raggiunge chi guarda da 2A o 2B
 * dello scientifico (ClassCode::covering(2B) = [2B, 2]). È il modo più usato
 * di pubblicare; una sezione serve per un materiale di una classe sola.
 */

/** La spiegazione sotto un corso che ha anni. */
export const NOTA_ANNI = "Un anno vale per tutte le sezioni di quell'anno in questo indirizzo: "
    + "«2» dello Scientifico sono tutte le seconde dello scientifico, anche quelle che verranno. "
    + "Le sezioni servono per un materiale di una classe sola.";

/** Un anno: solo cifra. */
const eAnno = (code) => /^[1-9]$/.test(String(code || ""));

/**
 * La chiave di una classe: la sigla, e per un anno anche il corso. Due «3» di
 * corsi diversi sono due classi.
 *
 * @param {{code: string, indirizzo?: string|null}} v
 * @returns {string}
 */
export function chiaveClasse(v) {
    return `${String(v.code || "")}|${v.indirizzo ? String(v.indirizzo) : ""}`;
}

/**
 * I gruppi della scheda delle classi: un gruppo per ogni corso spuntato,
 * nell'ordine dei corsi della scuola, con prima i suoi anni e poi le sue
 * sezioni; un corso spuntato che non è a catalogo va in fondo con la sua
 * sigla. Le classi senza corso (sezioni di prima della migrazione 100) stanno
 * in un gruppo a parte in cima, per poterle ancora spuntare o spegnere.
 *
 * @param {Array<{code: string, indirizzo?: string|null}>} voci  le classi della scuola
 * @param {Array<{code: string, label: string}>} indirizziScuola  i corsi della scuola
 * @param {Set<string>} indirizziAccesi  i corsi spuntati dal docente
 * @returns {Array<{titolo: string, nota?: string, voci: Array<object>}>}
 */
export function gruppiDelleClassi(voci, indirizziScuola, indirizziAccesi) {
    const perCorso = new Map();
    for (const v of voci || []) {
        const corso = v.indirizzo || "";
        if (!perCorso.has(corso)) perCorso.set(corso, []);
        perCorso.get(corso).push(v);
    }
    const ordinate = (lista) => [...lista.filter((v) => eAnno(v.code)), ...lista.filter((v) => !eAnno(v.code))];
    const gruppo = (titolo, lista) => {
        const g = { titolo, voci: ordinate(lista) };
        if (lista.some((v) => eAnno(v.code))) g.nota = NOTA_ANNI;
        return g;
    };
    const scuola = indirizziScuola || [];
    const gruppi = [];
    if (perCorso.has("")) {
        gruppi.push({ titolo: "Senza indirizzo", voci: perCorso.get("") });
    }
    for (const ind of scuola) {
        const lista = perCorso.get(ind.code);
        if (lista && indirizziAccesi.has(ind.code)) gruppi.push(gruppo(`${ind.label} (${ind.code})`, lista));
    }
    for (const [corso, lista] of perCorso) {
        if (corso && indirizziAccesi.has(corso) && !scuola.some((i) => i.code === corso)) {
            gruppi.push(gruppo(corso, lista));
        }
    }
    return gruppi;
}

/**
 * I figli da passare a `replaceChildren`, senza i vuoti. `replaceChildren`
 * converte in testo tutto ciò che non è un nodo: un `null` diventava la parola
 * «null» sotto il conto delle classi.
 *
 * @param {...(Node|string|null|undefined|false)} parti
 * @returns {Array<Node|string>}
 */
export function soloPresenti(...parti) {
    return parti.filter((p) => p !== null && p !== undefined && p !== false);
}
