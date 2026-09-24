<?php

declare(strict_types=1);

/**
 * Prepara gli utenti di test per la suite Playwright (2026-09-05).
 *
 *   php tools/dev/e2e_prepare_users.php [username ...]
 *
 * Per ogni utente (default: docente.uno, docente.due, admin) registra
 * l'accettazione dei Termini di servizio e dell'AUP nella versione corrente,
 * se manca: il gate TosAcceptanceMiddleware risponde 302 /tos-acceptance a
 * OGNI richiesta di chi non ha accettato, e una spec che entra come quell'utente
 * legge una pagina HTML dove si aspetta JSON. Lo chiama tests/e2e/global-setup.js;
 * e' idempotente e non tocca chi ha gia' accettato.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Services\Gdpr\TosAcceptanceService;

$users = array_slice($argv, 1) ?: ['docente.uno', 'docente.due', 'admin'];
if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile\n");
    exit(1);
}
$pdo = Database::connection();
$tos = new TosAcceptanceService($pdo);
$st  = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$out = [];
foreach ($users as $username) {
    $st->execute([$username]);
    $id = (int) $st->fetchColumn();
    if ($id <= 0) {
        $out[$username] = 'assente';
        continue;
    }
    if ($tos->hasAccepted($id)) {
        $out[$username] = 'tos ok';
        continue;
    }
    $out[$username] = $tos->recordAcceptance($id, '127.0.0.1', 'playwright e2e (tools/dev/e2e_prepare_users.php)')
        ? 'tos accettati ora'
        : 'tos NON registrati';
}
echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
