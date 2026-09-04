<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use RuntimeException;

/**
 * Toggle runtime del gate ToS/AUP.
 *
 * Il flag stava solo in `.env`, e quello era il posto sbagliato per tre motivi
 * concreti: `.env` è tracciato da git e viene caricato sul server dal deploy
 * FTP (quindi il valore migra fra ambienti senza che nessuno lo decida),
 * girarlo richiede accesso al filesystem del VPS, e non lascia traccia di chi
 * l'abbia girato. Per un controllo che impedisce l'accesso al servizio, l'ultimo
 * punto è quello che pesa: accendere o spegnere un muro legale è una decisione,
 * e va a registro come tale.
 *
 * Stessa meccanica di DeploymentMode (ADR-017): override runtime su file JSON
 * scritto atomicamente, env come default iniziale.
 *
 * Priorità lookup:
 *   1. `storage/config/tos_enforcement.json` — scritto da /admin/system/deployment
 *      (switch immediato, nessun restart di php-fpm).
 *   2. `TOS_ENFORCE` in .env, via config `multitenancy.tos_enforce`.
 *   3. Default `false` — spento. Un'installazione che non ha mai deciso nulla
 *      non deve murare fuori i propri docenti.
 */
final class TosEnforcement
{
    /** @var array<string,mixed>|null Cache per-request dell'override. */
    private static ?array $runtimeCache = null;

    /** Il gate è attivo? */
    public static function isEnabled(): bool
    {
        $runtime = self::loadRuntime();
        if ($runtime !== null && array_key_exists('enabled', $runtime)) {
            return (bool) $runtime['enabled'];
        }
        // filter_var e non (bool): la stringa 'false' che arriva da .env,
        // castata a bool, varrebbe true.
        return filter_var(
            Config::get('multitenancy.tos_enforce', $_ENV['TOS_ENFORCE'] ?? false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Stato corrente per il pannello admin.
     *
     * @return array{enabled: bool, source: string, updated_at: ?string, updated_by: ?string}
     */
    public static function snapshot(): array
    {
        $runtime = self::loadRuntime();
        return [
            'enabled'    => self::isEnabled(),
            'source'     => $runtime === null ? 'env' : 'runtime_override',
            'updated_at' => $runtime['updated_at'] ?? null,
            'updated_by' => $runtime['updated_by'] ?? null,
        ];
    }

    /**
     * Scrive l'override runtime. Atomico (tmp + rename) come DeploymentMode:
     * una richiesta concorrente legge il file vecchio o quello nuovo, mai
     * un JSON troncato a metà — che qui significherebbe gate spento per errore.
     */
    public static function persistRuntime(bool $enabled, string $actor, string $reason): void
    {
        $path = self::runtimePath();
        $dir  = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot_create_config_dir');
        }
        $json = json_encode([
            'enabled'    => $enabled,
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

    /** Rimuove l'override: si torna a quanto dice l'env. */
    public static function clearRuntime(): bool
    {
        $path = self::runtimePath();
        self::$runtimeCache = null;
        return !is_file($path) || @unlink($path);
    }

    /** @return array<string,mixed>|null null se l'override non c'è o è illeggibile */
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
        if (!is_array($data) || !array_key_exists('enabled', $data)) {
            // File corrotto: si ricade sull'env invece di indovinare. Un gate
            // legale non deve accendersi o spegnersi per un JSON malformato.
            error_log('[TosEnforcement] override illeggibile, fallback su env: ' . $path);
            self::$runtimeCache = ['_loaded' => false];
            return null;
        }
        $data['_loaded'] = true;
        self::$runtimeCache = $data;
        return $data;
    }

    private static function runtimePath(): string
    {
        return (string) Config::get('app.paths.storage') . '/config/tos_enforcement.json';
    }

    /** Reset cache (test). */
    public static function resetCache(): void
    {
        self::$runtimeCache = null;
    }
}
