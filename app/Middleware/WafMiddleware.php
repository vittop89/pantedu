<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\Waf\EdgeContext;
use App\Services\Waf\GeoIpService;
use App\Services\Waf\WafConfigRepository;
use App\Services\Waf\WafLogService;
use App\Services\Waf\WafProofOfWork;
use App\Services\Waf\WafRulesService;
use App\Services\Waf\WafScoringService;
use App\Services\Waf\WafSessionService;

/**
 * WAF Middleware — orchestratore livello applicativo.
 *
 * Sequenza decisionale (early-exit alla prima azione decisiva):
 *
 *   1. WAF disabilitato globalmente (waf_config.enabled=0) → bypass
 *   2. Request path bypass (static asset, /waf/fingerprint stesso, /admin/waf*) → bypass
 *   3. IP in waf_whitelisted_ips → pass + log "whitelist"
 *   4. IP in waf_blocked_ips → 403 + log "blocked_manual"
 *   5. GeoIP block (geo_mode=enforce + country not in geo_allowed) → 403 + log "blocked_geo"
 *   6. Custom rules engine (waf_rules) → applica action della prima rule che matcha
 *   7. Cookie waf_session HMAC:
 *        - valido + challenge=pass    → bypass + log "pass"
 *        - valido + challenge=soft    → inject interstitial + log "challenge_soft"
 *        - valido + challenge=block   → 403 + log "blocked_score"
 *        - assente/invalido           → inject fingerprint page + log "challenge_first"
 *
 * Mode operativo (waf_config.mode):
 *   - off:          bypass completo (anche con enabled=1 questo override)
 *   - monitor:      tutti i passaggi log-only, mai bloccare/sfidare
 *   - soft:         solo block azione di blocco; soft challenge inviata
 *   - enforce:      tutte le azioni applicate (default produzione)
 *   - under_attack: ogni request senza cookie → interstitial obbligatorio
 *
 * NB: questo middleware NON sostituisce Layer 1 Nginx (geo block veloce) ma
 * lo integra. In assenza di Nginx GeoIP, opera anche come Layer 1 (più lento
 * ma funzionale).
 */
final class WafMiddleware
{
    private const SESSION_COOKIE = 'waf_session';

    /**
     * Phase 25.J.2 — la request attende JSON (fetch/XHR del SPA o path /api/).
     * Le challenge HTML (fingerprint/PoW/interstitial) funzionano SOLO per
     * navigazioni full-page: una fetch non esegue lo <script> di challenge e
     * il client fa .json() su "<!doctype …>" → SyntaxError. Per queste request
     * il WAF mantiene la STESSA decisione di sicurezza ma la serve in JSON
     * (challenge → 403 {waf_challenge, reload:true}; block → 403 {request_blocked}).
     * Settato una volta in handle() così il branching formato vive in un solo punto.
     */
    private bool $expectsJson = false;

    public function __construct(
        private readonly ?WafConfigRepository $configRepo = null,
        private readonly ?WafScoringService $scoring = null,
        private readonly ?WafSessionService $session = null,
        private readonly ?GeoIpService $geoip = null,
        private readonly ?WafRulesService $rules = null,
        private readonly ?WafLogService $log = null,
    ) {
    }

    public function handle(Request $req, callable $next): Response
    {
        // Centralizzato: decide UNA volta se la risposta WAF va in JSON.
        // Copre sia l'Accept esplicito (XHR/fetch) sia tutti i path /api/*,
        // che sono endpoint JSON anche quando il client dimentica l'header.
        $this->expectsJson = $req->wantsJson()
            || str_starts_with($req->path, '/api/');
        try {
            return $this->evaluate($req, $next);
        } catch (\Throwable $e) {
            // Fail-CLOSED-verso-challenge: su errore interno il WAF NON deve
            // lasciar passare la request (il Kernel altrimenti fa fail-OPEN).
            // I browser reali superano la challenge invisibile, i bot no.
            error_log('[WAF] errore middleware, fail-closed a challenge: ' . $e->getMessage());
            try {
                $cfg = $this->configRepo ?? new WafConfigRepository();
                if (
                    !$cfg->getBool('enabled', false) || $cfg->get('mode', 'monitor') === 'off'
                    || $cfg->get('mode', 'monitor') === 'monitor' || $this->shouldBypass($req->path)
                ) {
                    return $next($req);
                }
                return $this->respondChallenge('invisible', $cfg);
            } catch (\Throwable) {
                return $next($req);
            }
        }
    }

