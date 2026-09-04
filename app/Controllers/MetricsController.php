<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\AclPolicy;

/**
 * Phase 25.E4.2 — Endpoint /metrics Prometheus-compatible.
 *
 * Format: Prometheus exposition format text/plain (no JSON).
 * https://github.com/prometheus/docs/blob/main/content/docs/instrumenting/exposition_formats.md
 *
 * Auth: super_admin OR Bearer token via env METRICS_BEARER_TOKEN.
 *   - Production: scrape Prometheus usa Bearer token (no session cookie).
 *   - Dev/admin: super_admin via session.
 *
 * Metriche esposte:
 *   - pantedu_users_total{role,status}      gauge
 *   - pantedu_teacher_content_total{type}    gauge
 *   - pantedu_consents_active_total{type}    gauge
 *   - pantedu_deletion_requests_total{status} gauge (Art. 17)
 *   - pantedu_dpo_requests_total{status,subject}  gauge (Art. 12-22)
 *   - pantedu_crypto_keys_total              gauge (teacher_keys row count)
 *   - pantedu_crypto_access_24h_total{operation,outcome} counter (last 24h)
 *   - pantedu_privileged_access_24h_total    counter
 *   - pantedu_app_info{version}              gauge=1 (label info)
 *
 * Tutte le metriche sono **aggregati** — no PII raw, no per-user counter.
 * Compatibile GDPR Art. 32 + scrape pubblico interno.
 */
final class MetricsController
{
    public function show(Request $req): Response
    {
        if (!$this->authorized($req)) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        $lines = [];
        $lines[] = '# Pantedu Prometheus metrics — Phase 25.E4.2';
        $lines[] = '# HELP pantedu_app_info Application info (constant 1)';
        $lines[] = '# TYPE pantedu_app_info gauge';
        $lines[] = sprintf('pantedu_app_info{version="phase-25"} 1');
        $lines[] = '';

        try {
            $this->appendUsersMetrics($lines);
            $this->appendContentMetrics($lines);
            $this->appendGdprMetrics($lines);
            $this->appendCryptoMetrics($lines);
            $this->appendAuditMetrics($lines);
        } catch (\Throwable $e) {
            // Best-effort: alcune metriche possono fallire (DB issue) ma il
            // resto resta esposto.
            $lines[] = '# WARNING: ' . str_replace("\n", ' ', $e->getMessage());
        }

        $body = implode("\n", $lines) . "\n";
        $resp = Response::html($body);
        $resp->headers['Content-Type'] = 'text/plain; version=0.0.4; charset=utf-8';
        $resp->headers['Cache-Control'] = 'no-store';
        return $resp;
    }

