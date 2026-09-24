<?php

declare(strict_types=1);

namespace App\Services\Maps;

use App\Services\Audit\ActivityLogger;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Il ripristino delle lettere accentate nelle mappe, sul server: censimento,
 * prova a secco e scrittura. Le regole stanno in RipristinoCaratteri; qui ci
 * sono il database, i blob e le precauzioni. Lo guida
 * tools/maps/ripristina_caratteri.php (wiki/domains/mappe/mappe-overview.md,
 * «Caratteri persi»).
 *
 * Il percorso è in tre tempi, e solo l'ultimo scrive:
 *
 *   1. `censimento()` legge ogni mappa e dice dove sono le corse di «?», con 24
 *      byte di contesto per lato: il contenuto intero non esce mai dal server;
 *   2. in locale, tools/maps/prepara_ripristino_caratteri.php confronta il
 *      censimento con gli originali del Drive e scrive la patch;
 *   3. `esegui()` applica la patch: a secco dice che cosa cambierebbe, con
 *      `applica` scrive.
 *
 * Le precauzioni della scrittura:
 *
 *   - una mappa modificata negli ultimi 15 minuti si salta (un editor può
 *     essere aperto), a meno di `forza`;
 *   - le sostituzioni passano per le invarianti di RipristinoCaratteri: se il
 *     blob è cambiato dal censimento non si usa l'offset, ma il contesto, che
 *     deve comparire una volta sola;
 *   - prima di scrivere, il file cifrato si copia così com'è in una cartella
 *     separata da maps_enc (si tiene 30 giorni, `pulisciCopie()`); se poi non
 *     si scrive niente, la copia si toglie: nella cartella restano solo i punti
 *     di ritorno veri;
 *   - si scrive con SalvataggioMappa, come l'editor: se la versione è cambiata
 *     fra la lettura e la scrittura non si scrive, e lo si dice;
 *   - si rilegge il blob e si confronta lo sha256. Quando la scrittura è
 *     avvenuta — anche se la rilettura trova altro, perché qualcuno ha scritto
 *     subito dopo — si registra l'evento in audit_activity_log (con l'esito
 *     della rilettura fra i dettagli e `outcome=error`) e una riga per
 *     sostituzione nel registro d'istanza (offset, carattere e fonte, senza il
 *     testo intorno). Una scrittura sui dati del docente non passa mai senza
 *     lasciare traccia dove si guarda dopo.
 */
final class RipristinoCaratteriMappe
{
    public const FORMATO_CENSIMENTO = 'pantedu/ripristino-caratteri/censimento/1';
    public const FORMATO_PATCH      = 'pantedu/ripristino-caratteri/patch/1';

    public const MINUTI_DI_RISPETTO = 15;
    public const GIORNI_DELLE_COPIE = 30;
    public const EVENTO = 'mappa_caratteri_ripristinati';

    public const APPLICATA    = 'applicata';
    public const DA_APPLICARE = 'da_applicare';
    public const INVARIATA    = 'invariata';
    public const RECENTE      = 'saltata_recente';
    public const CONFLITTO    = 'conflitto_di_versione';
    public const NON_TROVATA  = 'non_trovata';
    public const ERRORE       = 'errore';

    private PDO $pdo;
    private MapBlobStore $blob;
    private SalvataggioMappa $salvataggio;
    private string $cartellaCopie;
    private ?string $registro;

    /**
     * Proprietà scritte per esteso, non promosse nel costruttore: semgrep non
     * legge quella forma (tools/ci/cancello-semgrep.mjs, file non letti).
     *
     * @param string $cartellaCopie dove vanno le copie cifrate prima della scrittura (fuori da maps_enc)
     * @param string|null $registro file TSV del registro d'istanza; null = non si scrive
     * @param SalvataggioMappa|null $salvataggio null = quello di sempre, sugli stessi pdo e blob
     */
    public function __construct(
        PDO $pdo,
        MapBlobStore $blob,
        string $cartellaCopie,
        ?string $registro = null,
        ?SalvataggioMappa $salvataggio = null,
    ) {
        $this->pdo = $pdo;
        $this->blob = $blob;
        $this->salvataggio = $salvataggio ?? new SalvataggioMappa($pdo, $blob);
        $this->cartellaCopie = rtrim($cartellaCopie, '/');
        $this->registro = $registro;
    }

