<?php

namespace App\Domain;

/**
 * Phase 19 — Enum ContentVisibility per teacher_content.visibility.
 * Sostituisce string literals 'draft'/'published'/'archived' sparsi.
 */
enum ContentVisibility: string
{
    case DRAFT     = 'draft';
    case PUBLISHED = 'published';
    case ARCHIVED  = 'archived';

    public static function tryFromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }
        return self::tryFrom(\strtolower(\trim($value)));
    }

    public function isVisibleToStudents(): bool
    {
        return $this === self::PUBLISHED;
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT     => 'Bozza',
            self::PUBLISHED => 'Pubblicato',
            self::ARCHIVED  => 'Archiviato',
        };
    }

    /** @return list<string> valori per validazione (enum in DB/forms). */
    public static function values(): array
    {
        return \array_map(fn(self $c) => $c->value, self::cases());
    }
}
