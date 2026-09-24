// @ts-check
/**
 * Ogni sidepage crea e modifica ogni tipo di documento (15/9/2026).
 *
 * Richiesta dell'utente: «per ogni sidepage il docente deve poter creare
 * qualsiasi tipo di documento», dopo che una mappa creata nel Laboratorio si
 * apriva con «Modifica esercizio» e la mappa vuota restava bianca. Qui il giro
 * vero, dalla finestra «+ Nuovo» di ciascuna delle sei sidepage e per ciascuno
 * dei cinque modi: il contenuto nasce del tipo giusto, compare in quella
 * sidepage con un collegamento alla pagina del suo tipo, si riapre con ✎ come
 * il suo tipo e il salvataggio arriva. La verifica non ha un modo nella
 * finestra: nasce dall'API, e si prova che ogni sidepage la mostri e la modifichi.
 *
 * Il primo giro, il 15/9, ha trovato che nelle sidepage BES/DSA e Risorse
 * docente una mappa o un esercizio aprivano «Nessun documento BES/DSA per questa
 * combinazione» (collegamento-contenuto.js): 8 casi rossi su 10 lì.
 *
 * I contenuti della finestra nascono dalla finestra perché è lei che si prova;
 * la cancellazione passa dall'API ed è registrata appena si conosce l'id.
 *
 * 19/9/2026 (analisi C-export-bodypt e F-metadati-modifica-mappa): ✎ e «Salva»
 * non cambiano i metadati salvo quello che si è cambiato nel modulo. Prima il
 * modale li ricostruiva e il server li sostituiva: una mappa riceveva
 * `layout: exercises` e un body_pt d'esempio (e il 📥), perdeva
 * `mappa.href_hide` e `mappa.drawio_id`; un esercizio perdeva `contract_key`,
 * e /contract rispondeva 404.
 */
const { test, expect } = require("../support/test");

/** @typedef {import("../support/test").SidepageKey} SidepageKey */

/** @type {ReadonlyArray<[SidepageKey, string]>} [chiave del page object, section_key] */
const SIDEPAGE = [
    ["mappe", "mappe"],
    ["laboratorio", "lab"],
    ["esercizi", "eser"],
    ["verifiche", "verif"],
    ["besDsa", "bes"],
    ["risorseDocente", "risdoc"],
];

/** modo della finestra → tipo del contenuto che deve nascere */
const MODI = {
    link: "mappa",
    upload: "mappa",
    drawio_native: "mappa",
    exercises: "esercizio",
    custom: "document",
};

const DRAWIO = '<mxfile host="e2e"><diagram id="d" name="Pagina"><mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/></root></mxGraphModel></diagram></mxfile>';

/**
 * La pagina di studio del tipo: un documento di BES/DSA e Risorse ha la sua.
 *
 * @param {string} tipo
 * @param {string} sezione
 * @returns {string}
 */
function percorsoDelTipo(tipo, sezione) {
    return tipo === "document" && (sezione === "bes" || sezione === "risdoc") ? sezione : tipo;
}

/**
 * Senza `_`: le sidepage BES/DSA e Risorse lo mostrano come spazio.
 *
 * @param {string} sezione
 * @param {string} cosa
 * @param {import("@playwright/test").TestInfo} testInfo
 * @returns {string}
 */
function titoloUnico(sezione, cosa, testInfo) {
    return `e2e-sidepage-${sezione}-${cosa.replace(/_/g, "-")}-${testInfo.workerIndex}-${Date.now()}`;
}

/**
 * @param {import("../support/test").HomeSidebarPage} homeDocente
 * @param {import("../support/test").PublicEnv} env
 * @param {SidepageKey} pagina
 */
async function apriLaSidepage(homeDocente, env, pagina) {
    await homeDocente.vaiA();
    await homeDocente.scegliTerna(env.terna.indirizzo, env.terna.classe, env.terna.materia);
    return homeDocente.apriSidepage(pagina);
}

/**
 * I metadati del contenuto come li legge il docente (`show` li dà decodificati).
 *
 * @param {import("../support/test").TeacherApi} teacherApi
 * @param {number} id
 * @returns {Promise<unknown>}
 */
async function metadatiDi(teacherApi, id) {
    const riga = /** @type {Record<string, unknown>} */ (/** @type {unknown} */ ((await teacherApi.content.get(id)).content));
    const meta = riga.metadata;
    return meta && typeof meta === "object" && !Array.isArray(meta) ? meta : {};
}

