<?php

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimitStore;

/**
 * Sliding-window rate limiter for authenticated write endpoints.
 *
 * Soglie per-role (richieste / window):
 *   - admin:       120 req / 60 s
 *   - teacher:      60 req / 60 s
 *   - altri:        15 req / 60 s
 *
 * Phase 19 — Storage tramite RateLimitStore (backend DB o session,
 * auto-selected via env RATE_LIMIT_BACKEND). DB consente monitoring
 * + revocabilità cross-session.
 *
 * Sforamento: HTTP 429 + JSON {"error":"rate limit exceeded","retry_after":N}.
 */
final class RateLimitMiddleware
{
    /** La finestra predefinita, quando la rotta non ne dichiara una. */
    private const WINDOW_SECONDS = 60;

    /**
     * La finestra di un secchio, dal terzo parametro della rotta.
     *
     * PERCHE' (2026-09-22)
     *   Fino a oggi la finestra era una sola, sessanta secondi, per tutti.
     *   Ma `/segnalazione-contenuti` era dichiarato **3 all'ora per IP** in
     *   tre posti — la pagina pubblica `/legal/takedown-procedure`, l'indice
     *   dei documenti legali e il commento del controller — e operava a tre al
     *   minuto, cioè centottanta all'ora. Un controllo dichiarato che non
     *   opera, e per giunta scritto in un documento pubblicato in rete.
     *
     *   Dei due modi di riallineare, si è scelto quello che tiene fede al
     *   documento: il limite che i documenti promettono è anche quello giusto,
     *   perché quei moduli mandano posta a ogni invio.
     *
     * La dichiarazione è `rate:<secchio>,<quanti>,<finestra in secondi>`; il
     * Kernel spande già gli argomenti separati da virgola. Senza il terzo,
     * vale la finestra di sempre — nessuna rotta cambia comportamento se non
     * lo chiede.
     */
    public static function finestraSecondi(?string $dichiarata): int
    {
        if ($dichiarata !== null && ctype_digit($dichiarata) && (int)$dichiarata > 0) {
            return (int)$dichiarata;
        }

        return self::WINDOW_SECONDS;
    }

    /**
     * Il limite scritto in una chiave di configurazione, come stringa di
     * cifre, o null (con una riga di log) se la chiave manca o non è un intero
     * positivo.
     */
    private static function limiteDallaConfigurazione(string $chiave): ?string
    {
        $valore = \App\Core\Config::get($chiave);
        if (\is_int($valore) && $valore > 0) {
            return (string)$valore;
        }
        if (\is_string($valore) && ctype_digit($valore) && (int)$valore > 0) {
            return $valore;
        }
        \error_log(sprintf('[limitatore] %s non è un limite valido: vale il limite del ruolo', $chiave));

        return null;
    }

    private const LIMITS = [
        'administrator' => 120,
        'admin'         => 120,
        'teacher'       => 60,
        'student'       => 15,
        'guest'         => 15,
    ];

    public function __construct(
        private readonly RateLimitStore $store = new RateLimitStore(),
    ) {
    }

