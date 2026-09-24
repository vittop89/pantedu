<?php

declare(strict_types=1);

namespace App\Support;

use App\Repositories\TeacherContentRepository;

/**
 * Che cosa la barra laterale sa di una voce senza aprirla: se ha un corpo da
 * scaricare come ZIP TeX (il pulsante 📥) e i ruoli D/C/R del documento.
 *
 * Il calcolo stava in due copie (ContentStudyController e PublicStudyController)
 * e mancava in `/api/teacher/content`, da cui leggono il pannello Verifiche
 * disegnato per categoria e le sidepage Risorse docente e BES/DSA. Tre risposte
 * diverse per la stessa riga: il 📥 compariva e spariva a seconda di chi aveva
 * disegnato la voce (19/9/2026, analisi C-export-bodypt).
 *
 * La regola del 📥 è questa: c'è un corpo da esportare solo se il contenuto non
 * è una mappa e il suo `body_pt` ha del contenuto, cioè non è un segnaposto.
 *
 *   - Una mappa è il suo file drawio, il link o il file caricato: lo ZIP TeX
 *     converte soltanto `metadata.body_pt`, e di una mappa non porterebbe niente.
 *   - Il segnaposto è un `body_pt` fatto solo di titoli di sezione e di blocchi
 *     vuoti: lo scheletro «Esercizi per studenti / Verifiche» che il modale
 *     semina negli esercizi, il cui contenuto vero sta nel contratto, o la
 *     prima sezione vuota di un documento appena creato. Lo ZIP porterebbe via
 *     solo i titoli.
 *
 * La stessa regola del segnaposto la usa la pagina di studio per decidere se un
 * esercizio si rende dal `body_pt` o dal contratto (StudyPageRenderer).
 */
final class RigheDellaBarra
{
    /** I ruoli che un documento può avere, nell'ordine in cui si mostrano. */
    private const RUOLI = ['D', 'C', 'R'];

    /**
     * Aggiunge `has_body_pt` e `doc_roles` a ogni riga, dai suoi metadati.
     *
     * @param list<array<string,mixed>> $righe righe con `metadata_json` (o `metadata`)
     * @return list<array<string,mixed>>
     */
    public static function arricchisci(array $righe): array
    {
        // Il numero che le verifiche prendono dal loro esercizio: una query
        // sola per tutta la risposta, e solo se ci sono verifiche da abbinare.
        $verifiche = array_values(array_filter(
            $righe,
            static fn(array $r): bool => (string)($r['content_type'] ?? '') === 'verifica'
        ));
        $numeri = $verifiche === []
            ? []
            : self::abbinaNumeri($verifiche, self::eserciziCandidati($verifiche));

        foreach ($righe as &$riga) {
            $riga = array_merge($riga, self::perLaRiga($riga));
            $numero = $numeri[(int)($riga['id'] ?? 0)] ?? null;
            if ($numero !== null && (string)($riga['content_type'] ?? '') === 'verifica') {
                $riga['numero_esercizio'] = $numero;
            }
        }
        unset($riga);
        return $righe;
    }

    /**
     * Il numero dell'argomento che una verifica prende dal suo esercizio.
     *
     * Una verifica nasce da un esercizio, e nella riga ne porta il **titolo**
     * dentro `topic`: è la chiave con cui il sito ritrova «le verifiche di
     * questo argomento». L'esercizio, invece, in `topic` ha il suo numero
     * («3.0»). Nella barra le due voci parlavano quindi due lingue diverse: il
     * numero sull'esercizio, il titolo ripetuto sulla verifica. Dal 20/9/2026
     * la verifica mostra lo stesso numero dell'esercizio da cui viene.
     *
     * L'abbinamento si fa per titolo, dentro lo stesso ambito (docente,
     * materia, indirizzo, classe): in produzione, il 20/9/2026, 36 verifiche
     * su 37 trovano così il loro esercizio. La colonna `source_content_id`
     * esiste ma per le verifiche non la scrive nessuno (misurato: zero righe).
     *
     * Due casi restano senza numero, di proposito:
     *
     *   - la verifica il cui esercizio non c'è più (la 99, nata da un esercizio
     *     poi cancellato);
     *   - quella che ne trova due, perché due esercizi della stessa classe
     *     hanno lo stesso titolo (la 105: uno è numerato «2.0», l'altro è una
     *     bozza senza numero). Indovinare sarebbe peggio che tacere.
     *
     * @param list<array<string,mixed>> $verifiche righe di tipo verifica
     * @param list<array<string,mixed>> $esercizi  righe candidate (content_type esercizio)
     * @return array<int,string> id della verifica → numero dell'esercizio
     */
    public static function abbinaNumeri(array $verifiche, array $esercizi): array
    {
        $perAmbito = [];
        foreach ($esercizi as $e) {
            // Solo gli esercizi che un numero ce l'hanno: un esercizio il cui
            // `topic` è il titolo (bozza appena creata) non è un'etichetta.
            $numero = trim((string)($e['topic'] ?? ''));
            if ($numero === '' || !preg_match('/^\d+(\.\d+)*$/', $numero)) {
                continue;
            }
            $perAmbito[self::chiaveAmbito($e, (string)($e['title'] ?? ''))][] = $numero;
        }

        $numeri = [];
        foreach ($verifiche as $v) {
            $topic = trim((string)($v['topic'] ?? ''));
            if ($topic === '') {
                continue;
            }
            $candidati = array_unique($perAmbito[self::chiaveAmbito($v, $topic)] ?? []);
            if (count($candidati) === 1) {
                $numeri[(int)($v['id'] ?? 0)] = (string)reset($candidati);
            }
        }
        return $numeri;
    }

