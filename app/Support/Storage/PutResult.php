<?php

namespace App\Support\Storage;

/**
 * Esito di un'operazione `put`. Sempre ritornato dal provider per
 * permettere di aggiornare la tabella `storage_objects` senza che il
 * caller debba ricalcolare checksum/size.
 */
final class PutResult
{
    public function __construct(
        public readonly string $key,
        public readonly int    $size,
        public readonly string $checksum,   // sha256 hex
        public readonly string $provider,
    ) {}
}
