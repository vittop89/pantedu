import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";

/**
 * Segnalazione dell'utente (20/9/2026): entrato nel sito come docente, nella
 * barra non c'era né il «+» per creare né la ✎ per modificare; dopo aver
 * ricaricato la pagina la ✎ è tornata.
 *
 * Il segno di permesso è un bottone che **il server** mette dentro ogni
 * pannello (`views/partials/sidebar.php`, solo a docenti e amministratori).
 * Tutto il resto dei comandi nasce da lì: il disegnatore del pannello clona
 * quel bottone nell'intestazione della materia e, solo se lo trova, aggiunge
 * il «+». Ogni ripulitura del pannello lo mette da parte apposta
 * (`cleanSidepageKeepingEditBtn`) — tranne una: quando la classe non è ancora
 * scelta il pannello veniva svuotato con `innerHTML = ""`, che porta via anche
 * il segno. Al primo caricamento, con i menu ancora vuoti, il pannello restava
 * senza permessi per il resto della visita; ricaricare rimetteva il bottone
 * del server al suo posto, e i comandi tornavano.
 *
 * Il caso gemello era già stato corretto nel disegnatore per categoria («niente
 * fetch finché non c'è scope», con la ripulitura che conserva il bottone): qui
 * si misura quello per materia, che è il pannello degli Esercizi.
 */

const flush = () => new Promise((r) => setTimeout(r, 0));

/** La barra come la manda il server a un docente, con i menu ancora da scegliere. */
function barraDelDocente({ indirizzo = "", classe = "", materia = "" } = {}) {
    document.body.className = "fm-can-edit";
    document.body.innerHTML = `
        <select id="sel-iis"><option value="">—</option><option value="SCI">Scientifico</option></select>
        <select id="sel-cls"><option value="">—</option><option value="3">Classe III</option></select>
        <select id="sel-mater"><option value="">—</option><option value="FIS">Fisica</option></select>
        <div id="fm-sp-eser" class="fm-sb-panel" data-sidepage="eser" data-group-mode="subject">
            <button class="fm-btn fm-btn--xs js-edit-section" type="button"
                    data-action="toggle-edit-section"><strong>✎</strong></button>
        </div>`;
    document.getElementById("sel-iis").value = indirizzo;
    document.getElementById("sel-cls").value = classe;
    document.getElementById("sel-mater").value = materia;
    return document.getElementById("fm-sp-eser");
}

/** Un esercizio solo, per la materia chiesta. */
function reteFinta() {
    return vi.fn(async (url) => ({
        ok: true,
        status: 200,
        headers: { get: () => null },
        json: async () => ({
            ok: true,
            count: 1,
            rows: [{ id: 48, topic: "3.0", title: "Moto rettilineo", content_type: "esercizio", subject_code: "FIS" }],
        }),
    }));
}

let caricaPannello;

beforeEach(async () => {
    globalThis.fetch = reteFinta();
    if (!caricaPannello) {
        await import("../../js/modules/features/db-sidepage.js");
        caricaPannello = window.FM.loadDbSidepageContent;
    }
});

afterEach(() => {
    vi.restoreAllMocks();
    delete globalThis.fetch;
    document.body.className = "";
    document.body.innerHTML = "";
});

describe("Barra laterale — i comandi di chi può scrivere", () => {
    it("senza la classe scelta il pannello si svuota, ma il segno di permesso resta", async () => {
        const pannello = barraDelDocente();

        await caricaPannello("eser", "esercizio");
        await flush();

        expect(pannello.querySelector(".js-edit-section"), "il bottone del server è ancora lì").not.toBeNull();
        expect(pannello.querySelector("ul.fm-db-block"), "e il pannello non ha contenuti").toBeNull();
    });

    it("scelta la classe, l'intestazione della materia ha la ✎ e il «+»", async () => {
        const pannello = barraDelDocente();
        // Primo giro a menu vuoti, come succede appena si apre la pagina.
        await caricaPannello("eser", "esercizio");
        await flush();

        // Poi la terna arriva (dalla memoria del browser o dalla scelta).
        document.getElementById("sel-iis").value = "SCI";
        document.getElementById("sel-cls").value = "3";
        document.getElementById("sel-mater").value = "FIS";
        await caricaPannello("eser", "esercizio");
        await flush();

        const testa = pannello.querySelector("ul.fm-db-block .fm-db-head");
        expect(testa, "l'intestazione della materia c'è").not.toBeNull();
        expect(testa.querySelector(".js-edit-section"), "con la ✎").not.toBeNull();
        const piu = testa.querySelector(".fm-section-add");
        expect(piu, "e con il «+»").not.toBeNull();
        expect(piu.dataset.fmType).toBe("esercizio");
        expect(piu.dataset.fmSubj).toBe("FIS");
    });

    it("senza il segno di permesso non nasce nessun comando", async () => {
        const pannello = barraDelDocente({ indirizzo: "SCI", classe: "3", materia: "FIS" });
        // Chi studia riceve il pannello senza il bottone del server.
        pannello.querySelector(".js-edit-section").remove();

        await caricaPannello("eser", "esercizio");
        await flush();

        const testa = pannello.querySelector("ul.fm-db-block .fm-db-head");
        expect(testa, "l'intestazione della materia c'è lo stesso").not.toBeNull();
        expect(testa.querySelector(".fm-section-add"), "ma nessun «+»").toBeNull();
        expect(testa.querySelector(".js-edit-section"), "e nessuna ✎").toBeNull();
    });
});
