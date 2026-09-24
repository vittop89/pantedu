<?php

declare(strict_types=1);

namespace App\Services\Drive;

use App\Services\Maps\MapBlobStore;
use PDO;
use Throwable;

/**
 * Recupera da Drive le mappe che hanno la riga ma non il file cifrato.
 *
 * Il 15/9/2026 in produzione 11 mappe avevano `map_blob_path` senza il file
 * (in nessuna delle due alberature), e un `map_drive_id` vero: la copia che la
 * sincronizzazione aveva mandato su Drive in primavera, con il client OAuth di
 * allora. Quella copia è la sola rimasta.
 *
 * Il client di adesso ha il permesso `drive.file` e non vede i file creati da un
 * altro client: per scaricarli serve il consenso di migrazione
 * (`/teacher/drive/connect-migration`, aggiunge `drive.readonly`), come per lo
 * strumento G6. Il controllo del permesso lo fa il comando
 * (tools/migrations/recupera_mappe_da_drive.php); qui c'è la regola.
 *
 * Una mappa recuperata riceve un blob nuovo e `map_origin = drive_legacy`: la
 * sincronizzazione successiva la tratta come prima volta e crea il file nella
 * cartella dell'app, senza provare ad aggiornare quello vecchio, che il
 * permesso `drive.file` non consente di modificare.
 */
final class RecuperoMappeDaDrive
{
    public const RECUPERATA      = 'recuperata';
    public const DA_RECUPERARE   = 'da_recuperare';
    public const NON_SU_DRIVE    = 'non_su_drive';
    public const NON_RICONOSCIUTA = 'non_riconosciuta';
    public const ERRORE          = 'errore';

    private PDO $pdo;
    private MapBlobStore $blob;
    /** @var \Closure(int, string): string */
    private \Closure $scarica;

    /**
     * Proprietà scritte per esteso, non promosse `readonly` nel costruttore:
     * semgrep non legge quella forma, e il file resterebbe fuori dalle sue regole
     * (tools/ci/cancello-semgrep.mjs, tetto dei file non letti per intero).
     *
     * @param \Closure(int, string): string $scarica il contenuto del file su Drive (docente, id del file)
     */
    public function __construct(PDO $pdo, MapBlobStore $blob, \Closure $scarica)
    {
        $this->pdo = $pdo;
        $this->blob = $blob;
        $this->scarica = $scarica;
    }

    /**
     * Le mappe con la riga, un identificativo Drive vero e nessun file.
     *
     * Non sono candidate: `blob_orphan` (segnaposto della sincronizzazione) e
     * gli `id-…` dell'import di primavera, che non sono mai stati su Drive.
     *
     * @return list<array{id:int, teacher_id:int, title:string, map_blob_path:string, map_drive_id:string}>
     */
    public function candidate(?int $docente = null): array
    {
        $sql = 'SELECT id, teacher_id, title, map_blob_path, map_drive_id
                FROM teacher_content_data
                WHERE content_subtype = "mappa"
                  AND map_blob_path IS NOT NULL AND map_blob_path <> ""
                  AND map_drive_id IS NOT NULL AND map_drive_id <> ""
                  AND map_drive_id <> "blob_orphan" AND map_drive_id NOT LIKE "id-%"';
        $parametri = [];
        if ($docente !== null) {
            $sql .= ' AND teacher_id = ?';
            $parametri[] = $docente;
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY teacher_id, id');
        $st->execute($parametri);

        $candidate = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $riga) {
            try {
                if ($this->blob->exists((string)$riga['map_blob_path'])) {
                    continue;
                }
            } catch (Throwable) {
                // Un percorso malformato non è un file che c'è.
            }
            $candidate[] = [
                'id'            => (int)$riga['id'],
                'teacher_id'    => (int)$riga['teacher_id'],
                'title'         => (string)$riga['title'],
                'map_blob_path' => (string)$riga['map_blob_path'],
                'map_drive_id'  => (string)$riga['map_drive_id'],
            ];
        }
        return $candidate;
    }

    /**
     * Scarica la copia e, se `$applica`, la rimette cifrata al posto del file
     * mancante. Senza `$applica` scarica e basta: dice che cosa farebbe.
     *
     * @param array{id:int, teacher_id:int, title:string, map_blob_path:string, map_drive_id:string} $mappa
     * @return array{esito:string, byte?:int, tipo?:string, motivo?:string}
     */
    public function recupera(array $mappa, bool $applica): array
    {
        try {
            $contenuto = ($this->scarica)($mappa['teacher_id'], $mappa['map_drive_id']);
        } catch (Throwable $e) {
            $messaggio = $e->getMessage();
            $assente = str_contains($messaggio, '404') || str_contains($messaggio, 'notFound')
                || str_contains($messaggio, 'File not found');
            return ['esito' => $assente ? self::NON_SU_DRIVE : self::ERRORE, 'motivo' => $messaggio];
        }

        $tipo = MapSyncService::tipoDelContenuto('', $contenuto);
        if ($contenuto === '' || $tipo === MapSyncService::TIPO_SCONOSCIUTO) {
            return ['esito' => self::NON_RICONOSCIUTA, 'byte' => strlen($contenuto)];
        }
        if (!$applica) {
            return ['esito' => self::DA_RECUPERARE, 'byte' => strlen($contenuto), 'tipo' => $tipo];
        }

        $percorso = $this->blob->put($mappa['teacher_id'], $contenuto);
        $st = $this->pdo->prepare(
            'UPDATE teacher_content_data
             SET map_blob_path = ?, map_mime = ?, map_size = ?, map_origin = "drive_legacy", updated_at = NOW()
             WHERE id = ? AND teacher_id = ? AND map_blob_path = ?'
        );
        $st->execute([
            $percorso, $tipo, strlen($contenuto),
            $mappa['id'], $mappa['teacher_id'], $mappa['map_blob_path'],
        ]);
        if ($st->rowCount() !== 1) {
            // La riga è cambiata nel frattempo: il blob appena scritto non serve.
            $this->blob->delete($percorso);
            return ['esito' => self::ERRORE, 'motivo' => 'la riga è cambiata durante il recupero'];
        }
        return ['esito' => self::RECUPERATA, 'byte' => strlen($contenuto), 'tipo' => $tipo];
    }
}
