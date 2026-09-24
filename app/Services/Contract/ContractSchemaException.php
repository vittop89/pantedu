<?php

declare(strict_types=1);

namespace App\Services\Contract;

/**
 * Lanciata da `ContractRepository::save()` quando la scrittura porterebbe
 * nel contratto errori di schema che il contratto di prima non aveva
 * (`schemas/pantedu.content.v1.json`, ADR-005).
 *
 * Come ContractRenderedTextException, non è un errore del server: è una
 * richiesta che renderebbe il contratto non valido. Stato HTTP: 422.
 */
class ContractSchemaException extends \RuntimeException
{
    /** @var list<string> gli errori nuovi, come li scrive il validatore */
    public readonly array $errori;

    /**
     * @param list<string> $errori
     */
    public function __construct(array $errori)
    {
        $this->errori = $errori;
        parent::__construct(
            'Il contenuto non rispetta lo schema dei contratti: '
            . implode('; ', \array_slice($errori, 0, 5))
        );
    }
}
