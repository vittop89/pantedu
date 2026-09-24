<?php

declare(strict_types=1);

namespace App\Services\Maps;

/**
 * Un file drawio, o no: il controllo del contenuto di una mappa che entra
 * (23/9/2026, revisione architetturale A-3, R-3 passo 2).
 *
 * PERCHÉ
 *   «Carica file» (POST /api/maps, mode=upload) guardava il nome e i primi 256
 *   byte (MapsController::resolveMime, tolto oggi): passava un file qualsiasi
 *   con `<mxfile` all'inizio, anche in un commento, e un HTML qualsiasi,
 *   perché `text/html` era fra i tipi ammessi. L'import dei pacchetti
 *   (POST /api/teacher/import-bundle/apply) non guardava niente: il tipo lo
 *   dava l'estensione del percorso. La pagina di studio mette il file della
 *   mappa, com'è, dentro un `<script>` (StudyPageRenderer; fino al passo 3
 *   di R-3 erano due script in linea, ora un'isola JSON): lì un
 *   `</script>` del file chiudeva lo script. Da oggi la codifica è sicura;
 *   questo è il secondo strato, in ingresso, e lo usano tutte e due le
 *   strade.
 *
 *   PDF, PNG, JPEG e HTML non si accettano più come mappa: nessuna pagina li
 *   mostra (la pagina di studio e l'editor aprono solo drawio), e un PDF
 *   mandava in errore di sintassi gli script di tutte le mappe del topic
 *   (json_encode di byte non UTF-8 dà false).
 *
 * CHE COSA È UN DRAWIO, QUI
 *   Sui byte, prima di libxml:
 *   - testo UTF-8, senza byte nulli (niente UTF-16 che il controllo sui byte
 *     non vedrebbe), e senza una dichiarazione XML che nomini un'altra
 *     codifica: con `<?xml version="1.0" encoding="UTF-7"?>` libxml legge
 *     `+ADw-!DOCTYPE` come `<!DOCTYPE`, e il controllo qui sotto non lo vede;
 *   - senza DOCTYPE né entità: drawio non li scrive, e sono la strada di XXE e
 *     della moltiplicazione delle entità.
 *   Poi libxml:
 *   - XML ben formato, letto senza rete (LIBXML_NONET), senza caricare DTD
 *     esterni e senza sostituire le entità: né LIBXML_DTDLOAD né LIBXML_NOENT
 *     (FileDrawioTest guarda che nessun DTD o entità esterna si carichi);
 *   - con LIBXML_PARSEHUGE. Senza, libxml rifiuta un attributo sopra i 10 MB
 *     («AttValue length too long»; un testo di 30 MB invece passa): drawio
 *     mette un'immagine incorporata nello `style` della cella, in base64, e un
 *     drawio vero con un'immagine grande si rifiutava. La dimensione la
 *     limitano i due chiamanti prima di arrivare qui, a 50 MB:
 *     MapsController::MAX_BYTES per «Carica file» (e prima ancora
 *     upload_max_filesize, 128 MB, docker/php.ini) e
 *     ImportBundleController::MAX_SINGLE_FILE per l'import dei pacchetti;
 *   - dentro due tetti di struttura, contati sui byte PRIMA del DOM:
 *     profondità 64 sotto la radice e 200.000 elementi. PARSEHUGE
 *     toglie a libxml anche il suo limite di profondità (256), e la memoria di
 *     libxml memory_limit non la conta: nell'immagine del rilascio 49 MB di
 *     `<a>` annidati hanno portato un processo a 3 GB. Le mappe vere stanno
 *     molto sotto (le 192 della copia locale: profondità 5, 2.602 elementi);
 *   - senza DOCTYPE nemmeno nel documento letto, e con la radice `<mxfile>` o
 *     `<mxGraphModel>`. Lo stesso controllo vale per ogni strada d'ingresso:
 *     «Carica file», l'import dei pacchetti, la creazione dall'editor
 *     (drawio_native), il salvataggio dall'editor (update) e il link di Drive
 *     (MappaDaLinkDrive), che fino al 23/9 cercavano solo la sottostringa.
 *
 *   Misurato il 23/9/2026 nell'immagine del rilascio (PHP 8.4, libxml 2.9.14
 *   di Debian bookworm): con LIBXML_PARSEHUGE libxml non ferma più la
 *   moltiplicazione delle entità. Dieci alla nona entità in un attributo,
 *   scritte in UTF-7, hanno superato 1 GB di memoria (il contenitore, con
 *   quel tetto, ha ucciso il processo; senza PARSEHUGE: «Detected an entity
 *   reference loop»); anche `<?xml encoding="UTF-7"?>`, senza versione, fa
 *   cambiare codifica a libxml. Sulla macchina di sviluppo (libxml 2.9.14 di
 *   Ubuntu) il freno invece resta: le prove, lì, non lo vedono. Per questo
 *   nessun DTD deve
 *   arrivare a libxml: lo fermano i controlli sui byte, compreso quello sulla
 *   dichiarazione, che per questo è largo (qualunque `encoding` nella
 *   dichiarazione che non sia UTF-8).
 *
 *   Il contenuto delle pagine compresse (base64 + deflate) non si apre: lo
 *   apre il visualizzatore di diagrams.net, e nella pagina è testo base64.
 */
