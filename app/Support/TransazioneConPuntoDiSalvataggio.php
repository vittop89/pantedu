<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Una transazione che, dentro un'altra già aperta, è un punto di salvataggio
 * (24/9/2026).
 *
 * `PdoTransactionRunner`, dentro una transazione già aperta, esegue il lavoro
 * senza niente attorno: se il lavoro fallisce a metà, ciò che ha scritto resta
 * nella transazione di fuori, e chi la conferma conferma anche la metà. Qui,
 * dentro una transazione aperta, si apre un `SAVEPOINT`, e un fallimento torna
 * a quel punto; fuori da ogni transazione è `PdoTransactionRunner` e basta.
 * MariaDB e SQLite conoscono i punti di salvataggio.
 *
 * La usa l'approvazione delle iscrizioni (`RegistrationService::approve()`),
 * che deve essere tutta o niente anche quando la chiama chi tiene una
 * transazione sua: le prove d'integrazione, che la annullano alla fine per non
 * lasciare dati nel database.
 */
final class TransazioneConPuntoDiSalvataggio implements TransactionRunner
{
    /** Un nome per ogni punto aperto: due annidati non si confondono. */
    private static int $progressivo = 0;

    // Senza `readonly`, né promosso né dichiarato: semgrep (immagine della CI)
    // non legge i file che lo usano (tools/ci/semgrep-censimento.json).
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function run(callable $work): mixed
    {
        if (!$this->pdo->inTransaction()) {
            return (new PdoTransactionRunner($this->pdo))->run($work);
        }

        $punto = 'punto_' . ++self::$progressivo;
        $this->pdo->exec('SAVEPOINT ' . $punto);
        try {
            $esito = $work();
        } catch (Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $punto);
            } catch (Throwable $annullamento) {
                error_log('[TransazioneConPuntoDiSalvataggio] ritorno a ' . $punto
                    . ' fallito: ' . $annullamento->getMessage());
            }
            throw $e;
        }
        $this->pdo->exec('RELEASE SAVEPOINT ' . $punto);

        return $esito;
    }
}
