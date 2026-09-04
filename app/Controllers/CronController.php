<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Phase 25.E17 — Cron / localhost-only entrypoints.
 *
 * Sostituisce LegacyController per:
 *   ANY /delete_temp.php   → api/files/delete_temp.php
 *
 * Lo script ha la sua propria guard interna (PHP_SAPI === 'cli' OR
 * REMOTE_ADDR ∈ {127.0.0.1, ::1}). Il controller delega ma aggiunge un
 * controllo difensivo aggiuntivo a livello HTTP per coerenza di pattern.
 */
final class CronController
{
    /** Cron localhost-only: delete temp files. */
    public function deleteTemp(Request $req, array $params): Response
    {
        unset($params);
        $remote = $req->server['REMOTE_ADDR'] ?? '';
        $isCli = PHP_SAPI === 'cli';
        $isLocalhost = \in_array($remote, ['127.0.0.1', '::1'], true);
        if (!$isCli && !$isLocalhost) {
            return Response::html('<h1>403 Forbidden — cron-only endpoint</h1>', 403);
        }

        $base = (string)Config::get('app.paths.legacy');
        $absoluteBase = realpath($base);
        $target = $absoluteBase
            ? realpath($absoluteBase . DIRECTORY_SEPARATOR . 'api/files/delete_temp.php')
            : false;
        if ($target === false || !str_starts_with($target, (string)$absoluteBase) || !is_file($target)) {
            return Response::html('<h1>500 Cron script missing</h1>', 500);
        }

        return Response::file($target);
    }
}
