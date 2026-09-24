/**
 * Factory della condivisione: gruppi di colleghi, grant espliciti, flag del pool.
 *
 * Responsabilità: ogni mutazione dello stato di condivisione lascia nel
 * registro l'azione che la annulla (cancella il gruppo, revoca i grant,
 * ripristina il flag `shared_with_pool` al valore letto prima). Le spec di
 * condivisione non toccano più i contenuti scoperti al setup: creano i
 * propri con ContentFactory e li condividono da qui.
 *
 * Può importare: api, cleanup-registry, naming, env (tipi). Non può importare: pagine, fixture.
 */
import type { Role } from "../env";
import type { CurriculumApi } from "../api/curriculum.api";
import type { GrantSource, ShareApi } from "../api/share.api";
import type { TeacherContentApi } from "../api/teacher-content.api";
import type { GrantTarget, GrantsSet, SharePoolResult } from "../api/types";
import type { CleanupRegistry } from "./cleanup-registry";
import type { Naming } from "./naming";

export class ShareFactory {
    constructor(
        private readonly share: ShareApi,
        private readonly content: TeacherContentApi,
        private readonly curriculum: CurriculumApi,
        private readonly cleanup: CleanupRegistry,
        private readonly owner: Role,
        private readonly naming: Naming,
    ) {}

    /** Crea un gruppo di colleghi e ne registra la cancellazione. */
    async group(name?: string): Promise<number> {
        const created = await this.share.createGroup(name ?? this.naming.unique("gruppo"));
        this.cleanup.add(this.owner, `cancella gruppo di condivisione ${created.id}`, async () => {
            const result = await this.share.deleteGroup(created.id);
            if (!result.ok && result.status !== 404) {
                throw new Error(`POST /api/teacher/share/groups/${created.id}/delete → ${result.status}: ${result.text.slice(0, 200)}`);
            }
        });
        return created.id;
    }

    async setMembers(groupId: number, memberIds: readonly number[]): Promise<number> {
        return (await this.share.setMembers(groupId, memberIds)).count;
    }

    /** Imposta i grant di un contenuto e registra la revoca (grant vuoti). */
    async grant(source: GrantSource, id: number, grants: readonly GrantTarget[]): Promise<GrantsSet> {
        const result = await this.share.setGrants(source, id, grants);
        if (!result.ok || result.body === null) {
            throw new Error(`POST /api/teacher/share/grants/${source}/${id} → ${result.status}: ${result.text.slice(0, 300)}`);
        }
        this.cleanup.add(this.owner, `revoca i grant su ${source} ${id}`, async () => {
            const revoked = await this.share.setGrants(source, id, []);
            if (!revoked.ok && revoked.status !== 404) {
                throw new Error(`revoca grant ${source} ${id} → ${revoked.status}: ${revoked.text.slice(0, 200)}`);
            }
        });
        return result.body;
    }

    /**
     * Accende o spegne la condivisione nel pool.
     *
     * Per i contenuti che esistevano prima del test registra il ripristino del
     * valore precedente. Per quelli creati dalle factory va passato
     * `{ ripristina: false }`: verranno cancellati comunque, e rimetterli come
     * erano può essere impossibile (una verifica senza quesiti, condivisa per
     * propagazione dall'esercizio, non si può ricondividere da sola: il blocco
     * sul diritto d'autore la considera «non classificata»).
     */
    async setPoolSharing(id: number, enabled: boolean, options: { readonly ripristina?: boolean } = {}): Promise<SharePoolResult> {
        const ripristina = options.ripristina ?? true;
        let precedente = enabled;
        if (ripristina) {
            const prima = (await this.content.get(id)).content.shared_with_pool;
            precedente = prima === true || prima === 1;
        }
        const result = await this.content.setPoolSharing(id, enabled);
        if (!result.ok || result.body === null) {
            throw new Error(`POST /api/teacher/content/${id}/share-pool → ${result.status}: ${result.text.slice(0, 300)}`);
        }
        if (ripristina && precedente !== enabled) {
            this.cleanup.add(this.owner, `ripristina shared_with_pool=${precedente ? 1 : 0} sul contenuto ${id}`, async () => {
                const restored = await this.content.setPoolSharing(id, precedente);
                if (!restored.ok && restored.status !== 404) {
                    throw new Error(`ripristino share-pool ${id} → ${restored.status}: ${restored.text.slice(0, 200)}`);
                }
            });
        }
        return result.body;
    }

    /**
     * Recupera dal pool una copia del contenuto altrui e registra la
     * cancellazione della copia. Va chiamata sulla factory di chi recupera,
     * perché è la sua sessione a doverla cancellare.
     */
    async recoverFromPool(id: number, subjectCode: string): Promise<number> {
        const targetSubjectId = await this.curriculum.subjectId(subjectCode);
        const result = await this.share.recoverFromPool(id, targetSubjectId);
        if (!result.ok || result.body === null) {
            throw new Error(`POST /api/teacher/pool/recover/${id} → ${result.status}: ${result.text.slice(0, 300)}`);
        }
        const nuovo = result.body.new_id;
        this.cleanup.add(this.owner, `cancella la copia ${nuovo} recuperata dal pool`, async () => {
            const cancellata = await this.content.delete(nuovo);
            if (!cancellata.ok && cancellata.status !== 404) {
                throw new Error(`cancellazione della copia ${nuovo} → ${cancellata.status}: ${cancellata.text.slice(0, 200)}`);
            }
        });
        return nuovo;
    }

    /** Id di un collega dal suo nome utente (sostituisce gli id scritti a mano nelle spec). */
    async colleagueId(username: string): Promise<number> {
        const list = await this.share.colleagues();
        const found = list.colleagues.find((c) => c.username === username);
        if (!found) {
            throw new Error(`[factory] «${username}» non è tra i colleghi di questo docente (${list.colleagues.length} colleghi elencati)`);
        }
        return found.id;
    }
}
