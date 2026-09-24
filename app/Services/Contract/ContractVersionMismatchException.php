<?php

namespace App\Services\Contract;

/**
 * Phase 16 — lanciata da `ContractRepository::save()` quando il caller ha
 * fornito un `expectedVersion` che non combacia con la version corrente del
 * contract in storage (conflict: qualcuno ha salvato nel frattempo).
 *
 * Il client dovrebbe ricaricare il contract (fetch fresh → apply patch →
 * retry save). Status HTTP appropriato: 409 Conflict.
 */
class ContractVersionMismatchException extends \RuntimeException
{
    public function __construct(
        public readonly int $expected,
        public readonly int $actual,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? "Contract version mismatch: expected=$expected actual=$actual"
        );
    }
}
