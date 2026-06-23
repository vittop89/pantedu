<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use PDO;

/**
 * Phase 14 — metriche infrastrutturali per la dashboard super-admin.
 *
 * NON restituisce MAI dati personali studenti: solo aggregati
 * (conteggi, spazio, stato backup). Solo super-admin può leggere.
 */
final class InfrastructureMonitorService
{
    /**
     * @return array{
     *   db: array{enabled:bool, quota_mb:?float, used_mb:?float, pct:?int, threshold:string},
     *   fs: array{free_gb:float, total_gb:float, used_pct:int, threshold:string},
     *   counts: array<string,int>,
     *   backup: array{db_last_iso:?string, files_last_iso:?string, stale:bool},
     *   storage: array{provider:string, objects:?int, size_mb:?float}
     * }
     */
    public function snapshot(): array
    {
        $th = (array)Config::get('monitoring.quota_thresholds', []);
        $warn = (int)($th['warn']     ?? 70);
        $high = (int)($th['high']     ?? 85);
        $crit = (int)($th['critical'] ?? 95);

        $db = $this->db($warn, $high, $crit);
        $fs = $this->fs($warn, $high, $crit);
        $backup  = $this->backup();
        $counts  = $this->counts();
        $storage = $this->storage();

        return [
            'db'      => $db,
            'fs'      => $fs,
            'counts'  => $counts,
            'backup'  => $backup,
            'storage' => $storage,
            'thresholds' => ['warn' => $warn, 'high' => $high, 'critical' => $crit],
            'generated_at' => date('c'),
        ];
    }

    private function db(int $warn, int $high, int $crit): array
    {
        $out = ['enabled' => false, 'quota_mb' => null, 'used_mb' => null, 'pct' => null, 'threshold' => 'ok'];
        if (!Database::isAvailable()) {
            return $out;
        }
        $out['enabled'] = true;
        try {
            $pdo = Database::connection();
            $name = (string)Config::get('database.name');
            $stmt = $pdo->prepare(
                'SELECT SUM(data_length + index_length) AS bytes
                 FROM information_schema.TABLES WHERE table_schema = ?'
            );
            $stmt->execute([$name]);
            $bytes = (int)($stmt->fetchColumn() ?: 0);
            $out['used_mb'] = round($bytes / 1024 / 1024, 2);
            $quotaMb = (float)($_ENV['DB_QUOTA_MB'] ?? 1024);
            $out['quota_mb'] = $quotaMb;
            $pct = $quotaMb > 0 ? (int)round(($bytes / 1024 / 1024) / $quotaMb * 100) : 0;
            $out['pct'] = $pct;
            $out['threshold'] = $this->labelPct($pct, $warn, $high, $crit);
        } catch (\Throwable) {
/* lascia null */
        }
        return $out;
    }

    private function fs(int $warn, int $high, int $crit): array
    {
        $base  = (string)Config::get('app.paths.base');
        $total = @disk_total_space($base) ?: 0;
        $free  = @disk_free_space($base)  ?: 0;
        $used  = $total - $free;
        $pct   = $total > 0 ? (int)round($used / $total * 100) : 0;
        return [
            'free_gb'   => round($free  / 1024 / 1024 / 1024, 2),
            'total_gb'  => round($total / 1024 / 1024 / 1024, 2),
            'used_pct'  => $pct,
            'threshold' => $this->labelPct($pct, $warn, $high, $crit),
        ];
    }

    /** @return array<string,int> conteggi aggregati (niente PII) */
    private function counts(): array
    {
        $out = ['users_total' => 0, 'users_teacher' => 0, 'users_student' => 0,
                'institutes' => 0, 'materials' => 0, 'pending_regs' => 0];
        if (!Database::isAvailable()) {
            return $out;
        }
        try {
            $pdo = Database::connection();
            $out['users_total']   = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $out['users_teacher'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='teacher'")->fetchColumn();
            $out['users_student'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
            $out['institutes']    = (int)$pdo->query('SELECT COUNT(*) FROM institutes WHERE active=1')->fetchColumn();
            $out['materials']     = (int)$pdo->query('SELECT COUNT(*) FROM teacher_content')->fetchColumn();
            $out['pending_regs']  = (int)$pdo->query("SELECT COUNT(*) FROM registrations WHERE status='pending'")->fetchColumn();
        } catch (\Throwable) {
/* best-effort */
        }
        return $out;
    }

    private function backup(): array
    {
        $cfg = (array)Config::get('monitoring.backup', []);
        $dbDir    = (string)($cfg['db_dir']    ?? '');
        $filesDir = (string)($cfg['files_dir'] ?? '');
        $maxAgeHr = (int)($cfg['max_age_hr']   ?? 48);

        $lastDb    = $this->latestMtime($dbDir);
        $lastFiles = $this->latestMtime($filesDir);
        $nowTs     = time();
        $stale = (
            ($lastDb    === null || ($nowTs - $lastDb)    > $maxAgeHr * 3600)
            || ($lastFiles === null || ($nowTs - $lastFiles) > $maxAgeHr * 3600)
        );
        return [
            'db_last_iso'    => $lastDb    ? date('c', $lastDb)    : null,
            'files_last_iso' => $lastFiles ? date('c', $lastFiles) : null,
            'stale'          => $stale,
        ];
    }

    private function storage(): array
    {
        $provider = (string)Config::get('storage.default_provider', 'local');
        $out = ['provider' => $provider, 'objects' => null, 'size_mb' => null];
        if (Database::isAvailable()) {
            try {
                $pdo = Database::connection();
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) AS n, COALESCE(SUM(size_bytes),0) AS b
                     FROM storage_objects WHERE provider = ?'
                );
                $stmt->execute([$provider]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $out['objects'] = (int)($row['n'] ?? 0);
                $out['size_mb'] = round(((int)($row['b'] ?? 0)) / 1024 / 1024, 2);
            } catch (\Throwable) {
/* tabella non ancora presente */
            }
        }
        return $out;
    }

    private function latestMtime(string $dir): ?int
    {
        if ($dir === '' || !is_dir($dir)) {
            return null;
        }
        $max = null;
        foreach (@scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $dir . DIRECTORY_SEPARATOR . $entry;
            $m = @filemtime($p);
            if ($m !== false && ($max === null || $m > $max)) {
                $max = $m;
            }
        }
        return $max;
    }

    private function labelPct(int $pct, int $warn, int $high, int $crit): string
    {
        if ($pct >= $crit) {
            return 'critical';
        }
        if ($pct >= $high) {
            return 'high';
        }
        if ($pct >= $warn) {
            return 'warn';
        }
        return 'ok';
    }
}
