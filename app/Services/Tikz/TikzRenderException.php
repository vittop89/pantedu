<?php

declare(strict_types=1);

namespace App\Services\Tikz;

use RuntimeException;

/**
 * Eccezione dedicata per fallimenti di compilazione TikZ → SVG.
 * Include il log troncato del compilatore per surface in UI, e dal 24/9/2026
 * gli errori di pdflatex (ErroriTex): vuota se il fallimento non è del
 * disegno (tempo scaduto, rete).
 */
final class TikzRenderException extends RuntimeException
{
    /**
     * @param list<array{line:?int, message:string, context:string}> $errori
     */
    public function __construct(
        string $log,
        private readonly int $httpStatus = 0,
        private readonly array $errori = [],
    ) {
        parent::__construct($log === '' ? 'tikz_compile_failed' : $log);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return list<array{line:?int, message:string, context:string}> */
    public function errori(): array
    {
        return $this->errori;
    }
}
