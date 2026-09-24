/**
 * Unico ingresso delle spec: `const { test, expect } = require("./support/test")`.
 *
 * Responsabilità: comporre le fixture (diagnostica → sessioni per ruolo e
 * pulizia → factory) e riesportare ciò che una spec può usare oltre a
 * `test` ed `expect`: le attese sui segnali `fm:*`, l'errore delle API e i
 * tipi. Le spec non importano nient'altro da support/ (regola ESLint).
 *
 * Può importare: fixtures, sync, api/http (solo ApiError). Non può importare: spec.
 */
export { expect } from "@playwright/test";
export type { APIRequestContext, BrowserContext, Locator, Page } from "@playwright/test";
export { test } from "./fixtures/tools.fixture";
export { ApiError } from "./api/http";
export { fmEventCount, waitForFmEvent, attendiAnimazioniFerme, FM_EVENTS, installEventRecorder } from "./sync/signals";
export { apriPaginaVera, SELETTORE_PAGINA_DI_ERRORE } from "./pagina-vera";
export {
    PAGINE_PUBBLICHE,
    PAGINE_DEL_DOCENTE,
    PAGINE_DELL_AMMINISTRAZIONE,
    attendiDomFermo,
    preparaPagina,
} from "./pagine-accessibilita";
export type { PaginaDaControllare } from "./pagine-accessibilita";
export type { FmEvent } from "./sync/signals";
export type { TeacherApi } from "./api/teacher.api";
export type { AdminApi } from "./api/admin.api";
export type { Diagnostics } from "./fixtures/diagnostics.fixture";
export type { PublicEnv, Terna } from "./env";
export type { CreatedDocument, CreatedExercise, CreatedMap, CreatedPair } from "./factories/content.factory";
export { compilaVerifica, pdfCompilato } from "./verifiche/compilazione";
export { cartellaDiLavoro, compilaConPdflatex, esiste, estraiZip, leggiFile, scriviFile } from "./verifiche/pacchetto-locale";
export type { Strumenti } from "./fixtures/tools.fixture";
export type { StudioEsercizioPage } from "./pages/StudioEsercizioPage";
export type { HomeSidebarPage, SidepageKey } from "./pages/HomeSidebarPage";
export type { GruppoQuesiti } from "./components/GruppoQuesiti";
export type { VerificaFactory } from "./factories/verifica.factory";
export type { CleanupRegistry } from "./factories/cleanup-registry";
export type { ContentFactory } from "./factories/content.factory";
export type { CredentialFactory, CreatedCredential } from "./factories/credential.factory";
export type { AccessoClassePage } from "./pages/AccessoClassePage";
export type { PortachiaviBarra } from "./components/PortachiaviBarra";
export type { PannelloCredenziali } from "./components/PannelloCredenziali";
