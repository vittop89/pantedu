/**
 * Il primo punto di un elenco numerato, in tutte le combinazioni (24/9/2026).
 *
 * Il menu «List ▸ Numerazione» della barra dell'editor ha due voci:
 * «continua» (la lista riprende dal punto dopo l'ultimo della lista numerata
 * precedente allo stesso livello; fuori da una lista ne crea una nuova) e
 * «inizia da…» (il docente scrive il segno del primo punto: c, iv, 7…). Il
 * valore è l'attributo `start` dell'<ol>.
 *
 * Qui si provano, per ognuno dei dieci stili numerati del menu e per ognuno
 * dei tre livelli che gli stili definiscono:
 *   - «inizia da…» con ogni forma di scrittura che vale per quel segno
 *     (lettera minuscola o maiuscola, con punto o parentesi, la posizione in
 *     cifre; numero romano; numero), e con quelle che non valgono;
 *   - «continua» dentro una lista, e fuori da ogni lista;
 *   - l'uscita da metà lista, che spezza la lista e continua la numerazione;
 *   - i casi in cui il menu non deve fare niente, e lo dice.
 *
 * E per ogni combinazione si guarda **che cosa si vede**, in quattro posti:
 *   - nel campo dell'editor e nella sua anteprima: il testo dei segni come
 *     Chrome li impagina, letto dall'istantanea del layout (`DOMSnapshot`),
 *     che riporta anche i segni con la parentesi fatti con
 *     `::marker { content: … }` — l'albero di accessibilità li lascia vuoti,
 *     misurato lo stesso giorno;
 *   - nella pagina del quesito: i segni `.fm-dsa-li-num` che il quesito
 *     salvato mostra (resa del client, specchio di ContractRenderer; la resa
 *     del server per le stesse combinazioni è in
 *     `tests/Unit/Rendering/ElencoRipresoTest.php`);
 *   - nel PDF: una verifica vera col quesito, scaricata e compilata con
 *     pdflatex, letta con pdftotext (@pdflatex).
 *
 * Il segno atteso non viene dall'applicazione: lo calcola `segno()` qui
 * sotto, da capo, perché una prova che chiede la risposta al codice che prova
 * non misura niente.
 *
 * Questa spec ha trovato, al primo giro, un difetto dell'editor: negli stili
 * con la parentesi Chrome non ridisegnava i segni quando `start` cambiava su
 * una lista già in pagina («1)» dopo start=7). Corretto in
 * `redrawListMarkers` (list-edit-utils.js).
 *
 * Due combinazioni del PDF sono dichiarate rotte, non nascoste: le loro
 * prove affermano il segno sbagliato che il PDF stampa oggi, così diventano
 * rosse solo quando qualcuno lo corregge, e allora si mette l'atteso giusto.
 * (Non `test.fail`: passerebbe anche per un errore qualunque prima del
 * confronto, un verde che non guarda.) Tutte e due c'erano prima del menu, e
 * non riguardano l'inizio — la numerazione è giusta, è sbagliato l'aspetto:
 *   - lo stile predefinito «1. a. i.» nel PDF esce «(a) i. A.»: senza preset
 *     il Sanitizer non scrive etichette, e LaTeX usa le sue a partire dal
 *     livello dell'elenco degli esercizi, che contiene il quesito;
 *   - lo stile «01.» oltre il 9 nel PDF stampa «012.» dove lo schermo mostra
 *     «12.» (etichetta `0\arabic*` in `Sanitizer::PRESET_LEVELS`).
 */
const { execFileSync } = require("node:child_process");
const {
    test, expect, cartellaDiLavoro, compilaConPdflatex, estraiZip,
} = require("../../support/test");

