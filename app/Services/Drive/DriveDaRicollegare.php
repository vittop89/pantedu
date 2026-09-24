<?php

declare(strict_types=1);

namespace App\Services\Drive;

/**
 * Il collegamento a Drive del docente va rifatto (ADR-038).
 *
 * Il motivo è uno di StatoDiDrive::ACCESSO_REVOCATO e
 * StatoDiDrive::PERMESSI_INSUFFICIENTI. Chi la riceve sa che le altre mappe e
 * verifiche di quel docente fallirebbero allo stesso modo, e che non è un
 * errore da segnalare all'amministratore.
 */
final class DriveDaRicollegare extends \RuntimeException
{
    public function __construct(public readonly string $motivo, ?\Throwable $causa = null)
    {
        parent::__construct('drive_da_ricollegare', 0, $causa);
    }
}
