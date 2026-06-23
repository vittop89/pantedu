/**
 * G24.faseB.2 — Strategy class per la risoluzione conflitti 409 nelle POST
 * con optimistic locking (header `If-Match: "v<N>"`).
 *
 * Strategy disponibili:
 *   - SilentRetryStrategy   — autosave background: retry trasparente
 *                              riprendendo la actual version dal body.
 *   - InteractivePromptStrategy — save manuale: dialog Reload/Overwrite
 *                                  per decisione informata utente.
 *
 * Le strategy implementano `resolve({ url, body, actual, requestor }) →
 * { action: "retry"|"reload"|"abort", ifMatchVersion?: number }`. Il chiamante
 * (`apiPost`) interpreta il risultato per decidere come procedere.
 *
 * Sostituisce il blocco condizionale `if (silent) ... else await window.FM.Dialog.confirm()`
 * inline in apiPost: separazione policy/mechanism + facilita override per
 * unit test e future strategy (es. auto-merge, no-prompt-on-readonly, ecc).
 */

/** Strategy interface (duck-typed): { name: string, resolve(ctx) → action } */

/** Autosave background: ritorna sempre {action:"retry"} con actual version. */
export const silentRetryStrategy = {
    name: "silent-retry",
    /** @param {{ url:string, body:object, actual:number|null }} ctx */
    resolve(ctx) {
        if (ctx.actual == null) return { action: "abort", reason: "no-actual-version" };
        return { action: "retry", ifMatchVersion: ctx.actual };
    },
};

/** Save manuale: chiede all'utente Sovrascrivi / Ricarica.
 *  Usa `window.confirm()` per default; iniettabile per test/varianti. */
export function interactivePromptStrategy({
    promptFn = (msg) => (typeof window !== "undefined" ? window.confirm(msg) : false),
    message = "Conflitto di versione: il contenuto è stato modificato in un'altra sessione/tab.\n\n" +
              "OK → Sovrascrivi con le tue modifiche (perde l'altra sessione)\n" +
              "Annulla → Ricarica la pagina (perde le tue modifiche non salvate)",
} = {}) {
    return {
        name: "interactive-prompt",
        /** @param {{ url:string, body:object, actual:number|null }} ctx */
        resolve(ctx) {
            if (ctx.actual == null) return { action: "abort", reason: "no-actual-version" };
            const overwrite = promptFn(message);
            if (overwrite) return { action: "retry", ifMatchVersion: ctx.actual };
            return { action: "reload" };
        },
    };
}

/** Helper: applica la strategy appropriata (silent true → silent-retry,
 *  false → interactive-prompt con confirm default). Future: registry by
 *  name + plug-in custom (auto-merge per field-specific conflicts). */
export function resolveByMode(silent, ctx) {
    const strategy = silent
        ? silentRetryStrategy
        : interactivePromptStrategy();
    return strategy.resolve(ctx);
}