/** I dieci stili numerati del menu List: preset e segno dei tre livelli. */
const STILI = [
    { voce: "ol",             preset: "",                   livelli: ["D.",  "la.", "lr."] },
    { voce: "ol-Alpha",       preset: "alpha-decimal",      livelli: ["UA.", "D.",  "la."] },
    { voce: "ol-alpha",       preset: "lower-alpha-roman",  livelli: ["la.", "lr.", "D."] },
    { voce: "ol-Roman",       preset: "roman-alpha",        livelli: ["UR.", "UA.", "D."] },
    { voce: "ol-zero",        preset: "decimal-zero",       livelli: ["0D.", "la.", "lr."] },
    { voce: "ol-paren",       preset: "paren",              livelli: ["D)",  "la)", "lr)"] },
    { voce: "ol-Alpha-paren", preset: "alpha-paren",        livelli: ["UA)", "D)",  "la)"] },
    { voce: "ol-alpha-paren", preset: "lower-alpha-paren",  livelli: ["la)", "lr)", "D)"] },
    { voce: "ol-Roman-paren", preset: "roman-paren",        livelli: ["UR)", "UA)", "D)"] },
    { voce: "ol-zero-paren",  preset: "decimal-zero-paren", livelli: ["0D)", "la)", "lr)"] },
];

/** Che tipo di segno è: lettere, romani o numeri. */
function tipo(codice) {
    const c = codice.slice(0, -1);
    if (c === "la" || c === "UA") return "lettere";
    if (c === "lr" || c === "UR") return "romani";
    return "numeri";
}

/** Il segno del punto numero `n` per quel codice, calcolato da capo. */
function segno(codice, n) {
    const romano = (x) => {
        let s = "";
        for (const [v, r] of [[1000, "m"], [900, "cm"], [500, "d"], [400, "cd"], [100, "c"], [90, "xc"],
            [50, "l"], [40, "xl"], [10, "x"], [9, "ix"], [5, "v"], [4, "iv"], [1, "i"]]) {
            while (x >= v) { s += r; x -= v; }
        }
        return s;
    };
    const lettera = String.fromCharCode(96 + n);
    const corpo = {
        D: String(n), "0D": String(n).padStart(2, "0"),
        la: lettera, UA: lettera.toUpperCase(),
        lr: romano(n), UR: romano(n).toUpperCase(),
    }[codice.slice(0, -1)];
    return corpo + codice.slice(-1);
}

/** Che cosa scrive il docente in «inizia da…», e il primo numero che ne viene. */
const SCRITTURE = {
    lettere: [["c", 3], ["C", 3], ["c)", 3], ["c.", 3], ["3", 3], ["y", 25], ["a", 1]],
    romani: [["iv", 4], ["IV", 4], ["iv)", 4], ["4", 4], ["xii", 12], ["i", 1]],
    numeri: [["7", 7], ["07", 7], ["12)", 12], ["999", 999], ["1", 1]],
};

/** E che cosa non vale: il menu lo dice e non cambia niente. La «z» non vale
 *  perché le liste di prova hanno due punti, e il secondo andrebbe oltre. */
const SBAGLIATE = {
    lettere: ["27", "aa", "0", "", "z", "-1", "iv"],
    romani: ["iiii", "vx", "b", "0", "1000"],
    numeri: ["c", "0", "1000", "2.5", "", "iv"],
};

const CLASSE = 'class="fm-dsa-li-list" data-dsa-section="question"';

/**
 * Una lista di voci `voci` al livello `livello`, dentro la prima voce di ogni
 * livello sopra. Solo la lista più esterna porta il preset, come le fa il menu.
 */
function annidata(preset, livello, voci, start = 1) {
    const inizio = start > 1 ? ` start="${start}"` : "";
    let html = `<ol ${CLASSE}${inizio}>${voci.map((v) => `<li>${v}</li>`).join("")}</ol>`;
    for (let d = livello; d > 0; d--) html = `<ol ${CLASSE}><li>sopra${d}${html}</li></ol>`;
    // La prima apertura nella stringa è la lista più esterna.
    return preset ? html.replace(`<ol ${CLASSE}`, `<ol ${CLASSE} data-fm-list-style="${preset}"`) : html;
}

