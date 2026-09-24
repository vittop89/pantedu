// @ts-check
/**
 * Recupero di una mappa concettuale: il disegno viene ri-cifrato per chi la prende.
 * Riscrittura di g22_s25_mappa_pool_recover.spec.js, fetta 3.
 *
 * Il disegno di una mappa non sta nel database: è un file cifrato con la chiave
 * del docente, sotto una cartella intestata a lui. Quando un collega la recupera
 * dal pool deve ottenere un file proprio, ri-cifrato con la sua chiave: se il
 * recupero riusasse il file dell'autore, il giorno che l'autore perde la chiave
 * la copia diventerebbe illeggibile (è successo il 13 maggio 2026).
 *
 * Cosa cambia rispetto a prima: le due sessioni sono fixture, la mappa nasce
 * dalla factory e le due copie sono cancellate dal registro di pulizia invece
 * che da un blocco `finally`.
 */
const { test, expect } = require("../support/test");

test.describe("Condivisione — mappe concettuali", () => {
    test("la copia recuperata dal collega ha un disegno proprio, non quello dell'autore", async ({
        contentFactory, shareFactory, teacher2ShareFactory, teacherApi, teacher2Api,
    }) => {
        const mappa = await contentFactory.map();
        expect(mappa.blobPath, "il disegno dell'autore è salvato").toBeTruthy();

        const originale = await teacherApi.content.get(mappa.id);
        expect(originale.content.content_type).toBe("mappa");

        // Le mappe non hanno un contratto, quindi nessun blocco sul diritto d'autore.
        await shareFactory.setPoolSharing(mappa.id, true, { ripristina: false });

        const pool = await teacher2Api.share.poolMaterials({ content_type: "mappa" });
        const voce = pool.items.find((i) => i.source === "teacher_content" && i.id === mappa.id);
        expect(voce, `la mappa ${mappa.id} deve comparire nel pool del collega`).toBeTruthy();
        expect(voce?.content_type).toBe("mappa");
        expect(voce?.already_recovered).toBeFalsy();

        const copiaId = await teacher2ShareFactory.recoverFromPool(mappa.id, mappa.terna.materia);
        expect(copiaId).toBeGreaterThan(0);

        const copia = await teacher2Api.content.get(copiaId);
        expect(copia.content.content_type).toBe("mappa");
        const disegnoCopia = String(copia.content.map_blob_path ?? "");
        expect(disegnoCopia, "la copia ha il suo disegno").toBeTruthy();
        expect(disegnoCopia, "che non è il file dell'autore").not.toBe(mappa.blobPath);

        // Il percorso comincia con la cartella di chi possiede il file: deve essere cambiata.
        const cartellaAutore = mappa.blobPath.split("/")[0];
        expect(disegnoCopia.startsWith(`${cartellaAutore}/`), "il file non sta più nella cartella dell'autore").toBe(false);
    });
});
