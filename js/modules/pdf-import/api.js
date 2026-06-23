/**
 * Phase PDF-Import — wrapper API client (CSRF + JSON).
 *
 * Usa fetchJson/fetchCsrf centralizzati (gestione WAF challenge + sessione
 * scaduta). Le chiavi LLM non transitano mai dal client: il provider è solo
 * un nome ("anthropic"|"openai"|"ollama").
 */
import { fetchJson, fetchCsrf } from "../core/dom-utils.js";

const BASE = "/api/teacher/pdf-import";

async function postJson(url, body) {
    const token = await fetchCsrf();
    return fetchJson(url, {
        method: "POST",
        headers: { "x-csrf-token": token, "Content-Type": "application/json" },
        body: JSON.stringify(body),
    });
}

/** Crea una sessione caricando il PDF (multipart). */
export async function createSession(file, provider) {
    const token = await fetchCsrf();
    const fd = new FormData();
    fd.append("file", file);
    fd.append("provider", provider);
    // NIENTE Content-Type manuale: il browser imposta il boundary multipart.
    return fetchJson(`${BASE}/session`, {
        method: "POST",
        headers: { "x-csrf-token": token },
        body: fd,
    });
}

export function getStatus(id) {
    return fetchJson(`${BASE}/session/${id}`);
}

/** Interrompe l'estrazione in corso (marca la sessione 'cancelled'). */
export function stopSession(id) {
    return postJson(`${BASE}/session/${id}/stop`, {});
}

export function editCell(id, rowId, field, value) {
    return postJson(`${BASE}/session/${id}/cell`, { row_id: rowId, field, value });
}

export function bulkEdit(id, rowIds, field, value) {
    return postJson(`${BASE}/session/${id}/bulk`, { row_ids: rowIds, field, value });
}

export function generateSolutions(id) {
    return postJson(`${BASE}/session/${id}/solutions`, {});
}

/** Assegna automaticamente l'Argomento a ogni esercizio (feature legacy). */
export function generateTopics(id) {
    return postJson(`${BASE}/session/${id}/topics`, {});
}

/** Ricalcola la difficoltà col crop-zoom dei pallini (2° passaggio vision). */
export function refineDifficulty(id) {
    return postJson(`${BASE}/session/${id}/difficulty`, {});
}

/** Traduce in italiano gli esercizi in lingua straniera (incrementale). */
export function translate(id) {
    return postJson(`${BASE}/session/${id}/translate`, {});
}

export function insert(id, ctx, rowIds, rowTargets) {
    return postJson(`${BASE}/session/${id}/insert`, {
        ...ctx,
        row_ids: rowIds,
        // Mappa per-riga { rowId: {content_id, group} } → inserimento nelle verifiche.
        row_targets: rowTargets || {},
    });
}

export function pageImageUrl(id, n) {
    return `${BASE}/session/${id}/page/${n}`;
}

/** Codici origine (fonti) del docente per la colonna "Origine". */
export function getOrigins() {
    return fetchJson("/api/teacher/origins.json");
}

/** Anteprima renderizzata (LaTeX) di una o più righe, come esercizi reali. */
export function previewRows(id, ids) {
    return fetchJson(`${BASE}/session/${id}/preview?rows=${encodeURIComponent((ids || []).join(","))}`);
}

// ── Gestione chiavi provider (admin) ─────────────────────────────────────────
export function getProviderKeys() {
    return fetchJson(`${BASE}/provider-keys`);
}

/** Modelli vision disponibili dal provider (live per OpenRouter). */
export function getProviderModels(provider) {
    return fetchJson(`${BASE}/provider-models?provider=${encodeURIComponent(provider)}`);
}

export function saveProviderKey(provider, key, model) {
    return postJson(`${BASE}/provider-keys`, { provider, key, model: model || "" });
}

// scope: "global" (preset condiviso, solo admin) | "personal" (override del docente).
const sc = (scope) => `?scope=${encodeURIComponent(scope || "personal")}`;

/** Modelli per operazione (estrazione/numeri/argomenti/traduzione/soluzioni). */
export function getProviderOperations(scope) {
    return fetchJson(`${BASE}/provider-operations${sc(scope)}`);
}

export function saveProviderOperation(operation, provider, model, scope) {
    return postJson(`${BASE}/provider-operations${sc(scope)}`, { operation, provider: provider || "", model: model || "" });
}

/** Abilita/disabilita la cache delle risposte LLM. */
export function toggleCache(enabled) {
    return postJson(`${BASE}/provider-cache`, { enabled: !!enabled });
}

/** Abilita/disabilita una passata automatica (auto_topics/auto_difficulty/auto_translation). */
export function toggleSetting(key, enabled, scope) {
    return postJson(`${BASE}/setting${sc(scope)}`, { key, enabled: !!enabled });
}

/** Override del prompt di sistema di un'operazione (prompt vuoto = ripristina default). */
export function saveProviderPrompt(key, prompt, scope) {
    return postJson(`${BASE}/provider-prompt${sc(scope)}`, { key, prompt: prompt || "" });
}

export function clearProviderKey(provider) {
    return postJson(`${BASE}/provider-keys/clear`, { provider });
}
