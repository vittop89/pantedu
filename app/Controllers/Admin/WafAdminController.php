<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Waf\WafConfigRepository;
use App\Services\Waf\WafLogService;
use App\Services\Waf\WafRulesService;
use App\Services\Waf\WafSecurityRepository;

/**
 * Admin UI per WAF — super_admin only.
 *
 * Routes (vedi routes/web.php gruppo /admin/waf):
 *
 *   Pagine HTML:
 *     GET  /admin/waf                       redirect a /admin/waf/dashboard
 *     GET  /admin/waf/dashboard             real-time logs + counters + charts
 *     GET  /admin/waf/config                toggle mode + soglie + geo
 *     GET  /admin/waf/rules                 custom rules builder + lista
 *     GET  /admin/waf/blocks                Phase 25.R.19 — tab unificato:
 *                                           whitelist + WAF blacklist + IP auth-flow
 *                                           + credenziali bloccate (merge ex lists+credentials)
 *     GET  /admin/waf/reports               top IP + score distribution
 *
 *   API JSON (azioni — protette CSRF):
 *     POST /admin/waf/api/config            update key/value config
 *     POST /admin/waf/api/rules             create rule
 *     PUT  /admin/waf/api/rules/{id}        update rule
 *     DEL  /admin/waf/api/rules/{id}        delete rule
 *     POST /admin/waf/api/rules/{id}/toggle toggle enabled
 *     POST /admin/waf/api/blacklist         add IP
 *     DEL  /admin/waf/api/blacklist/{id}    remove IP
 *     POST /admin/waf/api/whitelist         add IP
 *     DEL  /admin/waf/api/whitelist/{id}    remove IP
 *     GET  /admin/waf/api/logs              last N logs (JSON)
 *     GET  /admin/waf/api/counters          live counters
 */
final class WafAdminController
{
    public function __construct(
        private readonly ?WafConfigRepository $configRepo = null,
        private readonly ?WafRulesService $rules = null,
        private readonly ?WafLogService $logSvc = null,
    ) {
    }

    private function repo(): WafConfigRepository
    {
        return $this->configRepo ?? new WafConfigRepository();
    }
    private function rulesSvc(): WafRulesService
    {
        return $this->rules ?? new WafRulesService();
    }
    private function logs(): WafLogService
    {
        return $this->logSvc ?? new WafLogService();
    }

