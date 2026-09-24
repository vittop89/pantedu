#!/usr/bin/env node
/**
 * I moduli che devono finire nel fascio principale ci finiscono davvero.
 *
 * ── Perché esiste (22 settembre 2026) ─────────────────────────────────────
 *
 * Il banner che annuncia una versione nuova dei Termini nasce con `hidden`
 * nel markup, e l'unico punto che lo toglie è una riga dentro
 * `js/modules/core/legal-notice.js`. Quel file **non era importato da
 * nessuno**: il banner promesso dai Termini §14 e dall'AUP §11 — l'avviso in
 * applicazione trenta giorni prima di una modifica sostanziale — non si è mai
 * visto da nessuno.
 *
 * Tutti i pezzi esistevano e sembravano collegati: il markup, il servizio che
 * lo alimenta, il commento che lo dichiarava. Mancava un import, e un import
 * che manca non fa rumore.
 *
 * ── Perché qui e non in una prova PHPUnit ─────────────────────────────────
 *
 * Perché il pacchetto costruito è un artefatto del front-end, e nella catena
 * di integrazione esiste soltanto nel lavoro che lo costruisce. Una prova
 * PHPUnit che lo cercasse dovrebbe saltare dove non c'è — e in quel lavoro
 * «ogni salto è un fallimento», giustamente: un controllo che si salta è un
 * controllo che non misura.
 *
 * ── Come si prova questo controllo ────────────────────────────────────────
 *
 * Togliendo l'import da `js/modules/bootstrap.js` e rilanciando `npm run
 * build`: deve uscire 1 e nominare il modulo. Fatto il 22/9/2026.
 */

import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

const DIR = "public/build/assets";

/**
 * Ogni voce: un'impronta che deve comparire nel fascio, e perché.
 * L'impronta è una stringa che sopravvive alla minificazione — una classe
 * CSS, un attributo, il nome di una chiave — non il nome della funzione, che
 * il minificatore rinomina.
 */
const ATTESI = [
    {
        impronta: "fm-legal-notice",
        modulo: "js/modules/core/legal-notice.js",
        perche: "senza, il banner dei Termini resta `hidden` per sempre e la promessa del §14 non si mantiene",
    },
];

/** Controllo positivo: se questa non c'è, non stiamo guardando il fascio giusto. */
const SENTINELLA = "fm-bb-menu";

function fascio() {
    let voci;
    try {
        voci = readdirSync(DIR);
    } catch {
        console.error(`[moduli-nel-fascio] ${DIR} non esiste: lanciare prima \`npm run build\`.`);
        process.exit(1);
    }
    const nome = voci.find((f) => /^bootstrap\..*\.js$/.test(f));
    if (!nome) {
        console.error(`[moduli-nel-fascio] nessun bootstrap.*.js in ${DIR}: il build non ha prodotto il fascio.`);
        process.exit(1);
    }
    return { nome, contenuto: readFileSync(join(DIR, nome), "utf8") };
}

const { nome, contenuto } = fascio();

if (!contenuto.includes(SENTINELLA)) {
    console.error(
        `[moduli-nel-fascio] ${nome} non contiene «${SENTINELLA}»: non sembra il fascio principale, ` +
        "e questo controllo non starebbe misurando niente.",
    );
    process.exit(1);
}

const mancanti = ATTESI.filter((a) => !contenuto.includes(a.impronta));

if (mancanti.length > 0) {
    console.error(`[moduli-nel-fascio] ${nome} non contiene:\n`);
    for (const m of mancanti) {
        console.error(`  · ${m.impronta}  (${m.modulo})`);
        console.error(`    ${m.perche}`);
        console.error(`    Manca un import in js/modules/bootstrap.js.\n`);
    }
    process.exit(1);
}

console.log(`[moduli-nel-fascio] ${ATTESI.length} moduli attesi, tutti dentro ${nome}.`);
