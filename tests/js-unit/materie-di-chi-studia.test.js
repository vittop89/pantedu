import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from "vitest";

/**
 * Le materie di chi studia, nel browser (19/9/2026).
 *
 * Il server disegna nel selettore solo le materie con materiali per la classe
 * di chi guarda (tests/Integration/MaterieDiChiStudiaTest.php). Qui la parte
 * del browser: al cambio di classe il selettore si ricalcola da
 * /api/study/materie.json, e l'avviso prende il suo posto quando non resta
 * niente; i pannelli chiedono i contenuti solo per quelle materie, e con un
 * elenco vuoto non ne chiedono nessuno invece di chiederle tutte.
 */

let chieste = [];
let risposte = [];
const finto = vi.fn(async (url) => {
    const u = new URL(String(url), "http://localhost");
    chieste.push(u);
    let corpo = { ok: true, rows: [] };
    if (u.pathname === "/api/study/materie.json") {
        corpo = risposte.shift() ?? { ok: true, filtrate: true, materie: [] };
    } else if (u.pathname === "/curriculum") {
        // Il catalogo della scuola: è il ripiego di db-sidepage quando il
        // selettore non ha voci.
        corpo = { curriculum: { materie: ["ZMA", "ZFI", "ZGE", "ZIT"].map((code) => ({ code, label: code })) } };
    }
    return new Response(JSON.stringify(corpo), { status: 200, headers: { "Content-Type": "application/json" } });
});

let modulo;
let catalogo;
beforeAll(async () => {
    globalThis.fetch = finto;
    // fm-url-state.js registra la navigazione con un beacon: qui andrebbe
    // davvero in rete (e la rete non c'e'). Si conta e basta.
    Object.defineProperty(globalThis.navigator, "sendBeacon", { value: vi.fn(() => true), configurable: true });
    modulo = await import("../../js/modules/features/materie-di-chi-studia.js");
    await import("../../js/modules/features/db-sidepage.js");
    // db-sidepage fa il suo primo giro dopo 300 ms. Se il file finisce prima,
    // il giro parte a ambiente smontato e cade su `document` (24/9/2026, rosso
    // di CI su #291 senza nessuna prova fallita): lo si lascia passare qui.
    await new Promise((r) => setTimeout(r, 350));
    catalogo = await import("../../js/modules/core/curriculum-codes.js");
    // Chi arriva sull'indirizzo di una pagina di studio passa di qui: la terna
    // dell'URL finisce nei selettori. Si importa per ultimo, cosi' il cambio di
    // classe che emette trova gia' in ascolto materie-di-chi-studia.
    await import("../../js/fm-url-state.js");
});

function pagina({ diChiStudia = true, materie = ["ZMA"] } = {}) {
    const opzioni = materie.map((m) => `<option value="${m}">${m}</option>`).join("");
    document.body.innerHTML = `
        <nav class="sidebar">
            <select id="sel-iis" hidden><option value="ZSM" selected></option></select>
            <select id="sel-cls" data-fm-classi-frequentate="1">
                <option value="2" selected>Seconda</option><option value="1">Prima</option>
            </select>
            <p id="fm-materie-avviso" hidden>Non ci sono ancora materiali pubblicati per la tua classe.</p>
            <label for="sel-mater">Materia:</label>
            <select id="sel-mater" ${diChiStudia ? 'data-fm-materie-di-chi-studia="1"' : ""}>
                <option value="" disabled selected>Scegli la materia:</option>${opzioni}
            </select>
            <div class="fm-sb-panel" id="fm-sp-mappe" data-sidepage="mappe"></div>
        </nav>`;
}

const valori = () => Array.from(document.getElementById("sel-mater").options).map((o) => o.value).filter(Boolean);
const materieChieste = () => chieste.filter((u) => u.pathname === "/api/study/materie.json");
const contenutiChiesti = () => chieste.filter((u) => u.pathname === "/api/study/content.json");

beforeEach(() => {
    chieste = [];
    risposte = [];
});

afterEach(() => {
    document.body.innerHTML = "";
});

