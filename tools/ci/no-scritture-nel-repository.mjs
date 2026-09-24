#!/usr/bin/env node
/**
 * Guardia — in `app/` le cartelle dei DATI non si costruiscono dalla radice
 * del CODICE.
 *
 * Il difetto che l'ha fatta nascere (misurato il 20 settembre 2026).
 * Dall'8 settembre l'applicazione gira in un container, e `docker/Dockerfile`
 * lo dice a chiare lettere: «L'immagine è immutabile: tutto ciò che si scrive
 * sta sotto PANTEDU_DATA_PATH». Trentasette punti di `app/`, però,
 * costruivano il percorso da `dirname(__DIR__, N)` — la radice del
 * repository, che dentro il container è l'immagine. Quello che ci si scriveva
 * finiva nello strato scrivibile di overlayfs e spariva al rilascio
 * successivo: nessun errore, nessun avviso, nessuna traccia.
 *
 * Perché non se n'era accorto nessuno: la scrittura **riesce**. `mkdir` e
 * `file_put_contents` rispondono `true`, l'interfaccia dice «salvato», e il
 * file c'è davvero — finché non si rilascia. È il «verde che non ha guardato»
 * nella sua forma peggiore, perché il guasto arriva giorni dopo la causa e in
 * un'altra parte del sistema. La faccia gemella è la lettura: chi cerca nella
 * radice del codice un file che sta nei dati non trova niente e tira avanti
 * in silenzio, con un catalogo vuoto o un `contract: null`.
 *
 * COME DISTINGUE. Non guarda se la riga scrive: seguire un valore attraverso
 * funzioni e oggetti è un'analisi di flusso che in uno script non si fa bene,
 * e una guardia che sbaglia la si spegne entro la settimana. Guarda invece il
 * **primo segmento del percorso**, che è un fatto locale e si controlla a
 * occhio:
 *
 *   `storage/`, `log/`, `logs/`, `temp/`, `verifiche/`, `tex_pdf/`
 *       → cartelle dei DATI d'istanza. Dalla radice del codice non ci si
 *         arriva: né per scrivere, né per leggere.
 *   `views/`, `css/`, `js/`, `public/`, `docs/`, `schemas/`, `database/`,
 *   `tools/`, `vendor/`, `routes/`, `img/`, `.github/`
 *       → CODICE e asset versionati, che viaggiano dentro l'immagine.
 *         Leggerli da lì è giusto e non viene segnalato.
 *
 * La forma corretta non viene segnalata per costruzione: avvolgere la radice
 * in `PercorsiDati::base(dirname(__DIR__, N))` toglie la concatenazione
 * diretta fra la radice e il pezzo di percorso, che è ciò che questa guardia
 * cerca. Stesso effetto per il ripiego documentato
 * `Config::get('app.paths.storage'|'logs'|'data_base', <radice>)`, dove la
 * radice è solo il valore di scorta e il primo argomento è già un percorso
 * dei dati.
 *
 * LE ECCEZIONI E IL LORO TETTO. Restano alcuni alberi versionati che stanno
 * sotto `storage/` per ragioni storiche — `storage/templates/risdoc/` (115
 * file in git) — e che l'immagine porta con sé. Quelle
 * letture si DICHIARANO in `LETTURE_DICHIARATE`, una per file, con il motivo
 * e **con quanti punti** ci si aspetta di trovare. Il conto è esatto: se in
 * un file dichiarato ne compare uno nuovo, la guardia parla. E il totale non
 * può superare `TETTO`, che è il numero di oggi: da qui si può solo scendere.
 * Un elenco di eccezioni che nessuno pota è il posto dove il problema torna a
 * nascondersi.
 *
 * Uscita: 0 se ogni percorso dei dati nasce dalla cartella dei dati, 1 se no.
 */
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join, relative, sep } from "node:path";
import { fileURLToPath } from "node:url";

