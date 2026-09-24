<?php

namespace App\Core;

use Throwable;

final class Kernel
{
    /**
     * Gli alias dei middleware di rotta, con la loro classe.
     *
     * 23/9/2026 — un alias che non sta qui fa fallire la rotta (500, e la
     * riga `[KERNEL]` con il nome sbagliato) invece di sparire: fino a oggi
     * `buildPipeline` lo saltava, e un refuso come `'atuh'` in routes/web.php
     * avrebbe tolto la porta a un gruppo di rotte senza nessun segnale. Il
     * refuso lo ferma prima la prova `AliasDeiMiddlewareTest`, che legge
     * routes/web.php con il Router vero e risolve ogni alias; la stessa prova
     * vuole che ogni alias di qui serva ad almeno una rotta (revisione
     * architetturale 2026-09, A-40).
     *
     * @var array<string, class-string>
     */
    public const MIDDLEWARE = [
        'auth'        => \App\Middleware\AuthMiddleware::class,
        'role'        => \App\Middleware\RoleMiddleware::class,
        // 14/9/2026 — lo studio: studente con account, oppure ospite con la
        // credenziale di classe (vedi StudioMiddleware e il gruppo in routes/web.php).
        'studio'      => \App\Middleware\StudioMiddleware::class,
        'csrf'        => \App\Middleware\CsrfMiddleware::class,
        'log'         => \App\Middleware\AccessLogMiddleware::class,
        'rate'        => \App\Middleware\RateLimitMiddleware::class,
        'legacy_gone'    => \App\Middleware\LegacyGoneMiddleware::class,
        'sadmin_audit'   => \App\Middleware\SuperAdminAuditMiddleware::class,
        // Phase 25.B4 — audit reason obbligatoria su mutazioni admin
        'audit_reason'   => \App\Middleware\RequiresAuditReasonMiddleware::class,
        // G22.S26 — gate super-admin centralizzato (evita check inline ripetuti)
        'super_admin_required' => \App\Middleware\SuperAdminRequiredMiddleware::class,
        // Primo accesso del docente: se non ha ancora dichiarato le materie lo
        // manda a sceglierle. Mirato al gruppo role:teacher, non globale.
        'teacher_subjects' => \App\Middleware\TeacherSubjectsMiddleware::class,
        // Non hanno alias, perche' non si applicano per rotta ma a ogni
        // richiesta da handle(): SecurityHeadersMiddleware, TosAcceptanceMiddleware
        // e la generazione dell'X-Request-ID. Gli alias 'sec_headers',
        // 'request_id', 'tos' e 'tenant' esistevano senza che nessuna rotta li
        // usasse; 'tenant' era un middleware mai attaccato (il cambio di
        // istituto passa da TenantController). Tolti il 2026-09-04, revisione
        // architetturale 2026-09, rilievo A15.
        //
        // 23/9/2026 — tolto anche 'waf' (A-40): il WAF lo applica handle() a
        // ogni richiesta, prima del match della rotta. Nessuna rotta lo usava,
        // e una che lo avesse usato l'avrebbe fatto girare due volte.
    ];

    /**
     * La mappa usata da `buildPipeline`: all'inizio è `MIDDLEWARE`. È una
     * proprietà perché le prove ci aggiungono un middleware finto
     * (KernelEccezioniTest).
     *
     * @var array<string, class-string>
     */
    private array $middlewareMap = self::MIDDLEWARE;

    /** Oltre questi passi la traccia nel registro si tronca: una ricorsione non deve fare una riga da megabyte. */
    private const PASSI_NELLA_TRACCIA = 40;

    /**
     * I due cancelli globali, come `fn(Request, callable $next): Response`.
     *
     * @var \Closure(Request, callable): Response
     */
    private \Closure $waf;

    /** @var \Closure(Request, callable): Response */
    private \Closure $tos;

    /**
     * `$waf` e `$tos` servono alle prove (23/9/2026, KernelEccezioniTest): un
     * WAF che si guasta prima di decidere non si costruisce con la classe
     * vera, e il gate ToS vero legge il database. In produzione restano
     * WafMiddleware e TosAcceptanceMiddleware, creati a ogni richiesta come
     * prima.
     *
     * @param null|\Closure(Request, callable): Response $waf
     * @param null|\Closure(Request, callable): Response $tos
     */
    public function __construct(private Router $router, ?\Closure $waf = null, ?\Closure $tos = null)
    {
        $this->waf = $waf ?? static fn(Request $r, callable $next): Response
            => (new \App\Middleware\WafMiddleware())->handle($r, $next);
        $this->tos = $tos ?? static fn(Request $r, callable $next): Response
            => (new \App\Middleware\TosAcceptanceMiddleware())->handle($r, $next);
    }

