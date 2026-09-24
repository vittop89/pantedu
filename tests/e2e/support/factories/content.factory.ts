/**
 * Factory dei contenuti del docente (esercizi con contratto, documenti PT).
 *
 * Responsabilità: creare via API un contenuto con nome unico e registrarne
 * subito la cancellazione; per gli esercizi aggiungere i gruppi (ognuno nasce
 * con i quesiti predefiniti di TemplateDefaults) e duplicare i quesiti fino
 * al numero chiesto, verificando sul contratto. Sostituisce la copia di
 * lavoro 1293 rigenerata da tools/dev/gen_proof_contract.cjs.
 *
 * Può importare: api, cleanup-registry, naming, env (tipi). Non può importare: pagine, fixture.
 */
import type { Role, Terna } from "../env";
import type { MapsApi } from "../api/maps.api";
import type { TeacherContentApi } from "../api/teacher-content.api";
import type { CleanupRegistry } from "./cleanup-registry";
import type { Naming } from "./naming";

export interface ExerciseOptions {
    readonly terna?: Terna;
    readonly title?: string;
    readonly topic?: string;
    /** Gruppi di quesiti da aggiungere (default 1). */
    readonly groups?: number;
    /**
     * Tipo dei gruppi (default `type_Collect`). `type_VF` fa un gruppo di
     * vero/falso, con i suoi punteggi e le sue caselle.
     */
    readonly groupType?: string;
    /** Quesiti minimi per gruppo (default: quelli predefiniti del tipo). */
    readonly itemsPerGroup?: number;
    readonly publish?: boolean;
    /** Sezione della sidebar (default `eser`, come il wizard). */
    readonly sectionKey?: string;
    /**
     * Testo del quesito, scritto su tutti i quesiti dopo le duplicazioni.
     * Serve ai test che hanno bisogno di un contenuto particolare (formule,
     * elenchi, marcatori); senza, resta quello predefinito del tipo.
     */
    readonly itemHtml?: string;
    /**
     * Tipo del contenuto (default `esercizio`). Con `verifica` il contenuto
     * finisce nella pagina di studio delle verifiche, con gli stessi gruppi.
     */
    readonly contentType?: "esercizio" | "verifica";
}

export interface CreatedExercise {
    readonly id: number;
    readonly title: string;
    readonly topic: string;
    readonly terna: Terna;
    readonly groupIds: readonly string[];
    /** Quesiti per gruppo, letti dal contratto dopo le duplicazioni. */
    readonly itemsPerGroup: readonly number[];
    /** URL della pagina studio con il solo esercizio creato (`?ids=`). */
    readonly studioUrl: string;
}

export interface DocumentOptions {
    readonly terna?: Terna;
    readonly title?: string;
    readonly topic?: string;
    readonly bodyPt?: readonly unknown[];
    readonly visibility?: "draft" | "published";
    /** Altri metadati del documento (categoria, struttura, blocchi). */
    readonly metadata?: Readonly<Record<string, unknown>>;
}

export interface CreatedDocument {
    readonly id: number;
    readonly title: string;
    readonly topic: string;
    readonly terna: Terna;
    readonly studioUrl: string;
}

const DEFAULT_TERNA: Terna = { indirizzo: "SCI", classe: "2", materia: "MAT" };

function studioUrl(kind: "esercizio" | "verifica" | "document", terna: Terna, topic: string, ids?: number): string {
    const base = `/studio/${kind}/${encodeURIComponent(terna.indirizzo)}/${encodeURIComponent(terna.classe)}/${encodeURIComponent(terna.materia)}/${encodeURIComponent(topic)}`;
    return ids === undefined ? base : `${base}?ids=${ids}`;
}

export interface CreatedMap {
    readonly id: number;
    readonly title: string;
    readonly topic: string;
    readonly terna: Terna;
    /** Percorso del disegno cifrato: comincia con l'identificativo del proprietario. */
    readonly blobPath: string;
}

export interface PairOptions {
    readonly terna?: Terna;
    /** Titolo dell'esercizio, che è anche l'argomento della verifica abbinata. */
    readonly titolo?: string;
    /**
     * Gruppi da mettere nella verifica abbinata (default nessuno). Servono ai
     * test che guardano la verifica correlata resa in fondo alla pagina
     * dell'esercizio: senza gruppi non c'è niente da rendere.
     */
    readonly gruppiNellaVerifica?: number;
}