const RADICE = join(fileURLToPath(new URL(".", import.meta.url)), "..", "..");

/** Dove si guarda. Il codice dell'applicazione, e basta. */
const CARTELLA = "app";

/**
 * Per provare la guardia: la cartella da esaminare al posto di `app/`.
 *
 * Serve a `tests/js-unit/percorsi-guardia.test.js`, che la misura nei due
 * versi su file finti. Con una cartella di prova le dichiarazioni e il tetto
 * non si controllano: parlano dei file veri del repository, e su un albero
 * finto direbbero solo che non ci sono.
 */
const CARTELLA_DI_PROVA = process.env.PERCORSI_DIR ?? "";
const DA_ESAMINARE = CARTELLA_DI_PROVA !== "" ? CARTELLA_DI_PROVA : join(RADICE, CARTELLA);
const RADICE_DEI_NOMI = CARTELLA_DI_PROVA !== "" ? CARTELLA_DI_PROVA : RADICE;

/** I primi segmenti che denotano dati d'istanza, non codice. */
const SEGMENTI_DEI_DATI = new Set([
    "storage", "log", "logs", "temp", "verifiche", "tex_pdf",
]);

/**
 * Le letture dichiarate: alberi versionati che vivono sotto un segmento dei
 * dati per ragioni storiche e che l'immagine porta con sé.
 *
 * `motivo` deve dire **perché quel percorso non è una scrittura**; «serve
 * così» non è un motivo. `punti` è quanti riscontri ci si aspetta in quel
 * file: il conto è esatto, così un percorso nuovo in un file già dichiarato
 * non passa inosservato.
 */
const LETTURE_DICHIARATE = {
    "app/Controllers/ContentExportController.php": {
        punti: 3,
        motivo:
            "legge `storage/templates/risdoc` — immagini e `texCommon` — per "
            + "infilare i loghi e i frammenti nello ZIP. Lo ZIP, che si "
            + "scrive, sta nei dati.",
    },
    "app/Controllers/Risdoc/ExportController.php": {
        punti: 4,
        motivo:
            "legge i modelli risdoc versionati (`images`, `texCommon`, lo "
            + "schema del documento). Lo ZIP temporaneo, che si scrive, sta "
            + "nei dati.",
    },
    "app/Controllers/Risdoc/TeacherTexCommonController.php": {
        punti: 3,
        motivo:
            "legge `storage/templates/risdoc/texCommon`, versionato. Gli "
            + "scostamenti del docente non stanno su disco: sono righe di "
            + "`risdoc_teacher_overrides`.",
    },
    "app/Controllers/Risdoc/TemplateController.php": {
        punti: 7,
        motivo:
            "legge i modelli risdoc versionati, gli schemi dei documenti e le "
            + "immagini condivise dall'immagine. Gli override del docente e "
            + "quelli istituzionali, che si SCRIVONO, passano tutti da "
            + "`radiceOverride()` e stanno nei dati. I due punti dei sorgenti "
            + "opzioni — la rotta `/risdoc/{path}` e il catalogo del "
            + "selettore — sono passati in cascata il 20/9/2026: da quel "
            + "giorno l'amministratore li salva nei dati.",
    },
    "app/Support/CriticalCss.php": {
        punti: 1,
        motivo:
            "compone il percorso del bundle CSS a partire dal manifest di "
            + "Vite: `'/../..' . $main` non ha un primo segmento letterale, ma "
            + "`$main` viene dal manifest e punta sempre dentro `public/`. "
            + "Asset del codice, sola lettura.",
    },
    "app/Controllers/Risdoc/TexFilesController.php": {
        punti: 1,
        motivo: "legge `storage/templates/risdoc/images`, loghi versionati.",
    },
    "app/Controllers/VerificaSyncController.php": {
        punti: 1,
        motivo:
            "mette nel pacchetto `storage/templates/risdoc/texCommon`, "
            + "versionato. I modelli delle verifiche e le librerie drawio, che "
            + "stanno nei dati, li chiede a chi di dovere.",
    },
    "app/Services/Risdoc/TemplateResolver.php": {
        punti: 1,
        motivo:
            "risolve i modelli risdoc dalla base versionata; la cascata degli "
            + "override la fa sul database.",
    },
};

