<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\Waf\WafConfigRepository;
use App\Services\Waf\WafLogService;
use App\Services\Waf\WafRulesService;
use App\Repositories\Waf\WafSecurityRepository;

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

    private function guard(): ?Response
    {
        if (!Auth::check() || !Auth::isSuperAdmin()) {
            return Response::html('<h1>403</h1><p>Solo super-admin.</p>', 403);
        }
        return null;
    }

    /**
     * L'id del percorso (`/admin/waf/api/rules/{id}` e simili); 0 se manca o
     * non è un intero positivo.
     *
     * 23/9/2026 (revisione architetturale, A-5) — le cinque azioni con l'id
     * nel percorso lo leggevano da `$req->params`, una proprietà che Request
     * non ha: il `??` taceva l'avviso e l'id valeva sempre 0. Nessuna regola
     * si disattivava né si cancellava, nessuna voce delle liste si toglieva,
     * ma le eliminazioni rispondevano `{ok:true}` e il registro dei privilegi
     * annotava azioni mai avvenute. I parametri della rotta arrivano come
     * secondo argomento dell'azione (Kernel::invoke), come in ogni altro
     * controller; la regola sulla tabella delle rotte è in
     * tests/Unit/Core/ParametriDiRottaTest.php.
     *
     * @param array<string, string> $params
     */
    private static function idDelPercorso(array $params): int
    {
        $id = (string)($params['id'] ?? '');
        return ctype_digit($id) ? (int)$id : 0;
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

    /**
     * Gli esiti che il WAF scrive, per il menu del filtro. Sono quelli di
     * `WafMiddleware`: se ne nascesse uno nuovo e non finisse qui, il filtro
     * non lo offrirebbe (e la prova di unità lo dice).
     */
    public const ESITI = [
        'pass', 'whitelist', 'allow_rule', 'log_rule',
        'challenge_first', 'challenge_rule', 'challenge_soft',
        'challenge_crowdsec', 'challenge_threat_intel', 'under_attack_interstitial',
        'monitor_geo', 'monitor_soft',
        // 23/9/2026 (A-71): in monitor un blocco si registra così e la
        // richiesta passa.
        'monitor_manual', 'monitor_threat_intel', 'monitor_crowdsec', 'monitor_rule', 'monitor_score',
        'blocked_geo', 'blocked_manual', 'blocked_rule', 'blocked_score',
        'blocked_crowdsec', 'blocked_threat_intel', 'honeypot_trap',
    ];

    /**
     * Il filtro delle richieste scrutinizzate, dai parametri dell'indirizzo.
     *
     * 21/9/2026 — la tabella mostrava le ultime cinquanta e basta: durante una
     * lezione cinquanta richieste sono pochi secondi, e chi cerca «perché quel
     * ragazzo non è entrato alle 10:18» non le trova più. Adesso si può
     * chiedere un esito solo e allargare la finestra. Un esito che non
     * conosciamo si ignora invece di finire nella query.
     *
     * @param array<string,mixed> $query
     * @return array{outcome: ?string, limit: int, dalle: ?string, alle: ?string}
     */
    public static function filtroDelleRichieste(array $query, int $quanteDiDefault = 50): array
    {
        $esito = \is_string($query['outcome'] ?? null) ? trim((string)$query['outcome']) : '';
        $quante = (int)($query['limit'] ?? $quanteDiDefault);
        return [
            'outcome' => \in_array($esito, self::ESITI, true) ? $esito : null,
            // Il tetto è quello della query (mille): chiedere di più non
            // darebbe di più, e il menu si ferma prima.
            'limit'   => max(10, min(1000, $quante)),
            'dalle'   => self::istante($query['dalle'] ?? null),
            'alle'    => self::istante($query['alle'] ?? null),
        ];
    }

    /**
     * Un istante scritto da una persona (o da un campo `datetime-local`) come
     * lo vuole il database, o null se non si capisce.
     *
     * Si accettano «2026-09-21T10:00», «2026-09-21 10:00» e con i secondi. Una
     * data sola vale dall'inizio del giorno. Tutto il resto è null: meglio
     * nessun filtro che un filtro che finge.
     */
    private static function istante(mixed $valore): ?string
    {
        if (!\is_string($valore)) {
            return null;
        }
        $t = str_replace('T', ' ', trim($valore));
        if ($t === '') {
            return null;
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $forma) {
            $d = \DateTimeImmutable::createFromFormat('!' . $forma, $t);
            if ($d !== false && $d->format($forma) === $t) {
                return $d->format('Y-m-d H:i:s');
            }
        }
        return null;
    }

    public function dashboard(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $filtro   = self::filtroDelleRichieste($req->query ?? []);
        $config   = $this->repo()->all();
        $counters = $this->logs()->counters();
        $recent   = $this->logs()->recent($filtro['limit'], $filtro['outcome'], $filtro['dalle'], $filtro['alle']);
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
            'filtro'   => $filtro,
            'esiti'    => self::ESITI,
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

    /**
     * L'IP con cui il WAF vede chi sta guardando il pannello.
     *
     * 23/9/2026 — da EdgeContext, cioè lo stesso su cui il WAF decide. Qui si
     * prendeva il primo elemento di `X-Forwarded-For`, che sceglie il client:
     * dietro il CDN il pannello poteva mostrare un indirizzo diverso da quello
     * che il WAF blocca o lascia passare (revisione architetturale 2026-09,
     * A-63).
     */
    private function clientIp(Request $req): string
    {
        return \App\Services\Waf\EdgeContext::clientIp($req->server ?? []);
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
        $diagData = $this->diagData($req);

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
        // Dati d'istanza, e accanto al file gemello delle soglie
        // (`storage/security/alerts/config.json`). Prima nasceva da
        // `app.paths.base`, cioè dalla radice del repository, che nel
        // container è l'immagine: lì un file scritto a runtime non c'è mai.
        //
        // Va detto: oggi `anomalies.json` non lo scrive nessuno (misurato il
        // 20/9/2026 — questo è l'unico punto del codice che lo nomina),
        // quindi il conto è zero finché la Fase 25.G non persisterà le
        // anomalie. Il percorso giusto serve perché quel giorno chi scrive e
        // chi legge si trovino.
        $base = \App\Support\PercorsiDati::base(dirname(__DIR__, 3));
        $path = $base . '/storage/security/alerts/anomalies.json';
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
        $body = $req->body();
        $source = (string)($body['source'] ?? 'all');
        $ti = new \App\Services\Waf\WafThreatIntelService();
        $jobs = [
            'asn'      => 'importBadAsnList',
            'spamhaus' => 'importSpamhaus',
            'x4b'      => 'importX4bVpn',
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
     * GET /admin/waf/api/cti?ip=… — che reputazione ha questo indirizzo.
     *
     * Su richiesta, un indirizzo per volta: è l'unica cosa che la chiave CTI
     * del piano gratuito permette (l'endpoint a forma di elenco è a pagamento,
     * voce 82 del debito). La risposta resta in cache ventiquattr'ore, perchè
     * la quota giornaliera è stretta e riaprire la pagina non deve consumarla.
     *
     * `?forza=1` la richiede comunque: serve quando si sospetta che la
     * reputazione sia cambiata da poco.
     */
    public function apiCti(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $ip = trim((string)($req->query['ip'] ?? ''));
        if ($ip === '') {
            return Response::json(['ok' => false, 'motivo' => 'indirizzo mancante'], 400);
        }
        $forza = (string)($req->query['forza'] ?? '') === '1';

        return Response::json((new \App\Services\Waf\WafCtiService())->guarda($ip, $forza));
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
     * Gli indirizzi del «Test lookup» della diagnostica, con che cosa sono.
     *
     * Due risolutori pubblici, di cui paese e ASN sono noti, e l'indirizzo di
     * chi guarda la pagina come lo ricostruisce EdgeContext: è quello che
     * serve per sapere se il blocco geografico lo lascerebbe passare.
     *
     * 23/9/2026 (A-44) — al posto del terzo c'era un indirizzo personale del
     * manutentore, scritto nel codice e non più attuale: diceva come veniva
     * visto un indirizzo di ieri, e intanto lo pubblicava in ogni copia del
     * repository.
     *
     * @param array<string,mixed> $server
     * @return array<string,string> indirizzo => che cos'è
     */
    public static function indirizziDiProva(array $server): array
    {
        $fuori = [
            '8.8.8.8' => 'risolutore pubblico',
            '1.1.1.1' => 'risolutore pubblico',
        ];
        // EdgeContext risponde 0.0.0.0 quando non ha un indirizzo valido: non
        // è di nessuno, e in tabella sembrerebbe un risultato.
        $tuo = \App\Services\Waf\EdgeContext::clientIp($server);
        if ($tuo !== '0.0.0.0' && filter_var($tuo, FILTER_VALIDATE_IP) !== false && !isset($fuori[$tuo])) {
            $fuori[$tuo] = 'il tuo indirizzo';
        }
        return $fuori;
    }

    /**
     * Le righe della tabella «Test lookup»: una per ogni indirizzo di
     * indirizziDiProva(), e nessun'altra.
     *
     * È l'unico punto da cui la vista riceve quella tabella (`diag_results`):
     * diagData() la prende da qui. La ricerca sta fuori, in `$guarda`, perché
     * quella vera interroga il GeoIP e il DNS inverso, e le prove non devono
     * andare in rete (23/9/2026, A-44).
     *
     * @param array<string,mixed> $server
     * @param \Closure(string): array{country: ?string, enrich: array<string,mixed>} $guarda
     * @return array<string, array{nota: string, country: ?string, enrich: array<string,mixed>}>
     */
    public static function righeDelTestLookup(array $server, \Closure $guarda): array
    {
        $righe = [];
        foreach (self::indirizziDiProva($server) as $ip => $nota) {
            $trovato = $guarda($ip);
            $righe[$ip] = [
                'nota'    => $nota,
                'country' => $trovato['country'],
                'enrich'  => $trovato['enrich'],
            ];
        }
        return $righe;
    }

    /**
     * Phase 25.R.22 — Computa dati diagnostica per fragment _diag_fragment.php.
     * Usato da reportsPage() per accordion.
     *
     * @return array<string,mixed>
     */
    private function diagData(Request $req): array
    {
        $countryPath = (string)\App\Core\Config::get('waf.geoip_db', '');
        $asnPath     = (string)\App\Core\Config::get('waf.geoip_asn_db', '');
        $geo = $this->geoip();
        $results = self::righeDelTestLookup(
            $req->server,
            static fn (string $ip): array => ['country' => $geo->lookup($ip), 'enrich' => $geo->enrich($ip)]
        );
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
        $body = $req->body();
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
        $body = $req->body();
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

    /** @param array<string, string> $params parametri della rotta */
    public function apiUpdateRule(Request $req, array $params = []): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $id = self::idDelPercorso($params);
        if ($id <= 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        // Un UPDATE su un id che non c'è riesce senza toccare niente: si
        // risponde 404, non «ok» (23/9/2026, A-5).
        if ($this->rulesSvc()->find($id) === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $body = $req->body();
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

    /** @param array<string, string> $params parametri della rotta */
    public function apiDeleteRule(Request $req, array $params = []): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $id = self::idDelPercorso($params);
        if ($id <= 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        // La DELETE su un id che non c'è riesce anche lei: prima si guarda
        // che la regola esista (23/9/2026, A-5).
        if ($this->rulesSvc()->find($id) === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return Response::json(['ok' => $this->rulesSvc()->delete($id)]);
    }

    /** @param array<string, string> $params parametri della rotta */
    public function apiToggleRule(Request $req, array $params = []): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $id = self::idDelPercorso($params);
        if ($id <= 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
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
        $body = $req->body();
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

    /** @param array<string, string> $params parametri della rotta */
    public function apiDeleteBlacklist(Request $req, array $params = []): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $id = self::idDelPercorso($params);
        if ($id <= 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        // deleteBlacklist() dice se ha tolto una voce (23/9/2026, A-5).
        if (!$this->rulesSvc()->deleteBlacklist($id)) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return Response::json(['ok' => true]);
    }

    public function apiAddWhitelist(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $body = $req->body();
        $ip = trim((string)($body['ip_or_cidr'] ?? ''));
        if ($ip === '' || !self::isValidIpOrCidr($ip)) {
            return Response::json(['error' => 'ip_invalid'], 400);
        }
        $expires = !empty($body['expires_at']) ? new \DateTimeImmutable((string)$body['expires_at']) : null;
        $uid = (int)(Auth::user()['id'] ?? 0) ?: null;
        $ok = $this->rulesSvc()->addWhitelist($ip, (string)($body['reason'] ?? ''), $expires, $uid);
        return Response::json(['ok' => $ok]);
    }

    /** @param array<string, string> $params parametri della rotta */
    public function apiDeleteWhitelist(Request $req, array $params = []): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        $id = self::idDelPercorso($params);
        if ($id <= 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        // deleteWhitelist() dice se ha tolto una voce (23/9/2026, A-5).
        if (!$this->rulesSvc()->deleteWhitelist($id)) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return Response::json(['ok' => true]);
    }

    public function apiLogs(Request $req): Response
    {
        if ($g = $this->guard()) {
            return $g;
        }
        // Stesso filtro della pagina: esito, finestra, quantità (21/9/2026).
        $f = self::filtroDelleRichieste($req->query ?? [], 100);
        return Response::json([
            'filtro' => $f,
            'logs'   => $this->logs()->recent($f['limit'], $f['outcome'], $f['dalle'], $f['alle']),
        ]);
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