/**
 * Coppia esercizio↔verifica che l'app considera abbinata: stessa materia e
 * argomento della verifica uguale al titolo dell'esercizio (è la regola con
 * cui `tools/dev/e2e_urls.php` la cerca e con cui la condivisione propaga).
 */
export interface CreatedPair {
    readonly esercizio: CreatedExercise;
    readonly verificaId: number;
    readonly verificaTitolo: string;
}

/** Disegno minimo valido per una mappa in formato drawio. */
const DRAWIO_XML =
    '<mxfile host="e2e"><diagram id="d1" name="Pagina-1"><mxGraphModel><root>'
    + '<mxCell id="0"/><mxCell id="1" parent="0"/>'
    + '<mxCell id="2" value="Mappa E2E" style="rounded=1;whiteSpace=wrap;" vertex="1" parent="1">'
    + '<mxGeometry x="10" y="10" width="120" height="40" as="geometry"/></mxCell>'
    + "</root></mxGraphModel></diagram></mxfile>";

export class ContentFactory {
    constructor(
        private readonly api: TeacherContentApi,
        private readonly cleanup: CleanupRegistry,
        private readonly owner: Role,
        private readonly naming: Naming,
        private readonly defaultTerna: Terna = DEFAULT_TERNA,
        private readonly maps?: MapsApi,
    ) {}

    /** Registra la cancellazione di un contenuto creato altrove (es. da un test via API). */
    registerDeletion(id: number, label = `contenuto ${id}`): void {
        this.cleanup.add(this.owner, `cancella ${label}`, async () => {
            const result = await this.api.delete(id);
            if (!result.ok && result.status !== 404) {
                throw new Error(`POST /api/teacher/content/${id}/delete → ${result.status}: ${result.text.slice(0, 200)}`);
            }
        });
    }

    async exercise(options: ExerciseOptions = {}): Promise<CreatedExercise> {
        const terna = options.terna ?? this.defaultTerna;
        const title = options.title ?? this.naming.unique("esercizio");
        const topic = options.topic ?? title;
        const tipo = options.contentType ?? "esercizio";
        const created = await this.api.create({
            type: tipo,
            subject: terna.materia,
            indirizzo: terna.indirizzo,
            classe: terna.classe,
            section_key: options.sectionKey ?? (tipo === "verifica" ? "verif" : "eser"),
            topic,
            title,
            body_html: "<p>Esercizio creato dalla suite E2E.</p>",
            visibility: "draft",
        });
        const id = created.id;
        this.registerDeletion(id, `${tipo} «${title}»`);

        const groupIds: string[] = [];
        const groups = options.groups ?? 1;
        for (let i = 0; i < groups; i++) {
            const added = await this.api.addGroup(id, {
                type: options.groupType ?? "type_Collect",
                clientId: this.naming.unique("gruppo"),
                title: `Gruppo ${i + 1}`,
            });
            groupIds.push(added.groupId);
        }

        const wanted = options.itemsPerGroup ?? 0;
        let itemsPerGroup = await this.countItems(id, groupIds);
        for (let gi = 0; gi < groupIds.length; gi++) {
            const groupId = groupIds[gi];
            if (groupId === undefined) continue;
            let count = itemsPerGroup[gi] ?? 0;
            if (count === 0 && wanted > 0) {
                throw new Error(`[factory] il gruppo ${groupId} dell'esercizio ${id} non ha quesiti predefiniti: impossibile duplicarli`);
            }
            while (count < wanted) {
                await this.api.duplicateQuesito(id, `${groupId}_q0`);
                count++;
            }
        }
        if (wanted > 0) itemsPerGroup = await this.countItems(id, groupIds);

        if (options.itemHtml !== undefined) {
            for (let gi = 0; gi < groupIds.length; gi++) {
                const quanti = itemsPerGroup[gi] ?? 0;
                for (let ii = 0; ii < quanti; ii++) {
                    await this.api.patchQuesito(id, `g${gi}_q${ii}`, { quesito: options.itemHtml });
                }
            }
        }

        if (options.publish) await this.api.publish(id);

        return { id, title, topic, terna, groupIds, itemsPerGroup, studioUrl: studioUrl(tipo, terna, topic, id) };
    }

