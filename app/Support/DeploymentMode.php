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

    /**
     * Il file del pannello, con la meccanica comune di SostituzioneSuFile
     * (23/9/2026, A-38): un file corrotto, o con un modo sconosciuto, si
     * ignora e si registra nell'anomalia `sostituzione_illeggibile`. Prima si
     * ignorava in silenzio, e un modo sconosciuto valeva `single`.
     */
    private const FILE = 'deployment.json';

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
        return (string)Config::get('mail.from', '');
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
     * Scrive atomicamente in storage/config/deployment.json (SostituzioneSuFile).
     * Caller responsabile di validare i valori (modo enum, email format).
     *
     * @param array{mode:string, institute_owner_email?:string, institute_legal_name?:string} $config
     */
    public static function persistRuntime(array $config): void
    {
        SostituzioneSuFile::scrivi(self::FILE, [
            'mode'                  => $config['mode'] ?? self::SINGLE,
            'institute_owner_email' => trim((string)($config['institute_owner_email'] ?? '')),
            'institute_legal_name'  => trim((string)($config['institute_legal_name'] ?? '')),
            'updated_at'            => date('c'),
        ]);
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
     * Il runtime override, o null se il file manca o è corrotto.
     *
     * @return array<string,mixed>|null
     */
    private static function loadRuntime(): ?array
    {
        return SostituzioneSuFile::leggi(
            self::FILE,
            static fn(array $dati): bool => in_array($dati['mode'] ?? null, [self::SINGLE, self::INSTITUTE], true),
        );
    }

    /**
     * Reset cache (utile nei test).
     */
    public static function resetCache(): void
    {
        SostituzioneSuFile::dimentica(self::FILE);
    }
}
