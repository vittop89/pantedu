// @ts-check
/**
 * Regressione visiva: le pagine non devono cambiare aspetto senza che qualcuno
 * lo abbia deciso.
 * Riscrittura di visual_regression_a11y.spec.js e
 * visual_regression_phase5.spec.js.
 *
 * Ogni pagina viene fotografata a tre larghezze e confrontata con l'immagine
 * attesa tenuta nel repo. Serve a fare pulizia nei fogli di stile senza paura:
 * se una regola tolta cambiava davvero qualcosa, il confronto lo dice.
 *
 * Alle pagine si aggiunge il caso del testo ingrandito al doppio, che è il
 * criterio WCAG 1.4.4, e quello della larghezza di 320 pixel, che è il 1.4.10:
 * lì non deve comparire una barra di scorrimento orizzontale.
 *
 * **Dove si confronta, e perché lì.** Le immagini attese vivono su una
 * piattaforma sola: Linux, quella dell'integrazione continua. `toMatchSnapshot`
 * appende il sistema al nome del file, quindi ogni sistema ne vuole di proprie,
 * e tenerne due serie voleva dire tenerne due allineate.
 *
 * Fino all'8 settembre 2026 la serie era quella di Windows e in CI il giro
 * passava `--ignore-snapshots`: cioè **in CI questa prova non confrontava
 * niente**, girava e basta. In locale confrontava, e quattro immagini su
 * cinquantasette fallivano — non per un cambiamento nei fogli di stile, ma
 * perché il database di sviluppo si era allontanato da com'era quando gli
 * scatti erano stati presi (la barra laterale mostra sezioni e terna che
 * arrivano da lì). Quattro rosse che non significavano niente, in una prova
 * che altrove non guardava. Voce 87 del debito.
 *
 * Adesso comanda Linux, per una ragione precisa: in CI il database è **seminato
 * da zero** (`tools/ci/seed_e2e_database.php`), quindi le pagine riservate sono
 * deterministiche. Sul database di sviluppo non lo sono e non possono esserlo.
 *
 * In locale il confronto si salta, dicendolo. Chi vuole farlo lo stesso —
 * per una pulizia dei fogli di stile, che è il mestiere di questa prova —
 * esporta `FM_E2E_VISUAL=1` e genera la propria serie con
 * `--update-snapshots`: sono file suoi, che non vanno versionati.
 *
 * Cosa cambia rispetto a prima: le pagine riservate non hanno più un accesso
 * loro con due variabili d'ambiente (e non si saltano più quando quelle
 * mancano): usano la sessione della suite, docente o amministratore secondo la
 * pagina. E delle attese a tempo non resta nulla.
 *
 * **Le quindici immagini che ritraevano un 404** (20 settembre 2026). Cinque
 * pagine — `privacy`, `cookie`, `profilo`, `modelli`, `fonti` — per tre
 * misure davano quindici file che erano in realtà tre: lo stesso «404 — Not
 * Found» fotografato a milleduecentottanta, settecentosessantotto e
 * trecentosessanta pixel. Le rotte chieste qui non esistevano: `/privacy` al
 * posto di `/privacy/informativa`, `/area_docente/...` con il trattino basso
 * dove le rotte vere hanno il trattino (`/area-docente/...`), e
 * `/cookie-policy`, che non è mai esistita.
 *
 * Il confronto passava: un 404 è identico a un altro 404. Il difetto non era
 * dunque nell'applicazione — le pagine vere rispondevano 200 — ma qui, e non
 * si sarebbe visto nemmeno rileggendo la spec con attenzione: un percorso
 * sbagliato è indistinguibile da uno giusto finché qualcuno non lo chiede al
 * router.
 *
 * Perciò adesso si passa da `apriPaginaVera`, che prima dello scatto pretende
 * un 200 e nessuna pagina d'errore in pagina. La sua prova nei due versi sta
 * in fondo a questo file, in un gruppo che **non** viene saltato fuori da
 * Linux: è l'unica parte di questa spec che misura qualcosa anche altrove.
 *
 * **Il sedicesimo 404, quello dentro un riquadro** (20 settembre 2026). La
 * guardia guarda il documento principale, e deve: la pagina sotto esame è
 * quella esterna. Restava perciò fuori il 404 che `osservazione-desktop` e
 * `osservazione-tablet` portavano **dentro** l'iframe di Grafana — pagina
 * esterna 200, pagina giusta, guardia muta e a ragione. Quel contenuto non è
 * confrontabile a pixel da nessuna parte (qui Grafana non c'è, in produzione
 * cambia ogni secondo), quindi adesso `preparaLoScatto` lo nasconde
 * tenendone il riquadro, e l'incorporamento ha una prova che lo misura:
 * `tests/e2e/admin/osservazione-e-il-riquadro-di-grafana.spec.js`. La
 * motivazione per esteso sta accanto alla riga che lo nasconde.
 */
