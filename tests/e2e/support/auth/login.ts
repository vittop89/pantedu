/**
 * Accesso con un ruolo, riusando la sessione in cache quando è ancora viva.
 *
 * Responsabilità: lo stesso flusso di tests/e2e/helpers.js `loginAs`:
 *   1. cookie dalla cache → `GET /auth/user-info` deve dire che l'utente è
 *      autenticato con quel nome (vedi debito: /me/consents rispondeva 200
 *      anche da ospite);
 *   2. altrimenti modulo /login; il primo POST può rispondere 403 «CSRF token
 *      invalid» in modo intermittente (voce 35 del registro del debito): si
 *      ritenta una volta e lo si conta, così il report mostra quante volte
 *      capita.
 * Non naviga altrove dopo l'accesso: è la spec a scegliere la pagina.
 *
 * Può importare: env, session-store. Non può importare: api di dominio, pagine, fixture.
 */
import type { APIRequestContext, Page } from "@playwright/test";
import { credentials, type Role } from "../env";
import { readSession, writeSession } from "./session-store";

export type SessionOrigin = "cache" | "form";

export interface LoginResult {
    readonly role: Role;
    readonly username: string;
    readonly origin: SessionOrigin;
    /** Quanti POST /login sono finiti in 403 CSRF prima di riuscire (0 o 1). */
    readonly csrfRetries: number;
}

interface UserInfo {
    readonly authenticated?: boolean;
    readonly username?: string;
}

/** URL che non contiene /login: dopo il POST del modulo si finisce sulla landing. */
const NOT_LOGIN_URL = /^(?!.*\/login).*/;

let csrfRetriesTotal = 0;

/** Retry sul 403 CSRF contati dall'avvio del worker (diagnostica). */
export function csrfRetriesSoFar(): number {
    return csrfRetriesTotal;
}

/** Vera se la sessione del contesto è autenticata (e, se indicato, con quel nome utente). */
export async function sessionAlive(request: APIRequestContext, username?: string): Promise<boolean> {
    try {
        const response = await request.get("/auth/user-info", { headers: { Accept: "application/json" } });
        if (!response.ok()) return false;
        const info = (await response.json().catch(() => null)) as UserInfo | null;
        if (!info || info.authenticated !== true) return false;
        return username === undefined || info.username === undefined || info.username === username;
    } catch {
        return false;
    }
}

export async function loginAs(page: Page, role: Role, options: { readonly fresh?: boolean } = {}): Promise<LoginResult> {
    const { username, password } = credentials(role);
    const context = page.context();

    if (!options.fresh) {
        const saved = readSession(username);
        if (saved) {
            await context.addCookies(saved.cookies);
            if (await sessionAlive(page.request, username)) {
                // I cookie possono essere ruotati dal server: si salva lo stato aggiornato.
                writeSession(username, await context.cookies(), saved.landing);
                return { role, username, origin: "cache", csrfRetries: 0 };
            }
            await context.clearCookies();
        }
    }

    let csrfRetries = 0;
    for (let attempt = 0; attempt < 2; attempt++) {
        await page.goto("/login");
        await page.locator('input[name="username"]').fill(username);
        await page.locator('input[name="password"]').fill(password);
        // Il POST può non essere osservabile (navigazione immediata): in quel caso
        // decide l'URL di arrivo, come nel helper storico.
        const post = page
            .waitForResponse((r) => r.request().method() === "POST" && /\/login/.test(r.url()), { timeout: 15_000 })
            .catch(() => null);
        await page.locator('button[type="submit"]').click();
        const response = await post;
        if (response && response.status() === 403 && attempt === 0) {
            csrfRetries++;
            csrfRetriesTotal++;
            console.warn(`[login] ${username}: 403 sul POST /login (CSRF), secondo tentativo`);
            continue;
        }
        try {
            await page.waitForURL(NOT_LOGIN_URL);
        } catch {
            throw new Error(`[login] accesso come ${username} (${role}) fallito: la pagina è rimasta su ${page.url()}`);
        }
        break;
    }

    const url = new URL(page.url());
    writeSession(username, await context.cookies(), url.pathname + url.search);
    return { role, username, origin: "form", csrfRetries };
}

export async function logout(page: Page): Promise<void> {
    await page.goto("/logout");
}