/** Aiuti che girano nella pagina: installati a ogni prova, la pagina è nuova. */
async function installa(page) {
    await page.evaluate(() => {
        const profondita = (lista, contenitore) => {
            let d = 0;
            for (let n = lista.parentNode; n && n !== contenitore; n = n.parentNode) {
                if (n.nodeType === 1 && /^(OL|UL)$/.test(n.tagName)) d++;
            }
            return d;
        };
        // La lista del livello `d` che viene per ultima: quella su cui si agisce.
        const bersaglio = (contenitore, d) => {
            const tutte = [...contenitore.querySelectorAll("ol, ul")].filter((l) => profondita(l, contenitore) === d);
            return tutte[tutte.length - 1] ?? null;
        };
        // L'editor esiste solo per chi insegna: la pagina del banco non ha la
        // classe, e senza scatterebbero le regole per gli studenti
        // (`list-style: revert`), che nell'editor vero non valgono.
        document.body.classList.add("fm-teacher-access");
        // Le notifiche a comparsa del menu, registrate invece di mostrate.
        const avvisi = [];
        window.FM.ToastManager.show = (tipoAvviso, _titolo, testo) => { avvisi.push({ tipo: tipoAvviso, testo }); };
        Object.assign(window, { __prova: { profondita, bersaglio, avvisi, letture: 0 } });
    });
}

/** Un campo dell'editor nuovo, con dentro `html`. */
async function campo(page, html) {
    await page.evaluate((contenuto) => {
        document.querySelectorAll("body > .fm-prova-numerazione").forEach((n) => n.remove());
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        wrap.classList.add("fm-prova-numerazione");
        document.body.appendChild(wrap);
        const f = wrap.querySelector(".fm-editor-field");
        f.innerHTML = contenuto;
        window.__prova.campo = f;
        window.__prova.avvisi.length = 0;
    }, html);
}

/**
 * Mette il caret nella prima voce della lista bersaglio del livello `livello`
 * (o nel nodo `#qui`, se `livello` è null) e lancia la voce del menu. Non
 * aspetta: «inizia da…» apre una finestra, che la prova compila.
 */
async function lancia(page, azione, livello) {
    await page.evaluate(([a, d]) => {
        const f = window.__prova.campo;
        const dove = d === null ? f.querySelector("#qui") : window.__prova.bersaglio(f, d).querySelector(":scope > li");
        f.focus();
        const r = document.createRange();
        r.setStart(dove.firstChild ?? dove, 0);
        r.collapse(true);
        const s = window.getSelection();
        s.removeAllRanges();
        s.addRange(r);
        window.__prova.azione = window.FM.__setListNumberingForTest({ _focusedTextarea: f }, a);
    }, [azione, livello]);
}

async function finita(page) {
    await page.evaluate(() => window.__prova.azione);
}

/**
 * Che cosa si vede della lista bersaglio del livello `livello`: l'attributo,
 * i segni nella pagina del quesito (resa del client dei blocchi salvati) e,
 * con `segni`, quelli dell'editor. Marca le voci per l'istantanea con un gettone.
 */
async function stato(page, livello) {
    return page.evaluate((d) => {
        const f = window.__prova.campo;
        const lista = window.__prova.bersaglio(f, d);
        // Un gettone nuovo a ogni lettura: l'anteprima copia l'HTML del campo,
        // marcature comprese, e una sua copia vecchia non deve contare.
        const gettone = `g${++window.__prova.letture}`;
        f.querySelectorAll("[data-segno]").forEach((n) => n.removeAttribute("data-segno"));
        lista.querySelectorAll(":scope > li").forEach((li, i) => li.setAttribute("data-segno", `${gettone}:${i}`));
        // Come quando il docente scrive: l'anteprima si ridisegna dal campo.
        f.dispatchEvent(new Event("input", { bubbles: true }));
        const html = window.FM.__toHtmlForTest(window.FM.__buildBlocksFromTextareaForTest(f));
        const t = document.createElement("template");
        t.innerHTML = html;
        const resa = window.__prova.bersaglio(t.content, d);
        return {
            gettone,
            start: lista.getAttribute("start"),
            preset: window.__prova.bersaglio(f, 0).getAttribute("data-fm-list-style"),
            pagina: resa ? [...resa.querySelectorAll(":scope > li > .fm-dsa-li-num")].map((n) => n.textContent) : [],
            avvisi: window.__prova.avvisi.splice(0),
            dialoghi: document.querySelectorAll("#fm-dialog-modal").length,
        };
    }, livello);
}

