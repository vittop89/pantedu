/**
 * Client del curriculum del docente (indirizzi, classi, materie).
 *
 * Responsabilità: leggere `GET /curriculum` e rispondere alle due domande che
 * la suite pone: qual è l'identificativo della mia materia con un certo codice
 * (serve come `target_subject_id` per recuperare un contenuto dal pool) e qual
 * è il mio identificativo di docente (le voci che possiedo portano
 * `owner_user_id`), che le spec storiche scrivevano a mano come 77 e 140.
 *
 * Può importare: http, types. Non può importare: pagine, fixture, factory.
 */
import type { HttpClient } from "./http";
import type { Curriculum, CurriculumEntry } from "./types";

export class CurriculumApi {
    constructor(private readonly http: HttpClient) {}

    async load(): Promise<Curriculum> {
        return this.http.getJson<Curriculum>("/curriculum");
    }

    /**
     * Le spunte del docente nell'istituto attivo, anche quelle spente, più il
     * vocabolario della scuola: è ciò che il profilo disegna come caselle.
     */
    async completo(instituteId?: number): Promise<Curriculum> {
        const params: Record<string, string> = { include_inactive: "1" };
        if (instituteId !== undefined) params["institute_id"] = String(instituteId);
        return this.http.getJson<Curriculum>("/api/teacher/curriculum", params);
    }

    /** Accende o spegne una spunta esistente (ADR-035). */
    async spunta(entryId: number, active: boolean): Promise<void> {
        await this.http.postForm<{ ok: boolean }>(`/api/teacher/curriculum/${entryId}/update`, {
            active: active ? "true" : "false",
        });
    }

    async materie(): Promise<readonly CurriculumEntry[]> {
        return (await this.load()).curriculum.materie ?? [];
    }

    /** Identificativo della propria materia con quel codice (es. «MAT»). */
    async subjectId(code: string): Promise<number> {
        const materie = await this.materie();
        const mia = materie.find((m) => m.code === code && m.owner_user_id !== undefined) ?? materie.find((m) => m.code === code);
        if (!mia) {
            throw new Error(`[curriculum] nessuna materia con codice «${code}» fra le ${materie.length} del docente`);
        }
        return mia.id;
    }

    /**
     * Identificativo del docente in sessione, dedotto dalle voci di curriculum
     * che possiede: `/auth/user-info` espone il nome utente ma non l'id.
     */
    async teacherId(): Promise<number> {
        const materie = await this.materie();
        const conProprietario = materie.find((m) => typeof m.owner_user_id === "number" && m.owner_user_id > 0);
        if (!conProprietario?.owner_user_id) {
            throw new Error("[curriculum] nessuna voce di curriculum posseduta dal docente: impossibile dedurre il suo identificativo");
        }
        return conProprietario.owner_user_id;
    }
}
