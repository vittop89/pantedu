/**
 * Controlli sui file dei workflow, che nessun parser locale fa (i primi due
 * qui sotto; gli altri sono descritti dove stanno).
 *
 * ── 1. Niente chiavi ripetute nello stesso passo ─────────────────────────
 *
 * Un passo con due `if:` è YAML valido per quasi tutti i parser — prendono
 * l'ultimo e vanno avanti — ma GitHub lo rifiuta e il workflow non parte
 * affatto. Il segno che è successo è sottile: nell'elenco dei giri il
 * workflow compare col nome del **file** (`.github/workflows/ci.yml`) invece
 * che col suo `name:`, perché GitHub non è riuscito a leggerlo.
 *
 * È successo l'8 settembre 2026, aggiungendo `if:` a dei passi che ne
 * avevano già uno: la modifica passava `yaml.safe_load` senza un lamento.
 *
 * ── 2. Le azioni di terzi fissate a un commit ────────────────────────────
 *
 * `uses: tizio/azione@v3` non è una versione: è un puntatore che il
 * proprietario può spostare quando vuole. Se quel repository viene
 * compromesso, al giro successivo gira codice diverso da quello che avevi
 * visto, senza che nulla qui sia cambiato.
 *
 * Fino a stasera la cosa era teorica: quel codice girava su una macchina
 * usa-e-getta di GitHub. Da quando i giri vanno su un runner in casa,
 * girerebbe **sul computer di chi sviluppa**, con i suoi file intorno. Il
 * calcolo del rischio cambia, e con lui la regola.
 *
 * Le `actions/*` restano su etichetta: sono di GitHub, cioè della stessa
 * piattaforma che esegue il workflow.
 */

