// @ts-check
/**
 * Il catalogo dell'istituto, dal pannello dell'amministratore (ADR-035).
 *
 * Il vocabolario è della scuola: l'amministratore aggiunge una voce, la
 * rinomina una volta per tutti, la spegne e la toglie — ogni mossa con la
 * motivazione, perché è una mutazione privilegiata, e senza motivazione niente
 * passa. La pagina dice anche quanti docenti hanno spuntato ogni voce: per una
 * materia del docente della suite il conto è almeno uno.
 *
 * La voce di prova ha una sigla unica e si toglie a fine prova; le voci vere
 * dell'istituto non si toccano.
 */
const { test, expect } = require("../support/test");

const MOTIVAZIONE = "prova end-to-end del catalogo dell'istituto";

/** Una sigla di sei lettere maiuscole che inizia per ZZ: fuori da ogni sigla vera. */
function siglaUnica() {
    let s = "ZZ";
    while (s.length < 6) s += String.fromCharCode(65 + Math.floor(Math.random() * 26));
    return s;
}

test.describe("Amministrazione — catalogo dell'istituto", () => {
    test("una voce aggiunta si vede, si rinomina per tutti, si spegne e si toglie; senza motivazione niente passa", async ({ adminPage, adminApi, teacherApi, naming, cleanup }) => {
        const mie = await teacherApi.adozioni.mie();
        const pagina = `/admin/institutes/${mie.institute_id}/catalogo`;
        const code = siglaUnica();
        const label = naming.unique("Materia di prova");

        /** L'identificativo della voce di prova, letto dalla pagina; null se non c'è. */
        const idVoce = async () => {
            const html = await (await adminApi.http.request.get(pagina)).text();
            const m = html.match(new RegExp(`data-voce="(\\d+)"[^>]*>\\s*<td><span class="fm-code">${code}</span>`));
            return m ? Number(m[1]) : null;
        };
        cleanup.add("admin", `toglie la voce «${code}» dal catalogo`, async () => {
            const id = await idVoce();
            if (id !== null) {
                await adminApi.http.send("POST", `${pagina}/${id}`, { form: { azione: "elimina", _audit_reason: MOTIVAZIONE } });
            }
        });

        // Senza motivazione la mutazione non passa: l'header che il client
        // della suite mette su ogni scrittura si azzera apposta, e nel modulo
        // non c'è.
        const senza = await adminApi.http.send("POST", pagina, {
            form: { kind: "materie", code, label },
            headers: { "X-Audit-Reason": "" },
        });
        expect(senza.status, "senza motivazione: rifiutata").toBe(400);
        expect(senza.body?.["error"], "e dice perché").toBe("audit_reason_required");
        expect(await idVoce(), "la voce non è entrata").toBeNull();

        const con = await adminApi.http.send("POST", pagina, { form: { kind: "materie", code, label, _audit_reason: MOTIVAZIONE } });
        expect(con.ok, `con la motivazione la voce entra (${con.status})`).toBe(true);
        const id = await idVoce();
        expect(id, "la voce è nel catalogo").not.toBeNull();

        await adminPage.goto(pagina);
        const riga = adminPage.locator(`tr[data-voce="${id}"]`);
        await expect(riga, "la riga della voce").toBeVisible({ timeout: 30_000 });
        await expect(riga).toContainText(label);
        await expect(riga, "origine: la scuola").toHaveAttribute("data-origine", "istituto");
        await expect(riga.locator("td").nth(3), "nessun docente l'ha spuntata").toHaveText("0");
        await expect(riga.locator("td").nth(4), "nessun contenuto ci punta").toHaveText("0");

        // Le voci vere: la prima materia spuntata dal docente della suite conta almeno lui.
        const materiaDelDocente = mie.materie_mie[0] ?? "";
        expect(materiaDelDocente, "il docente della suite ha una materia spuntata").not.toBe("");
        const rigaSua = adminPage
            .locator("#catalogo-materie tbody tr")
            .filter({ has: adminPage.locator("td:first-child .fm-code", { hasText: new RegExp(`^${materiaDelDocente}$`) }) });
        await expect(rigaSua, `la riga di «${materiaDelDocente}»`).toHaveCount(1);
        expect(Number(await rigaSua.locator("td").nth(3).innerText()), "docenti che l'hanno spuntata").toBeGreaterThanOrEqual(1);

        // Rinominare dalla pagina: vale per tutti, e la pagina lo conferma.
        const nuovaLabel = `${label} (rinominata)`;
        await riga.getByLabel(`Nuova etichetta di ${code}`).fill(nuovaLabel);
        await riga.getByLabel(`Motivazione per ${code}`).fill(MOTIVAZIONE);
        await riga.getByRole("button", { name: "Rinomina" }).click();
        await expect(adminPage.locator(".fm-alert"), "la pagina conferma").toContainText("rinominata", { timeout: 15_000 });
        await expect(adminPage.locator(`tr[data-voce="${id}"]`), "con la nuova etichetta").toContainText(nuovaLabel);

        // Spegnere: resta in elenco, disattivata.
        const rigaAccesa = adminPage.locator(`tr[data-voce="${id}"]`);
        await rigaAccesa.getByLabel(`Motivazione per ${code}`).fill(MOTIVAZIONE);
        await rigaAccesa.getByRole("button", { name: "Disattiva" }).click();
        await expect(adminPage.locator(`tr[data-voce="${id}"]`), "spenta, ma ancora in elenco").toContainText("disattivata", { timeout: 15_000 });

        // Togliere: sparisce, dalla pagina e dal catalogo.
        const rigaSpenta = adminPage.locator(`tr[data-voce="${id}"]`);
        await rigaSpenta.getByLabel(`Motivazione per ${code}`).fill(MOTIVAZIONE);
        await rigaSpenta.getByRole("button", { name: "Elimina" }).click();
        await expect(adminPage.locator(`tr[data-voce="${id}"]`), "tolta").toHaveCount(0, { timeout: 15_000 });
        expect(await idVoce(), "e non c'è più").toBeNull();
    });
});
