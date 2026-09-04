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
     */
    'hibp_enabled' => (bool)($_ENV['SECURITY_HIBP_ENABLED'] ?? true),

    /**
     * 2FA TOTP (RFC 6238). Master switch globale.
     * Phase 25.J: infra installata MA DISATTIVATA di default.
     * Da attivare a progetto concluso quando admin sceglie di
     * forzare 2FA per super-admin / teacher / all.
     *
     * Toggle granulare per ruolo via security.totp_required_roles.
     */
    'totp_enabled' => (bool)($_ENV['SECURITY_TOTP_ENABLED'] ?? false),

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
];