const {
    test,
    expect,
    attendiAnimazioniFerme,
    apriPaginaVera,
    SELETTORE_PAGINA_DI_ERRORE,
} = require("../support/test");

/**
 * La piattaforma su cui le immagini attese sono generate e versionate.
 *
 * Il confronto a pixel ha senso solo dove c'è qualcosa con cui confrontare.
 * Altrove Playwright, non trovando l'immagine, la **creerebbe** e il test
 * passerebbe: un verde che non ha guardato niente. Meglio saltare e dirlo.
 */
const PIATTAFORMA_DELLE_IMMAGINI = "linux";
/**
 * Le immagini attese ritraggono il **database seminato** della CI
 * (`tools/ci/seed_e2e_database.php`), non un database qualunque: le pagine
 * mostrano contenuti, e contenuti diversi fanno immagini diverse.
 *
 * 21/9/2026 — fino a oggi la condizione guardava solo la piattaforma, e da
 * quando si sviluppa in WSL (10/9/2026) piattaforma ce n'è una sola: linux.
 * Sulla macchina di chi lavora le quarantatré prove giravano quindi contro il
 * database di sviluppo, ed erano rosse sempre — per il motivo che il commento
 * qui sopra dichiarava già, cioè nessuno. Un rosso che non misura niente
 * logora quanto un verde che non guarda: si impara a scorrerlo.
 */
const SU_DATI_SEMINATI = ["1", "true"].includes(String(process.env.CI ?? "").toLowerCase());
const FORZATO = process.env.FM_E2E_VISUAL === "1";

/**
 * Salta il gruppo che la chiama, dove non c'è niente con cui confrontare.
 *
 * Era una `test.skip` in testa al file, e valeva per tutto quello che il file
 * conteneva. Adesso la guardia ha una prova sua, che di immagini attese non ha
 * bisogno e deve girare ovunque: se il salto restasse in testa, la prova della
 * guardia sarebbe l'ennesimo verde che non guarda niente — proprio sul pezzo
 * che serve a impedirli.
 */
function soloDoveCiSonoLeImmagini() {
    test.skip(
        () => (process.platform !== PIATTAFORMA_DELLE_IMMAGINI || !SU_DATI_SEMINATI) && !FORZATO,
        `le immagini attese sono generate su ${PIATTAFORMA_DELLE_IMMAGINI} con il database seminato della CI, ` +
            "quindi deterministico; altrove il database di sviluppo cambia sotto i piedi e il confronto " +
            "direbbe solo quello. Per farlo comunque: FM_E2E_VISUAL=1, e la prima volta --update-snapshots.",
    );
}

const LARGHEZZE = [
    { nome: "desktop", larghezza: 1280, altezza: 720 },
    { nome: "tablet", larghezza: 768, altezza: 1024 },
    { nome: "mobile", larghezza: 360, altezza: 740 },
];