    /**
     * La chiave dell'ambito: due contenuti si abbinano solo se stanno nella
     * stessa classe dello stesso docente, per la stessa materia.
     *
     * @param array<string,mixed> $riga
     */
    private static function chiaveAmbito(array $riga, string $titolo): string
    {
        return implode('|', [
            (int)($riga['teacher_id'] ?? 0),
            (string)($riga['subject_code'] ?? ''),
            (string)($riga['indirizzo'] ?? ''),
            (string)($riga['classe'] ?? ''),
            $titolo,
        ]);
    }

    /**
     * Gli esercizi che servono per abbinare queste verifiche, in una query
     * sola: i titoli sono quelli che le verifiche dichiarano in `topic`.
     *
     * @param list<array<string,mixed>> $verifiche
     * @return list<array<string,mixed>>
     */
    private static function eserciziCandidati(array $verifiche): array
    {
        $titoli = [];
        $docenti = [];
        foreach ($verifiche as $v) {
            $t = trim((string)($v['topic'] ?? ''));
            if ($t !== '') {
                $titoli[$t] = true;
                $docenti[(int)($v['teacher_id'] ?? 0)] = true;
            }
        }
        if ($titoli === [] || $docenti === []) {
            return [];
        }
        $segnaTitoli = implode(',', array_fill(0, count($titoli), '?'));
        $segnaDocenti = implode(',', array_fill(0, count($docenti), '?'));
        $sql = "SELECT id, teacher_id, subject_code, indirizzo, classe, topic, title
                  FROM teacher_content
                 WHERE content_type = 'esercizio'
                   AND title IN ($segnaTitoli)
                   AND teacher_id IN ($segnaDocenti)";
        try {
            $st = \App\Core\Database::connection()->prepare($sql);
            $st->execute([...array_keys($titoli), ...array_keys($docenti)]);
            return $st->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            // Senza numero la barra resta com'era: è un'etichetta, non un dato.
            return [];
        }
    }


    /**
     * I due campi per una riga sola.
     *
     * @param array<string,mixed> $riga
     * @return array{has_body_pt: bool, doc_roles: string}
     */
    public static function perLaRiga(array $riga): array
    {
        $meta = self::metadati($riga);
        return [
            'has_body_pt' => self::haCorpoDaEsportare($meta, self::formato($riga)),
            'doc_roles'   => self::ruoli($meta),
        ];
    }

    /**
     * @param array<string,mixed>|null $meta
     * @param string $formato 'map' | 'exercise' | 'document' (TeacherContentRepository::formatOf)
     */
    public static function haCorpoDaEsportare(?array $meta, string $formato): bool
    {
        if ($formato === 'map' || !\is_array($meta)) {
            return false;
        }
        $pt = $meta['body_pt'] ?? null;
        if (!\is_array($pt) || $pt === []) {
            return false;
        }
        return !self::eSegnaposto($pt);
    }

    /**
     * Vero se il body_pt contiene solo titoli di sezione e blocchi con testo
     * vuoto. Qualunque altro tipo di nodo (gruppo di problemi, sezione di un
     * modello, contenuto statico) è contenuto vero.
     *
     * @param array<mixed> $pt
     */
    public static function eSegnaposto(array $pt): bool
    {
        foreach ($pt as $nodo) {
            if (!\is_array($nodo)) {
                continue;
            }
            $tipo = (string)($nodo['_type'] ?? '');
            if ($tipo === 'sectionHeader') {
                continue;
            }
            if ($tipo === 'block') {
                $figli = $nodo['children'] ?? [];
                if (!\is_array($figli)) {
                    return false;
                }
                foreach ($figli as $figlio) {
                    if (\is_array($figlio) && trim((string)($figlio['text'] ?? '')) !== '') {
                        return false;
                    }
                }
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * I ruoli D/C/R del documento come stringa ordinata («», «D», «DC», …).
     *
     * @param array<string,mixed>|null $meta
     */
    public static function ruoli(?array $meta): string
    {
        $scelti = $meta['doc_roles'] ?? null;
        if (!\is_array($scelti) || $scelti === []) {
            return '';
        }
        $scelti = array_map(static fn($r): string => strtoupper((string)$r), $scelti);
        return implode('', array_values(array_filter(
            self::RUOLI,
            static fn(string $r): bool => \in_array($r, $scelti, true)
        )));
    }

    /**
     * @param array<string,mixed> $riga
     * @return array<string,mixed>|null
     */
    private static function metadati(array $riga): ?array
    {
        if (isset($riga['metadata']) && \is_array($riga['metadata'])) {
            return $riga['metadata'];
        }
        $grezzi = $riga['metadata_json'] ?? null;
        if (\is_array($grezzi)) {
            return $grezzi;
        }
        if (!\is_string($grezzi) || $grezzi === '') {
            return null;
        }
        $meta = json_decode($grezzi, true);
        return \is_array($meta) ? $meta : null;
    }

    /** @param array<string,mixed> $riga */
    private static function formato(array $riga): string
    {
        $formato = (string)($riga['content_format'] ?? '');
        if ($formato !== '') {
            return $formato;
        }
        return TeacherContentRepository::formatOf((string)($riga['content_type'] ?? $riga['content_subtype'] ?? ''));
    }
}
