<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use RuntimeException;

/**
 * Toggle runtime dell'obbligo di verifica in due passaggi.
 *
 * PERCHE' (2026-09-01)
 *
 * L'obbligo si governava con due variabili d'ambiente — `SECURITY_TOTP_ENABLED`
 * e `SECURITY_TOTP_REQUIRED_ROLES` — che per accendere richiedevano accesso al
 * filesystem del VPS, un riavvio di php-fpm e nessuna traccia di chi avesse
 * deciso. Per un controllo che puo' impedire l'accesso al servizio a un intero
 * ruolo, l'ultimo punto e' quello che pesa. Stessa storia, e stessa soluzione,
 * del gate ToS/AUP: override runtime su file JSON, env come default iniziale,
 * decisione a registro.
 *
 * TRE STATI, NON DUE
 *
 * Un booleano non bastava a esprimere quello che serve davvero decidere:
 *
 *   off     — nessun obbligo. Chi vuole attiva comunque la 2FA dal proprio
 *             profilo, e in tal caso gli viene chiesta al login: spegnere
 *             l'obbligo non toglie a nessuno una protezione che ha scelto.
 *   admins  — obbligatoria per amministratori e super-admin. E' il primo
 *             scalino sensato: sono gli account il cui furto costa di piu'.
 *   all     — obbligatoria anche per i docenti.
 *
 * Alzare lo scalino non chiude nessuno fuori all'istante: chi rientra nei ruoli
 * obbligati viene accompagnato alla pagina d'iscrizione e puo' completarla
 * subito (AuthMiddleware, ENROL2FA_ALLOWLIST).
 */
final class TwoFactorEnforcement
{
    public const MODE_OFF    = 'off';
    public const MODE_ADMINS = 'admins';
    public const MODE_ALL    = 'all';

    /** @var array<string, list<string>> ruoli obbligati per modalita' */
    private const ROLES = [
        self::MODE_OFF    => [],
        self::MODE_ADMINS => ['super_admin', 'administrator'],
        self::MODE_ALL    => ['super_admin', 'administrator', 'teacher'],
    ];

    /** @var array<string,mixed>|null Cache per-request dell'override. */
    private static ?array $runtimeCache = null;

    /** Modalita' corrente: off | admins | all. */
    public static function mode(): string
    {
        $runtime = self::loadRuntime();
        if ($runtime !== null && isset($runtime['mode']) && isset(self::ROLES[$runtime['mode']])) {
            return (string) $runtime['mode'];
        }

        // Nessun override: si deduce dall'env, che resta il default iniziale.
        $enabled = filter_var(
            Config::get('security.totp_enabled', false),
            FILTER_VALIDATE_BOOLEAN
        );
        if (!$enabled) {
            return self::MODE_OFF;
        }
        $roles = Config::get('security.totp_required_roles', []);
        $roles = is_array($roles) ? $roles : [];
        if (in_array('teacher', $roles, true)) {
            return self::MODE_ALL;
        }
        return $roles === [] ? self::MODE_OFF : self::MODE_ADMINS;
    }

    /** @return list<string> ruoli per cui la 2FA e' obbligatoria adesso */
    public static function requiredRoles(): array
    {
        return self::ROLES[self::mode()] ?? [];
    }

    public static function isRequiredFor(string $role): bool
    {
        return in_array($role, self::requiredRoles(), true);
    }

    /**
     * Stato corrente per il pannello admin.
     *
     * @return array{mode:string, roles:list<string>, source:string, updated_at:?string, updated_by:?string}
     */
    public static function snapshot(): array
    {
        $runtime = self::loadRuntime();
        return [
            'mode'       => self::mode(),
            'roles'      => self::requiredRoles(),
            'source'     => $runtime === null ? 'env' : 'runtime_override',
            'updated_at' => $runtime['updated_at'] ?? null,
            'updated_by' => $runtime['updated_by'] ?? null,
        ];
    }

    /**
     * Scrive l'override runtime. Atomico (tmp + rename): una richiesta
     * concorrente legge il file vecchio o quello nuovo, mai un JSON troncato —
     * che qui significherebbe obbligo spento per errore.
     */
    public static function persistRuntime(string $mode, string $actor, string $reason): void
    {
        if (!isset(self::ROLES[$mode])) {
            throw new RuntimeException('invalid_mode');
        }
        $path = self::runtimePath();
        $dir  = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot_create_config_dir');
        }
        $json = json_encode([
            'mode'       => $mode,
            'updated_at' => date('c'),
            'updated_by' => $actor,
            'reason'     => $reason,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('cannot_encode_config');
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('cannot_write_config');
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('cannot_rename_config');
        }
        @chmod($path, 0640);
        self::$runtimeCache = null;
    }

    /** Rimuove l'override: torna a valere l'env. */
    public static function clearRuntime(): bool
    {
        $path = self::runtimePath();
        self::$runtimeCache = null;
        return !is_file($path) || @unlink($path);
    }

    /** @return array<string,mixed>|null */
    private static function loadRuntime(): ?array
    {
        if (self::$runtimeCache !== null) {
            return self::$runtimeCache['_loaded'] === false ? null : self::$runtimeCache;
        }
        $path = self::runtimePath();
        if (!is_file($path)) {
            self::$runtimeCache = ['_loaded' => false];
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['mode'])) {
            // File corrotto: si ricade sull'env invece di indovinare. Un
            // obbligo d'accesso non deve accendersi per un JSON malformato.
            error_log('[TwoFactorEnforcement] override illeggibile, fallback su env: ' . $path);
            self::$runtimeCache = ['_loaded' => false];
            return null;
        }
        $data['_loaded'] = true;
        self::$runtimeCache = $data;
        return $data;
    }

    private static function runtimePath(): string
    {
        return (string) Config::get('app.paths.storage') . '/config/twofactor_enforcement.json';
    }

    /** Reset cache (test). */
    public static function resetCache(): void
    {
        self::$runtimeCache = null;
    }
}
