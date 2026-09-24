<?php

namespace App\Domain;

/**
 * Phase 19 — Enum Role (PHP 8.3 native).
 *
 * Sostituisce string literals sparsi: 'student', 'teacher',
 * 'institute_admin', 'administrator'. Usabile in typed params, match/switch,
 * static analysis.
 *
 * 2026-09-15 — il collaboratore non esiste più. Non apriva niente che un
 * docente non aprisse: la sua zona aveva solo tre indirizzi vecchi che
 * rispondono 410 e un gruppo di API vuoto. In produzione nessun utente lo
 * aveva. Dalla migrazione 128 il database non lo accetta.
 *
 * 2026-09-14 (ADR-040) — l'alias `admin` non esiste più. Rispondeva
 * ADMINISTRATOR, cioè i poteri dell'amministratore della piattaforma, per il
 * valore che il wizard degli istituti scriveva agli amministratori di un
 * istituto e che nessuna zona di accesso riconosceva. Dalla migrazione 125 il
 * database non lo accetta più.
 */
enum Role: string
{
    case STUDENT         = 'student';
    case TEACHER         = 'teacher';
    case INSTITUTE_ADMIN = 'institute_admin';
    case ADMINISTRATOR   = 'administrator';

    public static function fromString(string $value): self
    {
        return self::from(\strtolower(\trim($value)));
    }

    /** Soft version: ritorna null invece di throw. */
    public static function tryFromString(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }
        $value = \strtolower(\trim($value));
        if ($value === '') {
            return null;
        }
        return self::tryFrom($value);
    }

    public function isAdmin(): bool
    {
        return $this === self::ADMINISTRATOR;
    }
    public function isInstituteAdmin(): bool
    {
        return $this === self::INSTITUTE_ADMIN;
    }
    public function isTeacher(): bool
    {
        return $this === self::TEACHER;
    }
    public function isStudent(): bool
    {
        return $this === self::STUDENT;
    }

    public function canTeach(): bool
    {
        return $this === self::TEACHER || $this === self::ADMINISTRATOR;
    }

    public function label(): string
    {
        return match ($this) {
            self::STUDENT         => 'Studente',
            self::TEACHER         => 'Docente',
            self::INSTITUTE_ADMIN => 'Amministratore di istituto',
            self::ADMINISTRATOR   => 'Amministratore',
        };
    }
}
