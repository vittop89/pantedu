<?php

namespace App\Jobs;

use App\Services\Mailer;

/**
 * Phase 17 — Example job: invio mail async.
 *
 * Dispatch:
 *   (new JobRepository())->dispatch(
 *       \App\Jobs\SendMailJob::class,
 *       ['to' => 'x@y.it', 'subject' => '...', 'body' => '...'],
 *       queue: 'mail'
 *   );
 */
final class SendMailJob implements Job
{
    public function handle(array $payload): void
    {
        $to      = (string)($payload['to']      ?? '');
        $subject = (string)($payload['subject'] ?? '');
        $body    = (string)($payload['body']    ?? '');
        if ($to === '' || $subject === '') {
            throw new \InvalidArgumentException('missing to/subject');
        }

        // Phase 25.C8 — From address from env (APP_MAIL_FROM richiesta).
        // Senza configurazione SMTP/from il job fallisce esplicitamente
        // → worker applica backoff retry (config issue visibile).
        $from = (string)($_ENV['APP_MAIL_FROM'] ?? '');
        if ($from === '') {
            throw new \RuntimeException('APP_MAIL_FROM env not configured');
        }

        // Fail → exception propaga al worker che applica backoff retry.
        (new Mailer($from))->send($to, $subject, $body);
    }
}
