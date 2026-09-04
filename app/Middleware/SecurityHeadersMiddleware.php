<?php

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Phase 25.B6 — Security headers obbligatori per tutte le response HTML/JSON.
 *
 * Headers settati:
 *
 *   - Content-Security-Policy: prevention XSS + framing. Default 'self'
 *     per script/style/img/connect/font/frame-ancestors 'none'. Inline
 *     style/script ammessi via 'unsafe-inline' SOLO se necessario (legacy).
 *
 *   - Strict-Transport-Security: HSTS 1 anno, includeSubDomains, preload-ready.
 *     Solo se request è HTTPS (X-Forwarded-Proto=https oppure server HTTPS=on).
 *
 *   - X-Content-Type-Options: nosniff (impedisce MIME sniffing → mitiga
 *     SOP bypass).
 *
 *   - X-Frame-Options: DENY (deprecated da CSP frame-ancestors, ma backward-
 *     compat per browser vecchi).
 *
 *   - Referrer-Policy: strict-origin-when-cross-origin (no leak full URL
 *     a domini esterni).
 *
 *   - Permissions-Policy: blocca camera/microphone/geolocation/payment
 *     (nessuna feature usata dall'app, no opt-in necessario).
 *
 *   - Cross-Origin-Opener-Policy: same-origin (mitiga Spectre/Meltdown
 *     side-channel).
 *
 * Mode:
 *   - Config('security.csp_mode') = 'strict' | 'relaxed' | 'report-only'
 *   - 'relaxed' (default backward-compat): script-src 'self' 'unsafe-inline'
 *     (necessario per <script> inline legacy + onclick handlers)
 *   - 'strict': script-src 'self' 'sha256-...' (no inline; Phase 25.A
 *     cleanup obbligatorio prima di switch)
 *   - 'report-only': Content-Security-Policy-Report-Only (no enforcement,
 *     solo telemetria via report-uri)
 */
final class SecurityHeadersMiddleware
{
    public function handle(Request $req, callable $next): Response
    {
        $response = $next($req);
        if (!$response instanceof Response) {
            return $response;
        }

        // G22.S26 — Route che devono essere embedded come <iframe>
        // (same-origin only): preview admin risdoc pending. Per queste:
        //   - X-Frame-Options: SAMEORIGIN
        //   - CSP frame-ancestors 'self'
        // Default per tutte le altre resta DENY + frame-ancestors 'none'.
        $allowSameOriginFrame = $this->isSameOriginFrameRoute($req->path);

        // CSP — nonce per-request + (opt-in) policy strict con stamping a runtime.
        // 'relaxed' (default): inline ammesso, NO 'unsafe-eval'.
        // 'strict': script-src 'self' 'nonce-…' 'strict-dynamic' (no inline/eval).
        // 'report-only': stessa policy strict ma in Content-Security-Policy-Report-Only
        //   (test senza enforcement → raccoglie le violazioni reali via report-uri).
        $mode   = $this->resolveCspMode();
        $strict = ($mode === 'strict' || $mode === 'report-only');
        $nonce  = base64_encode(random_bytes(16));
        $cspHeader = ($mode === 'report-only')
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
        $response->headers[$cspHeader] = $this->buildCsp($allowSameOriginFrame, $strict, $nonce);
        // Stamping: aggiunge nonce a ogni <script> delle risposte HTML quando la
        // policy strict è attiva (relaxed non muta il body → zero overhead).
        if ($strict && stripos((string)($response->headers['Content-Type'] ?? ''), 'text/html') !== false) {
            $response->body = $this->stampScriptNonce($response->body, $nonce);
        }

        // HSTS (solo HTTPS).
        // Phase 25.B7 pentest-2026-05-18 FND-VPS-008 — aggiunta directive `preload`
        // per eligibilità HSTS Preload List (browser-builtin). Precondizioni:
        //   - max-age ≥ 31536000 (1 anno)
        //   - includeSubDomains
        //   - preload directive
        //   - serve HTTPS valido + redirect HTTP→HTTPS (già attivo)
        // Submit manuale a https://hstspreload.org/ dopo deploy per inclusion
        // in Chrome/Firefox/Safari/Edge preload list.
        if ($this->isHttps($req)) {
            $response->headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        // Hardening base
        $response->headers['X-Content-Type-Options'] = 'nosniff';
        $response->headers['X-Frame-Options']        = $allowSameOriginFrame ? 'SAMEORIGIN' : 'DENY';
        $response->headers['Referrer-Policy']        = 'strict-origin-when-cross-origin';
        $response->headers['Permissions-Policy']     = $this->permissionsPolicy();
        // 2026-05-24: COOP solo su secure context (HTTPS o localhost).
        // Browser ignora COOP su origin "untrustworthy" (es. http://pantedu.local/)
        // e logga warning console rumoroso in dev XAMPP. Skip in HTTP non-localhost
        // → no warning, no perdita sicurezza (HTTP è già "non isolato").
        $_https = !empty($_SERVER['HTTPS']) || ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https';
        $_localhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true);
        if ($_https || $_localhost) {
            $response->headers['Cross-Origin-Opener-Policy'] = 'same-origin';
        }

        return $response;
    }

    /**
     * Risolve la CSP mode con override RUNTIME da `waf_config.csp_mode`
     * (toggle admin /admin/waf/config — nessun redeploy). Precedenza:
     *   1. waf_config.csp_mode (se valido)
     *   2. env CSP_MODE (config/security.php)
     *   3. 'relaxed'
     * Fail-safe: WafConfigRepository ha try/catch interno (DB down → default ''),
     * quindi su errore si ricade su env/relaxed senza eccezioni.
     */
    private function resolveCspMode(): string
    {
        $valid = ['relaxed', 'report-only', 'strict'];
        try {
            $db = (new \App\Services\Waf\WafConfigRepository())->get('csp_mode', '');
            if (in_array($db, $valid, true)) {
                return $db;
            }
        } catch (\Throwable) {
            // fallback su env/config
        }
        $m = strtolower(trim((string)Config::get('security.csp_mode', 'relaxed')));
        return in_array($m, $valid, true) ? $m : 'relaxed';
    }

    /**
     * Aggiunge `nonce="…"` a ogni `<script>` che non ne ha già uno (inline e
     * `<script src>`, incluso il bundle Vite). I blocchi
     * `<script type="application/json">` ricevono il nonce in modo innocuo
     * (ignorato sui non-JS). Usato solo quando la policy strict è attiva.
     */
    private function stampScriptNonce(string $html, string $nonce): string
    {
        if ($html === '' || stripos($html, '<script') === false) {
            return $html;
        }
        return (string)preg_replace(
            '/<script\b(?![^>]*\bnonce=)/i',
            '<script nonce="' . $nonce . '"',
            $html,
        );
    }

    /**
     * G22.S26 — Route che richiedono X-Frame-Options: SAMEORIGIN +
     * frame-ancestors 'self' per essere embedded come <iframe> dalla stessa
     * origin. Pattern path-based, prefix match.
     */
    private function isSameOriginFrameRoute(string $path): bool
    {
        // Anteprima pending review (super-admin only, embedded in diff card)
        if (preg_match('#^/admin/risdoc/pending/\d+/preview$#', $path)) {
            return true;
        }
        // Phase 25.R follow-up — Grafana embed via auth_request SSO
        // (super_admin only, iframe in /admin/monitoring)
        if (str_starts_with($path, '/grafana/') || $path === '/grafana') {
            return true;
        }
        // PDF-Import: editor config (modelli/prompt) embeddato come <iframe> nei
        // tab di /admin/templates (preset globale) e /area-docente/templates
        // (override personale). Stessa origin.
        if ($path === '/area-docente/pdf-import/models' || $path === '/teacher/pdf-import/models') {
            return true;
        }
        return false;
    }

    private function buildCsp(bool $allowSameOriginFrame, bool $strict, string $nonce): string
    {
        $self = "'self'";

        // CDN whitelist — coerente con .htaccess esistente (jQuery, MathJax,
        // Chosen, FontAwesome, Quill, jsdelivr per polyfill/MathJax).
        // Phase 25.A target: spostare tutti i CDN a hosting locale + bundle
        // Vite, poi rimuovere le whitelist e passare a 'strict'.
        // G22.S15.bis Fase 4 — `www.geogebra.org` + `cdn.geogebra.org`
        // necessari per il modulo GeoGebra editor (deployggb.js carica
        // applet, web workers, fonts, CSS, asset runtime).
        // Phase 25.B7 — `polyfill.io` rimosso (CDN compromise 2024, ora
        // safe-redirect Cloudflare; non più referenziato dall'app, residuano
        // 15 file HTML legacy in storage/objects/.../eser_sc{1,2}s/ con
        // <script src="https://polyfill.io/..."> da sostituire con
        // cdn.jsdelivr.net/npm/@finsweet/polyfill).
        $ggb       = "https://www.geogebra.org https://cdn.geogebra.org";
        // Sprint B (2026-06-02): jQuery RIMOSSO ovunque — ajax.googleapis.com +
        // code.jquery.com tolti. Il branch risdoc che caricava jQuery era codice
        // morto (15/15 template hanno schema_path → renderWebComponent vanilla).
        $cdnScript = "https://cdn.jsdelivr.net https://cdnjs.cloudflare.com "
                   . "https://cdn.quilljs.com $ggb";
        $cdnStyle  = "https://cdn.jsdelivr.net https://cdnjs.cloudflare.com "
                   . "https://cdn.quilljs.com https://fonts.googleapis.com $ggb";
        $cdnFont   = "https://cdn.jsdelivr.net https://cdnjs.cloudflare.com "
                   . "https://fonts.gstatic.com $ggb";
        $cdnFrame  = "https://viewer.diagrams.net https://app.diagrams.net "
                   . "https://embed.diagrams.net "
                   . "https://www.overleaf.com https://drive.google.com "
                   . "https://www.geogebra.org";
        $cdnConnect = "https://cdn.jsdelivr.net $ggb";

        $directives = [
            "default-src $self",
            "img-src $self data: blob: https:",
            "font-src $self data: $cdnFont",
            "connect-src $self $cdnConnect",
            $allowSameOriginFrame ? "frame-ancestors 'self'" : "frame-ancestors 'none'",
            "frame-src $self $cdnFrame",
            "worker-src $self blob: $ggb",
            "form-action $self",
            "base-uri $self",
            "object-src 'none'",
        ];

        if ($strict) {
            // Phase 25.A — nonce + strict-dynamic: gli script iniziali (incl. il
            // bundle Vite) portano il nonce (stampato a runtime da
            // stampScriptNonce); strict-dynamic propaga la fiducia ai chunk
            // importati dinamicamente. NIENTE 'unsafe-inline' né 'unsafe-eval'.
            // Gli host CDN restano per i browser CSP2 (ignorati da chi supporta
            // strict-dynamic). style-src tiene 'unsafe-inline': gli attributi
            // style= non sono copribili da nonce (tightening separato).
            $directives[] = "script-src $self 'nonce-$nonce' 'strict-dynamic' blob: $cdnScript";
            $directives[] = "style-src $self 'unsafe-inline' $cdnStyle";
        } else {
            // Default 'relaxed': inline ammesso (legacy onclick + <script>).
            // 'unsafe-eval' RIMOSSO (2026-06-03 — nessun eval/new Function nel
            // sorgente; verificato anche su editor/GeoGebra).
            $directives[] = "script-src $self 'unsafe-inline' blob: $cdnScript";
            $directives[] = "style-src $self 'unsafe-inline' $cdnStyle";
        }

        // report-uri (telemetria) se configurato
        $reportUri = (string)Config::get('security.csp_report_uri', '');
        if ($reportUri !== '') {
            $directives[] = "report-uri {$reportUri}";
        }

        return implode('; ', $directives);
    }

    private function permissionsPolicy(): string
    {
        // Nessuna feature usata: blocca tutto. Lista feature ATTUALMENTE
        // riconosciute dai browser (Chrome 120+/FF 121+). Rimosse:
        //   - ambient-light-sensor (deprecata, rimossa da spec)
        //   - battery (deprecata, privacy concern)
        //   - document-domain (deprecata Chrome 88+)
        //   - navigation-override (mai standardizzata, rimossa)
        // Browser-warning su feature non riconosciute → rumore in console.
        // 2026-05-24: rimosso 'web-share' — Chrome 120+ NON la riconosce più
        // come permission feature standalone (era proposta non standardizzata),
        // genera warning "Unrecognized feature: 'web-share'" in console.
        $features = [
            'accelerometer', 'autoplay',
            'camera', 'cross-origin-isolated', 'display-capture',
            'encrypted-media', 'fullscreen', 'geolocation',
            'gyroscope', 'keyboard-map', 'magnetometer', 'microphone', 'midi',
            'payment', 'picture-in-picture',
            'publickey-credentials-get', 'screen-wake-lock', 'sync-xhr',
            'usb', 'xr-spatial-tracking',
        ];
        return implode(', ', array_map(static fn($f) => "{$f}=()", $features));
    }

    private function isHttps(Request $req): bool
    {
        $server = $req->server ?? [];
        $proto = $server['HTTP_X_FORWARDED_PROTO'] ?? null;
        if ($proto === 'https') {
            return true;
        }
        $https = $server['HTTPS'] ?? '';
        if (!empty($https) && strtolower((string)$https) !== 'off') {
            return true;
        }
        $port = (int)($server['SERVER_PORT'] ?? 0);
        return $port === 443;
    }
}
