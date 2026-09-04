<?php

declare(strict_types=1);

namespace App\Services\PdfImport\Provider;

/**
 * Phase PDF-Import — contratto minimo per un provider di estrazione vision.
 *
 * Una implementazione riceve i byte PNG di UNA pagina + system/user prompt e
 * ritorna il testo grezzo prodotto dal modello (atteso JSON, ma il parsing è
 * responsabilità del chiamante: l'output del modello è SEMPRE dato non fidato).
 *
 * Le chiavi API non transitano mai da qui: ogni client le legge da Config/.env
 * nel proprio costruttore/factory. Vedi ProviderRouter.
 */
interface ProviderInterface
{
    /** Nome canonico del provider ("anthropic"|"openai"|"ollama"). */
    public function name(): string;

    /**
     * Estrae da una singola immagine di pagina.
     *
     * @param string $imagePng  byte grezzi del PNG (NON base64)
     * @param string $systemPrompt istruzioni di sistema (fidate)
     * @param string $userPrompt   contesto/istruzione utente (fidato)
     * @return array{text:string, model:string, tokens_in:int, tokens_out:int}
     * @throws \RuntimeException su errore di rete/HTTP/provider
     */
    public function extract(string $imagePng, string $systemPrompt, string $userPrompt): array;

    /**
     * Estrae da PIÙ immagini in UNA chiamata (in ordine). Usato dal crop-zoom
     * difficoltà: N ritagli ingranditi → conteggio pallini per ciascuno.
     *
     * @param list<string> $imagesPng byte PNG (NON base64), in ordine
     * @return array{text:string, model:string, tokens_in:int, tokens_out:int}
     * @throws \RuntimeException su errore o se il provider non supporta multi-immagine
     */
    public function extractMany(array $imagesPng, string $systemPrompt, string $userPrompt): array;

    /**
     * Completamento testuale (no immagine) — usato per soluzioni AI e figure
     * TikZ (Fase 4). Stesso shape di ritorno di extract().
     *
     * @return array{text:string, model:string, tokens_in:int, tokens_out:int}
     * @throws \RuntimeException su errore di rete/HTTP/provider
     */
    public function complete(string $systemPrompt, string $userPrompt): array;
}
