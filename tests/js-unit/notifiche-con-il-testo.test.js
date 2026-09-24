import { describe, it, expect, vi, beforeAll, beforeEach } from "vitest";

/**
 * Le notifiche arrivano con il loro testo e con il loro tipo (revisione
 * architetturale del 23/9/2026, A-21).
 *
 * `ToastManager.show` vuole `(tipo, titolo, testo)`. `verifica-builder.js`
 * lo chiamava `show(testo, tipo)`: il testo finiva al posto del tipo, il tipo
 * («ok», «warn») al posto del titolo, e nel pannello compariva la parola
 * «warn» senza il messaggio. E il wizard degli esercizi passa i tipi brevi
 * «err»/«warn»/«ok», che `show` non conosceva: i suoi errori uscivano come
 * avvisi. Qui si guarda che cosa arriva davvero al pannello (`notify`).
 */

const { notify } = vi.hoisted(() => ({ notify: vi.fn() }));
vi.mock("../../js/modules/ui/sync-panel.js", () => ({ notify }));

let ToastManager;

beforeAll(async () => {
    document.body.innerHTML = `
        <main id="fm-content">
            <button id="btnCopyeser" type="button">Copia</button>
            <input type="checkbox" class="js-pick-ex" data-id="7">
        </main>`;
    ({ ToastManager } = await import("../../js/modules/ui/toast.js"));
    await import("../../js/modules/features/verifica-builder.js");
    window.FM.initVerificaBuilder();
});

beforeEach(() => notify.mockClear());

/** L'ultima riga consegnata al pannello, per nome. */
function ultimaNotifica() {
    const chiamata = notify.mock.calls.at(-1);
    expect(chiamata, "nessuna notifica arrivata al pannello").toBeDefined();
    const [titolo, tipo, testo] = chiamata;
    return { titolo, tipo, testo };
}

describe("verifica-builder: la notifica porta il suo testo", () => {
    it("senza esercizi scelti, l'avviso dice che cosa manca", () => {
        document.getElementById("btnCopyeser").click();

        const n = ultimaNotifica();
        expect(n.testo).toBe("Nessun esercizio selezionato.");
        expect(n.titolo).toContain("⚠");
        expect(n.titolo).not.toContain("warn");
    });

    it("con un esercizio scelto e copiato, la conferma è un successo con il conteggio", async () => {
        const scrivi = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, "clipboard", { value: { writeText: scrivi }, configurable: true });
        const casella = document.querySelector(".js-pick-ex");
        casella.checked = true;
        casella.dispatchEvent(new Event("change", { bubbles: true }));

        document.getElementById("btnCopyeser").click();
        await vi.waitFor(() => expect(scrivi).toHaveBeenCalledWith("7"));
        await vi.waitFor(() => expect(notify).toHaveBeenCalled());

        const n = ultimaNotifica();
        expect(n.testo).toBe("Copiati 1 id negli appunti.");
        expect(n.tipo).toBe("ok");
    });
});

describe("ToastManager: i tipi brevi valgono quanto quelli lunghi", () => {
    it("«err» è un errore, non un avviso", () => {
        ToastManager.show("err", "Errore", "Creazione fallita");
        expect(ultimaNotifica()).toMatchObject({ tipo: "error", testo: "Creazione fallita" });
        expect(ultimaNotifica().titolo).toContain("✕");
    });

    it("«ok» è un successo e «warn» un avviso con la sua icona", () => {
        ToastManager.show("ok", "Fatto", "Gruppo creato");
        expect(ultimaNotifica()).toMatchObject({ tipo: "ok", testo: "Gruppo creato" });

        ToastManager.show("warn", "Attenzione", "Scegli un'origine");
        expect(ultimaNotifica()).toMatchObject({ tipo: "info", testo: "Scegli un'origine" });
        expect(ultimaNotifica().titolo).toContain("⚠");
    });

    it("i tipi lunghi restano come erano", () => {
        ToastManager.show("error", "Errore", "x");
        expect(ultimaNotifica().tipo).toBe("error");
        ToastManager.show("success", "Successo", "y");
        expect(ultimaNotifica().tipo).toBe("ok");
        ToastManager.show("info", "Info", "z");
        expect(ultimaNotifica().tipo).toBe("info");
    });
});
