<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

use App\Core\Config;

/**
 * Phase PDF-Import — modello LLM PER OPERAZIONE.
 *
 * Operazioni diverse hanno bisogni diversi: l'estrazione vision (testo, struttura,
 * colori, difficoltà) richiede un modello forte; numeri/pagina, argomenti,
 * traduzione, soluzioni possono usare modelli più rapidi/economici. Qui si mappa
 * ogni operazione a un (provider, modello) opzionale; se assente, si ripiega sul
 * provider/modello della sessione (ProviderRouter).
 *
 * Persistito server-side in `storage/config/pdf_import_operations.json` (0600).
 * Formato: { "<operation>": { "provider": "openrouter", "model": "vendor/model" } }
 */
final class OperationModelStore extends JsonConfigStore
{
    /** Operazioni note (id => etichetta UI). L'ordine è quello mostrato in pagina. */
    public const OPERATIONS = [
        'extraction'  => 'Estrazione esercizi (testo, struttura, colori, difficoltà)',
        'difficulty'  => 'Numero + Difficoltà — crop-zoom badge (2° passaggio)',
        'numbers'     => 'Numeri esercizio + pagina (scansione)',
        'topics'      => 'Argomento automatico',
        'translation' => 'Traduzione in italiano',
        'solutions'   => 'Soluzioni AI',
    ];

    protected function fileName(): string
    {
        return 'operations.json';
    }

    /** @return array<string,array{provider?:string,model?:string}> solo operazioni note. */
    private function all(): array
    {
        $out = [];
        foreach ($this->readAll() as $op => $entry) {
            if (is_array($entry) && isset(self::OPERATIONS[$op])) {
                $out[$op] = $entry;
            }
        }
        return $out;
    }

    /** @return array{provider:string,model:string}|null override per l'operazione. */
    public function get(string $operation): ?array
    {
        $e = $this->all()[$operation] ?? null;
        if (!is_array($e)) {
            return null;
        }
        $provider = strtolower(trim((string)($e['provider'] ?? '')));
        $model    = trim((string)($e['model'] ?? ''));
        if ($provider === '' && $model === '') {
            return null;
        }
        return ['provider' => $provider, 'model' => $model];
    }

    /** Salva (provider e modello entrambi opzionali: vuoti = usa default sessione). */
    public function set(string $operation, string $provider, string $model): void
    {
        $operation = strtolower(trim($operation));
        if (!isset(self::OPERATIONS[$operation])) {
            throw new \RuntimeException('invalid_operation');
        }
        $provider = strtolower(trim($provider));
        $model = trim($model);
        $data = $this->readOwn(); // solo il file su cui scriviamo (preset o override)
        if ($provider === '' && $model === '') {
            unset($data[$operation]); // reset → default (preset/codice)
        } else {
            $data[$operation] = ['provider' => $provider, 'model' => $model];
        }
        $this->persist($data);
    }

    /** Stato per la UI: tutte le operazioni con l'eventuale override. */
    public function status(): array
    {
        $cur = $this->all();
        $out = [];
        foreach (self::OPERATIONS as $op => $label) {
            $out[$op] = [
                'label'    => $label,
                'provider' => (string)($cur[$op]['provider'] ?? ''),
                'model'    => (string)($cur[$op]['model'] ?? ''),
            ];
        }
        return $out;
    }
}
