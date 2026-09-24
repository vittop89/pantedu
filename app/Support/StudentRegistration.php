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
 * Persistenza: storage/config/student_registration.json, con la meccanica
 * comune di {@see SostituzioneSuFile} (scrittura atomica; dal 23/9/2026 un
 * file corrotto — o con un modo sconosciuto — si ignora e si registra
 * nell'anomalia `sostituzione_illeggibile`: prima si ignorava in silenzio,
 * revisione architetturale A-38).
 */
final class StudentRegistration
{
    public const FULL      = 'full';
    public const REDUCED   = 'reduced';
    public const ANONYMOUS = 'anonymous';

    private const FILE = 'student_registration.json';

    public static function mode(): string
    {
        $cfg = self::load();
        $m = $cfg !== null && isset($cfg['mode'])
            ? (string)$cfg['mode']
            : (string)Config::get('app.student_registration_mode', self::FULL);
        return in_array($m, [self::FULL, self::REDUCED, self::ANONYMOUS], true) ? $m : self::FULL;
    }

    public static function isFull(): bool
    {
        return self::mode() === self::FULL;
    }
    public static function isReduced(): bool
    {
        return self::mode() === self::REDUCED;
    }
    public static function isAnonymous(): bool
    {
        return self::mode() === self::ANONYMOUS;
    }

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
        SostituzioneSuFile::scrivi(self::FILE, [
            'mode'                    => $mode,
            'only_superadmin_classes' => $onlySuperadminClasses,
            'updated_at'              => date('c'),
        ]);
    }

    /** @return array<string,mixed>|null null se il file manca o è corrotto */
    private static function load(): ?array
    {
        return SostituzioneSuFile::leggi(
            self::FILE,
            static fn(array $dati): bool => in_array($dati['mode'] ?? null, [self::FULL, self::REDUCED, self::ANONYMOUS], true),
        );
    }

    public static function resetCache(): void
    {
        SostituzioneSuFile::dimentica(self::FILE);
    }
}
