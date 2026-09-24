<?php

/**
 * Security feature toggles — Phase 25.J.
 *
 * Configurazione runtime per feature di sicurezza che NON girano
 * nel WAF (la cui config sta in waf_config DB). Queste sono
 * controlli applicativi a livello auth/password/2FA.
 */

return [
    /**
     * Have I Been Pwned password breach check.
     * Al change-password (e in futuro register), verifica che la
     * password scelta non sia in breach pubblici noti via API
     * pwnedpasswords.com (k-anonymity, no password mai inviata).
     *
     * Fail-open: se API down → no block (non vogliamo bloccare
     * utenti legittimi per outage di servizi esterni).
     *
     * Gli interruttori di questo file si leggono con
     * Config::booleanoDallAmbiente (23/9/2026, A-35): il predefinito è il lato
     * prudente, e vale anche per una riga vuota o un valore sconosciuto.
     * Prima `(bool)` faceva valere vera la stringa 'false', e falsa la riga
     * vuota: `SECURITY_HIBP_ENABLED=` spegneva il controllo.
     */
    'hibp_enabled' => \App\Core\Config::booleanoDallAmbiente('SECURITY_HIBP_ENABLED', true),

    /**
     * 2FA TOTP (RFC 6238). Master switch globale.
     * Phase 25.J: infra installata MA DISATTIVATA di default.
     * Da attivare a progetto concluso quando admin sceglie di
     * forzare 2FA per super-admin / teacher / all.
     *
     * Toggle granulare per ruolo via security.totp_required_roles.
     *
     * Fino al 23/9/2026 era `(bool)`, e `SECURITY_TOTP_ENABLED=false` valeva
     * acceso: con dei ruoli elencati l'obbligo c'era. Adesso 'false' vale
     * falso, ma con dei ruoli elencati l'obbligo resta, e la contraddizione
     * finisce nel registro delle anomalie: una lettura corretta non deve
     * spegnere in silenzio un secondo fattore che oggi si chiede
     * (TwoFactorEnforcement::mode()).
     */
    'totp_enabled' => \App\Core\Config::booleanoDallAmbiente('SECURITY_TOTP_ENABLED', false),

    /**
     * Ruoli che DEVONO usare 2FA (se totp_enabled=true).
     * Default vuoto: nessun ruolo forzato. Esempi:
     *   ['super_admin']       — solo super-admin
     *   ['super_admin','administrator']  — admin + super-admin
     *   ['super_admin','administrator','teacher']  — tutti gli operatori
     *
     * Utenti possono comunque abilitare 2FA volontariamente via
     * /me/2fa (anche se non in roles required).
     */
    'totp_required_roles' => !empty($_ENV['SECURITY_TOTP_REQUIRED_ROLES'])
        ? array_filter(array_map('trim', explode(',', (string)$_ENV['SECURITY_TOTP_REQUIRED_ROLES'])))
        : [],

    /**
     * Content-Security-Policy mode (SecurityHeadersMiddleware). Valori:
     *   'relaxed'      (default) — inline ammesso, NO 'unsafe-eval'. Sicuro
     *                  per la base inline legacy ancora presente.
     *   'report-only'  — emette la policy STRICT (nonce + strict-dynamic) come
     *                  Content-Security-Policy-Report-Only: non blocca nulla,
     *                  raccoglie le violazioni reali (via csp_report_uri) per
     *                  pianificare la bonifica degli inline residui.
     *   'strict'       — enforce nonce + strict-dynamic (no inline/eval script).
     *                  Attivare SOLO dopo che report-only è pulito su tutte le
     *                  pagine (incl. admin/WAF) e dopo conversione degli on*=.
     */
    'csp_mode' => (static function (): string {
        $m = strtolower(trim((string)($_ENV['CSP_MODE'] ?? 'relaxed')));
        return in_array($m, ['relaxed', 'report-only', 'strict'], true) ? $m : 'relaxed';
    })(),

    /** Endpoint report-uri per le violazioni CSP (vuoto = nessun report). */
    'csp_report_uri' => (string)($_ENV['CSP_REPORT_URI'] ?? ''),

    // ── 2026-09-04 (revisione P7) — interruttori che prima ogni classe
    //    leggeva per conto suo da $_ENV o getenv(). Stessa semantica di prima.

    /**
     * Sanitizer HTML/SVG/TikZ. Default ON; si spegne SOLO per debug con
     * XSS_SANITIZE_ENABLED=0|false|off|no (nel .env o nell'ambiente del
     * processo: i sanitizer usavano getenv, che non vede il .env). Un valore
     * sconosciuto lo lascia acceso, come prima.
     */
    'xss_sanitize_enabled' => \App\Core\Config::booleanoDallAmbiente('XSS_SANITIZE_ENABLED', true, true),

    /** Durata del token CSRF in secondi (Core\Csrf). */
    'csrf_token_lifetime' => (int)($_ENV['CSRF_TOKEN_LIFETIME'] ?? 7200),

    /** Bearer atteso su /metrics; vuoto = endpoint chiuso ai bearer. */
    'metrics_bearer_token' => (string)($_ENV['METRICS_BEARER_TOKEN'] ?? ''),

    /**
     * Espone nella risposta il token di cancellazione self-service (solo
     * dev/CI/E2E). In produzione resta false; APP_ENV != production lo
     * implica comunque.
     */
    'expose_deletion_debug_token' => \App\Core\Config::booleanoDallAmbiente('EXPOSE_DELETION_DEBUG_TOKEN', false),

    /**
     * Phase 25.B5 — bypass dei rate limiter (mai in produzione: con
     * APP_ENV=production il container non parte, docker/verifica-avvio.php).
     */
    'rate_limit_disabled' => \App\Core\Config::booleanoDallAmbiente('RATE_LIMIT_DISABLED', false),

    /** Backend dei contatori del rate limit: auto (db se disponibile) | db | session. */
    'rate_limit_backend' => strtolower((string)($_ENV['RATE_LIMIT_BACKEND'] ?? (getenv('RATE_LIMIT_BACKEND') ?: 'auto'))),
];