/**
 * Il testo dei segni delle voci marcate col gettone, come Chrome li ha
 * impaginati (il layout del loro pseudo-elemento ::marker), separati fra il
 * campo dell'editor e la sua anteprima.
 */
async function segni(page, gettone) {
    const cdp = await page.context().newCDPSession(page);
    try {
        const { documents, strings } = await cdp.send("DOMSnapshot.captureSnapshot", { computedStyles: [] });
        const doc = documents[0];
        const nodi = doc.nodes;
        const pseudo = new Map((nodi.pseudoType?.index ?? []).map((i, k) => [i, strings[nodi.pseudoType.value[k]]]));
        const attributo = (i, nome) => {
            const a = nodi.attributes?.[i] ?? [];
            for (let k = 0; k < a.length; k += 2) if (strings[a[k]] === nome) return strings[a[k + 1]];
            return null;
        };
        const dove = (i) => {
            for (let n = i; n !== undefined && n !== -1; n = nodi.parentIndex[n]) {
                const classi = ` ${attributo(n, "class") ?? ""} `;
                if (classi.includes(" fm-editor-field ")) return "campo";
                if (classi.includes(" fm-editor-preview ")) return "anteprima";
            }
            return null;
        };
        /** @type {{campo: string[], anteprima: string[]}} */
        const out = { campo: [], anteprima: [] };
        doc.layout.nodeIndex.forEach((indice, k) => {
            const testo = doc.layout.text[k];
            if (testo === undefined || testo === -1) return;
            let n = indice;
            while (n !== undefined && n !== -1 && pseudo.get(n) !== "marker") n = nodi.parentIndex[n];
            if (n === undefined || n === -1) return;
            const voce = nodi.parentIndex[n];
            const marca = attributo(voce, "data-segno");
            if (!marca || !marca.startsWith(`${gettone}:`)) return;
            const dentro = dove(voce);
            if (!dentro) return;
            const p = Number(marca.split(":")[1]);
            out[dentro][p] = (out[dentro][p] ?? "") + strings[testo];
        });
        return { campo: out.campo.map((x) => x.trim()), anteprima: out.anteprima.map((x) => x.trim()) };
    } finally {
        await cdp.detach();
    }
}

/** Controlla i segni nel campo subito, e nell'anteprima quando si è ridisegnata. */
async function siVede(page, s, attesi, dove) {
    expect((await segni(page, s.gettone)).campo, `${dove}: i segni nel campo dell'editor`).toEqual(attesi);
    await expect.poll(async () => (await segni(page, s.gettone)).anteprima, {
        message: `${dove}: i segni nell'anteprima dell'editor`, timeout: 10_000,
    }).toEqual(attesi);
    expect(s.pagina, `${dove}: i segni nella pagina del quesito`).toEqual(attesi);
}

/** Compila la finestra di «inizia da…»: controlla la proposta e scrive. */
async function rispondi(page, proposta, scritto) {
    const finestra = page.getByRole("dialog");
    const casella = finestra.locator(".fm-dialog-input");
    await expect(casella, "la finestra propone il primo punto di adesso").toHaveValue(proposta);
    await casella.fill(scritto);
    await finestra.getByRole("button", { name: "OK" }).click();
}

test.beforeEach(async ({ bancoEditor }) => {
    await installa(bancoEditor.page);
});

