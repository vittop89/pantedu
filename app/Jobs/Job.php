<?php

namespace App\Jobs;

/**
 * Phase 17 — Contratto base per qualsiasi job async.
 * Gli handler ricevono il payload dal worker e lanciano exception per fail.
 */
interface Job
{
    /** Esegue il job. Eccezione = fail (retry logic in worker/repository). */
    public function handle(array $payload): void;
}
