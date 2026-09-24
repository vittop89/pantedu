#!/usr/bin/env node
/**
 * I PDF consegnati non sono più vecchi dei documenti da cui nascono.
 *
 * ── Perché esiste (22 settembre 2026) ─────────────────────────────────────
 *
 * I PDF di `docs/privacy/` e `docs/legal/` sono gli allegati veri della
 * corrispondenza con il DPO: sono loro che si mandano, non i `.md`.
 *
 * Quel giorno erano indietro fino a **sette versioni**. L'informativa
 * dichiarava 2.16 nel sorgente e 2.13 nel PDF; il registro 1.18 contro 1.16; e
 * il PDF della procedura di rimozione dei contenuti conteneva ancora il nome e
 * l'indirizzo di posta di un professionista, tolti dal `.md` lo stesso giorno.
 *
 * Il riquadro della lettera lo prevedeva — «prima di allegare: rigenerare i
 * PDF» — e cioè affidava a chi si ricorda una cosa che decide che cosa legge
 * un'autorità. Allegare senza rigenerare significherebbe mandare documenti che
 * non contengono nulla di ciò che la lettera racconta.
 *
 * ── Perché guarda le date di git e non il contenuto ───────────────────────
 *
 * Leggere dentro un PDF richiede `pdftotext`, che nella catena di integrazione
 * non c'è. Un controllo che si salta dove manca uno strumento è un controllo
 * che non misura — e nel lavoro «ogni salto è un fallimento» farebbe rosso per
 * la ragione sbagliata.
 *
 * Si guarda quindi la data dell'ultimo commit che ha toccato ciascun file: se
 * il `.md` è più recente del suo `.pdf`, il PDF è di sicuro vecchio. È lo
 * stesso criterio con cui `tests/e2e/global-setup.js` rifiuta di girare quando
 * il pacchetto servito è più vecchio dei sorgenti.
 *
 * Il limite, dichiarato: non accorge se un PDF è stato rigenerato da un
 * sorgente sbagliato. Per quello serve leggerlo, e lo si fa a mano prima di
 * inviare.
 *
 * ── Come si prova ─────────────────────────────────────────────────────────
 *
 * `touch docs/privacy/dpia.md && git commit -am prova` e rilanciare: deve
 * uscire 1 e nominare il file. Fatto il 22/9/2026. Dal 23/9/2026 lo fanno
 * `tests/js-unit/pdf-aggiornati.test.js`, su repository usa e getta, e la
 * guardia gira in CI (`ci.yml`, lavoro «Front-end»).
 *
 * ── Su un checkout superficiale si ferma (23 settembre 2026) ──────────────
 *
 * Con un solo commit di storia (`actions/checkout` di serie ha
 * `fetch-depth: 1`) ogni file ha la data di quel commit: nessun sorgente è
 * «più recente» del suo PDF e la guardia sarebbe verde senza aver confrontato
 * niente. Quel verde non è un esito, quindi esce 1 e dice che cosa manca.
 */

import { execFileSync } from "node:child_process";
import { existsSync } from "node:fs";
import { join } from "node:path";

/** Le cartelle i cui PDF si consegnano. */
const CARTELLE = ["docs/privacy", "docs/legal", "docs/dpo/pacchetto-scuola"];

function ultimoCommit(percorso) {
    try {
        const out = execFileSync("git", ["log", "-1", "--format=%ct", "--", percorso], {
            encoding: "utf8",
        }).trim();
        return out === "" ? null : Number(out);
    } catch {
        return null;
    }
}

function sorgentiConPdf() {
    const out = execFileSync("git", ["ls-files", ...CARTELLE], { encoding: "utf8" });
    return out
        .split("\n")
        .filter((f) => f.endsWith(".md"))
        .map((md) => ({ md, pdf: md.replace(/\.md$/, ".pdf") }))
        .filter((c) => existsSync(join(process.cwd(), c.pdf)));
}

function superficiale() {
    try {
        return execFileSync("git", ["rev-parse", "--is-shallow-repository"], { encoding: "utf8" }).trim() === "true";
    } catch {
        return false;
    }
}

if (superficiale()) {
    console.error(
        "[pdf-aggiornati] il repository ha una storia superficiale: le date dei commit non dicono\n" +
            "quale file è più recente, e il confronto sarebbe verde senza aver guardato niente.\n" +
            "In un workflow: actions/checkout con  fetch-depth: 0.  In locale:  git fetch --unshallow.",
    );
    process.exit(1);
}

const coppie = sorgentiConPdf();

if (coppie.length === 0) {
    console.error(
        "[pdf-aggiornati] nessuna coppia .md/.pdf trovata: il controllo non starebbe misurando niente.",
    );
    process.exit(1);
}

const vecchi = [];
for (const { md, pdf } of coppie) {
    const dataMd = ultimoCommit(md);
    const dataPdf = ultimoCommit(pdf);
    // Un PDF mai committato non è «vecchio»: è nuovo e non ancora versionato.
    if (dataMd === null || dataPdf === null) continue;
    if (dataMd > dataPdf) {
        const giorni = Math.round((dataMd - dataPdf) / 86400);
        vecchi.push({ md, pdf, giorni });
    }
}

if (vecchi.length > 0) {
    console.error("[pdf-aggiornati] questi PDF sono più vecchi del documento da cui nascono:\n");
    for (const v of vecchi) {
        console.error(`  · ${v.pdf}`);
        console.error(`    il sorgente ${v.md} è stato toccato ${v.giorni} giorni dopo.\n`);
    }
    console.error("Sono gli allegati che si consegnano: rigenerarli prima di inviare.\n");
    console.error("  tools/legal/build_pdf.sh <file.md> ...                (documenti legali e privacy)");
    console.error("  docs/dpo/pacchetto-scuola/_gen_pdf.py <file.md> ...   (pacchetto per il DPO)\n");
    console.error(
        "Usano strumenti di Windows, e col bash o il python di WSL si fermano.\n" +
            "Come si lanciano: docs/dev/sviluppo-in-wsl.md, «Che cosa si fa ancora da Windows».\n",
    );
    process.exit(1);
}

console.log(`[pdf-aggiornati] ${coppie.length} PDF, tutti non più vecchi del loro sorgente.`);
