// @ts-check
/**
 * Metriche esposte a Prometheus e tracciamento delle richieste.
 * Riscrittura di observability_metrics.spec.js e
 * observability_request_id.spec.js.
 *
 * Due cose che servono quando qualcosa va storto in produzione. Le metriche
 * dicono quanti utenti, quanti contenuti, quante richieste di cancellazione e
 * quanti accessi privilegiati: sono numeri che si guardano nel tempo, e
 * l'esposizione è protetta da un gettone perché dicono anche quanti account ci
 * sono e in che stato. Il numero di richiesta, invece, è quello che permette di
 * ritrovare nel registro la singola richiesta di cui un utente si sta
 * lamentando: viene assegnato a ognuna, si può imporre dall'esterno per
 * seguirla attraverso più sistemi, e se quel che arriva da fuori non è pulito
 * viene sostituito — un numero di richiesta finisce nei registri, e non deve
 * poter portarci dentro nient'altro.
 *
 * Cosa cambia rispetto a prima: il gettone delle metriche non è più scritto
 * nella spec. Stava lì in chiaro, come costante in testa al file: adesso lo
 * legge il client da `.env.local` e non lo restituisce a nessuno.
 */
const { test, expect } = require("../support/test");

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

test.describe("Osservabilità — metriche", () => {
    test("senza gettone le metriche non si leggono", async ({ teacherApi }) => {
        const risposta = await teacherApi.osservabilita.metricsSenzaGettone();
        expect(risposta.status(), "l'applicazione rifiuta").toBe(401);
        expect((await risposta.json()).error, "e dice perché").toBe("unauthorized");
    });

    test("con un gettone sbagliato nemmeno", async ({ teacherApi }) => {
        const risposta = await teacherApi.osservabilita.metricsConGettoneSbagliato();
        expect(risposta.status(), "l'applicazione rifiuta").toBe(401);
    });

    test("col gettone giusto arrivano, nel formato che Prometheus si aspetta", async ({ teacherApi }) => {
        const risposta = await teacherApi.osservabilita.metrics();
        expect(risposta.ok(), "le metriche si leggono").toBe(true);
        expect(risposta.headers()["content-type"], "nel formato dichiarato").toMatch(/text\/plain.*version=0\.0\.4/);

        const corpo = await risposta.text();
        expect(corpo, "ogni metrica è descritta").toMatch(/^# HELP pantedu_app_info/m);
        expect(corpo, "e dichiara il proprio tipo").toMatch(/^# TYPE pantedu_app_info gauge/m);
        expect(corpo, "con la versione dell'applicazione").toMatch(/^pantedu_app_info\{version="[^"]+"\} 1/m);
    });

    test("il gettone vale anche nell'indirizzo, per chi non può mandare intestazioni", async ({ teacherApi }) => {
        const risposta = await teacherApi.osservabilita.metricsConGettoneNellIndirizzo();
        expect(risposta.ok(), "le metriche si leggono lo stesso").toBe(true);
        expect(await risposta.text(), "e ci sono").toContain("pantedu_users_total");
    });

    test("ci sono le metriche che servono a sorvegliare l'istanza", async ({ teacherApi }) => {
        const corpo = await (await teacherApi.osservabilita.metrics()).text();

        for (const metrica of [
            "pantedu_app_info",
            "pantedu_users_total",
            "pantedu_teacher_content_total",
            "pantedu_consents_active_total",
            "pantedu_deletion_requests_total",
            "pantedu_dpo_requests_total",
            "pantedu_parent_consents_total",
            "pantedu_crypto_keys_total",
            "pantedu_privileged_access_24h_total",
        ]) {
            expect(corpo, `la metrica «${metrica}» è esposta`).toContain(metrica);
        }
    });

    test("il conteggio degli utenti è diviso per ruolo e per stato", async ({ teacherApi }) => {
        const corpo = await (await teacherApi.osservabilita.metrics()).text();
        expect(corpo, "ogni riga dice ruolo e stato").toMatch(/pantedu_users_total\{role="[^"]+",status="[^"]+"\} \d+/);
    });
});

test.describe("Osservabilità — numero di richiesta", () => {
    test("ogni richiesta ne riceve uno, diverso dalle altre", async ({ page }) => {
        const numeri = [];
        for (const indirizzo of ["/security", "/security", "/privacy/your-data"]) {
            const risposta = await page.request.get(indirizzo);
            const numero = risposta.headers()["x-request-id"];
            expect(numero, `${indirizzo} risponde con un numero di richiesta`).toMatch(UUID_V4);
            numeri.push(numero);
        }
        expect(new Set(numeri).size, "e tre richieste hanno tre numeri diversi").toBe(3);
    });

    test("un numero pulito imposto dall'esterno viene rispettato", async ({ page }) => {
        const numero = "traccia-del-cliente-12345";
        const risposta = await page.request.get("/security", { headers: { "X-Request-ID": numero } });
        expect(risposta.headers()["x-request-id"], "l'applicazione lo tiene").toBe(numero);
    });

    test("un numero che contiene marcatura viene sostituito", async ({ page }) => {
        const sporco = "cattivo<script>alert(1)</script>";
        const risposta = await page.request.get("/security", { headers: { "X-Request-ID": sporco } });
        const numero = risposta.headers()["x-request-id"];
        expect(numero, "non viene ripreso così com'è").not.toBe(sporco);
        expect(numero, "ma sostituito con uno generato").toMatch(UUID_V4);
    });
});