const PAGINE_PUBBLICHE = [
    { percorso: "/", nome: "home" },
    { percorso: "/login", nome: "accesso" },
    { percorso: "/register", nome: "iscrizione" },
    // `soloSopraLaPiega`: questa pagina e' un documento legale lungo, e a
    // trecentosessanta pixel di larghezza lo scatto intero e' alto
    // **trentaseimila** pixel. Un pixel di differenza in quell'altezza —
    // arrotondamento del layout, niente di piu' — e Playwright rifiuta il
    // confronto: non tollera immagini di dimensioni diverse, quindi
    // `maxDiffPixelRatio` non c'entra e non aiuta. E' successo l'8 settembre
    // 2026: «Expected an image 792px by 36479px, received 792px by 36480px»,
    // un test rosso su centoquaranta, verde nei tre giri successivi.
    //
    // Una prova che fallisce a caso non copre niente: si fotografa quello che
    // sta sopra la piega, come gia' si fa per le pagine riservate.
    { percorso: "/accessibility", nome: "dichiarazione-accessibilita", soloSopraLaPiega: true },
    // L'informativa è a `/privacy/informativa`: `/privacy` da solo non è una
    // rotta, è il prefisso di due (`informativa` e `your-data`). Fino al 20
    // settembre 2026 qui c'era il prefisso, e le tre immagini «privacy»
    // ritraevano un 404.
    //
    // Come la dichiarazione di accessibilità, è un documento legale lungo: a
    // trecentosessanta pixel di larghezza il documento è alto **29.439**
    // pixel (misurati il 20 settembre 2026; la dichiarazione, lì accanto, ne
    // fa 36.345). Un pixel di arrotondamento in quell'altezza e Playwright
    // rifiuta il confronto, come l'8 settembre. Quindi sopra la piega.
    { percorso: "/privacy/informativa", nome: "privacy", soloSopraLaPiega: true },
    // Qui c'era `/cookie-policy`, nome: `cookie`. Quella pagina non esiste e
    // non è mai esistita: `git log -S cookie-policy -- routes/` non trova
    // niente, e l'unica rotta dell'applicazione che nomini i cookie è il
    // redirect 301 da `/cookies_privacy-policy.html` all'informativa. Dal 15
    // settembre 2026 (#128) i cookie sono solo tecnici e non c'è nemmeno il
    // banner, quindi una pagina da fotografare non c'è. La voce è tolta
    // invece di essere corretta, e con lei le sue tre immagini.
];

const PAGINE_DEL_DOCENTE = [
    { percorso: "/teacher/dashboard", nome: "cruscotto-docente" },
    { percorso: "/teacher/templates", nome: "modelli-docente" },
    // Trattino, non trattino basso: `area_docente` è il nome della cartella
    // delle viste (`views/area_docente/profilo.php`), le rotte sono
    // `/area-docente/...`. Fino al 20 settembre 2026 queste tre righe
    // portavano il nome della cartella, e le loro nove immagini ritraevano un
    // 404.
    { percorso: "/area-docente/profilo", nome: "profilo" },
    { percorso: "/area-docente/templates", nome: "modelli" },
    { percorso: "/area-docente/fonti", nome: "fonti" },
];

/*
 * `/admin/waf/anomalies` non è in questo elenco: quella pagina mostra le
 * anomalie rilevate mentre la suite gira, e cambia a ogni esecuzione — anche
 * nascondendo le righe, i contatori e il grafico restano diversi. Confrontarla
 * a pixel vorrebbe dire riscrivere l'immagine attesa ogni volta.
 */
const PAGINE_DELL_AMMINISTRATORE = [
    { percorso: "/admin/dashboard", nome: "cruscotto-admin" },
    { percorso: "/admin/templates", nome: "modelli-admin" },
    { percorso: "/admin/monitoring", nome: "osservazione" },
    { percorso: "/admin/waf/dashboard", nome: "waf-cruscotto" },
    { percorso: "/admin/logs", nome: "registri" },
    { percorso: "/admin/backup", nome: "copie" },
    { percorso: "/admin/crypto-status", nome: "stato-cifratura" },
];