    private function evaluate(Request $req, callable $next): Response
    {
        $config = $this->configRepo ?? new WafConfigRepository();

        // Bypass: WAF disabilitato globalmente
        if (!$config->getBool('enabled', false)) {
            return $next($req);
        }
        $mode = $config->get('mode', 'monitor');
        if ($mode === 'off') {
            return $next($req);
        }

        // Bypass: path interni o asset
        if ($this->shouldBypass($req->path)) {
            return $next($req);
        }

        $edge    = EdgeContext::resolve($req->server ?? []);
        $ip      = $edge->ip;
        $headers = $this->extractHeaders($req);
        $uaHash  = WafSessionService::uaHash((string)($req->server['HTTP_USER_AGENT'] ?? ''));
        $rules   = $this->rules ?? new WafRulesService();
        $logSvc  = $this->log ?? new WafLogService();
        $rid     = (string)($req->server['HTTP_X_REQUEST_ID'] ?? '');

        $baseLog = [
            'ip'          => $ip,
            'user_agent'  => substr((string)($req->server['HTTP_USER_AGENT'] ?? ''), 0, 512),
            'request_uri' => $req->path,
            'method'      => $req->method,
            'referer'     => (string)($req->server['HTTP_REFERER'] ?? ''),
            'request_id'  => $rid !== '' ? $rid : null,
        ];

        // 2.5. Honeypot trap (Phase 25.J) — paths fake admin/wp/git/env.
        // Eseguito PRIMA della whitelist così cattura anche IP whitelistati
        // che accidentalmente accedono a path noti di scanner (es. bot
        // fingerprinter test). Skip per super-admin loggati.
        if ($config->getBool('honeypot_enabled', true) && $this->isHoneypotPath($req->path)) {
            $isSuperAdmin = false;
            try {
                $isSuperAdmin = \App\Core\Auth::check() && \App\Core\Auth::isSuperAdmin();
            } catch (\Throwable) {
            }
            if (!$isSuperAdmin) {
                $logSvc->log($baseLog + [
                    'outcome' => 'honeypot_trap',
                    'score'   => 100,
                ]);
                $action = $config->get('honeypot_action', 'block');
                if ($action === 'block') {
                    // Auto-blacklist 30 giorni via waf_threat_ips
                    try {
                        $pdo = \App\Core\Database::connection();
                        $stmt = $pdo->prepare(
                            'INSERT INTO waf_threat_ips
                                (ip, source, action, reason, expires_at)
                             VALUES (?, "honeypot", "block", ?, DATE_ADD(NOW(), INTERVAL 30 DAY))
                             ON DUPLICATE KEY UPDATE
                                imported_at = CURRENT_TIMESTAMP,
                                expires_at  = VALUES(expires_at)'
                        );
                        $stmt->execute([$ip, 'Honeypot hit: ' . $req->path]);
                    } catch (\Throwable) {
                    }
                    return $this->enforceBlock($mode, 'Honeypot trap');
                }
                if ($action === 'challenge') {
                    return $this->respondChallenge('interstitial', $config);
                }
                // log_only → fall through (rare; usato per tuning)
            }
        }

        // 3. Whitelist manuale
        if (GeoIpService::ipInCidrList($ip, $rules->whitelistCidrs())) {
            $logSvc->log($baseLog + ['outcome' => 'whitelist']);
            return $next($req);
        }

        // 4. Blacklist manuale
        if (GeoIpService::ipInCidrList($ip, $rules->blacklistCidrs())) {
            $logSvc->log($baseLog + ['outcome' => 'blocked_manual']);
            return $this->enforceBlock($mode, 'Manual IP block');
        }

        // 4.5. Threat Intel Layer (Phase 25.I) — IP exact-match O(1) + CIDR scan
        if ($config->getBool('threat_intel_enabled', true)) {
            $threat = new \App\Services\Waf\WafThreatIntelService();
            $hit = $threat->checkIp($ip) ?? $threat->checkCidr($ip);
            if ($hit !== null) {
                $baseLog['outcome_source'] = $hit['source'];
                $action = $hit['action'];
                if ($action === 'block') {
                    $logSvc->log($baseLog + ['outcome' => 'blocked_threat_intel']);
                    return $this->enforceBlock($mode, 'Threat intel: ' . $hit['source']);
                }
                if ($action === 'challenge') {
                    $logSvc->log($baseLog + ['outcome' => 'challenge_threat_intel']);
                    if ($mode === 'enforce' || $mode === 'under_attack') {
                        return $this->respondChallenge('interstitial', $config);
                    }
                }
            }
        }

        // 4.7. CrowdSec Bouncer (Phase 25.J) — LAPI self-hosted live query.
        // Free alternative al Service API a pagamento. Agent installato
        // su VPS scrape nginx log + altri, popola decisioni in LAPI sqlite.
        // Fail-open se LAPI down (no block utenti per outage interno).
        $crowdsec = \App\Services\Waf\WafCrowdSecBouncerService::default();
        if ($crowdsec->isConfigured()) {
            $csHit = $crowdsec->checkIp($ip);
            if ($csHit !== null) {
                $baseLog['outcome_source'] = 'crowdsec:' . $csHit['scenario'];
                if ($csHit['action'] === 'block') {
                    $logSvc->log($baseLog + ['outcome' => 'blocked_crowdsec']);
                    return $this->enforceBlock($mode, 'CrowdSec: ' . $csHit['scenario']);
                }
                if ($csHit['action'] === 'challenge') {
                    $logSvc->log($baseLog + ['outcome' => 'challenge_crowdsec']);
                    if ($mode === 'enforce' || $mode === 'under_attack') {
                        return $this->respondChallenge('interstitial', $config);
                    }
                }
            }
        }

        // 5. GeoIP (country) + ASN (Phase 25.H: rules engine context)
        $geoip = $this->geoip ?? new GeoIpService(
            Config::get('waf.geoip_db', null),
            Config::get('waf.geoip_asn_db', null),
        );
        // Country: header CF fidato solo se EdgeContext lo ritiene tale
        // (origin lockato / proxy CF), altrimenti lookup mmdb sull'IP reale.
        // Così "solo IP italiani" non è aggirabile inviando Cf-IPCountry: IT.
        $country = $edge->country ?? $geoip->lookup($ip, $edge->trustedEdge ? $headers : []);
        $baseLog['country'] = $country;
        $asnInfo = $geoip->lookupAsn($ip);
        $asnStr  = $asnInfo ? 'AS' . $asnInfo['asn'] : '';
        $baseLog['asn'] = $asnStr ?: null;

        $geoMode = $config->get('geo_mode', 'monitor');
        $allowed = array_map(
            fn($c) => strtoupper(trim($c)),
            explode(',', $config->get('geo_allowed', 'IT'))
        );
        if ($country !== null && !in_array($country, $allowed, true)) {
            if ($geoMode === 'enforce' && $mode !== 'monitor') {
                $logSvc->log($baseLog + ['outcome' => 'blocked_geo']);
                return $this->enforceBlock($mode, 'Geographic restriction');
            }
            // monitor mode: solo log
            if ($geoMode === 'monitor' || $mode === 'monitor') {
                $logSvc->log($baseLog + ['outcome' => 'monitor_geo']);
            }
        }

        // 6. Custom rules
        $ctx = [
            'ip'         => $ip,
            'country'    => $country ?? '',
            'asn'        => $asnStr,
            'user_agent' => $baseLog['user_agent'],
            'url'        => $req->path,
            'referer'    => $baseLog['referer'],
            'cookie'     => (string)($req->server['HTTP_COOKIE'] ?? ''),
            'method'     => $req->method,
        ];
        $ruleHit = $rules->evaluate($ctx);
        if ($ruleHit !== null) {
            $baseLog['rule_id'] = $ruleHit['rule_id'];
            $action = $ruleHit['action'];
            if ($action === 'allow') {
                $logSvc->log($baseLog + ['outcome' => 'allow_rule']);
                return $next($req);
            }
            if ($action === 'block') {
                $logSvc->log($baseLog + ['outcome' => 'blocked_rule']);
                return $this->enforceBlock($mode, 'Blocked by rule: ' . $ruleHit['rule_name']);
            }
            if ($action === 'challenge') {
                $logSvc->log($baseLog + ['outcome' => 'challenge_rule']);
                return $this->respondChallenge('interstitial', $config);
            }
            if ($action === 'log_only') {
                $logSvc->log($baseLog + ['outcome' => 'log_rule']);
                // continue to session check
            }
        }

        // 7. Session HMAC check
        $session = $this->session ?? new WafSessionService(
            (string)Config::get('waf.hmac_secret', ''),
            $config->getInt('session_ttl', 3600)
        );
        $cookie = $this->extractCookie($req, self::SESSION_COOKIE);
        $verified = $cookie !== '' ? $session->verifyToken($cookie, $ip, $uaHash) : null;

        // Under-attack mode: ogni request senza cookie valido = interstitial
        if ($mode === 'under_attack' && $verified === null) {
            $logSvc->log($baseLog + ['outcome' => 'under_attack_interstitial']);
            return $this->respondChallenge('interstitial', $config);
        }

        if ($verified === null) {
            // Prima visita o cookie scaduto: inject fingerprint invisible
            $logSvc->log($baseLog + ['outcome' => 'challenge_first']);
            return $this->respondChallenge(
                $config->get('challenge_template', 'invisible'),
                $config
            );
        }

        // Cookie valido — applica challenge memorizzato
        $challenge = $verified['challenge'];
        $score     = $verified['score'];
        $baseLog['score']         = $score;
        $baseLog['challenge']     = $challenge;
        $baseLog['session_token'] = substr($cookie, 0, 32);

        if ($challenge === 'block') {
            $logSvc->log($baseLog + ['outcome' => 'blocked_score']);
            return $this->enforceBlock($mode, 'Risk score too high');
        }

        if ($challenge === 'soft') {
            if ($mode === 'monitor') {
                $logSvc->log($baseLog + ['outcome' => 'monitor_soft']);
                return $next($req);
            }
            $logSvc->log($baseLog + ['outcome' => 'challenge_soft']);
            return $this->respondChallenge('interstitial', $config);
        }

        // pass
        $logSvc->log($baseLog + ['outcome' => 'pass']);
        return $next($req);
    }