for (const stile of STILI) {
    test.describe(`stile ${stile.voce} (${stile.livelli.map((c) => segno(c, 1)).join(" ")})`, () => {
        for (const [livello, codice] of stile.livelli.entries()) {
            const scritture = SCRITTURE[tipo(codice)];
            const voci = ["uno", "due"];

            test(`livello ${livello + 1}: «inizia da…» con ogni scrittura che vale`, async ({ bancoEditor }) => {
                const page = bancoEditor.page;
                await campo(page, annidata(stile.preset, livello, voci));
                let proposta = segno(codice, 1).slice(0, -1);
                for (const [scritto, n] of scritture) {
                    await lancia(page, "num-inizia", livello);
                    await rispondi(page, proposta, scritto);
                    await finita(page);
                    const s = await stato(page, livello);
                    const attesi = [segno(codice, n), segno(codice, n + 1)];
                    expect(s.avvisi, `«${scritto}»: nessun avviso`).toEqual([]);
                    expect(s.start, `«${scritto}» → start`).toBe(n === 1 ? null : String(n));
                    expect(s.preset, "lo stile non cambia").toBe(stile.preset || null);
                    await siVede(page, s, attesi, `«${scritto}»`);
                    proposta = segno(codice, n).slice(0, -1);
                }
            });

            test(`livello ${livello + 1}: «inizia da…» rifiuta quello che non vale, e «Annulla» non cambia niente`, async ({ bancoEditor }) => {
                const page = bancoEditor.page;
                await campo(page, annidata(stile.preset, livello, voci, 2));
                const attesi = [segno(codice, 2), segno(codice, 3)];
                for (const scritto of SBAGLIATE[tipo(codice)]) {
                    await lancia(page, "num-inizia", livello);
                    await rispondi(page, segno(codice, 2).slice(0, -1), scritto);
                    await finita(page);
                    const s = await stato(page, livello);
                    expect(s.avvisi.map((a) => a.tipo), `«${scritto}» si rifiuta con un errore`).toEqual(["error"]);
                    expect(s.start, `«${scritto}» non tocca la lista`).toBe("2");
                    expect(s.pagina).toEqual(attesi);
                }
                await lancia(page, "num-inizia", livello);
                await page.getByRole("dialog").getByRole("button", { name: "Annulla" }).click();
                await finita(page);
                const s = await stato(page, livello);
                expect(s.avvisi, "«Annulla» non dice niente").toEqual([]);
                expect(s.start, "e non cambia niente").toBe("2");
                await siVede(page, s, attesi, "dopo «Annulla»");
            });

            test(`livello ${livello + 1}: «continua» riprende dalla lista precedente dello stesso livello`, async ({ bancoEditor }) => {
                const page = bancoEditor.page;
                // Due rami: la lista del livello nel primo parte da 2 e ha tre
                // punti, quella nel secondo deve ripartire da 5.
                const prima = annidata("", livello, ["p1", "p2", "p3"], 2).replace(/^<ol [^>]*>/, "").replace(/<\/ol>$/, "");
                const seconda = annidata("", livello, voci).replace(/^<ol [^>]*>/, "").replace(/<\/ol>$/, "");
                const radice = `<ol ${CLASSE}${stile.preset ? ` data-fm-list-style="${stile.preset}"` : ""}>`;
                const html = livello === 0
                    ? `${annidata(stile.preset, 0, ["p1", "p2", "p3"], 2)}<div>Un paragrafo in mezzo.</div>${annidata(stile.preset, 0, voci)}`
                    : `${radice}${prima}${seconda}</ol>`;
                await campo(page, html);
                await lancia(page, "num-continua", livello);
                await finita(page);
                const s = await stato(page, livello);
                const attesi = [segno(codice, 5), segno(codice, 6)];
                expect(s.avvisi).toEqual([]);
                expect(s.start, "riprende da 2 + 3").toBe("5");
                await siVede(page, s, attesi, "«continua»");
            });
        }

        test("«continua» fuori da ogni lista ne crea una nuova con lo stesso stile, che riprende", async ({ bancoEditor }) => {
            const page = bancoEditor.page;
            const codice = stile.livelli[0];
            await campo(page, `${annidata(stile.preset, 0, ["uno", "due"], 3)}<div>Un paragrafo.</div><div id="qui">cinque</div>`);
            await lancia(page, "num-continua", null);
            await finita(page);
            const s = await stato(page, 0);
            expect(s.avvisi).toEqual([]);
            expect(s.preset, "la lista nuova ha lo stile di quella che continua").toBe(stile.preset || null);
            expect(s.start, "e riprende da 3 + 2").toBe("5");
            await siVede(page, s, [segno(codice, 5)], "la lista nuova");
        });

        test("uscire da metà lista la spezza, e la seconda metà continua la numerazione", async ({ bancoEditor }) => {
            const page = bancoEditor.page;
            const codice = stile.livelli[0];
            await campo(page, annidata(stile.preset, 0, ["uno", "due", "tre", "quattro"], 2));
            await page.evaluate(() => {
                const f = window.__prova.campo;
                window.FM.__outdentListItemForTest(f.querySelectorAll(":scope > ol > li")[1], f, false, true);
            });
            const ordine = await page.evaluate(() => [...window.__prova.campo.children].map((n) => `${n.tagName}:${n.textContent}`));
            expect(ordine, "nessuna voce cambia posto").toEqual(["OL:uno", "DIV:due", "OL:trequattro"]);
            const s = await stato(page, 0);
            expect(s.preset, "la seconda metà ha lo stesso stile").toBe(stile.preset || null);
            expect(s.start).toBe("3");
            await siVede(page, s, [segno(codice, 3), segno(codice, 4)], "la seconda metà");
        });

        test("cambiare stile a una lista che ha già un inizio lo conserva", async ({ bancoEditor }) => {
            const page = bancoEditor.page;
            const codice = stile.livelli[0];
            // Si parte da un altro stile, con la lista che comincia dal terzo punto.
            const altro = stile.voce === "ol-alpha" ? STILI[0] : STILI.find((x) => x.voce === "ol-alpha");
            await campo(page, annidata(altro.preset, 0, ["uno", "due"], 3));
            await page.evaluate((voce) => {
                const f = window.__prova.campo;
                f.focus();
                const r = document.createRange();
                r.setStart(f.querySelector("li").firstChild, 0);
                r.collapse(true);
                window.getSelection().removeAllRanges();
                window.getSelection().addRange(r);
                window.FM.__insertListSnippetForTest({ _focusedTextarea: f }, voce);
            }, stile.voce);
            const s = await stato(page, 0);
            expect(s.preset, "lo stile è quello scelto").toBe(stile.preset || null);
            expect(s.start, "l'inizio resta").toBe("3");
            await siVede(page, s, [segno(codice, 3), segno(codice, 4)], "dopo il cambio di stile");
        });
    });
}