/*
 * IL RIQUADRO DI GRAFANA DI `/admin/monitoring` — si nasconde il contenuto,
 * non la cornice. (20 settembre 2026.)
 *
 * PERCHÉ. Quel contenuto non è confrontabile a pixel in nessun ambiente, e
 * non per un difetto che si possa correggere:
 *
 *   - qui e in CI non c'è. `/grafana/` non è una rotta dell'applicazione —
 *     `routes/web.php` non la nomina — è una `location` dell'nginx di
 *     produzione, che fa `auth_request` verso `/auth/grafana-gate` e poi
 *     passa a Grafana su 127.0.0.1:3000. Senza quell'nginx la richiesta
 *     arriva al router PHP, che non la conosce e risponde 404. Misurato:
 *     `php tools/dev/render_page.php --route=/grafana/` → «404 — Not Found»;
 *   - dove c'è, è un cruscotto vivo. I numeri cambiano a ogni secondo: una
 *     immagine attesa sarebbe sbagliata appena presa.
 *
 * Così due immagini su tre — `osservazione-desktop` e `osservazione-tablet` —
 * spendevano la maggior parte della loro altezza a ritrarre la pagina
 * d'errore dell'applicazione. E la ritraevano **a caso**: dentro un iframe
 * l'iniezione qui sotto non arriva (`addStyleTag` tocca solo il documento
 * principale), quindi la dissolvenza `fm-card-in` — opacità da 0 a 1 in 0,25
 * secondi — continuava a girare. Misurato sulle immagini versionate: nella
 * desktop il 404 è a piena opacità (rgb(199,49,73) su bianco), nella tablet a
 * circa metà strada (rgb(222,163,178) su rgb(246,248,252)). Lo stesso errore,
 * colto in due istanti diversi della stessa animazione. La terza, la mobile,
 * non lo mostrava affatto: a 360×740 il riquadro sta sotto la piega, e con
 * `loading="lazy"` non veniva nemmeno caricato.
 *
 * `visibility: hidden` e non `display: none`: la cornice tiene il suo posto
 * (`.fm-monitoring-frame` è alta `100vh - 280px`, minimo 600), quindi la
 * geometria della pagina resta sotto confronto — che è quel che questa prova
 * sa davvero misurare.
 *
 * E QUEL CHE C'ERA DENTRO. Un riquadro nascosto non protegge più il suo
 * contenuto. Qui però il contenuto non era protetto nemmeno prima: era un 404
 * confrontato con un 404, lo stesso verde cieco delle quindici immagini di
 * cui sopra. Quel che dell'incorporamento *si può* proteggere — che l'iframe
 * ci sia e sia uno solo, dove punti, con quali permessi di sandbox, e che la
 * CSP gli conceda `frame-ancestors 'self'` negandolo a tutto il resto — ha
 * adesso una prova che lo misura invece di ritrarlo:
 * `tests/e2e/admin/osservazione-e-il-riquadro-di-grafana.spec.js`. Chi togliesse
 * la riga qui sotto guardi prima là: senza quella prova, nascondere non è una
 * cura, è il verde cieco spostato di un metro.
 */

/**
 * Ferma le animazioni e nasconde quel che cambia a ogni caricamento: orari,
 * elenchi di contenuti veri, nomi e indirizzi di posta. Senza, il confronto
 * fallirebbe ogni volta, e le immagini nel repo porterebbero dati di persone.
 * @param {import("@playwright/test").Page} pagina
 */
async function preparaLoScatto(pagina) {
    await pagina.addStyleTag({
        content: `
            *, *::before, *::after {
                animation-duration: 0s !important;
                animation-delay: 0s !important;
                transition-duration: 0s !important;
                caret-color: transparent !important;
            }
            [data-timestamp], time { visibility: hidden !important; }
            .fm-db-block li,
            .fm-db-block a,
            .fm-newcat-host,
            ul.fm-db-block,
            #fm-sp-mappe ul, #fm-sp-lab ul, #fm-sp-eser ul,
            #fm-sp-verif ul, #fm-sp-bes ul, #fm-sp-risdoc ul,
            .fm-an-table tr td:not(:first-child),
            [data-content-id], [data-template-id],
            .fm-pi-card, .fm-tpl-card, .fm-vd-block,
            .fm-search-result, .fm-ex-result,
            .fm-waf-block-row, .fm-waf-anomaly-row,
            .fm-log-line, .fm-pool-share-row {
                visibility: hidden !important;
            }
            .fm-session-user, .fm-session-banner strong,
            .fm-area-docente-page h1 + p,
            [data-username], [data-user-email] {
                color: transparent !important;
                text-shadow: 0 0 6px #888 !important;
            }
            /* Il riquadro di Grafana: si nasconde il contenuto, non la
               cornice. Il perché è nel commento sopra la funzione. */
            .fm-monitoring-frame iframe { visibility: hidden !important; }
        `,
    });
    await attendiAnimazioniFerme(pagina);
}

