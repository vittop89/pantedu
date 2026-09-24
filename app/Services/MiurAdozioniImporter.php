<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Curriculum\AdozioniRepository;
use App\Support\IndirizzoCodeDeriver;
use App\Support\MiurCurriculumAlias;
use PDO;
use RuntimeException;

/**
 * Ricava indirizzi, SEZIONI, materie e LIBRI di una scuola dal dataset MIUR
 * delle adozioni.
 *
 * SORGENTE
 *   dati.istruzione.it → ambito "Adozioni libri di testo", un dataset per
 *   REGIONE (Lombardia: DS0712ALTLOMBARDIA → ALTLOMBARDIA<data>.csv, ~50 MB).
 *   Licenza IODL 2.0, aggiornato settimanalmente da luglio a novembre: quindi
 *   riflette le classi realmente attive nell'anno in corso.
 *
 *   E' un elenco di adozioni, non un'anagrafe di classi — ed e' proprio questo
 *   a renderlo affidabile: se una classe ha adottato un libro, quella classe
 *   esiste. COMBINAZIONE porta l'indirizzo di studio, SEZIONEANNO la lettera,
 *   sulla stessa riga: e' l'unica fonte che dia l'accoppiata sezione↔indirizzo.
 *   E sulla stessa riga c'e' il libro (ISBN, titolo, autori, editore, volume):
 *   dal 13 settembre 2026 (ADR-036) entra nel catalogo delle adozioni
 *   dell'istituto, da cui i docenti prendono le proprie fonti.
 *
 * DUE FASI, DI PROPOSITO
 *   scan() legge e non scrive: produce il piano, cioe' l'elenco esatto delle
 *   righe che verrebbero create. apply() esegue quel piano e nient'altro.
 *   Separarle non e' una comodita' dell'interfaccia: i codici degli indirizzi
 *   sono PROPOSTI da IndirizzoCodeDeriver, e una volta che i docenti ci hanno
 *   agganciato contenuti cambiarli non e' piu' una scelta ma una migrazione.
 *   La conferma umana sta in mezzo, e deve vedere esattamente cio' che verra'
 *   scritto — non una seconda scansione che potrebbe dare un altro risultato.
 *
 * COSA NON TOCCA
 *   Le voci che il vocabolario ha gia' (etichette comprese) e i libri gia' a
 *   catalogo: un nuovo import aggiunge, non riscrive. Le voci scrive con
 *   `origine = 'miur'`; quelle aggiunte a mano dall'amministratore restano
 *   `istituto` (ADR-035).
 *
 * Il CSV serve solo qui: nessuna parte dell'app lo legge a runtime.
 */
final class MiurAdozioniImporter
{
    /** Colonne senza le quali il file non e' quello giusto. */
    private const RICHIESTE = ['CODICESCUOLA', 'ANNOCORSO', 'SEZIONEANNO', 'COMBINAZIONE'];

    /** Secondaria di II grado: solo li' COMBINAZIONE e' un indirizzo di studio. */
    private const GRADI_II = ['NO', 'NT'];

    private MiurCurriculumAlias $alias;
    private MiurCurriculumAlias $aliasMaterie;

    public function __construct(
        private PDO $pdo,
        ?MiurCurriculumAlias $alias = null,
        ?MiurCurriculumAlias $aliasMaterie = null
    ) {
        $this->alias        = $alias        ?? MiurCurriculumAlias::fromFile('indirizzi');
        $this->aliasMaterie = $aliasMaterie ?? MiurCurriculumAlias::fromFile('materie');
    }

