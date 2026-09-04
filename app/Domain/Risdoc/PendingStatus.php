<?php

declare(strict_types=1);

namespace App\Domain\Risdoc;

/**
 * G22.S26 — Status di una row in `risdoc_template_pending_changes`.
 *
 * Sostituisce string literal sparsi nei controller / service. Coerente
 * con il pattern già adottato da App\Domain\Role: backed enum + helper
 * `tryFromString` per parsing soft di input utente.
 */
enum PendingStatus: string
{
    case PENDING  = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    /**
     * Soft parse: ritorna null se il valore non è uno status valido
     * (usato per validare query string `?status=pending`).
     */
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

    /** Lista dei valori string accettati (utile per validation + UI). */
    public static function values(): array
    {
        return \array_map(static fn(self $c) => $c->value, self::cases());
    }

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }
}
