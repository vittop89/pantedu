<?php

namespace App\Domain;

/**
 * Phase 19 — Enum Role (PHP 8.3 native).
 *
 * Sostituisce string literals sparsi: 'student', 'teacher', 'collaborator',
 * 'administrator'. Usabile in typed params, match/switch, static analysis.
 * Preserva retro-compat via ::fromString con accept di 'admin' alias.
 */
enum Role: string
{
    case STUDENT       = 'student';
    case TEACHER       = 'teacher';
    case COLLABORATOR  = 'collaborator';
    case ADMINISTRATOR = 'administrator';

    /** Accept eventuale alias 'admin' usato in legacy. */
    public static function fromString(string $value): self
    {
        $value = \strtolower(\trim($value));
        if ($value === 'admin') {
            return self::ADMINISTRATOR;
        }
        return self::from($value);
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
        if ($value === 'admin') {
            return self::ADMINISTRATOR;
        }
        return self::tryFrom($value);
    }

    public function isAdmin(): bool
    {
        return $this === self::ADMINISTRATOR;
    }
    public function isTeacher(): bool
    {
        return $this === self::TEACHER;
    }
    public function isCollaborator(): bool
    {
        return $this === self::COLLABORATOR;
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
            self::STUDENT       => 'Studente',
            self::TEACHER       => 'Docente',
            self::COLLABORATOR  => 'Collaboratore',
            self::ADMINISTRATOR => 'Amministratore',
        };
    }
}