    // === Helpers ===

    /**
     * Phase 25.J — Honeypot path detection.
     * Pattern noti che NON sono path legittimi del nostro stack
     * (PHP custom router, no WordPress / Joomla / phpMyAdmin / .git).
     * Hit su questi = scanner automatizzato → blacklist auto.
     */
    private function isHoneypotPath(string $path): bool
    {
        // /admin* è REAL (admin panel). NON includere qui.
        // /admin.php (con .php) è fake (no router .php files) → trap.
        static $patterns = [
            '#^/wp-(login|admin|content|includes|config|json)#i',
            '#^/wp-[a-z0-9_-]+\.php#i',
            '#^/xmlrpc\.php#i',
            '#^/\.env(\.|$)#',
            '#^/\.git/#',
            '#^/\.svn/#',
            '#^/\.htaccess$#',
            '#^/\.htpasswd$#',
            '#^/phpmyadmin#i',
            '#^/pma(/|$)#i',
            '#^/myadmin#i',
            '#^/dbadmin#i',
            '#^/mysql(/|$)#i',
            '#^/administrator(/|$)#i',
            '#^/admin\.php#',
            '#^/admin/(login|index)\.php#',
            '#^/cgi-bin/#i',
            '#^/owa(/|$)#i',
            '#^/server-(status|info)$#i',
            '#^/\.well-known/openid-configuration$#i',
            '#^/manager/(html|status|text)#i',  // Tomcat
            '#^/cms/#i',
            '#^/joomla/#i',
            '#^/drupal/#i',
            '#^/sites/default/files/.*\.php#i',
            '#^/_profiler/#',  // Symfony profiler
            '#^/actuator/#i',  // Spring Boot
            '#^/console$#i',
            '#^/setup\.php$#i',
            '#^/install\.php$#i',
            '#^/wp$#',
            '#^/wordpress/#i',
        ];
        foreach ($patterns as $pat) {
            if (preg_match($pat, $path)) {
                return true;
            }
        }
        return false;
    }

