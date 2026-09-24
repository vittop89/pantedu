/**
 * Nomi unici per i dati di prova: `e2e-<spec>-<tipo>-<istante in base 36>`.
 *
 * Responsabilità: rendere riconoscibile chi ha creato un dato (la spec) e
 * quando, così i residui di un giro interrotto si trovano con `e2e-` e non
 * collidono con i titoli reali del docente (vincolo di unicità titolo per
 * docente e tipo, TeacherContentController::store).
 *
 * Può importare: node. Non può importare: tutto il resto.
 */
import * as path from "node:path";

export class Naming {
    private counter = 0;
    readonly spec: string;

    constructor(specFile: string) {
        this.spec = path
            .basename(specFile)
            .replace(/\.spec\.[jt]s$/, "")
            .replace(/[^a-z0-9]+/gi, "-")
            .toLowerCase();
    }

    /** Nome unico per un dato di prova, sicuro anche in un segmento di URL. */
    unique(kind: string): string {
        this.counter++;
        return `e2e-${this.spec}-${kind}-${Date.now().toString(36)}${this.counter.toString(36)}`;
    }
}
