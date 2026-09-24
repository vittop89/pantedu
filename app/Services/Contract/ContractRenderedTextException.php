<?php

declare(strict_types=1);

namespace App\Services\Contract;

/**
 * Lanciata da `ContractRepository::save()` quando la scrittura porterebbe
 * dentro il contratto pezzi della pagina resa (vedi `TestoDiPaginaResa`):
 * il riquadro d'errore TikZ, uno `<script type="text/tikz">` come testo, un
 * `<svg>` compilato, gli attributi `data-tikz-*` del client.
 *
 * Non è un conflitto e non è un errore del server: è una richiesta che
 * distruggerebbe una sorgente. Stato HTTP: 422.
 */
class ContractRenderedTextException extends \RuntimeException
{
    /**
     * @param list<array{percorso: string, marcatore: string, testo: string}> $trovati
     */
    public function __construct(public readonly array $trovati)
    {
        parent::__construct(TestoDiPaginaResa::spiegazione($trovati));
    }

    /** I punti del contratto, per il registro (senza il testo, che è lungo). */
    public function perIlRegistro(): string
    {
        return implode('; ', array_map(
            static fn(array $v): string => $v['percorso'] . ' → ' . $v['marcatore'],
            \array_slice($this->trovati, 0, 5),
        ));
    }
}
