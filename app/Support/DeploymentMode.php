<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Phase S2 (ADR-017) — Deployment mode helper.
 *
 * Modo `single` (default, S1): solo Operatore, uso personale didattico.
 *   - Registration self-signup chiusa (404 sui form pubblici)
 *   - DPO contact = APP_MAIL_FROM
 *   - Privacy notice template S1
 *
 * Modo `institute` (S2): Operatore + colleghi stessa scuola.
 *   - Registration aperta (admin approve)
 *   - DPO contact = INSTITUTE_OWNER_EMAIL
 *   - Privacy notice template S2 + footer "Gestito da {INSTITUTE_LEGAL_NAME}"
 *
 * Priorità lookup config:
 *   1. Runtime override file `storage/config/deployment.json` (modificato da
 *      pannello /admin/system/deployment — switch immediato, no restart).
 *   2. Env vars in .env (DEPLOYMENT_MODE, INSTITUTE_OWNER_EMAIL, INSTITUTE_LEGAL_NAME)
 *      via Config::get('app.*').
 *   3. Default 'single' + valori vuoti.
 */
final class DeploymentMode
{
    public const SINGLE    = 'single';
    public const INSTITUTE = 'institute';

    /** @var array<string,mixed>|null cache per-request del runtime override */
    private static ?array $runtimeCache = null;

    public static function current(): string
    {
        $mode = self::resolve('mode', (string) Config::get('app.deployment_mode', self::SINGLE));
        return in_array($mode, [self::SINGLE, self::INSTITUTE], true) ? $mode : self::SINGLE;
    }

    public static function isSingle(): bool
    {
        return self::current() === self::SINGLE;
    }

    public static function isInstitute(): bool
    {
        return self::current() === self::INSTITUTE;
    }

    /**
     * Email DPO/owner per privacy notice + breach notification + authority.
     * In modo single → APP_MAIL_FROM (admin = data controller).
     * In modo institute → INSTITUTE_OWNER_EMAIL (DPO scuola).
     */
    public static function dpoContact(): string
    {
        if (self::isInstitute()) {
            $email = self::resolve('institute_owner_email', (string) Config::get('app.institute_owner_email', ''));
            if ($email !== '') {
                return $email;
            }
        }
        return (string)($_ENV['APP_MAIL_FROM'] ?? '');
    }

    /**
     * Nome legale ragione sociale istituto (solo in modo institute).
     * Usato in footer + privacy notice + DPA documents.
     */
    public static function instituteLegalName(): ?string
    {
        if (!self::isInstitute()) {
            return null;
        }
        $name = self::resolve('institute_legal_name', (string) Config::get('app.institute_legal_name', ''));
        return $name !== '' ? $name : null;
    }

    /**
     * Phase S2 F3 — applica nuovo set di config (chiamato da AdminSystemController).
     * Scrive atomicamente in storage/config/deployment.json via tmp+rename.
     * Caller responsabile di validare i valori (modo enum, email format).
     *
     * @param array{mode:string, institute_owner_email?:string, institute_legal_name?:string} $config
     */
    public static function persistRuntime(array $config): void
    {
        $path = self::runtimePath();
        $dir  = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot_create_config_dir');
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode([
            'mode'                  => $config['mode'] ?? self::SINGLE,
            'institute_owner_email' => trim((string)($config['institute_owner_email'] ?? '')),
            'institute_legal_name'  => trim((string)($config['institute_legal_name'] ?? '')),
            'updated_at'            => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new \RuntimeException('cannot_write_config');
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('cannot_rename_config');
        }
        @chmod($path, 0640);
        self::$runtimeCache = null;  // invalida cache per richieste future
    }

    /**
     * Restituisce snapshot della configurazione corrente (per pannello admin).
     *
     * @return array{mode:string, institute_owner_email:string, institute_legal_name:string, source:string}
     */
    public static function snapshot(): array
    {
        $override = self::loadRuntime();
        $source = $override === null ? 'env' : 'runtime_override';
        return [
            'mode'                  => self::current(),
            'institute_owner_email' => self::dpoContact(),
            'institute_legal_name'  => self::instituteLegalName() ?? '',
            'source'                => $source,
        ];
    }

    /**
     * Restituisce valore con priorità runtime > env > default.
     */
    private static function resolve(string $key, string $envFallback): string
    {
        $runtime = self::loadRuntime();
        if ($runtime !== null && isset($runtime[$key]) && (string)$runtime[$key] !== '') {
            return (string)$runtime[$key];
        }
        return $envFallback;
    }

    /**
     * Carica e cacha il runtime override JSON. Restituisce null se file mancante.
     *
     * @return array<string,mixed>|null
     */
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
        $raw = (string) file_get_contents($path);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::$runtimeCache = ['_loaded' => false];
            return null;
        }
        $data['_loaded'] = true;
        self::$runtimeCache = $data;
        return $data;
    }

    private static function runtimePath(): string
    {
        return (string) Config::get('app.paths.storage') . '/config/deployment.json';
    }

    /**
     * Reset cache (utile nei test).
     */
    public static function resetCache(): void
    {
        self::$runtimeCache = null;
    }
}
