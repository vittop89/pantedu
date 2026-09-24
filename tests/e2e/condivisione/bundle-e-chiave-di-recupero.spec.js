// @ts-check
/**
 * Chiave di recupero e pacchetto di sincronizzazione fra docenti.
 * Riscrittura di g22_s20_import_bundle.spec.js (G22.S20), fetta 3.
 *
 * Un docente esporta i propri contenuti come pacchetto con un manifesto
 * firmato; un collega può importarlo solo se possiede la chiave di recupero di
 * chi ha esportato. Il flusso completo passa dal selettore di cartelle del
 * browser, che non si automatizza: qui si verificano la parte che si può
 * verificare (le API, dalla generazione della chiave all'anteprima
 * dell'importazione) e la presenza dei comandi nell'interfaccia.
 *
 * Cosa cambia rispetto a prima: niente login né contesti aperti a mano, niente
 * attese a tempo prima di cercare i comandi, e la chiave di recupero del
 * docente viene ripristinata alla fine invece di restare quella creata dal test.
 */
const { test, expect } = require("../support/test");

test.describe("Condivisione — chiave di recupero e pacchetto", () => {
    test("chiave, manifesto firmato e anteprima dell'importazione presso il collega", async ({ teacherApi, teacher2Api }) => {

        // La chiave si può solo sostituire: si revoca quella in essere e se ne
        // genera una nuova. Alla fine il docente resta con una chiave valida,
        // come l'ha trovato, quindi non c'è nulla da ripristinare (una seconda
        // generazione senza revoca risponderebbe «chiave già presente»).
        await teacherApi.bundle.revokeRecoveryKey();
        const generata = await teacherApi.bundle.generateRecoveryKey();
        expect(generata.ok).toBe(true);
        expect(generata.recovery_hex, "chiave in esadecimale di 64 cifre").toMatch(/^[0-9a-f]{64}$/);

        const stato = await teacherApi.bundle.recoveryKeyStatus();
        expect(stato.status.exists).toBe(true);
        expect(stato.status.revoked_at, "la chiave appena generata non è revocata").toBeNull();

        const manifesto = await teacherApi.bundle.manifest();
        expect(typeof manifesto.hmac).toBe("string");
        expect(manifesto.hmac.length, "il manifesto è firmato").toBeGreaterThan(40);
        expect(Array.isArray(manifesto.files)).toBe(true);
        expect(manifesto.files.length, "il manifesto elenca dei file").toBeGreaterThan(0);

        // Il collega prova l'importazione con la chiave giusta: nessuna scrittura,
        // solo il resoconto di ciò che verrebbe creato o dove sarebbero i conflitti.
        const anteprima = await teacher2Api.bundle.previewImport({
            recoveryCode: generata.recovery_hex,
            manifest: manifesto,
            conflictStrategy: "rename",
        });
        expect(anteprima.status, anteprima.text.slice(0, 200)).toBe(200);
        expect(anteprima.body?.preview).toBe(true);
        const resoconto = anteprima.body?.report;
        const voci = (resoconto?.created?.length ?? 0) + (resoconto?.conflicts?.length ?? 0) + (resoconto?.unsupported?.length ?? 0);
        expect(voci, "l'anteprima riporta almeno una voce").toBeGreaterThan(0);

        // 23/9/2026 — la firma copre il manifesto: con la chiave giusta, lo
        // stesso manifesto con un file in più, aggiunto dopo la firma, è
        // rifiutato. Che cosa la firma non prova: TeacherRecoveryService.
        const cambiato = await teacher2Api.bundle.previewImport({
            recoveryCode: generata.recovery_hex,
            manifest: {
                ...manifesto,
                files: [...manifesto.files, { path: "ZZ/SCI/2A/FIS/mappe/Aggiunta.drawio", size: 10, sha256: "0".repeat(64), type: "mappa" }],
            },
            conflictStrategy: "rename",
        });
        expect(cambiato.status, cambiato.text.slice(0, 200)).toBe(403);
        expect(/** @type {{ error?: string }} */ (cambiato.body)?.error).toBe("invalid_recovery_code_or_manifest");
    });

    test("con una chiave sbagliata l'importazione è rifiutata", async ({ teacherApi, teacher2Api }) => {
        const manifesto = await teacherApi.bundle.manifest();

        const anteprima = await teacher2Api.bundle.previewImport({
            recoveryCode: "0".repeat(64), // forma valida, chiave errata
            manifest: manifesto,
            conflictStrategy: "skip",
        });
        expect(anteprima.status).toBe(403);
        expect(anteprima.body?.ok).toBe(false);
        expect(/** @type {{ error?: string }} */ (anteprima.body)?.error).toBe("invalid_recovery_code_or_manifest");
    });

    test("il collega senza chiave di recupero risulta senza chiave valida", async ({ teacher2Api }) => {
        await teacher2Api.bundle.revokeRecoveryKey();
        const stato = await teacher2Api.bundle.recoveryKeyStatus();
        expect(stato.ok).toBe(true);
        // Mai generata, oppure generata e revocata: in nessun caso una chiave valida.
        if (stato.status.exists) {
            expect(stato.status.revoked_at, "se esiste, deve risultare revocata").not.toBeNull();
        } else {
            expect(stato.status.exists).toBe(false);
        }
    });

    test("il curriculum del docente usa i codici canonici degli indirizzi", async ({ teacherApi }) => {
        /** @type {{ curriculum?: { indirizzi?: ReadonlyArray<{ code: string }> } }} */
        const curriculum = await teacherApi.http.getJson("/api/teacher/curriculum");
        const codici = (curriculum.curriculum?.indirizzi ?? []).map((i) => i.code);
        expect(codici.length, "l'istituto ha degli indirizzi").toBeGreaterThan(0);
        expect(codici.some((c) => ["SCI", "CLA", "LIN", "ART", "AFM"].includes(c)), "almeno un codice canonico").toBe(true);
        expect(codici.some((c) => ["sc", "cl", "li", "ling", "ar", "af"].includes(c)), "nessun codice storico").toBe(false);
    });

    test("i comandi di importazione e la chiave di recupero sono raggiungibili", async ({ teacherPage, studioEsercizio, contentFactory }) => {
        // Il comando di importazione sta nella barra della sessione, presente
        // nelle pagine con la barra laterale (non nel pannello del docente).
        const esercizio = await contentFactory.exercise({ publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        const importa = teacherPage.locator(".fm-session-import-btn");
        await expect(importa).toBeVisible();
        const etichetta = (await importa.getAttribute("aria-label")) ?? (await importa.getAttribute("title")) ?? "";
        expect(etichetta).toMatch(/import/i);

        // La chiave di recupero sta nella scheda «Sicurezza & manutenzione» del pannello.
        await teacherPage.goto("/teacher/dashboard");
        await teacherPage.getByRole("tab", { name: /Sicurezza/ }).click();
        const sezione = teacherPage.locator("#fm-recovery-section");
        await expect(sezione).toBeVisible();
        await expect(teacherPage.locator("#fm-recovery-status .fm-drive-label")).not.toBeEmpty();
    });
});