final class FileDrawio
{
    public const VUOTO            = 'vuoto';
    public const NON_UTF8         = 'non_utf8';
    public const CON_DTD          = 'con_dtd';
    public const XML_NON_VALIDO   = 'xml_non_valido';
    public const RADICE_SBAGLIATA = 'radice_sbagliata';
    public const TROPPO_COMPLESSO = 'troppo_complesso';

    /**
     * I tetti della struttura: profondità sotto la radice (che sta a 0) ed
     * elementi, radice compresa. Le 192 mappe della copia locale, misurate il
     * 23/9/2026, arrivano a 5 e a 2.602.
     */
    public const PROFONDITA_MASSIMA = 64;
    public const ELEMENTI_MASSIMI   = 200_000;

    /** Le radici di un file drawio: il file intero o il solo modello. */
    private const RADICI = ['mxfile', 'mxGraphModel'];

    public static function valido(string $contenuto): bool
    {
        return self::motivoDelRifiuto($contenuto) === null;
    }

    /**
     * Null se il contenuto è un file drawio; altrimenti il motivo (una delle
     * costanti), che le prove usano per sapere che il rifiuto viene dalla
     * regola giusta.
     */
    public static function motivoDelRifiuto(string $contenuto): ?string
    {
        if (trim($contenuto) === '') {
            return self::VUOTO;
        }
        if (
            str_contains($contenuto, "\0")
            || !mb_check_encoding($contenuto, 'UTF-8')
            || self::dichiaraUnAltraCodifica($contenuto)
        ) {
            return self::NON_UTF8;
        }
        if (stripos($contenuto, '<!DOCTYPE') !== false || stripos($contenuto, '<!ENTITY') !== false) {
            return self::CON_DTD;
        }
        return self::strutturaFuoriDaiTetti($contenuto) ?? self::motivoNelDocumentoLetto($contenuto);
    }

