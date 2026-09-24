#!/usr/bin/env node
/**
 * Cancello su semgrep: legge il risultato JSON e decide se il giro passa.
 *
 * Perché esiste. Fino all'8 settembre 2026 il passo «Semgrep OWASP + regole
 * del progetto» era verde e **non scansionava niente**: l'azione
 * `returntocorp/semgrep-action` passava a semgrep solo `.semgrep.yml` (i
 * pacchetti OWASP li buttava via), semgrep rifiutava il file per una regola
 * malformata, e l'azione restituiva comunque «riuscito». Mesi di verde su un
 * controllo che non misurava niente.
 *
 * Questo script è scritto per rendere quel guasto impossibile da ripetere.
 * Fallisce se:
 *
 *   1. semgrep ha segnalato errori nel caricamento delle regole — una regola
 *      che non si compila è un buco, non un dettaglio;
 *   2. la scansione ha toccato troppo pochi file — se il montaggio va storto
 *      o `.semgrepignore` esclude mezzo repository, «zero risultati» sembra
 *      un successo ed è invece una misura mancata;
 *   3. una regola supera il suo tetto nel censimento;
 *   4. compare una regola che nel censimento non c'è — nessuna categoria
 *      nuova entra in silenzio;
 *   5. una regola sta **sotto** il tetto: vuol dire che qualcosa è stato
 *      sistemato, e il tetto va abbassato subito, altrimenti fra un mese quel
 *      margine si riempie di nuovo senza che nessuno se ne accorga.
 *
 * Il punto 5 è la differenza fra un censimento e una lista di scuse. È la
 * stessa regola del censimento a11y (tests/e2e/qualita/dipendenza-a11y.json).
 *
 * Fallisce anche se un file che semgrep legge solo in parte non è
 * nell'elenco del censimento (`file_non_letti.elenco`), e stampa sempre
 * l'elenco intero: vedi «1-ter» più sotto.
 *
 * Uso:
 *   node tools/ci/cancello-semgrep.mjs <risultati.json>
 *   node tools/ci/cancello-semgrep.mjs <risultati.json> --scrivi-censimento
 */

