<?php

declare(strict_types=1);

namespace App\Services\PdfImport\Provider;

use App\Core\Config;
use App\Repositories\PdfImportSessionRepository;
use App\Services\PdfImport\OperationModelStore;
use App\Services\PdfImport\ProviderKeyStore;

/**
 * Phase PDF-Import — selezione e costruzione del provider vision dalla config.
 *
 * Responsabilità di sicurezza centralizzate qui (cfr. LLM-PY-001):
 *   - chiavi API lette SOLO da Config/.env (mai dal request body)
 *   - base-URL Ollama validato via SsrfGuard (SSRF)
 *   - gate di budget token/giorno per docente (LLM10)
 *
 * I client concreti (Anthropic/OpenAI/Ollama) vengono istanziati lazy: se la
 * classe non è ancora disponibile o non è configurata, lancia eccezione gestita
 * dal controller come 503/422.
 */
final class ProviderRouter
{
    private const CLIENTS = [
        'anthropic' => AnthropicVisionClient::class,
        'openai'    => OpenAiVisionClient::class,
        // Qwen via Alibaba Cloud Model Studio (DashScope) — endpoint
        // OpenAI-compatibile: riusa il client OpenAI puntando a compatible-mode.
        'qwen'      => OpenAiVisionClient::class,
        // OpenRouter — gateway multi-modello, anch'esso OpenAI-compatibile.
        'openrouter' => OpenAiVisionClient::class,
        'ollama'    => OllamaVisionClient::class,
    ];

    public function __construct(
        private readonly PdfImportSessionRepository $sessions = new PdfImportSessionRepository(),
        private readonly ProviderKeyStore $keys = new ProviderKeyStore(),
        private readonly OperationModelStore $operations = new OperationModelStore(),
    ) {
    }

    /** Chiave effettiva del provider: store runtime (UI) → .env (Config). */
    public function keyFor(string $provider): string
    {
        $fromStore = $this->keys->get($provider);
        if ($fromStore !== null && $fromStore !== '') {
            return $fromStore;
        }
        return (string)Config::get("pdf_import.providers.$provider.key", '');
    }

    /** Modello effettivo: override store (UI) → .env (Config). */
    public function modelFor(string $provider): string
    {
        $fromStore = $this->keys->getModel($provider);
        if ($fromStore !== null && $fromStore !== '') {
            return $fromStore;
        }
        return (string)Config::get("pdf_import.providers.$provider.model", '');
    }

