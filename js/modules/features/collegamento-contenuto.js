/**
 * Il collegamento di un contenuto del docente nelle sidepage BES/DSA e Risorse
 * docente (risdoc-sidepage.js). Provato in
 * tests/js-unit/collegamento-contenuto.test.js e, dal vero, in
 * tests/e2e/area-docente/creazione-e-modifica-per-sidepage.spec.js.
 *
 * 15/9/2026. Ogni sidepage crea qualsiasi tipo di documento (ADR-027), ma qui
 * ogni contenuto puntava a `/studio/bes/…` o `/studio/risdoc/…` per argomento:
 * una mappa o un esercizio creati in BES/DSA aprivano «Nessun documento BES/DSA
 * per questa combinazione». Misurato con la spec: 8 casi su 10 in queste due
 * sidepage. Un contenuto che non è un documento apre la pagina del suo tipo, con
 * `?ids=` come le altre sidepage (db-sidepage.js, itemLiHtml).
 */

const TIPI_CON_PAGINA_PROPRIA = new Set(["mappa", "esercizio", "verifica"]);

/**
 * @param {{ id: number|string, tipo?: string, origine?: string, ind: string, cls: string,
 *           subj: string, topic: string, ternaScoped?: boolean }} contenuto
 * @returns {string}
 */
export function hrefContenutoRisdoc({ id, tipo, origine, ind, cls, subj, topic, ternaScoped = false }) {
    const terna = `${ind}/${cls}/${subj}/${encodeURIComponent(topic)}`;
    if (TIPI_CON_PAGINA_PROPRIA.has(String(tipo || ""))) {
        return `/studio/${tipo}/${terna}?ids=${encodeURIComponent(id)}`;
    }
    const base = `/studio/${origine === "strcomp" ? "bes" : "risdoc"}/${terna}`;
    // ADR-030 — un documento terna_scoped apre sempre questa riga (?ids), e la
    // terna dell'indirizzo fa solo da lente per i valori dei campi 🔗.
    return ternaScoped && id ? `${base}?ids=${encodeURIComponent(id)}` : base;
}
