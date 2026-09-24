<?php

declare(strict_types=1);

namespace App\Services\Maps;

use PDO;
use Throwable;

/**
 * Sovrascrive il disegno di una mappa con il controllo di versione.
 *
 * È il salvataggio dell'editor (POST /api/maps/{id}/update), tolto dal
 * controller il 19/9/2026 perché lo usa anche lo strumento che rimette le
 * lettere accentate (tools/maps/ripristina_caratteri.php): così l'editor e lo
 * strumento scrivono allo stesso modo, e un editor aperto si accorge della
 * scrittura dello strumento con il 409 di sempre.
 *
 * L'ordine conta. Prima si blocca la riga e si alza la versione, **poi** si
 * scrive il file. Fino al 19/9 era il contrario: il file si scriveva prima
 * del controllo, e chi perdeva la gara riceveva il 409 dopo aver già
 * sovrascritto il file di chi l'aveva vinta. Ora chi arriva secondo aspetta il
 * blocco della riga, trova la versione cambiata e non scrive niente.
 *
 * Il file si riscrive sullo stesso ULID (MapBlobStore::put, scrittura atomica
 * con rename). Se è la `put()` a fallire, la transazione torna indietro e il
 * file è ancora quello di prima.
 *
 * Quello che l'ordine non chiude, e che è bene sapere invece di crederlo
 * atomico: il file si scrive dentro la transazione, il commit viene dopo. Se
 * a fallire è il commit — database riavviato, `wait_timeout` scaduto fra le
 * due — il disegno nuovo è già sul disco e la riga torna indietro: `map_version`
 * e `map_size` restano quelli di prima e al chiamante arriva un'eccezione
 * (l'editor mostra «update_failed» mentre ricaricando la pagina si vede il
 * disegno nuovo). È una finestra stretta e il rimedio è il salvataggio
 * successivo, che riallinea riga e file. L'ordine inverso sarebbe peggio: chi
 * perde la gara di versione sovrascriverebbe il lavoro di chi l'ha vinta, che
 * è il guasto per cui questa classe esiste. Chi vuole accorgersi della finestra
 * rilegge il blob e confronta lo sha256, come fa RipristinoCaratteriMappe.
 *
 * Non è `final` di proposito: la prova dello strumento
 * (tests/Integration/RipristinoCaratteriMappeTest.php) la estende per far
 * salvare l'«editor» fra la lettura e la scrittura.
 */
class SalvataggioMappa
{
    public const SALVATA             = 'salvata';
    public const CONFLITTO           = 'conflitto';
    public const NON_TROVATA         = 'non_trovata';
    public const PERCORSO_NON_VALIDO = 'percorso_non_valido';

    private PDO $pdo;
    private MapBlobStore $blob;

    public function __construct(PDO $pdo, MapBlobStore $blob)
    {
        $this->pdo = $pdo;
        $this->blob = $blob;
    }

    /**
     * Scrive `$xml` al posto del disegno della mappa `$id` del docente
     * `$teacherId`, se la versione sul server è ancora `$versioneAttesa`.
     *
     * Se chi chiama ha già una transazione aperta, la usa e non la chiude: in
     * quel caso, se la scrittura del file fallisce, tornare indietro tocca a lui.
     *
     * @return array{esito:string, versione?:int, versione_server?:int, byte?:int}
     */
    public function sovrascrivi(int $id, int $teacherId, string $xml, int $versioneAttesa): array
    {
        $propria = !$this->pdo->inTransaction();
        if ($propria) {
            $this->pdo->beginTransaction();
        }
        try {
            $st = $this->pdo->prepare(
                'SELECT map_blob_path, map_version FROM teacher_content_data
                 WHERE id = ? AND teacher_id = ? LIMIT 1 FOR UPDATE'
            );
            $st->execute([$id, $teacherId]);
            $riga = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($riga) || (string)($riga['map_blob_path'] ?? '') === '') {
                $this->annulla($propria);
                return ['esito' => self::NON_TROVATA];
            }
            $versione = (int)$riga['map_version'];
            if ($versione !== $versioneAttesa) {
                $this->annulla($propria);
                return ['esito' => self::CONFLITTO, 'versione_server' => $versione];
            }
            // Il percorso è "{teacher_id}/{ulid}.bin" e si riscrive lo stesso file.
            // Una cartella di un altro docente vorrebbe dire scrivere un file che
            // la riga non guarda: la modifica sparirebbe senza errori.
            if (
                preg_match('#^(\d+)/([0-9A-Z]{26})\.bin$#', (string)$riga['map_blob_path'], $m) !== 1
                || (int)$m[1] !== $teacherId
            ) {
                $this->annulla($propria);
                return ['esito' => self::PERCORSO_NON_VALIDO];
            }

            $upd = $this->pdo->prepare(
                'UPDATE teacher_content_data
                 SET map_size = ?, map_version = map_version + 1, updated_at = NOW()
                 WHERE id = ? AND teacher_id = ? AND map_version = ?'
            );
            $upd->execute([strlen($xml), $id, $teacherId, $versioneAttesa]);
            if ($upd->rowCount() === 0) {
                // Con la riga bloccata non dovrebbe succedere; se succede, non si scrive.
                $this->annulla($propria);
                return ['esito' => self::CONFLITTO, 'versione_server' => $versione];
            }

            $this->blob->put($teacherId, $xml, $m[2]);

            if ($propria) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            $this->annulla($propria);
            throw $e;
        }

        return ['esito' => self::SALVATA, 'versione' => $versioneAttesa + 1, 'byte' => strlen($xml)];
    }

    private function annulla(bool $propria): void
    {
        if ($propria && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