    private function shouldBypass(string $path): bool
    {
        // Static asset (Apache native serve già di solito, ma safety net)
        if (preg_match('#\.(css|js|mjs|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|otf|map|json|wasm)$#i', $path)) {
            return true;
        }
        // WAF API stesso (no recursion)
        if (str_starts_with($path, '/waf/')) {
            return true;
        }
        // Admin WAF UI (admin deve poter sempre raggiungere il pannello)
        if (str_starts_with($path, '/admin/waf')) {
            return true;
        }
        // Phase 25.J — API admin (auth-protected by separate middleware,
        // CSRF su POST). Bypass evita HTML challenge response su fetch JSON
        // dei pannelli admin (es. /admin/waf/anomalies usa /api/admin/security/*).
        if (str_starts_with($path, '/api/admin/')) {
            return true;
        }
        // Health checks / metrics + heartbeat client (bootstrap.js fa GET
        // /auth/user-info ad ogni page load → su pagine auto-refresh come
        // /admin/waf/dashboard generava log loop, riempiendo waf_logs di
        // sé stesso. Phase 25.H.1: bypass per evitare self-pollution).
        if (
            $path === '/healthz' || $path === '/metrics'
            || $path === '/auth/csrf' || $path === '/auth/user-info'
        ) {
            return true;
        }
        // .well-known
        if (str_starts_with($path, '/.well-known/')) {
            return true;
        }
        return false;
    }

