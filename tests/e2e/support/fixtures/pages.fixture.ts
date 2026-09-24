/**
 * Fixture delle pagine: oggetti già costruiti sulla sessione del docente.
 *
 * Responsabilità: dare alle spec `studioEsercizio` e `homeDocente` senza che
 * debbano importare le classi (la regola ESLint permette solo
 * `./support/test`). Le pagine delle altre aree nascono nella fetta che le usa.
 *
 * Può importare: pages, fixture delle factory. Non può importare: spec, test.ts.
 */
import { PannelloCredenziali } from "../components/PannelloCredenziali";
import { PortachiaviBarra } from "../components/PortachiaviBarra";
import { AccessoClassePage } from "../pages/AccessoClassePage";
import { BancoEditor } from "../pages/BancoEditor";
import { HomeSidebarPage } from "../pages/HomeSidebarPage";
import { StudioEsercizioPage } from "../pages/StudioEsercizioPage";
import { test as withFactories } from "./factories.fixture";

export interface PageFixtures {
    studioEsercizio: StudioEsercizioPage;
    homeDocente: HomeSidebarPage;
    /** Le stesse pagine viste dal secondo docente (spec di condivisione). */
    studioEsercizioCollega: StudioEsercizioPage;
    /** La stessa pagina vista da un amministratore: ha i filtri riservati. */
    studioEsercizioAdmin: StudioEsercizioPage;
    /** Pagina con l'editor caricato, per le prove di modulo di `editor/moduli/`. */
    bancoEditor: BancoEditor;
    /** /accesso-classe vista da chi non ha un account: la pagina anonima. */
    accessoClasse: AccessoClassePage;
    /** Il riquadro del portachiavi nella barra laterale della home, per la stessa pagina anonima. */
    portachiaviBarra: PortachiaviBarra;
    /** Il riquadro delle credenziali di classe nel profilo del docente. */
    pannelloCredenziali: PannelloCredenziali;
}

export const test = withFactories.extend<PageFixtures>({
    accessoClasse: async ({ page }, use) => {
        await use(new AccessoClassePage(page));
    },
    portachiaviBarra: async ({ page }, use) => {
        await use(new PortachiaviBarra(page));
    },
    pannelloCredenziali: async ({ teacherPage }, use) => {
        await use(new PannelloCredenziali(teacherPage));
    },
    studioEsercizio: async ({ teacherPage }, use) => {
        await use(new StudioEsercizioPage(teacherPage));
    },
    homeDocente: async ({ teacherPage }, use) => {
        await use(new HomeSidebarPage(teacherPage));
    },
    studioEsercizioCollega: async ({ teacher2Page }, use) => {
        await use(new StudioEsercizioPage(teacher2Page));
    },
    studioEsercizioAdmin: async ({ adminPage }, use) => {
        await use(new StudioEsercizioPage(adminPage));
    },
    bancoEditor: async ({ teacherPage }, use) => {
        const banco = new BancoEditor(teacherPage);
        await banco.apri();
        await use(banco);
        await banco.pulisci();
    },
});
