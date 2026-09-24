<?php

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Config;
use App\Core\PrivilegedAccessLogger;
use App\Core\Request;
use App\Core\Response;
use App\Services\AclPolicy;

/**
 * Phase 25.B4 — Middleware "audit reason required" per operazioni admin
 * che mutano dati cross-teacher.
 *
 * Comportamento:
 *   - Verifica presenza di una `audit_reason` come:
 *       - header HTTP `X-Audit-Reason: ...`
 *       - body POST/PUT field `_audit_reason: ...`
 *   - Min 10 caratteri (no "test", "ok", "...") per forzare motivazione
 *     significativa.
 *   - Logga in `privileged_access_log` con reason effettiva (non hardcoded
 *     come `SuperAdminAuditMiddleware`).
 *
 * Modalità (Config `audit.reason_mode` o env `AUDIT_REASON_MODE`):
 *   - `enforce`  → 400 se reason mancante/breve (predefinito dal 23/9/2026;
 *                  in produzione dal 2/9/2026)
 *   - `warn`     → log warning + lascia passare (il modo del rollout)
 *   - `disabled` → middleware no-op (per fase migrazione client legacy)
 *
 * Dove si applica (23/9/2026, A-69): a ogni mutazione amministrativa —
 * POST, PUT, PATCH, DELETE sotto /admin e /api/admin, con
 * `super_admin_required` o con la sola zona `admin` — come
 * `audit_reason:<azione>,<risorsa>`, salvo le esenzioni scritte (rotte che
 * non scrivono, o che toccano solo i temporanei). Lo sorveglia
 * tests/Unit/Core/CoperturaMotivazioneTest.php; che i chiamanti la mandino,
 * ChiamantiMandanoLaMotivazioneTest. Fino a quel giorno questo commento
 * diceva «tutti gli endpoint /api/admin/** POST/DELETE», e 50 mutazioni su
 * 81 sotto /admin e /api/admin non la chiedevano.
 *
 * Agisce solo per il super-admin (vedi handle()): l'amministratore di
 * istituto e il docente passano senza motivazione.
 *
 * NB: SuperAdminAuditMiddleware registra le sole LETTURE del super-admin,
 * con la motivazione fissa `super_admin_read`. Questo middleware è
 * complementare: sulle mutazioni pretende la motivazione di chi agisce.
 */
final class RequiresAuditReasonMiddleware
{
    public const MODE_ENFORCE  = 'enforce';
    public const MODE_WARN     = 'warn';
    public const MODE_DISABLED = 'disabled';

    private const MIN_REASON_LENGTH = 10;
    private const MAX_REASON_LENGTH = 255;

    public function handle(Request $req, callable $next, string $action = 'admin_mutation', string $resourceType = 'generic'): Response
    {
        // Skippa per non-super-admin: gating role gestito altrove (RoleMiddleware).
        if (!Auth::check() || !AclPolicy::isSuperAdmin()) {
            return $next($req);
        }

        $mode = $this->mode();
        if ($mode === self::MODE_DISABLED) {
            return $next($req);
        }

        $reason = $this->extractReason($req);
        // In caratteri, non in byte (23/9/2026): «è» sono due byte in UTF-8,
        // e la colonna `reason` è VARCHAR(255) in caratteri.
        $valid = $reason !== null
            && mb_strlen($reason) >= self::MIN_REASON_LENGTH
            && mb_strlen($reason) <= self::MAX_REASON_LENGTH;

        if (!$valid) {
            // Sempre log (anche in warn mode) per audit trail completo.
            PrivilegedAccessLogger::log(
                action:       $action,
                resourceType: $resourceType,
                resourceId:   $req->path ?? null,
                reason:       'MISSING_OR_INVALID_AUDIT_REASON',
                outcome:      $mode === self::MODE_ENFORCE ? 'denied' : 'warn',
            );
            if ($mode === self::MODE_ENFORCE) {
                return Response::json([
                    'error'   => 'audit_reason_required',
                    'message' => sprintf(
                        'Provide X-Audit-Reason header or _audit_reason field (%d-%d chars).',
                        self::MIN_REASON_LENGTH,
                        self::MAX_REASON_LENGTH
                    ),
                ], 400);
            }
            // warn mode: passa, ma con audit log
            return $next($req);
        }

        // Reason valida: log + propaga al PrivilegedAccessLogger
        PrivilegedAccessLogger::log(
            action:       $action,
            resourceType: $resourceType,
            resourceId:   $req->path ?? null,
            reason:       $reason,
            outcome:      'ok',
        );
        return $next($req);
    }

    /**
     * Modalità da app/Config/audit.php (`reason_mode`, che legge
     * AUDIT_REASON_MODE dal .env e in ripiego dall'ambiente del processo).
     *
     * 2026-09-23 — senza un valore, o con uno sconosciuto, vale `enforce`,
     * come nella configurazione (A-37 della revisione del 23/9). Il ripiego
     * era `warn`, il modo del rollout finito il 2/9/2026: una configurazione
     * che non arrivava, per esempio in una prova o in uno strumento che
     * carica il middleware senza la configurazione, lasciava passare le
     * mutazioni senza motivazione.
     */
    private function mode(): string
    {
        $mode = strtolower((string)(Config::get('audit.reason_mode') ?: self::MODE_ENFORCE));
        return in_array($mode, [self::MODE_ENFORCE, self::MODE_WARN, self::MODE_DISABLED], true)
            ? $mode
            : self::MODE_ENFORCE;
    }

    /**
     * Estrae reason da header HTTP o body POST/PUT. Trim + sanitize.
     *
     * 2026-09-23 — un'intestazione il browser la scrive in ISO-8859-1, un
     * byte per carattere: la «à» di una motivazione arriva come 0xE0, che
     * non è UTF-8. Passata così al registro, MariaDB rifiutava la riga e il
     * ripiego su file (`json_encode`) ne scriveva una vuota: la motivazione
     * si perdeva senza errori. Se i byte non sono UTF-8 valido si leggono
     * come ISO-8859-1; se lo sono (curl, uno script) restano come sono.
     */
    private function extractReason(Request $req): ?string
    {
        // Header X-Audit-Reason (preferred)
        $headers = $req->server ?? [];
        $headerKey = 'HTTP_X_AUDIT_REASON';
        if (isset($headers[$headerKey]) && is_string($headers[$headerKey])) {
            $r = trim($headers[$headerKey]);
            if (!mb_check_encoding($r, 'UTF-8')) {
                $r = (string)mb_convert_encoding($r, 'UTF-8', 'ISO-8859-1');
            }
            if ($r !== '') {
                return $r;
            }
        }

        // Body field _audit_reason (per form submission legacy)
        if (isset($req->post['_audit_reason']) && is_string($req->post['_audit_reason'])) {
            $r = trim($req->post['_audit_reason']);
            if ($r !== '') {
                return $r;
            }
        }

        return null;
    }
}
