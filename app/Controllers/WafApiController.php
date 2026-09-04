<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\Waf\EdgeContext;
use App\Services\Waf\GeoIpService;
use App\Services\Waf\WafConfigRepository;
use App\Services\Waf\WafLogService;
use App\Services\Waf\WafProofOfWork;
use App\Services\Waf\WafScoringService;
use App\Services\Waf\WafSessionService;

/**
 * POST /waf/fingerprint — endpoint pubblico raccolta fingerprint browser.
 *
 * Body: JSON con i ~30 parametri raccolti dal JS fingerprinter (vedi
 * public/js/waf/fingerprint.js).
 *
 * Response:
 *   200 { ok: true, challenge: "pass"|"soft"|"block", score: int }
 *   + Set-Cookie: waf_session=<token HMAC>; HttpOnly; Secure; SameSite=Strict
 *
 *   400 { error: "invalid_payload" } su JSON malformato
 */
final class WafApiController
{
    public function collect(Request $req): Response
    {
        $raw = (string)file_get_contents('php://input');
        if ($raw === '' || strlen($raw) > 16384) {
            return Response::json(['error' => 'invalid_payload'], 400);
        }
        try {
            $fp = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return Response::json(['error' => 'invalid_payload'], 400);
        }
        if (!is_array($fp)) {
            return Response::json(['error' => 'invalid_payload'], 400);
        }

        $server  = $req->server ?? [];
        $edge    = EdgeContext::resolve($server);
        $ip      = $edge->ip;
        $serverUa = (string)($server['HTTP_USER_AGENT'] ?? '');
        $uaHash   = WafSessionService::uaHash($serverUa);

        $config = new WafConfigRepository();
        $secret = (string)Config::get('waf.hmac_secret', '');
        if (strlen($secret) < 32) {
            // Misconfig grave: secret non risolvibile (mai dovrebbe accadere,
            // vedi fallback key-file in config/waf.php). Log + 503 esplicito.
            error_log('[WAF] hmac_secret non disponibile (<32B) in WafApiController');
            return Response::json(['ok' => false, 'error' => 'waf_not_configured'], 503);
        }

        // Proof-of-Work: verifica la soluzione computazionale prima di
        // concedere il pass. Roll-out graduale:
        //   - PoW presente e INVALIDO  → rifiuto (no cookie).
        //   - PoW presente e valido    → prosegui.
        //   - PoW assente              → lenient di default (client con JS in
        //     cache), oppure rifiuto se pow_required=1.
        $powEnabled  = $config->getBool('pow_enabled', true);
        $powRequired = $config->getBool('pow_required', false);
        $powToken = (string)($fp['powChallenge'] ?? '');
        $powNonce = (string)($fp['powNonce'] ?? '');
        $powState = 'disabled';
        if ($powEnabled) {
            if ($powToken !== '') {
                $pow = new WafProofOfWork($secret);
                $powState = $pow->verify($powToken, $powNonce) ? 'ok' : 'failed';
                if ($powState === 'failed') {
                    (new WafLogService())->log([
                        'ip'          => $ip,
                        'user_agent'  => substr($serverUa, 0, 512),
                        'request_uri' => '/waf/fingerprint',
                        'method'      => 'POST',
                        'score'       => 100,
                        'outcome'     => 'pow_failed',
                        'request_id'  => (string)($server['HTTP_X_REQUEST_ID'] ?? '') ?: null,
                    ]);
                    return Response::json(['ok' => false, 'error' => 'pow_failed', 'retry' => true], 403);
                }
            } elseif ($powRequired) {
                return Response::json(['ok' => false, 'error' => 'pow_required', 'retry' => true], 403);
            } else {
                $powState = 'missing';
            }
        }

        $scoring = new WafScoringService();
        $score   = $scoring->calculateScore($fp) + $scoring->serverSignals($fp, $server);
        if ($score > 100) {
            $score = 100;
        }

        $challenge = $scoring->getChallenge(
            $score,
            $config->getInt('threshold_pass', 40),
            $config->getInt('threshold_block', 70)
        );

        $session = new WafSessionService($secret, $config->getInt('session_ttl', 3600));
        $token = $session->createToken($score, $ip, $challenge, $uaHash);

        $isHttps = $this->isHttps($req);
        $cookie  = $session->buildSetCookieHeader($token, $isHttps);

        // Log entry per dashboard
        $headers = [];
        foreach ($req->server ?? [] as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
            }
        }
        // Country: preferisci l'header CF già validato da EdgeContext (fidato
        // solo se l'edge è fidato), altrimenti lookup mmdb sull'IP reale.
        $geo = $edge->country ?? (new GeoIpService(Config::get('waf.geoip_db', null)))->lookup($ip, $edge->trustedEdge ? $headers : []);
        (new WafLogService())->log([
            'ip'            => $ip,
            'country'       => $geo,
            'user_agent'    => substr($serverUa, 0, 512),
            'request_uri'   => '/waf/fingerprint',
            'method'        => 'POST',
            'score'         => $score,
            'challenge'     => $challenge,
            'outcome'       => 'fingerprint_collected',
            'outcome_source' => 'pow:' . $powState,
            'fp_hash'       => $scoring->fingerprintHash($fp),
            'request_id'    => (string)($server['HTTP_X_REQUEST_ID'] ?? '') ?: null,
        ]);

        $resp = Response::json(
            ['ok' => true, 'challenge' => $challenge, 'score' => $score],
            200
        );
        $resp->headers['Set-Cookie'] = $cookie;
        return $resp;
    }

    private function isHttps(Request $req): bool
    {
        $s = $req->server ?? [];
        if (($s['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        $https = $s['HTTPS'] ?? '';
        if (!empty($https) && strtolower((string)$https) !== 'off') {
            return true;
        }
        return ((int)($s['SERVER_PORT'] ?? 0)) === 443;
    }
}
