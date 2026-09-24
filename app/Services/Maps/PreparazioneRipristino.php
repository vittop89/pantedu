<?php

declare(strict_types=1);

namespace App\Services\Maps;

/**
 * Dal censimento delle mappe e dagli originali del Drive, la patch da
 * applicare e l'elenco da rivedere (wiki/domains/mappe/mappe-overview.md,
 * «Caratteri persi»). Niente input né output: i file li legge e li scrive
 * tools/maps/prepara_ripristino_caratteri.php, in locale.
 *
 * Per ogni corsa di «?» del censimento, in quest'ordine:
 *
 *   1. **esatto** — c'è un originale con `sha256(asciify(originale))` uguale
 *      allo sha256 del blob: il blob è quell'originale rovinato, e il testo
 *      vero sta allo stesso offset;
 *   2. **contesto** — i byte intorno alla corsa compaiono negli originali, e
 *      ovunque compaiano danno lo stesso testo (RipristinoCaratteri::daContesto);
 *   3. altrimenti la corsa va nell'elenco da rivedere, con una **proposta** se
 *      una regola ne ha una (dizionario delle parole del docente, apostrofo,
 *      grado dopo una cifra, spazio non separabile, «è» isolata). Le regole non
 *      scrivono mai da sole: una persona approva riga per riga.
 *
 * Le mappe del docente modificate dopo l'importazione (quindi con lo sha
 * cambiato) non hanno un originale esatto: passano dal contesto. La
 * sostituzione per offset si usa solo con l'originale esatto.
 *
 * Una corsa che negli originali è di nuovo «??» è legittima (un segnaposto
 * voluto): non entra nella patch, e nell'elenco da rivedere compare con la
 * fonte «legittima», così si vede e, se era una perdita più vecchia, si può
 * correggere lo stesso.
 */
final class PreparazioneRipristino
{
    public const FONTE_LEGITTIMA = 'legittima';
    public const FONTE_NESSUNA   = 'nessuna';

    /** Come si scrive nel CSV lo spazio non separabile, che a vista non si distingue. */
    private const SEGNO_NBSP = '\\' . 'u00A0';

    /** Le risposte che contano come «sì» nella colonna «approvata». */
    private const SI = ['si', 'sì', 's', 'x', '1', 'yes', 'y', 'ok'];

    /** Parole che il corpus potrebbe non avere, e che si scrivono sempre con l'accento. */
    private const PAROLE_NOTE = [
        'è', 'perché', 'poiché', 'affinché', 'finché', 'benché', 'purché', 'cioè', 'né', 'sé', 'più', 'può',
        'però', 'perciò', 'ciò', 'già', 'giù', 'così', 'città', 'unità', 'velocità', 'quantità', 'proprietà',
        'attività', 'capacità', 'densità', 'intensità', 'metà', 'qualità', 'necessità', 'possibilità',
        'realtà', 'età', 'identità', 'continuità', 'probabilità', 'difficoltà', 'università', 'gravità',
        'elettricità', 'verità', 'dà', 'lì', 'là', 'sì',
    ];

    /** @var array<string, array{0:string, 1:string}> sha256(testo) => [originale, asciify(originale)] */
    private array $testi = [];
    /** @var array<string, list<string>> sha256(asciify(originale)) => sha256 dei testi */
    private array $perAscii = [];
    /** @var array<string, int> parola minuscola con lettere non ASCII => occorrenze */
    private array $dizionario = [];

    /**
     * @param iterable<string, string> $originali nome del file => contenuto (il nome non conta:
     *   due file uguali sono un testo solo)
     */
    public function __construct(iterable $originali)
    {
        foreach ($originali as $contenuto) {
            $this->aggiungi((string)$contenuto);
        }
        foreach (self::PAROLE_NOTE as $parola) {
            $this->dizionario[$parola] = ($this->dizionario[$parola] ?? 0) + 1;
        }
    }

    private function aggiungi(string $contenuto): void
    {
        $impronta = hash('sha256', $contenuto);
        if (isset($this->testi[$impronta])) {
            return;
        }
        $ascii = RipristinoCaratteri::asciify($contenuto);
        $this->testi[$impronta] = [$contenuto, $ascii];
        $this->perAscii[hash('sha256', $ascii)][] = $impronta;

        // Le pagine compresse si aprono e si aggiungono come testo a sé: il
        // contesto di una corsa del blob può stare in una pagina che nel file
        // del Drive, salvato dopo, è compressa (e viceversa).
        $aperto = self::apriPagine($contenuto);
        if ($aperto !== $contenuto) {
            $impronta2 = hash('sha256', $aperto);
            if (!isset($this->testi[$impronta2])) {
                $this->testi[$impronta2] = [$aperto, RipristinoCaratteri::asciify($aperto)];
            }
        }
        $this->aggiungiParole($aperto);
    }

