<?php

declare(strict_types=1);

namespace App\Services\Risdoc\Pt;

/**
 * Errore di una formula PT (ADR-031): il codice (`#REF!`, `#VALUE!`,
 * `#DIV/0!`, `#NAME?`…) e' quello che la cella mostra, come nel motore JS
 * gemello. Viveva nello stesso file di FormulaEngine; separato il
 * 2026-09-04 (PSR-12: una classe per file).
 */
final class FormulaError extends \RuntimeException
{
    public string $errCode;

    public function __construct(string $errCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errCode);
        $this->errCode = $errCode;
    }
}