    private function authorized(Request $req): bool
    {
        // Bearer token check (Prometheus scrape-friendly)
        // Apache spesso strippa Authorization header → fallback multipli:
        //   1. $req->server[HTTP_AUTHORIZATION]
        //   2. $_SERVER[REDIRECT_HTTP_AUTHORIZATION] (mod_rewrite passes via REDIRECT_*)
        //   3. apache_request_headers()['Authorization'] (CGI / mod_php)
        //   4. Query string ?bearer=... (no-auth dev fallback, mai in prod)
        $auth = (string)($req->server['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '');
        if ($auth === '' && function_exists('apache_request_headers')) {
            $headers = \apache_request_headers();
            if (is_array($headers)) {
                $auth = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
            }
        }
        if (str_starts_with($auth, 'Bearer ')) {
            $token = substr($auth, 7);
            $expected = $_ENV['METRICS_BEARER_TOKEN'] ?? '';
            if ($expected !== '' && hash_equals($expected, $token)) {
                return true;
            }
        }
        // Query string fallback (dev/test only; production prefers Bearer header)
        $bearer = (string)($req->query['bearer'] ?? '');
        if ($bearer !== '') {
            $expected = $_ENV['METRICS_BEARER_TOKEN'] ?? '';
            if ($expected !== '' && hash_equals($expected, $bearer)) {
                return true;
            }
        }
        // Session-based (super_admin)
        return Auth::check() && AclPolicy::isSuperAdmin();
    }

    /** @param array<int,string> $lines */
    private function appendUsersMetrics(array &$lines): void
    {
        $rows = Database::connection()->query(
            'SELECT role, status, COUNT(*) AS n FROM users
             WHERE deleted_at IS NULL
             GROUP BY role, status'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $lines[] = '# HELP pantedu_users_total Active users by role and status';
        $lines[] = '# TYPE pantedu_users_total gauge';
        foreach ($rows as $r) {
            $role = $this->labelEsc($r['role'] ?? 'unknown');
            $status = $this->labelEsc($r['status'] ?? 'unknown');
            $lines[] = sprintf('pantedu_users_total{role="%s",status="%s"} %d', $role, $status, (int)$r['n']);
        }
        $lines[] = '';
    }

    /** @param array<int,string> $lines */
    private function appendContentMetrics(array &$lines): void
    {
        $rows = Database::connection()->query(
            'SELECT content_type, COUNT(*) AS n FROM teacher_content
             GROUP BY content_type'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $lines[] = '# HELP pantedu_teacher_content_total Total teacher_content rows by type';
        $lines[] = '# TYPE pantedu_teacher_content_total gauge';
        foreach ($rows as $r) {
            $type = $this->labelEsc($r['content_type'] ?? 'unknown');
            $lines[] = sprintf('pantedu_teacher_content_total{type="%s"} %d', $type, (int)$r['n']);
        }
        $lines[] = '';
    }

    /** @param array<int,string> $lines */
    private function appendGdprMetrics(array &$lines): void
    {
        // Consents active by type
        try {
            $rows = Database::connection()->query(
                'SELECT consent_type, COUNT(*) AS n FROM consents
                 WHERE revoked_at IS NULL
                 GROUP BY consent_type'
            )->fetchAll(\PDO::FETCH_ASSOC);
            $lines[] = '# HELP pantedu_consents_active_total Active GDPR consents by type';
            $lines[] = '# TYPE pantedu_consents_active_total gauge';
            foreach ($rows as $r) {
                $type = $this->labelEsc($r['consent_type'] ?? 'unknown');
                $lines[] = sprintf('pantedu_consents_active_total{type="%s"} %d', $type, (int)$r['n']);
            }
            $lines[] = '';
        } catch (\Throwable) {
/* table may not exist */
        }

        // Deletion requests Art. 17
        try {
            $rows = Database::connection()->query(
                'SELECT status, COUNT(*) AS n FROM deletion_requests GROUP BY status'
            )->fetchAll(\PDO::FETCH_ASSOC);
            $lines[] = '# HELP pantedu_deletion_requests_total Art. 17 deletion requests by status';
            $lines[] = '# TYPE pantedu_deletion_requests_total gauge';
            foreach ($rows as $r) {
                $status = $this->labelEsc($r['status'] ?? 'unknown');
                $lines[] = sprintf('pantedu_deletion_requests_total{status="%s"} %d', $status, (int)$r['n']);
            }
            $lines[] = '';
        } catch (\Throwable) {
        }

        // DPO requests
        try {
            $rows = Database::connection()->query(
                'SELECT subject, status, COUNT(*) AS n FROM dpo_requests
                 GROUP BY subject, status'
            )->fetchAll(\PDO::FETCH_ASSOC);
            $lines[] = '# HELP pantedu_dpo_requests_total DPO contact requests by subject/status';
            $lines[] = '# TYPE pantedu_dpo_requests_total gauge';
            foreach ($rows as $r) {
                $subj = $this->labelEsc($r['subject'] ?? 'unknown');
                $status = $this->labelEsc($r['status'] ?? 'unknown');
                $lines[] = sprintf(
                    'pantedu_dpo_requests_total{subject="%s",status="%s"} %d',
                    $subj,
                    $status,
                    (int)$r['n']
                );
            }
            $lines[] = '';
        } catch (\Throwable) {
        }

        // Parent consents (Art. 8 minori)
        try {
            $rows = Database::connection()->query(
                'SELECT status, COUNT(*) AS n FROM parent_consents GROUP BY status'
            )->fetchAll(\PDO::FETCH_ASSOC);
            $lines[] = '# HELP pantedu_parent_consents_total Art. 8 parent consents by status';
            $lines[] = '# TYPE pantedu_parent_consents_total gauge';
            foreach ($rows as $r) {
                $status = $this->labelEsc($r['status'] ?? 'unknown');
                $lines[] = sprintf(
                    'pantedu_parent_consents_total{status="%s"} %d',
                    $status,
                    (int)$r['n']
                );
            }
            $lines[] = '';
        } catch (\Throwable) {
        }
    }

    /** @param array<int,string> $lines */
    private function appendCryptoMetrics(array &$lines): void
    {
        try {
            $count = (int)Database::connection()->query(
                'SELECT COUNT(*) FROM teacher_keys'
            )->fetchColumn();
            $lines[] = '# HELP pantedu_crypto_keys_total Total teacher_keys rows (Phase 25.D)';
            $lines[] = '# TYPE pantedu_crypto_keys_total gauge';
            $lines[] = sprintf('pantedu_crypto_keys_total %d', $count);
            $lines[] = '';
        } catch (\Throwable) {
        }

        try {
            $rows = Database::connection()->query(
                'SELECT operation, outcome, COUNT(*) AS n FROM crypto_access_log
                 WHERE accessed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 GROUP BY operation, outcome'
            )->fetchAll(\PDO::FETCH_ASSOC);
            $lines[] = '# HELP pantedu_crypto_access_24h_total Crypto access events last 24h';
            $lines[] = '# TYPE pantedu_crypto_access_24h_total counter';
            foreach ($rows as $r) {
                $op = $this->labelEsc($r['operation'] ?? 'unknown');
                $out = $this->labelEsc($r['outcome'] ?? 'unknown');
                $lines[] = sprintf(
                    'pantedu_crypto_access_24h_total{operation="%s",outcome="%s"} %d',
                    $op,
                    $out,
                    (int)$r['n']
                );
            }
            $lines[] = '';
        } catch (\Throwable) {
        }
    }

    /** @param array<int,string> $lines */
    private function appendAuditMetrics(array &$lines): void
    {
        try {
            $rows = Database::connection()->query(
                'SELECT action, outcome, COUNT(*) AS n FROM privileged_access_log
                 WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 GROUP BY action, outcome'
            )->fetchAll(\PDO::FETCH_ASSOC);
            $lines[] = '# HELP pantedu_privileged_access_24h_total Super-admin privileged access events last 24h';
            $lines[] = '# TYPE pantedu_privileged_access_24h_total counter';
            foreach ($rows as $r) {
                $action = $this->labelEsc($r['action'] ?? 'unknown');
                $out = $this->labelEsc($r['outcome'] ?? 'unknown');
                $lines[] = sprintf(
                    'pantedu_privileged_access_24h_total{action="%s",outcome="%s"} %d',
                    $action,
                    $out,
                    (int)$r['n']
                );
            }
            $lines[] = '';
        } catch (\Throwable) {
        }
    }

    /** Escape per Prometheus label values: \\ → \\\\, \n → \\n, " → \\". */
    private function labelEsc(string $s): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $s);
    }
}