    /**
     * @return array<string,string>
     */
    private function extractHeaders(Request $req): array
    {
        $headers = [];
        foreach ($req->server ?? [] as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $headers[$name] = (string)$v;
            }
        }
        return $headers;
    }

    private function extractCookie(Request $req, string $name): string
    {
        $raw = (string)($req->server['HTTP_COOKIE'] ?? '');
        if ($raw === '') {
            return '';
        }
        foreach (explode(';', $raw) as $part) {
            $part = trim($part);
            if (str_starts_with($part, "$name=")) {
                return urldecode(substr($part, strlen($name) + 1));
            }
        }
        return '';
    }

    private function enforceBlock(string $mode, string $reason): Response
    {
        if ($mode === 'monitor') {
            // monitor mode: no block, fallback a invisible challenge
            return new Response('', 200);
        }
        // XHR/JSON: stesso block (403) ma in JSON, così il client non fa
        // .json() su una pagina HTML. Decisione di sicurezza invariata.
        if ($this->expectsJson) {
            return Response::json(
                ['ok' => false, 'error' => 'request_blocked', 'reason' => $reason],
                403
            );
        }
        $body = '<!doctype html><html lang="it"><head><meta charset="utf-8">'
              . '<title>403 Accesso negato</title></head><body>'
              . '<h1>403 Accesso negato</h1>'
              . '<p>Richiesta bloccata dal sistema di protezione.</p>'
              . '<p>Motivo: ' . htmlspecialchars($reason, ENT_QUOTES) . '</p>'
              . '<p>Se ritieni si tratti di un errore contatta '
              . '<a href="/.well-known/security.txt">l\'amministratore</a>.</p>'
              . '</body></html>';
        return new Response($body, 403, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Inject pagina challenge che eseguirà il fingerprinter JS.
     * mode: "invisible" | "interstitial" | "under_attack"
     */
    private function respondChallenge(string $mode, WafConfigRepository $config): Response
    {
        // XHR/JSON: la challenge HTML è inutilizzabile (la fetch non esegue lo
        // script). Stessa decisione (richiedi verifica) ma in JSON: il client
        // ricarica la pagina → la navigazione full-page risolve la challenge
        // invisibile e rinfresca il cookie waf_session, poi l'azione riesce.
        if ($this->expectsJson) {
            // G27 — re-solve TRASPARENTE: includiamo il token PoW (firmato HMAC,
            // come nel path HTML) così il client può ri-risolvere la challenge in
            // background (ri-eseguendo /js/waf/fingerprint.js con data-waf-reload=0)
            // e ritentare la richiesta SENZA reload (no perdita lavoro nel modal).
            // Il token è già esposto nella pagina-challenge HTML → nessuna riduzione
            // di sicurezza: il costo PoW e la validazione fingerprint restano. Il
            // flag `reload:true` resta come FALLBACK se il re-solve fallisce.
            $resp = ['ok' => false, 'error' => 'security_check_required',
                     'code' => 'waf_challenge', 'reload' => true, 'mode' => $mode];
            if ($config->getBool('pow_enabled', true)) {
                $secret = (string)Config::get('waf.hmac_secret', '');
                if (strlen($secret) >= 32) {
                    $bits = $config->getInt('pow_bits', (int)Config::get('waf.pow_default_bits', 16));
                    if ($mode === 'under_attack') {
                        $bits += 4;
                    }
                    $issued = (new WafProofOfWork($secret))->issue($bits);
                    $resp['pow']     = $issued['token'];
                    $resp['powBits'] = (int)$issued['bits'];
                }
            }
            return Response::json($resp, 403);
        }
        $scriptUrl = '/js/waf/fingerprint.js';
        $modeAttr  = htmlspecialchars($mode, ENT_QUOTES);

        // Proof-of-Work: emette una challenge computazionale firmata. Difficoltà
        // più alta in under_attack. Embeddata come data-attribute; il client la
        // risolve e rispedisce challenge+nonce a /waf/fingerprint.
        $powAttr = '';
        if ($config->getBool('pow_enabled', true)) {
            $secret = (string)Config::get('waf.hmac_secret', '');
            if (strlen($secret) >= 32) {
                $bits = $config->getInt('pow_bits', (int)Config::get('waf.pow_default_bits', 16));
                if ($mode === 'under_attack') {
                    $bits += 4;
                }
                $issued = (new WafProofOfWork($secret))->issue($bits);
                $powAttr = ' data-waf-pow="' . htmlspecialchars($issued['token'], ENT_QUOTES)
                    . '" data-waf-pow-bits="' . (int)$issued['bits'] . '"';
            }
        }

        if ($mode === 'invisible') {
            // Risponde body minimo con script che invia fingerprint + reload silenzioso.
            $body = <<<HTML
<!doctype html>
<html lang="it"><head><meta charset="utf-8"><title>Verifica…</title></head>
<body><script src="$scriptUrl" data-waf-mode="invisible" data-waf-reload="1"$powAttr></script>
<noscript><h1>Verifica in corso</h1><p>Abilita JavaScript per continuare.</p></noscript>
</body></html>
HTML;
            return new Response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        // interstitial / under_attack: pagina con spinner
        $msg = $mode === 'under_attack'
            ? 'Stiamo verificando la tua connessione'
            : 'Verifica di sicurezza in corso';
        $body = <<<HTML
<!doctype html>
<html lang="it"><head>
<meta charset="utf-8"><title>$msg</title>
<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f5f7fb;color:#1f2937;
     display:grid;place-items:center;min-height:100vh;margin:0}
.card{background:#fff;padding:2rem;border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.12);max-width:440px;text-align:center}
.spinner{width:40px;height:40px;border:4px solid #d9dee6;border-top-color:#0b5fd1;
         border-radius:50%;margin:1rem auto;animation:s 1s linear infinite}
@keyframes s{to{transform:rotate(360deg)}}
.muted{color:#6b7280;font-size:.9rem}
</style></head>
<body><div class="card">
<h1>$msg</h1><div class="spinner"></div>
<p class="muted">Verifica automatica anti-bot. Attendi qualche secondo…</p>
<p class="muted" style="font-size:.8rem">Powered by Pantedu WAF</p>
</div>
<script src="$scriptUrl" data-waf-mode="$modeAttr" data-waf-reload="1"$powAttr></script>
</body></html>
HTML;
        return new Response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
