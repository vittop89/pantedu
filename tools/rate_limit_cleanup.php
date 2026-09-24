<?php

/**
 * Pulizia della tabella `rate_limits`: toglie i colpi più vecchi di un'ora
 * (RateLimitStore::CONSERVAZIONE_SECONDI).
 *
 * La tabella conserva l'IP in chiaro di chi passa dal limitatore, compresi gli
 * studenti che entrano con la credenziale di classe. Registro dei trattamenti
 * (B.6) e DPIA dichiarano una pulizia giornaliera.
 *
 * Uso:
 *   php tools/rate_limit_cleanup.php               # righe più vecchie di un'ora
 *   php tools/rate_limit_cleanup.php --older=300   # più vecchie di cinque minuti
 *
 * Lo lancia `pantedu-rate-limit-cleanup.timer`, una volta al giorno
 * (tools/systemd, da installare a mano: docs/dev/ci-cd.md).
 *
 * 23/9/2026 — fino a oggi l'intestazione suggeriva un cron che nessuno aveva
 * creato: nessuna unità o workflow del repository lanciava lo script (e il
 * crontab di root, in produzione, è vuoto dal 9/9/2026), quindi gli IP si
 * accumulavano senza termine (A-84 e DOC-30 della revisione del 23/9). E senza
 * database lo script diceva «Removed 0 rows» e usciva 0: una pulizia che non
 * aveva guardato niente sembrava una pulizia riuscita. Adesso esce 1 e dice
 * perché, così l'unità va in `failed` e parte l'avviso (OnFailure=).
 * Prova: tests/Unit/PuliziaDelLimitatoreTest.php.
 *
 * Uscita: 0 se la pulizia è avvenuta (anche con zero righe tolte), 1 se no.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Services\RateLimitStore;

if (!Config::get('database.enabled')) {
    fwrite(STDERR, "DB_ENABLED è falso: rate_limits non è stata pulita.\n");
    exit(1);
}

$older = RateLimitStore::CONSERVAZIONE_SECONDI;
foreach ($argv as $a) {
    if (\preg_match('/^--older=(\d+)$/', $a, $m)) {
        $older = (int)$m[1];
    }
}

try {
    $removed = RateLimitStore::purgeDb($older);
} catch (\Throwable $e) {
    fwrite(STDERR, 'La pulizia di rate_limits non è riuscita: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "Removed $removed rate_limits rows older than {$older}s.\n";
