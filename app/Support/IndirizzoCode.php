<?php

declare(strict_types=1);

namespace App\Support;

/**
 * G22.S20 v2.C2 — DEPRECATED back-compat wrapper. La nuova API generica è
 * App\Support\CurriculumLookup. Questo file resta solo per ridurre il diff
 * dei Repository già refactorati: delegate sottostante a CurriculumLookup.
 *
 * @deprecated Usa CurriculumLookup direttamente nel codice nuovo.
 */
final class IndirizzoCode
{
    public static function canonByCode(?string $code): string
    {
        return CurriculumLookup::canonicalize('indirizzi', $code);
    }

    public static function idFromCode(?string $code, ?int $instituteId = null): ?int
    {
        return CurriculumLookup::idFromCode('indirizzi', $code, $instituteId);
    }

    public static function codeFromId(?int $id): ?string
    {
        return CurriculumLookup::codeFromId($id, 'indirizzi');
    }

    public static function instituteForTeacher(?int $teacherId): ?int
    {
        return CurriculumLookup::instituteForTeacher($teacherId);
    }

    public static function idFromCodeForTeacher(?string $code, ?int $teacherId): ?int
    {
        return CurriculumLookup::idFromCodeForTeacher('indirizzi', $code, $teacherId);
    }

    public static function resetCache(): void
    {
        CurriculumLookup::resetCache();
    }
}