/**
 * Apre la pagina alla larghezza indicata e la confronta con l'immagine attesa.
 *
 * Delle pagine riservate si fotografa solo quel che sta sopra la piega: sotto
 * ci sono gli elenchi dei contenuti del docente, che questa stessa suite crea
 * e cancella di continuo — l'altezza della pagina cambierebbe a ogni giro e il
 * confronto fallirebbe sempre.
 *
 * @param {import("@playwright/test").Page} pagina
 * @param {{ percorso: string, nome: string }} destinazione
 * @param {{ nome: string, larghezza: number, altezza: number }} misura
 * @param {{ interaPagina?: boolean }} opzioni
 */
async function confronta(pagina, destinazione, misura, opzioni = {}) {
    await pagina.setViewportSize({ width: misura.larghezza, height: misura.altezza });
    // Non `goto` diretto: `apriPaginaVera` pretende un 200 e nessuna pagina
    // d'errore. Senza, si fotografa quel che arriva — e un 404 confrontato con
    // un 404 è verde.
    await apriPaginaVera(pagina, destinazione.percorso);
    await preparaLoScatto(pagina);
    expect(await pagina.screenshot({ fullPage: opzioni.interaPagina ?? true })).toMatchSnapshot(
        `${destinazione.nome}-${misura.nome}.png`,
        { maxDiffPixelRatio: 0.02 },
    );
}

test.describe("Qualità — aspetto delle pagine pubbliche", () => {
    soloDoveCiSonoLeImmagini();
    for (const destinazione of PAGINE_PUBBLICHE) {
        for (const misura of LARGHEZZE) {
            test(`la ${destinazione.nome} è com'era, a ${misura.nome}`, async ({ page }) => {
                await confronta(page, destinazione, misura, {
                    interaPagina: !destinazione.soloSopraLaPiega,
                });
            });
        }
    }
});

test.describe("Qualità — aspetto delle pagine del docente", () => {
    soloDoveCiSonoLeImmagini();
    for (const destinazione of PAGINE_DEL_DOCENTE) {
        for (const misura of LARGHEZZE) {
            test(`la pagina «${destinazione.nome}» è com'era, a ${misura.nome}`, async ({ teacherPage }) => {
                await confronta(teacherPage, destinazione, misura, { interaPagina: false });
            });
        }
    }
});

test.describe("Qualità — aspetto delle pagine dell'amministratore", () => {
    soloDoveCiSonoLeImmagini();
    for (const destinazione of PAGINE_DELL_AMMINISTRATORE) {
        for (const misura of LARGHEZZE) {
            test(`la pagina «${destinazione.nome}» è com'era, a ${misura.nome}`, async ({ adminPage }) => {
                await confronta(adminPage, destinazione, misura, { interaPagina: false });
            });
        }
    }
});

