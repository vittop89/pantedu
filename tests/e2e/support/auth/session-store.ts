/**
 * Cache delle sessioni in tests/e2e/.auth/<utente>.json.
 *
 * Responsabilità: leggere e scrivere i cookie di una sessione già aperta,
 * nello stesso formato di tests/e2e/helpers.js (`cookies`, `landing`,
 * `savedAt`), così le spec vecchie e quelle nuove condividono la cache
 * durante la migrazione. La cache è un'ottimizzazione: se manca o è
 * corrotta si rifà il login dal modulo.
 *
 * Può importare: node. Non può importare: api, pagine, fixture.
 */
import * as fs from "node:fs";
import * as path from "node:path";
import type { BrowserContext } from "@playwright/test";

/** Cookie come lo restituisce BrowserContext.cookies(): stesso tipo, nessuna copia manuale. */
export type StoredCookie = Awaited<ReturnType<BrowserContext["cookies"]>>[number];

export interface StoredSession {
    readonly cookies: readonly StoredCookie[];
    readonly landing: string;
    readonly savedAt: number;
}

export const AUTH_DIR = path.resolve(__dirname, "..", "..", ".auth");

export function sessionFile(username: string): string {
    return path.join(AUTH_DIR, `${username.replace(/[^\w.@-]/g, "_")}.json`);
}

function isStoredSession(value: unknown): value is StoredSession {
    if (typeof value !== "object" || value === null) return false;
    const record = value as Record<string, unknown>;
    return Array.isArray(record["cookies"]) && record["cookies"].length > 0;
}

/** Sessione salvata, o null se assente, vuota o illeggibile. */
export function readSession(username: string): StoredSession | null {
    try {
        const parsed: unknown = JSON.parse(fs.readFileSync(sessionFile(username), "utf8"));
        if (!isStoredSession(parsed)) return null;
        return {
            cookies: parsed.cookies,
            landing: typeof parsed.landing === "string" ? parsed.landing : "/",
            savedAt: typeof parsed.savedAt === "number" ? parsed.savedAt : 0,
        };
    } catch {
        return null;
    }
}

/** Salva i cookie correnti; un errore di scrittura non ferma il test (si rifarà il login). */
export function writeSession(username: string, cookies: readonly StoredCookie[], landing: string): void {
    try {
        fs.mkdirSync(AUTH_DIR, { recursive: true });
        const session: StoredSession = { cookies, landing, savedAt: Date.now() };
        fs.writeFileSync(sessionFile(username), JSON.stringify(session));
    } catch (error) {
        console.warn(`[sessione] cache non scritta per ${username}: ${error instanceof Error ? error.message : String(error)}`);
    }
}