    /** Il drawio con le pagine compresse aperte (base64, deflate, urlencode). */
    public static function apriPagine(string $xml): string
    {
        return (string)preg_replace_callback(
            '#(<diagram\b[^>]*>)([A-Za-z0-9+/=\s]+)(</diagram>)#',
            static function (array $m): string {
                $binario = base64_decode(trim($m[2]), true);
                $aperto = $binario === false ? false : @gzinflate($binario);
                return $aperto === false ? $m[0] : $m[1] . rawurldecode($aperto) . $m[3];
            },
            $xml,
        );
    }

    /** Il testo che si legge: le entità HTML dei valori drawio sono spesso doppie. */
    public static function leggibile(string $s): string
    {
        $flag = ENT_QUOTES | ENT_HTML5;
        return html_entity_decode(html_entity_decode($s, $flag, 'UTF-8'), $flag, 'UTF-8');
    }

    private function aggiungiParole(string $xml): void
    {
        if (preg_match_all('/\p{L}+/u', self::leggibile($xml), $parole) === false) {
            return;
        }
        foreach ($parole[0] as $parola) {
            if (preg_match('/[^\x00-\x7F]/', $parola) === 1) {
                $k = mb_strtolower($parola);
                $this->dizionario[$k] = ($this->dizionario[$k] ?? 0) + 1;
            }
        }
    }

    /** Quanti testi distinti si usano come fonte (originali e pagine aperte). */
    public function quantiTesti(): int
    {
        return count($this->testi);
    }

