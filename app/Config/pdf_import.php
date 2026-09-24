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
    'enabled' => \App\Core\Config::booleanoDallAmbiente('PDF_IMPORT_ENABLED', false),

    // Provider di default quando il client non ne specifica uno valido.
    // Default = ollama: il percorso SENZA trasferimenti verso terzi. Se Ollama
    // non è configurato, ProviderRouter::resolveName ripiega da solo sul primo
    // provider disponibile, quindi il default non blocca nulla — sposta solo
    // la scelta implicita su quella che non richiede DPA né SCC.
    'default_provider' => $_ENV['PDF_IMPORT_DEFAULT_PROVIDER'] ?? 'ollama',

    // Ollama è opt-in esplicito: il base_url ha un default, quindi senza questo
    // flag NON viene considerato un provider "pronto" (evita di mostrarlo quando
    // nessun server Ollama è realmente in ascolto).
    'ollama_enabled' => \App\Core\Config::booleanoDallAmbiente('PDF_IMPORT_OLLAMA_ENABLED', false),

    // Vincoli di upload/rasterizzazione.
    'max_pages'      => (int)($_ENV['PDF_IMPORT_MAX_PAGES'] ?? 40),
    'max_pdf_bytes'  => (int)($_ENV['PDF_IMPORT_MAX_PDF_BYTES'] ?? 25 * 1024 * 1024), // 25 MiB
    'dpi'            => (int)($_ENV['PDF_IMPORT_DPI'] ?? 300),

    // Estrazione in BACKGROUND: la POST /session lancia il worker detached (exec)
    // → i poll restano veloci e il log si aggiorna live; niente blocco sui PDF
    // lunghi. Se false (o exec disabilitato), fallback: l'estrazione avanza sui
    // poll GET (1 pagina per poll, può sembrare "bloccato" durante la chiamata).
    'async_extraction' => \App\Core\Config::booleanoDallAmbiente('PDF_IMPORT_ASYNC', true),
    // Binario PHP CLI per il worker background (≠ php-fpm). Default Debian/Ubuntu.
    'php_cli' => $_ENV['PDF_IMPORT_PHP_CLI'] ?? '/usr/bin/php',
    // Solo pulizia, niente estrazione, per tools/cron/process_pdf_import_jobs.php
    // (PDF_IMPORT_PURGE_ONLY, o l'opzione --purge-only). Fino al 23/9/2026 lo
    // script lo leggeva da sé, e valeva solo '1' (A-35).
    'purge_only' => \App\Core\Config::booleanoDallAmbiente('PDF_IMPORT_PURGE_ONLY', false, true),

    // Timeout HTTP (secondi) per chiamata vision a un provider. 60s: con un
    // modello vision VELOCE (gemini-flash, gpt-4o-mini) bastano ~10-20s; se il
    // modello configurato è lento fallisce prima e ritenta.
    'provider_timeout' => (int)($_ENV['PDF_IMPORT_PROVIDER_TIMEOUT'] ?? 60),

    // Scan numeri a 2 fasi (legacy NumberScanner): una passata vision dedicata
    // legge SOLO i numeri dei badge → corregge i numeri esercizio (meno errori).
    // Aggiunge 1 chiamata vision per pagina → DEFAULT OFF: raddoppia le chiamate
    // e con provider lenti/instabili l'estrazione resta a lungo su
    // "0/1". Attivabile con PDF_IMPORT_NUMBER_SCAN=true se il provider è veloce.
    // ON: l'estrazione gira in background (non blocca), quindi la passata extra
    // per i numeri vale la pena → numeri esercizio molto più accurati (con un
    // modello vision capace). Override: PDF_IMPORT_NUMBER_SCAN=false.
    'number_scan' => \App\Core\Config::booleanoDallAmbiente('PDF_IMPORT_NUMBER_SCAN', true),

    // Passata difficoltà automatica a fine estrazione (agente vision dedicato che
    // conta i pallini, tipo legacy). ON di default: in background non blocca e
    // corregge le difficoltà (l'estrazione le sbaglia). Modello = operazione
    // 'difficulty'. Override: PDF_IMPORT_AUTO_DIFFICULTY=false.
    'auto_difficulty' => \App\Core\Config::booleanoDallAmbiente('PDF_IMPORT_AUTO_DIFFICULTY', true),
    // Argomento automatico (riempie i topic vuoti) e traduzione IT (solo righe in
    // lingua straniera) a fine estrazione. Ogni passata ri-salva → tabella
    // incrementale. Override: PDF_IMPORT_AUTO_TOPICS / PDF_IMPORT_AUTO_TRANSLATION.
    'auto_topics'      => \App\Core\Config::booleanoDallAmbiente('PDF_IMPORT_AUTO_TOPICS', true),
    'auto_translation' => \App\Core\Config::booleanoDallAmbiente('PDF_IMPORT_AUTO_TRANSLATION', true),

    // Soluzioni AI: quanti esercizi per richiesta web (il client cicla finché
    // remaining>0). Tenuto basso per restare sotto fastcgi_read_timeout.
    'solutions_per_request' => (int)($_ENV['PDF_IMPORT_SOLUTIONS_PER_REQUEST'] ?? 2),

    // Retention (giorni): dopo questo TTL il worker cancella file + righe delle
    // sessioni (incluse quelle abbandonate). Le sessioni inserite cancellano i
    // file subito dopo l'insert. Tieni basso per minimizzare materiale a riposo.
    'retention_days' => (int)($_ENV['PDF_IMPORT_RETENTION_DAYS'] ?? 7),

    // Il bundle delle CA per i fornitori lo trova App\Support\BundleCa in
    // ProviderRouter. Fino al 23/9/2026 qui c'era `ca_bundle`, con il default
    // a un percorso XAMPP di Windows (A-42).

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
        // Qwen (Alibaba/DashScope) e OpenRouter rimossi il 2026-08-26 —
        // vedi la nota in ProviderRouter::CLIENTS e docs/legal/dpa_template.md
        // § 7.1-bis. Restano due fornitori cloud + Ollama locale.
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
