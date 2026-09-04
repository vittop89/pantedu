<?php
/**
 * Phase 18 — Fase 2: import users da JSON legacy → DB `users`.
 *
 * Sorgenti (precedenza prima→ultima, first-wins per username):
 *   1. log/data/admin_users.json            → role=administrator, no institute
 *   2. log/data/collaborators.json          → role=collaborator, no institute
 *   3. storage/data/users.json              → registered users (role dal record)
 *   4. storage/objects/institutes/{iid}/private/{tid}/eser/{ind}/eser_{code}/users/users.json
 *      → students, institute_id=iid (inferred dal path)
 *
 * Idempotente: ON DUPLICATE KEY UPDATE su username.
 *
 * Run:
 *   php tools/import_legacy_users_to_db.php              # dry-run
 *   php tools/import_legacy_users_to_db.php --apply      # commit
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

if (!Config::get('database.enabled')) {
    fwrite(STDERR, "DB_ENABLED=false.\n");
    exit(1);
}

$apply = in_array('--apply', $argv, true);
$root  = dirname(__DIR__);
$pdo   = Database::connection();

$paths = Config::get('auth.paths');

/**
 * @return array<string, array{source:string, row:array, institute_id:?int}>
 */
$collect = static function () use ($paths, $root): array {
    $out = [];
    $remember = static function (string $u, string $source, array $row, ?int $iid) use (&$out): void {
        if ($u === '') return;
        if (isset($out[$u])) return;
        $out[$u] = ['source' => $source, 'row' => $row, 'institute_id' => $iid];
    };

    // 1. admin_users.json
    if (is_file($paths['admin_users'])) {
        $d = json_decode((string)file_get_contents($paths['admin_users']), true) ?? [];
        foreach (($d['users'] ?? []) as $u => $e) {
            $e['role'] = $e['role'] ?? 'administrator';
            $remember((string)$u, 'admin_users', $e, null);
        }
    }

    // 2. collaborators.json (active only)
    if (is_file($paths['collaborators'])) {
        $d = json_decode((string)file_get_contents($paths['collaborators']), true) ?? [];
        foreach (($d['active_users'] ?? []) as $u => $e) {
            if (($e['status'] ?? '') !== 'active') continue;
            $e['role']    = $e['role']    ?? 'collaborator';
            $e['created'] = $e['created'] ?? ($e['created_date'] ?? null);
            $remember((string)$u, 'collaborators', $e, null);
        }
    }

    // 3. storage/data/users.json (self-registered approved)
    if (!empty($paths['registered_users']) && is_file($paths['registered_users'])) {
        $d = json_decode((string)file_get_contents($paths['registered_users']), true) ?? [];
        foreach (($d['users'] ?? []) as $e) {
            $u = (string)($e['username'] ?? '');
            if (($e['status'] ?? '') !== 'approved') continue;
            $remember($u, 'registered', $e, null);
        }
    }

    // 4. storage_objects institute per-section students
    $glob = $root . '/storage/objects/institutes/*/private/*/eser/*/eser_*/users/users.json';
    foreach (glob($glob) ?: [] as $f) {
        // extract institute id from path
        if (preg_match('#/institutes/(\d+)/private/(\d+)/#', str_replace('\\', '/', $f), $m)) {
            $iid = (int)$m[1];
        } else $iid = null;
        $d = json_decode((string)file_get_contents($f), true) ?? [];
        foreach (($d['users'] ?? []) as $u => $e) {
            $e['role'] = $e['role'] ?? 'student';
            $remember((string)$u, 'section:' . ($iid ?? 'null'), $e, $iid);
        }
    }

    return $out;
};

$users = $collect();
echo "Collected " . count($users) . " users dai JSON legacy.\n";
echo str_repeat('-', 80) . "\n";

$perSource = [];
foreach ($users as $u => $meta) {
    $s = explode(':', $meta['source'])[0];
    $perSource[$s] = ($perSource[$s] ?? 0) + 1;
}
foreach ($perSource as $s => $n) echo sprintf("  %-15s %d\n", $s, $n);

if (!$apply) {
    echo "\nDRY-RUN. Per applicare: php tools/import_legacy_users_to_db.php --apply\n";
    exit(0);
}

$upsert = $pdo->prepare(
    'INSERT INTO users
         (username, role, first_name, last_name, email, password_hash, status, active, institute_id, created_at, approved_at, approved_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
         role          = IF(users.role IN (\'administrator\',\'teacher\'), users.role, VALUES(role)),
         first_name    = COALESCE(NULLIF(VALUES(first_name), \'\'), users.first_name),
         last_name     = COALESCE(NULLIF(VALUES(last_name),  \'\'), users.last_name),
         email         = COALESCE(NULLIF(VALUES(email),      \'\'), users.email),
         password_hash = COALESCE(NULLIF(VALUES(password_hash), \'\'), users.password_hash),
         status        = COALESCE(NULLIF(VALUES(status),     \'\'), users.status),
         active        = VALUES(active),
         institute_id  = COALESCE(users.institute_id, VALUES(institute_id))'
);

$pdo->beginTransaction();
try {
    $n = 0;
    foreach ($users as $username => $meta) {
        $e   = $meta['row'];
        $iid = $meta['institute_id'];
        $upsert->execute([
            $username,
            (string)($e['role'] ?? 'student'),
            (string)($e['first_name'] ?? ''),
            (string)($e['last_name']  ?? ''),
            (string)($e['email']      ?? ''),
            (string)($e['password_hash'] ?? ''),
            (string)($e['status']     ?? 'approved'),
            (int)   (!empty($e['active']) ? 1 : 0),
            $iid,
            (string)($e['created'] ?? $e['created_date'] ?? date('Y-m-d H:i:s')),
            $e['approved_at'] ?? null,
            $e['approved_by'] ?? null,
        ]);
        $n++;
    }
    $pdo->commit();
    echo "\nUpserted $n utenti.\n";
} catch (\Throwable $t) {
    $pdo->rollBack();
    fwrite(STDERR, "ERRORE: " . $t->getMessage() . "\n");
    exit(1);
}