    public function handle(Request $req): Response
    {
        // Phase 25.E4 — Request ID correlation: imposta X-Request-ID prima
        // di TUTTO (log rotation incluso) per avere correlazione anche su
        // errori early-stage. La logica vive in RequestIdMiddleware, che
        // qui non e' nella pipeline: era duplicata inline (2026-09-04).
        \App\Middleware\RequestIdMiddleware::ensure($req);

        // 23/9/2026 (revisione architetturale A-16) — il nonce della CSP nasce
        // qui, prima della pipeline: le viste lo scrivono negli script
        // dell'applicazione mentre la rendono, e SecurityHeadersMiddleware,
        // che gira dopo, mette lo stesso nell'intestazione. Prima lo generava
        // il middleware a risposta finita e lo timbrava su ogni <script>.
        \App\Support\Csp::nuovaRichiesta();

        // Il preload del catalogo curriculum (G22.S20 v2.C2) non sta piu' qui:
        // CurriculumLookup lo fa alla prima lettura (revisione 2026-09, P12),
        // cosi' le richieste che non toccano il curriculum non pagano la query.

        // Phase 19-20 — in-process log rotation throttled (1h sentinel).
        // Copre storage/logs/ + log/errors/ (legacy). Fail-safe.
        try {
            // La radice dei DATI: i registri stanno lì, non nell'immagine.
            $base = \App\Support\PercorsiDati::base(\dirname(__DIR__, 2));
            \App\Services\LogRotator::maybeRotateAll([
                (string)Config::get('app.paths.logs', $base . '/storage/logs'),
                $base . '/log/errors',
            ]);
        } catch (Throwable) {
/* best-effort */
        }

        try {
            // Phase 25.C — WAF middleware applicato globalmente (gate by DB flag).
            // Wrap del pipeline router così la decisione WAF avviene PRIMA del match.
            // WafMiddleware fa early-exit se waf_config.enabled=0 (zero overhead).
            $execute = function (Request $r): Response {
                $route = $this->router->match($r);
                if (!$route) {
                    return Response::html($this->errorPage(
                        404,
                        'Not Found',
                        'La pagina richiesta non esiste.',
                        '🔎'
                    ), 404);
                }
                $pipeline = $this->buildPipeline($route, $r);
                return $pipeline($r);
            };
            // Phase 25.P — gate ToS/AUP applicato globalmente, come il WAF.
            // Deve avvolgere il match della route: appenderlo a ogni rotta
            // significherebbe dimenticarlo sulla prossima che si aggiunge.
            // Early-exit a costo zero se TOS_ENFORCE=false.
            $execute = function (Request $r) use ($execute): Response {
                return ($this->tos)($r, $execute);
            };
            // WAF fail-safe: se il WAF si guasta PRIMA di lasciar passare la
            // richiesta, la si serve senza filtro (fail-open, invariante).
            //
            // 23/9/2026 — solo prima. Questo catch avvolgeva anche la
            // pipeline che il WAF chiama: un'eccezione del controller finiva
            // qui, veniva scartata senza lasciare traccia e il controller
            // girava una seconda volta (quattro con il gate ToS attivo, che
            // aveva lo stesso schema). Gli effetti già prodotti — scritture,
            // posta, righe di audit, colpi del limitatore — si ripetevano, e
            // nel registro finiva l'errore della seconda esecuzione, non
            // della prima (revisione architetturale 2026-09, A-1). Adesso
            // `$partita` dice se la pipeline è partita: se sì l'eccezione è
            // sua, e va al gestore degli errori qui sotto, una volta sola.
            $partita = false;
            $avvia = function (Request $r) use ($execute, &$partita): Response {
                $partita = true;
                return $execute($r);
            };
            try {
                $resp = ($this->waf)($req, $avvia);
            } catch (Throwable $e) {
                if ($partita) {
                    throw $e;
                }
                error_log('[KERNEL] WAF guasto prima della decisione, la richiesta passa senza filtro: '
                    . self::perIlRegistro($e));
                $resp = $execute($req);
            }
            $this->logActivity($req, $resp);
            return $this->applySecurityHeaders($req, $resp);
        } catch (Throwable $e) {
            // Un corpo che non si può usare (troppo grande, vuoto, non JSON) è
            // un errore del client, non del server: la risposta la porta
            // l'eccezione, 413 o 400, in JSON (A-52, 23/9/2026). Il resto è 500.
            $resp = $e instanceof CorpoNonValido
                ? Response::fail($e->getMessage(), $e->stato())
                : $this->renderError($e);
            $this->logActivity($req, $resp);
            return $this->applySecurityHeaders($req, $resp);
        }
    }

