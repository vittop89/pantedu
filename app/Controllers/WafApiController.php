<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\Waf\EdgeContext;
use App\Services\Waf\GeoIpService;
use App\Repositories\Waf\WafConfigRepository;
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
 *
 * In modalità `monitor` la sfida assegnata non è mai `block`: al suo posto
 * `soft` (23/9/2026, A-71). Prima il cookie portava `block` anche in
 * monitor, e lo script della sfida mostrava «Accesso bloccato» a chi aveva
 * un punteggio alto proprio mentre la modalità doveva solo osservare; se la
 * modalità passava a `enforce`, quel cookie bloccava per tutta la sua
 * durata con soglie ancora in taratura. Con `soft` il monitor registra
 * `monitor_soft` a ogni richiesta, e in `enforce` arriva la sfida
 * interstiziale, che un browser vero supera. Il punteggio resta nel
 * registro, e dice che cosa la soglia avrebbe deciso.
 */
final class WafApiController
{
    private ?WafConfigRepository $config;
    private ?WafLogService $registro;

    /**
     * Configurazione e registro si passano solo nelle prove (WafInMonitorTest):
     * senza, sono quelli sul database, come prima.
     */
    public function __construct(?WafConfigRepository $config = null, ?WafLogService $registro = null)
    {
        $this->config = $config;
        $this->registro = $registro;
    }

    public function collect(Request $req): Response
    {
        $raw = $req->rawBody();
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

        $config = $this->config ?? new WafConfigRepository();
        $secret = (string)Config::get('waf.hmac_secret', '');
        if (strlen($secret) < 32) {
            // Misconfig grave: secret non risolvibile (mai dovrebbe accadere,
            // vedi fallback key-file in config/waf.php). Log + 503 esplicito.
            error_log('[WAF] hmac_secret non disponibile (<32B) in WafApiController');
            return Response::fail('waf_not_configured', 503);
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
                    ($this->registro ?? new WafLogService())->log([
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
        if ($challenge === 'block' && $config->get('mode', 'monitor') === 'monitor') {
            $challenge = 'soft';
        }

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
        ($this->registro ?? new WafLogService())->log([
            'ip'            => $ip,
            'country'       => $geo,
            'user_agent'    => substr($serverUa, 0, 512),
            'request_uri'   => '/waf/fingerprint',
            'method'        => 'POST',
            'score'         => $score,
            'challenge'     => $challenge,
            // Ventuno caratteri: ci stanno dalla migrazione 135 in poi. Prima
            // la colonna era varchar(16) e questa riga non si scriveva mai.
            'outcome'       => 'fingerprint_collected',
            'outcome_source' => 'pow:' . $powState,
            // 22/9/2026 — NON si scrive. Fino a oggi questa riga non
            // arrivava mai al database perché l'INSERT falliva per un altro
            // motivo (l'esito non entrava in `outcome`), e l'informativa
            // poteva dire «nessuna impronta viene conservata»: era vero per
            // caso. Allargata la colonna, la riga si sarebbe scritta, e quella
            // frase sarebbe diventata falsa per effetto della correzione.
            // `fp_hash` non lo legge nessuno — solo l'esportazione dei propri
            // dati, che esporta ciò che trova — quindi non si conserva: si
            // rende vera la frase invece di riscriverla. Il punteggio si
            // calcola e resta; l'impronta si perde con la richiesta.
            'fp_hash'       => null,
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
