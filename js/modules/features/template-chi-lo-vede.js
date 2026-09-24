/**
 * Chi vede un modello risdoc, detto in parole.
 *
 * Segnalazione dell'utente (21/9/2026): «in /admin/templates posso gestire i
 * permessi, ma non vedo nessuna spunta su 👁 Visibile ✎ Collab. 🛡 Revisione
 * per nessun docente — e come docente.uno riesco a vedere i template
 * anche se non dovrei; inoltre fra i docenti c'è admin, che dovrebbe poter
 * fare tutto a prescindere».
 *
 * Misurato: le spunte non mentivano, ma non erano loro a decidere. In
 * `App\Services\Risdoc\Permission::canView` l'ordine è
 *   super-admin → collaboratore → spunta 👁 → **ambito del modello**,
 * e l'ambito (`risdoc_templates.visibility_scope`) vale `public` di default:
 * cioè «tutti i docenti», spunte o no. Il pannello non lo mostrava da nessuna
 * parte, e nemmeno permetteva di cambiarlo: mostrava una matrice vuota e
 * lasciava credere che decidesse lei.
 *
 * Qui stanno le due cose che si possono misurare senza un browser: la frase
 * che dice chi vede adesso, e le righe dei docenti (chi ha le spunte e chi
 * invece può tutto a prescindere).
 */

/** Gli ambiti che il server accetta (RisdocAdminController::setVisibilityScope). */
export const AMBITI = ["public", "institute", "indirizzo", "classe", "denied"];

/** L'etichetta di ognuno nel menu a tendina. */
export const ETICHETTE_AMBITO = {
    public:    "Tutti i docenti",
    institute: "Solo un Istituto",
    indirizzo: "Solo un indirizzo",
    classe:    "Solo una classe",
    denied:    "Nessuno, salvo le spunte 👁",
};

/**
 * Lo stesso, in due parole, per la pastiglia nell'elenco dei modelli: chi lo
 * vede è la prima domanda di chi apre quella pagina, e prima bisognava aprire
 * il pannello di ogni modello per rispondere.
 *
 * @returns {{testo: string, titolo: string, chiuso: boolean}} `chiuso` = non
 *          lo vedono tutti (la pastiglia si colora diversamente).
 */
export function ambitoInBreve(modello = {}) {
    const ambito = AMBITI.includes(modello.visibility_scope) ? modello.visibility_scope : "public";
    const finale = " Si cambia da «Gestisci» → «Chi lo vede adesso».";
    switch (ambito) {
        case "denied":
            // Non «nessuno»: sarebbe falso, perché le spunte, i collaboratori
            // e gli amministratori lo vedono lo stesso.
            return {
                testo: "solo su invito",
                titolo: "Lo vede solo chi è stato aggiunto con la spunta 👁, più i collaboratori ✎ e gli"
                    + " amministratori." + finale,
                chiuso: true,
            };
        case "institute":
            return {
                testo: "solo un Istituto",
                titolo: "Lo vedono solo i docenti di un Istituto." + finale,
                chiuso: true,
            };
        case "indirizzo":
            return {
                testo: `solo indirizzo ${modello.scope_indirizzo || "?"}`,
                titolo: `Lo vedono solo i docenti dell'indirizzo ${modello.scope_indirizzo || "(non indicato)"}.`
                    + finale,
                chiuso: true,
            };
        case "classe":
            return {
                testo: `solo classe ${modello.scope_classe || "?"}`,
                titolo: `Lo vedono solo i docenti della classe ${modello.scope_classe || "(non indicata)"}.`
                    + finale,
                chiuso: true,
            };
        default:
            return {
                testo: "tutti i docenti",
                titolo: "Lo vede ogni docente della piattaforma, spunte o non spunte." + finale,
                chiuso: false,
            };
    }
}

/**
 * Chi vede il modello adesso, in una frase.
 *
 * @param {{visibility_scope?:string, scope_institute_id?:number|string|null,
 *          scope_indirizzo?:string|null, scope_classe?:string|null}} modello
 * @param {Array<{id:number|string,name:string}>} [istituti]
 * @returns {string}
 */
