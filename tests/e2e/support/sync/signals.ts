/**
 * Segnali dell'app: eventi `fm:*` contati dentro la pagina.
 *
 * Responsabilità: un registratore installato prima di ogni script della
 * pagina (addInitScript) conta ogni evento il cui tipo inizia con `fm:`,
 * su qualunque bersaglio (window, document, elementi); le attese leggono il
 * contatore con waitForFunction, quindi non c'è corsa fra emissione e
 * ascolto. I punti di emissione sono elencati nel blueprint §6.6.
 *
 * Può importare: solo tipi Playwright. Non può importare: tutto il resto.
 */
import type { BrowserContext, Page } from "@playwright/test";

export const FM_EVENTS = [
    "fm:active-institute-changed",
    "fm:add-pt-section",
    "fm:add-pt-subsection",
    "fm:category-labels-hydrated",
    "fm:collapse-all-sections",
    "fm:collapsible-expanded",
    "fm:db-sidepage-rendered",
    "fm:delete-pt-section",
    "fm:drive:ready",
    "fm:duplicate-pt-section",
    "fm:edit-section-toggled",
    "fm:editor:ready",
    "fm:grade-change",
    "fm:mathjax-ready",
    "fm:move-pt-section",
    "fm:navigated",
    "fm:new-exercise-added",
    "fm:origin-selected",
    "fm:reg-changed",
    "fm:reset-model",
    "fm:resource-grant-changed",
    "fm:risdoc-sidepage-rendered",
    "fm:risdoc:ready",
    "fm:sec-changed",
    "fm:section-collapse-change",
    "fm:sidebar-config-hydrated",
    "fm:sidebar-template-ready",
    "fm:sync-log-updated",
    "fm:user-changed",
    "fm:value-change",
    "fm:verifica-pdf-batch",
    "fm:verifica-saved",
    "fm:verifica-ui-loaded",
] as const;

export type FmEvent = (typeof FM_EVENTS)[number];

/**
 * Attende che nessuna animazione sia in corso.
 *
 * Serve prima di misurare una posizione o di premere qualcosa che si sta
 * ancora muovendo: a metà transizione i numeri sono falsi e il clic va a
 * vuoto. Le animazioni senza fine (le rotelle di caricamento) non si aspettano,
 * perché non finiscono mai.
 */
export async function attendiAnimazioniFerme(page: Page, timeout = 15_000): Promise<void> {
    await page.waitForFunction(
        () => document.getAnimations().every((a) => {
            const senzaFine = a.effect?.getComputedTiming?.().iterations === Infinity;
            return senzaFine || a.playState === "finished" || a.playState === "idle";
        }),
        null,
        { timeout },
    );
}

declare global {
    interface Window {
        /** Contatori degli eventi fm:* installati da installEventRecorder (solo nei test). */
        __fmEvents?: Record<string, number>;
    }
}

/** Da chiamare sul contesto prima di aprire pagine: vale per ogni documento del contesto. */
export async function installEventRecorder(context: BrowserContext): Promise<void> {
    await context.addInitScript(() => {
        const counts: Record<string, number> = Object.create(null) as Record<string, number>;
        const original = EventTarget.prototype.dispatchEvent;
        EventTarget.prototype.dispatchEvent = function (this: EventTarget, event: Event): boolean {
            if (typeof event.type === "string" && event.type.startsWith("fm:")) {
                counts[event.type] = (counts[event.type] ?? 0) + 1;
            }
            return original.call(this, event);
        };
        window.__fmEvents = counts;
    });
}

/** Quante volte l'evento è stato emesso nel documento corrente della pagina. */
export async function fmEventCount(page: Page, name: FmEvent): Promise<number> {
    return page.evaluate((eventName) => window.__fmEvents?.[eventName] ?? 0, name);
}

export interface WaitForFmEventOptions {
    /** Aspetta un'emissione successiva a questo conteggio (default 0: almeno una). */
    readonly after?: number;
    readonly timeout?: number;
}

/** Attende che il contatore superi `after`; restituisce il conteggio raggiunto. */
export async function waitForFmEvent(page: Page, name: FmEvent, options: WaitForFmEventOptions = {}): Promise<number> {
    const after = options.after ?? 0;
    const handle = await page.waitForFunction(
        ([eventName, previous]) => {
            const count = window.__fmEvents?.[eventName] ?? 0;
            return count > previous ? count : null;
        },
        [name, after] as const,
        options.timeout === undefined ? {} : { timeout: options.timeout },
    );
    const value = await handle.jsonValue();
    return typeof value === "number" ? value : after + 1;
}

declare global {
    interface Window {
        /** Spazio dei moduli dell'applicazione, popolato da js/modules/bootstrap.js. */
        FM?: Record<string, unknown>;
    }
}