import { readFileSync, writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const QUI = dirname(fileURLToPath(import.meta.url));
// SEMGREP_CENSIMENTO solo per provarlo su un censimento finto
// (tests/js-unit/cancello-semgrep-elenco.test.js).
const CENSIMENTO = process.env.SEMGREP_CENSIMENTO || join(QUI, "semgrep-censimento.json");

/** Sotto questa soglia di file esaminati, la scansione non è credibile. */
const MINIMO_FILE_ESAMINATI = 1000;

const COME_SI_AGGIORNA =
    "I numeri si rigenerano con `node tools/ci/cancello-semgrep.mjs "
    + "<risultati.json> --scrivi-censimento`, ma il motivo lo scrive una persona. "
    + "Un tetto può solo scendere: se un numero cala, abbassalo nello stesso "
    + "commit che lo ha fatto calare.";

const [, , percorsoRisultati, ...resto] = process.argv;
const scriviCensimento = resto.includes("--scrivi-censimento");

if (!percorsoRisultati) {
    console.error("uso: node tools/ci/cancello-semgrep.mjs <risultati.json>");
    process.exit(2);
}

let dati;
try {
    dati = JSON.parse(readFileSync(percorsoRisultati, "utf8"));
} catch (e) {
    console.error(`Non riesco a leggere ${percorsoRisultati}: ${e.message}`);
    console.error("Se semgrep non ha prodotto il file, il giro è da rifare: un");
    console.error("risultato assente non è un risultato pulito.");
    process.exit(2);
}

const problemi = [];

/** Numeri scesi sotto il loro tetto: si segnalano, non fanno fallire. */
const cali = [];

// ── 1. Le regole si sono caricate tutte? ─────────────────────────────────
//
// È il guasto dell'8 settembre: `.semgrep.yml` conteneva una regola con
// `pattern-not-inside` messo dove lo schema non lo ammette, semgrep usciva
// con «Invalid rule schema», e l'azione restituiva «riuscito» lo stesso.
//
// Un rifiuto di regola arriva con `level: "error"`. Gli avvisi di analisi sui
// singoli file arrivano con `level: "warn"` e si contano più sotto: sono due
// problemi diversi e vanno trattati diversamente.
const errori = Array.isArray(dati.errors) ? dati.errors : [];
const gravi = errori.filter((e) => e.level === "error");
if (gravi.length > 0) {
    problemi.push(
        `${gravi.length} errori gravi di semgrep. Una regola che non si compila non `
        + "protegge niente, e semgrep va avanti lo stesso:\n"
        + gravi.map((e) => `      - ${e.message ?? JSON.stringify(e)}`).join("\n"),
    );
}

// ── 1-bis. Quanto codice non è riuscito a leggere? ───────────────────────
//
// L'analizzatore PHP di semgrep non digerisce tutta la sintassi moderna: su
// una proprietà promossa `readonly` si ferma e legge il file a metà. Il
// risultato è che le regole OWASP su quel file **non girano**, e nel resoconto
// la cosa appare come «nessun riscontro».
//
// È la stessa forma di guasto del verde che non misurava niente, in piccolo:
// una scansione che si restringe assomiglia a una scansione pulita. Quindi il
// numero di file che semgrep non riesce a leggere ha un tetto come tutto il
// resto, e se cresce il giro è rosso.
// Il confronto col tetto avviene dopo aver letto il censimento (più sotto).
const fileNonLetti = [
    ...new Set(errori.filter((e) => e.level === "warn" && e.path).map((e) => e.path)),
].sort();

// ── 2. Ha davvero guardato il repository? ────────────────────────────────
const esaminati = Array.isArray(dati?.paths?.scanned) ? dati.paths.scanned.length : 0;
if (esaminati < MINIMO_FILE_ESAMINATI) {
    problemi.push(
        `esaminati solo ${esaminati} file (soglia: ${MINIMO_FILE_ESAMINATI}). `
        + "Con così pochi file «nessun risultato» non vuol dire «tutto a posto», "
        + "vuol dire che la scansione non è arrivata al codice: controlla il "
        + "montaggio della cartella e .semgrepignore.",
    );
}

// ── 3-5. Il censimento ───────────────────────────────────────────────────
const conteggio = new Map();
for (const r of dati.results ?? []) {
    const id = String(r.check_id ?? "").split(".").pop();
    conteggio.set(id, (conteggio.get(id) ?? 0) + 1);
}

if (scriviCensimento) {
    const esistente = leggiCensimento();
    const soffitti = {};
    for (const [id, quanti] of [...conteggio].sort((a, b) => b[1] - a[1])) {
        soffitti[id] = {
            quanti,
            motivo: esistente?.soffitti?.[id]?.motivo ?? "DA SCRIVERE",
        };
    }
    // 2026-09-23 — riscriveva solo `aggiornato`, `come_si_aggiorna` e
    // `soffitti`: `perche_esiste` e `file_non_letti` (tetto e motivo) andavano
    // persi. Ora restano, e l'elenco dei file letti in parte si riscrive con
    // quello di questo risultato; il tetto no, lo decide una persona.
    writeFileSync(
        CENSIMENTO,
        `${JSON.stringify(
            {
                ...esistente,
                aggiornato: new Date().toISOString().slice(0, 10),
                come_si_aggiorna: esistente?.come_si_aggiorna ?? COME_SI_AGGIORNA,
                file_non_letti: {
                    quanti: esistente?.file_non_letti?.quanti ?? fileNonLetti.length,
                    motivo: esistente?.file_non_letti?.motivo ?? "DA SCRIVERE",
                    elenco: fileNonLetti,
                },
                soffitti,
            },
            null,
            4,
        )}\n`,
        "utf8",
    );
    console.log(`Censimento riscritto: ${CENSIMENTO}`);
    console.log("Scrivi il motivo per ogni voce nuova prima di consegnare.");
    process.exit(0);
}

const censimento = leggiCensimento();
if (!censimento) {
    console.error(`Censimento mancante: ${CENSIMENTO}`);
    console.error("Generalo con --scrivi-censimento, poi scrivi i motivi.");
    process.exit(2);
}

const soffitti = censimento.soffitti ?? {};

const tettoNonLetti = Number(censimento.file_non_letti?.quanti ?? 0);

// ── 1-ter. Quali file, non solo quanti ───────────────────────────────────
//
// 2026-09-23 — il tetto contava solo il numero (84 su 85 alla revisione
// architetturale di quel giorno, A-61), e il censimento non conservava
// l'elenco: un file tornato leggibile e uno nuovo letto a metà si
// compensavano, e il secondo entrava senza che nessuno lo vedesse. Adesso
// l'elenco si stampa sempre per intero, e un file che non c'è fa fallire il
// giro anche con il totale sotto il tetto. Un file dell'elenco che ora si
// legge per intero (o che non c'è più) si stampa e non fa fallire: la misura
// oscilla in quel verso da sola. Il 23/9, su nove scansioni dello stesso
// albero, quattro volte un file dell'elenco è stato letto per intero, e mai un
// file fuori dall'elenco letto in parte. Si toglie dall'elenco quando si
// sistema davvero.
console.log(`File che semgrep legge solo in parte: ${fileNonLetti.length} (tetto ${tettoNonLetti}).`);
for (const p of fileNonLetti) console.log(`  - ${p}`);
const elencoNonLetti = censimento.file_non_letti?.elenco;
if (!Array.isArray(elencoNonLetti)) {
    problemi.push(
        "il censimento non ha l'elenco dei file letti in parte (`file_non_letti.elenco`): "
        + "senza, un file sistemato e uno nuovo letto a metà si compensano. Si genera con "
        + "`--scrivi-censimento` da un risultato della CI.",
    );
} else {
    const nuovi = fileNonLetti.filter((p) => !elencoNonLetti.includes(p));
    const tornati = elencoNonLetti.filter((p) => !fileNonLetti.includes(p));
    if (nuovi.length > 0) {
        problemi.push(
            `${nuovi.length} file letti solo in parte che l'elenco del censimento non ha. `
            + "Su quei file le regole non girano, anche se il totale resta sotto il tetto:\n"
            + nuovi.map((p) => `      - ${p}`).join("\n")
            + "\n      Se si può, si scrive in modo che semgrep lo legga (vedi il motivo del "
            + "censimento: `readonly` promosso, `case … esac` su una riga); se no, lo si "
            + "aggiunge a `file_non_letti.elenco` dicendo perché nel commit.",
        );
    }
    if (tornati.length > 0) {
        console.log(
            `  (${tornati.length} file dell'elenco adesso si leggono per intero, o non ci sono più: `
            + `${tornati.join(", ")}. Toglili da \`file_non_letti.elenco\`.)`,
        );
    }
}

if (fileNonLetti.length > tettoNonLetti) {
    problemi.push(
        `${fileNonLetti.length} file semgrep non riesce a leggerli per intero, il `
        + `tetto è ${tettoNonLetti}. Su quei file le regole non girano, e il `
        + "resoconto lo mostra come «nessun riscontro» (l'elenco intero è stampato sopra).",
    );
} else if (fileNonLetti.length < tettoNonLetti) {
    // Qui, a differenza dei riscontri, un calo **non** fa fallire il giro.
    //
    // Per i riscontri la regola del «cala, quindi abbassa il tetto» ha un
    // senso preciso: quel debito lo stiamo pagando noi, e un margine lasciato
    // lì si riempie di nuovo. Questo numero invece dipende da quali file
    // esistono e da cosa l'analizzatore di semgrep sa leggere: scende quando
    // si cancella un file, sale quando se ne aggiunge uno con sintassi che
    // semgrep non digerisce. Farlo fallire a ogni calo vuol dire chiedere una
    // riga di censimento in commit che con la sicurezza non c'entrano niente
    // — e le regole che disturbano il lavoro normale vengono disattivate.
    //
    // Salire continua a far fallire: quella è la direzione che conta, perché
    // vuol dire che la scansione sta guardando meno codice di prima.
    console.log(
        `  (file letti solo in parte: ${fileNonLetti.length}, con un tetto di `
        + `${tettoNonLetti}. Il margine è voluto — vedi il censimento — e non va `
        + "chiuso: serve perché la misura oscilla da sola.)",
    );
}

const senzaMotivo = Object.entries(soffitti)
    .filter(([, v]) => !v.motivo || v.motivo === "DA SCRIVERE" || v.motivo.trim().length < 40)
    .map(([id]) => id);
if (senzaMotivo.length > 0) {
    problemi.push(
        `${senzaMotivo.length} voci del censimento non dicono perché sono accettate:\n`
        + senzaMotivo.map((id) => `      - ${id}`).join("\n")
        + "\n      Un numero senza motivo non è un censimento, è un silenziatore.",
    );
}

for (const [id, quanti] of [...conteggio].sort()) {
    const atteso = soffitti[id];
    if (!atteso) {
        const esempi = (dati.results ?? [])
            .filter((r) => String(r.check_id ?? "").split(".").pop() === id)
            .slice(0, 3)
            .map((r) => `${r.path}:${r.start?.line}`);
        problemi.push(
            `regola nuova senza voce nel censimento: ${id} (${quanti} riscontri)\n`
            + `      primi: ${esempi.join(", ")}\n`
            + "      Va corretta, oppure accettata con un motivo scritto in "
            + "tools/ci/semgrep-censimento.json.",
        );
        continue;
    }
    if (quanti > atteso.quanti) {
        problemi.push(
            `${id}: ${quanti} riscontri, il tetto è ${atteso.quanti} `
            + `(+${quanti - atteso.quanti}). Il numero non deve salire.`,
        );
    }
    if (quanti < atteso.quanti) {
        // Un calo si stampa, non fa fallire. Ci sono voluti tre giri per
        // capirlo, e la ragione è che i pacchetti di regole (`p/owasp-top-ten`,
        // `p/php`, `p/javascript`) semgrep **se li scarica dalla rete a ogni
        // scansione**, e cambiano senza preavviso.
        //
        // Misurato il 9 settembre 2026: stesso binario (1.148.0), stesso file
        // (`public/index.php`, non toccato da mesi), stessa regola
        // (`php.lang.security.injection.tainted-filename`) — e i riscontri
        // passati da 3 a 0 fra due giri a poche ore di distanza.
        //
        // Quindi un calo non vuol dire «il codice è più sicuro»: può voler
        // dire «la regola è diventata più stretta». Bloccare il giro per
        // chiedere di abbassare un tetto sulla base di quel numero significa
        // abbassare la guardia per una notizia che non riguarda noi.
        //
        // Il rialzo continua a far fallire, ed è la direzione che conta: che
        // venga da codice nuovo o da una regola nuova, va guardato.
        //
        // La cura vera sarebbe fissare anche i pacchetti di regole — scaricarli
        // una volta, versionarli, aggiornarli di proposito. Costa, e vale la
        // pena solo se questi numeri diventano una fonte di rumore.
        cali.push(`${id}: ${quanti} riscontri, il tetto dice ${atteso.quanti}`);
    }
}

for (const id of Object.keys(soffitti)) {
    if (!conteggio.has(id) && soffitti[id].quanti > 0) {
        problemi.push(
            `${id}: il censimento dice ${soffitti[id].quanti} riscontri, la `
            + "scansione zero. O è stato risolto (togli la voce) o la regola non "
            + "è più stata caricata (e allora è un buco).",
        );
    }
}

// ── Il verdetto ──────────────────────────────────────────────────────────
const totale = [...conteggio.values()].reduce((a, b) => a + b, 0);
console.log(
    `semgrep: ${esaminati} file esaminati, ${totale} riscontri in `
    + `${conteggio.size} regole, tutte censite. ${fileNonLetti.length} file letti `
    + "solo in parte.",
);

if (cali.length > 0) {
    console.log(
        `\n${cali.length} numeri sono scesi sotto il loro tetto. Se il merito è di una`
        + " correzione, abbassa il tetto nel censimento per non lasciare margine;"
        + " se invece è cambiata la regola a monte (succede: i pacchetti si"
        + " scaricano dalla rete), non c'è niente da fare.\n",
    );
    for (const c of cali) console.log(`  · ${c}`);
    console.log("");
}

if (problemi.length > 0) {
    console.error(`\n${problemi.length} problemi:\n`);
    for (const p of problemi) console.error(`  - ${p}`);
    console.error("");
    process.exit(1);
}

process.exit(0);

function leggiCensimento() {
    try {
        return JSON.parse(readFileSync(CENSIMENTO, "utf8"));
    } catch {
        return null;
    }
}
