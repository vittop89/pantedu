<?php

/**
 * Cambia la chiave master (KMS_MASTER_KEY) senza perdere i dati: riavvolge
 * con la chiave nuova le KEK dei docenti e le loro chiavi di recupero, le
 * due sole cose cifrate con la master (23/9/2026, A-88).
 *
 * LE CHIAVI
 *   La vecchia da KMS_MASTER_KEY, la nuova da KMS_MASTER_KEY_NEW, lette come
 *   le legge l'applicazione: .env, poi .env.local che vince. Rifiuta chiavi
 *   uguali, assenti o che non sono esattamente 64 caratteri esadecimali:
 *   basta un a capo in fondo per il rifiuto.
 *
 * I MODI
 *   --dry-run  (predefinito) conta e controlla che tutto si apra con la
 *              vecchia; non scrive niente
 *   --apply    ricifra con la nuova, IV nuovo, una transazione per docente;
 *              quello che si apre già con la nuova si salta, quindi un giro
 *              interrotto si ripete e basta
 *   --verify   controlla che tutto si apra con la nuova; elenca per id quello
 *              che si apre ancora solo con la vecchia
 *   --docenti=ID,ID  limita il giro; senza, lavora su tutti i docenti
 *
 * Stampa solo conteggi e id, e confronta gli elementi esaminati con le righe
 * che il database conta. Esce con 0 se è tutto a posto, 1 se qualcosa non si
 * apre o se il giro non ha esaminato tutte le righe, 2 per un rifiuto o un
 * giro interrotto.
 *
 * La procedura intera — salvataggio prima, sito fermo, scambio delle chiavi,
 * copie fuori linea, firme degli export già emessi — sta in
 * docs/security/operations/kms-recovery.md. Eseguirla in produzione è una
 * decisione del manutentore.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Crypto\ChiaveMadre;
use App\Services\Crypto\ComandoDiRiavvolgimento;
use Dotenv\Dotenv;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Solo da riga di comando.\n");
    exit(2);
}

// Come app/bootstrap.php, senza la sessione: la chiave vecchia deve essere
// esattamente quella con cui gira l'applicazione.
$base = dirname(__DIR__, 2);
if (is_file($base . '/.env')) {
    Dotenv::createImmutable($base)->safeLoad();
}
if (is_file($base . '/.env.local')) {
    Dotenv::createMutable($base, '.env.local')->safeLoad();
}
Config::load($base . '/app/Config');

exit(ComandoDiRiavvolgimento::esegui(
    array_values(array_slice($argv, 1)),
    [
        // Tutte e due lette e validate da ChiaveMadre, l'unica regola (A-36).
        'KMS_MASTER_KEY'     => ChiaveMadre::dallAmbiente(),
        'KMS_MASTER_KEY_NEW' => ChiaveMadre::dallaVariabile('KMS_MASTER_KEY_NEW'),
    ],
    static fn (): PDO => Database::connection(),
    STDOUT,
    STDERR
));
