/**
 * Client della biblioteca dei modelli TikZ (TikzController, TikzDataController).
 *
 * Responsabilità: leggere gli elementi di un gruppo e crearne, rinominarne e
 * cancellarne uno. Le tre mutazioni sono riservate agli amministratori; la
 * lettura è aperta a chi ha una sessione.
 *
 * Può importare: http, types. Non può importare: pagine, fixture, factory.
 */
import type { HttpClient } from "./http";

/** Un elemento della biblioteca: l'etichetta è quella che compare nel menu TeX. */
export interface TikzElement {
    readonly label?: string;
    readonly code?: string;
    readonly type?: string;
}

/** `GET /modelli_tikz_elements.json` → `{ "gruppo-X": [ {label, code}, … ] }`. */
export type TikzLibrary = Readonly<Record<string, readonly TikzElement[]>>;

interface TikzResult {
    readonly success?: boolean;
    readonly error?: string;
}

/** Un errore di pdflatex come lo manda `/tikz/render` (ErroriTex, 24/9/2026). */
export interface TikzTexError {
    readonly line: number | null;
    readonly message: string;
    readonly context: string;
}

/** La risposta di `POST /tikz/render`: l'SVG, o il log con gli errori. */
export interface TikzRender {
    readonly status: number;
    readonly svg: string;
    readonly error?: string;
    readonly log?: string;
    readonly errors?: readonly TikzTexError[];
}

export class TikzApi {
    constructor(private readonly http: HttpClient) {}

    async library(): Promise<TikzLibrary> {
        return this.http.getJson<TikzLibrary>("/modelli_tikz_elements.json");
    }

    /** Le etichette di un gruppo, nell'ordine in cui il menu le mostra. */
    async labels(group: string): Promise<readonly string[]> {
        const library = await this.library();
        return (library[group] ?? []).map((e) => e.label ?? "");
    }

    async saveNew(fields: {
        readonly existingGroup: string;
        readonly label: string;
        readonly code: string;
        readonly elementType?: string;
    }): Promise<TikzResult> {
        return this.http.postForm<TikzResult>("/tikz/save-new-element", {
            groupName: "",
            existingGroup: fields.existingGroup,
            elementType: fields.elementType ?? "tikz",
            label: fields.label,
            code: fields.code,
        });
    }

    async edit(fields: {
        readonly groupName: string;
        readonly elementLabel: string;
        readonly label: string;
        readonly code: string;
        readonly elementType?: string;
    }): Promise<TikzResult> {
        return this.http.postForm<TikzResult>("/tikz/edit-element", {
            groupName: fields.groupName,
            elementLabel: fields.elementLabel,
            elementType: fields.elementType ?? "tikz",
            label: fields.label,
            code: fields.code,
        });
    }

    /**
     * Compila un disegno per l'anteprima (`POST /tikz/render`), senza lanciare:
     * 200 con l'SVG, oppure 422 con `log` ed `errors`.
     */
    async render(tikz: string, scope = "public"): Promise<TikzRender> {
        const esito = await this.http.send<{ error?: string; log?: string; errors?: TikzTexError[] }>(
            "POST", "/tikz/render", { json: { tikz, scope } },
        );
        const corpo = esito.status === 200 ? {} : (esito.body ?? {});
        return { status: esito.status, svg: esito.status === 200 ? esito.text : "", ...corpo };
    }

    /** Cancellazione senza lancio: la pulizia gira anche quando l'elemento non c'è più. */
    async remove(groupName: string, elementLabel: string): Promise<TikzResult> {
        const esito = await this.http.send<TikzResult>("POST", "/tikz/delete-element", {
            form: { groupName, deleteWholeGroup: "false", elementLabel },
        });
        return esito.body ?? {};
    }
}
