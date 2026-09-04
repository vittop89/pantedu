<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use PDO;
use Throwable;

/**
 * Registro append-only delle operazioni, per tutti i ruoli.
 *
 * Nasce dal buco descritto nella migration 098: le azioni di uno studente non
 * finivano in nessuna tabella, e quelle di docenti e admin vivevano in un file
 * JSON troncato a mille voci. Qui ci finisce cio' che e' un'operazione — le
 * scritture, i tentativi negati, gli eventi di dominio — e non la navigazione,
 * che resta in access_log.json.
 *
 * USO
 *   ActivityLogger::request($req, $res);                  // dal middleware
 *   ActivityLogger::event('parent_consent_granted',       // evento di dominio
 *       subjectType: 'user', subjectId: (string)$studentId,
 *       details: ['parent_email_domain' => 'esempio.it']);
 *
 * Gli errori non si propagano mai: un log che fallisce non deve far fallire
 * l'operazione che sta descrivendo. Ma non si perdono in silenzio — vanno in
 * error_log, perche' un audit che smette di scrivere e' esso stesso un
 * incidente.
 */
final class ActivityLogger
{
    /** Metodi che non sono un'operazione: la lettura resta fuori dal registro. */
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * POST che non sono operazioni.
     *
     * Sono richieste che il browser manda da solo, molte al minuto, e che non
     * cambiano nulla di conservato: telemetria e chiamate di calcolo. Se
     * entrassero, in un pomeriggio di lavoro all'editor coprirebbero le righe
     * che contano — e un registro che non si riesce a leggere non e' un
     * registro. Il traffico bloccato dal WAF ha gia' `waf_logs`; la
     * navigazione ha `access_log.json`.
     */
    private const NON_OPERATIONS = [
        '/analytics/nav',    // beacon di navigazione SPA
        '/api/vitals',       // Web Vitals RUM
        '/waf/fingerprint',  // raccolta impronta WAF
        '/tikz/render',      // rendering TikZ→SVG, nessuno stato scritto
        '/tex/format',       // formattazione LaTeX, nessuno stato scritto
    ];

    /** Rifiuti di accesso: 401 non autenticato, 403 vietato, 429 troppo insistente. */
    private const DENIAL_STATUSES = [401, 403, 429];

    /**
     * True se questa richiesta va registrata.
     *
     * Tre casi, in ordine:
     *
     *   1. e' una scrittura → sempre, e' un'operazione;
     *   2. e' una lettura respinta su risorsa protetta (401/403/429) → sempre,
     *      anche da anonimo: e' il tentativo che un audit serve a trovare;
     *   3. e' un'altra lettura fallita (404, 500) → solo se chi la fa e'
     *      autenticato. Un anonimo che colleziona 404 e' uno scanner, ne
     *      produce migliaia, e per quello c'e' gia' `waf_logs`; un utente
     *      autenticato che sbatte su un 404 e' invece un fatto da poter
     *      ricostruire.
     *
     * `$path` e `$authenticated` sono opzionali per non rompere la firma, ma
     * senza di loro il filtro e' piu' largo.
     */
    public static function shouldLogRequest(
        string $method,
        int $status,
        string $path = '',
        bool $authenticated = true,
    ): bool {
        if ($path !== '' && self::isNonOperation($path)) {
            return false;
        }
        if (!\in_array(strtoupper($method), self::READ_METHODS, true)) {
            return true;
        }
        if (\in_array($status, self::DENIAL_STATUSES, true)) {
            return true;
        }
        return $status >= 400 && $authenticated;
    }

