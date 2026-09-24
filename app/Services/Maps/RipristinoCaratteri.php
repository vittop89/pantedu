<?php

declare(strict_types=1);

namespace App\Services\Maps;

/**
 * Le lettere accentate diventate «??» nelle mappe: le regole per rimetterle,
 * senza input né output (wiki/domains/mappe/mappe-overview.md, «Caratteri
 * persi»).
 *
 * Come si sono perse. Nel 2025 il vecchio sito riceveva i drawio dal Drive
 * del docente e passava il contenuto per `mb_convert_encoding($v, 'UTF-8',
 * 'auto')`: su un testo lungo con pochi accenti PHP sceglieva ASCII, e ogni
 * byte non ASCII diventava un «?». Una lettera accentata occupa due byte in
 * UTF-8 (à è ° e lo spazio non separabile) e diventa «??»; un carattere da tre
 * byte (’ – €) diventa «???». Nient'altro è cambiato: il file rovinato è
 * l'originale con ogni byte da 0x80 in su scritto come «?», cioè
 * `asciify(originale)`.
 *
 * Da qui la regola che tiene insieme tutto: **si cambiano solo i byte «?»
 * delle corse, mai altro.** Ogni sostituzione ha la stessa lunghezza della
 * corsa, e `asciify(nuovo) === asciify(vecchio)`: se una mappa è stata
 * modificata dal docente dopo l'importazione, il testo che ha scritto non si
 * tocca.
 *
 * Il testo vero si prende solo dove si può dimostrare:
 *
 *   - da un originale **esatto**, quando `asciify(originale)` è il blob byte per
 *     byte: la sostituzione sta allo stesso offset;
 *   - dal **contesto**, quando i byte intorno alla corsa compaiono negli
 *     originali (trasformati con `asciify`) e ovunque compaiano danno lo stesso
 *     testo;
 *   - da una **persona**, che approva la proposta una per una.
 *
 * Una corsa che compare anche nell'originale («??? mm», un segnaposto di un
 * esercizio) è legittima e resta com'è.
 */
final class RipristinoCaratteri
{
    public const FONTE_ESATTO    = 'esatto';
    public const FONTE_CONTESTO  = 'contesto';
    public const FONTE_APPROVATA = 'approvata';

    /** Byte di contesto per lato che il censimento porta con ogni corsa. */
    public const CONTESTO = 24;

    /** Le finestre di contesto provate negli originali, dalla più larga. */
    public const FINESTRE = [16, 8, 4];

