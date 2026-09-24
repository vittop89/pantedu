import { describe, it, expect, afterEach } from "vitest";
import { readFileSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { AIUTO, MESSAGGI, messaggio, applicaRegola } from "../../js/modules/features/teacher-credentials.js";

/**
 * Le regole delle credenziali di classe dal lato del browser (ADR-044).
 *
 * I pattern li scrive il server, in costanti PHP, e la vista del profilo li
 * stampa nell'attributo `pattern`. Il browser li compila come espressioni
 * regolari con il flag `v` e ancorate (`^(?:…)$`): qui si fa lo stesso, sullo
 * stesso corpus della prova del server (tests/Fixtures/credenziali-regole.json,
 * tests/Unit/Repositories/TeacherCredentialRulesTest.php). Se il browser e il
 * server non dicono la stessa cosa su un caso, una delle due prove fallisce.
 *
 * Fino al 19 settembre 2026 il campo della password aveva solo minlength=6,
 * contato in unità UTF-16: «🙂🙂🙂» passava nel browser e il server la
 * rifiutava; il pattern dell'username non aveva un messaggio che dicesse la
 * regola; e i codici del server senza frase (username_in_uso, scadenza_passata)
 * arrivavano al docente come «Errore: …».
 */
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const corpus = JSON.parse(readFileSync(join(RADICE, "tests", "Fixtures", "credenziali-regole.json"), "utf8"));
const repository = readFileSync(join(RADICE, "app", "Repositories", "TeacherCredentialRepository.php"), "utf8");
const etichetta = readFileSync(join(RADICE, "app", "Domain", "EtichettaCredenziale.php"), "utf8");

/** Il testo di una costante PHP fra apici singoli (nessun escape da togliere nei pattern). */
function costante(sorgente, nome) {
    const m = sorgente.match(new RegExp(`const ${nome} = '([^']+)';`));
    if (!m) throw new Error(`costante ${nome} non trovata`);
    return m[1];
}

/** Come il browser compila l'attributo pattern. */
function comeIlBrowser(pattern) {
    return new RegExp(`^(?:${pattern})$`, "v");
}

describe("i pattern stampati dalla vista, compilati come fa il browser", () => {
    const username = comeIlBrowser(costante(repository, "USERNAME_HTML_PATTERN"));
    const password = comeIlBrowser(costante(repository, "PASSWORD_HTML_PATTERN"));
    const aggiunta = comeIlBrowser(costante(etichetta, "AGGIUNTA_HTML_PATTERN"));

    it.each(corpus.username.accettati)("username accettato: %j", (u) => {
        expect(username.test(u)).toBe(true);
    });
    it.each(corpus.username.rifiutati)("username rifiutato: %j", (u) => {
        expect(username.test(u)).toBe(false);
    });
    it.each(corpus.password.accettate)("password accettata: %j", (p) => {
        expect(password.test(p)).toBe(true);
    });
    it.each(Object.keys(corpus.password.rifiutate))("password rifiutata: %j", (p) => {
        expect(password.test(p)).toBe(false);
    });
    it.each(corpus.aggiunta.accettate)("aggiunta accettata: %j", (a) => {
        expect(aggiunta.test(a)).toBe(true);
    });
    it.each(corpus.aggiunta.rifiutate)("aggiunta rifiutata: %j", (a) => {
        expect(aggiunta.test(a)).toBe(false);
    });
});

describe("ogni rifiuto del server ha una frase nel modulo", () => {
    // I codici che il repository lancia (InvalidArgumentException) o
    // restituisce dai controlli delle regole, più quelli del controller.
    const lanciati = [...repository.matchAll(/InvalidArgumentException\('([a-z_]+)'/g)].map((m) => m[1]);
    const restituiti = [...repository.matchAll(/(?:return|:) '([a-z]+_[a-z_]+)';/g)].map((m) => m[1]);
    const codici = [...new Set([...lanciati, ...restituiti, "persist_failed", "not_found", "db_unavailable", "unauthorized"])];

    it("il repository ne lancia davvero (la prova non gira a vuoto)", () => {
        expect(codici).toEqual(expect.arrayContaining(["username_in_uso", "scadenza_passata", "etichetta_gia_usata", "invalid_username", "password_troppo_lunga"]));
    });

    it.each(codici)("%s", (codice) => {
        expect(Object.hasOwn(AIUTO, codice) || Object.hasOwn(MESSAGGI, codice), `manca la frase per «${codice}»`).toBe(true);
    });
});

describe("applicaRegola(): il fumetto del browser dice la regola del campo", () => {
    afterEach(() => { document.body.innerHTML = ""; });

    function campo(pattern, valore) {
        document.body.innerHTML = `<input id="c" aria-describedby="c-aiuto"><small id="c-aiuto">La regola del campo.</small>`;
        const input = /** @type {HTMLInputElement} */ (document.getElementById("c"));
        input.setAttribute("pattern", pattern);
        applicaRegola(input);
        input.value = valore;
        input.dispatchEvent(new Event("input"));
        return input;
    }

    it("fuori regola: il messaggio è il testo d'aiuto", () => {
        expect(campo(costante(repository, "USERNAME_HTML_PATTERN"), "non valido").validationMessage).toBe("La regola del campo.");
        expect(campo(costante(repository, "PASSWORD_HTML_PATTERN"), "🙂🙂🙂").validationMessage).toBe("La regola del campo.");
    });

    it("nella regola: nessun messaggio, e il campo torna valido dopo la correzione", () => {
        const input = campo(costante(repository, "USERNAME_HTML_PATTERN"), "non valido");
        input.value = "abc_1";
        input.dispatchEvent(new Event("input"));
        expect(input.validationMessage).toBe("");
        expect(input.validity.valid).toBe(true);
    });
});

describe("messaggio()", () => {
    afterEach(() => { document.body.innerHTML = ""; });

    it("per una regola usa il testo d'aiuto della vista, senza ripeterne i numeri qui", () => {
        document.body.innerHTML = '<small id="fm-cred-username-help">Da 3 a 64 caratteri: lettere senza accenti.</small>';
        expect(messaggio("invalid_username")).toBe("Username non valido. Da 3 a 64 caratteri: lettere senza accenti.");
    });

    it("per un doppione dice cosa fare", () => {
        expect(messaggio("username_in_uso")).toMatch(/già usato/);
        expect(messaggio("etichetta_gia_usata")).toMatch(/aggiunta/);
    });

    it("un codice sconosciuto resta leggibile", () => {
        expect(messaggio("boh")).toBe("Errore: boh");
    });
});