describe("al cambio di classe", () => {
    it("chiede le materie della classe e ridisegna il selettore, e lo annuncia", async () => {
        pagina();
        risposte.push({ ok: true, filtrate: true, materie: [{ code: "ZFI", label: "Fisica" }, { code: "ZGE", label: "Geografia" }] });
        const annunci = [];
        document.getElementById("sel-mater").addEventListener("change", (e) => annunci.push(e.detail?.fmSource));

        expect(await modulo.ricarica("1", "ZSM")).toBe(true);

        const [u] = materieChieste();
        expect(u.searchParams.get("classe")).toBe("1");
        expect(u.searchParams.get("indirizzo")).toBe("ZSM");
        expect(valori()).toEqual(["ZFI", "ZGE"]);
        expect(document.getElementById("sel-mater").hidden).toBe(false);
        expect(document.getElementById("fm-materie-avviso").hidden).toBe(true);
        expect(annunci, "i pannelli sanno che le materie sono cambiate").toEqual(["materie-di-chi-studia"]);
    });

    it("senza materie il selettore lascia il posto all'avviso, e con una torna", async () => {
        pagina();
        risposte.push({ ok: true, filtrate: true, materie: [] });
        await modulo.ricarica("1", "ZSM");
        expect(valori()).toEqual([]);
        expect(document.getElementById("sel-mater").hidden).toBe(true);
        expect(document.querySelector('label[for="sel-mater"]').hidden).toBe(true);
        expect(document.getElementById("fm-materie-avviso").hidden).toBe(false);

        risposte.push({ ok: true, filtrate: true, materie: [{ code: "ZMA", label: "Matematica" }] });
        await modulo.ricarica("2", "ZSM");
        expect(valori()).toEqual(["ZMA"]);
        expect(document.getElementById("sel-mater").hidden).toBe(false);
        expect(document.getElementById("fm-materie-avviso").hidden).toBe(true);
    });

    it("parte dal cambio del selettore della classe", async () => {
        pagina();
        risposte.push({ ok: true, filtrate: true, materie: [{ code: "ZFI", label: "Fisica" }] });
        const cls = document.getElementById("sel-cls");
        cls.value = "1";
        cls.dispatchEvent(new Event("change", { bubbles: true }));
        await vi.waitFor(() => expect(valori()).toEqual(["ZFI"]));
        expect(materieChieste()).toHaveLength(1);
    });

    it("per docenti e amministratori non chiede niente", async () => {
        pagina({ diChiStudia: false, materie: ["ZMA", "ZFI"] });
        expect(await modulo.ricarica("1", "ZSM")).toBe(false);
        const cls = document.getElementById("sel-cls");
        cls.value = "1";
        cls.dispatchEvent(new Event("change", { bubbles: true }));
        await new Promise((r) => setTimeout(r, 0));
        expect(materieChieste()).toHaveLength(0);
        expect(valori()).toEqual(["ZMA", "ZFI"]);
    });

    it("una risposta arrivata dopo una domanda più recente si scarta", async () => {
        pagina();
        let sblocca;
        const lenta = new Promise((r) => { sblocca = r; });
        const fetchImpl = vi.fn()
            .mockImplementationOnce(async () => { await lenta; return new Response(JSON.stringify({ ok: true, filtrate: true, materie: [{ code: "ZVE", label: "Vecchia" }] })); })
            .mockImplementationOnce(async () => new Response(JSON.stringify({ ok: true, filtrate: true, materie: [{ code: "ZNU", label: "Nuova" }] })));
        const prima = modulo.ricarica("1", "ZSM", { fetchImpl });
        const seconda = modulo.ricarica("2", "ZSM", { fetchImpl });
        expect(await seconda).toBe(true);
        sblocca();
        expect(await prima).toBe(false);
        expect(valori()).toEqual(["ZNU"]);
    });
});

describe("i pannelli chiedono solo le materie del selettore", () => {
    it("una richiesta per materia offerta, non una per materia della scuola", async () => {
        pagina({ materie: ["ZMA", "ZFI"] });
        await window.FM.loadDbSidepageContent("mappe", "mappa");
        expect(contenutiChiesti().map((u) => u.searchParams.get("subject")).sort()).toEqual(["ZFI", "ZMA"]);
    });

    it("con un elenco vuoto nessuna richiesta, invece del catalogo intero", async () => {
        await catalogo.loadCurriculum(true);
        expect(catalogo.codesFor("materie"), "il ripiego avrebbe quattro materie da chiedere").toHaveLength(4);

        pagina({ materie: [] });
        await window.FM.loadDbSidepageContent("mappe", "mappa");
        expect(contenutiChiesti()).toHaveLength(0);

        // Nell'altro verso: senza il segno (docente) il ripiego resta com'era.
        pagina({ diChiStudia: false, materie: [] });
        await window.FM.loadDbSidepageContent("mappe", "mappa");
        expect(contenutiChiesti()).toHaveLength(4);
    });
});