/**
 * La voce c'è, porta alla pagina del suo tipo, si riapre con ✎ come il suo tipo
 * e il titolo nuovo arriva al server e alla voce. I metadati restano quelli di
 * prima; una mappa non ha il 📥; un esercizio o una verifica hanno ancora il
 * loro contratto.
 *
 * @param {{ page: import("../support/test").Page, pannello: import("../support/test").Locator,
 *           teacherApi: import("../support/test").TeacherApi, id: number, titolo: string,
 *           tipo: string, sezione: string, conFile: boolean }} argomenti
 */
async function voceModificabile({ page, pannello, teacherApi, id, titolo, tipo, sezione, conFile }) {
    const percorso = percorsoDelTipo(tipo, sezione);
    const voce = pannello.locator(`li[data-content-id="${id}"]`);
    await expect(voce, `il contenuto ${id} compare nella sidepage ${sezione}`).toBeAttached({ timeout: 30_000 });

    const href = new URL((await voce.locator("a").first().getAttribute("href")) || "", page.url());
    expect(href.pathname, "il collegamento della voce apre la pagina del suo tipo").toMatch(new RegExp(`^/studio/${percorso}/`));
    if (percorso !== "bes" && percorso !== "risdoc") {
        expect(href.searchParams.get("ids"), "il collegamento della voce apre proprio questo contenuto").toBe(String(id));
    }

    // Aperto un documento, la sidepage esce dalla modifica: si riattiva il
    // blocco in cui sta la voce.
    if (!(await voce.locator(".fm-item-edit").isVisible())) {
        const blocco = pannello.locator("ul.fm-db-block", { has: page.locator(`li[data-content-id="${id}"]`) });
        await blocco.locator(".js-edit-section").click();
        await expect(blocco).toHaveAttribute("data-edit-active", "1");
    }
    const metadatiPrima = await metadatiDi(teacherApi, id);
    if (tipo === "mappa") {
        await expect(voce, "una mappa non ha niente da scaricare come ZIP TeX").toHaveAttribute("data-has-body-pt", "0");
        await expect(voce.locator(".fm-item-export")).toHaveCount(0);
    }
    await voce.locator(".fm-item-edit").click();
    const modifica = page.locator(".fm-modal-backdrop").last();
    await expect(modifica.locator('input[name="title"]')).toHaveValue(titolo, { timeout: 15_000 });
    await expect(modifica.locator("code").first(), "✎ apre la modifica del tipo del contenuto").toHaveText(tipo);
    if (conFile) {
        await expect(modifica.locator(".fm-modal-drawio-edit-btn"), "la mappa con il file offre l'editor").toBeVisible();
    }

    const nuovo = `${titolo}-mod`;
    await modifica.locator('input[name="title"]').fill(nuovo);
    const salvataggio = page.waitForResponse((r) => r.request().method() === "POST"
        && new URL(r.url()).pathname === `/api/teacher/content/${id}/update`);
    await modifica.locator('button[type="submit"]').click();
    const salvato = await salvataggio;
    expect(salvato.ok(), `salvataggio: ${salvato.status()} ${await salvato.text()}`).toBe(true);
    const dopo = /** @type {Record<string, unknown>} */ (/** @type {unknown} */ ((await teacherApi.content.get(id)).content));
    expect(dopo.title).toBe(nuovo);
    expect(dopo.content_type, "la modifica non cambia il tipo").toBe(tipo);
    await expect(voce, "la voce della sidepage mostra il titolo nuovo").toContainText(nuovo, { timeout: 15_000 });
    expect(await metadatiDi(teacherApi, id), "✎ e Salva del solo titolo non cambiano i metadati").toEqual(metadatiPrima);
    if (tipo === "mappa") {
        await expect(voce, "nemmeno dopo il salvataggio").toHaveAttribute("data-has-body-pt", "0");
        await expect(voce.locator(".fm-item-export")).toHaveCount(0);
    }
    if (tipo === "esercizio" || tipo === "verifica") {
        // Lancia se la risposta non è 2xx: prima del 19/9 era 404 no_contract.
        await teacherApi.content.contract(id);
    }
}