    /**
     * Legge il CSV e ritorna il piano. NON tocca il database.
     *
     * @param  string      $csvPath  file scaricato dal catalogo MIUR
     * @param  string|null $onlyCode limita a un istituto (codice MIUR)
     * @return array{
     *     righe:int,
     *     istituti:list<array{id:int,code:string,name:string,indirizzi:list<array<string,mixed>>,materie:list<array<string,mixed>>,libri:array{letti:int,nuovi:int,gia_a_catalogo:int}}>,
     *     ops:list<array<string,mixed>>,
     *     stats:array{indirizzi:int,sezioni:int,materie:int,sistemate:int,illeggibili:int,libri:int}
     * }
     */
    public function scan(string $csvPath, ?string $onlyCode = null): array
    {
        $istituti = $this->istituti($onlyCode);
        if ($istituti === []) {
            throw new RuntimeException('nessun istituto' . ($onlyCode !== null ? " con codice $onlyCode" : ''));
        }

        [$trovati, $materie, $righe, $libri] = $this->leggi($csvPath, $istituti);
        if ($trovati === []) {
            throw new RuntimeException('nessuna riga per gli istituti a tabella: il file copre la regione giusta?');
        }

        $out = [
            'righe'    => $righe,
            'istituti' => [],
            'ops'      => [],
            'stats'    => ['indirizzi' => 0, 'sezioni' => 0, 'materie' => 0, 'sistemate' => 0, 'illeggibili' => 0, 'libri' => 0],
        ];

        ksort($trovati);
        foreach ($trovati as $cs => $combinazioni) {
            $inst = $istituti[$cs];
            $blocco = $this->pianificaIstituto($inst['id'], $combinazioni, $out);
            $mat    = $this->pianificaMaterie($inst['id'], array_keys($materie[$cs] ?? []), $out);
            $lib    = $this->pianificaLibri($inst['id'], $libri[$cs] ?? [], $blocco, $mat, $out);
            $out['istituti'][] = [
                'id'        => $inst['id'],
                'code'      => (string)$cs,
                'name'      => $inst['name'],
                'indirizzi' => $blocco,
                'materie'   => $mat,
                'libri'     => $lib,
            ];
        }
        return $out;
    }

