// @ts-check
/**
 * Global setup Playwright (2026-09-05).
 *
 * Molte spec puntavano a URL di studio scritte a mano (/studio/esercizio/ar/2s/MAT/1,
 * /studio/verifica/sc/2s/FIS, ...) che nel DB locale possono essere vuote:
 * fallivano per i dati, non per il codice. Qui tools/dev/e2e_urls.php chiede al
 * DB le terne con piu' contenuti per il docente di test e le espone come variabili
 * d'ambiente; le spec le leggono con `process.env.FM_E2E_ESER_URL || "<url storica>"`
 * (le spec riscritte passano da tests/e2e/support/env.ts).
 * Se lo script non parte (DB giu', PHP assente) le spec usano le URL storiche.
 *
 * La cache delle sessioni (tests/e2e/.auth) NON viene svuotata: sopravvive ai
 * giri ed e' validata a ogni uso con /auth/user-info (helpers.js e
 * support/auth/login.ts). Fino al 2026-09-06 qui si cancellava un percorso
 * che non esisteva piu'.
 */
const { execFileSync } = require("child_process");
const fs = require("fs");
const path = require("path");

const MAP = {
    eser_list_url:  "FM_E2E_ESER_LIST_URL",
    eser_url:       "FM_E2E_ESER_URL",
    eser_ids_url:   "FM_E2E_ESER_IDS_URL",
    verif_list_url: "FM_E2E_VERIF_LIST_URL",
    verif_url:      "FM_E2E_VERIF_URL",
    mappa_list_url: "FM_E2E_MAPPA_LIST_URL",
    mappa_url:      "FM_E2E_MAPPA_URL",
    // 2026-09-05 — verifica con tabelle RM ed esercizio con verifica correlata
    verif_rm_list_url:  "FM_E2E_VERIF_RM_LIST_URL",
    verif_rm_url:       "FM_E2E_VERIF_RM_URL",
    verif_rm_topic:     "FM_E2E_VERIF_RM_TOPIC",
    eser_related_url:   "FM_E2E_ESER_RELATED_URL",
    eser_related_title: "FM_E2E_ESER_RELATED_TITLE",
    // coppia esercizio↔verifica e mappa condivisibili nel pool (niente blocco copyright)
    share_eser_id:      "FM_E2E_SHARE_ESER_ID",
    share_verif_id:     "FM_E2E_SHARE_VERIF_ID",
    share_title:        "FM_E2E_SHARE_TITLE",
    share_topic:        "FM_E2E_SHARE_TOPIC",
    share_ind:          "FM_E2E_SHARE_IND",
    share_cls:          "FM_E2E_SHARE_CLS",
    share_subject:      "FM_E2E_SHARE_SUBJECT",
    share_mappa_id:     "FM_E2E_SHARE_MAPPA_ID",
    // ADR-037 — la scuola in cui le pagine sono state scoperte: la fixture del
    // docente ci riporta la sessione a ogni prova.
    scuola_id:          "FM_E2E_SCUOLA_ID",
    share_mappa_title:  "FM_E2E_SHARE_MAPPA_TITLE",
};

/** @param {unknown} e */
const firstLine = (e) => String(e instanceof Error ? e.message : e).split("\n")[0];

const RADICE = path.join(__dirname, "..", "..");

/**
 * Il file più recente sotto `dir`, con la sua data.
 * @param {string} dir
 * @param {(nome: string) => boolean} [escludi]
 * @returns {{ file: string, quando: number } | null}
 */
function piuRecente(dir, escludi = () => false) {
    /** @type {{ file: string, quando: number } | null} */
    let vincitore = null;
    /** @param {string} corrente */
    const scendi = (corrente) => {
        for (const voce of fs.readdirSync(corrente, { withFileTypes: true })) {
            const percorso = path.join(corrente, voce.name);
            if (escludi(percorso)) continue;
            if (voce.isDirectory()) { scendi(percorso); continue; }
            const quando = fs.statSync(percorso).mtimeMs;
            if (!vincitore || quando > vincitore.quando) vincitore = { file: percorso, quando };
        }
    };
    try { scendi(dir); } catch { return null; }
    return vincitore;
}

/**
 * Ferma il giro se il pacchetto servito è più vecchio dei sorgenti.
 *
 * Il 7 settembre 2026 lo era di quattro commit, e la suite è girata verde
 * contro JavaScript vecchio: un difetto del componente dei documenti si è
 * visto solo ricostruendo. `public/build/` e `css/main.bundle.css` sono
 * generati e non versionati, quindi niente li tiene allineati da sé.
 * Voce 62 del registro del debito.
 */