    /**
     * La patch e l'elenco da rivedere.
     *
     * @param array<string,mixed> $censimento come lo produce RipristinoCaratteriMappe::censimento()
     * @return array{
     *     patch: array{formato:string, censimento:string, mappe:list<array<string,mixed>>},
     *     revisione: list<array<string,string>>,
     *     conteggi: array<string,mixed>
     * }
     */
    public function prepara(array $censimento): array
    {
        if (($censimento['formato'] ?? null) !== RipristinoCaratteriMappe::FORMATO_CENSIMENTO) {
            throw new \InvalidArgumentException('censimento_formato_sconosciuto');
        }
        $coppie = array_values($this->testi);
        $conteggi = [
            'mappe' => 0, 'mappe_in_patch' => 0, 'mappe_utf8_non_valido' => 0,
            'per_docente' => [], 'contenuti' => [], 'validazione_regole' => [],
        ];
        $mappePatch = [];
        /** @var array<string, array<string,string>> $righe chiave => riga dell'elenco */
        $righe = [];
        /** @var array<string, array<int, array{fonte:string, testo:?string, regola:string, candidati:list<string>}>> $memo */
        $memo = [];

        foreach ((array)($censimento['mappe'] ?? []) as $m) {
            $conteggi['mappe']++;
            $id = (int)$m['id'];
            $tid = (int)$m['teacher_id'];
            $sha = (string)$m['sha256'];
            $d = &$conteggi['per_docente'][$tid];
            $d ??= ['mappe' => 0, 'corse' => 0, 'esatto' => 0, 'contesto' => 0, 'legittime' => 0, 'proposte' => 0, 'senza_fonte' => 0];
            $d['mappe']++;
            if (!($m['utf8_valido'] ?? false)) {
                $conteggi['mappe_utf8_non_valido']++;
                unset($d);
                continue;
            }

            $esatti = $this->perAscii[$sha] ?? [];
            $sostituzioni = [];
            foreach ((array)$m['corse'] as $c) {
                $offset = (int)$c['offset'];
                $corsa = (string)$c['corsa'];
                $sinistra = (string)$c['sinistra'];
                $destra = (string)$c['destra'];
                $d['corse']++;

                // Stesso contenuto (le copie del docente 140 hanno lo sha del 77):
                // la decisione si prende una volta sola.
                $decisione = $memo[$sha][$offset] ??= $this->decidi($esatti, $offset, $corsa, $sinistra, $destra, $coppie);
                $conteggi['contenuti'][$sha] ??= true;

                if ($decisione['fonte'] === RipristinoCaratteri::FONTE_ESATTO || $decisione['fonte'] === RipristinoCaratteri::FONTE_CONTESTO) {
                    $d[$decisione['fonte']]++;
                    $sostituzioni[] = [
                        'offset' => $offset, 'corsa' => $corsa, 'testo' => (string)$decisione['testo'],
                        'sinistra' => $sinistra, 'destra' => $destra, 'fonte' => $decisione['fonte'],
                    ];
                    // Le regole si misurano sulle corse di cui si sa il testo vero.
                    [$proposta, $regola] = $this->proponi($sinistra, $corsa, $destra);
                    if ($proposta !== null) {
                        $esito = $proposta === $decisione['testo'] ? 'giusta' : 'sbagliata';
                        $conteggi['validazione_regole'][$regola][$esito] = ($conteggi['validazione_regole'][$regola][$esito] ?? 0) + 1;
                    }
                    continue;
                }
                if ($decisione['fonte'] === self::FONTE_LEGITTIMA) {
                    $d['legittime']++;
                } elseif ($decisione['testo'] !== null) {
                    $d['proposte']++;
                } else {
                    $d['senza_fonte']++;
                }
                $chiave = substr($sha, 0, 16) . ':' . $offset;
                if (isset($righe[$chiave])) {
                    $righe[$chiave]['mappe'] .= ", $id ($tid)";
                    continue;
                }
                $righe[$chiave] = [
                    'chiave'    => $chiave,
                    'mappe'     => "$id ($tid)",
                    'titolo'    => (string)($m['titolo'] ?? ''),
                    'corsa'     => $corsa,
                    'prima'     => self::perLeggere(self::leggibile($sinistra)),
                    'dopo'      => self::perLeggere(self::leggibile($destra)),
                    'fonte'     => $decisione['fonte'],
                    'regola'    => $decisione['regola'],
                    'candidati' => implode(' | ', $decisione['candidati']),
                    'approvata' => '',
                    'testo'     => self::perScrivere((string)($decisione['testo'] ?? '')),
                ];
            }
            unset($d);
            if ($sostituzioni !== []) {
                $conteggi['mappe_in_patch']++;
                $mappePatch[] = [
                    'id' => $id, 'teacher_id' => $tid, 'sha256' => $sha,
                    'map_version' => (int)$m['map_version'], 'sostituzioni' => $sostituzioni,
                ];
            }
        }
        $conteggi['contenuti'] = count($conteggi['contenuti']);
        ksort($conteggi['per_docente']);
        ksort($conteggi['validazione_regole']);

        return [
            'patch' => [
                'formato'    => RipristinoCaratteriMappe::FORMATO_PATCH,
                'censimento' => (string)($censimento['generato'] ?? ''),
                'mappe'      => $mappePatch,
            ],
            'revisione' => array_values($righe),
            'conteggi'  => $conteggi,
        ];
    }

    /**
     * @param list<string> $esatti impronte dei testi il cui asciify è il blob
     * @param list<array{0:string, 1:string}> $coppie
     * @return array{fonte:string, testo:?string, regola:string, candidati:list<string>}
     */
    private function decidi(array $esatti, int $offset, string $corsa, string $sinistra, string $destra, array $coppie): array
    {
        $candidati = [];
        if ($esatti !== []) {
            $veri = [];
            foreach ($esatti as $impronta) {
                $veri[substr($this->testi[$impronta][0], $offset, strlen($corsa))] = true;
            }
            $candidati = array_map('strval', array_keys($veri));
            if (count($candidati) === 1) {
                $vero = $candidati[0];
                if ($vero === $corsa) {
                    return ['fonte' => self::FONTE_LEGITTIMA, 'testo' => null, 'regola' => 'originale_esatto', 'candidati' => []];
                }
                if (RipristinoCaratteri::testoAmmesso($corsa, $vero)) {
                    return ['fonte' => RipristinoCaratteri::FONTE_ESATTO, 'testo' => $vero, 'regola' => '', 'candidati' => []];
                }
            }
            // Originali esatti che non vanno d'accordo: decide una persona.
        } else {
            $r = RipristinoCaratteri::daContesto($sinistra, $corsa, $destra, $coppie);
            $candidati = $r['candidati'];
            if ($r['testo'] !== null) {
                if ($r['testo'] === $corsa) {
                    return ['fonte' => self::FONTE_LEGITTIMA, 'testo' => null, 'regola' => 'contesto_' . $r['finestra'], 'candidati' => []];
                }
                return ['fonte' => RipristinoCaratteri::FONTE_CONTESTO, 'testo' => $r['testo'], 'regola' => '', 'candidati' => []];
            }
        }
        [$proposta, $regola] = $this->proponi($sinistra, $corsa, $destra);
        $candidati = array_values(array_filter($candidati, static fn(string $x): bool => preg_match('//u', $x) === 1));
        return [
            'fonte'     => $proposta !== null ? 'regola' : self::FONTE_NESSUNA,
            'testo'     => $proposta,
            'regola'    => $regola,
            'candidati' => $candidati,
        ];
    }