    /**
     * Sola lettura: le corse di «?» di ogni mappa, con il contesto, e i titoli
     * e gli argomenti che ne hanno.
     *
     * @param list<int> $ids
     * @return array{
     *     formato:string, generato:string, contesto:int,
     *     mappe:list<array<string,mixed>>,
     *     titoli:list<array{id:int, teacher_id:int, campo:string, testo:string}>,
     *     riepilogo:array<string,mixed>
     * }
     */
    public function censimento(?int $docente = null, array $ids = []): array
    {
        $sql = 'SELECT id, teacher_id, title, topic, map_version, updated_at, map_blob_path
                FROM teacher_content_data WHERE content_subtype = "mappa"';
        $parametri = [];
        if ($docente !== null) {
            $sql .= ' AND teacher_id = ?';
            $parametri[] = $docente;
        }
        if ($ids !== []) {
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($parametri, ...$ids);
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY id');
        $st->execute($parametri);

        $riepilogo = [
            'righe' => 0, 'senza_blob' => 0, 'decifrate' => 0, 'errori' => [],
            'con_corse' => 0, 'corse' => 0, 'utf8_non_valido' => 0, 'percorso_di_altro_docente' => 0,
            'pagine_compresse' => 0, 'corse_in_pagine_compresse' => 0, 'titoli_con_corse' => 0,
        ];
        $mappe = [];
        $titoli = [];
        while (($riga = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $riepilogo['righe']++;
            $id = (int)$riga['id'];
            $tid = (int)$riga['teacher_id'];
            foreach (['title', 'topic'] as $campo) {
                $valore = (string)($riga[$campo] ?? '');
                if (preg_match('/\?{2,}/', $valore) === 1) {
                    $titoli[] = ['id' => $id, 'teacher_id' => $tid, 'campo' => $campo, 'testo' => $valore];
                    $riepilogo['titoli_con_corse']++;
                }
            }
            $percorso = (string)($riga['map_blob_path'] ?? '');
            if ($percorso === '') {
                $riepilogo['senza_blob']++;
                continue;
            }
            if (!str_starts_with($percorso, $tid . '/')) {
                $riepilogo['percorso_di_altro_docente']++;
            }
            try {
                $xml = $this->blob->get($tid, $percorso);
            } catch (Throwable $e) {
                $riepilogo['errori'][$e->getMessage()] = ($riepilogo['errori'][$e->getMessage()] ?? 0) + 1;
                continue;
            }
            $riepilogo['decifrate']++;
            $compresse = RipristinoCaratteri::pagineCompresse($xml);
            $riepilogo['pagine_compresse'] += $compresse['pagine'];
            $riepilogo['corse_in_pagine_compresse'] += $compresse['corse'];
            $utf8 = preg_match('//u', $xml) === 1;
            if (!$utf8) {
                $riepilogo['utf8_non_valido']++;
            }
            $corse = RipristinoCaratteri::corse($xml);
            if ($corse === [] && $compresse['corse'] === 0) {
                continue;
            }
            $riepilogo['con_corse'] += $corse === [] ? 0 : 1;
            $riepilogo['corse'] += count($corse);
            $elenco = [];
            foreach ($corse as $c) {
                $fine = $c['offset'] + strlen($c['corsa']);
                $elenco[] = [
                    'offset'   => $c['offset'],
                    'corsa'    => $c['corsa'],
                    // Senza UTF-8 valido il contesto non si può scrivere in JSON: la
                    // mappa si segnala, e in patch non entra (lo strumento locale la scarta).
                    'sinistra' => $utf8 ? RipristinoCaratteri::contestoSinistro($xml, $c['offset']) : '',
                    'destra'   => $utf8 ? RipristinoCaratteri::contestoDestro($xml, $fine) : '',
                ];
            }
            $mappe[] = [
                'id'                        => $id,
                'teacher_id'                => $tid,
                'titolo'                    => (string)$riga['title'],
                'map_version'               => (int)$riga['map_version'],
                'updated_at'                => (string)$riga['updated_at'],
                'sha256'                    => hash('sha256', $xml),
                'byte'                      => strlen($xml),
                'utf8_valido'               => $utf8,
                'pagine_compresse'          => $compresse['pagine'],
                'corse_in_pagine_compresse' => $compresse['corse'],
                'corse'                     => $elenco,
            ];
            unset($xml);
        }

        return [
            'formato'   => self::FORMATO_CENSIMENTO,
            'generato'  => gmdate('Y-m-d\TH:i:s\Z'),
            'contesto'  => RipristinoCaratteri::CONTESTO,
            'mappe'     => $mappe,
            'titoli'    => $titoli,
            'riepilogo' => $riepilogo,
        ];
    }

    /**
     * Controlla la forma della patch e la restituisce normalizzata.
     *
     * @param array<string,mixed> $patch
     * @return list<array{id:int, teacher_id:int, sha256:string, map_version:int, sostituzioni:list<array{offset:int, corsa:string, testo:string, sinistra:string, destra:string, fonte:string}>}>
     */
    public static function mappeDellaPatch(array $patch): array
    {
        if (($patch['formato'] ?? null) !== self::FORMATO_PATCH || !is_array($patch['mappe'] ?? null)) {
            throw new InvalidArgumentException('patch_formato_sconosciuto');
        }
        $mappe = [];
        foreach ($patch['mappe'] as $i => $m) {
            if (
                !is_array($m) || !is_int($m['id'] ?? null) || !is_int($m['teacher_id'] ?? null)
                || !is_string($m['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $m['sha256']) !== 1
                || !is_int($m['map_version'] ?? null) || !is_array($m['sostituzioni'] ?? null)
            ) {
                throw new InvalidArgumentException("patch_mappa_non_valida:$i");
            }
            $sostituzioni = [];
            foreach ($m['sostituzioni'] as $j => $s) {
                if (
                    !is_array($s) || !is_int($s['offset'] ?? null) || !is_string($s['corsa'] ?? null)
                    || !is_string($s['testo'] ?? null) || !is_string($s['sinistra'] ?? null)
                    || !is_string($s['destra'] ?? null) || !is_string($s['fonte'] ?? null)
                ) {
                    throw new InvalidArgumentException("patch_sostituzione_non_valida:{$m['id']}:$j");
                }
                $sostituzioni[] = [
                    'offset' => $s['offset'], 'corsa' => $s['corsa'], 'testo' => $s['testo'],
                    'sinistra' => $s['sinistra'], 'destra' => $s['destra'], 'fonte' => $s['fonte'],
                ];
            }
            $mappe[] = [
                'id' => $m['id'], 'teacher_id' => $m['teacher_id'], 'sha256' => $m['sha256'],
                'map_version' => $m['map_version'], 'sostituzioni' => $sostituzioni,
            ];
        }
        return $mappe;
    }

    /**
     * Prova a secco (default) o applicazione della patch.
     *
     * @param array<string,mixed> $patch
     * @param array{ids?:list<int>, docente?:?int, applica?:bool, forza?:bool} $opzioni
     * @return list<array<string,mixed>> un esito per mappa
     */
    public function esegui(array $patch, array $opzioni = []): array
    {
        $ids = $opzioni['ids'] ?? [];
        $docente = $opzioni['docente'] ?? null;
        $applica = (bool)($opzioni['applica'] ?? false);
        $forza = (bool)($opzioni['forza'] ?? false);

        $esiti = [];
        foreach (self::mappeDellaPatch($patch) as $m) {
            if (($ids !== [] && !in_array($m['id'], $ids, true)) || ($docente !== null && $m['teacher_id'] !== $docente)) {
                continue;
            }
            try {
                $esiti[] = $this->unaMappa($m, $applica, $forza);
            } catch (Throwable $e) {
                $esiti[] = ['id' => $m['id'], 'teacher_id' => $m['teacher_id'], 'esito' => self::ERRORE, 'motivo' => $e->getMessage()];
            }
        }
        return $esiti;
    }

    /**
     * @param array{id:int, teacher_id:int, sha256:string, map_version:int, sostituzioni:list<array{offset:int, corsa:string, testo:string, sinistra:string, destra:string, fonte:string}>} $m
     * @return array<string,mixed>
     */
    private function unaMappa(array $m, bool $applica, bool $forza): array
    {
        $esito = ['id' => $m['id'], 'teacher_id' => $m['teacher_id']];
        $st = $this->pdo->prepare(
            'SELECT teacher_id, map_blob_path, map_version,
                    (updated_at > NOW() - INTERVAL ' . self::MINUTI_DI_RISPETTO . ' MINUTE) AS recente
             FROM teacher_content_data WHERE id = ? AND content_subtype = "mappa" LIMIT 1'
        );
        $st->execute([$m['id']]);
        $riga = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($riga) || (int)$riga['teacher_id'] !== $m['teacher_id'] || (string)$riga['map_blob_path'] === '') {
            return $esito + ['esito' => self::NON_TROVATA];
        }
        $recente = (int)$riga['recente'] === 1;
        $versione = (int)$riga['map_version'];
        $percorso = (string)$riga['map_blob_path'];
        $esito += ['versione_prima' => $versione, 'recente' => $recente];
        if ($applica && $recente && !$forza) {
            return $esito + ['esito' => self::RECENTE];
        }

        $xml = $this->blob->get($m['teacher_id'], $percorso);
        $shaPrima = hash('sha256', $xml);
        $r = RipristinoCaratteri::applica($xml, $m['sostituzioni'], $m['sha256']);
        $esito += [
            'per_offset' => $r['per_offset'],
            'applicate'  => $r['applicate'],
            'conflitti'  => $r['conflitti'],
        ];
        if ($r['errore'] !== null) {
            return $esito + ['esito' => self::ERRORE, 'motivo' => $r['errore']];
        }
        if ($r['applicate'] === []) {
            return $esito + ['esito' => self::INVARIATA];
        }
        if (!$applica) {
            return $esito + ['esito' => self::DA_APPLICARE];
        }

        // Il file cifrato così com'è, prima di toccarlo.
        $copia = $this->cartellaCopie . '/' . $m['teacher_id'] . '/'
            . basename($percorso, '.bin') . '-' . gmdate('Ymd-His') . '.bin';
        $this->blob->copiaCifrata($percorso, $copia);
        $esito['copia'] = $copia;

        $salvata = $this->salvataggio->sovrascrivi($m['id'], $m['teacher_id'], $r['xml'], $versione);
        if ($salvata['esito'] !== SalvataggioMappa::SALVATA) {
            // Non si è scritto niente: la copia non è un punto di ritorno, e
            // lasciarla la renderebbe indistinguibile da quelle che lo sono
            // (stesso nome, cambia solo la data). Chi più tardi prendesse
            // questa per tornare indietro rimetterebbe un disegno più vecchio
            // del lavoro fatto dal docente nel frattempo.
            @unlink($copia);
            unset($esito['copia']);
            return $esito + [
                'esito'  => $salvata['esito'] === SalvataggioMappa::CONFLITTO ? self::CONFLITTO : self::ERRORE,
                'motivo' => $salvata['esito'],
            ];
        }

        // Da qui in poi la mappa È cambiata: qualunque cosa dica la rilettura,
        // della scrittura deve restare traccia dove si guarda dopo. L'esito
        // della rilettura entra nei dettagli e nel registro, non li sostituisce.
        $shaAtteso = hash('sha256', $r['xml']);
        $shaDopo = hash('sha256', $this->blob->get($m['teacher_id'], $percorso));
        $riletturaUguale = hash_equals($shaAtteso, $shaDopo);
        $esitoScrittura = $riletturaUguale ? self::APPLICATA : 'rilettura_diversa';

        $fonti = [];
        foreach ($r['applicate'] as $a) {
            $fonte = (string)($a['fonte'] ?? '');
            $fonti[$fonte] = ($fonti[$fonte] ?? 0) + 1;
        }
        ksort($fonti);
        $versioneDopo = (int)($salvata['versione'] ?? $versione + 1);
        ActivityLogger::event(
            self::EVENTO,
            subjectType: 'teacher_content',
            subjectId: (string)$m['id'],
            details: [
                'docente'         => $m['teacher_id'],
                'esito'           => $esitoScrittura,
                'sostituzioni'    => count($r['applicate']),
                'fonti'           => $fonti,
                'conflitti'       => count($r['conflitti']),
                'per_offset'      => $r['per_offset'],
                'versione_prima'  => $versione,
                'versione_dopo'   => $versioneDopo,
                'sha256_prima'    => $shaPrima,
                'sha256_atteso'   => $shaAtteso,
                'sha256_dopo'     => $shaDopo,
                'copia'           => basename($copia),
            ],
            outcome: $riletturaUguale ? 'ok' : 'error',
            actorName: 'ripristina_caratteri',
            actorRole: 'cli',
        );
        $this->registra($m['id'], $m['teacher_id'], $r['applicate'], $versione, $versioneDopo, $esitoScrittura);

        if (!$riletturaUguale) {
            return $esito + [
                'esito'         => self::ERRORE,
                'motivo'        => 'rilettura_diversa',
                'versione_dopo' => $versioneDopo,
                'sha256_atteso' => $shaAtteso,
                'sha256_dopo'   => $shaDopo,
            ];
        }

        return $esito + ['esito' => self::APPLICATA, 'versione_dopo' => $versioneDopo, 'sha256_dopo' => $shaDopo];
    }

    /**
     * Una riga per sostituzione nel registro d'istanza: quando, quale mappa,
     * dove, quale carattere e da quale fonte. Il testo intorno non si scrive.
     *
     * Nove colonne, separate da tabulazione:
     * `quando, mappa, docente, offset, testo, fonte, versione_prima,
     * versione_dopo, esito` — dove `esito` è `applicata` se la rilettura ha
     * confermato la scrittura, `rilettura_diversa` se qualcuno ha scritto
     * sopra subito dopo (la riga descrive quello che lo strumento ha scritto,
     * non per forza quello che c'è adesso nel blob).
     *
     * @param list<array<string,mixed>> $applicate
     */
    private function registra(int $id, int $tid, array $applicate, int $prima, int $dopo, string $esito): void
    {
        if ($this->registro === null) {
            return;
        }
        $righe = '';
        $quando = gmdate('Y-m-d\TH:i:s\Z');
        foreach ($applicate as $a) {
            $righe .= implode("\t", [
                $quando, $id, $tid, (int)$a['posizione'], (string)$a['testo'], (string)($a['fonte'] ?? ''), $prima, $dopo, $esito,
            ]) . "\n";
        }
        $cartella = dirname($this->registro);
        if (!is_dir($cartella)) {
            @mkdir($cartella, 0o770, true);
        }
        if (@file_put_contents($this->registro, $righe, FILE_APPEND | LOCK_EX) === false) {
            // La scrittura è già avvenuta e l'evento è in audit_activity_log: si
            // annota dove qualcuno lo leggerà, senza far fallire il ripristino.
            error_log('[ripristina_caratteri] registro non scritto: ' . $this->registro);
        }
    }

    /**
     * Le copie cifrate più vecchie di `$giorni`; con `$applica` si cancellano.
     *
     * @return list<string> i percorsi relativi alla cartella delle copie
     */
    public function pulisciCopie(bool $applica, int $giorni = self::GIORNI_DELLE_COPIE, ?int $adesso = null): array
    {
        if (!is_dir($this->cartellaCopie)) {
            return [];
        }
        $limite = ($adesso ?? time()) - $giorni * 86400;
        $vecchie = [];
        $voci = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cartellaCopie, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($voci as $voce) {
            if (!$voce instanceof \SplFileInfo || !$voce->isFile() || !str_ends_with($voce->getFilename(), '.bin')) {
                continue;
            }
            if ($voce->getMTime() < $limite) {
                $vecchie[] = substr($voce->getPathname(), strlen($this->cartellaCopie) + 1);
                if ($applica) {
                    @unlink($voce->getPathname());
                }
            }
        }
        sort($vecchie);
        return $vecchie;
    }
}
