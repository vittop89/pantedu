/**
 * Fixture di sessione per ruolo e registro di pulizia.
 *
 * Responsabilità: `teacherPage`, `teacher2Page`, `adminPage` aprono ciascuna
 * un contesto proprio (cookie separati per ruolo, registratore degli eventi
 * fm:* installato) e vi entrano riusando la cache di tests/e2e/.auth/;
 * `teacherApi`, `teacher2Api`, `adminApi` sono i client sulla stessa
 * sessione. Nel teardown ogni fixture di ruolo esegue le azioni di pulizia
 * registrate per il suo ruolo, poi chiude il contesto: le cancellazioni
 * partono sempre da una sessione ancora aperta. Le fixture non navigano:
 * è la spec a scegliere la pagina.
 *
 * Può importare: auth, api, factories/cleanup-registry, sync, env, diagnostics.
 * Non può importare: spec, test.ts.
 */
import type { Browser, BrowserContextOptions, Page, TestInfo } from "@playwright/test";
import { HttpClient } from "../api/http";
import { AdminApi } from "../api/admin.api";
import { TeacherApi } from "../api/teacher.api";
import { loginAs } from "../auth/login";
import { publicEnv, type PublicEnv, type Role } from "../env";
import { CleanupRegistry } from "../factories/cleanup-registry";
import { installEventRecorder } from "../sync/signals";
import { test as withDiagnostics, type Diagnostics } from "./diagnostics.fixture";

export interface AuthFixtures {
    /** Ambiente senza segreti: nomi utente, URL di base, dati scoperti dal setup. */
    env: PublicEnv;
    /** Registro delle azioni di pulizia, eseguite dalla fixture del ruolo indicato. */
    cleanup: CleanupRegistry;
    teacherPage: Page;
    teacher2Page: Page;
    adminPage: Page;
    teacherApi: TeacherApi;
    teacher2Api: TeacherApi;
    adminApi: AdminApi;
}

interface RolePageDeps {
    readonly browser: Browser;
    readonly contextOptions: BrowserContextOptions;
    readonly diagnostics: Diagnostics;
    readonly cleanup: CleanupRegistry;
    readonly testInfo: TestInfo;
}

const ROLE_LABEL: Readonly<Record<Role, string>> = {
    teacher: "docente",
    teacher2: "secondo docente",
    admin: "amministratore",
};

/**
 * Se una navigazione finisce sulla pagina di accesso, rientra e riprova. Una
 * volta sola.
 *
 * Perché serve. In un giro notturno su ventitré, `studio/gruppo-e-quesito` è
 * fallita aspettando `fm:verifica-ui-loaded`: la traccia dice che la
 * navigazione aveva preso 302 verso `/login`. Ma le dieci chiamate API
 * immediatamente prima erano tutte 200 e autenticate — l'ultima era una
 * `publish` — e la richiesta della pagina mandava il cookie, senza nessun
 * `Set-Cookie` in risposta. Cioè: la sessione valeva un istante prima e non
 * valeva più un istante dopo, e le ipotesi ovvie (scadenza dei trenta minuti,
 * il `/logout` di due prove sulla sessione condivisa, la rotazione
 * dell'identificativo) sono state verificate e non reggono. Voce 80 del
 * debito: la causa è ancora da trovare.
 *
 * Perché rientrare e non fallire. È quello che farebbe una persona, e non
 * nasconde niente: ogni ripresa lascia una riga nella diagnostica, che finisce
 * nel rapporto di ogni prova rossa. Se un giorno quella riga comincia a
 * comparire spesso, si vede — e allora c'è un difetto da inseguire, non una
 * stranezza da una volta su ventitré.
 *
 * Se anche il secondo tentativo finisce sull'accesso, non si insiste: la
 * pagina di studio se ne accorge e lo dice con parole sue.
 */
function installaRipresaSessione(page: Page, role: Role, diagnostics: Diagnostics): void {
    const suAccesso = (indirizzo: string): boolean => /\/login(\?|$)/.test(indirizzo);
    const gotoOriginale = page.goto.bind(page);

    page.goto = async (url, options) => {
        const risposta = await gotoOriginale(url, options);
        // Chi chiede l'accesso o l'uscita vuole quello: non è una caduta.
        // (`/logout` finisce sull'accesso per definizione: senza escluderlo,
        // le due prove che escono davvero rientrerebbero subito dopo, e la
        // diagnostica segnalerebbe una caduta che non c'è stata.)
        if (suAccesso(url) || /\/logout(\?|$)/.test(url) || !suAccesso(page.url())) {
            return risposta;
        }

        diagnostics.notes.push(
            `[sessione] ${ROLE_LABEL[role]}: caduta durante «${url}», rientrato e riprovato`,
        );
        await loginAs(page, role, { fresh: true });
        return gotoOriginale(url, options);
    };
}