export function chiLoVede(modello = {}, istituti = []) {
    const ambito = AMBITI.includes(modello.visibility_scope) ? modello.visibility_scope : "public";
    const inPiu = "Fuori da lì lo vede chi aggiungi con la spunta 👁 nell'elenco più in basso,"
        + " insieme ai collaboratori ✎ e agli amministratori.";
    switch (ambito) {
        case "public":
            return "Lo vedono tutti i docenti, anche quelli senza nessuna spunta: a decidere è"
                + " l'ambito, e adesso l'ambito è aperto a tutti. Le spunte 👁 servono ad aggiungere"
                + " chi l'ambito lascia fuori, quindi qui non aggiungono niente a nessuno e non"
                + " tolgono niente a nessuno. Per riservarlo a una parte, stringi l'ambito nel menu"
                + " qui sotto.";
        case "institute": {
            const id = Number(modello.scope_institute_id);
            const trovato = (istituti || []).find((i) => Number(i.id) === id);
            const nome = trovato ? trovato.name : (id > 0 ? `Istituto #${id}` : "un Istituto che non esiste più");
            return `Solo i docenti di ${nome}. ${inPiu}`;
        }
        case "indirizzo":
            return `Solo i docenti dell'indirizzo ${modello.scope_indirizzo || "(non indicato)"}. ${inPiu}`;
        case "classe":
            return `Solo i docenti della classe ${modello.scope_classe || "(non indicata)"}. ${inPiu}`;
        default:
            return "Nessun docente per conto suo: lo vede solo chi aggiungi con la spunta 👁"
                + " nell'elenco più in basso, insieme ai collaboratori ✎ e agli amministratori."
                + " È l'ambito in cui la spunta è l'unico modo per farlo vedere a qualcuno.";
    }
}

/**
 * Le pastiglie della riga, nel catalogo dei modelli.
 *
 * Segnalazione dell'utente (21/9/2026): «che significano 8 override, 8 drift,
 * ok... visib tutti a 0 ecc...». Erano cinque pastiglie in fila, tutte dello
 * stesso azzurro, tre delle quali dicevano zero, e due scritte con le parole
 * della tabella del database.
 *
 * Adesso: si mostra quello che c'è e si tace quello che non c'è (uno zero non
 * chiede niente a nessuno), si colora solo ciò che chiede di essere guardato,
 * e ogni pastiglia porta con sé la frase che la spiega.
 *
 * @param {Record<string, any>} modello la riga come la manda il catalogo
 * @returns {Array<{testo: string, titolo: string, tono: "neutro"|"avviso"}>}
 */
export function pastiglieDellaRiga(modello = {}) {
    const n = (v) => Number(v) || 0;
    const fuori = [];
    const ambito = ambitoInBreve(modello);

    // Chi lo vede c'è sempre, e non è né un allarme né un successo: è uno
    // stato. Restringere un modello non è un guasto, quindi niente colore.
    fuori.push({ testo: ambito.testo, titolo: ambito.titolo, tono: "neutro" });

    // Le spunte 👁 si scrivono solo quando l'ambito è ristretto: con «tutti i
    // docenti» non aggiungono nessuno, e un numero lì farebbe credere a un
    // permesso che non esiste.
    const spunte = n(modello.visible_count);
    if (spunte > 0 && ambito.chiuso) {
        fuori.push({
            testo: `👁 ${spunte} in più`,
            titolo: `${spunte} ${spunte === 1 ? "docente vede" : "docenti vedono"} questo modello pur restando`
                + " fuori dall'ambito, perché sono stati aggiunti uno per uno con la spunta 👁 in «Gestisci»."
                + " La spunta aggiunge e basta: per riservare davvero il modello si stringe l'ambito.",
            tono: "neutro",
        });
    }

    const collaboratori = n(modello.collab_count);
    if (collaboratori > 0) {
        fuori.push({
            testo: `✎ ${collaboratori} ${collaboratori === 1 ? "può" : "possono"} modificare`,
            titolo: `${collaboratori} ${collaboratori === 1 ? "docente può" : "docenti possono"} cambiare`
                + " l'originale, quello che usano tutti — non una loro copia: su questo modello hanno le stesse"
                + " mani di un amministratore, e lo vedono anche se l'ambito li lascia fuori. I nomi sono in"
                + " «Gestisci».",
            tono: "neutro",
        });
    }

    const salvataggi = n(modello.override_count);
    if (salvataggi > 0) {
        fuori.push({
            testo: `${salvataggi} ${salvataggi === 1 ? "salvataggio" : "salvataggi"}`,
            titolo: "Che cosa hanno salvato i docenti nelle loro copie personali:"
                + ` ${salvataggi} ${salvataggi === 1 ? "pezzo" : "pezzi"} in tutto — un testo riscritto,`
                + " un'immagine sostituita, o anche solo una copia creata e mai toccata. Conta i pezzi, non le"
                + " persone. L'originale non è toccato: serve a sapere se, cambiando il modello, tocchi un"
                + " lavoro già avviato.",
            tono: "neutro",
        });
    }

    // Niente colore nemmeno qui: da questa pagina non c'è niente da premere, e
    // un avviso che nessuno può spegnere diventa un rapporto che non si apre più.
    const nonAllineati = n(modello.drift_count);
    if (nonAllineati > 0) {
        fuori.push({
            testo: `${nonAllineati} non ${nonAllineati === 1 ? "allineato" : "allineati"}`,
            titolo: `Di quei salvataggi, ${nonAllineati} ${nonAllineati === 1 ? "è partito" : "sono partiti"}`
                + " da una versione di questo modello precedente a quella di adesso: chi li usa non ha le tue"
                + " ultime correzioni. Non si rompe niente e tornano allineati quando il docente risalva quel"
                + " pezzo; l'elenco per esteso è nella scheda «⚠ Copie non allineate».",
            tono: "neutro",
        });
    }

    // L'unica che chiede qualcosa a te, e per questo l'unica colorata.
    const daApprovare = n(modello.pending_count);
    if (daApprovare > 0) {
        fuori.push({
            testo: `🛡 ${daApprovare} da approvare`,
            titolo: `${daApprovare} ${daApprovare === 1 ? "modifica proposta" : "modifiche proposte"} da un`
                + " collaboratore ✎ sul modello di tutti, e non ancora entrate: restano ferme finché non"
                + " rispondi, e con loro il lavoro di un collega. Si risponde dalla scheda «🛡 Modifiche in"
                + " revisione».",
            tono: "avviso",
        });
    }

    return fuori;
}