    /**
     * Estrae body POST/PUT: form-encoded $_POST + JSON body (Content-Type
     * application/json). Necessario perché PHP non parsifica JSON in $_POST
     * automaticamente.
     *
     * @return array<string,mixed>
     */
    private function body(Request $req): array
    {
        if (!empty($req->post)) {
            return $req->post;
        }
        // 2026-06-03 fix: `Request::parseHeaders()` legge solo gli header `HTTP_*`,
        // ma PHP espone `Content-Type` in `$_SERVER['CONTENT_TYPE']` (senza
        // prefisso) → `$req->headers['content-type']` è vuoto e il vecchio guard
        // `str_contains($ct, 'application/json')` falliva sempre (ogni POST JSON
        // a questo endpoint → "no_fields", salvataggi WAF config rotti). Qui i
        // metodi ricevono solo JSON o form: se $_POST è vuoto, proviamo il parse
        // JSON di php://input direttamente (fix locale, non tocca il core).
        $raw = (string)file_get_contents('php://input');
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
            }
        }
        return [];
    }

    private function guard(): ?Response
    {
        if (!Auth::check() || !Auth::isSuperAdmin()) {
            return Response::html('<h1>403</h1><p>Solo super-admin.</p>', 403);
        }
        return null;
    }

    /**
     * Render WAF view dentro lo shell admin standard (topbar + sidebar +
     * dark-mode + body class fm-shell). Pattern: stesso di AdminToolsController +
     * AdminAnalyticsController + AdminInfrastructureController.
     */
    private function render(string $view, array $data = []): Response
    {
        $viewer = View::default();
        $body   = $viewer->render('admin/waf/' . $view, $data);
        $html   = $viewer->render('layout/shell', [
            'title' => 'WAF — Pantedu',
            'body'  => $body,
        ]);
        return Response::html($html);
    }

    // === PAGES ===

    public function index(Request $req): Response
    {
        return Response::redirect('/admin/waf/dashboard');
    }

    public function dashboard(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $config   = $this->repo()->all();
        $counters = $this->logs()->counters();
        $recent   = $this->logs()->recent(50);
        $enrich   = $this->enrichRequested($req);
        if ($enrich) {
            $recent = $this->enrichRowsRdnsAsn($recent, 'ip');
        }
        return $this->render('dashboard', [
            'user'     => Auth::user() ?? [],
            'config'   => $config,
            'counters' => $counters,
            'recent'   => $recent,
            'enrich'   => $enrich,
            'csrf'     => Csrf::token(),
        ]);
    }

    public function configPage(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        return $this->render('config', [
            'user'   => Auth::user() ?? [],
            'config' => $this->repo()->all(),
            'csrf'   => Csrf::token(),
        ]);
    }

    public function rulesPage(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        return $this->render('rules', [
            'user'   => Auth::user() ?? [],
            'config' => $this->repo()->all(),
            'rules'  => $this->rulesSvc()->listAll(),
            'csrf'   => Csrf::token(),
        ]);
    }

    /**
     * Phase 25.R.19 — Tab unificato `Blocks` (merge ex lists + credentials).
     *
     * Aggrega 4 sorgenti distinte concettualmente correlate ("blocchi
     * sicurezza"):
     *   - waf_whitelist (bypass WAF)
     *   - waf_blacklist (pre-route generic block)
     *   - waf_blocked_ips section!=NULL (auth-flow per-section, JS fetch)
     *   - waf_blocked_credentials (brute-force lockout, JS fetch)
     *
     * Server-side prepara solo blacklist+whitelist (table render PHP); le
     * altre 2 sezioni fetchano via /api/admin/security/blocked-{ips,credentials}.
     */
    public function blocksPage(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $geoip = $this->geoip();
        $countryEnrich = static function (array $rows) use ($geoip): array {
            foreach ($rows as &$r) {
                $ip = (string)($r['ip_or_cidr'] ?? '');
                $singleIp = explode('/', $ip)[0];
                $cc = filter_var($singleIp, FILTER_VALIDATE_IP) ? $geoip->lookup($singleIp) : null;
                $r['country']      = $cc;
                $r['country_flag'] = \App\Services\Waf\GeoIpService::countryFlag($cc);
            }
            return $rows;
        };
        $blacklist = $countryEnrich($this->rulesSvc()->listBlacklist());
        $whitelist = $countryEnrich($this->rulesSvc()->listWhitelist());
        $enrichOn = $this->enrichRequested($req);
        if ($enrichOn) {
            $blacklist = $this->enrichRowsRdnsAsn($blacklist, 'ip_or_cidr');
            $whitelist = $this->enrichRowsRdnsAsn($whitelist, 'ip_or_cidr');
        }
        $clientIp = $this->clientIp($req);
        // Phase 25.R.22 — threat-intel stats merged here (ex /admin/waf/threat-intel tab)
        $tiStats = (new \App\Services\Waf\WafThreatIntelService())->stats();
        return $this->render('blocks', [
            'user'      => Auth::user() ?? [],
            'config'    => $this->repo()->all(),
            'blacklist' => $blacklist,
            'whitelist' => $whitelist,
            'client_ip' => $clientIp,
            'client_country' => $geoip->lookup($clientIp),
            'enrich'    => $enrichOn,
            'ti_stats'  => $tiStats,
            'csrf'      => Csrf::token(),
        ]);
    }

    /** Phase 25.R.19 — back-compat: redirect 301 ex /admin/waf/lists → /blocks#blacklist */
    public function listsPage(Request $req): Response
    {
        return Response::redirect('/admin/waf/blocks#blacklist', 301);
    }

    /**
     * Costruisce GeoIpService con entrambi i DB (country + ASN).
     * Phase 25.H — usato per enrichment "RDNS & ASN" admin toggle.
     */
    private function geoip(): \App\Services\Waf\GeoIpService
    {
        return new \App\Services\Waf\GeoIpService(
            (string)(\App\Core\Config::get('waf.geoip_db', '')) ?: null,
            (string)(\App\Core\Config::get('waf.geoip_asn_db', '')) ?: null,
        );
    }

    /**
     * True se admin ha richiesto enrichment rDNS+ASN via ?enrich=1.
     *
     * Phase 25.H.1 — semplificato: solo URL param. Era doppia guardia
     * config+URL ma confondeva (toggle UI sembrava rotto se config OFF
     * dimenticato). Click su toggle = consenso esplicito admin.
     * Config `enrich_rdns_asn` resta come hint env per UX (mostra
     * warning se mmdb non installato).
     */
    private function enrichRequested(Request $req): bool
    {
        return !empty($req->query['enrich']);
    }

    /**
     * Arricchisce ogni riga con `rdns` + `asn` + `org` via GeoIpService.
     * IP key indica il nome della colonna che contiene l'IP (es. 'ip',
     * 'ip_or_cidr'). Per CIDR usa il primo IP del range.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function enrichRowsRdnsAsn(array $rows, string $ipKey = 'ip'): array
    {
        $geo = $this->geoip();
        foreach ($rows as &$r) {
            $raw = (string)($r[$ipKey] ?? '');
            $ip = explode('/', $raw)[0];
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $r['rdns'] = null;
                $r['asn'] = null;
                $r['org'] = null;
                continue;
            }
            $e = $geo->enrich($ip);
            $r['rdns'] = $e['rdns'];
            $r['asn']  = $e['asn'];
            $r['org']  = $e['org'];
        }
        return $rows;
    }

    private function clientIp(Request $req): string
    {
        $s = $req->server ?? [];
        $xff = (string)($s['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return (string)($s['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function reportsPage(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $logs = $this->logs();

        // Arricchimento top_ips con country flag (static helper, no SDK init)
        $topIps = $logs->topIps(7, 20);
        foreach ($topIps as &$row) {
            $row['country_flag'] = \App\Services\Waf\GeoIpService::countryFlag($row['country'] ?? null);
        }
        unset($row);
        // RDNS + ASN enrich (opt-in via ?enrich=1 + config toggle)
        $enrichOn = $this->enrichRequested($req);
        if ($enrichOn) {
            $topIps = $this->enrichRowsRdnsAsn($topIps, 'ip');
        }

        // Top countries (con flag)
        $topCountries = $logs->topCountries(7, 15);
        foreach ($topCountries as &$r) {
            $r['flag'] = \App\Services\Waf\GeoIpService::countryFlag($r['country'] ?? null);
        }

        // Counters auth-protection (DB modernized): cred + IP-auth + IP-manual
        $authCounters = (new WafSecurityRepository())->counters();
        $anomalies    = $this->countAnomalies();

        // Phase 25.R.22 — diag data injected per accordion (ex /admin/waf/diag merged here)
        $diagData = $this->diagData();

        return $this->render('reports', array_merge([
            'user'              => Auth::user() ?? [],
            'config'            => $this->repo()->all(),
            'top_ips'           => $topIps,
            'top_countries'     => $topCountries,
            'score_dist'        => $logs->scoreDistribution(7),
            'rpm_outcome'       => $logs->rpmByOutcome(6),
            'outcome_breakdown' => $logs->outcomeBreakdown(7),
            'counters'          => $logs->counters(),
            'auth_counters'     => $authCounters,
            'anomalies_count'   => $anomalies,
            'enrich'            => $enrichOn,
            'csrf'              => Csrf::token(),
        ], $diagData));
    }

    /**
     * Anomalie real-time (excessive_access + credential_sharing) restano
     * detection JSON-based (AnomalyDetectionService computa da access_log).
     * Future Phase 25.G: persistere in `waf_anomalies` table.
     */
    private function countAnomalies(): int
    {
        $base = (string)\App\Core\Config::get('app.paths.base', dirname(__DIR__, 3));
        $path = $base . '/log/security/alerts/anomalies.json';
        if (!file_exists($path)) {
            return 0;
        }
        try {
            $raw = file_get_contents($path);
            if ($raw === false) {
                return 0;
            }
            $data = json_decode($raw, true);
            return is_array($data) ? count($data) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Phase 25.R.19 — back-compat: redirect 301 ex /admin/waf/credentials → /blocks#credentials */
    public function credentialsPage(Request $req): Response
    {
        return Response::redirect('/admin/waf/blocks#credentials', 301);
    }

    /**
     * Tab Anomalies: anomaly detection legacy soglie + lista alerts.
     */
    /**
     * Phase 25.R.22 — back-compat: pagina /anomalies eliminata.
     * Split:
     *   - Soglie config  → /admin/waf/config#anomaly-thresholds
     *   - Lista rilevati → /admin/waf/blocks#anomalies-detected
     * Lista è il contenuto principale → target redirect default.
     */
    public function anomaliesPage(Request $req): Response
    {
        return Response::redirect('/admin/waf/blocks#anomalies-detected', 301);
    }

    /**
     * Phase 25.I — Threat Intelligence panel.
     * Mostra stats sync per ogni source + ultimo run + bottoni sync manuale.
     */
    /**
     * Phase 25.R.22 — back-compat: tab Threat Intel eliminato. Split:
     *   - Stats + sync UI    → /admin/waf/blocks#threat-intel
     *   - Master toggle + key → /admin/waf/config#threat-intel-config
     *   - Read-only stats    → /admin/waf/reports#diagnostics (accordion)
     */
    public function threatIntelPage(Request $req): Response
    {
        return Response::redirect('/admin/waf/blocks#threat-intel', 301);
    }

    /** POST /admin/waf/api/threat-intel/sync — esegue sync di uno o tutti i source */
    public function apiThreatIntelSync(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $body = $this->body($req);
        $source = (string)($body['source'] ?? 'all');
        $ti = new \App\Services\Waf\WafThreatIntelService();
        $jobs = [
            'asn'      => 'importBadAsnList',
            'spamhaus' => 'importSpamhaus',
            'x4b'      => 'importX4bVpn',
            'crowdsec' => 'importCrowdSec',
            'tor'      => 'importTor',
        ];
        $run = $source === 'all' ? array_keys($jobs) : [$source];
        $results = [];
        foreach ($run as $k) {
            if (!isset($jobs[$k])) {
                continue;
            }
            $results[$k] = $ti->{$jobs[$k]}();
        }
        return Response::json(['ok' => true, 'results' => $results]);
    }

    /**
     * Phase 25.H — Diagnostica admin per verificare setup GeoIP/ASN
     * senza richiedere SSH. Mostra path mmdb, esistenza, lookup test.
     */
    /**
     * Phase 25.R.22 — back-compat: tab Diag eliminato.
     * Contenuto inlined come accordion in /admin/waf/reports.
     */
    public function diagPage(Request $req): Response
    {
        return Response::redirect('/admin/waf/reports#diagnostics', 301);
    }

    /**
     * Phase 25.R.22 — Computa dati diagnostica per fragment _diag_fragment.php.
     * Usato da reportsPage() per accordion.
     *
     * @return array<string,mixed>
     */
    private function diagData(): array
    {
        $countryPath = (string)\App\Core\Config::get('waf.geoip_db', '');
        $asnPath     = (string)\App\Core\Config::get('waf.geoip_asn_db', '');
        $geo = $this->geoip();
        $testIps = ['8.8.8.8', '1.1.1.1', '79.18.139.97'];
        $results = [];
        foreach ($testIps as $ip) {
            $results[$ip] = [
                'country' => $geo->lookup($ip),
                'enrich'  => $geo->enrich($ip),
            ];
        }
        $envInfo = [
            'WAF_GEOIP_DB'     => $_ENV['WAF_GEOIP_DB']     ?? $_SERVER['WAF_GEOIP_DB']     ?? '(unset)',
            'WAF_GEOIP_ASN_DB' => $_ENV['WAF_GEOIP_ASN_DB'] ?? $_SERVER['WAF_GEOIP_ASN_DB'] ?? '(unset)',
        ];
        $sdkAvail = class_exists('\\GeoIp2\\Database\\Reader');

        $wafCfg = $this->repo()->all();
        $cs = \App\Services\Waf\WafCrowdSecBouncerService::default();
        $csStatus = $cs->status();

        try {
            $pdo = \App\Core\Database::connection();
            $hpStats = $pdo->query(
                "SELECT COUNT(*) AS hits_total,
                        SUM(ts >= NOW() - INTERVAL 24 HOUR) AS hits_24h,
                        COUNT(DISTINCT ip) AS unique_ips
                 FROM waf_logs WHERE outcome = 'honeypot_trap'"
            )->fetch(\PDO::FETCH_ASSOC);
            $hpTop = $pdo->query(
                "SELECT ip, country, COUNT(*) AS hits,
                        SUBSTRING_INDEX(GROUP_CONCAT(request_uri ORDER BY ts DESC SEPARATOR '|||'), '|||', 1) AS last_path,
                        MAX(ts) AS last_ts
                 FROM waf_logs WHERE outcome = 'honeypot_trap'
                 GROUP BY ip, country ORDER BY hits DESC LIMIT 10"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            $hpStats = ['hits_total' => 0, 'hits_24h' => 0, 'unique_ips' => 0];
            $hpTop = [];
        }

        $tiStats = (new \App\Services\Waf\WafThreatIntelService())->stats();

        $logPath = '/var/log/pantedu-deploy.log';
        $logTail = null;
        if (is_readable($logPath)) {
            $lines = @file($logPath, FILE_IGNORE_NEW_LINES);
            $logTail = $lines ? array_slice($lines, -50) : [];
        }

        return [
            'diag_countryPath' => $countryPath,
            'diag_asnPath'     => $asnPath,
            'diag_envInfo'     => $envInfo,
            'diag_sdkAvail'    => $sdkAvail,
            'diag_results'     => $results,
            'diag_wafCfg'      => $wafCfg,
            'diag_csStatus'    => $csStatus,
            'diag_hpStats'     => $hpStats,
            'diag_hpTop'       => $hpTop,
            'diag_tiStats'     => $tiStats,
            'diag_logTail'     => $logTail,
            'diag_logPath'     => $logPath,
        ];
    }


    // === JSON API ===

    public function apiUpdateConfig(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $body = $this->body($req);
        $allowed = [
            'enabled', 'mode', 'threshold_pass', 'threshold_block',
            'session_ttl', 'geo_allowed', 'geo_mode', 'challenge_template',
            'log_retention_days', 'enrich_rdns_asn',
            'threat_intel_enabled', 'crowdsec_api_key', 'abuseipdb_api_key',
            'honeypot_enabled', 'honeypot_action',
            // Track 7 — Content-Security-Policy mode (SecurityHeadersMiddleware).
            'csp_mode', 'csp_report_uri',
        ];
        $update = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $body)) {
                $update[$k] = (string)$body[$k];
            }
        }
        if (empty($update)) {
            return Response::json(['error' => 'no_fields'], 400);
        }
        // Validation minima
        if (isset($update['threshold_pass'])) {
            $update['threshold_pass'] = (string)max(0, min(99, (int)$update['threshold_pass']));
        }
        if (isset($update['threshold_block'])) {
            $update['threshold_block'] = (string)max(1, min(100, (int)$update['threshold_block']));
        }
        if (isset($update['mode'])) {
            $valid = ['off', 'monitor', 'soft', 'enforce', 'under_attack'];
            if (!in_array($update['mode'], $valid, true)) {
                return Response::json(['error' => 'invalid_mode'], 400);
            }
        }
        if (isset($update['geo_mode'])) {
            $valid = ['off', 'monitor', 'enforce'];
            if (!in_array($update['geo_mode'], $valid, true)) {
                return Response::json(['error' => 'invalid_geo_mode'], 400);
            }
        }
        // Audit 25.R.31 — challenge_template è un identificatore di template
        // (prima non validato, a differenza di mode/geo_mode): charset sicuro
        // per evitare path traversal / injection sul nome.
        if (
            isset($update['challenge_template']) && $update['challenge_template'] !== ''
            && !preg_match('#^[A-Za-z0-9_-]{1,32}$#', $update['challenge_template'])
        ) {
            return Response::json(['error' => 'invalid_challenge_template'], 400);
        }
        if (
            isset($update['csp_mode'])
            && !in_array($update['csp_mode'], ['relaxed', 'report-only', 'strict'], true)
        ) {
            return Response::json(['error' => 'invalid_csp_mode'], 400);
        }
        $uid = (int)(Auth::user()['id'] ?? 0) ?: null;
        $this->repo()->set($update, $uid);
        return Response::json(['ok' => true, 'updated' => array_keys($update)]);
    }

    public function apiCreateRule(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $body = $this->body($req);
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') {
            return Response::json(['error' => 'name_required'], 400);
        }
        $action = (string)($body['action'] ?? 'block');
        if (!in_array($action, ['allow', 'block', 'challenge', 'log_only'], true)) {
            return Response::json(['error' => 'invalid_action'], 400);
        }
        $conditions = $body['conditions'] ?? null;
        if (is_string($conditions)) {
            try {
                $conditions = json_decode($conditions, true, 8, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return Response::json(['error' => 'invalid_conditions_json'], 400);
            }
        }
        if (!is_array($conditions) || empty($conditions['conditions'] ?? [])) {
            return Response::json(['error' => 'conditions_required'], 400);
        }
        // Audit 25.R.31 — dry-run delle regex: una regex invalida veniva
        // persistita e poi falliva (silenziosa) nell'hot-path di OGNI request
        // (matchSingle). Validiamo ogni operatore matches_regex prima di salvare.
        foreach ((array)($conditions['conditions'] ?? []) as $c) {
            if (is_array($c) && (string)($c['operator'] ?? '') === 'matches_regex') {
                // Anti-ReDoS (audit 2026-06-01): compilabile + lunghezza
                // bounded + dry-run non catastrofico (vedi WafRulesService).
                if (!\App\Services\Waf\WafRulesService::isRegexConditionSafe((string)($c['value'] ?? ''))) {
                    return Response::json(['error' => 'invalid_regex', 'detail' => (string)($c['value'] ?? '')], 400);
                }
            }
        }
        $uid = (int)(Auth::user()['id'] ?? 0) ?: null;
        $id = $this->rulesSvc()->create([
            'name'        => $name,
            'description' => (string)($body['description'] ?? ''),
            'enabled'     => !empty($body['enabled']),
            'priority'    => (int)($body['priority'] ?? 100),
            'conditions'  => $conditions,
            'action'      => $action,
        ], $uid);
        return Response::json(['ok' => true, 'id' => $id]);
    }

    public function apiUpdateRule(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $id = (int)($req->params['id'] ?? 0);
        if ($id <= 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $body = $this->body($req);
        $data = [];
        foreach (['name', 'description', 'action', 'priority'] as $k) {
            if (array_key_exists($k, $body)) {
                $data[$k] = $body[$k];
            }
        }
        if (array_key_exists('enabled', $body)) {
            $data['enabled'] = !empty($body['enabled']);
        }
        if (array_key_exists('conditions', $body)) {
            $c = $body['conditions'];
            if (is_string($c)) {
                try {
                    $c = json_decode($c, true, 8, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    return Response::json(['error' => 'invalid_conditions_json'], 400);
                }
            }
            // Anti-ReDoS: valida ogni condizione matches_regex anche in update.
            foreach ((array)($c['conditions'] ?? []) as $cond) {
                if (
                    is_array($cond) && (string)($cond['operator'] ?? '') === 'matches_regex'
                    && !\App\Services\Waf\WafRulesService::isRegexConditionSafe((string)($cond['value'] ?? ''))
                ) {
                    return Response::json(['error' => 'invalid_regex', 'detail' => (string)($cond['value'] ?? '')], 400);
                }
            }
            $data['conditions'] = $c;
        }
        $ok = $this->rulesSvc()->update($id, $data);
        return Response::json(['ok' => $ok]);
    }

    public function apiDeleteRule(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $id = (int)($req->params['id'] ?? 0);
        return Response::json(['ok' => $this->rulesSvc()->delete($id)]);
    }

    public function apiToggleRule(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $id = (int)($req->params['id'] ?? 0);
        $rule = $this->rulesSvc()->find($id);
        if ($rule === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $newState = !((int)$rule['enabled'] === 1);
        return Response::json(['ok' => $this->rulesSvc()->setEnabled($id, $newState), 'enabled' => $newState]);
    }

    public function apiAddBlacklist(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $body = $this->body($req);
        $ip = trim((string)($body['ip_or_cidr'] ?? ''));
        if ($ip === '' || !self::isValidIpOrCidr($ip)) {
            return Response::json(['error' => 'ip_invalid'], 400);
        }
        $expires = !empty($body['expires_at']) ? new \DateTimeImmutable((string)$body['expires_at']) : null;
        $uid = (int)(Auth::user()['id'] ?? 0) ?: null;
        $ok = $this->rulesSvc()->addBlacklist($ip, (string)($body['reason'] ?? ''), $expires, $uid);
        return Response::json(['ok' => $ok]);
    }

    /** Audit 25.R.31 — valida IPv4/IPv6 o CIDR; prima un valore non valido veniva
     *  persistito → regola di block/whitelist silenziosamente inefficace. */
    private static function isValidIpOrCidr(string $s): bool
    {
        if (filter_var($s, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (!str_contains($s, '/')) {
            return false;
        }
        [$addr, $prefix] = explode('/', $s, 2);
        if (!ctype_digit($prefix) || filter_var($addr, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $max = filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;
        return (int)$prefix >= 0 && (int)$prefix <= $max;
    }

    public function apiDeleteBlacklist(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $id = (int)($req->params['id'] ?? 0);
        return Response::json(['ok' => $this->rulesSvc()->deleteBlacklist($id)]);
    }

    public function apiAddWhitelist(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $body = $this->body($req);
        $ip = trim((string)($body['ip_or_cidr'] ?? ''));
        if ($ip === '' || !self::isValidIpOrCidr($ip)) {
            return Response::json(['error' => 'ip_invalid'], 400);
        }
        $expires = !empty($body['expires_at']) ? new \DateTimeImmutable((string)$body['expires_at']) : null;
        $uid = (int)(Auth::user()['id'] ?? 0) ?: null;
        $ok = $this->rulesSvc()->addWhitelist($ip, (string)($body['reason'] ?? ''), $expires, $uid);
        return Response::json(['ok' => $ok]);
    }

    public function apiDeleteWhitelist(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $id = (int)($req->params['id'] ?? 0);
        return Response::json(['ok' => $this->rulesSvc()->deleteWhitelist($id)]);
    }

    public function apiLogs(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $limit = (int)($req->query['limit'] ?? 100);
        $outcome = $req->query['outcome'] ?? null;
        return Response::json(['logs' => $this->logs()->recent($limit, $outcome ? (string)$outcome : null)]);
    }

    public function apiCounters(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        return Response::json([
            'counters' => $this->logs()->counters(),
            'rpm'      => $this->logs()->rpmByOutcome(6),
        ]);
    }
}
