<?php

declare(strict_types=1);

namespace App\Support;

/**
 * G22.S1 — Astrazione per scope transazionali DB.
 *
 * Permette ai servizi di dichiarare boundary atomici senza dipendere
 * direttamente da `Database::connection()`. In test si usa il decorator
 * no-op (`NullTransactionRunner`) per isolare la logica di rollback dei
 * side-effect non transazionali (blob filesystem) da PDO.
 */
interface TransactionRunner
{
    /**
     * Esegue $work dentro una transazione DB.
     *
     * Contratto:
     *   - se $work ritorna senza eccezioni → commit, ritorna il valore.
     *   - se $work lancia → rollback, rilancia l'eccezione (no swallow).
     *   - rientranza: se gia' dentro una transazione, $work viene eseguito
     *     senza begin/commit annidati (savepoint nativi non garantiti su
     *     tutti i driver).
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function run(callable $work): mixed;
}