/**
 * Riporta la sessione del docente di prova nella scuola in cui il setup ha
 * scoperto le pagine (ADR-037).
 *
 * Perché serve. Dal 13 settembre 2026 un contenuto sta nella scuola in cui è
 * pubblicato, e la scuola attiva è uno stato della sessione che le prove
 * condividono (la cache di tests/e2e/.auth la tiene anche fra un giro e
 * l'altro). Una prova che cambia istituto dal selettore lasciava la sessione
 * nell'altra scuola, e le prove successive cercavano le pagine scoperte dove
 * non ci sono: sul database di sviluppo, 241 contenuti stanno in una scuola e
 * compaiono nell'altra solo per coincidenza di sigle, che adesso non conta più.
 *
 * Una domanda per prova (la scuola attiva) e un cambio solo se serve. Se il
 * setup non ha una scuola (nessun contenuto, o database senza pubblicazioni)
 * non si fa niente.
 */
async function riportaNellaScuolaDelleProve(page: Page, role: Role, diagnostics: Diagnostics): Promise<void> {
    const scuola = publicEnv().discovered.scuolaId;
    if (role !== "teacher" || scuola === undefined || scuola <= 0) {
        return;
    }
    const http = new HttpClient(page.request, ROLE_LABEL[role], (riga) => diagnostics.failedResponses.push(riga));
    const corrente = await http.getJson<{ current_institute_id: number | string | null }>("/api/tenant/current");
    if (Number(corrente.current_institute_id) === scuola) {
        return;
    }
    await http.postForm<{ ok: boolean }>("/api/tenant/switch", { institute_id: String(scuola) });
    diagnostics.notes.push(
        `[sessione] ${ROLE_LABEL[role]}: scuola attiva ${String(corrente.current_institute_id)} → ${scuola}, quella delle pagine scoperte`,
    );
}

async function withRolePage(role: Role, deps: RolePageDeps, use: (page: Page) => Promise<void>): Promise<void> {
    const context = await deps.browser.newContext(deps.contextOptions);
    await installEventRecorder(context);
    const page = await context.newPage();
    deps.diagnostics.observe(page);
    const login = await loginAs(page, role);
    installaRipresaSessione(page, role, deps.diagnostics);
    deps.diagnostics.notes.push(
        `[sessione] ${ROLE_LABEL[role]} ${login.username}: ${login.origin === "cache" ? "dalla cache" : "dal modulo"}` +
            (login.csrfRetries ? `, ${login.csrfRetries} retry sul 403 CSRF` : ""),
    );
    await riportaNellaScuolaDelleProve(page, role, deps.diagnostics);

    await use(page);

    const failures = await deps.cleanup.runFor(role);
    await context.close();
    if (failures.length) {
        const lines = failures.map((f) => `- ${f.description}: ${f.error}`);
        await deps.testInfo.attach(`pulizia-${role}.txt`, { body: lines.join("\n"), contentType: "text/plain" });
        throw new Error(`[pulizia] ${failures.length} azioni fallite per ${ROLE_LABEL[role]}:\n${lines.join("\n")}`);
    }
}

export const test = withDiagnostics.extend<AuthFixtures>({
    env: async ({}, use) => {
        await use(publicEnv());
    },

    cleanup: async ({}, use) => {
        const registry = new CleanupRegistry();
        await use(registry);
        const left = registry.pending();
        if (left.length) {
            // Una spec ha registrato azioni per un ruolo di cui non ha aperto la sessione.
            throw new Error(
                `[pulizia] ${left.length} azioni registrate ma mai eseguite: ${left.map((e) => `${e.description} (${ROLE_LABEL[e.owner]})`).join("; ")}`,
            );
        }
    },

    teacherPage: async ({ browser, contextOptions, diagnostics, cleanup }, use, testInfo) => {
        await withRolePage("teacher", { browser, contextOptions, diagnostics, cleanup, testInfo }, use);
    },
    teacher2Page: async ({ browser, contextOptions, diagnostics, cleanup }, use, testInfo) => {
        await withRolePage("teacher2", { browser, contextOptions, diagnostics, cleanup, testInfo }, use);
    },
    adminPage: async ({ browser, contextOptions, diagnostics, cleanup }, use, testInfo) => {
        await withRolePage("admin", { browser, contextOptions, diagnostics, cleanup, testInfo }, use);
    },

    // Le risposte 4xx/5xx delle API finiscono nella diagnostica della prova,
    // come quelle della pagina (OsservatoreRisposte, in api/http.ts).
    teacherApi: async ({ teacherPage, diagnostics }, use) => {
        await use(new TeacherApi(new HttpClient(teacherPage.request, ROLE_LABEL.teacher, (riga) => diagnostics.failedResponses.push(riga))));
    },
    teacher2Api: async ({ teacher2Page, diagnostics }, use) => {
        await use(new TeacherApi(new HttpClient(teacher2Page.request, ROLE_LABEL.teacher2, (riga) => diagnostics.failedResponses.push(riga))));
    },
    adminApi: async ({ adminPage, diagnostics }, use) => {
        await use(new AdminApi(new HttpClient(adminPage.request, ROLE_LABEL.admin, (riga) => diagnostics.failedResponses.push(riga))));
    },
});
