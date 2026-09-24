import { describe, it, expect } from "vitest";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join, dirname, relative } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Ogni mutazione dell'amministrazione porta con sé la sua motivazione.
 *
 * `RequiresAuditReasonMiddleware` pretende, per ogni POST/DELETE di un
 * super-admin verso `/api/admin/**`, un motivo di almeno dieci caratteri
 * (`X-Audit-Reason` o `_audit_reason`), e lo scrive in
 * `privileged_access_log`. In modalità `enforce` chi non lo manda si prende un
 * 400; in `warn` passa, e nel registro resta la riga con scritto
 * `MISSING_OR_INVALID_AUDIT_REASON` — cioè la forma senza il contenuto. Nel
 * 2026 era già successo: 178 righe su 216 così.
 *
 * Il 21/9/2026 è ricapitato a me: il bottone «Salva ambito» del pannello dei
 * permessi dei modelli mandava CSRF e dati, e non la motivazione. Non l'ha
 * trovato nessuna prova — l'ho visto leggendo una spec end-to-end che si
 * aspettava il 400. Questa prova è perché non serva più leggere le spec a
 * mano: scorre le chiamate `fetch` di tutto `js/` e pretende che quelle verso
 * `/api/admin/` con un metodo che muta portino la motivazione.
 *
 * 23/9/2026 (A-69) — guarda solo le `fetch` scritte per esteso verso
 * `/api/admin/`. La guardia completa, che parte dalle rotte vere con
 * `audit_reason` e legge anche gli aiutanti (`post`, `apiPost`, …), i moduli
 * HTML e le pagine scritte nei controller, è
 * `tests/Unit/Core/ChiamantiMandanoLaMotivazioneTest.php`.
 */

const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..", "js");

function tuttiIFileJs(dir) {
    const fuori = [];
    for (const nome of readdirSync(dir)) {
        const p = join(dir, nome);
        if (statSync(p).isDirectory()) fuori.push(...tuttiIFileJs(p));
        else if (nome.endsWith(".js")) fuori.push(p);
    }
    return fuori;
}

/**
 * Le chiamate `fetch(...)` di un sorgente, prese col bilanciamento delle
 * parentesi (una regexp sola si ferma alla prima `)` annidata e taglia via
 * proprio le intestazioni che ci interessano).
 */
export function chiamateFetch(sorgente) {
    const fuori = [];
    const re = /\bfetch\s*\(/g;
    let m;
    while ((m = re.exec(sorgente)) !== null) {
        let i = m.index + m[0].length;
        let livello = 1;
        let apice = null;
        while (i < sorgente.length && livello > 0) {
            const c = sorgente[i];
            const prec = sorgente[i - 1];
            if (apice) {
                if (c === apice && prec !== "\\") apice = null;
            } else if (c === '"' || c === "'" || c === "`") {
                apice = c;
            } else if (c === "(") livello++;
            else if (c === ")") livello--;
            i++;
        }
        fuori.push(sorgente.slice(m.index, i));
    }
    return fuori;
}

const MUTA = /method:\s*"(POST|DELETE|PUT|PATCH)"/;

function mutazioniAdminSenzaMotivazione() {
    const senza = [];
    let quante = 0;
    for (const file of tuttiIFileJs(RADICE)) {
        for (const chiamata of chiamateFetch(readFileSync(file, "utf8"))) {
            if (!chiamata.includes("/api/admin/") || !MUTA.test(chiamata)) continue;
            quante++;
            if (chiamata.includes("X-Audit-Reason") || chiamata.includes("auditHeaders(")) continue;
            const rotta = (chiamata.match(/\/api\/admin\/[^"'`]*/) || ["?"])[0];
            senza.push(`${relative(RADICE, file)} → ${rotta}`);
        }
    }
    return { quante, senza };
}

describe("Le mutazioni dell'amministrazione", () => {
    it("portano tutte la motivazione per il registro", () => {
        const { quante, senza } = mutazioniAdminSenzaMotivazione();

        expect(quante, "se questo numero è zero la prova non sta misurando niente").toBeGreaterThan(5);
        expect(senza, `mutazioni senza X-Audit-Reason:\n  ${senza.join("\n  ")}`).toEqual([]);
    });

    it("il controllo sa accorgersi di una chiamata senza motivazione", () => {
        // La controprova, dentro la prova: sullo stesso schema di codice, con
        // e senza l'intestazione. Senza questa, un giorno in cui `chiamateFetch`
        // smettesse di trovare le chiamate la prova resterebbe verde senza aver
        // guardato niente.
        const buona = `fetch("/api/admin/cose/1", {
            method: "POST", headers: { "X-Audit-Reason": auditReason("Cambio", "x") }, body: fd });`;
        const cattiva = `fetch("/api/admin/cose/1", {
            method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: fd });`;

        const esamina = (src) => chiamateFetch(src)
            .filter((c) => c.includes("/api/admin/") && MUTA.test(c))
            .filter((c) => !c.includes("X-Audit-Reason") && !c.includes("auditHeaders("));

        expect(esamina(buona), "quella con la motivazione passa").toEqual([]);
        expect(esamina(cattiva).length, "quella senza viene presa").toBe(1);
    });
});
