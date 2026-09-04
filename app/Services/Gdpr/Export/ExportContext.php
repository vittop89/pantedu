<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export;

/**
 * Phase 25.R.23 — Contesto richiesta export.
 *
 * Controlla:
 *   - chi è il data subject (userId)
 *   - chi sta richiedendo (requestorId — può essere == userId per self-service o admin/autorità)
 *   - scope: self-service Art. 15/20 vs admin authority Art. 6(1)(c)
 *   - filtri opzionali (date range, tipi specifici, etc.)
 *
 * Le exporter usano questo contesto per applicare minimizzazione:
 *   - Self-service: include consensi, ma NON audit log altrui
 *   - Admin authority: include audit log, ma escludi TOTP secrets
 */
final class ExportContext
{
    public const SCOPE_SELF_SERVICE = 'self-service';
    public const SCOPE_AUTHORITY    = 'authority';
    public const SCOPE_ADMIN_AUDIT  = 'admin-audit';

    public function __construct(
        /** ID utente i cui dati sono esportati (data subject). */
        public readonly int $userId,
        /** Scope richiesta: self-service vs authority vs admin-audit. */
        public readonly string $scope = self::SCOPE_SELF_SERVICE,
        /** ID di chi sta facendo la richiesta (per audit). null = anonimo. */
        public readonly ?int $requestorId = null,
        /** Filtri opzionali (date_from, date_to, content_types, etc.). */
        public readonly array $filters = [],
        /** Reason testuale (per log/audit). */
        public readonly ?string $reason = null,
    ) {
    }

    public function isSelfService(): bool
    {
        return $this->scope === self::SCOPE_SELF_SERVICE;
    }

    public function isAuthority(): bool
    {
        return $this->scope === self::SCOPE_AUTHORITY;
    }

    public function isAdmin(): bool
    {
        return $this->scope === self::SCOPE_AUTHORITY || $this->scope === self::SCOPE_ADMIN_AUDIT;
    }

    /** True se il requestor coincide con il data subject (auto-richiesta). */
    public function isOwnerRequest(): bool
    {
        return $this->requestorId === $this->userId;
    }
}
