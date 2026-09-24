#!/usr/bin/env node
/**
 * Guardia — nessun JavaScript legge un `<meta>` che nessuna vista emette.
 *
 * Il difetto che l'ha fatta nascere (9 settembre 2026). Nove punti del
 * front-end prendevano il gettone CSRF così:
 *
 *     document.querySelector('meta[name="csrf-token"]')?.content || ""
 *
 * Quel `<meta>` **non esiste in nessuna vista**, e non è mai esistito. Il
 * ripiego `|| ""` faceva partire una stringa vuota; il server rispondeva 403;
 * il client mostrava «errore». Quattro funzioni — salvataggio ed eliminazione
 * nel catalogo GeoGebra, riordino dei gruppi, salvataggio delle scorciatoie
 * LaTeX — erano rotte da mesi.
 *
 * Perché nessuno se n'era accorto: un 403 sembra un blocco riuscito. E i due
 * lati della faccenda stanno in cartelle diverse — chi legge il meta è in
 * `js/`, chi lo emetterebbe è in `views/` — quindi nessuna lettura di un file
 * solo poteva svelarlo. Serviva **confrontare le due liste**, che è tutto
 * quello che fa questo script.
 *
 * La regola è generale, non sul solo CSRF: leggere un `<meta>` che nessuno
 * scrive è sempre un difetto silenzioso, perché `?.content` restituisce
 * `undefined` senza rumore.
 *
 * Uscita: 0 se ogni meta letto è anche emesso, 1 altrimenti.
 */
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const RADICE = join(fileURLToPath(new URL(".", import.meta.url)), "..", "..");

/** Dove si legge un meta. */
const CARTELLE_JS = ["js"];
/** Dove si emette. Le viste PHP e i pochi frammenti statici. */
const CARTELLE_VISTE = ["views", "app"];

/**
 * Meta letti da JS ma emessi da qualcosa che non sappiamo leggere (per
 * esempio un'estensione del browser o una libreria di terze parti). Ogni voce
 * porta il motivo: senza, questo elenco diventa il posto dove sparisce il
 * problema invece di risolverlo.
 */
const AMMESSI = {
    // (vuoto: qualunque aggiunta va motivata qui)
};

const LETTURA = /meta\[\s*name\s*=\s*["']([^"']+)["']\s*\]/g;
const EMISSIONE = /<meta[^>]*\bname\s*=\s*["']([^"']+)["']/gi;
/** `Response::header('...')` no: qui interessa solo il documento HTML. */

function percorri(dir, estensioni) {
    const fuori = [];
    let voci;
    try {
        voci = readdirSync(dir);
    } catch {
        return fuori;
    }
    for (const nome of voci) {
        if (nome === "node_modules" || nome === "vendor" || nome === "build") continue;
        const p = join(dir, nome);
        if (statSync(p).isDirectory()) fuori.push(...percorri(p, estensioni));
        else if (estensioni.test(nome)) fuori.push(p);
    }
    return fuori;
}

/**
 * Toglie i commenti lasciando le righe al loro posto.
 *
 * Serve perché questo stesso file, e i commenti che spiegano il difetto negli
 * altri, nominano `meta[name="csrf-token"]` per iscritto. Senza, la guardia
 * segnalerebbe la propria documentazione — e una guardia che grida al lupo
 * sulle spiegazioni viene disattivata entro la settimana.
 *
 * I blocchi `/* … *\/` diventano righe vuote (i capoversi si conservano) e di
 * ogni riga si taglia da `//` in poi, saltando `://` per non troncare gli URL.
 */
function senzaCommenti(testo) {
    const senzaBlocchi = testo.replace(/\/\*[\s\S]*?\*\//g, (b) => b.replace(/[^\n]/g, " "));
    return senzaBlocchi
        .split("\n")
        .map((riga) => {
            const i = riga.search(/(^|[^:])\/\//);
            return i === -1 ? riga : riga.slice(0, i);
        });
}

const letti = new Map(); // nome → [percorso:riga, ...]
for (const cartella of CARTELLE_JS) {
    for (const file of percorri(join(RADICE, cartella), /\.(js|mjs)$/)) {
        const testo = readFileSync(file, "utf8");
        const righe = senzaCommenti(testo);
        righe.forEach((riga, i) => {
            for (const m of riga.matchAll(LETTURA)) {
                const nome = m[1];
                if (!letti.has(nome)) letti.set(nome, []);
                letti.get(nome).push(`${relative(RADICE, file)}:${i + 1}`);
            }
        });
    }
}

const emessi = new Set();
for (const cartella of CARTELLE_VISTE) {
    for (const file of percorri(join(RADICE, cartella), /\.(php|html?)$/)) {
        const testo = readFileSync(file, "utf8");
        for (const m of testo.matchAll(EMISSIONE)) emessi.add(m[1]);
    }
}

const fantasmi = [...letti.keys()]
    .filter((nome) => !emessi.has(nome) && !(nome in AMMESSI))
    .sort();

if (fantasmi.length === 0) {
    console.log(
        `meta: ${letti.size} letti dal front-end, tutti emessi da una vista `
        + `(${emessi.size} nomi disponibili).`,
    );
    process.exit(0);
}

console.error(
    `\n${fantasmi.length} <meta> vengono letti dal JavaScript e non li emette nessuno.\n`,
);
console.error(
    "`document.querySelector('meta[name=...]')?.content` restituisce `undefined`\n"
    + "senza fare rumore: il valore parte vuoto e il guasto si manifesta lontano\n"
    + "da qui, di solito come un errore del server che sembra un rifiuto legittimo.\n",
);
for (const nome of fantasmi) {
    console.error(`  <meta name="${nome}">  letto in:`);
    for (const dove of letti.get(nome)) console.error(`      ${dove}`);
}
console.error(
    "\nO la vista lo emette, o il JavaScript prende il valore da un'altra parte\n"
    + "(per il gettone CSRF: `fetchCsrf()` in js/modules/core/dom-utils.js).\n",
);
process.exit(1);