import { readFileSync, readdirSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
// WORKFLOWS_DIR solo per provarlo su file finti.
const DIR = process.env.WORKFLOWS_DIR || join(RADICE, ".github", "workflows");

const COMMIT = /^[0-9a-f]{40}$/;
const problemi = [];
let azioniControllate = 0;
let passiControllati = 0;

for (const nome of readdirSync(DIR).filter((f) => f.endsWith(".yml") || f.endsWith(".yaml"))) {
    const righe = readFileSync(join(DIR, nome), "utf8").split("\n");

    // ── 1. Chiavi ripetute dentro un passo ──
    // Un passo comincia con `- ` a una certa indentazione; le sue proprietà
    // stanno due spazi più a destra. Si contano quelle che compaiono più di
    // una volta.
    let inizio = null;
    let base = null;
    let viste = new Map();

    const chiudiPasso = () => {
        if (inizio === null) return;
        for (const [chiave, occorrenze] of viste) {
            if (occorrenze.length > 1) {
                problemi.push(
                    `${nome}: il passo che comincia alla riga ${inizio} ha ${occorrenze.length} volte «${chiave}:» ` +
                        `(righe ${occorrenze.join(", ")}).\n` +
                        "    YAML lo accetta prendendo l'ultimo, GitHub rifiuta il workflow intero:\n" +
                        "    nell'elenco dei giri comparirebbe col nome del file invece che col suo `name`.\n" +
                        "    Se servono due condizioni, uniscile:  if: ${{ A && B }}",
                );
            }
        }
        inizio = null;
        viste = new Map();
    };

    righe.forEach((riga, i) => {
        const numero = i + 1;
        const apre = riga.match(/^(\s*)- /);
        if (apre) {
            chiudiPasso();
            inizio = numero;
            base = apre[1].length;
            passiControllati++;
            return;
        }
        if (inizio === null) return;

        const indent = riga.length - riga.trimStart().length;
        if (riga.trim() !== "" && indent <= base) {
            chiudiPasso();
            return;
        }
        // Solo le proprietà di primo livello del passo, non quelle dentro `with:`.
        if (indent !== base + 2) return;
        const chiave = riga.match(/^\s*([a-zA-Z][a-zA-Z0-9_-]*):/);
        if (!chiave) return;
        const k = chiave[1];
        if (!viste.has(k)) viste.set(k, []);
        viste.get(k).push(numero);
    });
    chiudiPasso();

    // ── 2. Azioni di terzi fissate a un commit ──
    righe.forEach((riga, i) => {
        const m = riga.match(/^\s*(?:-\s*)?uses:\s*([^\s@]+)@([^\s#]+)/);
        if (!m) return;
        const [, azione, riferimento] = m;
        if (azione.startsWith(".") || azione.startsWith("actions/")) return;

        azioniControllate++;
        if (!COMMIT.test(riferimento)) {
            problemi.push(
                `${nome}:${i + 1} — ${azione}@${riferimento} è fissata a un'etichetta.\n` +
                    "    Un'etichetta si può spostare: al giro dopo girerebbe altro codice,\n" +
                    "    e adesso girerebbe sul computer di chi sviluppa. Metti il commit:\n" +
                    `    gh api repos/${azione}/git/ref/tags/${riferimento} --jq .object.sha\n` +
                    `    poi:  uses: ${azione}@<commit>  # ${riferimento}`,
            );
        }
    });

    // ── Nomi di job col trattino dentro le espressioni ────────────────────
    //
    // `needs.axe-baseline.result` non è un errore di sintassi per GitHub: è
    // una **sottrazione** fra `needs.axe`, `baseline` e `result`. L'espressione
    // vale vuoto, il workflow gira lo stesso, e il valore che doveva decidere
    // qualcosa non decide niente. Con un trattino nel nome serve
    // `needs['axe-baseline'].result`.
    //
    // Trovato il 9 settembre 2026 scrivendo l'avviso per i giri programmati,
    // prima che partisse: sarebbe stato un allarme che non suona.
    righe.forEach((riga, i) => {
        if (riga.trim().startsWith("#")) return;
        for (const m of riga.matchAll(/\bneeds\.([A-Za-z0-9_]*-[A-Za-z0-9_-]+)\./g)) {
            problemi.push(
                `${nome}:${i + 1} — \`needs.${m[1]}.\` con un trattino nel nome del job.\n` +
                    "    GitHub la legge come una sottrazione: l'espressione vale vuoto e\n" +
                    "    non dà nessun errore. Scrivi invece:\n" +
                    `    needs['${m[1]}'].<campo>`,
            );
        }
    });
}

// ── 3. Niente porte fisse e niente /tmp nei job ──────────────────────────
//
// I runner di casa sono quattro nella stessa WSL: stessa rete e stessa /tmp.
// Il 14 settembre 2026 il controllo di accessibilità di una pull request ha
// interrogato il server della E2E di `main`, perché tutti e due volevano
// `127.0.0.1:8000`: verde senza aver provato il codice. Una porta la sceglie
// `tools/ci/server-php.sh` (o Docker con `127.0.0.1::PORTA`), i file stanno in
// RUNNER_TEMP. Contano solo le righe che non sono commenti; `APP_URL=` in un
// file di configurazione non apre porte, e resta libero.
for (const nome of readdirSync(DIR).filter((f) => f.endsWith(".yml") || f.endsWith(".yaml"))) {
    readFileSync(join(DIR, nome), "utf8").split("\n").forEach((riga, i) => {
        const testo = riga.trim();
        if (testo.startsWith("#") || testo.includes("APP_URL=")) return;
        const porta = testo.match(/(?:127\.0\.0\.1|localhost):(\d{2,5})\b/);
        if (porta) {
            problemi.push(
                `${nome}:${i + 1} — porta fissa ${porta[0]}.\n` +
                    "    Sui runner di casa un altro job può averla: si finisce a provare il suo server.\n" +
                    "    Usa  bash tools/ci/server-php.sh scegli / avvia  e  ${{ env.URL_SERVER }},\n" +
                    "    o per un container  -p 127.0.0.1::PORTA  e  docker port.",
            );
        }
        if (/(^|[\s"'=(])\/tmp\//.test(testo)) {
            problemi.push(
                `${nome}:${i + 1} — un percorso in /tmp.\n` +
                    "    /tmp è di tutti i runner: un altro job può leggerlo o sovrascriverlo. Usa $RUNNER_TEMP.",
            );
        }
    });
}

// ── 4. Un valore generato che entra nell'ambiente del giro va mascherato ──
//
// Ciò che si scrive in $GITHUB_ENV GitHub lo stampa in testa a ogni passo
// successivo, e lo maschera solo se viene da `secrets.*`. Il 15 settembre 2026
// il registro della E2E aveva in chiaro, diciotto volte a giro, le password
// generate per gli utenti di prova e il gettone delle metriche. Un passo che
// genera un valore (`openssl rand`, `/dev/urandom`) e scrive in $GITHUB_ENV
// deve dire `::add-mask::` prima.
//
// Conta solo ciò che finisce davvero lì: le righe che nominano $GITHUB_ENV e i
// gruppi `{ … } >> "$GITHUB_ENV"`. Un segreto generato dentro `.env` (un file,
// non l'ambiente del giro) non si stampa, e resta libero.
const GENERATO = /openssl\s+rand|\/dev\/urandom/;
for (const nome of readdirSync(DIR).filter((f) => f.endsWith(".yml") || f.endsWith(".yaml"))) {
    const righe = readFileSync(join(DIR, nome), "utf8").split("\n");
    let passo = null;
    const chiudi = () => {
        if (passo === null) return;
        const codice = passo.righe.filter((r) => !r.trim().startsWith("#"));
        const nellAmbiente = [];
        let gruppo = null;
        for (const r of codice) {
            if (/^\s*\{\s*$/.test(r)) {
                gruppo = [];
            } else if (gruppo !== null && /^\s*\}/.test(r)) {
                if (/GITHUB_ENV/.test(r)) nellAmbiente.push(...gruppo);
                gruppo = null;
            } else if (gruppo !== null) {
                gruppo.push(r);
            } else if (/GITHUB_ENV/.test(r)) {
                nellAmbiente.push(r);
            }
        }
        const scritto = nellAmbiente.join("\n");
        const generate = [...codice.join("\n").matchAll(/([A-Za-z_][A-Za-z0-9_]*)="?\$\((?:openssl\s+rand|[^)]*\/dev\/urandom)/g)].map((m) => m[1]);
        const diretto = GENERATO.test(scritto);
        const daVariabile = generate.some((v) => new RegExp(`\\$\\{?${v}\\b`).test(scritto));
        if (diretto || (daVariabile && !codice.join("\n").includes("::add-mask::"))) {
            problemi.push(
                `${nome}:${passo.inizio} — un valore generato entra in $GITHUB_ENV senza essere mascherato.\n` +
                    "    GitHub lo stamperebbe in chiaro in testa a ogni passo successivo. Prima di scriverlo:\n" +
                    '    valore="$(openssl rand -base64 24)"; echo "::add-mask::$valore"',
            );
        }
        passo = null;
    };
    righe.forEach((riga, i) => {
        const apre = riga.match(/^(\s*)- /);
        if (apre) {
            chiudi();
            passo = { inizio: i + 1, base: apre[1].length, righe: [riga] };
            return;
        }
        if (passo === null) return;
        const indent = riga.length - riga.trimStart().length;
        if (riga.trim() !== "" && indent <= passo.base) {
            chiudi();
            return;
        }
        passo.righe.push(riga);
    });
    chiudi();
}

// ── 5. Ogni guardia di `npm run ci` la lancia un workflow ────────────────
//
// `npm run ci` è l'elenco delle guardie del progetto, ma non lo esegue nessun
// workflow: ogni guardia gira in CI solo se un passo la nomina. Il 23/9/2026
// tre non le nominava nessuno — `legal:pdf`, `moduli:fascio` e
// `percorsi:dati` — e giravano solo sulla macchina di chi se ne ricordava
// (revisione architetturale del 23/9/2026, A-25). Una guardia aggiunta allo
// script e dimenticata nel workflow è verde per sempre senza aver guardato.
//
// Conta un `npm run <nome>` fuori dai commenti (di riga e in coda), in un
// workflow qualunque. PACKAGE_JSON solo per provarlo su file finti.
const PACCHETTO = process.env.PACKAGE_JSON || join(RADICE, "package.json");
const scriptCi = JSON.parse(readFileSync(PACCHETTO, "utf8")).scripts?.ci ?? "";
const guardieDelCi = [...scriptCi.matchAll(/npm run ([A-Za-z0-9:_-]+)/g)].map((m) => m[1]);
if (guardieDelCi.length === 0) {
    problemi.push(
        `${PACCHETTO}: lo script \`ci\` non lancia nessun \`npm run <nome>\`.\n` +
            "    Il controllo non avrebbe niente da confrontare: un elenco vuoto non è un elenco rispettato.",
    );
}
const lanciate = new Set();
for (const nome of readdirSync(DIR).filter((f) => f.endsWith(".yml") || f.endsWith(".yaml"))) {
    for (const riga of readFileSync(join(DIR, nome), "utf8").split("\n")) {
        const codice = riga.trim().startsWith("#") ? "" : riga.replace(/\s#.*$/, "");
        for (const m of codice.matchAll(/npm run ([A-Za-z0-9:_-]+)/g)) lanciate.add(m[1]);
    }
}
for (const guardia of guardieDelCi) {
    if (!lanciate.has(guardia)) {
        problemi.push(
            `package.json: \`npm run ${guardia}\` è nello script \`ci\` ma nessun workflow lo lancia.\n` +
                "    In CI non gira: è verde perché nessuno lo guarda. Aggiungi un passo, di solito nel\n" +
                "    lavoro «Front-end» di ci.yml, con  if: ${{ !cancelled() }}  come gli altri.",
        );
    }
}

// ── 6. Un controllo non ingoia il proprio fallimento ─────────────────────
//
// Il 23/9/2026 (revisione architetturale, A-27) PHPCS e Lighthouse avevano
// `continue-on-error: true`, e `a11y.yml` e `lighthouse.yml` scrivevano
// `npm run build || echo …` e `npm ci || npm install`: un build rotto
// diventava una riga nel registro, un `npm ci` fallito un'installazione
// diversa da quella del lockfile. Lighthouse sul runner di casa non riusciva
// nemmeno ad aprire Chrome, e il giro risultava verde lo stesso.
//
// Tre forme, fuori dai commenti:
//   a. `continue-on-error:` con un valore diverso da `false`, su un passo o
//      su un intero lavoro (`jobs.<id>.continue-on-error`: il lavoro può
//      fallire senza far fallire il giro). La chiave di un lavoro sta fuori
//      da ogni `- `, quindi questa forma si cerca su tutto il file;
//   b. un comando che installa, costruisce o prova (npm, npx, composer,
//      phpunit, phpstan, phpcs, playwright, vendor/bin/…) seguito da `||`,
//      se quello che segue non esce con un errore (`exit 1`, `false`);
//   c. `|| echo` fuori da una cattura `$( … )`, se il passo non gira solo
//      quando il lavoro è già rosso (`if:` con `failure()`): lì la
//      diagnostica che va avanti dopo una query fallita è il suo mestiere.
const INSTALLA_O_PROVA = /(^|[\s;&|(])(npm|npx|composer|phpunit|phpstan|phpcs|playwright|vendor\/bin\/[\w.-]+)\s/;
const ESCE_CON_ERRORE = /\bexit\s+("?\$\S+|[1-9]\d*)|(^|[\s;{])false\b/;
for (const nome of readdirSync(DIR).filter((f) => f.endsWith(".yml") || f.endsWith(".yaml"))) {
    const righe = readFileSync(join(DIR, nome), "utf8").split("\n");
    // a. Su tutto il file, non solo dentro i passi.
    righe.forEach((riga, i) => {
        if (riga.trim().startsWith("#")) return;
        const valore = riga.replace(/\s#.*$/, "").match(/^\s*(?:-\s+)?continue-on-error:\s*(.+?)\s*$/);
        if (valore && valore[1] !== "false") {
            problemi.push(
                `${nome}:${i + 1} — continue-on-error: ${valore[1]}.\n` +
                    "    Il passo o il lavoro può fallire e il giro resta verde: non è un controllo, è una riga nel registro.\n" +
                    "    Se il controllo dà falsi rossi si corregge la causa; se non deve bloccare, lo si dice\n" +
                    "    nella protezione del ramo (un contesto non obbligatorio), non qui.",
            );
        }
    });
    let passo = null;
    const chiudi = () => {
        if (passo === null) return;
        const soloSeRosso = passo.righe.some((r) => /^\s*(-\s+)?if:.*\bfailure\(\)/.test(r));
        // Righe di codice: niente commenti, e le continuazioni `\` unite.
        const logiche = [];
        let accumulo = null;
        for (const { testo, numero } of passo.righe.map((t, k) => ({ testo: t, numero: passo.inizio + k }))) {
            if (testo.trim().startsWith("#")) continue;
            const codice = testo.replace(/\s#.*$/, "");
            if (accumulo === null) accumulo = { testo: "", numero };
            accumulo.testo += ` ${codice.trim()}`;
            if (codice.trimEnd().endsWith("\\")) continue;
            logiche.push(accumulo);
            accumulo = null;
        }
        if (accumulo !== null) logiche.push(accumulo);

        for (const { testo, numero } of logiche) {
            const pezzi = testo.split("||");
            for (let k = 1; k < pezzi.length; k++) {
                if (INSTALLA_O_PROVA.test(` ${pezzi[k - 1]}`) && !ESCE_CON_ERRORE.test(pezzi[k])) {
                    problemi.push(
                        `${nome}:${numero} — «${pezzi[k - 1].trim()} ||${pezzi[k].trimEnd()}».\n` +
                            "    Un'installazione, un build o una prova che fallisce deve far fallire il passo.\n" +
                            "    Niente ripiego (`npm ci || npm install`) né «continuo lo stesso» (`|| echo`, `|| true`).",
                    );
                }
            }
            const fuoriDalleCatture = testo.replace(/\$\([^()]*\)/g, "");
            if (!soloSeRosso && /\|\|\s*echo\b/.test(fuoriDalleCatture)) {
                problemi.push(
                    `${nome}:${numero} — «|| echo» in un passo che non è solo diagnostica.\n` +
                        "    Il comando a sinistra fallisce, si stampa una riga e il passo resta verde.\n" +
                        "    Se il fallimento conta:  || { echo \"::error::…\"; exit 1; }.  Se non conta, il comando non serve.",
                );
            }
        }
        passo = null;
    };
    righe.forEach((riga, i) => {
        const apre = riga.match(/^(\s*)- /);
        if (apre) {
            chiudi();
            passo = { inizio: i + 1, base: apre[1].length, righe: [riga.replace(/^(\s*)- /, "$1  ")] };
            return;
        }
        if (passo === null) return;
        const indent = riga.length - riga.trimStart().length;
        if (riga.trim() !== "" && indent <= passo.base) {
            chiudi();
            return;
        }
        passo.righe.push(riga);
    });
    chiudi();
}

// ── 7. L'avviso guarda tutti i lavori e tutti i giri senza nessuno davanti ─
//
// Il 23/9/2026 (revisione architetturale, A-28) l'avviso di `compliance.yml`
// dipendeva solo da `reuse-lint`, quindi un `publiccode.yml` non valido faceva
// rosso il giro della domenica senza aprire la segnalazione; e quello di
// `e2e.yml` scattava solo sul giro della domenica, non su quello dopo ogni
// spinta su `main`, che è il giro che conta: il codice è già in produzione.
//
// In un workflow che chiama `avvisa-guasto.yml`:
//   a. il lavoro dell'avviso ha in `needs` tutti gli altri lavori;
//   b. se sono più di uno, l'esito li combina (`needs.*.result`);
//   c. il suo `if:` nomina ogni innesco senza nessuno davanti che il
//      workflow ha: `schedule` e `push`. Sulle pull request e a mano chi ha
//      lanciato il giro lo sta guardando.
// Lettura per righe, come il resto di questo file: i lavori sono le chiavi due
// spazi (o quattro) sotto `jobs:`, gli inneschi quelle sotto `on:`.
const SENZA_NESSUNO = ["schedule", "push"];
let avvisiControllati = 0;
/**
 * Le chiavi figlie di una chiave di primo livello (`jobs`, `on`), con la loro
 * riga e il loro blocco.
 * @param {string[]} righe
 * @param {string} chiave
 * @returns {{ nome: string, riga: number, corpo: string[] }[]}
 */
function figlieDi(righe, chiave) {
    const inizio = righe.findIndex((r) => r.startsWith(`${chiave}:`));
    if (inizio < 0) return [];
    const esito = [];
    let passo = null;
    for (let i = inizio + 1; i < righe.length; i++) {
        const r = righe[i];
        if (/^\S/.test(r) && !r.startsWith("#")) break;
        const m = r.match(/^(\s+)([A-Za-z_][\w-]*):/);
        if (m && !r.trim().startsWith("#")) {
            if (passo === null) passo = m[1].length;
            if (m[1].length === passo) {
                esito.push({ nome: m[2], riga: i + 1, corpo: [] });
                continue;
            }
        }
        if (esito.length > 0) esito[esito.length - 1].corpo.push(r);
    }
    return esito;
}
for (const nome of readdirSync(DIR).filter((f) => f.endsWith(".yml") || f.endsWith(".yaml"))) {
    const righe = readFileSync(join(DIR, nome), "utf8").split("\n");
    const figlie = (chiave) => figlieDi(righe, chiave);
    const lavori = figlie("jobs");
    const avvisi = lavori.filter((l) => l.corpo.some((r) => /uses:\s*\.\/\.github\/workflows\/avvisa-guasto\.yml/.test(r)));
    if (avvisi.length === 0) continue;
    const inneschi = figlie("on").map((f) => f.nome);
    for (const avviso of avvisi) {
        avvisiControllati++;
        const codice = avviso.corpo.filter((r) => !r.trim().startsWith("#")).join("\n");
        const bisogni = (codice.match(/^\s*needs:\s*\[?([^\]\n]*)\]?/m)?.[1] ?? "")
            .split(",").map((x) => x.trim()).filter(Boolean);
        const altri = lavori.filter((l) => !avvisi.includes(l)).map((l) => l.nome);
        const scoperti = altri.filter((l) => !bisogni.includes(l));
        if (scoperti.length > 0) {
            problemi.push(
                `${nome}:${avviso.riga} — l'avviso non guarda ${scoperti.map((l) => `«${l}»`).join(", ")}.\n` +
                    "    Se quel lavoro è rosso il giro è rosso, ma la segnalazione non si apre.\n" +
                    `    needs: [${altri.join(", ")}]  e un esito che li combina (needs.*.result).`,
            );
        }
        if (bisogni.length > 1 && !/needs\.\*\.result/.test(codice)) {
            problemi.push(
                `${nome}:${avviso.riga} — l'avviso dipende da ${bisogni.length} lavori ma l'esito non li combina.\n` +
                    "    Usa needs.*.result: rosso se uno è rosso, verde solo se lo sono tutti.",
            );
        }
        const condizione = codice.match(/^\s*if:\s*(.+)$/m)?.[1] ?? "";
        for (const evento of SENZA_NESSUNO.filter((e) => inneschi.includes(e))) {
            if (!new RegExp(`event_name\\s*==\\s*'${evento}'`).test(condizione)) {
                problemi.push(
                    `${nome}:${avviso.riga} — il workflow gira su «${evento}» ma l'avviso no.\n` +
                        "    Su quel giro nessuno sta guardando: un rosso arriva solo con la mail generica di GitHub.\n" +
                        `    Nell'if dell'avviso:  github.event_name == '${evento}'`,
                );
            }
        }
    }
}

// ── 8. Il Node lo sceglie `.nvmrc`, su ogni runner ───────────────────────
//
// Fino al 23/9/2026 setup-node si saltava sui runner di casa
// (`if: ${{ !vars.RUNNER }}`), e lì girava il Node che prepara-runner.sh aveva
// installato sulla macchina. Il passaggio di `.nvmrc` da 20 a 22 (D-5) avrebbe
// fatto rosso il lavoro obbligatorio «Front-end» finché qualcuno non avesse
// aggiornato a mano ogni macchina, e con la copia dello script del ramo, non
// quella di `main`. Con setup-node anche in casa la versione la decide il
// commit: setup-node la cerca nella cache degli strumenti del runner e la
// scarica solo la prima volta.
//
// Ogni lavoro che lancia `node`, `npm` o `npx` (fuori dai commenti e dai
// `name:`) ha un passo `actions/setup-node`:
//   a. senza `if:`, così gira su ogni runner;
//   b. con `node-version-file: ".nvmrc"` e senza `node-version:` scritta a mano.
const LANCIA_NODE = /(^|[\s;&|(])(node|npm|npx)\s/;
let lavoriConNode = 0;
for (const nome of readdirSync(DIR).filter((f) => f.endsWith(".yml") || f.endsWith(".yaml"))) {
    const righe = readFileSync(join(DIR, nome), "utf8").split("\n");
    for (const lavoro of figlieDi(righe, "jobs")) {
        const codice = lavoro.corpo.map((r) => (r.trim().startsWith("#") ? "" : r.replace(/\s#.*$/, "")));
        if (!codice.some((r) => !/^\s*(?:-\s+)?name:/.test(r) && LANCIA_NODE.test(` ${r} `))) continue;
        lavoriConNode++;
        const k = codice.findIndex((r) => /^\s*(?:-\s+)?uses:\s*actions\/setup-node@/.test(r));
        if (k < 0) {
            problemi.push(
                `${nome}:${lavoro.riga} — il lavoro «${lavoro.nome}» lancia node o npm senza un passo actions/setup-node.\n` +
                    "    Girerebbe il Node installato sul runner, qualunque sia. Aggiungi:\n" +
                    '    - uses: actions/setup-node@v4\n      with:\n          node-version-file: ".nvmrc"',
            );
            continue;
        }
        // Il passo che contiene `uses:`: dal suo `- ` fin dove l'indentazione torna indietro.
        let inizio = k;
        while (inizio > 0 && !/^\s*- /.test(codice[inizio])) inizio--;
        const base = codice[inizio].match(/^(\s*)- /)?.[1].length ?? 0;
        const passo = [codice[inizio].replace(/^(\s*)- /, "$1  ")];
        for (let i = inizio + 1; i < codice.length; i++) {
            const r = codice[i];
            if (r.trim() !== "" && r.length - r.trimStart().length <= base) break;
            passo.push(r);
        }
        const riga = lavoro.riga + 1 + inizio;
        if (passo.some((r) => new RegExp(`^\\s{${base + 2}}if:`).test(r))) {
            problemi.push(
                `${nome}:${riga} — il passo setup-node del lavoro «${lavoro.nome}» ha un \`if:\`.\n` +
                    "    Dove si salta gira il Node di sistema del runner, non quello di .nvmrc: era così sui\n" +
                    "    runner di casa fino al 23/9/2026. Per non usare la cache di npm in casa:\n" +
                    "    cache: ${{ !vars.RUNNER && 'npm' || '' }}",
            );
        }
        if (!passo.some((r) => /^\s*node-version-file:\s*["']?\.nvmrc["']?\s*$/.test(r)) || passo.some((r) => /^\s*node-version:/.test(r))) {
            problemi.push(
                `${nome}:${riga} — il passo setup-node del lavoro «${lavoro.nome}» non prende la versione da .nvmrc.\n` +
                    '    Usa  node-version-file: ".nvmrc"  e nessun  node-version:  scritto a mano.',
            );
        }
    }
}

if (problemi.length > 0) {
    console.error("Problemi nei file dei workflow:\n");
    for (const p of problemi) console.error("  ✗ " + p + "\n");
    console.error(`${problemi.length} ${problemi.length === 1 ? "problema" : "problemi"}.`);
    process.exit(1);
}

console.log(
    `✓ Workflow: ${passiControllati} passi senza chiavi ripetute, ` +
        `${azioniControllate} azioni di terzi fissate a un commit, nessuna porta fissa né /tmp, ` +
        "nessun valore generato in chiaro nell'ambiente del giro, " +
        `le ${guardieDelCi.length} guardie di \`npm run ci\` lanciate da un workflow, ` +
        "nessun controllo che ingoia il proprio fallimento, " +
        `${avvisiControllati} avvisi che guardano tutti i lavori e tutti i giri senza nessuno davanti, ` +
        `${lavoriConNode} lavori con il Node di .nvmrc su ogni runner.`,
);