/**
 * Quanti punti dichiarati in tutto. Può solo scendere.
 *
 * 26 → 22 il 20/9/2026: i quattro punti dei sorgenti opzioni risdoc (due in
 * `PtToTex`, due in `TemplateController`) sono passati in cascata, perché da
 * quel giorno l'amministratore li salva nei dati e la sola immagine non basta
 * più a leggerli.
 *
 * 22 → 21 il 23/9/2026: `GitHubSyncService` non cerca più
 * `storage/ca-bundle/cacert.pem` nella radice del codice; il bundle delle CA
 * lo trova `App\Support\BundleCa` (revisione architetturale 2026-09, A-42).
 */
const TETTO = 21;

/** Le chiavi di `app.paths` che indicano il CODICE. */
const CHIAVI_DI_CODICE = "base|legacy|public|app|views|routes";
/** Le chiavi che indicano i DATI: lì il ripiego `dirname()` è documentato. */
const RIPIEGO_DEI_DATI = /Config::get\(\s*['"]app\.paths\.(?:data_base|storage|logs)['"]\s*,/;

/**
 * Il nome della classe può arrivare qualificato: `\App\Core\Config::get(…)`.
 *
 * Fino al 20/9/2026 `ASSEGNA_CONFIG` ammetteva solo `Config::get`, e la forma
 * qualificata le passava sotto il naso: la variabile non entrava fra le radici
 * tracciate e la concatenazione della riga dopo non veniva esaminata.
 * `app/Controllers/Admin/WafAdminController.php` usava esattamente quella
 * forma per costruire un percorso dei dati dalla radice del codice.
 */
const FORSE_QUALIFICATO = "(?:\\\\?\\w+\\\\)*";

/** `dirname(__DIR__, N)` — la radice del codice, in tutte le sue scritture. */
const RADICE_DIRNAME = "(?:\\\\)?dirname\\(\\s*__DIR__\\s*,\\s*\\d+\\s*\\)";

/** `dirname(__DIR__, N) . '/pezzo'` → cattura il pezzo. */
const DIRNAME_CONCATENATO = new RegExp(`${RADICE_DIRNAME}\\s*\\.\\s*['"]([^'"]*)`, "g");
/** `__DIR__ . '/../../pezzo'` → la stessa risalita, scritta a mano. */
const DIR_RISALITA = /__DIR__\s*\.\s*['"]((?:\.\.?\/|\/)[^'"]*)/g;
/** `Config::get('app.paths.base') . '/pezzo'`. */
const CONFIG_CONCATENATO = new RegExp(
    `Config::get\\(\\s*['"]app\\.paths\\.(?:${CHIAVI_DI_CODICE})['"][^)]*\\)\\s*\\.\\s*['"]([^'"]*)`,
    "g",
);
/**
 * `sprintf('%s/pezzo', dirname(__DIR__, N))` — la stessa concatenazione, con
 * il percorso nel formato e la radice fra gli argomenti. Non è una forma
 * esotica: la si sceglie appena i pezzi diventano due o tre.
 */
const SPRINTF_DIRNAME = new RegExp(
    `sprintf\\(\\s*['"]%s([^'"]*)['"]\\s*,\\s*${RADICE_DIRNAME}`,
    "g",
);
/** La radice pubblica del server: nel container è dentro l'immagine. */
const DOCUMENT_ROOT = /\$_SERVER\s*\[\s*['"]DOCUMENT_ROOT['"]\s*\]/g;

/** `$x = dirname(__DIR__, N);` — la radice del codice dentro una variabile. */
const ASSEGNA_DIRNAME = /(\$\w+)\s*=\s*(?:\(string\)\s*)?(?:\\)?dirname\(\s*__DIR__\s*,\s*\d+\s*\)\s*;/g;
/** `$x = Config::get('app.paths.base', ...);` — idem, anche qualificato. */
const ASSEGNA_CONFIG = new RegExp(
    `(\\$\\w+)\\s*=\\s*(?:\\(string\\))?\\s*${FORSE_QUALIFICATO}`
    + `Config::get\\(\\s*['"]app\\.paths\\.(?:${CHIAVI_DI_CODICE})['"]`,
    "g",
);

function percorri(dir) {
    const fuori = [];
    let voci;
    try {
        voci = readdirSync(dir);
    } catch {
        return fuori;
    }
    for (const nome of voci.sort()) {
        if (nome === "node_modules" || nome === "vendor") continue;
        const p = join(dir, nome);
        if (statSync(p).isDirectory()) fuori.push(...percorri(p));
        else if (nome.endsWith(".php")) fuori.push(p);
    }
    return fuori;
}

/**
 * Toglie i commenti lasciando le righe al loro posto.
 *
 * Indispensabile: i commenti che spiegano questo stesso difetto, nei file
 * corretti, citano per iscritto `dirname(__DIR__, 2) . '/storage/…'`. Senza
 * questo passaggio la guardia segnalerebbe le proprie spiegazioni — e una
 * guardia che grida al lupo sulla documentazione viene spenta subito.
 */
function senzaCommenti(testo) {
    const senzaBlocchi = testo.replace(/\/\*[\s\S]*?\*\//g, (b) => b.replace(/[^\n]/g, " "));
    return senzaBlocchi.split("\n").map((riga) => {
        const i = riga.search(/(^|[^:])\/\//);
        return i === -1 ? riga : riga.slice(0, i);
    });
}

/**
 * Il primo segmento vero di un pezzo di percorso: si buttano via le risalite
 * (`../`), il punto e le barre. `null` se il pezzo non ne ha uno letterale
 * (per esempio `'/' . $variabile`): in quel caso non si può dire, e non
 * potersi pronunciare vale quanto un riscontro.
 */
function primoSegmento(pezzo) {
    for (const seg of pezzo.replace(/\\/g, "/").split("/")) {
        if (seg === "" || seg === "." || seg === "..") continue;
        return seg.replace(/\{.*$/, "");
    }
    return null;
}

/** I nomi di variabile che, in questo file, contengono la radice del codice. */
function radiciInVariabili(testo) {
    const nomi = new Set();
    for (const re of [ASSEGNA_DIRNAME, ASSEGNA_CONFIG]) {
        re.lastIndex = 0;
        for (const m of testo.matchAll(re)) nomi.add(m[1]);
    }
    return nomi;
}

/**
 * L'istruzione che contiene la riga `i`: si risale finché la riga di sopra non
 * ha chiuso qualcosa (`;`, `{`, `}`) o non è vuota.
 *
 * Serve al ripiego documentato, che si può spezzare come qualunque altra
 * istruzione:
 *
 *     $dir = Config::get('app.paths.logs',
 *         dirname(__DIR__, 2) . '/storage/logs');
 *
 * Guardando la sola riga del riscontro, `Config::get` non si vedrebbe e la
 * guardia segnalerebbe la forma che essa stessa documenta.
 */
function finestraIstruzione(righe, i) {
    let inizio = i;
    while (inizio > 0) {
        const precedente = righe[inizio - 1].trim();
        if (precedente === "" || /[;{}]$/.test(precedente)) break;
        inizio -= 1;
    }
    return righe.slice(inizio, i + 1).join("\n");
}

const reperti = new Map(); // file → [{riga, testo, forma}]
for (const file of percorri(DA_ESAMINARE)) {
    const rel = relative(RADICE_DEI_NOMI, file).split(sep).join("/");
    const righe = senzaCommenti(readFileSync(file, "utf8"));
    const variabili = radiciInVariabili(righe.join("\n"));
    const alternativaVar = variabili.size === 0
        ? null
        : `(?:${[...variabili].map((v) => `\\${v}`).join("|")})`;
    const varConcatenate = alternativaVar === null
        ? null
        : new RegExp(`${alternativaVar}\\s*\\.\\s*['"]([^'"]*)`, "g");
    const varSprintf = alternativaVar === null
        ? null
        : new RegExp(`sprintf\\(\\s*['"]%s([^'"]*)['"]\\s*,\\s*${alternativaVar}`, "g");

    // Si guarda il file INTERO, non una riga alla volta: la concatenazione
    // spezzata a metà — che il linter chiede appena il percorso è lungo —
    // sfuggiva a una guardia che leggeva le righe separate (misurato il
    // 20/9/2026: la sonda a capo usciva con 0, la stessa su una riga con 1).
    const testo = righe.join("\n");
    const inizi = [];
    let scorrimento = 0;
    for (const riga of righe) {
        inizi.push(scorrimento);
        scorrimento += riga.length + 1;
    }
    /** Il numero di riga (da 1) dove comincia il riscontro. */
    const rigaDa = (posizione) => {
        let n = 1;
        while (n < inizi.length && inizi[n] <= posizione) n += 1;
        return n;
    };

    const trovati = [];
    const guarda = (re, etichetta) => {
        re.lastIndex = 0;
        for (const m of testo.matchAll(re)) {
            const n = rigaDa(m.index);
            // Il ripiego documentato: il primo argomento è già un percorso dei
            // dati, la radice è solo il valore di scorta per lo sviluppo locale.
            if (RIPIEGO_DEI_DATI.test(finestraIstruzione(righe, n - 1))) continue;
            const pezzo = m[1] ?? null;
            const seg = pezzo === null ? null : primoSegmento(pezzo);
            if (pezzo !== null && seg !== null && !SEGMENTI_DEI_DATI.has(seg)) continue;
            trovati.push({
                riga: n,
                testo: righe[n - 1].trim(),
                forma: seg === null ? `${etichetta} (segmento non letterale)` : `${etichetta} → ${seg}/`,
            });
        }
    };
    guarda(DIRNAME_CONCATENATO, "dirname(__DIR__, N)");
    guarda(DIR_RISALITA, "__DIR__ . '/../…'");
    guarda(CONFIG_CONCATENATO, "app.paths.<codice>");
    guarda(SPRINTF_DIRNAME, "sprintf('%s/…', dirname(__DIR__, N))");
    if (varConcatenate) guarda(varConcatenate, "$radiceDelCodice");
    if (varSprintf) guarda(varSprintf, "sprintf('%s/…', $radiceDelCodice)");
    // `DOCUMENT_ROOT` non ha un pezzo di percorso da guardare: la radice
    // pubblica del server è già dentro l'immagine, comunque la si usi.
    DOCUMENT_ROOT.lastIndex = 0;
    for (const m of testo.matchAll(DOCUMENT_ROOT)) {
        const n = rigaDa(m.index);
        trovati.push({ riga: n, testo: righe[n - 1].trim(), forma: "$_SERVER['DOCUMENT_ROOT']" });
    }

    if (trovati.length === 0) continue;
    // Un riscontro per FORMA, non per riga: due percorsi sulla stessa riga
    // sono due punti. Contarli per riga faceva passare inosservato un
    // percorso nuovo infilato accanto a uno già dichiarato — misurato il
    // 20/9/2026 provando la guardia, che sulla controprova (j) taceva.
    trovati.sort((a, b) => a.riga - b.riga);
    reperti.set(rel, trovati);
}

let errori = 0;
// Su una cartella di prova le dichiarazioni non si controllano: parlano dei
// file veri, e qui direbbero solo che non ci sono.
const dichiarate = CARTELLA_DI_PROVA !== "" ? [] : Object.entries(LETTURE_DICHIARATE);
const puntiDichiarati = dichiarate.reduce((n, [, v]) => n + v.punti, 0);

// 1. Il tetto non si alza.
if (puntiDichiarati > TETTO) {
    console.error(
        `\nLETTURE_DICHIARATE conta ${puntiDichiarati} punti, il tetto è ${TETTO}.\n`
        + "Il tetto può solo scendere: se serve dichiararne uno nuovo è perché\n"
        + "un percorso nuovo nasce dalla radice del codice, e quello va\n"
        + "corretto, non dichiarato.\n",
    );
    errori += 1;
}

// 2. Nessuna voce morta, e nessun conto sbagliato: una dichiarazione che non
//    combacia più è il posto dove il problema torna a nascondersi.
for (const [f, atteso] of dichiarate) {
    const trovati = reperti.get(f)?.length ?? 0;
    if (trovati === atteso.punti) continue;
    errori += 1;
    if (trovati === 0) {
        console.error(
            `\n${f} è dichiarato in LETTURE_DICHIARATE ma non ha più nessun\n`
            + "percorso dalla radice del codice: togliere la voce.\n",
        );
        continue;
    }
    console.error(
        `\n${f}: dichiarati ${atteso.punti} punti, trovati ${trovati}.\n`,
    );
    for (const r of reperti.get(f)) console.error(`      ${r.riga}: ${r.testo}`);
    console.error(
        trovati > atteso.punti
            ? "\nCe n'è uno nuovo: se è una scrittura va corretta, se è una\n"
              + "lettura legittima si alza il conto — ma il TETTO resta.\n"
            : `\nUno è sparito: portare \`punti\` a ${trovati} (e abbassare il TETTO).\n`,
    );
}

// 3. I percorsi non dichiarati.
const nuovi = [...reperti.keys()]
    .filter((f) => CARTELLA_DI_PROVA !== "" || !(f in LETTURE_DICHIARATE))
    .sort();
if (nuovi.length > 0) {
    console.error(
        `\n${nuovi.length} file di app/ costruiscono un percorso dei DATI `
        + "dalla radice del repository.\n",
    );
    console.error(
        "Dall'8/9/2026 l'applicazione gira in un container e quella radice è\n"
        + "l'IMMAGINE, che è immutabile: una scrittura riesce, non dà errore, e\n"
        + "sparisce al rilascio successivo insieme allo strato scrivibile; una\n"
        + "lettura non trova niente e tira avanti in silenzio.\n",
    );
    for (const f of nuovi) {
        console.error(`  ${f}`);
        for (const r of reperti.get(f)) {
            console.error(`      ${r.riga}: ${r.testo}`);
            console.error(`          forma: ${r.forma}`);
        }
    }
    console.error(
        "\nSe è una SCRITTURA (o la lettura di ciò che si è scritto): portarla\n"
        + "sotto la cartella dei dati con\n"
        + "    \\App\\Support\\PercorsiDati::base(dirname(__DIR__, N)) . '/…'\n"
        + "lasciando a chi chiama la possibilità di passare una radice esplicita\n"
        + "(il modello è app/Services/Tikz/TikzRenderService.php).\n\n"
        + "Se l'albero ha una base versionata NELL'IMMAGINE più gli scostamenti\n"
        + "dell'istanza, la lettura va in cascata: PercorsiDati::inCascata().\n\n"
        + "Se è una lettura di un albero versionato e basta: dichiararla in\n"
        + "LETTURE_DICHIARATE, in questo file, scrivendo perché.\n",
    );
    errori += 1;
}

if (errori > 0) process.exit(1);

console.log(
    `percorsi: in ${CARTELLA}/ nessuna cartella dei dati nasce dalla radice del `
    + `repository. ${dichiarate.length} file dichiarati, ${puntiDichiarati} punti `
    + `(tetto ${TETTO}).`,
);
process.exit(0);
