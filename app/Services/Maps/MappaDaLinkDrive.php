<?php

declare(strict_types=1);

namespace App\Services\Maps;

/**
 * Una mappa drawio da un link pubblico di Google Drive (15/9/2026).
 *
 * PERCHÉ
 *   Nel modale di creazione «🔗 Link esterno» accetta un link a un drawio su
 *   Drive, anche nella forma che produce diagrams.net con «Pubblica link»
 *   (`viewer.diagrams.net/?…#U<indirizzo di Drive>`). Il contenuto nasceva con il
 *   solo link, e la pagina di studio, che dal G7 mostra le mappe dal file
 *   cifrato sul server (ADR-009), diceva «Mappa non disponibile localmente
 *   (orphan)». Segnalato dall'utente con una mappa caricata su Drive.
 *
 *   Il file si scarica una volta, alla creazione, e si salva come le altre
 *   mappe: da lì la pagina lo mostra dal server, senza passare dal proxy di
 *   diagrams.net, e il docente lo modifica nell'editor. Il link resta nei
 *   metadati come provenienza; le modifiche fatte dopo su Drive non arrivano.
 *
 * SICUREZZA
 *   L'indirizzo da scaricare lo costruisce questa classe da un identificativo
 *   di file (`[A-Za-z0-9_-]`), sempre verso drive.google.com in HTTPS: il link
 *   del docente non diventa mai l'URL di una richiesta del server. I rimandi si
 *   seguono solo dentro i domini di Google, con un tetto di dimensione e di
 *   tempo, e si accetta solo un file drawio (`<mxfile` o `<mxGraphModel`).
 */
final class MappaDaLinkDrive
{
    /** Più di quanto serve a una mappa (quella dell'utente: 595 KB). */
    public const MAX_BYTES = 10 * 1024 * 1024;

    private const ID = '[A-Za-z0-9_-]{20,}';

    /** @var callable(string): array{status:int, body:string, host:string} */
    private $scarica;

    /**
     * @param (callable(string): array{status:int, body:string, host:string})|null $scarica
     *        chi fa la richiesta; di norma curl. Le prove lo sostituiscono.
     */
    public function __construct(?callable $scarica = null)
    {
        $this->scarica = $scarica ?? static fn(string $url): array => self::richiesta($url);
    }