test.describe("Qualità — testo ingrandito e larghezza minima", () => {
    soloDoveCiSonoLeImmagini();
    const CON_INGRANDIMENTO = [
        { percorso: "/", nome: "home" },
        { percorso: "/login", nome: "accesso" },
        // Il marcatore va ripetuto: questo è un elenco a sé, e senza si
        // tornerebbe allo scatto alto diciottomila pixel.
        { percorso: "/accessibility", nome: "dichiarazione-accessibilita", soloSopraLaPiega: true },
    ];

    for (const destinazione of CON_INGRANDIMENTO) {
        test(`la ${destinazione.nome} regge il testo ingrandito al doppio`, async ({ page }) => {
            // Criterio WCAG 1.4.4: portando il testo al 200% la pagina deve
            // restare leggibile, non spezzarsi.
            await page.addInitScript(() => {
                // Lo script parte prima che il documento esista: la prima
                // chiamata trova `documentElement` a null, e va rifatta quando
                // il documento c'è.
                const ingrandisci = () => {
                    document.documentElement?.style.setProperty("font-size", "200%");
                };
                ingrandisci();
                document.addEventListener("DOMContentLoaded", ingrandisci);
            });
            await confronta(page, destinazione, { nome: "testo-doppio", larghezza: 1280, altezza: 720 }, {
                // Stessa ragione di sopra: al 200% la dichiarazione di
                // accessibilita' e' alta diciottomila pixel, e un pixel di
                // arrotondamento basta a far rifiutare il confronto.
                interaPagina: !destinazione.soloSopraLaPiega,
            });
        });
    }

    test("a carattere doppio l'etichetta della barra laterale si legge tutta", async ({ page }) => {
        // Il confronto a pixel non basta a vedere una parola tagliata: una
        // scritta di sei lettere sta sotto la tolleranza del 2%. Qui si
        // misura. «CHIUDI» aveva una larghezza fissa di 68 pixel e al 200%
        // se ne leggeva «CHI» (voce 65 del debito).
        await page.addInitScript(() => {
            const ingrandisci = () => {
                document.documentElement?.style.setProperty("font-size", "200%");
            };
            ingrandisci();
            document.addEventListener("DOMContentLoaded", ingrandisci);
        });
        await page.goto("/", { waitUntil: "domcontentloaded" });
        await attendiAnimazioniFerme(page);

        const etichetta = page.locator(".fm-sb-close");
        await expect(etichetta, "l'etichetta è in pagina").toBeVisible();
        const misure = await etichetta.evaluate((el) => ({
            testo: (el.textContent ?? "").trim(),
            visibile: Math.round(el.getBoundingClientRect().width),
            necessaria: el.scrollWidth,
        }));
        expect(
            misure.visibile,
            `«${misure.testo}» ha bisogno di ${misure.necessaria} pixel e ne ha ${misure.visibile}`,
        ).toBeGreaterThanOrEqual(misure.necessaria - 1);
    });

    test("a 320 pixel non compare una barra di scorrimento orizzontale", async ({ page }) => {
        // Criterio WCAG 1.4.10: a quella larghezza si deve poter leggere
        // scorrendo solo in verticale.
        await page.setViewportSize({ width: 320, height: 568 });
        await page.goto("/", { waitUntil: "domcontentloaded" });
        await attendiAnimazioniFerme(page);

        const misure = await page.evaluate(() => ({
            larghezzaDelDocumento: document.documentElement.scrollWidth,
            larghezzaDellaFinestra: window.innerWidth,
        }));
        expect(
            misure.larghezzaDelDocumento,
            `il documento è largo ${misure.larghezzaDelDocumento} contro i ${misure.larghezzaDellaFinestra} della finestra`,
        ).toBeLessThanOrEqual(misure.larghezzaDellaFinestra + 1);
    });
});

/*
 * La guardia che impedisce di rifare il 20 settembre 2026, e la sua prova.
 *
 * Questo gruppo non chiama `soloDoveCiSonoLeImmagini()`: non confronta
 * immagini, quindi gira su qualunque piattaforma. È voluto. Una guardia
 * provata solo dove non si guarda mai sarebbe esattamente il difetto che
 * dovrebbe chiudere.
 *
 * Si prova nei due versi, perché un verso solo non distingue una guardia da
 * una funzione che risponde sempre allo stesso modo: una che solleva sempre
 * passerebbe il caso «scatta», una che non solleva mai passerebbe il caso
 * «tace». Servono tutti e due.
 */
test.describe("Qualità — la guardia delle pagine fotografate", () => {
    test("scatta su una rotta che non esiste", async ({ page }) => {
        // Un percorso inventato ma innocuo: lettere e trattini, niente che il
        // WAF possa scambiare per un tentativo (risponderebbe 403, che la
        // guardia rifiuterebbe comunque, ma per la ragione sbagliata).
        const inventata = "/questa-pagina-non-esiste-davvero";

        const errore = await apriPaginaVera(page, inventata).then(
            () => null,
            (e) => e,
        );

        expect(errore, `«${inventata}» doveva far sollevare la guardia, e non l'ha fatto`).not.toBeNull();
        expect(errore.message).toContain("non è una pagina vera");
        // Il codice vero finisce nel messaggio, non solo «qualcosa non va»:
        // è l'informazione che per dodici giorni non ha avuto dove comparire.
        expect(errore.message).toContain("404");
    });

    test("tace su una pagina vera", async ({ page }) => {
        const risposta = await apriPaginaVera(page, "/login");
        expect(risposta.status(), "la pagina di accesso risponde 200").toBe(200);
        await expect(
            page.locator(SELETTORE_PAGINA_DI_ERRORE),
            "e non porta il titolo delle pagine d'errore",
        ).toHaveCount(0);
    });
});
