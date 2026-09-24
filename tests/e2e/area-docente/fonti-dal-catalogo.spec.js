// @ts-check
/**
 * Le fonti dal catalogo delle adozioni (ADR-036).
 *
 * L'amministratore mette a catalogo un libro per una classe che il docente ha
 * spuntato; il docente lo vede in /area-docente/fonti e con «Aggiungi» lo
 * porta nel proprio registro, dove diventa una fonte come le altre. Il
 * rovescio: per una classe che il docente non ha il libro non compare, finché
 * non chiede «tutto l'istituto».
 *
 * Il libro è creato dall'API dell'amministratore (non dal caricamento del
 * dataset MIUR, che pesa decine di megabyte) con un ISBN unico, e a fine prova
 * si cancella insieme alla fonte che ha lasciato nel registro.
 */
const { test, expect } = require("../support/test");

const ANNO = "2026/2027";

/** Un ISBN-13 sintattico, unico per giro: prefisso 979 più le ultime dieci cifre dell'orologio. */
function isbnUnico() {
    return `979${String(Date.now()).slice(-10)}`;
}

test.describe("Area docente — fonti dal catalogo delle adozioni", () => {
    test("un libro adottato per una classe del docente compare fra le fonti proposte, e «Aggiungi» lo mette nel registro", async ({ teacherPage, teacherApi, adminApi, naming, cleanup }) => {
        const mie = await teacherApi.adozioni.mie();
        expect(mie.ok, "il catalogo risponde").toBe(true);
        expect(mie.classi_mie.length, "il docente ha almeno una classe spuntata").toBeGreaterThan(0);
        const classe = mie.classi_mie[0] ?? "";
        const materia = mie.materie_mie[0];

        const titolo = naming.unique("Libro E2E");
        const isbn = isbnUnico();
        const registroPrima = (await teacherApi.sources.registry()).sources ?? [];
        cleanup.add("teacher", `toglie «${titolo}» dal registro delle fonti`, async () => {
            const registro = (await teacherApi.sources.registry()).sources ?? [];
            await teacherApi.sources.saveRegistry(registro.filter((s) => s.isbn !== isbn && s.book !== titolo));
        });

        const creato = await adminApi.adozioni.create({
            institute_id: mie.institute_id,
            anno_scolastico: ANNO,
            classe,
            disciplina: "MATEMATICA (E2E)",
            ...(materia ? { materia } : {}),
            isbn,
            titolo,
            autori: "Autrice E2E",
            editore: "Editore E2E",
            volume: "2",
        });
        expect(creato.status, `il libro entra a catalogo: ${creato.text}`).toBe(200);
        const id = creato.body?.id ?? 0;
        expect(id, "l'identificativo del libro").toBeGreaterThan(0);
        cleanup.add("admin", `cancella il libro «${titolo}» dal catalogo`, async () => {
            await adminApi.adozioni.delete(id);
        });

        // Dall'API: c'è per la classe del docente, non per una che non ha; con «tutte» c'è comunque.
        const perLeMie = await teacherApi.adozioni.mie();
        expect(perLeMie.libri.map((l) => l.id), "il libro è fra quelli delle classi spuntate").toContain(id);
        const perUnAltra = await teacherApi.adozioni.mie({ classe: "9Z" });
        expect(perUnAltra.libri.map((l) => l.id), "non per una classe che il docente non ha").not.toContain(id);
        const tutte = await teacherApi.adozioni.mie({ tutte: true });
        expect(tutte.libri.map((l) => l.id), "con «tutto l'istituto» c'è comunque").toContain(id);
        expect(perLeMie.libri.find((l) => l.id === id)?.in_registro, "non è ancora fra le fonti").toBe(false);

        // Dalla pagina.
        await teacherPage.goto("/area-docente/fonti");
        const riga = teacherPage.locator(`#fm-adozioni-table tr[data-adozione="${id}"]`);
        await expect(riga, "il libro è nella tabella del catalogo").toBeVisible({ timeout: 30_000 });
        await expect(riga).toContainText(titolo);
        await riga.getByRole("button", { name: "➕ Aggiungi" }).click();
        await expect(riga, "la riga dice che il libro è fra le fonti").toContainText("fra le tue fonti", { timeout: 15_000 });

        const registro = (await teacherApi.sources.registry()).sources ?? [];
        const fonte = registro.find((s) => s.isbn === isbn);
        expect(fonte, "il registro ha la fonte con l'ISBN del libro").toBeTruthy();
        expect(fonte?.book, "il titolo").toBe(titolo);
        expect(fonte?.volume, "volume ed editore, nella forma che il cartellino separa").toBe("Vol.2 - EDITORE E2E");
        expect(fonte?.authors, "gli autori").toBe("Autrice E2E");
        expect(registro.length, "le fonti di prima ci sono ancora").toBe(registroPrima.length + 1);

        // Il catalogo lo sa, e la tabella delle fonti in pagina si è aggiornata da sola.
        expect((await teacherApi.adozioni.mie()).libri.find((l) => l.id === id)?.in_registro, "ora è fra le fonti").toBe(true);
        await expect(teacherPage.locator("#fm-fonti-table"), "la tabella delle fonti mostra il libro").toContainText(titolo, { timeout: 15_000 });
    });
});