    /**
     * Registro delle operazioni, applicato globalmente.
     *
     * Sta qui e non in un middleware di rotta per lo stesso motivo del gate
     * ToS e dei security header: appenderlo route per route significa
     * dimenticarlo sulla prossima. Cosi' copre anche cio' che una rotta non
     * ha (il 404 di una URL inesistente) e cio' che oggi non ha 'log'
     * (login, registrazione, conferma del consenso genitoriale).
     *
     * Filtra: solo scritture e tentativi non riusciti. Vedi migration 098.
     */
    private function logActivity(Request $req, Response $resp): void
    {
        try {
            $path = (string)($req->server['REQUEST_URI'] ?? $req->path);
            $auth = Auth::check();
            if (\App\Services\Audit\ActivityLogger::shouldLogRequest($req->method, $resp->status, $path, $auth)) {
                \App\Services\Audit\ActivityLogger::request($req->method, $path, $resp->status);
            }
        } catch (Throwable) {
/* il registro non deve mai far cadere la risposta */
        }
    }

    /**
     * Phase 25.B6 — security headers applicati GLOBALMENTE su ogni response,
     * indipendentemente dalla route. Approccio centralized invece di
     * ->middleware('sec_headers') sparso su 100+ route.
     *
     * Skippabile per asset statici (file response, performance) — i security
     * headers sono comunque settati dal webserver (.htaccess) per static files.
     */
    private function applySecurityHeaders(Request $req, Response $response): Response
    {
        if (isset($response->headers['X-Serve-File'])) {
            // Static file response: header settati dal webserver, skip.
            return $response;
        }
        // Phase 25.E4 — echo-back X-Request-ID per debug client
        $rid = $_SERVER['X_REQUEST_ID'] ?? null;
        if ($rid) {
            $response->headers['X-Request-ID'] = $rid;
        }

        return (new \App\Middleware\SecurityHeadersMiddleware())->handle($req, fn() => $response);
    }

    private function buildPipeline(Route $route, Request $req): callable
    {
        $handler = fn(Request $r) => $this->invoke($route, $r);

        $middleware = array_reverse($route->middleware);
        foreach ($middleware as $name) {
            [$class, $args] = self::risolvi($this->middlewareMap, $name);

            $next    = $handler;
            $handler = fn(Request $r) => (new $class())->handle($r, $next, ...$args);
        }

        return $handler;
    }

    /**
     * La classe e gli argomenti di una voce di middleware di rotta
     * (`'rate:compile,15'` → RateLimitMiddleware, `['compile', '15']`).
     *
     * Lancia se l'alias non esiste. È pubblica per la prova che risolve ogni
     * voce di routes/web.php (AliasDeiMiddlewareTest): la regola è una sola,
     * quella che usa `buildPipeline`.
     *
     * @return array{0: class-string, 1: list<string>}
     * @throws \LogicException se l'alias non è fra i MIDDLEWARE
     */
    public static function middlewareDellaVoce(string $voce): array
    {
        return self::risolvi(self::MIDDLEWARE, $voce);
    }

    /**
     * @param array<string, class-string> $mappa
     * @return array{0: class-string, 1: list<string>}
     */
    private static function risolvi(array $mappa, string $voce): array
    {
        $alias = $voce;
        $args  = [];
        if (str_contains($voce, ':')) {
            [$alias, $arg] = explode(':', $voce, 2);
            $args = explode(',', $arg);
        }
        $classe = $mappa[$alias] ?? null;
        if ($classe === null) {
            throw new \LogicException(sprintf(
                'Middleware di rotta sconosciuto «%s»: la rotta non si serve senza la protezione che chiede '
                . '(alias ammessi in Kernel::MIDDLEWARE).',
                $alias
            ));
        }
        return [$classe, $args];
    }