    /** Provider configurati e utilizzabili (chiave presente / ollama allowlisted). */
    public function availableProviders(): array
    {
        $out = [];
        foreach (array_keys(self::CLIENTS) as $name) {
            if ($this->isConfigured($name)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    public function isConfigured(string $name): bool
    {
        $cfg = (array)Config::get("pdf_import.providers.$name", []);
        return match ($name) {
            'anthropic', 'openai', 'qwen', 'openrouter' => $this->keyFor($name) !== '',
            // Ollama (LLM LOCALE): pronto se il base_url è impostato dal popup
            // (store) OPPURE da .env con opt-in PDF_IMPORT_OLLAMA_ENABLED.
            'ollama' => $this->keys->get('ollama') !== null
                || (($cfg['base_url'] ?? '') !== '' && (bool)Config::get('pdf_import.ollama_enabled', false)),
            default => false,
        };
    }

    /** Base URL effettiva di Ollama: store (popup) → .env. */
    private function ollamaBaseUrl(): string
    {
        $fromStore = $this->keys->get('ollama'); // per Ollama lo "slot key" contiene il base_url
        if ($fromStore !== null && $fromStore !== '') {
            return $fromStore;
        }
        return (string)Config::get('pdf_import.providers.ollama.base_url', '');
    }

    /**
     * Normalizza il nome richiesto: se non valido/non configurato, ripiega sul
     * default; se nemmeno quello è configurato, lancia 'no_provider_configured'.
     */
    public function resolveName(?string $requested): string
    {
        $requested = is_string($requested) ? strtolower(trim($requested)) : '';
        if ($requested !== '' && isset(self::CLIENTS[$requested]) && $this->isConfigured($requested)) {
            return $requested;
        }
        $default = (string)Config::get('pdf_import.default_provider', 'anthropic');
        if ($this->isConfigured($default)) {
            return $default;
        }
        $available = $this->availableProviders();
        if ($available !== []) {
            return $available[0];
        }
        throw new \RuntimeException('no_provider_configured');
    }

    /**
     * Client per una OPERAZIONE: usa il (provider, modello) configurato per
     * l'operazione (OperationModelStore) e, se assente, ripiega sul provider della
     * sessione + modello di default. Permette modelli diversi per estrazione,
     * numeri, argomenti, traduzione, soluzioni.
     */
    public function operationClient(string $operation, string $fallbackProvider): ProviderInterface
    {
        $op = $this->operations->get($operation);
        $provider = ($op['provider'] ?? '') !== '' ? (string)$op['provider'] : $fallbackProvider;
        $provider = $this->resolveName($provider);
        $model = ($op['model'] ?? '') !== '' ? (string)$op['model'] : null;
        return $this->build($provider, $model);
    }

    /** Modello che userà operationClient() per l'operazione (per log/UI). */
    public function modelForOperation(string $operation, string $fallbackProvider): string
    {
        $op = $this->operations->get($operation);
        $provider = ($op['provider'] ?? '') !== '' ? (string)$op['provider'] : $fallbackProvider;
        try {
            $provider = $this->resolveName($provider);
        } catch (\Throwable) {
            return '';
        }
        return ($op['model'] ?? '') !== '' ? (string)$op['model'] : $this->modelFor($provider);
    }

    /** Costruisce il client concreto per il provider dato (modello opzionale override). */
    public function build(string $name, ?string $modelOverride = null): ProviderInterface
    {
        if (!isset(self::CLIENTS[$name]) || !$this->isConfigured($name)) {
            throw new \RuntimeException('provider_unavailable');
        }
        $class = self::CLIENTS[$name];
        if (!class_exists($class)) {
            throw new \RuntimeException('provider_unavailable');
        }
        $cfg      = (array)Config::get("pdf_import.providers.$name", []);
        $timeout  = (int)Config::get('pdf_import.provider_timeout', 60);
        $caBundle = (string)Config::get('pdf_import.ca_bundle', '');
        $ovr      = $modelOverride !== null && $modelOverride !== '' ? $modelOverride : null;
        // Modello effettivo per il provider: override operazione → store/.env.
        $modelFor = fn(string $p): string => $ovr ?? $this->modelFor($p);

        return match ($name) {
            'anthropic' => new AnthropicVisionClient(
                apiKey:   $this->keyFor('anthropic'),
                endpoint: (string)$cfg['endpoint'],
                model:    $modelFor('anthropic'),
                apiVersion: (string)($cfg['api_version'] ?? '2023-06-01'),
                timeoutSeconds: $timeout,
                caBundle: $caBundle,
            ),
            'openai' => new OpenAiVisionClient(
                apiKey:   $this->keyFor('openai'),
                endpoint: (string)$cfg['endpoint'],
                model:    $modelFor('openai'),
                timeoutSeconds: $timeout,
                caBundle: $caBundle,
            ),
            // Qwen (DashScope compatible-mode) — stesso wire format di OpenAI.
            'qwen' => new OpenAiVisionClient(
                apiKey:   $this->keyFor('qwen'),
                endpoint: (string)$cfg['endpoint'],
                model:    $modelFor('qwen'),
                timeoutSeconds: $timeout,
                caBundle: $caBundle,
            ),
            // OpenRouter — gateway multi-modello OpenAI-compatibile. Il modello
            // (es. openai/gpt-4o-mini) lo sceglie l'utente da popup/.env/operazione.
            'openrouter' => new OpenAiVisionClient(
                apiKey:   $this->keyFor('openrouter'),
                endpoint: (string)$cfg['endpoint'],
                model:    $modelFor('openrouter'),
                timeoutSeconds: $timeout,
                caBundle: $caBundle,
            ),
            'ollama' => new OllamaVisionClient(
                baseUrl: SsrfGuard::assertOllamaBaseUrl(
                    $this->ollamaBaseUrl(),
                    (array)Config::get('pdf_import.ollama_allowed_hosts', [])
                ),
                model:   $modelFor('ollama') !== '' ? $modelFor('ollama') : (string)($cfg['model'] ?? 'qwen2.5vl'),
                timeoutSeconds: $timeout,
                caBundle: $caBundle,
            ),
            default => throw new \RuntimeException('provider_unavailable'),
        };
    }

    /**
     * Gate di budget: lancia 'budget_exceeded' se il docente ha superato il
     * cap giornaliero di token. cap=0 → nessun limite.
     */
    public function assertBudget(int $teacherId): void
    {
        $cap = (int)Config::get('pdf_import.budget.daily_tokens_per_teacher', 0);
        if ($cap <= 0) {
            return;
        }
        if ($this->sessions->tokensUsedToday($teacherId) >= $cap) {
            throw new \RuntimeException('budget_exceeded');
        }
    }
}
