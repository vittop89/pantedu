<?php

/**
 * Phase PDF-Import — configurazione del tool di estrazione esercizi da PDF.
 *
 * Reimplementazione PHP-nativa, multi-provider (Anthropic / OpenAI / Ollama).
 * Tutte le chiavi API vivono SOLO lato server in `.env` (mai sul client e mai
 * in `.env.example`/repo). Hardening guidato dal pentest LLM-PY-001:
 *   - budget/token cap per docente   (LLM10 unbounded consumption)
 *   - allowlist base-URL Ollama      (SSRF)
 *   - SHA-256 content addressing      (no MD5)
 *
 * Disabilitazione: PDF_IMPORT_ENABLED=false → controller risponde 503 pulito.
 */

declare(strict_types=1);

return [
    // Master switch. Se false, gli endpoint rispondono 503 feature_disabled.
    'enabled' => filter_var($_ENV['PDF_IMPORT_ENABLED'] ?? false, FILTER_VALIDATE_BOOL),

    // Provider di default quando il client non ne specifica uno valido.
    'default_provider' => $_ENV['PDF_IMPORT_DEFAULT_PROVIDER'] ?? 'anthropic',

    // Ollama è opt-in esplicito: il base_url ha un default, quindi senza questo
    // flag NON viene considerato un provider "pronto" (evita di mostrarlo quando
    // nessun server Ollama è realmente in ascolto).
    'ollama_enabled' => filter_var($_ENV['PDF_IMPORT_OLLAMA_ENABLED'] ?? false, FILTER_VALIDATE_BOOL),

    // Vincoli di upload/rasterizzazione.
    'max_pages'      => (int)($_ENV['PDF_IMPORT_MAX_PAGES'] ?? 40),
    'max_pdf_bytes'  => (int)($_ENV['PDF_IMPORT_MAX_PDF_BYTES'] ?? 25 * 1024 * 1024), // 25 MiB
    'dpi'            => (int)($_ENV['PDF_IMPORT_DPI'] ?? 300),

    // Estrazione in BACKGROUND: la POST /session lancia il worker detached (exec)
    // → i poll restano veloci e il log si aggiorna live; niente blocco sui PDF
    // lunghi. Se false (o exec disabilitato), fallback: l'estrazione avanza sui
    // poll GET (1 pagina per poll, può sembrare "bloccato" durante la chiamata).
    'async_extraction' => filter_var($_ENV['PDF_IMPORT_ASYNC'] ?? true, FILTER_VALIDATE_BOOL),
    // Binario PHP CLI per il worker background (≠ php-fpm). Default Debian/Ubuntu.
    'php_cli' => $_ENV['PDF_IMPORT_PHP_CLI'] ?? '/usr/bin/php',

    // Timeout HTTP (secondi) per chiamata vision a un provider. 60s: con un
    // modello vision VELOCE (gemini-flash, gpt-4o-mini) bastano ~10-20s; se il
    // modello configurato è lento (es. qwen-plus) fallisce prima e ritenta.
    'provider_timeout' => (int)($_ENV['PDF_IMPORT_PROVIDER_TIMEOUT'] ?? 60),

    // Scan numeri a 2 fasi (legacy NumberScanner): una passata vision dedicata
    // legge SOLO i numeri dei badge → corregge i numeri esercizio (meno errori).
    // Aggiunge 1 chiamata vision per pagina → DEFAULT OFF: raddoppia le chiamate
    // e con provider lenti/instabili (OpenRouter) l'estrazione resta a lungo su
    // "0/1". Attivabile con PDF_IMPORT_NUMBER_SCAN=true se il provider è veloce.
    // ON: l'estrazione gira in background (non blocca), quindi la passata extra
    // per i numeri vale la pena → numeri esercizio molto più accurati (con un
    // modello vision tipo qwen-vl). Override: PDF_IMPORT_NUMBER_SCAN=false.
    'number_scan' => filter_var($_ENV['PDF_IMPORT_NUMBER_SCAN'] ?? true, FILTER_VALIDATE_BOOL),

    // Passata difficoltà automatica a fine estrazione (agente vision dedicato che
    // conta i pallini, tipo legacy). ON di default: in background non blocca e
    // corregge le difficoltà (l'estrazione le sbaglia). Modello = operazione
    // 'difficulty' (consigliato qwen-vl). Override: PDF_IMPORT_AUTO_DIFFICULTY=false.
    'auto_difficulty' => filter_var($_ENV['PDF_IMPORT_AUTO_DIFFICULTY'] ?? true, FILTER_VALIDATE_BOOL),
    // Argomento automatico (riempie i topic vuoti) e traduzione IT (solo righe in
    // lingua straniera) a fine estrazione. Ogni passata ri-salva → tabella
    // incrementale. Override: PDF_IMPORT_AUTO_TOPICS / PDF_IMPORT_AUTO_TRANSLATION.
    'auto_topics'      => filter_var($_ENV['PDF_IMPORT_AUTO_TOPICS'] ?? true, FILTER_VALIDATE_BOOL),
    'auto_translation' => filter_var($_ENV['PDF_IMPORT_AUTO_TRANSLATION'] ?? true, FILTER_VALIDATE_BOOL),

    // Soluzioni AI: quanti esercizi per richiesta web (il client cicla finché
    // remaining>0). Tenuto basso per restare sotto fastcgi_read_timeout.
    'solutions_per_request' => (int)($_ENV['PDF_IMPORT_SOLUTIONS_PER_REQUEST'] ?? 2),

    // Retention (giorni): dopo questo TTL il worker cancella file + righe delle
    // sessioni (incluse quelle abbandonate). Le sessioni inserite cancellano i
    // file subito dopo l'insert. Tieni basso per minimizzare materiale a riposo.
    'retention_days' => (int)($_ENV['PDF_IMPORT_RETENTION_DAYS'] ?? 7),

    // Path al CA bundle (Windows/XAMPP fix cURL error 60). Vuoto su Linux/prod.
    'ca_bundle' => $_ENV['PDF_IMPORT_CA_BUNDLE']
        ?? $_ENV['TEX_COMPILE_CA_BUNDLE']
        ?? $_ENV['DRIVE_CA_BUNDLE']
        ?? 'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt',

    // Budget anti-abuso per docente (LLM10). Cap giornaliero di token in+out
    // sommati su tutte le sessioni del docente. 0 = nessun cap (sconsigliato).
    'budget' => [
        'daily_tokens_per_teacher' => (int)($_ENV['PDF_IMPORT_DAILY_TOKENS'] ?? 2_000_000),
    ],

    // Configurazione per-provider. `key` SOLO da env; `endpoint` costante per i
    // cloud, allowlistato per Ollama (SSRF guard).
    'providers' => [
        'anthropic' => [
            'key'      => $_ENV['PDF_IMPORT_ANTHROPIC_KEY'] ?? '',
            'endpoint' => 'https://api.anthropic.com/v1/messages',
            'model'    => $_ENV['PDF_IMPORT_ANTHROPIC_MODEL'] ?? 'claude-opus-4-8',
            'api_version' => '2023-06-01',
        ],
        'openai' => [
            'key'      => $_ENV['PDF_IMPORT_OPENAI_KEY'] ?? '',
            'endpoint' => 'https://api.openai.com/v1/chat/completions',
            'model'    => $_ENV['PDF_IMPORT_OPENAI_MODEL'] ?? 'gpt-4o',
        ],
        // Qwen via Alibaba Cloud Model Studio (DashScope) — endpoint
        // OpenAI-compatibile ("compatible-mode"). Default: regione internazionale.
        // Cina/Pechino: https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions
        // Modelli vision: qwen-vl-max | qwen-vl-plus | qwen2.5-vl-72b-instruct
        'qwen' => [
            'key'      => $_ENV['PDF_IMPORT_QWEN_KEY'] ?? '',
            'endpoint' => $_ENV['PDF_IMPORT_QWEN_ENDPOINT']
                ?? 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions',
            'model'    => $_ENV['PDF_IMPORT_QWEN_MODEL'] ?? 'qwen-vl-max',
        ],
        // OpenRouter — gateway OpenAI-compatibile verso decine di modelli vision.
        // Il modello (slug "vendor/model") si sceglie da popup o .env. Default:
        // un modello vision economico; cambialo con quelli da openrouter.ai/models.
        'openrouter' => [
            'key'      => $_ENV['PDF_IMPORT_OPENROUTER_KEY'] ?? '',
            'endpoint' => $_ENV['PDF_IMPORT_OPENROUTER_ENDPOINT']
                ?? 'https://openrouter.ai/api/v1/chat/completions',
            // Default: modello vision VELOCE e affidabile su OpenRouter. (Lo slug
            // gemini-2.0-flash-001 dava 404 "No endpoints found".) Cambialo dal
            // popup "Chiavi LLM" con quelli di openrouter.ai/models.
            'model'    => $_ENV['PDF_IMPORT_OPENROUTER_MODEL'] ?? 'openai/gpt-4o-mini',
        ],
        'ollama' => [
            // Locale: nessuna key. base_url validato contro allowlist.
            'base_url' => $_ENV['PDF_IMPORT_OLLAMA_BASE_URL'] ?? 'http://127.0.0.1:11434',
            'model'    => $_ENV['PDF_IMPORT_OLLAMA_MODEL'] ?? 'qwen2.5vl:7b',
        ],
    ],

    // SSRF: host ammessi come base-URL Ollama. Solo loopback/LAN esplicita.
    // Qualsiasi altro host → SsrfGuard rifiuta (no metadata endpoints, no SSRF).
    'ollama_allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string)($_ENV['PDF_IMPORT_OLLAMA_ALLOWED_HOSTS'] ?? '127.0.0.1,localhost'))
    ))),

    // Rate limit (bucket → max richieste/finestra), applicati via middleware
    // `rate:bucket,N` sulle rotte. Qui solo documentazione dei valori usati.
    'rate' => [
        'pdf_import'     => (int)($_ENV['PDF_IMPORT_RATE_GENERIC'] ?? 30),
        'pdf_import_llm' => (int)($_ENV['PDF_IMPORT_RATE_LLM'] ?? 12),
    ],
];