    private function invoke(Route $route, Request $req): Response
    {
        $h = $route->handler;

        if (is_string($h)) {
            return Response::file($h);
        }
        if (is_callable($h)) {
            $result = $h($req, $route->params);
            return $result instanceof Response ? $result : Response::html((string)$result);
        }
        if (is_array($h) && count($h) === 2) {
            [$class, $method] = $h;
            $result = (new $class())->{$method}($req, $route->params);
            return $result instanceof Response ? $result : Response::html((string)$result);
        }
        return Response::html('<h1>500 Invalid handler</h1>', 500);
    }

    /**
     * L'eccezione per `error_log`, in una riga sola: classe, messaggio,
     * file:riga e la traccia, passo per passo, con file, riga e funzione.
     *
     * Senza gli argomenti, di proposito (23/9/2026, revisione architetturale
     * 2026-09, A-1). `getTraceAsString()` li scrive: nel container
     * `zend.exception_ignore_args` vale Off (docker/php.ini non la imposta),
     * quindi le password, gli indirizzi di posta e i testi passati alle
     * funzioni finirebbero nel registro, troncati a quindici caratteri ma
     * leggibili. Il registro non li deve avere, qualunque sia l'impostazione:
     * per questo la traccia si costruisce da `getTrace()` e non si scrivono
     * mai le chiavi `args`. Il messaggio dell'eccezione resta, come prima.
     *
     * Una riga sola perché il registro si legge per righe (grep,
     * /admin/debug-log con `lines=`): una traccia su più righe si
     * spezzerebbe dalla sua eccezione.
     */
    private static function perIlRegistro(Throwable $e): string
    {
        $passi = [];
        foreach (\array_slice($e->getTrace(), 0, self::PASSI_NELLA_TRACCIA) as $i => $passo) {
            $dove = isset($passo['file']) ? $passo['file'] . '(' . ($passo['line'] ?? '?') . ')' : '[interno]';
            $passi[] = '#' . $i . ' ' . $dove . ': '
                . ($passo['class'] ?? '') . ($passo['type'] ?? '') . $passo['function'] . '()';
        }
        $oltre = \count($e->getTrace()) - self::PASSI_NELLA_TRACCIA;
        if ($oltre > 0) {
            $passi[] = '… altri ' . $oltre . ' passi';
        }

        // Anche il messaggio in una riga: un errore di sintassi di MariaDB
        // riporta il pezzo di query dove si ferma, a capo compresi.
        $messaggio = str_replace(["\r\n", "\r", "\n"], ' ', $e->getMessage());

        $riga = $e::class . ': ' . $messaggio . ' @ ' . $e->getFile() . ':' . $e->getLine()
            . ($passi === [] ? '' : ' | traccia: ' . implode(' ; ', $passi));

        // error_log() scrive fino al primo byte NUL, e il nome di una classe
        // anonima ne contiene uno: senza questa sostituzione la riga si
        // fermerebbe lì, messaggio e traccia compresi (23/9/2026).
        return str_replace("\0", '\\0', $riga);
    }

    private function renderError(Throwable $e): Response
    {
        error_log('[KERNEL] ' . self::perIlRegistro($e));
        if (Config::get('app.debug')) {
            $extra = '<pre style="text-align:left;background:#f3f4f7;padding:1rem;border-radius:4px;'
                   . 'overflow:auto;max-height:50vh;font-size:.8rem">'
                   . e($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
            return Response::html($this->errorPage(
                500,
                'Internal Server Error',
                'Si è verificato un errore interno.',
                '💥',
                $extra
            ), 500);
        }
        return Response::html($this->errorPage(
            500,
            'Internal Server Error',
            'Si è verificato un errore interno. Riprova più tardi.',
            '💥'
        ), 500);
    }

    private function errorPage(int $code, string $title, string $message, string $icon, string $extraHtml = ''): string
    {
        $view = View::default();
        $body = $view->render('errors/generic', [
            'code'      => $code,
            'title'     => $title,
            'message'   => $message,
            'icon'      => $icon,
            'extraHtml' => $extraHtml,
        ]);
        return $view->render('layout/shell', [
            'title' => "$code — $title",
            'body'  => $body,
        ]);
    }
}
