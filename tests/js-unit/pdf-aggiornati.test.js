// @vitest-environment node
import { describe, it, expect, beforeAll, afterAll } from "vitest";
import { execFileSync, spawnSync } from "node:child_process";
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * `tools/ci/check-pdf-aggiornati.mjs` (`npm run legal:pdf`), nei due versi, su
 * repository git usa e getta.
 *
 * Fino al 23/9/2026 la guardia stava solo in `npm run ci`, che nessun workflow
 * esegue, e la si era provata a mano una volta. In CI il checkout ha un solo
 * commit di storia: lì tutte le date sono uguali e il confronto non può
 * fallire (revisione architetturale del 23/9/2026, A-25). Quindi, oltre ai due
 * versi del confronto, la guardia deve fermarsi su una storia superficiale
 * invece di dare un verde che non ha guardato.
 */
const RADICE = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const GUARDIA = join(RADICE, "tools", "ci", "check-pdf-aggiornati.mjs");

let cartella;
let repo;

/** git nel repository di prova, con autore e data fissati. */
function git(dove, args, data = "2026-09-01T10:00:00Z") {
    return execFileSync("git", args, {
        cwd: dove,
        encoding: "utf8",
        env: {
            ...process.env,
            GIT_AUTHOR_NAME: "prova", GIT_AUTHOR_EMAIL: "prova@example.invalid",
            GIT_COMMITTER_NAME: "prova", GIT_COMMITTER_EMAIL: "prova@example.invalid",
            GIT_AUTHOR_DATE: data, GIT_COMMITTER_DATE: data,
        },
    });
}

/** Lancia la guardia dentro `dove`: esito e messaggi. */
function guardia(dove) {
    const r = spawnSync(process.execPath, [GUARDIA], { cwd: dove, encoding: "utf8" });
    return { esito: r.status, testo: `${r.stdout}${r.stderr}` };
}

/** Riscrive un file e lo committa alla data data. */
function committa(percorso, contenuto, data) {
    writeFileSync(join(repo, percorso), contenuto);
    git(repo, ["add", percorso]);
    git(repo, ["commit", "-q", "-m", `tocca ${percorso}`], data);
}

beforeAll(() => {
    cartella = mkdtempSync(join(tmpdir(), "pdf-aggiornati-"));
    repo = join(cartella, "repo");
    mkdirSync(join(repo, "docs", "privacy"), { recursive: true });
    git(repo, ["init", "-q", "-b", "main"]);
    committa("docs/privacy/informativa.md", "# Informativa 1.0\n", "2026-09-01T10:00:00Z");
    committa("docs/privacy/informativa.pdf", "%PDF-1.4 versione 1.0\n", "2026-09-02T10:00:00Z");
});
afterAll(() => { rmSync(cartella, { recursive: true, force: true }); });

describe("guardia dei PDF legali — il PDF non è più vecchio del suo sorgente", () => {
    it("tace quando il PDF è stato rigenerato dopo l'ultima modifica del sorgente", () => {
        const { esito, testo } = guardia(repo);
        expect(testo).toContain("1 PDF, tutti non più vecchi");
        expect(esito).toBe(0);
    });

    it("scatta e nomina il PDF quando il sorgente cambia dopo", () => {
        committa("docs/privacy/informativa.md", "# Informativa 1.1\n", "2026-09-05T10:00:00Z");
        const { esito, testo } = guardia(repo);
        expect(testo).toContain("docs/privacy/informativa.pdf");
        expect(testo).toContain("3 giorni dopo");
        expect(esito).toBe(1);
    });

    it("torna a tacere quando il PDF si rigenera", () => {
        committa("docs/privacy/informativa.pdf", "%PDF-1.4 versione 1.1\n", "2026-09-06T10:00:00Z");
        expect(guardia(repo).esito).toBe(0);
    });

    it("su una storia superficiale si ferma invece di dare un verde che non ha confrontato niente", () => {
        // Un sorgente più nuovo del PDF, e un clone di un commit solo: con le
        // date tutte uguali il confronto direbbe «tutto a posto».
        committa("docs/privacy/informativa.md", "# Informativa 1.2\n", "2026-09-08T10:00:00Z");
        const superficiale = join(cartella, "superficiale");
        execFileSync("git", ["clone", "-q", "--depth", "1", `file://${repo}`, superficiale]);
        expect(git(superficiale, ["rev-parse", "--is-shallow-repository"]).trim()).toBe("true");

        const { esito, testo } = guardia(superficiale);
        expect(testo).toContain("storia superficiale");
        expect(testo).toContain("fetch-depth: 0");
        expect(esito).toBe(1);

        // Con la storia intera lo stesso contenuto è rosso per la ragione giusta.
        const intero = join(cartella, "intero");
        execFileSync("git", ["clone", "-q", `file://${repo}`, intero]);
        const pieno = guardia(intero);
        expect(pieno.testo).toContain("docs/privacy/informativa.pdf");
        expect(pieno.esito).toBe(1);
    });
});
