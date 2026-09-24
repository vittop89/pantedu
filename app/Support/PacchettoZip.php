<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Un pacchetto ZIP che non resta su disco.
 *
 * 21/9/2026 — le esportazioni dei modelli e dei documenti costruivano
 * l'archivio come file vero in `<dati>/storage/risdoc-tmp/`, rispondevano con
 * l'indirizzo da cui scaricarlo, e lasciavano lì il file finché qualcuno non
 * passava a cancellarlo. Funzionava, ma comprava tre cose che non servivano:
 * una cartella da sorvegliare, una promessa da mantenere sulla sua durata, e
 * una rotta che consegnava il pacchetto a *un* docente qualunque invece che al
 * suo proprietario.
 *
 * Il modo più economico di mantenere una promessa su un file temporaneo è non
 * lasciarlo: qui l'archivio nasce in un file privato della richiesta, viene
 * letto e **cancellato subito**, anche quando qualcosa va storto a metà. Quello
 * che torna sono i byte, che il chiamante consegna nel corpo della risposta.
 *
 * `ZipArchive` vuole un percorso e non sa costruire in memoria: il file
 * temporaneo è obbligato, ma la sua vita è quella della richiesta. Misurato il
 * 21/9/2026: il pacchetto più grosso che esiste oggi è di 40 KB — il pezzo più
 * pesante non è il testo del docente ma lo stemma della Repubblica, 27 KB, che
 * entra in ogni pacchetto.
 *
 * Lo stesso mestiere lo fanno già `SelfServiceController::exportData` e
 * `VerificaController::zipExport`: questa classe è quella ricetta, in un posto
 * solo e con la cancellazione garantita.
 */
final class PacchettoZip
{
    /**
     * Costruisce l'archivio e ne restituisce i byte, senza lasciare niente.
     *
     * @param callable(ZipArchive): void $riempi ci mette dentro i file
     * @throws RuntimeException se l'archivio non si può aprire o rileggere
     */
    public static function byte(callable $riempi, string $prefisso = 'pantedu_'): string
    {
        $percorso = tempnam(sys_get_temp_dir(), $prefisso);
        if ($percorso === false) {
            throw new RuntimeException('non riesco a creare il file temporaneo del pacchetto');
        }

        try {
            $zip = new ZipArchive();
            // OVERWRITE e non CREATE: `tempnam` il file l'ha già fatto, vuoto, e
            // senza questa opzione ZipArchive lo rifiuta perché non è un archivio.
            if ($zip->open($percorso, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('non riesco ad aprire il pacchetto');
            }

            try {
                $riempi($zip);
            } catch (Throwable $e) {
                // Chiudere prima di risollevare: un archivio lasciato aperto
                // tiene il descrittore, e su Windows il file non si cancella.
                $zip->close();
                throw $e;
            }

            $zip->close();

            $byte = @file_get_contents($percorso);
            if ($byte === false) {
                throw new RuntimeException('non riesco a rileggere il pacchetto appena costruito');
            }
            return $byte;
        } finally {
            // Il punto di tutta la classe: qualunque cosa succeda sopra, qui non
            // resta niente. Senza questo `finally` un'eccezione a metà
            // costruzione sposterebbe lo scaffale invece di toglierlo — e in una
            // cartella che nessuna pulizia guarda.
            @unlink($percorso);
        }
    }

    /**
     * Le intestazioni con cui si consegna un pacchetto nel corpo della risposta.
     *
     * `Content-Length` c'è perché nginx non debba indovinarla e il browser possa
     * mostrare l'avanzamento; `no-store` perché un documento di scuola non
     * resti nella cache di un computer condiviso.
     *
     * @return array<string,string>
     */
    public static function intestazioni(string $nomeFile, string $byte): array
    {
        return [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . self::nomeSicuro($nomeFile) . '"',
            'Content-Length'      => (string)strlen($byte),
            'Cache-Control'       => 'private, no-store',
        ];
    }

    /**
     * Un nome di file che si può scrivere in un'intestazione HTTP.
     *
     * Le intestazioni parlano ISO-8859-1 e non accettano virgolette né a capo:
     * le lettere accentate diventano la loro versione semplice («relazione
     * finale 3ª» → «relazione_finale_3a.zip»), il resto sparisce. Stessa
     * traduzione che il progetto usa già per i nomi dei file delle verifiche.
     */
    public static function nomeSicuro(string $nome): string
    {
        $senzaAccenti = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome);
        if ($senzaAccenti === false) {
            $senzaAccenti = $nome;
        }
        $pulito = preg_replace('/[^A-Za-z0-9._-]+/', '_', $senzaAccenti) ?? '';
        $pulito = trim($pulito, '_');
        if ($pulito === '' || $pulito === '.zip') {
            $pulito = 'pacchetto.zip';
        }
        return str_ends_with($pulito, '.zip') ? $pulito : $pulito . '.zip';
    }
}
