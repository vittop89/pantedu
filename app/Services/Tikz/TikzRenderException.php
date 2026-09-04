<?php

declare(strict_types=1);

namespace App\Services\Tikz;

use RuntimeException;

/**
 * Eccezione dedicata per fallimenti di compilazione TikZ → SVG.
 * Include il log troncato del compilatore per surface in UI.
 */
final class TikzRenderException extends RuntimeException
{
    public function __construct(
        string $log,
        private readonly int $httpStatus = 0,
    ) {
        parent::__construct($log === '' ? 'tikz_compile_failed' : $log);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