    /**
     * Le corse di almeno due «?»: una lettera persa ne lascia sempre almeno
     * due, perché in UTF-8 nessun carattere non ASCII sta in un byte solo.
     *
     * @return list<array{offset:int, corsa:string}>
     */
    public static function corse(string $testo): array
    {
        if (preg_match_all('/\?{2,}/', $testo, $trovate, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $corse = [];
        foreach ($trovate[0] as [$corsa, $offset]) {
            $corse[] = ['offset' => (int)$offset, 'corsa' => (string)$corsa];
        }
        return $corse;
    }

    /** Ogni byte da 0x80 in su diventa «?»: è quello che ha fatto il vecchio sito. */
    public static function asciify(string $testo): string
    {
        return (string)preg_replace('/[\x80-\xFF]/', '?', $testo);
    }

    /**
     * Vero se il testo ha la firma della perdita: almeno una corsa di «?» e
     * nessun byte non ASCII.
     *
     * Sbaglia per eccesso, di proposito: un file tutto ASCII con un
     * segnaposto voluto («??? mm») ha la firma anche se è sano. Chi la usa
     * rifiuta il file, e un rifiuto di troppo si controlla a mano; un file
     * rovinato che passa torna invece nelle mappe. Una versione più stretta
     * (corsa attaccata a una lettera, «pi??») è stata misurata il 19/9/2026
     * sui drawio legacy: lasciava passare 6 file rovinati su 82, quelli con
     * solo «30??» o «COM'??».
     */
    public static function firmaDiPerdita(string $testo): bool
    {
        return preg_match('/[\x80-\xFF]/', $testo) === 0
            && preg_match('/\?{2,}/', $testo) === 1;
    }

    /**
     * I byte prima dell'offset, al più `$quanti`, senza un carattere a metà
     * all'inizio: così il contesto è UTF-8 valido anche in una mappa che il
     * docente ha modificato e che ha di nuovo lettere accentate vere.
     */
    public static function contestoSinistro(string $testo, int $offset, int $quanti = self::CONTESTO): string
    {
        $inizio = max(0, $offset - $quanti);
        $pezzo = substr($testo, $inizio, $offset - $inizio);
        // Un byte di continuazione (10xxxxxx) all'inizio è la coda di un carattere tagliato.
        return (string)preg_replace('/^[\x80-\xBF]+/', '', $pezzo);
    }

    /** I byte dopo la fine della corsa, al più `$quanti`, senza un carattere a metà alla fine. */
    public static function contestoDestro(string $testo, int $fine, int $quanti = self::CONTESTO): string
    {
        $pezzo = substr($testo, $fine, $quanti);
        while ($pezzo !== '' && preg_match('//u', $pezzo) !== 1) {
            $pezzo = substr($pezzo, 0, -1);
        }
        return $pezzo;
    }

    /**
     * Il testo vero di ogni corsa, da un originale di cui il blob è la copia
     * rovinata. Null se l'originale non è quello: la lunghezza o un solo byte
     * ASCII diverso bastano a scartarlo.
     *
     * Restituisce tutte le corse, anche quelle legittime: lì `testo` è uguale
     * a `corsa`, e chi chiama decide che farne (nel ripristino si saltano).
     *
     * @return list<array{offset:int, corsa:string, testo:string}>|null
     */
    public static function daOriginale(string $corrotto, string $originale): ?array
    {
        if (strlen($corrotto) !== strlen($originale) || self::asciify($originale) !== $corrotto) {
            return null;
        }
        $esito = [];
        foreach (self::corse($corrotto) as $c) {
            $esito[] = $c + ['testo' => substr($originale, $c['offset'], strlen($c['corsa']))];
        }
        return $esito;
    }

    /**
     * Il testo vero di una corsa cercandone il contesto negli originali.
     *
     * Per ogni finestra (16, 8, 4 byte per lato) si cerca `sinistra + corsa +
     * destra` in `asciify` di ogni originale, e si legge nell'originale il
     * testo allo stesso posto. Si accetta solo se tutte le occorrenze, in tutti
     * gli originali, danno lo stesso testo; se ne danno due diversi ci si ferma
     * (una finestra più stretta non può essere più sicura di una larga), se non
     * ce n'è nessuna si prova la finestra dopo.
     *
     * @param list<array{0:string, 1:string}> $originali coppie [originale, asciify(originale)]
     * @param list<int> $finestre
     * @return array{testo:?string, finestra:?int, candidati:list<string>, occorrenze:int}
     */
    public static function daContesto(string $sinistra, string $corsa, string $destra, array $originali, array $finestre = self::FINESTRE): array
    {
        foreach ($finestre as $k) {
            $s = substr($sinistra, -$k);
            $d = substr($destra, 0, $k);
            // Anche il contesto passa per asciify: in una mappa che il docente
            // ha modificato, accanto alla corsa può esserci una lettera accentata
            // vera, che negli originali trasformati è di nuovo «??».
            $ago = self::asciify($s) . $corsa . self::asciify($d);
            $testi = [];
            $occorrenze = 0;
            foreach ($originali as [$vero, $ascii]) {
                $da = 0;
                while (($q = strpos($ascii, $ago, $da)) !== false) {
                    $occorrenze++;
                    $testi[substr($vero, $q + strlen($s), strlen($corsa))] = true;
                    if (count($testi) > 1) {
                        break 2;
                    }
                    $da = $q + 1;
                }
            }
            if ($testi === []) {
                continue;
            }
            $candidati = array_map('strval', array_keys($testi));
            if (count($candidati) > 1) {
                return ['testo' => null, 'finestra' => $k, 'candidati' => $candidati, 'occorrenze' => $occorrenze];
            }
            $testo = $candidati[0];
            // Un testo che non potrebbe stare al posto della corsa (UTF-8 non
            // valido, per esempio un carattere da tre byte preso a metà) si
            // scarta: meglio una corsa da approvare che una lettera sbagliata.
            if ($testo !== $corsa && !self::testoAmmesso($corsa, $testo)) {
                return ['testo' => null, 'finestra' => $k, 'candidati' => $candidati, 'occorrenze' => $occorrenze];
            }
            return ['testo' => $testo, 'finestra' => $k, 'candidati' => $candidati, 'occorrenze' => $occorrenze];
        }
        return ['testo' => null, 'finestra' => null, 'candidati' => [], 'occorrenze' => 0];
    }

    /**
     * Vero se `$testo` può prendere il posto di `$corsa`: stessa lunghezza,
     * UTF-8 valido, e ogni byte è non ASCII oppure un «?» (una corsa può
     * contenere un punto di domanda vero: «COS'È?» → «COS'???» → «È?»).
     */
    public static function testoAmmesso(string $corsa, string $testo): bool
    {
        return $testo !== $corsa
            && preg_match('/^\?{2,}$/', $corsa) === 1
            && strlen($testo) === strlen($corsa)
            && self::asciify($testo) === $corsa
            && preg_match('//u', $testo) === 1;
    }

    /**
     * Applica le sostituzioni e controlla il risultato.
     *
     * Se lo sha256 del testo è `$shaAtteso` (il blob non è cambiato dal
     * censimento) la sostituzione sta al suo offset, e lì devono esserci la
     * corsa e il contesto. Altrimenti la si ancora al contesto (`sinistra +
     * corsa + destra`), che deve comparire una volta sola. Quello che non torna
     * finisce fra i conflitti e non si applica.
     *
     * Prima di restituire si controllano le invarianti; se una non regge, il
     * testo torna com'era e `errore` dice quale:
     *
     *   - `asciify(nuovo) === asciify(vecchio)`: sono cambiati solo i «?»;
     *   - il nuovo è UTF-8 valido;
     *   - se il vecchio si leggeva come XML, si legge anche il nuovo;
     *   - le corse calano esattamente di quante se ne sono sostituite.
     *
     * @param list<array{offset:int, corsa:string, testo:string, sinistra?:string, destra?:string, fonte?:string}> $sostituzioni
     * @return array{
     *     xml:string,
     *     applicate:list<array{offset:int, corsa:string, testo:string, sinistra?:string, destra?:string, fonte?:string, posizione:int}>,
     *     conflitti:list<array{sostituzione:array<string,mixed>, motivo:string}>,
     *     errore:?string,
     *     per_offset:bool
     * }
     */
    public static function applica(string $xml, array $sostituzioni, ?string $shaAtteso): array
    {
        $perOffset = $shaAtteso !== null && hash_equals($shaAtteso, hash('sha256', $xml));
        $applicate = [];
        $conflitti = [];
        $occupati = [];

        foreach ($sostituzioni as $s) {
            $corsa = (string)$s['corsa'];
            $testo = (string)$s['testo'];
            $sinistra = (string)($s['sinistra'] ?? '');
            $destra = (string)($s['destra'] ?? '');
            if (!self::testoAmmesso($corsa, $testo)) {
                $conflitti[] = ['sostituzione' => $s, 'motivo' => 'testo_non_ammesso'];
                continue;
            }
            $lunghezza = strlen($corsa);

            if ($perOffset) {
                $posizione = (int)$s['offset'];
                if (
                    substr($xml, $posizione, $lunghezza) !== $corsa
                    || ($sinistra !== '' && substr($xml, $posizione - strlen($sinistra), strlen($sinistra)) !== $sinistra)
                    || ($destra !== '' && substr($xml, $posizione + $lunghezza, strlen($destra)) !== $destra)
                ) {
                    $conflitti[] = ['sostituzione' => $s, 'motivo' => 'offset_non_corrisponde'];
                    continue;
                }
            } else {
                if ($sinistra === '' && $destra === '') {
                    $conflitti[] = ['sostituzione' => $s, 'motivo' => 'senza_contesto'];
                    continue;
                }
                $ago = $sinistra . $corsa . $destra;
                $trovate = [];
                $da = 0;
                while (count($trovate) < 2 && ($q = strpos($xml, $ago, $da)) !== false) {
                    $trovate[] = $q;
                    $da = $q + 1;
                }
                if ($trovate === []) {
                    $conflitti[] = ['sostituzione' => $s, 'motivo' => 'contesto_assente'];
                    continue;
                }
                if (count($trovate) > 1) {
                    $conflitti[] = ['sostituzione' => $s, 'motivo' => 'contesto_non_univoco'];
                    continue;
                }
                $posizione = $trovate[0] + strlen($sinistra);
            }

            // La corsa trovata deve essere intera: un «?» subito prima o subito
            // dopo vorrebbe dire che nel testo attuale la corsa è un'altra.
            if (
                ($posizione > 0 && $xml[$posizione - 1] === '?')
                || ($posizione + $lunghezza < strlen($xml) && $xml[$posizione + $lunghezza] === '?')
            ) {
                $conflitti[] = ['sostituzione' => $s, 'motivo' => 'corsa_diversa'];
                continue;
            }
            if (isset($occupati[$posizione])) {
                $conflitti[] = ['sostituzione' => $s, 'motivo' => 'doppia'];
                continue;
            }
            $occupati[$posizione] = true;
            $applicate[] = $s + ['posizione' => $posizione];
        }

        usort($applicate, static fn(array $a, array $b): int => $a['posizione'] <=> $b['posizione']);
        $pezzi = [];
        $da = 0;
        foreach ($applicate as $a) {
            $pezzi[] = substr($xml, $da, $a['posizione'] - $da);
            $pezzi[] = (string)$a['testo'];
            $da = $a['posizione'] + strlen((string)$a['corsa']);
        }
        $pezzi[] = substr($xml, $da);
        $nuovo = implode('', $pezzi);

        $errore = $applicate === [] ? null : self::invarianteViolata($xml, $nuovo, $applicate);
        if ($errore !== null) {
            return ['xml' => $xml, 'applicate' => [], 'conflitti' => $conflitti, 'errore' => $errore, 'per_offset' => $perOffset];
        }
        return ['xml' => $nuovo, 'applicate' => $applicate, 'conflitti' => $conflitti, 'errore' => null, 'per_offset' => $perOffset];
    }

    /**
     * Il nome dell'invariante che il nuovo testo viola, o null.
     *
     * @param list<array{corsa:string, testo:string}> $applicate
     */
    public static function invarianteViolata(string $vecchio, string $nuovo, array $applicate): ?string
    {
        if (strlen($nuovo) !== strlen($vecchio) || self::asciify($nuovo) !== self::asciify($vecchio)) {
            return 'cambiato_altro_che_i_punti_di_domanda';
        }
        if (preg_match('//u', $nuovo) !== 1) {
            return 'utf8_non_valido';
        }
        if (self::siLeggeComeXml($vecchio) && !self::siLeggeComeXml($nuovo)) {
            return 'xml_non_leggibile';
        }
        $attese = count(self::corse($vecchio)) - count($applicate);
        foreach ($applicate as $a) {
            $attese += count(self::corse((string)$a['testo']));
        }
        if (count(self::corse($nuovo)) !== $attese) {
            return 'corse_non_tornano';
        }
        return null;
    }

    public static function siLeggeComeXml(string $testo): bool
    {
        if (trim($testo) === '') {
            return false;
        }
        $prima = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            return $dom->loadXML($testo, LIBXML_NONET | LIBXML_PARSEHUGE) === true;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prima);
        }
    }

    /**
     * Le pagine compresse di un drawio (contenuto del `<diagram>` in base64 e
     * deflate) e le corse che contengono una volta aperte.
     *
     * Il ripristino non le tocca: nel file le pagine compresse sono base64, che
     * non ha «?», e quello che contengono è arrivato intatto dal vecchio sito.
     * Il censimento le conta perché si sappia che ci sono (al 19/9/2026 una
     * mappa sola, con sei «???» già nell'originale del 2022).
     *
     * @return array{pagine:int, corse:int}
     */
    public static function pagineCompresse(string $xml): array
    {
        $pagine = 0;
        $corse = 0;
        if (preg_match_all('#<diagram\b[^>]*>([A-Za-z0-9+/=\s]+)</diagram>#', $xml, $trovate) > 0) {
            foreach ($trovate[1] as $contenuto) {
                $binario = base64_decode(trim($contenuto), true);
                $aperto = $binario === false ? false : @gzinflate($binario);
                if ($aperto === false) {
                    continue;
                }
                $pagine++;
                $corse += count(self::corse(rawurldecode($aperto)));
            }
        }
        return ['pagine' => $pagine, 'corse' => $corse];
    }
}