    /**
     * Profondità ed elementi, contati sui byte prima di costruire il DOM
     * (23/9/2026, verifica avversaria di A-3). LIBXML_PARSEHUGE, che serve per
     * le immagini grandi negli attributi, toglie a libxml anche il limite di
     * profondità: nell'immagine del rilascio un file di 49 MB di `<a>`
     * annidati ha portato un processo PHP a 3 GB (memory_limit non conta la
     * memoria di libxml), e uno di `<a/>` affiancati a 1,8 GB. Il conto si
     * ferma al primo tetto superato, e il DOM si costruisce solo per un file
     * che ci sta.
     *
     * Sui byte e non con XMLReader: XMLReader legge a pezzi, e su un attributo
     * di 11 MB (un'immagine incorporata) rilegge l'attributo a ogni pezzo
     * nuovo, 23 secondi misurati. Qui ogni passo è una strpos o una strcspn.
     * In un XML ben formato ogni `<` apre un markup (nei valori e nel testo
     * sta come `&lt;`): `</` chiude, `<!` e `<?` sono commenti, CDATA e
     * istruzioni, che si saltano; il resto apre un elemento, che finisce al
     * primo `>` fuori dagli apici e si chiude da sé se prima c'è `/`. Un
     * documento rotto può ingannare il conto, ma poi lo rifiuta il DOM.
     */
    private static function strutturaFuoriDaiTetti(string $contenuto): ?string
    {
        $lunghezza = \strlen($contenuto);
        // Un int qualsiasi: PHPStan, dentro il ciclo, ne deduce un intervallo
        // stretto e darebbe il confronto con il tetto per sempre falso.
        /** @var int $profondita */
        $profondita = 0;
        $elementi = 0;
        $pos = 0;
        while (($apre = strpos($contenuto, '<', $pos)) !== false) {
            $dopo = $contenuto[$apre + 1] ?? '';
            if ($dopo === '/') {
                $profondita--;
                $pos = $apre + 2;
                continue;
            }
            if ($dopo === '!' || $dopo === '?') {
                $chiusura = match (true) {
                    substr_compare($contenuto, '<!--', $apre, 4) === 0 => '-->',
                    substr_compare($contenuto, '<![CDATA[', $apre, 9) === 0 => ']]>',
                    $dopo === '?' => '?>',
                    default => '>',
                };
                $fine = strpos($contenuto, $chiusura, $apre + 2);
                if ($fine === false) {
                    return null;
                }
                $pos = $fine + \strlen($chiusura);
                continue;
            }
            // Un elemento: la sua profondità è quella di adesso (la radice a 0).
            if ($profondita > self::PROFONDITA_MASSIMA || ++$elementi > self::ELEMENTI_MASSIMI) {
                return self::TROPPO_COMPLESSO;
            }
            // La fine del tag: il primo `>` fuori dagli apici dei valori.
            $i = $apre + 1;
            while (true) {
                $i += strcspn($contenuto, '"\'>', $i);
                if ($i >= $lunghezza) {
                    return null;
                }
                if ($contenuto[$i] === '>') {
                    break;
                }
                $apice = strpos($contenuto, $contenuto[$i], $i + 1);
                if ($apice === false) {
                    return null;
                }
                $i = $apice + 1;
            }
            if ($contenuto[$i - 1] !== '/') {
                $profondita++;
            }
            $pos = $i + 1;
        }
        return null;
    }

    /**
     * La dichiarazione XML in testa nomina una codifica che non è UTF-8?
     *
     * Largo di proposito: si guarda da `<?xml` al primo `>` (in una
     * dichiarazione ben formata non ce n'è un altro prima della fine), e ogni
     * `encoding` che compare lì deve essere `encoding="UTF-8"` (o `UTF8`, con
     * gli apici semplici, maiuscole o minuscole). Una dichiarazione rotta che
     * nomina un'altra codifica si rifiuta anche se libxml, forse, non la
     * seguirebbe: `<?xml encoding="UTF-7"?>`, senza versione, libxml la segue.
     */
    private static function dichiaraUnAltraCodifica(string $contenuto): bool
    {
        $testa = str_starts_with($contenuto, "\xEF\xBB\xBF") ? substr($contenuto, 3) : $contenuto;
        if (strncasecmp($testa, '<?xml', 5) !== 0) {
            return false;
        }
        $fine = strpos($testa, '>');
        $dichiarazione = $fine === false ? $testa : substr($testa, 0, $fine + 1);
        $nominate = substr_count(strtolower($dichiarazione), 'encoding');
        $utf8 = (int)preg_match_all('/encoding\s*=\s*(["\'])utf-?8\1/i', $dichiarazione);
        return $nominate !== $utf8;
    }

    /**
     * Il documento come lo legge libxml. Ci si arriva solo dopo i controlli sui
     * byte (niente DOCTYPE, niente altre codifiche): con LIBXML_PARSEHUGE un
     * DTD qui dentro sarebbe la moltiplicazione delle entità senza freno.
     */
    private static function motivoNelDocumentoLetto(string $contenuto): ?string
    {
        $prima = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            if ($dom->loadXML($contenuto, LIBXML_NONET | LIBXML_PARSEHUGE) !== true) {
                return self::XML_NON_VALIDO;
            }
            // Il secondo sguardo al DOCTYPE, nel documento letto: se una strada
            // che i controlli sui byte non conoscono ne facesse passare uno.
            if ($dom->doctype !== null) {
                return self::CON_DTD;
            }
            $radice = $dom->documentElement;
            if ($radice === null || !\in_array($radice->nodeName, self::RADICI, true)) {
                return self::RADICE_SBAGLIATA;
            }
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prima);
        }
    }
}
