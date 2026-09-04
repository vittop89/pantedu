<?php

/**
 * WAF (Phase 25.C) — Web Application Firewall self-hosted config.
 *
 * Riferimento implementazione: docs/todo/waf_security_prompt.md
 *
 * NOTA: i toggle operativi (enabled, mode, soglie, geo) vivono in DB
 * (tabella `waf_config`) per essere controllabili dal pannello admin
 * /admin/waf senza redeploy. Questo file contiene SOLO i segreti
 * (HMAC key) e i path runtime (GeoIP DB).
 */

return [
    /**
     * Chiave HMAC per firmare i cookie waf_session + le challenge PoW.
     * DEVE essere >= 32 byte. Genera con: openssl rand -hex 32
     *
     * Risoluzione (audit sicurezza 2026-06-01 — niente più secret vuoto che
     * disattiva silenziosamente il layer session/scoring):
     *   1. env WAF_HMAC_SECRET, se >= 32 byte;
     *   2. fallback su key-file persistente auto-generato (0600) sotto il
     *      data path, così il WAF resta funzionante anche se l'env non è
     *      impostato. In produzione impostare comunque WAF_HMAC_SECRET.
     *
     * ATTENZIONE: rotazione richiede gestione dual-key per evitare logout
     * massivi di sessioni valide (TODO: WafSessionService supporta solo
     * single-key per ora — Phase 25.C.2 future enhancement).
     */
    'hmac_secret' => (static function (): string {
        $env = (string)($_ENV['WAF_HMAC_SECRET'] ?? (getenv('WAF_HMAC_SECRET') ?: ''));
        if (strlen($env) >= 32) {
            return $env;
        }
        $dataBase = (string)($_ENV['PANTEDU_DATA_PATH'] ?? (getenv('PANTEDU_DATA_PATH') ?: ''));
        $base = $dataBase !== '' ? $dataBase : dirname(__DIR__, 2);
        $dir  = $base . '/storage/keys';
        $file = $dir . '/waf_hmac.key';
        $existing = @file_get_contents($file);
        if (is_string($existing) && strlen(trim($existing)) >= 32) {
            return trim($existing);
        }
        try {
            $key = bin2hex(random_bytes(32)); // 64 hex chars
        } catch (\Throwable) {
            return str_repeat('0', 64); // ultimo fallback deterministico (>=32)
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $key, LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $file);
        }
        return $key;
    })(),

    /**
     * Proof-of-Work: difficoltà (bit iniziali a zero da trovare) della
     * challenge computazionale. 16 ≈ qualche centinaio di ms su device
     * moderni; tarato basso per non penalizzare device scolastici lenti / 3G.
     * I toggle operativi (pow_enabled, pow_bits) vivono in waf_config DB.
     */
    'pow_default_bits' => (int)($_ENV['WAF_POW_BITS'] ?? 16),

    /**
     * Range CIDR di proxy fidati AGGIUNTIVI (oltre ai range Cloudflare già
     * embeddati in EdgeContext), CSV. Da popolare se davanti a CF c'è un
     * ulteriore reverse-proxy. Vuoto in setup standard.
     */
    'trusted_proxies' => (string)($_ENV['TRUSTED_PROXIES'] ?? ''),

    /**
     * Path al file MaxMind GeoLite2-Country.mmdb per lookup country code.
     *
     * Download:
     *   1. Registra account free su maxmind.com (rate-limited ma sufficiente)
     *   2. Scarica GeoLite2-Country.tar.gz, estrai `.mmdb`
     *   3. Posiziona in storage/geoip/GeoLite2-Country.mmdb (gitignored)
     *   4. Setup cron settimanale `geoipupdate` per refresh DB
     *
     * Fallback se file assente: lookup tramite header Cf-IPCountry (Cloudflare)
     * o X-GeoIP-Country (Nginx ngx_http_geoip2_module pre-impostato).
     *
     * Se nessuna delle 3 strategie funziona → country = null → tutte le
     * request passano (no geo enforcement).
     */
    'geoip_db' => $_ENV['WAF_GEOIP_DB'] ?? null,

    /**
     * Path al file ASN .mmdb (db-ip o MaxMind GeoLite2-ASN) per
     * enrichment "RDNS & ASN" admin toggle. Free db-ip Lite.
     *
     * Senza questo path il lookup ASN restituisce null silenziosamente.
     * rDNS funziona comunque (usa gethostbyaddr nativa, no db esterno).
     */
    'geoip_asn_db' => $_ENV['WAF_GEOIP_ASN_DB'] ?? null,

    /**
     * CrowdSec Local API (LAPI) self-hosted endpoint.
     * Phase 25.J: bouncer free (no CrowdSec Service API a pagamento).
     *
     * Setup VPS (tools/dev/setup_crowdsec_vps.sh):
     *   1. apt install crowdsec
     *   2. cscli collections install crowdsecurity/nginx
     *   3. cscli collections install crowdsecurity/http-cve
     *   4. cscli collections install crowdsecurity/sshd
     *   5. cscli bouncers add pantedu-php → key
     *   6. .env: CROWDSEC_LAPI_KEY=<key>
     *
     * LAPI default su 127.0.0.1:8080. Fail-open se irraggiungibile.
     */
    'crowdsec_lapi_url' => $_ENV['CROWDSEC_LAPI_URL'] ?? 'http://127.0.0.1:8080',
    'crowdsec_lapi_key' => $_ENV['CROWDSEC_LAPI_KEY'] ?? '',
];
