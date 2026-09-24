<?php

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Support\Csp;
use App\Support\PaginaConGettone;

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
 *     a domini esterni). Tranne dove la risposta ha già chiesto
 *     `no-referrer`, più stretta: le pagine con un gettone nell'indirizzo
 *     (App\Support\PaginaConGettone, 24/9/2026). Ogni altro valore si
 *     sostituisce: una risposta può stringere la politica, non allargarla.
 *
 *   - Permissions-Policy: blocca camera/microphone/geolocation/payment
 *     (nessuna feature usata dall'app, no opt-in necessario).
 *
 *   - Cross-Origin-Opener-Policy: same-origin (mitiga Spectre/Meltdown
 *     side-channel).
 *
 * Mode (la sceglie resolveCspMode: waf_config.csp_mode, poi CSP_MODE, poi 'relaxed'):
 *   - 'relaxed': script-src 'self' 'unsafe-inline' (niente 'unsafe-eval');
 *   - 'strict': script-src 'self' 'nonce-…' 'strict-dynamic': il browser
 *     esegue solo gli script scritti con il nonce e quelli che loro creano;
 *   - 'report-only': la policy di 'strict' in Content-Security-Policy-Report-Only
 *     (niente blocchi, solo i rapporti via report-uri).
 *
 * Il nonce (23/9/2026, revisione architetturale A-16, R-3 passo 4). È quello di
 * App\Support\Csp, generato PRIMA di rendere la risposta; le viste e le
 * classi lo scrivono negli script dell'applicazione. Questo middleware lo mette
 * solo nell'intestazione e non tocca più il corpo: fino a oggi lo generava dopo
 * `$next()` e con una regex lo timbrava su ogni `<script>` della risposta,
 * compresi quelli arrivati dal contenuto, che con 'strict-dynamic' diventavano
 * eseguibili. Prove: tests/Unit/Middleware/NonceSoloAgliScriptDellAppTest.php
 * e NonceDavantiAlTipoTest.php.
 */
final class SecurityHeadersMiddleware
{
    /** La modalità CSP imposta dal chiamante (le prove); null = resolveCspMode(). */
    private ?string $modoCsp;

    /**
     * @param string|null $modoCsp 'relaxed', 'report-only' o 'strict' per le
     *     prove, che non leggono waf_config; in produzione null, come prima.
     */
    public function __construct(?string $modoCsp = null)
    {
        $this->modoCsp = $modoCsp;
    }

    public function handle(Request $req, callable $next): Response
    {
        // Il nonce prima della risposta: mentre $next() rende la pagina, le
        // viste lo leggono da Csp e lo scrivono negli script dell'applicazione.
        // Dal Kernel arriva già generato (Kernel::handle chiama
        // Csp::nuovaRichiesta() prima della pipeline): qui si rilegge lo stesso.
        $nonce = Csp::nonce();
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

        // CSP — nonce della richiesta (Csp) + policy.
        // 'relaxed' (default): inline ammesso, NO 'unsafe-eval'.
        // 'strict': script-src 'self' 'nonce-…' 'strict-dynamic' (no inline/eval).
        // 'report-only': stessa policy strict ma in Content-Security-Policy-Report-Only
        //   (test senza enforcement → raccoglie le violazioni reali via report-uri).
        // Il corpo non si tocca: il nonce lo portano solo gli script che
        // l'applicazione ha scritto con Csp::attributo() (A-16).
        $mode   = $this->modoCsp ?? $this->resolveCspMode();
        $strict = ($mode === 'strict' || $mode === 'report-only');
        $cspHeader = ($mode === 'report-only')
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
        $response->headers[$cspHeader] = $this->buildCsp($allowSameOriginFrame, $strict, $nonce);

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
        $this->politicaDelReferer($response);
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
     * `Referrer-Policy`: quella di sempre, o `no-referrer` se la risposta
     * l'ha già chiesta (PaginaConGettone). Fino al 24/9/2026 si sovrascriveva
     * sempre, e una pagina non poteva chiedere di non mandare il suo
     * indirizzo. Solo `no-referrer` resta: un valore più largo
     * (`unsafe-url`, …) si sostituisce con quello di sempre. Il nome si
     * confronta senza maiuscole, e resta una sola intestazione.
     */
    private function politicaDelReferer(Response $response): void
    {
        $politica = 'strict-origin-when-cross-origin';
        foreach ($response->headers as $nome => $valore) {
            if (strcasecmp((string)$nome, 'Referrer-Policy') !== 0) {
                continue;
            }
            if (strtolower(trim((string)$valore)) === PaginaConGettone::POLITICA) {
                $politica = PaginaConGettone::POLITICA;
            }
            unset($response->headers[$nome]);
        }
        $response->headers['Referrer-Policy'] = $politica;
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
            $db = (new \App\Repositories\Waf\WafConfigRepository())->get('csp_mode', '');
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
        // Editor dei modelli di verifica: il bottone «Editor» della topbar lo apre
        // come <iframe> (`?embed=1`) nella pagina esercizi. Stessa origin; senza
        // questa eccezione il frame restava vuoto (frame-ancestors 'none',
        // 2026-09-05).
        if ($path === '/area-docente/templates') {
            return true;
        }
        return false;
    }

    private function buildCsp(bool $allowSameOriginFrame, bool $strict, string $nonce): string
    {
        $self = "'self'";

        // Host esterni autorizzati. 2026-09-04 — via jsdelivr, cdnjs, quilljs
        // e Google Fonts: Lit, PDF.js e pako stanno nel bundle Vite, MathJax e
        // il suo font in public/vendor (copiati da npm al build), Quill in
        // public/vendor/quill da maggio. Restano solo gli host di GeoGebra,
        // che serve l'applet, i worker, i font e gli asset runtime da casa
        // sua (G22.S15.bis Fase 4), e i frame di drawio e Drive. Overleaf
        // è uscito il 21/9/2026: non veniva mai messo in cornice (i vecchi
        // percorsi aprivano una scheda), quindi quel permesso era inutile
        // già prima.
        // I 15 file HTML legacy in storage/objects che citano jQuery e MathJax
        // 3 da CDN non sono pagine dell'app: sono contenuti pre-Phase 18, gia'
        // convertiti in contract (revisione architetturale 2026-09, rilievo A4).
        $ggb       = "https://www.geogebra.org https://cdn.geogebra.org";
        $cdnScript = $ggb;
        $cdnStyle  = $ggb;
        $cdnFont   = $ggb;
        $cdnFrame  = "https://viewer.diagrams.net https://app.diagrams.net "
                   . "https://embed.diagrams.net "
                   . "https://drive.google.com "
                   . "https://www.geogebra.org";
        $cdnConnect = $ggb;

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
            // bundle Vite) portano il nonce, scritto da chi li emette con
            // Csp::attributo() (fino al 23/9/2026 lo timbrava qui una regex su
            // ogni <script> del corpo, A-16); strict-dynamic propaga la fiducia
            // agli script che loro creano e ai chunk importati. NIENTE
            // 'unsafe-inline' né 'unsafe-eval'.
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