for (const [pagina, sezione] of SIDEPAGE) {
    for (const [modo, tipo] of Object.entries(MODI)) {
        test(`${sezione}: «${modo}» crea un contenuto ${tipo} e lo si modifica`, async ({ homeDocente, teacherApi, cleanup, env }, testInfo) => {
            const page = homeDocente.page;
            const titolo = titoloUnico(sezione, modo, testInfo);

            const pannello = await apriLaSidepage(homeDocente, env, pagina);
            await homeDocente.attivaModificaSezione(pannello);
            const finestra = await homeDocente.nuovoNellaSezione(pannello);

            // La finestra dà il fuoco al titolo 50 ms dopo l'apertura: si aspetta,
            // o quel fuoco può arrivare a metà del riempimento di un altro campo.
            await expect(finestra.locator('input[name="title"]')).toBeFocused();
            await finestra.locator(`input[name="doc_mode"][value="${modo}"]`).check();
            await finestra.locator('input[name="title"]').fill(titolo);
            await finestra.locator('input[name="topic"]').fill("9.9");

            if (modo === "link") {
                await finestra.locator('input[name="href"]').fill("https://example.org/e2e-dispensa.pdf");
            } else if (modo === "upload") {
                await finestra.locator('input[name="map_file"]').setInputFiles({
                    name: "e2e.drawio", mimeType: "application/xml", buffer: Buffer.from(DRAWIO),
                });
            } else if (modo === "drawio_native") {
                await finestra.locator(".fm-modal-drawio-open").click();
                const editor = page.frameLocator(".fm-drawio-overlay iframe");
                // L'editor è pronto quando ha ricevuto il diagramma vuoto: il
                // riquadro bianco del 15/9 non arrivava mai a questo punto, e
                // «Salva ed esci» si preme solo se il modale non gli sta sopra.
                await expect(editor.locator(".geDiagramContainer"), "l'editor drawio si apre e carica il diagramma").toBeVisible({ timeout: 60_000 });
                await editor.getByRole("button", { name: /salva ed esci|save & exit/i }).click();
                await expect(finestra.locator(".fm-modal-drawio-status")).toContainText("Mappa salvata");
            }

            const creazione = page.waitForResponse((r) => r.request().method() === "POST"
                && ["/api/teacher/content", "/api/maps"].includes(new URL(r.url()).pathname));
            await finestra.locator('button[type="submit"]').click();
            const risposta = await creazione;
            const corpo = await risposta.json().catch(() => ({}));
            expect(risposta.ok(), `creazione: ${risposta.status()} ${JSON.stringify(corpo)}`).toBe(true);
            const id = Number(corpo.id);
            expect(id, "la creazione restituisce l'id").toBeGreaterThan(0);
            cleanup.add("teacher", `contenuto ${id} (${sezione}/${modo})`, async () => {
                const r = await teacherApi.content.delete(id);
                if (!r.ok) throw new Error(`cancellazione di ${id}: ${r.status} ${r.text}`);
            });

            // Un contenuto creato da /api/teacher/content si apre da solo
            // («naviga il pannello al nuovo documento»): deve essere la pagina
            // del suo tipo, e il ✎ si preme dopo, non su una pagina che se ne va.
            if (new URL(risposta.url()).pathname === "/api/teacher/content") {
                await expect.poll(() => new URL(page.url()).pathname, { timeout: 15_000, message: "dopo la creazione si apre la pagina del contenuto" })
                    .toMatch(new RegExp(`^/studio/${percorsoDelTipo(tipo, sezione)}/`));
            }

            const riga = /** @type {Record<string, unknown>} */ (/** @type {unknown} */ ((await teacherApi.content.get(id)).content));
            expect(riga.content_type, "il tipo del contenuto").toBe(tipo);

            await voceModificabile({
                page, pannello, teacherApi, id, titolo, tipo, sezione,
                conFile: modo === "upload" || modo === "drawio_native",
            });
        });
    }

    test(`${sezione}: una verifica si mostra e si modifica come verifica`, async ({ homeDocente, contentFactory, teacherApi, env }, testInfo) => {
        const titolo = titoloUnico(sezione, "verifica", testInfo);
        // La finestra non ha un modo per le verifiche: nasce dall'API, ancorata
        // a questa sezione, con la cancellazione registrata dalla factory.
        const { id } = await contentFactory.exercise({
            contentType: "verifica", sectionKey: sezione, title: titolo, topic: "9.8", groups: 0, terna: env.terna,
        });
        const pannello = await apriLaSidepage(homeDocente, env, pagina);
        await homeDocente.attivaModificaSezione(pannello);
        await voceModificabile({
            page: homeDocente.page, pannello, teacherApi, id, titolo, tipo: "verifica", sezione, conFile: false,
        });
    });
}