    /**
     * L'identificativo del file su Drive, dalle forme di link che si incollano:
     *   - https://drive.google.com/file/d/ID/view?usp=sharing
     *   - https://drive.google.com/open?id=ID · …/uc?id=ID&export=download
     *   - https://drive.usercontent.google.com/download?id=ID
     *   - https://viewer.diagrams.net/?…#U<uno dei precedenti, codificato>
     *   - https://app.diagrams.net/#GID (file di Google Drive aperto in diagrams.net)
     * Null per ogni altro link: un sito, un PDF, un drawio non su Drive.
     */
    public static function idDalLink(string $href): ?string
    {
        $href = trim($href);
        $parti = parse_url($href);
        if (!\is_array($parti) || !\in_array(strtolower((string)($parti['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower((string)($parti['host'] ?? ''));
        $frammento = (string)($parti['fragment'] ?? '');

        if ($host === 'viewer.diagrams.net' || $host === 'app.diagrams.net' || $host === 'embed.diagrams.net') {
            if (str_starts_with($frammento, 'U')) {
                return self::idDalLink(rawurldecode(substr($frammento, 1)));
            }
            if (preg_match('/^G(' . self::ID . ')$/', rawurldecode($frammento), $m) === 1) {
                return $m[1];
            }
            parse_str((string)($parti['query'] ?? ''), $q);
            return isset($q['url']) && \is_string($q['url']) ? self::idDalLink($q['url']) : null;
        }

        if ($host !== 'drive.google.com' && $host !== 'drive.usercontent.google.com' && $host !== 'docs.google.com') {
            return null;
        }
        if (preg_match('#/file/d/(' . self::ID . ')(?:/|$)#', (string)($parti['path'] ?? ''), $m) === 1) {
            return $m[1];
        }
        parse_str((string)($parti['query'] ?? ''), $q);
        $id = isset($q['id']) && \is_string($q['id']) ? $q['id'] : '';
        return preg_match('/^' . self::ID . '$/', $id) === 1 ? $id : null;
    }

    /**
     * Scarica il file pubblico e ne ritorna l'XML drawio.
     *
     * @throws \RuntimeException link_non_raggiungibile · link_non_pubblico · link_non_drawio · payload_too_large
     */
    public function xml(string $id): string
    {
        if (preg_match('/^' . self::ID . '$/', $id) !== 1) {
            throw new \RuntimeException('link_non_drawio');
        }
        $r = ($this->scarica)('https://drive.google.com/uc?export=download&id=' . rawurlencode($id));
        if (!self::diGoogle($r['host'])) {
            throw new \RuntimeException('link_non_raggiungibile');
        }
        if ($r['status'] === 404 || $r['status'] === 403 || $r['status'] === 401) {
            throw new \RuntimeException('link_non_pubblico');
        }
        if ($r['status'] !== 200 || $r['body'] === '') {
            throw new \RuntimeException('link_non_raggiungibile');
        }
        if (\strlen($r['body']) > self::MAX_BYTES) {
            throw new \RuntimeException('payload_too_large');
        }
        $inizio = substr(ltrim($r['body']), 0, 4096);
        if (!str_contains($inizio, '<mxfile') && !str_contains($inizio, '<mxGraphModel')) {
            // Una pagina HTML di Google: accesso richiesto o file non pubblico.
            throw new \RuntimeException(stripos($inizio, '<html') !== false ? 'link_non_pubblico' : 'link_non_drawio');
        }
        // Il file intero, come ogni altra strada d'ingresso (FileDrawio,
        // 23/9/2026): i primi 4 KB dicono solo che non è una pagina di Google.
        if (!FileDrawio::valido($r['body'])) {
            throw new \RuntimeException('drawio_non_valido');
        }
        return $r['body'];
    }

    /**
     * Una mappa che esiste già con il solo link (create prima di questa classe):
     * scarica il drawio e, con `$applica`, lo salva come file della mappa. Senza
     * `$applica` dice che cosa farebbe. Tocca solo una riga senza file.
     *
     * @return array{esito:string, byte?:int, motivo?:string}
     *         esito: importata · da_importare · gia_col_file · non_drive · non_trovata · errore
     */
    public function importaNellaMappa(\PDO $pdo, MapBlobStore $blob, int $id, bool $applica): array
    {
        $st = $pdo->prepare('SELECT teacher_id, map_blob_path, metadata_json FROM teacher_content_data WHERE id = ? AND content_subtype = "mappa"');
        $st->execute([$id]);
        $riga = $st->fetch(\PDO::FETCH_ASSOC);
        if ($riga === false) {
            return ['esito' => 'non_trovata'];
        }
        if ((string)$riga['map_blob_path'] !== '') {
            return ['esito' => 'gia_col_file'];
        }
        $meta = json_decode((string)$riga['metadata_json'], true);
        $idFile = self::idDalLink(\is_array($meta) ? (string)($meta['mappa']['href'] ?? '') : '');
        if ($idFile === null) {
            return ['esito' => 'non_drive'];
        }
        try {
            $xml = $this->xml($idFile);
        } catch (\RuntimeException $e) {
            return ['esito' => 'errore', 'motivo' => $e->getMessage()];
        }
        if (!$applica) {
            return ['esito' => 'da_importare', 'byte' => \strlen($xml)];
        }
        $percorso = $blob->put((int)$riga['teacher_id'], $xml);
        $up = $pdo->prepare(
            'UPDATE teacher_content_data
                SET map_blob_path = ?, map_mime = "application/xml", map_size = ?, map_origin = "upload", map_version = 1
              WHERE id = ? AND (map_blob_path IS NULL OR map_blob_path = "")'
        );
        $up->execute([$percorso, \strlen($xml), $id]);
        if ($up->rowCount() !== 1) {
            $blob->delete($percorso);
            return ['esito' => 'errore', 'motivo' => 'la riga è cambiata durante l\'importazione'];
        }
        return ['esito' => 'importata', 'byte' => \strlen($xml)];
    }

    private static function diGoogle(string $host): bool
    {
        $host = strtolower($host);
        return $host === 'google.com' || str_ends_with($host, '.google.com') || str_ends_with($host, '.googleusercontent.com');
    }

    /** @return array{status:int, body:string, host:string} */
    private static function richiesta(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('link_non_raggiungibile');
        }
        $corpo = '';
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_TIMEOUT         => 30,
            CURLOPT_USERAGENT       => 'Pantedu',
            CURLOPT_WRITEFUNCTION   => static function ($ch, string $pezzo) use (&$corpo): int {
                $corpo .= $pezzo;
                // Oltre il tetto si interrompe: curl esce con errore di scrittura.
                return \strlen($corpo) > self::MAX_BYTES + 1 ? 0 : \strlen($pezzo);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $host = (string)(parse_url((string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), PHP_URL_HOST) ?? '');
        curl_close($ch);
        if ($ok === false && \strlen($corpo) <= self::MAX_BYTES) {
            throw new \RuntimeException('link_non_raggiungibile');
        }
        return ['status' => $status, 'body' => $corpo, 'host' => $host];
    }
}
