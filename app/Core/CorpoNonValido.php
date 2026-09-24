<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * Il corpo della richiesta non si può usare: troppo grande, vuoto dove serve,
 * o non JSON (23/9/2026, revisione architetturale A-52; ADR-034).
 *
 * Lo lanciano `Request::json()`, `body()` e `jsonObbligatorio()`, e porta la
 * risposta con sé: il messaggio è il codice che finisce in `error`
 * (`payload_too_large`, `empty_payload`, `invalid_json`), `stato()` il codice
 * HTTP. Un corpo troppo grande è **sempre** 413: prima quattro copie di
 * `readJsonBody()` rispondevano 413 o 400 a seconda del controller.
 *
 * Se nessuno la prende la risposta la dà il Kernel. Un controller che avvolge
 * la lettura in un `catch (Throwable)` chiede lo stato con `statoPer()`,
 * invece di decidere 400 per tutto.
 */
final class CorpoNonValido extends RuntimeException
{
    private int $stato;

    private function __construct(string $codice, int $stato)
    {
        parent::__construct($codice);
        $this->stato = $stato;
    }

    public static function troppoGrande(): self
    {
        return new self('payload_too_large', 413);
    }

    public static function vuoto(): self
    {
        return new self('empty_payload', 400);
    }

    public static function jsonRotto(): self
    {
        return new self('invalid_json', 400);
    }

    public function stato(): int
    {
        return $this->stato;
    }

    /** Lo stato HTTP di un'eccezione presa da un catch generico. */
    public static function statoPer(Throwable $e, int $altrimenti): int
    {
        return $e instanceof self ? $e->stato() : $altrimenti;
    }
}
