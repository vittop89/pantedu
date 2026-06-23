<?php
// Legacy cron entrypoint — clears /temp + /verifiche/temp.
// Allowed only from CLI or localhost.
$isCli       = PHP_SAPI === 'cli';
$isLocalhost = isset($_SERVER['REMOTE_ADDR']) && in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'], true);

if (!$isCli && !$isLocalhost) {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\FileService;

$svc     = new FileService();
$removed = $svc->clearRootContents('temp') + $svc->clearRootContents('verifiche_temp');
echo "cleared=$removed";