    /**
     * Esegue il piano prodotto da scan(). Transazionale: o entra tutto o niente.
     *
     * @param  array<string,mixed> $plan
     * @return array{indirizzi:int,sezioni:int,materie:int,sistemate:int,libri:int}
     */
    public function apply(array $plan): array
    {
        /** @var list<array<string,mixed>> $ops */
        $ops = is_array($plan['ops'] ?? null) ? $plan['ops'] : [];
        $fatte = ['indirizzi' => 0, 'sezioni' => 0, 'materie' => 0, 'sistemate' => 0, 'libri' => 0];
        if ($ops === []) {
            return $fatte;
        }

        $insInd = $this->pdo->prepare(
            'INSERT INTO curriculum_entries
                (kind, institute_id, code, label, grp, indirizzo, active, shared_with_pool, origine)
             VALUES ("indirizzi", ?, ?, ?, NULL, NULL, 1, 0, "miur")'
        );
        $insCls = $this->pdo->prepare(
            'INSERT INTO curriculum_entries
                (kind, institute_id, code, label, grp, indirizzo, active, shared_with_pool, origine)
             VALUES ("classi", ?, ?, ?, NULL, ?, 1, 0, "miur")'
        );
        $insMat = $this->pdo->prepare(
            'INSERT INTO curriculum_entries
                (kind, institute_id, code, label, grp, indirizzo, active, shared_with_pool, origine)
             VALUES ("materie", ?, ?, ?, NULL, NULL, 1, 0, "miur")'
        );
        // Solo dove indirizzo E' ancora NULL: se nel frattempo qualcuno l'ha
        // assegnato a mano, la sua scelta vince sulla nostra proposta.
        $updCls = $this->pdo->prepare(
            'UPDATE curriculum_entries SET indirizzo = ?
              WHERE kind = "classi" AND institute_id = ?
                AND code = ? AND indirizzo IS NULL'
        );
        $adozioni = new AdozioniRepository($this->pdo);

        $mia = !$this->pdo->inTransaction();
        if ($mia) {
            $this->pdo->beginTransaction();
        }
        try {
            foreach ($ops as $op) {
                switch ((string)($op['op'] ?? '')) {
                    case 'indirizzo':
                        $insInd->execute([(int)$op['institute_id'], (string)$op['code'], (string)$op['label']]);
                        $fatte['indirizzi']++;
                        break;
                    case 'classe':
                        $insCls->execute([
                            (int)$op['institute_id'], (string)$op['code'],
                            (string)$op['label'], (string)$op['indirizzo'],
                        ]);
                        $fatte['sezioni']++;
                        break;
                    case 'materia':
                        $insMat->execute([(int)$op['institute_id'], (string)$op['code'], (string)$op['label']]);
                        $fatte['materie']++;
                        break;
                    case 'classe_indirizzo':
                        $updCls->execute([(string)$op['indirizzo'], (int)$op['institute_id'], (string)$op['code']]);
                        $fatte['sistemate']++;
                        break;
                    case 'libro':
                        if ($adozioni->inserisci((int)$op['institute_id'], $op, 'miur') > 0) {
                            $fatte['libri']++;
                        }
                        break;
                }
            }
            if ($mia) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($mia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $fatte;
    }

    /** @return array<string, array{id:int,name:string}> codice MIUR → istituto */
    private function istituti(?string $onlyCode): array
    {
        $sql = 'SELECT id, code, name FROM institutes WHERE code IS NOT NULL AND code <> ""';
        $args = [];
        if ($onlyCode !== null && trim($onlyCode) !== '') {
            $sql .= ' AND UPPER(code) = ?';
            $args[] = strtoupper(trim($onlyCode));
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[strtoupper((string)$r['code'])] = [
                'id'   => (int)$r['id'],
                'name' => (string)$r['name'],
            ];
        }
        return $out;
    }

    /**
     * Streaming: il file sta sui 50 MB e in RAM ci finisce solo cio' che
     * riguarda gli istituti a tabella.
     *
     * @param  array<string, array{id:int,name:string}> $istituti
     * @return array{0: array<string, array<string, array<string,bool>>>, 1: array<string, array<string,bool>>, 2: int, 3: array<string, list<array<string,mixed>>>}
     */
    private function leggi(string $csvPath, array $istituti): array
    {
        if (!is_readable($csvPath)) {
            throw new RuntimeException("file non leggibile: $csvPath");
        }
        $fh = fopen($csvPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('apertura fallita');
        }
        try {
            $first = (string)fgets($fh);
            $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            rewind($fh);
            $header = fgetcsv($fh, 0, $delim);
            if ($header === false) {
                throw new RuntimeException('intestazione illeggibile');
            }
            $col = [];
            foreach ($header as $i => $hname) {
                $col[strtoupper(trim((string)$hname, " \t\n\r\0\x0B\"\xEF\xBB\xBF"))] = $i;
            }
            foreach (self::RICHIESTE as $needed) {
                if (!isset($col[$needed])) {
                    throw new RuntimeException(
                        "colonna $needed assente. Trovate: " . implode(', ', array_keys($col))
                    );
                }
            }
            $conLibri = isset($col['TITOLO'], $col['CODICEISBN']);
            $cella = static fn(array $row, string $nome): string =>
                isset($col[$nome]) ? trim((string)($row[$col[$nome]] ?? '')) : '';

            $trovati = [];
            $materie = [];
            $libri   = [];
            $righe = 0;
            while (($row = fgetcsv($fh, 0, $delim)) !== false) {
                $righe++;
                $cs = strtoupper(trim((string)($row[$col['CODICESCUOLA']] ?? '')));
                if ($cs === '' || !isset($istituti[$cs])) {
                    continue;
                }
                $comb = trim((string)($row[$col['COMBINAZIONE']] ?? ''));
                $anno = trim((string)($row[$col['ANNOCORSO']] ?? ''));
                $sez  = strtoupper(trim((string)($row[$col['SEZIONEANNO']] ?? '')));
                if ($comb === '' || !preg_match('/^[1-9]$/', $anno)) {
                    continue;
                }
                // Per primaria e medie COMBINAZIONE contiene il tempo scuola
                // ("CORSO A ORARIO ORDINARIO"), che non e' un indirizzo.
                $grado = isset($col['TIPOGRADOSCUOLA'])
                    ? strtoupper(trim((string)($row[$col['TIPOGRADOSCUOLA']] ?? '')))
                    : '';
                if ($grado !== '' && !in_array($grado, self::GRADI_II, true)) {
                    continue;
                }
                $trovati[$cs][$comb][$anno . $sez] = true;
                // La materia sta sulla stessa riga della classe. Si raccoglie a
                // parte, a livello di scuola: e' il vocabolario dell'istituto,
                // non un attributo della sezione.
                $disc = $cella($row, 'DISCIPLINA');
                if ($disc !== '') {
                    $materie[$cs][$disc] = true;
                }
                // Il libro: stessa riga, ma vive per (classe, disciplina).
                if ($conLibri && $disc !== '') {
                    $isbn = preg_replace('/[^0-9Xx]/', '', $cella($row, 'CODICEISBN')) ?? '';
                    $titolo = $cella($row, 'TITOLO');
                    if ($isbn !== '' && $titolo !== '') {
                        $libri[$cs][] = [
                            'anno_scolastico' => $cella($row, 'ANNOSCOLASTICO') ?: date('Y') . '/' . (date('Y') + 1),
                            'combinazione'    => $comb,
                            'classe'          => $anno . $sez,
                            'disciplina'      => $disc,
                            'isbn'            => strtoupper($isbn),
                            'titolo'          => $titolo,
                            'sottotitolo'     => $cella($row, 'SOTTOTITOLO'),
                            'autori'          => $cella($row, 'AUTORI'),
                            'editore'         => $cella($row, 'EDITORE'),
                            'volume'          => $cella($row, 'VOLUME'),
                            'prezzo'          => $cella($row, 'PREZZO'),
                            'nuova_adozione'  => self::si($cella($row, 'NUOVAADOZ')),
                            'da_acquistare'   => self::si($cella($row, 'DAACQUIST')),
                            'consigliato'     => self::si($cella($row, 'CONSIGLIATO')),
                        ];
                    }
                }
            }
            return [$trovati, $materie, $righe, $libri];
        } finally {
            fclose($fh);
        }
    }

    /** «Si», «S», «SI», «1», «true» → vero. Il dataset non e' coerente da un anno all'altro. */
    private static function si(string $v): bool
    {
        return in_array(strtoupper(trim($v)), ['SI', 'S', '1', 'TRUE', 'VERO', 'X'], true);
    }

    /**
     * I libri della scuola, per (classe, disciplina): quelli che non sono
     * ancora a catalogo diventano operazioni. L'indirizzo e la materia del
     * libro sono le sigle proposte negli stessi blocchi di questo piano, cosi'
     * un libro entra con la sigla che l'amministratore sta confermando.
     *
     * @param  list<array<string,mixed>> $libri
     * @param  list<array<string,mixed>> $blocchiIndirizzi
     * @param  list<array<string,mixed>> $blocchiMaterie
     * @param  array<string,mixed>       $out accumulatore (ops + stats)
     * @return array{letti:int,nuovi:int,gia_a_catalogo:int}
     */
    private function pianificaLibri(int $instId, array $libri, array $blocchiIndirizzi, array $blocchiMaterie, array &$out): array
    {
        $riepilogo = ['letti' => count($libri), 'nuovi' => 0, 'gia_a_catalogo' => 0];
        if ($libri === []) {
            return $riepilogo;
        }
        $indPerDescrizione = [];
        foreach ($blocchiIndirizzi as $b) {
            $indPerDescrizione[(string)$b['descrizione']] = $b['code'] !== null ? (string)$b['code'] : null;
        }
        $matPerDescrizione = [];
        foreach ($blocchiMaterie as $b) {
            $matPerDescrizione[(string)$b['descrizione']] = $b['code'] !== null ? (string)$b['code'] : null;
        }

        $sel = $this->pdo->prepare(
            'SELECT anno_scolastico, classe, isbn, disciplina FROM adozioni_libri WHERE institute_id = ?'
        );
        $sel->execute([$instId]);
        $presenti = [];
        foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $presenti[self::chiaveLibro((string)$r['anno_scolastico'], (string)$r['classe'], (string)$r['isbn'], (string)$r['disciplina'])] = true;
        }

        $visti = [];
        foreach ($libri as $l) {
            $k = self::chiaveLibro((string)$l['anno_scolastico'], (string)$l['classe'], (string)$l['isbn'], (string)$l['disciplina']);
            if (isset($visti[$k])) {
                continue; // la stessa adozione ripetuta nel file
            }
            $visti[$k] = true;
            if (isset($presenti[$k])) {
                $riepilogo['gia_a_catalogo']++;
                continue;
            }
            $riepilogo['nuovi']++;
            $out['stats']['libri']++;
            $out['ops'][] = [
                'op'              => 'libro',
                'institute_id'    => $instId,
                'anno_scolastico' => $l['anno_scolastico'],
                'indirizzo'       => $indPerDescrizione[(string)$l['combinazione']] ?? null,
                'classe'          => $l['classe'],
                'materia'         => $matPerDescrizione[(string)$l['disciplina']] ?? null,
                'disciplina'      => $l['disciplina'],
                'isbn'            => $l['isbn'],
                'titolo'          => $l['titolo'],
                'sottotitolo'     => $l['sottotitolo'],
                'autori'          => $l['autori'],
                'editore'         => $l['editore'],
                'volume'          => $l['volume'],
                'prezzo'          => $l['prezzo'],
                'nuova_adozione'  => $l['nuova_adozione'],
                'da_acquistare'   => $l['da_acquistare'],
                'consigliato'     => $l['consigliato'],
            ];
        }
        return $riepilogo;
    }

    private static function chiaveLibro(string $anno, string $classe, string $isbn, string $disciplina): string
    {
        return $anno . '|' . strtoupper($classe) . '|' . strtoupper($isbn) . '|' . mb_substr($disciplina, 0, 64);
    }

    /**
     * Materie della scuola, dal campo DISCIPLINA delle adozioni.
     *
     * Le materie sono a livello di ISTITUTO e senza indirizzo: il dataset le
     * darebbe anche per indirizzo, ma una materia insegnata al liceo artistico
     * e una insegnata allo scientifico restano la stessa materia — duplicarle
     * per corso creerebbe due "Matematica" fra cui il docente dovrebbe
     * scegliere senza sapere la differenza.
     *
     * @param  list<string>        $discipline
     * @param  array<string,mixed> $out accumulatore (ops + stats)
     * @return list<array<string,mixed>>
     */
    private function pianificaMaterie(int $instId, array $discipline, array &$out): array
    {
        $sel = $this->pdo->prepare(
            'SELECT code, label FROM curriculum_entries
              WHERE kind = "materie" AND institute_id = ?'
        );
        $sel->execute([$instId]);
        $codiciPresi = [];
        $perParole = [];
        // L'etichetta gia' scritta. Per una voce che esiste e' quella che
        // l'utente vede, e l'import non la tocca: mostrare al suo posto la
        // descrizione MIUR farebbe leggere nell'anteprima una modifica che non
        // avverra' — "CHIMICA" dove a registro c'e' "Chimica".
        $labelPerCodice = [];
        foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $codiciPresi[] = strtoupper((string)$r['code']);
            $perParole[implode(' ', IndirizzoCodeDeriver::words((string)$r['label']))] = (string)$r['code'];
            $labelPerCodice[strtoupper((string)$r['code'])] = (string)$r['label'];
        }

        sort($discipline);
        $blocchi = [];
        foreach ($discipline as $descrizione) {
            $chiave = implode(' ', IndirizzoCodeDeriver::words($descrizione));
            $code = $perParole[$chiave] ?? null;
            $ali = $this->aliasMaterie->lookup($descrizione);
            $etichetta = $ali['label'] ?? $descrizione;

            if ($code === null && $ali !== null) {
                $code = $ali['code'];
                $noto = in_array($code, $codiciPresi, true);
                $stato = $noto ? 'alias-unificato' : 'alias-nuovo';
                if (!$noto) {
                    $codiciPresi[] = $code;
                    $out['stats']['materie']++;
                    $out['ops'][] = [
                        'op' => 'materia', 'institute_id' => $instId,
                        'code' => $code, 'label' => $etichetta,
                    ];
                }
                $perParole[$chiave] = $code;
            } elseif ($code !== null) {
                $stato = 'esistente';
            } else {
                $code = IndirizzoCodeDeriver::unique($descrizione, $codiciPresi);
                if ($code === '' || !preg_match('/^[A-Z]{3,6}$/', $code)) {
                    $out['stats']['illeggibili']++;
                    $blocchi[] = ['descrizione' => $descrizione, 'label' => $etichetta,
                                  'code' => null, 'stato' => 'non-derivabile'];
                    continue;
                }
                $codiciPresi[] = $code;
                $perParole[$chiave] = $code;
                $stato = 'nuovo';
                $out['stats']['materie']++;
                $out['ops'][] = [
                    'op' => 'materia', 'institute_id' => $instId,
                    'code' => $code, 'label' => $etichetta,
                ];
            }

            $registrata = $labelPerCodice[strtoupper((string)$code)] ?? null;
            $blocchi[] = [
                'descrizione' => $descrizione,
                'label'       => $registrata ?? $etichetta,
                // L'import non riscrive le etichette esistenti. Se l'alias ne
                // propone una diversa da quella a registro lo si segnala, cosi'
                // la si puo' cambiare a mano sapendo che c'e' una scelta —
                // invece di applicarla di nascosto sopra una che la scuola
                // potrebbe aver messo apposta.
                'label_proposta' => $this->labelDivergente($ali, $registrata, $etichetta),
                'code'  => $code,
                'stato' => $stato,
            ];
        }
        return $blocchi;
    }