test.describe("quando il menu non deve fare niente, lo dice", () => {
    const casi = [
        { nome: "«inizia da…» in un elenco puntato", html: '<ul class="fm-dsa-li-list"><li>p</li></ul>', azione: "num-inizia", livello: 0, avviso: "warning" },
        { nome: "«continua» in un elenco puntato", html: '<ol><li>a</li></ol><ul class="fm-dsa-li-list"><li>p</li></ul>', azione: "num-continua", livello: 0, avviso: "warning" },
        { nome: "«inizia da…» fuori da ogni lista", html: '<ol><li>a</li></ol><div id="qui">testo</div>', azione: "num-inizia", livello: null, avviso: "warning" },
        { nome: "«continua» senza una lista numerata prima", html: `<ol ${CLASSE}><li>a</li></ol>`, azione: "num-continua", livello: 0, avviso: "info" },
        { nome: "«continua» che porterebbe le lettere oltre la z", html: `<ol ${CLASSE} data-fm-list-style="lower-alpha-roman" start="25"><li>y</li><li>z</li></ol><div>p</div><ol ${CLASSE} data-fm-list-style="lower-alpha-roman"><li>a</li></ol>`, azione: "num-continua", livello: 0, avviso: "error" },
    ];
    for (const caso of casi) {
        test(caso.nome, async ({ bancoEditor }) => {
            const page = bancoEditor.page;
            await campo(page, caso.html);
            const prima = await page.evaluate(() => window.__prova.campo.innerHTML);
            await lancia(page, caso.azione, caso.livello);
            await finita(page);
            const dopo = await page.evaluate(() => ({
                html: window.__prova.campo.innerHTML,
                avvisi: window.__prova.avvisi.splice(0),
                dialoghi: document.querySelectorAll("#fm-dialog-modal").length,
            }));
            expect(dopo.avvisi.map((a) => a.tipo), "un avviso solo, del tipo giusto").toEqual([caso.avviso]);
            expect(dopo.dialoghi, "nessuna finestra").toBe(0);
            // Gli attributi data-a11y-* li aggiunge da sé, e quando capita, lo
            // script di accessibilità della pagina: non sono una modifica del menu.
            const pulito = (html) => html.replace(/ data-a11y-[a-z-]+="[^"]*"/g, "");
            expect(pulito(dopo.html), "il campo non cambia").toBe(pulito(prima));
        });
    }
});

