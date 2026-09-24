/**
 * Fascio dei client di dominio di un docente, sulla stessa sessione.
 *
 * Responsabilità: dare alle spec un solo oggetto per ruolo
 * (`teacherApi.content`, `teacherApi.verifica`, `teacherApi.share`, `teacherApi.risdoc`,
 * `teacherApi.http` per le chiamate non ancora coperte da un client).
 *
 * Può importare: http e i client di dominio. Non può importare: pagine, fixture, factory.
 */
import { AccessApi } from "./access.api";
import { AdozioniDocenteApi } from "./adozioni.api";
import { BundleApi } from "./bundle.api";
import { CredentialsApi } from "./credentials.api";
import { CurriculumApi } from "./curriculum.api";
import type { HttpClient } from "./http";
import { MapsApi } from "./maps.api";
import { ObservabilityApi } from "./observability.api";
import { PrintInfoApi } from "./print-info.api";
import { RisdocApi } from "./risdoc.api";
import { ShareApi } from "./share.api";
import { SourcesApi } from "./sources.api";
import { TeacherContentApi } from "./teacher-content.api";
import { VerificaApi } from "./verifica.api";

export class TeacherApi {
    readonly content: TeacherContentApi;
    readonly verifica: VerificaApi;
    readonly share: ShareApi;
    readonly curriculum: CurriculumApi;
    readonly maps: MapsApi;
    readonly bundle: BundleApi;
    readonly printInfo: PrintInfoApi;
    readonly risdoc: RisdocApi;
    /** I libri da cui vengono i quesiti: danno la citazione al cartellino. */
    readonly sources: SourcesApi;
    /** Accesso alle risorse riservate senza account. */
    readonly access: AccessApi;
    /** Le credenziali di classe: la porta degli studenti negli scenari 1 e 2. */
    readonly credentials: CredentialsApi;
    /** Metriche e tracciamento delle richieste. */
    readonly osservabilita: ObservabilityApi;
    /** I libri in adozione della scuola, per le classi e le materie spuntate. */
    readonly adozioni: AdozioniDocenteApi;

    constructor(readonly http: HttpClient) {
        this.content = new TeacherContentApi(http);
        this.verifica = new VerificaApi(http);
        this.share = new ShareApi(http);
        this.curriculum = new CurriculumApi(http);
        this.maps = new MapsApi(http);
        this.bundle = new BundleApi(http);
        this.printInfo = new PrintInfoApi(http);
        this.risdoc = new RisdocApi(http);
        this.sources = new SourcesApi(http);
        this.access = new AccessApi(http);
        this.credentials = new CredentialsApi(http);
        this.osservabilita = new ObservabilityApi(http);
        this.adozioni = new AdozioniDocenteApi(http);
    }
}