    /**
     * Una proposta per una corsa senza fonte, e il nome della regola.
     * Solo proposte: le approva una persona.
     *
     * @return array{0:?string, 1:string}
     */
    public function proponi(string $sinistra, string $corsa, string $destra): array
    {
        $lunghezza = strlen($corsa);
        $s = self::leggibile($sinistra);
        $d = self::leggibile($destra);
        $prima = preg_match('/(\p{L}*)$/u', $s, $a) === 1 ? $a[1] : '';
        $dopo = preg_match('/^(\p{L}*)/u', $d, $b) === 1 ? $b[1] : '';

        if ($lunghezza === 2 && $prima === '') {
            // Gradi: 90°, \(30\)°, 20 °C, 32 °F.
            if (preg_match('/\d(\\\\\))?$/u', $s) === 1 && ($dopo === '' || preg_match('/^[CF]/u', $dopo) === 1)) {
                return ['°', 'grado'];
            }
            if (preg_match('/\d\s$/u', $s) === 1 && preg_match('/^[CF]\b/u', $dopo) === 1) {
                return ['°', 'grado'];
            }
        }
        if ($prima !== '' || $dopo !== '') {
            if ($lunghezza !== 2) {
                if (
                    $lunghezza === 3 && $prima !== '' && $dopo !== ''
                    && preg_match('/^(l|d|un|dell|all|nell|dall|sull|quell|c|s|n|m|t|v|po)$/iu', $prima) === 1
                ) {
                    return ['’', 'apostrofo'];
                }
                return [null, 'parola_lunghezza_' . $lunghezza];
            }
            $candidati = [];
            $forma = '/^' . preg_quote(mb_strtolower($prima), '/') . '(.)' . preg_quote(mb_strtolower($dopo), '/') . '$/u';
            foreach ($this->dizionario as $parola => $quante) {
                if (preg_match($forma, (string)$parola, $x) === 1 && strlen($x[1]) === 2) {
                    $candidati[$x[1]] = ($candidati[$x[1]] ?? 0) + $quante;
                }
            }
            $intera = $prima . $dopo;
            $maiuscola = mb_strlen($intera) > 1 && mb_strtoupper($intera) === $intera && mb_strtolower($intera) !== $intera;
            if (count($candidati) === 1) {
                $c = (string)array_key_first($candidati);
                return [$maiuscola ? mb_strtoupper($c) : $c, mb_strlen($intera) <= 1 ? 'dizionario_parola_corta' : 'dizionario'];
            }
            if (count($candidati) > 1) {
                return [null, 'dizionario_ambiguo'];
            }
            if ($dopo === '' && preg_match('/it$/iu', $prima) === 1) {
                return [mb_strtoupper($prima) === $prima ? 'À' : 'à', 'suffisso_ita'];
            }
            return [null, 'parola_sconosciuta'];
        }
        if ($lunghezza === 2) {
            // Spazio non separabile: accanto a formule, graffe, a capo o ad altri spazi persi.
            if (preg_match('/[})\]\\\\]$|\n$|\?\?\s?$/u', $s) === 1 || preg_match('/^(\\\\|\n|\s?\?\?)/u', $d) === 1) {
                return ["\u{00A0}", 'nbsp'];
            }
            $seguente = preg_match('/^\s*(\p{L}+)/u', $d, $n) === 1 ? $n[1] : '';
            if (preg_match("/\p{Lu}{2,}'$/u", $s) === 1 || (mb_strlen($seguente) > 1 && mb_strtoupper($seguente) === $seguente)) {
                return ['È', 'isolata_maiuscola'];
            }
            if (preg_match('/^[\s"]/u', $d) === 1) {
                return ['è', 'isolata'];
            }
            return [null, 'isolata_altro'];
        }
        return [null, 'isolata_lunghezza_' . $lunghezza];
    }

