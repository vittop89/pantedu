<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * G22.S1 — Implementazione default di TransactionRunner via PDO.
 *
 * Usa `Database::connection()` come singleton. Rientranza gestita con
 * `inTransaction()` check: i begin/commit annidati sono no-op (la
 * transazione esterna controlla il commit finale).
 */
final class PdoTransactionRunner implements TransactionRunner
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    public function run(callable $work): mixed
    {
        $pdo = $this->pdo ?? Database::connection();

        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $result = $work();
            if ($owns) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
