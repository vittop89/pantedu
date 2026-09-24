// @ts-check
/**
 * Una copia appena fatta non è una copia vecchia.
 *
 * Nel catalogo dei modelli (`/admin/templates`) ogni riga conta le copie
 * personali dei docenti e quante di quelle sono «da riallineare», cioè fatte a
 * partire da una versione del modello che nel frattempo è cambiata. Il
 * confronto è fra l'impronta del modello e quella registrata sulla copia.
 *
 * Fino al 21/9/2026 il riquadro TEX/PDF, salvando, registrava al posto
 * dell'impronta la stringa `manual-<data>`: non coincide con nessuna impronta,
 * quindi ogni file salvato da lì nasceva marcato «da riallineare» e ci restava
 * per sempre. Misurato in produzione: sul «Piano annuale» otto copie
 * disallineate su otto, quattro delle quali scritte quel giorno stesso dalla
 * versione corrente.
 *
 * Qui si misura il verso che mancava: salvo un file e la copia NON risulta da
 * riallineare. L'altro verso — quando il modello cambia davvero, la copia
 * risulta vecchia — sta in `tests/Integration/RisdocTemplateRepositoryTest.php`,
 * dove si può cambiare l'impronta del modello.
 */
const { test, expect } = require("../support/test");

test.describe("Risdoc — le copie dei file di un modello", () => {
    test("un file salvato adesso non risulta da riallineare", async ({ adminApi, naming, cleanup }) => {
        const { id } = await adminApi.risdoc.unTemplate({ origin: "risdoc" });
        const marcatore = naming.unique("copia").toUpperCase();
        const percorso = "texCommon/risdoc-istituto.tex";

        const salvato = await adminApi.http.send("POST", `/api/risdoc/templates/${id}/tex-files/save`, {
            json: { files: [{ path: percorso, content: `% ${marcatore}\n` }] },
        });
        expect(salvato.ok, `salvataggio del file → ${salvato.status}`).toBe(true);
        cleanup.add("admin", `toglie la copia di ${percorso} dal modello ${id}`, async () => {
            await adminApi.risdoc.deleteOverride(id, {
                kind: "texCommon", path: "risdoc-istituto.tex", instance_key: "",
            });
        });

        const disallineate = await adminApi.http.send("GET", "/api/admin/risdoc/drift");
        expect(disallineate.status, "l'elenco delle copie da riallineare risponde").toBe(200);

        const righe = /** @type {{drifted?: Array<Record<string, unknown>>}} */ (disallineate.body)?.drifted ?? [];
        const laMia = righe.filter((r) => Number(r["template_id"]) === id
            && String(r["relative_path"]) === "risdoc-istituto.tex");

        expect(laMia, `una copia appena fatta non è vecchia: ${JSON.stringify(laMia)}`).toEqual([]);
    });
});
