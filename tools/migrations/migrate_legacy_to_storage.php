<?php
/**
 * migrate_legacy_to_storage.php — Phase 14.
 *
 * Migra le cartelle legacy (mappe, verifiche, lab, eser, strcomp_bes_altro)
 * al nuovo StorageProvider + metadata `storage_objects` in MySQL.
 *
 * Key scheme:
 *   institutes/{institute_id}/private/{teacher_id}/{category}/{rel_path}
 *
 * Idempotente: file già presente in storage_objects con stesso checksum
 * viene skippato. Cambia checksum → upsert con version++ (non ancora
 * implementato, per ora version=1).
 *
 * Dry-run di default. Per applicare:
 *   php tools/migrations/migrate_legacy_to_storage.php --apply
 *
 * Opzioni:
 *   --owner=<username>     (default: superadmin)
 *   --institute=<code>     (default: MIUR-ESEMPIO-COMUNE ESEMPIO-SCI)
 *   --folders=mappe,eser   (subset; default: tutte)
 *   --limit=N              (stop dopo N file, per testing)
 *   --quiet                (meno output)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\StorageObjectRepository;
use App\Support\Storage\StorageFactory;

// ─── Arg parse ─────────────────────────────────────────
$opts = [
    'apply'     => false,
    'owner'     => 'superadmin',
    'institute' => 'MIUR-ESEMPIO-COMUNE ESEMPIO-SCI',
    'folders'   => ['mappe', 'verifiche', 'lab', 'eser', 'strcomp_bes_altro'],
    'limit'     => 0,
    'quiet'     => false,
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply')  { $opts['apply']  = true;  continue; }
    if ($arg === '--quiet')  { $opts['quiet']  = true;  continue; }
    if (str_starts_with($arg, '--owner='))     { $opts['owner']     = substr($arg, 8);                 continue; }
    if (str_starts_with($arg, '--institute=')) { $opts['institute'] = substr($arg, 12);                continue; }
    if (str_starts_with($arg, '--folders='))   { $opts['folders']   = explode(',', substr($arg, 10));  continue; }
    if (str_starts_with($arg, '--limit='))     { $opts['limit']     = (int)substr($arg, 8);            continue; }
    fwrite(STDERR, "Arg ignoto: $arg\n"); exit(1);
}

$log = function (string $msg) use ($opts) {
    if (!$opts['quiet']) echo $msg, "\n";
};

// ─── Precondizioni ─────────────────────────────────────
if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile.\n");
    exit(1);
}
$pdo = Database::connection();

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$opts['owner']]);
$ownerId = (int)($stmt->fetchColumn() ?: 0);
if ($ownerId === 0) {
    fwrite(STDERR, "Owner '{$opts['owner']}' non trovato. Esegui prima seed_super_admin.\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT id FROM institutes WHERE code = ? LIMIT 1');
$stmt->execute([$opts['institute']]);
$instId = (int)($stmt->fetchColumn() ?: 0);
if ($instId === 0) {
    fwrite(STDERR, "Institute '{$opts['institute']}' non trovato.\n");
    exit(1);
}

$base    = (string)Config::get('app.paths.base');
$provider = StorageFactory::default();
$repo     = new StorageObjectRepository();

$log(($opts['apply'] ? '[APPLY]' : '[DRY-RUN]')
   . " owner=$ownerId institute=$instId provider=" . $provider->name());

// ─── Scan + migrate ────────────────────────────────────
$stats = [
    'scanned'    => 0,
    'migrated'   => 0,
    'skipped'    => 0,
    'skipped_php'=> 0,
    'errors'     => 0,
    'bytes'      => 0,
];

$categoryMap = [
    'mappe'             => 'mappe',
    'verifiche'         => 'verifiche',
    'lab'               => 'lab',
    'eser'              => 'eser',
    'strcomp_bes_altro' => 'bes',
];

foreach ($opts['folders'] as $folder) {
    $folder = trim((string)$folder);
    if ($folder === '') continue;
    $srcDir = $base . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($srcDir)) {
        $log("  $folder: (cartella non presente, skip)");
        continue;
    }
    $category = $categoryMap[$folder] ?? $folder;
    $log("  $folder → category=$category");

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($stats['migrated'] + $stats['skipped'] >= $opts['limit'] && $opts['limit'] > 0) break 2;
        if (!$file->isFile()) continue;
        // Phase 14 — esclusione PHP: pagine legacy eseguibili restano su
        // filesystem; saranno riscritte in teacher_content (fase separata).
        $ext = strtolower(pathinfo($file->getPathname(), PATHINFO_EXTENSION));
        if ($ext === 'php') { $stats['skipped_php'] = ($stats['skipped_php'] ?? 0) + 1; continue; }
        $stats['scanned']++;

        $relFromRoot = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($srcDir))), '/');
        $key = sprintf(
            'institutes/%d/private/%d/%s/%s',
            $instId, $ownerId, $category, $relFromRoot,
        );
        try {
            if ($repo->exists($provider->name(), $key)) {
                $stats['skipped']++;
                continue;
            }
            $bytes = @file_get_contents($file->getPathname());
            if ($bytes === false) throw new RuntimeException('read_failed');
            $mime = @mime_content_type($file->getPathname()) ?: null;

            if ($opts['apply']) {
                $res = $provider->put($key, $bytes, $mime ?? 'application/octet-stream');
                $repo->upsert([
                    'provider'      => $res->provider,
                    'storage_key'   => $res->key,
                    'checksum'      => $res->checksum,
                    'size_bytes'    => $res->size,
                    'mime'          => $mime,
                    'visibility'    => 'private',
                    'owner_user_id' => $ownerId,
                    'institute_id'  => $instId,
                    'version'       => 1,
                ]);
            }
            $stats['migrated']++;
            $stats['bytes'] += strlen($bytes);
            if (!$opts['quiet'] && $stats['migrated'] % 100 === 0) {
                $log(sprintf('    … %d file migrati (%s MB)', $stats['migrated'], round($stats['bytes']/1024/1024, 1)));
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            fwrite(STDERR, "  [ERR] $relFromRoot → " . $e->getMessage() . "\n");
        }
    }
}

// ─── Report ────────────────────────────────────────────
$log('');
$log('─── report ───');
$log(sprintf('  scanned     : %d', $stats['scanned']));
$log(sprintf('  migrated    : %d', $stats['migrated']));
$log(sprintf('  skipped     : %d (già presenti)', $stats['skipped']));
$log(sprintf('  skipped_php : %d (restano su filesystem)', $stats['skipped_php']));
$log(sprintf('  errors      : %d', $stats['errors']));
$log(sprintf('  bytes       : %s MB', round($stats['bytes']/1024/1024, 2)));
$log('');
if (!$opts['apply']) {
    $log('Nessuna scrittura effettuata. Riesegui con --apply per applicare.');
}
exit($stats['errors'] > 0 ? 2 : 0);