    async document(options: DocumentOptions = {}): Promise<CreatedDocument> {
        const terna = options.terna ?? this.defaultTerna;
        const title = options.title ?? this.naming.unique("documento");
        const topic = options.topic ?? title;
        const created = await this.api.create({
            type: "document",
            subject: terna.materia,
            indirizzo: terna.indirizzo,
            classe: terna.classe,
            topic,
            title,
            visibility: options.visibility ?? "draft",
            metadata: {
                ...options.metadata,
                body_pt: options.bodyPt ?? [
                    { _type: "block", style: "normal", children: [{ _type: "span", text: "Documento creato dalla suite E2E.", marks: [] }] },
                ],
            },
        });
        this.registerDeletion(created.id, `documento «${title}»`);
        return { id: created.id, title, topic, terna, studioUrl: studioUrl("document", terna, topic) };
    }

    /** Esercizio più la verifica che l'app gli abbina (stessa materia, argomento = titolo). */
    async pair(options: PairOptions = {}): Promise<CreatedPair> {
        const terna = options.terna ?? this.defaultTerna;
        const titolo = options.titolo ?? this.naming.unique("coppia");
        const esercizio = await this.exercise({ terna, title: titolo, topic: titolo, publish: true });
        const verificaTitolo = this.naming.unique("verifica-abbinata");
        const gruppi = options.gruppiNellaVerifica ?? 0;
        if (gruppi > 0) {
            const verifica = await this.exercise({
                terna,
                title: verificaTitolo,
                topic: titolo,
                contentType: "verifica",
                groups: gruppi,
                itemsPerGroup: 1,
                publish: true,
            });
            return { esercizio, verificaId: verifica.id, verificaTitolo };
        }
        const verifica = await this.api.create({
            type: "verifica",
            subject: terna.materia,
            indirizzo: terna.indirizzo,
            classe: terna.classe,
            topic: titolo,
            title: verificaTitolo,
            visibility: "published",
        });
        this.registerDeletion(verifica.id, `verifica abbinata «${verificaTitolo}»`);
        return { esercizio, verificaId: verifica.id, verificaTitolo };
    }

    /**
     * Contenuto di tipo verifica del docente: è quello che il pannello
     * «Verifiche» della barra laterale elenca per la terna scelta (i documenti
     * TeX prodotti dalla generazione stanno in un'altra tabella).
     */
    async createVerifica(options: { readonly titolo?: string; readonly terna?: Terna } = {}): Promise<{ id: number; titolo: string }> {
        const terna = options.terna ?? this.defaultTerna;
        const titolo = options.titolo ?? this.naming.unique("verifica");
        const created = await this.api.create({
            type: "verifica",
            subject: terna.materia,
            indirizzo: terna.indirizzo,
            classe: terna.classe,
            section_key: "verif",
            topic: titolo,
            title: titolo,
            visibility: "published",
        });
        this.registerDeletion(created.id, `verifica «${titolo}»`);
        return { id: created.id, titolo };
    }

    /**
     * Mappa concettuale in formato drawio, con il disegno cifrato sul filesystem.
     * `xml` sostituisce il disegno di prova (serve a chi controlla i byte).
     */
    async map(options: { readonly terna?: Terna; readonly title?: string; readonly xml?: string } = {}): Promise<CreatedMap> {
        if (!this.maps) {
            throw new Error("[factory] client delle mappe non disponibile per questo ruolo");
        }
        const terna = options.terna ?? this.defaultTerna;
        const title = options.title ?? this.naming.unique("mappa");
        const created = await this.maps.createDrawio({
            xml: options.xml ?? DRAWIO_XML,
            title,
            topic: title,
            subject: terna.materia,
            indirizzo: terna.indirizzo,
            classe: terna.classe,
            visibility: "published",
        });
        this.registerDeletion(created.id, `mappa «${title}»`);
        return { id: created.id, title, topic: title, terna, blobPath: created.blob_path ?? "" };
    }

    private async countItems(id: number, groupIds: readonly string[]): Promise<number[]> {
        const contract = await this.api.contract(id);
        return groupIds.map((groupId) => {
            const group = (contract.groups ?? []).find((g) => g.id === groupId);
            return group?.items?.length ?? 0;
        });
    }
}