    /**
     * @param  array<string, array<string,bool>> $combinazioni descrizione → sezioni
     * @param  array<string,mixed>               $out          accumulatore (ops + stats)
     * @return list<array<string,mixed>>
     */
    private function pianificaIstituto(int $instId, array $combinazioni, array &$out): array
    {
        // Confronto sulle PAROLE, non sulla stringa: a registro sta
        // "Scientifico" e il MIUR scrive "LICEO SCIENTIFICO". Stesso indirizzo,
        // e trattarli come diversi creerebbe il doppione che il guard su
        // CurriculumService::add() esiste per impedire.
        $selInd = $this->pdo->prepare(
            'SELECT code, label FROM curriculum_entries
              WHERE kind = "indirizzi" AND institute_id = ?'
        );
        $selInd->execute([$instId]);
        $codiciPresi = [];
        $perParole = [];
        $labelPerCodice = [];
        foreach ($selInd->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $codiciPresi[] = strtoupper((string)$r['code']);
            $perParole[implode(' ', IndirizzoCodeDeriver::words((string)$r['label']))] = (string)$r['code'];
            $labelPerCodice[strtoupper((string)$r['code'])] = (string)$r['label'];
        }

        $selCls = $this->pdo->prepare(
            'SELECT code, indirizzo FROM curriculum_entries
              WHERE kind = "classi" AND institute_id = ?'
        );
        $selCls->execute([$instId]);
        $clsEsistenti = [];
        foreach ($selCls->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $clsEsistenti[strtoupper((string)$r['code'])] = $r['indirizzo'];
        }

        ksort($combinazioni);
        $blocchi = [];
        foreach ($combinazioni as $descrizione => $sezioni) {
            $descrizione = (string)$descrizione;
            $chiave = implode(' ', IndirizzoCodeDeriver::words($descrizione));
            $code = $perParole[$chiave] ?? null;

            // L'alias ha la precedenza sul derivatore: e' una decisione presa,
            // non un'ipotesi. Puo' puntare a un codice che l'istituto ha gia'
            // (unificando due descrizioni) o introdurne uno leggibile al posto
            // di quello ricavato da una stringa troncata.
            $ali = $this->alias->lookup($descrizione);
            $etichetta = $ali['label'] ?? $descrizione;

            if ($code === null && $ali !== null) {
                $code = $ali['code'];
                $noto = in_array($code, $codiciPresi, true);
                $stato = $noto ? 'alias-unificato' : 'alias-nuovo';
                if (!$noto) {
                    $codiciPresi[] = $code;
                    $out['stats']['indirizzi']++;
                    $out['ops'][] = [
                        'op' => 'indirizzo', 'institute_id' => $instId,
                        'code' => $code, 'label' => $etichetta,
                    ];
                }
                $perParole[$chiave] = $code;
            } elseif ($code !== null) {
                $stato = 'esistente';
            } else {
                $code = IndirizzoCodeDeriver::unique($descrizione, $codiciPresi);
                if ($code === '' || !preg_match('/^[A-Z]{3,6}$/', $code)) {
                    // Senza codice non c'e' niente a cui appendere le sezioni:
                    // si segnala e si lascia all'inserimento a mano.
                    $out['stats']['illeggibili']++;
                    $blocchi[] = [
                        'descrizione' => $descrizione, 'label' => $etichetta, 'code' => null,
                        'stato' => 'non-derivabile', 'sezioni_nuove' => [], 'sezioni_sistemate' => [],
                    ];
                    continue;
                }
                $codiciPresi[] = $code;
                $perParole[$chiave] = $code;
                $stato = 'nuovo';
                $out['stats']['indirizzi']++;
                $out['ops'][] = [
                    'op' => 'indirizzo', 'institute_id' => $instId,
                    'code' => $code, 'label' => $etichetta,
                ];
            }

            ksort($sezioni);
            $nuove = $fix = [];
            foreach (array_keys($sezioni) as $sez) {
                $sez = (string)$sez;
                if (!preg_match('/^[1-9][A-Z0-9]{0,5}$/', $sez)) {
                    continue; // fuori dal dominio di curriculum_entries.classi
                }
                if (!array_key_exists($sez, $clsEsistenti)) {
                    $nuove[] = $sez;
                    $clsEsistenti[$sez] = $code;
                    $out['stats']['sezioni']++;
                    $out['ops'][] = [
                        'op' => 'classe', 'institute_id' => $instId, 'code' => $sez,
                        'label' => 'Classe ' . $sez, 'indirizzo' => $code,
                    ];
                } elseif ($clsEsistenti[$sez] === null) {
                    // Sezione gia' presente ma senza corso: righe create prima
                    // della migration 100, che risultano trasversali e
                    // ricompaiono sotto qualunque indirizzo.
                    $fix[] = $sez;
                    $clsEsistenti[$sez] = $code;
                    $out['stats']['sistemate']++;
                    $out['ops'][] = [
                        'op' => 'classe_indirizzo', 'institute_id' => $instId,
                        'code' => $sez, 'indirizzo' => $code,
                    ];
                }
            }

            $registrata = $labelPerCodice[strtoupper((string)$code)] ?? null;
            $blocchi[] = [
                'descrizione'    => $descrizione,
                'label'          => $registrata ?? $etichetta,
                'label_proposta' => $this->labelDivergente($ali, $registrata, $etichetta),
                'code'           => $code,
                'stato' => $stato, 'sezioni_nuove' => $nuove, 'sezioni_sistemate' => $fix,
            ];
        }
        return $blocchi;
    }

    /**
     * L'etichetta che l'alias propone, quando differisce da quella gia' scritta.
     *
     * Null quando non c'e' niente da segnalare, e in particolare quando l'alias
     * non propone nulla: senza alias $etichetta e' la descrizione MIUR grezza,
     * e segnalare "a registro Chimica, proposta CHIMICA" sarebbe rumore che
     * nasconde le due divergenze vere.
     *
     * @param array{code:string,label:?string,note:?string,miur:string}|null $ali
     */
    private function labelDivergente(?array $ali, ?string $registrata, string $etichetta): ?string
    {
        if ($ali === null || ($ali['label'] ?? null) === null) {
            return null;
        }
        if ($registrata === null || $registrata === $etichetta) {
            return null;
        }
        return $etichetta;
    }
}
