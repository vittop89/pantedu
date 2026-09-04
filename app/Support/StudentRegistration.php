<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Modalità di acquisizione dati per la registrazione studenti, scelta dal
 * super-admin in /admin/system/deployment. Default: `full`.
 *
 *  - full      : tutti i dati (email, data di nascita/età, consenso minori Art.8,
 *                istituto, indirizzo, classe). Comportamento storico.
 *  - reduced   : solo nome, cognome, istituto, indirizzo, classe (NIENTE
 *                email/data di nascita/genitore) → minimizzazione massima per il
 *                caso "sola visualizzazione fonti". Rinuncia all'age-gating Art.8.
 *  - anonymous : registrazione studente DISABILITATA; gli studenti accedono via
 *                credenziale del docente (grant tecnico, zero PII studente).
 *
 * `only_superadmin_classes`: quando true, le classi ammesse alla registrazione
 * sono limitate a quelle del super-admin (sync su registration_allowed_classes).
 *
 * Persistenza: storage/config/student_registration.json (atomic tmp+rename),
 * stesso pattern di {@see DeploymentMode}.
 */
final class StudentRegistration
{
    public const FULL      = 'full';
    public const REDUCED   = 'reduced';
    public const ANONYMOUS = 'anonymous';

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    public static function mode(): string
    {
        $cfg = self::load();
        $m = $cfg !== null && isset($cfg['mode'])
            ? (string)$cfg['mode']
            : (string)Config::get('app.student_registration_mode', self::FULL);
        return in_array($m, [self::FULL, self::REDUCED, self::ANONYMOUS], true) ? $m : self::FULL;
    }

    public static function isFull(): bool      { return self::mode() === self::FULL; }
    public static function isReduced(): bool   { return self::mode() === self::REDUCED; }
    public static function isAnonymous(): bool { return self::mode() === self::ANONYMOUS; }

    public static function onlySuperadminClasses(): bool
    {
        $cfg = self::load();
        return $cfg !== null && !empty($cfg['only_superadmin_classes']);
    }

    /** @return array{mode:string,only_superadmin_classes:bool,source:string} */
    public static function snapshot(): array
    {
        $cfg = self::load();
        return [
            'mode'                    => self::mode(),
            'only_superadmin_classes' => self::onlySuperadminClasses(),
            'source'                  => $cfg === null ? 'default' : 'runtime',
        ];
    }

    public static function persist(string $mode, bool $onlySuperadminClasses): void
    {
        $mode = in_array($mode, [self::FULL, self::REDUCED, self::ANONYMOUS], true) ? $mode : self::FULL;
        $path = self::path();
        $dir  = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot_create_config_dir');
        }
        $tmp  = $path . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode([
            'mode'                    => $mode,
            'only_superadmin_classes' => $onlySuperadminClasses,
            'updated_at'              => date('c'),
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
        self::$cache = null;
    }

    /** @return array<string,mixed>|null */
    private static function load(): ?array
    {
        if (self::$cache !== null) {
            return self::$cache['_loaded'] ? self::$cache : null;
        }
        $path = self::path();
        if (!is_file($path)) {
            self::$cache = ['_loaded' => false];
            return null;
        }
        $d = json_decode((string)file_get_contents($path), true);
        if (!is_array($d)) {
            self::$cache = ['_loaded' => false];
            return null;
        }
        $d['_loaded'] = true;
        self::$cache = $d;
        return $d;
    }

    private static function path(): string
    {
        return (string)Config::get('app.paths.storage') . '/config/student_registration.json';
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }
}