describe("la materia dell'URL, quando le opzioni arrivano dopo", () => {
    /**
     * La barra come la disegna il server a chi studia in terza: le materie
     * sono quelle della *sua* classe, e la seconda (un anno gia' fatto) e'
     * nelle classi frequentate.
     */
    function paginaDiStudio() {
        document.body.innerHTML = `
            <nav class="sidebar">
                <select id="sel-iis"><option value="ZSM" selected>ZSM</option></select>
                <select id="sel-cls" data-fm-classi-frequentate="1">
                    <option value="3" selected>Terza</option><option value="2">Seconda</option>
                </select>
                <p id="fm-materie-avviso" hidden>Non ci sono ancora materiali pubblicati per la tua classe.</p>
                <label for="sel-mater">Materia:</label>
                <select id="sel-mater" data-fm-materie-di-chi-studia="1">
                    <option value="" disabled selected>Scegli la materia:</option>
                    <option value="ZST">Storia</option>
                </select>
            </nav>`;
    }

    const selMater = () => document.getElementById("sel-mater");

    it("arrivando sull'indirizzo di un anno gia' fatto, la materia dell'URL resta scelta", async () => {
        // Il difetto: fm-url-state.js cambia la classe (il selettore delle
        // materie si ricalcola, asincrono) e subito dopo prova a scegliere la
        // materia contro le opzioni ancora vecchie — quelle della terza, che
        // fisica non ce l'hanno. Senza il segno la materia si perdeva, e i
        // pannelli chiedevano i contenuti di TUTTE le materie della seconda.
        paginaDiStudio();
        risposte.push({ ok: true, filtrate: true, materie: [{ code: "ZFI", label: "Fisica" }, { code: "ZGE", label: "Geografia" }] });

        window.dispatchEvent(new CustomEvent("fm:navigated", { detail: { url: "/studio/esercizio/ZSM/2/ZFI/scheda.php" } }));

        await vi.waitFor(() => expect(valori()).toEqual(["ZFI", "ZGE"]));
        expect(document.getElementById("sel-cls").value, "la classe dell'URL").toBe("2");
        expect(selMater().value, "e la materia dell'URL").toBe("ZFI");
        expect(selMater().dataset.fmValoreAtteso, "il segno si consuma").toBeUndefined();
    });

    it("il segno vale una volta sola: al cambio di classe seguente la materia non torna", async () => {
        paginaDiStudio();
        risposte.push({ ok: true, filtrate: true, materie: [{ code: "ZFI", label: "Fisica" }, { code: "ZGE", label: "Geografia" }] });
        window.dispatchEvent(new CustomEvent("fm:navigated", { detail: { url: "/studio/esercizio/ZSM/2/ZFI/scheda.php" } }));
        await vi.waitFor(() => expect(selMater().value).toBe("ZFI"));

        // Adesso la classe la sceglie lo studente, e li' fisica non c'e'.
        risposte.push({ ok: true, filtrate: true, materie: [{ code: "ZST", label: "Storia" }, { code: "ZMA", label: "Matematica" }] });
        expect(await modulo.ricarica("1", "ZSM")).toBe(true);
        expect(valori()).toEqual(["ZMA", "ZST"]);
        expect(selMater().value, "nessuna materia scelta al posto di chi studia").toBe("");
    });

    it("nell'altro verso: se la materia dell'URL c'e' gia' fra le opzioni si sceglie subito, senza segni e senza ricalcoli", () => {
        paginaDiStudio();

        window.dispatchEvent(new CustomEvent("fm:navigated", { detail: { url: "/studio/esercizio/ZSM/3/ZST/scheda.php" } }));

        expect(selMater().value).toBe("ZST");
        expect(selMater().dataset.fmValoreAtteso, "niente da attendere").toBeUndefined();
        expect(materieChieste(), "la classe non cambia: niente da ricalcolare").toHaveLength(0);
    });
});