    private static function isNonOperation(string $path): bool
    {
        // Via la query string: /api/vitals?x=1 e' comunque un beacon.
        $clean = strtok($path, '?') ?: $path;
        foreach (self::NON_OPERATIONS as $prefix) {
            if ($clean === $prefix || str_starts_with($clean, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Registra una richiesta HTTP. Chiamato da AccessLogMiddleware dopo che
     * la risposta e' stata prodotta, cosi' lo status e' quello vero.
     */
    public static function request(string $method, string $path, int $status): void
    {
        self::write(
            action:      'http_request',
            method:      $method,
            path:        $path,
            status:      $status,
            outcome:     self::outcomeFor($status),
        );
    }

    /**
     * Registra un evento di dominio: qualcosa che il solo metodo+path non
     * descrive (un consenso concesso, una registrazione approvata).
     *
     * @param array<string,mixed>|null $details
     */
    public static function event(
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?array $details = null,
        string $outcome = 'ok',
        ?int $actorUserId = null,
        ?string $actorName = null,
        ?string $actorRole = null,
    ): void {
        self::write(
            action:      $action,
            method:      $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            path:        self::currentPath(),
            status:      null,
            outcome:     $outcome,
            subjectType: $subjectType,
            subjectId:   $subjectId,
            details:     $details,
            actorUserId: $actorUserId,
            actorName:   $actorName,
            actorRole:   $actorRole,
        );
    }

    /** @param array<string,mixed>|null $details */
    private static function write(
        string $action,
        string $method,
        string $path,
        ?int $status,
        string $outcome,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?array $details = null,
        ?int $actorUserId = null,
        ?string $actorName = null,
        ?string $actorRole = null,
    ): void {
        if (!Config::get('database.enabled')) {
            return;
        }

        try {
            [$uid, $name, $role] = self::actor();
            $stmt = Database::connection()->prepare(
                'INSERT INTO audit_activity_log
                    (actor_user_id, actor_name, actor_role, action, method, path,
                     status, outcome, subject_type, subject_id, details_json,
                     ip_hash, ua_hash, request_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $actorUserId ?? $uid,
                $actorName   ?? $name,
                $actorRole   ?? $role,
                $action,
                strtoupper(substr($method, 0, 10)),
                substr($path, 0, 512),
                $status,
                $outcome,
                $subjectType,
                $subjectId !== null ? substr($subjectId, 0, 128) : null,
                $details !== null
                    ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                RequestFingerprint::ipHash(),
                // Anche lo User-Agent come hash (2026-09-03): prima restava in
                // chiaro, e l'informativa diceva il contrario.
                RequestFingerprint::uaHash(),
                self::requestId(),
            ]);
        } catch (Throwable $e) {
            // Un audit che smette di scrivere e' un incidente: si annota
            // dove qualcuno lo leggera', invece di sparire in un catch vuoto.
            \error_log('[ActivityLogger] insert fallita (' . $action . '): ' . $e->getMessage());
        }
    }

    /**
     * Attore corrente: id, nome, ruolo *effettivo*. actorRole() e non role(),
     * altrimenti un super-admin risulta 'teacher' — che e' esattamente
     * l'errore che rendeva inutile il vecchio access log.
     *
     * @return array{0: ?int, 1: string, 2: string}
     */
    private static function actor(): array
    {
        try {
            $u = Auth::user();
            if (!$u) {
                return [null, 'anonymous', 'guest'];
            }
            return [
                isset($u['id']) ? (int)$u['id'] : null,
                (string)($u['username'] ?? 'unknown'),
                Auth::actorRole(),
            ];
        } catch (Throwable) {
            return [null, 'unknown', 'guest'];
        }
    }

    // IP e User-Agent come hash: la logica sta in RequestFingerprint, unica
    // per tutti i registri (2026-09-03). Prima era qui, e in tre altre copie
    // che non hashavano.

    /** Correlazione con i log applicativi (RequestIdMiddleware). */
    private static function requestId(): ?string
    {
        $rid = $_SERVER['X_REQUEST_ID'] ?? null;
        return \is_string($rid) && $rid !== '' ? substr($rid, 0, 64) : null;
    }

    private static function currentPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return \is_string($uri) ? substr($uri, 0, 512) : '';
    }

    private static function outcomeFor(int $status): string
    {
        if ($status === 401 || $status === 403 || $status === 429) {
            return 'denied';
        }
        return $status >= 400 ? 'error' : 'ok';
    }

    /**
     * Ultimi N eventi, per il pannello admin.
     *
     * @return list<array<string,mixed>>
     */
    public static function recent(int $limit = 100): array
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT id, occurred_at, actor_user_id, actor_name, actor_role,
                        action, method, path, status, outcome,
                        subject_type, subject_id, details_json
                 FROM audit_activity_log
                 ORDER BY occurred_at DESC, id DESC
                 LIMIT ?'
            );
            $stmt->execute([max(1, min(1000, $limit))]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}
