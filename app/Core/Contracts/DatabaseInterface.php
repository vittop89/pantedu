<?php

namespace App\Core\Contracts;

use PDO;

/**
 * Phase 17 — Thin wrapper per iniettare PDO nei service/repository invece
 * di chiamate statiche `Database::connection()`. Beneficio principale:
 *   - Unit testability (mock via PHPUnit stubs)
 *   - Supporto futuro connection pooling / read replicas
 *   - Chiara separazione infra vs domain
 *
 * Default impl `DatabaseGateway` usa la chiamata statica esistente, quindi
 * niente breaking changes. Chi vuole migrare injects `DatabaseInterface`.
 */
interface DatabaseInterface
{
    /** Ritorna la PDO connection corrente. */
    public function connection(): PDO;
}
