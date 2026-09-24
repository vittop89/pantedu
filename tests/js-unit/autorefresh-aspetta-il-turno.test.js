import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";

/**
 * Segnalazione dell'utente (21/9/2026): «quando entro come admin nelle varie
 * pagine, ogni tot secondi mi ricarica la pagina e flickera, perdendo tutte le
 * informazioni inserite».
 *
 * Era `data-fm-autorefresh`, e l'unica pagina che lo chiede è il cruscotto del
 * WAF: dieci secondi, per i contatori dal vivo. Finché lì non c'era niente da
 * scrivere non faceva danno; da quando c'è il filtro delle richieste (stesso
 * giorno), ricaricare mentre si compila butta via quello che si sta
 * scrivendo — e la riga del filtro è proprio la cosa che si va a scrivere
 * quando si cerca perché uno studente non è entrato.
 *
 * Qui si misura la regola nuova: il giro aspetta il suo turno.
 */

let armAutorefresh;

beforeEach(async () => {
    vi.useFakeTimers();
    document.body.replaceChildren();
    ({ armAutorefresh } = await import("../../js/modules/core/declarative.js"));
});

afterEach(() => {
    vi.useRealTimers();
});

/** Il pezzo di pagina che chiede il ricaricamento, come nel cruscotto. */
function contatoreCheSiAggiorna(ms = 1000) {
    const el = document.createElement("div");
    el.setAttribute("data-fm-autorefresh", String(ms));
    document.body.appendChild(el);
    return el;
}

describe("data-fm-autorefresh", () => {
    it("ricarica quando nessuno sta scrivendo", () => {
        contatoreCheSiAggiorna();
        const ricarica = vi.fn();

        armAutorefresh(document, { ricarica });
        vi.advanceTimersByTime(1000);

        expect(ricarica).toHaveBeenCalledTimes(1);
    });

    it("non ricarica mentre il fuoco è in un campo, e riprova dopo", () => {
        contatoreCheSiAggiorna();
        const campo = document.createElement("input");
        document.body.appendChild(campo);
        campo.focus();
        const ricarica = vi.fn();

        armAutorefresh(document, { ricarica });
        vi.advanceTimersByTime(5000);
        expect(ricarica, "mentre si scrive, mai").not.toHaveBeenCalled();

        campo.blur();
        vi.advanceTimersByTime(1000);
        expect(ricarica, "finito di scrivere, il giro riprende").toHaveBeenCalledTimes(1);
    });

    it("non ricarica una scheda che non è in primo piano", () => {
        contatoreCheSiAggiorna();
        const ricarica = vi.fn();
        const doc = { visibilityState: "hidden", activeElement: null, body: document.body };

        armAutorefresh(document, { ricarica, doc });
        vi.advanceTimersByTime(4000);
        expect(ricarica).not.toHaveBeenCalled();

        doc.visibilityState = "visible";
        vi.advanceTimersByTime(1000);
        expect(ricarica).toHaveBeenCalledTimes(1);
    });

    it("un elemento tolto dalla pagina non ricarica più niente", () => {
        const el = contatoreCheSiAggiorna();
        const ricarica = vi.fn();

        armAutorefresh(document, { ricarica });
        el.remove();
        vi.advanceTimersByTime(10_000);

        expect(ricarica).not.toHaveBeenCalled();
    });

    it("armare due volte non raddoppia i giri", () => {
        contatoreCheSiAggiorna();
        const ricarica = vi.fn();

        armAutorefresh(document, { ricarica });
        armAutorefresh(document, { ricarica });
        vi.advanceTimersByTime(1000);

        expect(ricarica).toHaveBeenCalledTimes(1);
    });
});