    /**
     * Le righe approvate dell'elenco, come sostituzioni da aggiungere alla patch.
     *
     * Una riga vale se «approvata» è un sì e il testo può stare al posto della
     * corsa (stessa lunghezza, UTF-8, solo byte non ASCII o «?»). Le righe che
     * non valgono si restituiscono a parte, con il motivo: niente si scarta in
     * silenzio.
     *
     * @param array<string,mixed> $censimento
     * @param list<array<string,string>> $righe righe del CSV, per nome di colonna
     * @param array{formato:string, censimento:string, mappe:list<array<string,mixed>>} $patch
     * @return array{patch: array{formato:string, censimento:string, mappe:list<array<string,mixed>>}, aggiunte:int, scartate:list<array{chiave:string, motivo:string}>}
     */
    public static function conApprovate(array $censimento, array $righe, array $patch): array
    {
        $approvate = [];
        $scartate = [];
        foreach ($righe as $riga) {
            $chiave = trim((string)($riga['chiave'] ?? ''));
            if (!in_array(mb_strtolower(trim((string)($riga['approvata'] ?? ''))), self::SI, true)) {
                continue;
            }
            $testo = self::daScritto((string)($riga['testo'] ?? ''));
            $approvate[$chiave] = $testo;
        }

        $perId = [];
        foreach ($patch['mappe'] as $i => $m) {
            $perId[(int)$m['id']] = $i;
        }
        $aggiunte = 0;
        $usate = [];
        foreach ((array)($censimento['mappe'] ?? []) as $m) {
            $sha = (string)$m['sha256'];
            foreach ((array)$m['corse'] as $c) {
                $chiave = substr($sha, 0, 16) . ':' . (int)$c['offset'];
                if (!array_key_exists($chiave, $approvate)) {
                    continue;
                }
                $usate[$chiave] = true;
                $testo = $approvate[$chiave];
                if (!RipristinoCaratteri::testoAmmesso((string)$c['corsa'], $testo)) {
                    $scartate[$chiave] = ['chiave' => $chiave, 'motivo' => 'testo_non_ammesso (stessa lunghezza in byte della corsa, UTF-8, niente lettere ASCII)'];
                    continue;
                }
                $sostituzione = [
                    'offset' => (int)$c['offset'], 'corsa' => (string)$c['corsa'], 'testo' => $testo,
                    'sinistra' => (string)$c['sinistra'], 'destra' => (string)$c['destra'],
                    'fonte' => RipristinoCaratteri::FONTE_APPROVATA,
                ];
                $id = (int)$m['id'];
                if (!isset($perId[$id])) {
                    $patch['mappe'][] = [
                        'id' => $id, 'teacher_id' => (int)$m['teacher_id'], 'sha256' => $sha,
                        'map_version' => (int)$m['map_version'], 'sostituzioni' => [],
                    ];
                    $perId[$id] = array_key_last($patch['mappe']);
                }
                $esistenti = array_column((array)$patch['mappe'][$perId[$id]]['sostituzioni'], 'offset');
                if (in_array((int)$c['offset'], $esistenti, true)) {
                    $scartate[$chiave] = ['chiave' => $chiave, 'motivo' => 'la corsa ha già una sostituzione automatica'];
                    continue;
                }
                $patch['mappe'][$perId[$id]]['sostituzioni'][] = $sostituzione;
                $aggiunte++;
            }
        }
        foreach (array_keys($approvate) as $chiave) {
            if (!isset($usate[$chiave])) {
                $scartate[$chiave] = ['chiave' => (string)$chiave, 'motivo' => 'chiave non presente nel censimento'];
            }
        }
        return ['patch' => $patch, 'aggiunte' => $aggiunte, 'scartate' => array_values($scartate)];
    }

    /** Nel CSV lo spazio non separabile si scrive «\u00A0»: a vista non si distingue. */
    public static function perScrivere(string $testo): string
    {
        return str_replace("\u{00A0}", self::SEGNO_NBSP, $testo);
    }

    public static function daScritto(string $testo): string
    {
        return (string)preg_replace_callback(
            '/\\\\u([0-9A-Fa-f]{4})/',
            static fn(array $m): string => mb_chr((int)hexdec($m[1]), 'UTF-8') ?: $m[0],
            $testo,
        );
    }

    private static function perLeggere(string $s): string
    {
        return self::perScrivere(str_replace(["\r\n", "\n", "\r", "\t"], ['⏎', '⏎', '⏎', ' '], $s));
    }
}
