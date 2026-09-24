<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Support\ImprontaIp;

/**
 * POST /api/csp-report — raccoglie le violazioni della Content Security
 * Policy che il browser manda al `report-uri` (2026-09-05, revisione A7).
 *
 * Serve a tenere la produzione in `CSP_MODE=report-only`: la policy severa
 * non blocca nulla, ma ogni cosa che bloccherebbe arriva qui e si legge con
 * calma prima di passare a `strict`. Senza questo endpoint le violazioni si
 * vedrebbero solo nella console di chi naviga.
 *
 * Contratto:
 *   - nessuna sessione (il browser manda il report anche dalla pagina di
 *     login), rate limit in rotta, corpo al massimo 16 KB;
 *   - accetta il formato `report-uri` ({"csp-report": {...}}) e quello
 *     `report-to` ([{"body": {...}}]);
 *   - tiene solo i campi utili a capire la violazione, troncati; niente
 *     cookie, IP come impronta con chiave che cambia ogni giorno
 *     (ImprontaIp::delGiorno), user agent troncato;
 *   - append-only NDJSON in <app.paths.logs>/csp-reports/AAAA-MM-GG.ndjson,
 *     una riga JSON per report.
 *   - risponde 204 al report accettato, 400 a tutto il resto.
 */
final class CspReportController
{
    private const MAX_BODY = 16384;

    /** Campi del report che vale la pena tenere (nomi di report-uri e di report-to). */
    private const FIELDS = [
        'document-uri', 'documentURL',
        'referrer',
        'violated-directive', 'effective-directive', 'effectiveDirective',
        'blocked-uri', 'blockedURL',
        'source-file', 'sourceFile',
        'line-number', 'lineNumber',
        'column-number', 'columnNumber',
        'status-code', 'statusCode',
        'disposition',
        'script-sample', 'sample',
    ];

    public function collect(Request $req): Response
    {
        $raw = $req->rawBody();
        if ($raw === '' || strlen($raw) > self::MAX_BODY) {
            return Response::json(['ok' => false], 400);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return Response::json(['ok' => false], 400);
        }
        $report = $data['csp-report'] ?? ($data[0]['body'] ?? null);
        if (!is_array($report)) {
            return Response::json(['ok' => false], 400);
        }

        $entry = ['t' => date('c')];
        foreach (self::FIELDS as $field) {
            if (!isset($report[$field]) || !is_scalar($report[$field])) {
                continue;
            }
            $entry[$field] = mb_substr((string)$report[$field], 0, 500);
        }
        if (count($entry) === 1) {
            return Response::json(['ok' => false], 400);
        }
        $entry['ua']  = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120);
        // Un'impronta dell'indirizzo che cambia ogni giorno, con chiave (dal
        // 24/9/2026). Prima era lo SHA-256 di «IP|data»: la data si sa, e un
        // IPv4 si ritrovava provando tutti gli indirizzi.
        $entry['iph'] = ImprontaIp::delGiorno((string)($_SERVER['REMOTE_ADDR'] ?? ''), date('Y-m-d'));

        $dir = rtrim((string)Config::get('app.paths.logs', dirname(__DIR__, 2) . '/storage/logs'), '/\\') . '/csp-reports';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $dir . '/' . date('Y-m-d') . '.ndjson',
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );

        return new Response('', 204);
    }
}
