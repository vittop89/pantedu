#!/usr/bin/env node
/**
 * CI guard — le migrazioni distruttive devono dichiarare come si torna indietro.
 *
 * Il fallimento che questo script previene: una migrazione che elimina o
 * rinomina qualcosa rende impossibile il rollback del codice. Tornare al
 * commit precedente non basta — lo schema non ha più la colonna che quel
 * codice si aspetta — e l'unica via è ripristinare il dump che `deploy.sh`
 * salva prima di migrare, perdendo tutto ciò che è stato scritto nel
 * frattempo. Con un utente solo è un fastidio; con delle classi collegate è
 * un incidente.
 *
 * La regola, decisa il 2026-09-07:
 *
 *   - le aggiunte non distruttive (nuova tabella, colonna nullable, indice)
 *     non hanno bisogno di cerimonia;
 *   - le istruzioni distruttive — eliminare o rinominare una colonna o una
 *     tabella, imporre NOT NULL senza default, restringere un tipo — vanno
 *     dichiarate in testa al file, con due righe:
 *
 *         -- SICUREZZA: perché è sicura con il codice della versione precedente
 *         -- ROLLBACK: come si torna indietro
 *
 *     Scriverle costa un minuto quando si ha in mente il perché; ricostruirlo
 *     mesi dopo, davanti a un guasto, costa molto di più.
 *
 * Perché una soglia invece di un elenco di eccezioni: le migrazioni fino alla
 * 106 sono già in produzione e riscriverne le intestazioni non aggiunge
 * sicurezza a niente. La regola vale da qui in avanti, ed è verificabile
 * guardando il numero del file.
 *
 * Uso: node tools/ci/check-migrations-safety.mjs  (npm run db:migrations)
 */
import { readFileSync, readdirSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const CARTELLA = join(RADICE, "database", "migrations");

/** Le migrazioni fino a questo numero sono precedenti alla regola. */
const PRIMA_DELLA_REGOLA = 106;

/**
 * Istruzioni che tolgono qualcosa a cui il codice precedente potrebbe
 * appoggiarsi. `DROP ... IF EXISTS` su un indice o una chiave esterna non è
 * distruttivo per i dati: resta fuori di proposito.
 */
const DISTRUTTIVE = [
    { schema: /\bDROP\s+TABLE\b/i, nome: "DROP TABLE" },
    { schema: /\bDROP\s+COLUMN\b/i, nome: "DROP COLUMN" },
    { schema: /\bALTER\s+TABLE\b[\s\S]{0,200}?\bRENAME\s+(?:COLUMN|TO)\b/i, nome: "RENAME" },
    { schema: /\bCHANGE\s+(?:COLUMN\s+)?`?\w+`?\s+`?\w+`?/i, nome: "CHANGE COLUMN" },
    { schema: /\bMODIFY\s+(?:COLUMN\s+)?[\s\S]{0,120}?\bNOT\s+NULL\b/i, nome: "MODIFY ... NOT NULL" },
    { schema: /\bTRUNCATE\b/i, nome: "TRUNCATE" },
    { schema: /\bDELETE\s+FROM\b/i, nome: "DELETE FROM" },
];

const dichiaraSicurezza = (testo) => /^\s*--\s*SICUREZZA\s*:/im.test(testo);
const dichiaraRollback = (testo) => /^\s*--\s*ROLLBACK\s*:/im.test(testo);

/** Toglie i commenti: una parola in una nota non è un'istruzione. */
const senzaCommenti = (sql) =>
    sql.split("\n").filter((riga) => !/^\s*--/.test(riga)).join("\n");

const errori = [];
const file = readdirSync(CARTELLA).filter((n) => n.endsWith(".sql")).sort();

for (const nome of file) {
    const numero = Number.parseInt(nome.slice(0, 3), 10);
    if (!Number.isFinite(numero) || numero <= PRIMA_DELLA_REGOLA) continue;

    const testo = readFileSync(join(CARTELLA, nome), "utf8");
    const istruzioni = senzaCommenti(testo);
    const trovate = DISTRUTTIVE.filter(({ schema }) => schema.test(istruzioni)).map(({ nome: n }) => n);
    if (trovate.length === 0) continue;

    const mancanti = [];
    if (!dichiaraSicurezza(testo)) mancanti.push("-- SICUREZZA:");
    if (!dichiaraRollback(testo)) mancanti.push("-- ROLLBACK:");
    if (mancanti.length > 0) {
        errori.push(
            `${nome} contiene ${trovate.join(", ")} ma non dichiara ${mancanti.join(" e ")}`
        );
    }
}

if (errori.length > 0) {
    console.error("✗ Migrazioni distruttive senza dichiarazione:\n");
    for (const e of errori) console.error(`  · ${e}`);
    console.error("");
    console.error("  In testa al file servono due righe, per esteso:");
    console.error("    -- SICUREZZA: perché questa migrazione è sicura con il codice della versione precedente");
    console.error("    -- ROLLBACK: come si torna indietro se il deploy va male");
    console.error("");
    console.error("  Se la modifica si può fare senza distruggere niente (aggiungere ora,");
    console.error("  migrare i dati, togliere il vecchio in un deploy successivo), è la strada preferita.");
    console.error("");
    process.exit(1);
}

console.log(`✓ Migrazioni oltre la ${PRIMA_DELLA_REGOLA}: nessuna distruttiva senza dichiarazione`);