    /**
     * Phase 25.B5 — supporta override per-route via syntax:
     *   ->middleware('rate:login,10')        // bucket=login, limit=10/min
     *   ->middleware('rate:instances,30')    // bucket=instances, limit=30/min
     *
     * Senza parametri = comportamento legacy (per-role globale, write:*).
     *
     * Bucket suffix è chiave-route (es. "login", "instances"), così endpoint
     * sensibili hanno il loro counter dedicato e NON vengono "protetti" dal
     * counter generico (un teacher che fa 60 mappe non si auto-blocca dalle
     * 30 instances/min).
     */
    /**
     * Questa richiesta entra nel contatore?
     *
     * 22/9/2026 — fino a oggi qui usciva **ogni** richiesta che non fosse
     * POST, PUT, PATCH o DELETE, e dodici rotte GET dichiaravano un secchio
     * senza che il limite operasse. Per la verifica in due passaggi e il
     * recupero password non c'era danno: la GET è il modulo, e la POST
     * corrispondente è limitata. Ma `/accesso-classe/qr/{token}` esiste
     * **solo** come GET, tenta credenziali e ne accetta dodici per richiesta,
     * mentre il commento della rotta prometteva «stesso limite di tentativi
     * della password».
     *
     * Il token è di 43 caratteri, 256 bit: non era un modo per entrare. Era un
     * controllo dichiarato che non opera — e letture al database senza freno.
     *
     * La regola: si conta sempre ciò che scrive; di ciò che legge, solo quello
     * che ha chiesto un secchio esplicito. Limitare ogni lettura non servirebbe
     * e romperebbe la navigazione: un controllo troppo largo è un guasto quanto
     * uno assente.
     */
    public static function daContare(string $metodo, ?string $secchio): bool
    {
        if (\in_array(strtoupper($metodo), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }

        return $secchio !== null && $secchio !== '';
    }

    public function handle(
        Request $req,
        callable $next,
        ?string $bucketKey = null,
        ?string $limitOverride = null,
        ?string $finestraOverride = null,
    ): Response {
        if (!self::daContare($req->method, $bucketKey)) {
            return $next($req);
        }

        // Phase 25.B5 — bypass per test/dev (security.rate_limit_disabled,
        // da RATE_LIMIT_DISABLED=1). Evita i 429 a catena dovuti ai contatori
        // accumulati fra un test e l'altro (non un difetto funzionale).
        // 2026-09-23 — dove vale 1: wiki/environment-variables.md. Con la
        // configurazione a «acceso» il limite si applica anche a una richiesta
        // senza intestazione, cioè a chiunque in produzione:
        // tests/Unit/LimitatoreAccesoTest.php.
        // 2026-09-05 — con il limitatore spento un client può chiederne
        // l'applicazione con `X-Pantedu-Rate-Limit: enforce` (solo in senso
        // restrittivo: nessun header allenta mai il limite). Lo usa la spec
        // e2e del rate limit, che altrimenti non potrebbe girare in locale.
        $enforce = strtolower((string)($req->headers['x-pantedu-rate-limit'] ?? '')) === 'enforce';
        if (!$enforce && (bool)\App\Core\Config::get('security.rate_limit_disabled', false)) {
            return $next($req);
        }

        $user     = Auth::user();
        $username = $user['username'] ?? null;
        $clientIp = $this->clientIp($req) ?? 'unknown';

        // Phase 25.B5 — bucket scoped: bucketKey distingue endpoint sensibili
        // (login/instances/content/etc.) da bucket generico "write:*".
        // Per login (no auth ancora): per-IP. Per altri: per-username.
        $scope = $bucketKey ?? 'write';
        $bucket = $username !== null
            ? "{$scope}:{$username}"
            : "{$scope}:ip:{$clientIp}";

        // 2026-09-23 — `config:<chiave>` al posto del numero: il limite lo
        // dà la configurazione (per esempio `pdf_import.rate.pdf_import`, da
        // PDF_IMPORT_RATE_GENERIC). Prima le rotte scrivevano il numero e la
        // variabile non arrivava mai qui (registro del debito, voce 195). Una
        // chiave che manca o non è un intero positivo vale come nessun
        // override: il limite del ruolo, mai «nessun limite».
        if ($limitOverride !== null && str_starts_with($limitOverride, 'config:')) {
            $limitOverride = self::limiteDallaConfigurazione(substr($limitOverride, 7));
        }

        // Limit: se override esplicito, usa quello. Altrimenti legacy per-role.
        if ($limitOverride !== null && ctype_digit($limitOverride)) {
            $limit = (int)$limitOverride;
        } else {
            $role  = (string)($user['role'] ?? 'guest');
            $limit = self::LIMITS[$role] ?? self::LIMITS['guest'];
        }

        $finestra = self::finestraSecondi($finestraOverride);

        $hits = $this->store->hits($bucket, $finestra);
        if (\count($hits) >= $limit) {
            $oldest = (int)$hits[0];
            $retry  = \max(1, ($oldest + $finestra) - \time());
            return Response::json([
                'error'       => 'rate limit exceeded',
                'retry_after' => $retry,
                'bucket'      => $scope,
                'limit'       => $limit,
            ], 429);
        }

        $this->store->append($bucket, $clientIp);
        return $next($req);
    }

    /**
     * IP client per il bucket rate-limit. Audit Phase 25.R.31 (HIGH): prima
     * usava HTTP_X_FORWARDED_FOR/HTTP_CLIENT_IP GREZZI → un client poteva
     * iniettare un valore arbitrario e ruotare il bucket all'infinito,
     * annullando la difesa anti-brute-force su /login. Ora valida ogni hop
     * (FILTER_VALIDATE_IP). Audit 2026-06-01: delega a EdgeContext, che si
     * fida degli header di forwarding SOLO se la connessione arriva da un proxy
     * fidato (range Cloudflare). Dietro Cloudflare questo è essenziale: senza,
     * REMOTE_ADDR sarebbe l'IP di Cloudflare e il bucket login risulterebbe
     * GLOBALE (tutti gli utenti condividono pochi IP CF) anziché per-utente.
     */
    private function clientIp(Request $req): ?string
    {
        $ip = \App\Services\Waf\EdgeContext::clientIp($req->server ?? []);
        return ($ip !== '' && $ip !== '0.0.0.0') ? $ip : null;
    }
}