function verificaIlPacchetto() {
    // In CI la suite gira contro l'immagine del rilascio (14/9/2026): il
    // pacchetto lo costruisce l'immagine, e che sia quella del commit delle
    // prove lo verifica il workflow con /version. Qui non c'è niente di locale
    // da confrontare, e lo si dice invece di saltare in silenzio.
    if (process.env.IMMAGINE_E2E) {
        console.log(`[e2e global-setup] pacchetto: dentro l'immagine ${process.env.IMMAGINE_E2E} (commit verificato dal workflow)`);
        return;
    }
    const controlli = [
        {
            cosa: "JavaScript",
            sorgenti: piuRecente(path.join(RADICE, "js")),
            costruito: path.join(RADICE, "public", "build", "manifest.json"),
            comando: "npm run build",
        },
        {
            cosa: "fogli di stile",
            sorgenti: piuRecente(path.join(RADICE, "css"), (p) => p.endsWith("main.bundle.css")),
            costruito: path.join(RADICE, "css", "main.bundle.css"),
            comando: "php tools/build-css-bundle.php",
        },
    ];

    const fermi = [];
    for (const { cosa, sorgenti, costruito, comando } of controlli) {
        if (!sorgenti || !fs.existsSync(costruito)) continue;   // niente pacchetto: l'app serve i sorgenti
        const quandoCostruito = fs.statSync(costruito).mtimeMs;
        if (sorgenti.quando > quandoCostruito) {
            const ritardo = Math.round((sorgenti.quando - quandoCostruito) / 60000);
            fermi.push(`  - ${cosa}: ${path.relative(RADICE, sorgenti.file)} è più recente di ${path.relative(RADICE, costruito)} di ${ritardo} minuti → ${comando}`);
        }
    }

    if (fermi.length) {
        throw new Error(
            "[e2e global-setup] il pacchetto servito è più vecchio dei sorgenti: la suite verificherebbe codice"
            + " che non è quello del repository.\n" + fermi.join("\n")
            + "\nRicostruisci e rilancia.",
        );
    }
}

module.exports = async function globalSetup() {
    verificaIlPacchetto();

    const user = process.env.FM_E2E_USER || "docente.uno";

    // Termini di servizio accettati per gli utenti di test: senza, il gate
    // risponde 302 /tos-acceptance a ogni richiesta (HTML dove si aspetta JSON).
    try {
        const admin = process.env.FM_E2E_ADMIN_USERNAME || "admin";
        const out = execFileSync("php", ["-d", "extension=pdo_sqlite", path.join(__dirname, "..", "..", "tools", "dev", "e2e_prepare_users.php"), user, "docente.due", admin], {
            encoding: "utf8",
            timeout: 60_000,
            stdio: ["ignore", "pipe", "pipe"],
        });
        console.log(`[e2e global-setup] utenti: ${out.trim()}`);
    } catch (e) {
        console.warn(`[e2e global-setup] preparazione utenti fallita (${firstLine(e)})`);
    }
    /** @type {Record<string, string>} */
    let json;
    try {
        const out = execFileSync("php", ["-d", "extension=pdo_sqlite", path.join(__dirname, "..", "..", "tools", "dev", "e2e_urls.php"), user], {
            encoding: "utf8",
            timeout: 60_000,
            stdio: ["ignore", "pipe", "pipe"],
        });
        json = JSON.parse(out);
    } catch (e) {
        console.warn(`[e2e global-setup] URL con contenuti non scoperte (${firstLine(e)}): le spec usano le URL storiche`);
        return;
    }
    const set = [];
    for (const [key, env] of Object.entries(MAP)) {
        const value = json[key];
        if (value !== undefined && value !== "" && !process.env[env]) {
            process.env[env] = String(value);
            set.push(`${env}=${value}`);
        }
    }
    console.log(`[e2e global-setup] docente ${json.user}: ${set.length ? set.join("  ") : "nessuna URL trovata"}`);

    // La coppia esercizio/verifica della catena di condivisione deve avere fonte
    // «personale»: nel dump locale i contratti sono vuoti (non classificati) e la
    // condivisione sarebbe bloccata dal copyright. Vedi tools/dev/e2e_prepare_content.php.
    const shareIds = [process.env.FM_E2E_SHARE_ESER_ID, process.env.FM_E2E_SHARE_VERIF_ID].filter(Boolean);
    if (shareIds.length) {
        try {
            const out = execFileSync("php", ["-d", "extension=pdo_sqlite", path.join(__dirname, "..", "..", "tools", "dev", "e2e_prepare_content.php"), user, shareIds.join(",")], {
                encoding: "utf8",
                timeout: 60_000,
                stdio: ["ignore", "pipe", "pipe"],
            });
            console.log(`[e2e global-setup] contenuti condivisibili: ${out.trim()}`);
        } catch (e) {
            console.warn(`[e2e global-setup] preparazione contenuti fallita (${firstLine(e)})`);
        }
    }
};
