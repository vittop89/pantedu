/**
 * Gli errori di pdflatex, come li mostra l'editor (24/9/2026).
 *
 * Il server manda `errors` (ErroriTex / errori_tex.py): per ognuno il
 * messaggio, il numero dopo `l.` e il contesto in cui TeX mostra il punto in
 * cui si è fermato. Quel numero è la riga del documento **compilato**, che ha
 * righe in più in testa (la classe, i font): nell'editor del docente non
 * corrisponde. La riga vera si ritrova cercando nel sorgente il testo che TeX
 * cita dopo `l.NNN`.
 */

/**
 * La riga (da 1) del sorgente in cui sta il testo citato da TeX, o null.
 * TeX abbrevia l'inizio con `...` quando la riga è lunga: si cerca il pezzo
 * che resta.
 */
export function rigaNelSorgente(sorgente, errore) {
    const contesto = String(errore?.context ?? "");
    const m = contesto.match(/^l\.\d+ (.*)$/m);
    if (!m) return null;
    const citato = m[1].replace(/^\.\.\./, "").trim();
    if (citato.length < 3) return null;
    const righe = String(sorgente ?? "").split(/\r?\n/);
    const trovate = [];
    righe.forEach((r, i) => { if (r.includes(citato)) trovate.push(i + 1); });
    // Due righe uguali: non si sa quale, meglio non indicarne una a caso.
    return trovate.length === 1 ? trovate[0] : null;
}

/** Il testo per il docente: quanti errori, e per ognuno riga, messaggio e punto. */
export function testoErrori(errori, sorgente) {
    const n = errori.length;
    const parti = [`${n} ${n === 1 ? "errore" : "errori"} di pdflatex:`];
    for (const e of errori) {
        const riga = rigaNelSorgente(sorgente, e);
        const dove = riga ? `riga ${riga} del sorgente — ` : "";
        parti.push(`${dove}! ${e.message}${e.context ? `\n${e.context}` : ""}`);
    }
    return parti.join("\n\n");
}
