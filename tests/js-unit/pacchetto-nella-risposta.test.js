import { describe, it, expect, vi, afterEach } from "vitest";
import {
    pacchettoDallaRisposta, nomeDallIntestazione, scaricaPacchetto,
} from "../../js/modules/core/pacchetto.js";

/**
 * Il pacchetto arriva nella risposta, non per indirizzo.
 *
 * Decisione dell'utente del 21/9/2026: l'esportazione non scrive più il
 * pacchetto su disco. La conseguenza per il client è che la stessa rotta
 * risponde in due modi — l'archivio quando va bene, un JSON quando c'è un
 * errore da mostrare al docente — e chi chiama non deve indovinare quale.
 *
 * Il caso che conta più di tutti è il secondo: «questo documento non ha un
 * corpo da esportare» è un messaggio che il docente legge parola per parola e
 * che gli dice che cosa fare. Se si perdesse dietro un «HTTP 400», la
 * correzione avrebbe tolto un file e rotto un messaggio.
 */

function risposta({ tipo, corpo, stato = 200, disposition = null }) {
    return {
        ok: stato >= 200 && stato < 300,
        status: stato,
        headers: {
            get: (k) => {
                const key = String(k).toLowerCase();
                if (key === "content-type") return tipo;
                if (key === "content-disposition") return disposition;
                return null;
            },
        },
        json: async () => corpo,
        blob: async () => corpo,
    };
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe("La risposta di un'esportazione", () => {
    it("quando è un archivio, torna il pacchetto col suo nome", async () => {
        const finto = { tipoFinto: "blob" };
        const p = await pacchettoDallaRisposta(risposta({
            tipo: "application/zip",
            corpo: finto,
            disposition: 'attachment; filename="Piano_annuale.zip"',
        }));

        expect(p.blob).toBe(finto);
        expect(p.nome).toBe("Piano_annuale.zip");
    });

    it("senza il nome dal server resta quello di ripiego", async () => {
        const p = await pacchettoDallaRisposta(
            risposta({ tipo: "application/zip", corpo: {} }), "modello-16.zip");

        expect(p.nome).toBe("modello-16.zip");
    });

    it("quando è un errore, arriva il messaggio del server e non «HTTP 400»", async () => {
        await expect(pacchettoDallaRisposta(risposta({
            tipo: "application/json; charset=UTF-8",
            corpo: { error: "no_body_pt" },
            stato: 400,
        }))).rejects.toThrow("no_body_pt");
    });

    it("un JSON illeggibile non fa sparire l'errore", async () => {
        const rotta = risposta({ tipo: "application/json", corpo: null, stato: 500 });
        rotta.json = async () => { throw new Error("corpo a pezzi"); };

        await expect(pacchettoDallaRisposta(rotta)).rejects.toThrow(/500/);
    });

    it("una risposta non riuscita che non è JSON non passa per buona", async () => {
        // È il caso in cui qualcosa davanti all'applicazione risponde con una
        // pagina: senza questo controllo si scaricherebbe quella pagina
        // chiamandola pacchetto.
        await expect(pacchettoDallaRisposta(risposta({
            tipo: "text/html", corpo: {}, stato: 502,
        }))).rejects.toThrow(/502/);
    });
});

describe("Il nome del file", () => {
    it("si legge dall'intestazione, con o senza virgolette", () => {
        expect(nomeDallIntestazione('attachment; filename="a b.zip"', "x.zip")).toBe("a b.zip");
        expect(nomeDallIntestazione("attachment; filename=senza-virgolette.zip", "x.zip"))
            .toBe("senza-virgolette.zip");
    });

    it("senza intestazione, o con una che non dice niente, resta il ripiego", () => {
        expect(nomeDallIntestazione(null, "ripiego.zip")).toBe("ripiego.zip");
        expect(nomeDallIntestazione("attachment", "ripiego.zip")).toBe("ripiego.zip");
    });
});

describe("Il salvataggio nel browser", () => {
    it("non lascia in giro nemmeno l'indirizzo temporaneo", () => {
        vi.useFakeTimers();
        const creato = [];
        const revocati = [];
        globalThis.URL.createObjectURL = vi.fn(() => {
            const u = `blob:finto/${creato.length}`;
            creato.push(u);
            return u;
        });
        globalThis.URL.revokeObjectURL = vi.fn((u) => revocati.push(u));

        scaricaPacchetto({ blob: new Blob(["PK"]), nome: "prova.zip" });

        expect(creato).toHaveLength(1);
        expect(revocati, "prima che passi il tempo, ancora niente").toEqual([]);
        vi.advanceTimersByTime(1500);
        expect(revocati, "e poi si revoca").toEqual(creato);
        expect(document.querySelector("a[download]"), "il collegamento non resta in pagina").toBeNull();

        vi.useRealTimers();
    });
});