test.describe("nel PDF della verifica", () => {
    /**
     * Un quesito con tutte le liste di prova, reso come lo rende la pagina,
     * in una verifica vera: si scarica il pacchetto, si compila main_NOR.tex
     * e si legge il testo. Ogni voce ha una parola sua, e il segno è quello
     * che la precede sulla riga.
     */
    async function segniNelPdf(page, verificaFactory, teacherApi, naming, liste) {
        const html = await page.evaluate((contenuti) => contenuti.map((contenuto) => {
            const wrap = window.FM.__buildSectionForTest("Quesito", "");
            const f = wrap.querySelector(".fm-editor-field");
            f.innerHTML = contenuto;
            return window.FM.__toHtmlForTest(window.FM.__buildBlocksFromTextareaForTest(f));
        }), liste);
        const batch = await verificaFactory.batch({
            title: naming.unique("numerazione"),
            versionLabel: "num",
            overrides: {
                selectedIIS: "sc", selectedCLS: "3", indirizzo: "sc", classe: "3",
                nPrint: 1, dsa: false,
                problems: [{
                    filePath: "/x", problemId: "type_Collect_x", position: 1, type: "Collect", text: "Elenchi.",
                    items: html.map((h) => ({ html: h, points: 1, includeSolution: false })),
                }],
            },
        });
        expect(batch.batch_id, "la verifica si crea").toBeTruthy();
        const cartella = cartellaDiLavoro(`numerazione-${batch.batch_id}`);
        estraiZip(await teacherApi.verifica.batchZip(batch.batch_id), cartella);
        const esito = compilaConPdflatex(`${cartella}/versioni`, "main_NOR.tex");
        expect(esito.byte, `main_NOR.tex compila: ${esito.errore ?? ""}`).toBeGreaterThan(0);
        const testo = execFileSync("pdftotext", ["-layout", "main_NOR.pdf", "-"], { cwd: `${cartella}/versioni` }).toString("utf8");
        /** @type {Record<string, string>} */
        const segni = {};
        for (const m of testo.matchAll(/(\S+)\s+(v\d+l\dn\d)\b/g)) segni[m[2]] = m[1];
        return segni;
    }

    /** Tre livelli annidati, ognuno col suo primo punto: 3 per le lettere, 4 per i romani, 7 per i numeri. */
    const PARTENZA = { lettere: 3, romani: 4, numeri: 7 };
    function tuttiILivelli(stile, s) {
        const lista = (d) => {
            const inizio = PARTENZA[tipo(stile.livelli[d])];
            const voci = [0, 1].map((i) => `<li>v${s}l${d}n${i}${d < 2 && i === 0 ? lista(d + 1) : ""}</li>`).join("");
            const preset = d === 0 && stile.preset ? ` data-fm-list-style="${stile.preset}"` : "";
            return `<ol ${CLASSE}${preset} start="${inizio}">${voci}</ol>`;
        };
        return lista(0);
    }

    /** Il segno letto nel PDF e quello atteso, per ogni voce delle liste di `stili`. */
    async function confronto(page, verificaFactory, teacherApi, naming, stili) {
        const letti = await segniNelPdf(page, verificaFactory, teacherApi, naming, stili.map((stile, s) => tuttiILivelli(stile, s)));
        const attesi = {};
        const visti = {};
        stili.forEach((stile, s) => stile.livelli.forEach((codice, d) => [0, 1].forEach((i) => {
            const chiave = `${stile.voce} livello ${d + 1} punto ${i + 1}`;
            attesi[chiave] = segno(codice, PARTENZA[tipo(codice)] + i);
            visti[chiave] = letti[`v${s}l${d}n${i}`] ?? "(manca)";
        })));
        return { attesi, visti };
    }

    test("ogni stile con un preset, a ogni livello, parte dal punto scelto @pdflatex", async ({
        bancoEditor, verificaFactory, teacherApi, naming, strumenti,
    }) => {
        test.setTimeout(300_000);
        strumenti.richiede("pdflatex");
        const { attesi, visti } = await confronto(bancoEditor.page, verificaFactory, teacherApi, naming,
            STILI.filter((stile) => stile.preset !== ""));
        expect(visti).toEqual(attesi);
    });

    test("lo stile predefinito «1. a. i.» nel PDF: difetto noto, esce «(a) i. A.» @pdflatex", async ({
        bancoEditor, verificaFactory, teacherApi, naming, strumenti,
    }) => {
        test.setTimeout(300_000);
        strumenti.richiede("pdflatex");
        const { visti } = await confronto(bancoEditor.page, verificaFactory, teacherApi, naming,
            STILI.filter((stile) => stile.preset === ""));
        // Settimo, terzo e quarto punto: l'inizio c'è, l'aspetto è quello di
        // LaTeX (livelli 2-4 di enumerate) invece di 7. c. iv. dello schermo.
        expect(visti, "difetto noto: quando lo si corregge, l'atteso diventa 7. 8. c. d. iv. v.").toEqual({
            "ol livello 1 punto 1": "(g)", "ol livello 1 punto 2": "(h)",
            "ol livello 2 punto 1": "iii.", "ol livello 2 punto 2": "iv.",
            "ol livello 3 punto 1": "D.", "ol livello 3 punto 2": "E.",
        });
    });

    test("lo stile «01.» oltre il 9 nel PDF: difetto noto, stampa «012.» @pdflatex", async ({
        bancoEditor, verificaFactory, teacherApi, naming, strumenti,
    }) => {
        test.setTimeout(300_000);
        strumenti.richiede("pdflatex");
        const zero = STILI.filter((s) => s.preset.startsWith("decimal-zero"));
        const liste = zero.map((stile, s) => `<ol ${CLASSE} data-fm-list-style="${stile.preset}" start="12"><li>v${s}l0n0</li></ol>`);
        const segni = await segniNelPdf(bancoEditor.page, verificaFactory, teacherApi, naming, liste);
        // A schermo «12.» e «12)» (segno()); nel PDF l'etichetta 0\arabic* di
        // Sanitizer::PRESET_LEVELS mette lo zero davanti a ogni numero.
        expect(zero.map((stile, s) => segni[`v${s}l0n0`]), "difetto noto: quando lo si corregge, l'atteso diventa 12. e 12)")
            .toEqual(["012.", "012)"]);
    });
});
