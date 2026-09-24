#!/usr/bin/env node
/**
 * Guardia — ogni script con lo shebang è eseguibile anche in git.
 *
 * Il difetto che l'ha fatta nascere (9 settembre 2026). Git teneva
 * `tools/crypto/backup_teacher_keys.sh` come `100644`, cioè non eseguibile.
 * Il file aveva il bit di esecuzione sul VPS solo perché qualcuno l'aveva
 * messo a mano una volta; il primo `git reset --hard` del rilascio lo ha
 * ricreato senza, e da lì il cron delle 03:00 rispondeva:
 *
 *     /bin/sh: 1: .../backup_teacher_keys.sh: Permission denied
 *
 * dentro un file di registro che non legge nessuno. **Il salvataggio delle
 * chiavi di cifratura dei docenti è rimasto fermo dal 18 agosto al 9
 * settembre: tre settimane.** Il registro non aveva nemmeno le date, quindi
 * anche aprendolo non si capiva da quando.
 *
 * La regola è semplice e non ha eccezioni sensate: un file che comincia con
 * `#!` dichiara di essere un programma. Se git non lo sa, ogni copia fresca
 * del repository lo ricrea inerte, e il guasto compare lontano da qui — su
 * una macchina, in un cron, di notte.
 *
 * `core.fileMode=false` su Windows nasconde il problema in locale: git non
 * guarda i permessi del disco, quindi nessuno si accorge di niente finché
 * qualcosa non prova a eseguirlo davvero.
 *
 * Uscita: 0 se ogni script con shebang è `100755`, 1 altrimenti.
 */
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { join } from "node:path";

const RADICE = join(new URL("../..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"));

/** Estensioni che, con uno shebang, sono programmi da eseguire. */
const ESTENSIONI = /\.(sh|bash|zsh|mjs|js|py|pl|rb)$/;

let elenco;
try {
    elenco = execFileSync("git", ["ls-files", "-s"], {
        cwd: RADICE, encoding: "utf8", maxBuffer: 32 * 1024 * 1024,
    });
} catch (e) {
    console.error(`Non riesco a leggere l'indice di git: ${e.message}`);
    process.exit(2);
}

const inerti = [];
let controllati = 0;

for (const riga of elenco.split("\n")) {
    if (!riga.trim()) continue;
    // Formato: "<modo> <impronta> <stadio>\t<percorso>"
    const tab = riga.indexOf("\t");
    if (tab === -1) continue;
    const modo = riga.slice(0, 6);
    const percorso = riga.slice(tab + 1);
    if (!ESTENSIONI.test(percorso)) continue;

    let prima;
    try {
        // Solo i primi byte: basta lo shebang, e i file possono essere grossi.
        const b = readFileSync(join(RADICE, percorso));
        prima = b.subarray(0, 2).toString("latin1");
    } catch {
        continue; // cancellato o non leggibile: non è compito di questa guardia
    }
    if (prima !== "#!") continue;

    controllati++;
    if (modo !== "100755") inerti.push({ percorso, modo });
}

if (inerti.length === 0) {
    console.log(`script: ${controllati} file con shebang, tutti eseguibili anche in git.`);
    process.exit(0);
}

console.error(
    `\n${inerti.length} file cominciano con \`#!\` ma git li tiene NON eseguibili.\n`,
);
console.error(
    "Ogni copia fresca del repository li ricrea inerti. Il guasto non si vede qui:\n"
    + "compare quando qualcosa prova a eseguirli — un cron, un'unità systemd, il\n"
    + "rilascio — e di solito di notte.\n",
);
for (const { percorso, modo } of inerti) console.error(`  ${modo}  ${percorso}`);
console.error(
    "\nSi corregge con:\n"
    + `  git update-index --chmod=+x ${inerti.map((i) => i.percorso).join(" ")}\n`,
);
process.exit(1);
