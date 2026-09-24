/**
 * Fixture delle factory, legate al client del docente e al registro di pulizia.
 *
 * Responsabilità: `contentFactory`, `verificaFactory`, `shareFactory` creano
 * dati con nome unico (`e2e-<spec>-…`) e ne registrano la cancellazione per
 * il ruolo `teacher`, che la esegue nel proprio teardown. Le factory per gli
 * altri ruoli e per le altre aree (risdoc, print-info, TikZ) nascono nella
 * fetta che le usa (blueprint §8).
 *
 * Può importare: api, factories, fixture di autenticazione. Non può importare: spec, test.ts.
 */
import { ContentFactory } from "../factories/content.factory";
import { CredentialFactory } from "../factories/credential.factory";
import { Naming } from "../factories/naming";
import { ShareFactory } from "../factories/share.factory";
import { VerificaFactory } from "../factories/verifica.factory";
import { test as withAuth } from "./auth.fixture";

export interface FactoryFixtures {
    naming: Naming;
    contentFactory: ContentFactory;
    verificaFactory: VerificaFactory;
    shareFactory: ShareFactory;
    /** Credenziali di classe del docente: la porta degli studenti negli scenari 1 e 2. */
    credentialFactory: CredentialFactory;
    /** Factory legate al secondo docente: chi recupera dal pool cancella la propria copia. */
    teacher2ContentFactory: ContentFactory;
    teacher2ShareFactory: ShareFactory;
    /** Le credenziali del secondo docente: servono al portachiavi con più docenti. */
    teacher2CredentialFactory: CredentialFactory;
}

export const test = withAuth.extend<FactoryFixtures>({
    naming: async ({}, use, testInfo) => {
        await use(new Naming(testInfo.file));
    },
    contentFactory: async ({ teacherApi, cleanup, naming, env }, use) => {
        await use(new ContentFactory(teacherApi.content, cleanup, "teacher", naming, env.terna, teacherApi.maps));
    },
    verificaFactory: async ({ teacherApi, cleanup, naming }, use) => {
        await use(new VerificaFactory(teacherApi.verifica, cleanup, "teacher", naming));
    },
    shareFactory: async ({ teacherApi, cleanup, naming }, use) => {
        await use(new ShareFactory(teacherApi.share, teacherApi.content, teacherApi.curriculum, cleanup, "teacher", naming));
    },
    teacher2ContentFactory: async ({ teacher2Api, cleanup, naming, env }, use) => {
        await use(new ContentFactory(teacher2Api.content, cleanup, "teacher2", naming, env.terna, teacher2Api.maps));
    },
    teacher2ShareFactory: async ({ teacher2Api, cleanup, naming }, use) => {
        await use(new ShareFactory(teacher2Api.share, teacher2Api.content, teacher2Api.curriculum, cleanup, "teacher2", naming));
    },
    credentialFactory: async ({ teacherApi, cleanup, naming }, use) => {
        await use(new CredentialFactory(teacherApi.credentials, cleanup, "teacher", naming));
    },
    teacher2CredentialFactory: async ({ teacher2Api, cleanup, naming }, use) => {
        await use(new CredentialFactory(teacher2Api.credentials, cleanup, "teacher2", naming));
    },
});
