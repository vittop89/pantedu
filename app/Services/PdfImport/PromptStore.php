<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

use App\Core\Config;

/**
 * Phase PDF-Import — override dei PROMPT di sistema per operazione (UI admin).
 *
 * Permette di personalizzare i prompt dati agli LLM (estrazione, numeri,
 * argomenti, traduzione, soluzioni per categoria) senza toccare il codice. Se per
 * una chiave non c'è override, si usa il default di codice (OperationPrompts).
 *
 * Persistito server-side in `storage/config/pdf_import_prompts.json` (0600).
 * Formato: { "<key>": "testo prompt", ... }
 */
final class PromptStore extends JsonConfigStore
{
    protected function fileName(): string
    {
        return 'prompts.json';
    }

    public function get(string $key): ?string
    {
        $v = $this->readAll()[$key] ?? null;
        return is_string($v) && trim($v) !== '' ? $v : null;
    }

    /** Salva un override; vuoto = rimuove (torna al default di codice). */
    public function set(string $key, string $prompt): void
    {
        $key = trim($key);
        $data = $this->readOwn(); // solo il file su cui scriviamo (preset o override)
        if (trim($prompt) === '') {
            unset($data[$key]);
        } else {
            $data[$key] = $prompt;
        }
        $this->persist($data);
    }
}
