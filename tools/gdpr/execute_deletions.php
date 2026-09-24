<?php

declare(strict_types=1);

/**
 * Esegue le richieste di cancellazione (art. 17 GDPR) giunte a fine
 * cooling-off: crypto-shredding e anonimizzazione dell'account.
 *
 * PERCHE' (2026-09-04)
 *   DeletionRequestService::executeOverdue() esiste da aprile e il suo
 *   docblock parla di un "cron job" che lo chiama. Non lo chiamava nessuno:
 *   nessun timer, nessuna riga di cron. Una richiesta confermata restava in
 *   `cooling_off` per sempre, e l'informativa prometteva "30 giorni di
 *   cooling-off, poi crypto-shredding". Questo file e il timer
 *   tools/systemd/pantedu-gdpr-deletions.timer chiudono la promessa.
 *
 * USO
 *   php tools/gdpr/execute_deletions.php           # dry-run: elenca le richieste dovute
 *   php tools/gdpr/execute_deletions.php --apply   # esegue
 *
 * Dopo un ripristino da backup va rilanciato a mano:
 * docs/security/operations/restore-reerasure.md
 */

require __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Services\Gdpr\DeletionRequestService;

$apply = in_array('--apply', $argv, true);

if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile.\n");
    exit(1);
}

$due = Database::connection()->query(
    "SELECT id, user_id, execute_after FROM deletion_requests
      WHERE status = 'cooling_off' AND execute_after <= NOW()
      ORDER BY execute_after"
)->fetchAll(PDO::FETCH_ASSOC);

printf("%s — richieste a fine cooling-off: %d\n", $apply ? 'APPLY' : 'DRY-RUN', count($due));
foreach ($due as $r) {
    printf("  #%-5d user_id=%-6d dovuta dal %s\n", $r['id'], $r['user_id'], $r['execute_after']);
}
if (!$apply) {
    if ($due !== []) {
        echo "Per eseguire: php tools/gdpr/execute_deletions.php --apply\n";
    }
    exit(0);
}

$stats = (new DeletionRequestService())->executeOverdue();
printf("Eseguite %d, fallite %d.\n", $stats['succeeded'], $stats['failed']);
foreach ($stats['errors'] as $e) {
    echo "  ERRORE: $e\n";
}
exit($stats['failed'] > 0 ? 1 : 0);