/**
 * Crea via API un contenuto ancorato a una sezione, con la cancellazione
 * registrata subito.
 *
 * @param {{ teacherApi: import("../support/test").TeacherApi, cleanup: import("../support/test").CleanupRegistry,
 *           env: import("../support/test").PublicEnv, tipo: string, sezione: string, titolo: string,
 *           metadata: Record<string, unknown> }} argomenti
 * @returns {Promise<number>}
 */
async function contenutoDaApi({ teacherApi, cleanup, env, tipo, sezione, titolo, metadata }) {
    const { id } = await teacherApi.content.create({
        type: tipo, subject: env.terna.materia, indirizzo: env.terna.indirizzo, classe: env.terna.classe,
        section_key: sezione, topic: "9.7", title: titolo, visibility: "draft", metadata,
    });
    cleanup.add("teacher", `contenuto ${id} (${sezione}/${tipo})`, async () => {
        const r = await teacherApi.content.delete(id);
        if (!r.ok && r.status !== 404) throw new Error(`cancellazione di ${id}: ${r.status} ${r.text}`);
    });
    return id;
}

test("mappe: ✎ e Salva conservano href_hide, drawio_id e display, anche dopo aver ricaricato", async ({ homeDocente, teacherApi, cleanup, env }, testInfo) => {
    const titolo = titoloUnico("mappe", "chiavi", testInfo);
    const mappa = {
        href: "https://example.org/e2e-mappa.pdf",
        href_hide: "https://example.org/e2e-nascosto.pdf",
        drawio_id: "e2e-drawio",
        display: "hide",
    };
    const id = await contenutoDaApi({ teacherApi, cleanup, env, tipo: "mappa", sezione: "mappe", titolo, metadata: { mappa } });

    const pannello = await apriLaSidepage(homeDocente, env, "mappe");
    await homeDocente.attivaModificaSezione(pannello);
    await voceModificabile({ page: homeDocente.page, pannello, teacherApi, id, titolo, tipo: "mappa", sezione: "mappe", conFile: false });
    expect(await metadatiDi(teacherApi, id), "prima del 19/9: {href, display: show}").toEqual({ mappa });

    // Ricaricando, la voce la disegna il server: niente 📥 nemmeno lì.
    const dopo = await apriLaSidepage(homeDocente, env, "mappe");
    await expect(dopo.locator(`li[data-content-id="${id}"]`)).toHaveAttribute("data-has-body-pt", "0", { timeout: 30_000 });
});

for (const [pagina, sezione] of /** @type {ReadonlyArray<[SidepageKey, string]>} */ ([["verifiche", "verif"], ["besDsa", "bes"]])) {
    test(`${sezione}: un documento Personalizzabile con del testo ha il 📥, con il suo titolo nel nome`, async ({ homeDocente, teacherApi, cleanup, env }, testInfo) => {
        const titolo = titoloUnico(sezione, "zip", testInfo);
        const id = await contenutoDaApi({
            teacherApi, cleanup, env, tipo: "document", sezione, titolo,
            metadata: {
                layout: "custom",
                ...(sezione === "bes" ? { category: "bes" } : {}),
                body_pt: [
                    { _type: "sectionHeader", title: "Obiettivi", level: 2 },
                    { _type: "block", style: "normal", children: [{ _type: "span", text: "Testo scritto dal docente.", marks: [] }] },
                ],
            },
        });

        const pannello = await apriLaSidepage(homeDocente, env, pagina);
        const voce = pannello.locator(`li[data-content-id="${id}"]`);
        await expect(voce, "prima, in BES/DSA l'attributo non c'era mai").toHaveAttribute("data-has-body-pt", "1", { timeout: 30_000 });
        await homeDocente.attivaModificaSezione(pannello);
        if (!(await voce.locator(".fm-item-edit").isVisible())) {
            const blocco = pannello.locator("ul.fm-db-block", { has: homeDocente.page.locator(`li[data-content-id="${id}"]`) });
            await blocco.locator(".js-edit-section").click();
        }
        await expect(voce.getByRole("button", { name: `Scarica ZIP TeX «${titolo}` })).toBeVisible();
    });
}
