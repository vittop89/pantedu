<?php

namespace App\Core;

final class AccessLogger
{
    private string $logFile;
    private string $statsFile;

    // G22.S15.bis Fase 5+ — keys uppercase modernizzati + legacy lowercase
    // per back-compat (log storici prima della migrazione).
    private const INSTITUTE_MAP = [
        'SCI' => 'Scientifico', 'sc' => 'Scientifico',
        'ART' => 'Artistico',   'ar' => 'Artistico',
        'CLA' => 'Classico',    'cl' => 'Classico',
        'LIN' => 'Linguistico', 'ling' => 'Linguistico', 'li' => 'Linguistico',
        'AFM' => 'Amministrazione e Finanza', 'af' => 'Amministrazione e Finanza',
    ];

    private const CLASS_MAP = [
        '1S' => 'Prima Standard',  '2S' => 'Seconda Standard',
        '3S' => 'Terza Standard',  '4S' => 'Quarta Standard',  '5S' => 'Quinta Standard',
        '1B' => 'Prima Bilinguismo','2B' => 'Seconda Bilinguismo',
        '3B' => 'Terza Bilinguismo','4B' => 'Quarta Bilinguismo','5B' => 'Quinta Bilinguismo',
        // legacy lowercase
        '1s' => 'Prima Standard',  '2s' => 'Seconda Standard',
        '3s' => 'Terza Standard',  '4s' => 'Quarta Standard',  '5s' => 'Quinta Standard',
        '1b' => 'Prima Bilinguismo','2b' => 'Seconda Bilinguismo',
        '3b' => 'Terza Bilinguismo','4b' => 'Quarta Bilinguismo','5b' => 'Quinta Bilinguismo',
    ];

    public function __construct(?string $logDir = null)
    {
        $dir = $logDir ?? Config::get('app.paths.logs', dirname(__DIR__, 2) . '/storage/logs');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->logFile   = $dir . '/access_log.json';
        $this->statsFile = $dir . '/access_stats.json';

        if (!file_exists($this->logFile)) {
            file_put_contents($this->logFile, '[]');
        }
        if (!file_exists($this->statsFile)) {
            file_put_contents($this->statsFile, json_encode([
                'daily_stats'     => new \stdClass(),
                'user_stats'      => new \stdClass(),
                'institute_stats' => new \stdClass(),
                'class_stats'     => new \stdClass(),
            ]));
        }
    }

    public function logAccess(string $username, string $role, ?string $linkref = null, string $action = 'access'): void
    {
        if ($action === 'logout') {
            $this->appendDebug("LOGOUT user=$username role=$role");
            return;
        }

        $entry = array_merge([
            'timestamp'  => date('Y-m-d H:i:s'),
            'date'       => date('Y-m-d'),
            'time'       => date('H:i:s'),
            'username'   => $username,
            'role'       => $role,
            'linkref'    => $linkref,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'ip_address' => $this->ip(),
            'session_id' => session_id(),
            'action'     => $action,
        ], $this->parsePath($linkref ?? ''));

        $this->append($entry);
        $this->updateStats($entry);
    }

    private function parsePath(string $linkref): array
    {
        $info = [
            'institute_code' => null, 'institute_name' => null,
            'class_code'     => null, 'class_name'     => null,
            'subject'        => null, 'lesson_number'  => null, 'lesson_topic' => null,
        ];
        if (preg_match('#/eser/([a-z]+)/eser_([a-z]+\d+[sb]?)/#', $linkref, $m)) {
            $info['institute_code'] = $m[1];
            $info['institute_name'] = self::INSTITUTE_MAP[$m[1]] ?? 'Sconosciuto';
            if (preg_match('#([a-z]+)(\d+[sb]?)$#', $m[2], $cm)) {
                $info['class_code'] = $cm[2];
                $info['class_name'] = self::CLASS_MAP[$cm[2]] ?? 'Sconosciuta';
            }
            if (preg_match('#/([A-Z]+)/(\d+)_[A-Z]+-([^-]+)-#', $linkref, $lm)) {
                $info['subject']       = $lm[1];
                $info['lesson_number'] = $lm[2];
                $info['lesson_topic']  = $lm[3];
            }
        }
        return $info;
    }

    private function append(array $entry): void
    {
        $max  = (int)($_ENV['LOG_MAX_ENTRIES'] ?? 1000);
        $logs = json_decode(file_get_contents($this->logFile), true) ?: [];
        $logs[] = $entry;
        if (count($logs) > $max) {
            $logs = array_slice($logs, -$max);
        }
        file_put_contents($this->logFile, json_encode($logs, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function updateStats(array $e): void
    {
        $stats = json_decode(file_get_contents($this->statsFile), true) ?: [
            'daily_stats' => [], 'user_stats' => [], 'institute_stats' => [], 'class_stats' => [],
        ];
        $date     = $e['date'];
        $user     = $e['username'];
        $inst     = $e['institute_code'] ?? 'unknown';
        $cls      = $e['class_code']     ?? 'unknown';

        $stats['daily_stats'][$date] ??= ['total_accesses' => 0, 'unique_users' => []];
        $stats['daily_stats'][$date]['total_accesses']++;
        if (!in_array($user, $stats['daily_stats'][$date]['unique_users'], true)) {
            $stats['daily_stats'][$date]['unique_users'][] = $user;
        }

        $stats['user_stats'][$user] ??= [
            'total_accesses' => 0, 'first_access' => $e['timestamp'],
            'institutes_visited' => [], 'classes_visited' => [], 'subjects_accessed' => [],
        ];
        $stats['user_stats'][$user]['total_accesses']++;
        $stats['user_stats'][$user]['last_access'] = $e['timestamp'];

        file_put_contents($this->statsFile, json_encode($stats, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function appendDebug(string $msg): void
    {
        $file = Config::get('app.paths.logs') . '/debug.log';
        $line = sprintf(
            "[%s] %s ip=%s session=%s\n",
            date('d-M-Y H:i:s T'),
            $msg,
            $this->ip(),
            session_id()
        );
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private function ip(): string
    {
        return $_SERVER['HTTP_CLIENT_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'unknown';
    }

    public function recent(int $limit = 50): array
    {
        $logs = json_decode(file_get_contents($this->logFile), true) ?: [];
        $logs = array_filter($logs, fn($l) => ($l['action'] ?? '') !== 'logout');
        usort($logs, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
        return array_slice($logs, 0, $limit);
    }

    public function stats(string $type = 'all'): array
    {
        $s = json_decode(file_get_contents($this->statsFile), true) ?: [];
        return $type === 'all' ? $s : ($s[$type] ?? []);
    }
}