/**
 * Le righe della matrice dei permessi.
 *
 * Chi è super-admin non ha caselle: le tre colonne per lui sono sempre vere e
 * nessuna spunta gliele toglie. Mostrargli tre caselle vuote diceva il
 * contrario — ed è esattamente quello che ha visto chi ha segnalato.
 *
 * @param {Array<Object>} docenti righe di `users` (id, username, first_name, last_name, is_super_admin)
 * @param {Set<number>} conSpunta id con visibilità esplicita
 * @param {Map<number, boolean>} collaboratori id → richiede revisione
 */
export function righeDeiPermessi(docenti = [], conSpunta = new Set(), collaboratori = new Map()) {
    return (docenti || []).map((u) => {
        const id = Number(u.id);
        const nome = [u.first_name, u.last_name].map((p) => (p || "").trim()).filter(Boolean).join(" ");
        return {
            id,
            username: String(u.username || ""),
            nome,
            sempre: Number(u.is_super_admin) === 1,
            visibile: conSpunta.has(id),
            collaboratore: collaboratori.has(id),
            revisione: collaboratori.get(id) === true,
        };
    });
}

/**
 * Il riassunto sopra la tabella: serve quando i docenti sono decine o
 * centinaia e le spunte non si contano a occhio.
 */
export function riassuntoDeiPermessi(righe = []) {
    const n = righe.length;
    const spunte = righe.filter((r) => r.visibile && !r.sempre).length;
    const collab = righe.filter((r) => r.collaboratore && !r.sempre).length;
    const sempre = righe.filter((r) => r.sempre).length;
    const pezzi = [`${n} ${n === 1 ? "docente" : "docenti"}`];
    pezzi.push(`${spunte} con la spunta 👁`);
    pezzi.push(`${collab} ${collab === 1 ? "collaboratore" : "collaboratori"} ✎`);
    if (sempre > 0) {
        pezzi.push(`${sempre} ${sempre === 1 ? "amministratore" : "amministratori"} (sempre tutto)`);
    }
    return pezzi.join(" · ");
}

/**
 * Filtro della tabella: cerca nel nome e nell'username, senza badare a
 * maiuscole e spazi ai bordi.
 */
export function filtraDocenti(righe = [], cerca = "") {
    const q = String(cerca || "").trim().toLowerCase();
    if (q === "") return righe;
    return righe.filter((r) =>
        r.username.toLowerCase().includes(q) || (r.nome || "").toLowerCase().includes(q));
}
