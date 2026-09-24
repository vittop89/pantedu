<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;
use PDO;

/**
 * ADR-037, fase 1 — le domande «c'è una pubblicazione…?» in un posto solo.
 *
 * Una pubblicazione è un contenuto in una scuola, in una terna, con uno stato
 * (`content_publications`, migrazione 117). Nella fase 1 le scrivono i
 * trigger dalle colonne della riga e dai bersagli; chi legge chiede qui.
 * Ogni lettore che decide chi vede un contenuto — elenchi dello studio, barra
 * del docente, studenti, credenziali, pool, dettaglio per id, mappe — usa
 * questi frammenti, così la regola non si ricopia con piccole differenze in
 * dieci posti (è così che la coincidenza di sigle è sopravvissuta).
 *
 * I frammenti SQL tornano come coppia [sql, argomenti], con i segnaposto
 * nell'ordine in cui compaiono. `$riga` è l'espressione della query esterna
 * che porta l'id del documento: per un contenuto anche il suo `content_type`
 * (per esempio `teacher_content` o l'alias `tc`), per una verifica le sigle
 * della vista `verifica_documents` (per esempio l'alias `vd`).
 *
 * ADR-037, fase 3 — le verifiche stanno nella stessa tabella, con la loro
 * colonna (`verifica_document_id`): ogni domanda prende la fonte, e le
 * regole restano le stesse. Due differenze, che dipendono dalla verifica e
 * non dal lettore: non esiste un «generale» (una verifica è sempre per una
 * classe), e negli anni passati conta sempre la marca «visibile anche dopo
 * l'anno».
 */
final class Pubblicazioni
{
    /** Le due fonti: un contenuto (`teacher_content_data`) o una verifica (`verifica_documents_data`). */
    public const CONTENUTO = 'teacher_content';
    public const VERIFICA  = 'verifica_documents';

    /** La colonna di `content_publications` che punta al documento di quella fonte. */
    private static function colonna(string $fonte): string
    {
        return $fonte === self::VERIFICA ? 'verifica_document_id' : 'teacher_content_id';
    }

    /**
     * EXISTS: il documento ha almeno una pubblicazione che risponde a tutte le
     * condizioni date.
     *
     * @param ?int         $scuola      null = in qualunque scuola
     * @param list<string> $classi      sigle di classe ammesse; vuoto = qualunque classe
     * @param ?string      $indirizzo   sigla del corso; null = qualunque
     * @param ?string      $stato       'published' | 'draft' | 'archived'; null = qualunque
     * @param bool         $principale  solo la principale
     * @param bool         $archivio    anni passati: una verifica conta solo se archive_visible
     * @param ?string      $materia     sigla della materia DELLA PUBBLICAZIONE; null = qualunque.
     *                                  Dalla fase 2 una pubblicazione in un'altra scuola può avere
     *                                  una sigla diversa da quella della riga («MATE»)
     * @param bool         $ancheSenza  indirizzo e classe chiesti valgono anche per le
     *                                  pubblicazioni che non li hanno (l'«include_unscoped» dell'elenco)
     * @param string       $fonte       self::CONTENUTO | self::VERIFICA
     * @return array{0:string,1:list<int|string>}
     */
    public static function esiste(
        string $riga,
        ?int $scuola = null,
        array $classi = [],
        ?string $indirizzo = null,
        ?string $stato = 'published',
        bool $principale = false,
        bool $archivio = false,
        ?string $materia = null,
        bool $ancheSenza = false,
        string $fonte = self::CONTENUTO,
    ): array {
        $join  = '';
        $dove  = ['p.' . self::colonna($fonte) . " = {$riga}.id"];
        $args  = [];
        if ($indirizzo !== null) {
            $join  .= ' LEFT JOIN curriculum_entries pi ON pi.id = p.indirizzo_id';
            $dove[] = $ancheSenza ? '(pi.code = ? OR p.indirizzo_id IS NULL)' : 'pi.code = ?';
            $args[] = $indirizzo;
        }
        $classi = array_values(array_unique(array_map('strval', $classi)));
        if ($classi !== []) {
            $join  .= ' LEFT JOIN curriculum_entries pc ON pc.id = p.classe_id';
            $in = 'pc.code IN (' . implode(',', array_fill(0, count($classi), '?')) . ')';
            $dove[] = $ancheSenza ? "({$in} OR p.classe_id IS NULL)" : $in;
            foreach ($classi as $c) {
                $args[] = $c;
            }
        }
        if ($materia !== null) {
            $join  .= ' JOIN curriculum_entries pm ON pm.id = p.subject_id';
            $dove[] = 'pm.code = ?';
            $args[] = $materia;
        }
        if ($scuola !== null) {
            $dove[] = 'p.institute_id = ?';
            $args[] = $scuola;
        }
        if ($stato !== null) {
            $dove[] = 'p.visibility = ?';
            $args[] = $stato;
        }
        if ($principale) {
            $dove[] = 'p.is_primary = 1';
        }
        if ($archivio) {
            // Negli anni passati una verifica entra solo se il docente l'ha
            // marcata «visibile anche dopo l'anno»: un contenuto di tipo
            // verifica, o una verifica vera e propria.
            $dove[] = $fonte === self::VERIFICA
                ? 'p.archive_visible = 1'
                : "({$riga}.content_type <> 'verifica' OR p.archive_visible = 1)";
        }
        $sql = 'EXISTS (SELECT 1 FROM content_publications p' . $join
            . ' WHERE ' . implode(' AND ', $dove) . ')';
        return [$sql, $args];
    }

    /**
     * Il perimetro di chi guarda da una classe (studente con account o
     * credenziale): una pubblicazione pubblicata per una delle sue classi, o
     * — per un contenuto «generale» — una pubblicazione pubblicata qualsiasi,
     * sempre nella scuola indicata.
     *
     * Riproduce la regola della migrazione 069 (class · classes · general)
     * sulle pubblicazioni: le principali dei contenuti «per più classi» sono
     * in bozza e quelle dei bersagli pubblicate, quindi la stessa domanda
     * vale per tutti e tre gli scope.
     *
     * Una verifica non ha «generale»: il perimetro è solo quello di classe.
     *
     * @param list<string> $classi
     * @return array{0:string,1:list<int|string>}
     */
    public static function perimetroDiClasse(
        string $riga,
        ?int $scuola,
        array $classi,
        ?string $indirizzo,
        bool $archivio = false,
        ?string $materia = null,
        string $fonte = self::CONTENUTO,
    ): array {
        [$perClasse, $a1] = self::esiste($riga, $scuola, $classi, $indirizzo, 'published', false, $archivio, $materia, false, $fonte);
        if ($fonte === self::VERIFICA) {
            return ["({$perClasse})", $a1];
        }
        [$qualsiasi, $a2] = self::esiste($riga, $scuola, [], null, 'published', false, $archivio, $materia);
        return [
            "({$perClasse} OR ({$riga}.publish_scope = 'general' AND {$qualsiasi}))",
            [...$a1, ...$a2],
        ];
    }

    /**
     * Il perimetro di un docente o di un amministratore di istituto che
     * naviga nella scuola S: i contenuti con una pubblicazione in S — la
     * principale o una scelta dal docente (fase 2) — con le sigle chieste
     * sulla pubblicazione; più i contenuti propri che una scuola non l'hanno
     * (senza etichette: restano al proprietario in ogni scuola, come prima),
     * con le sigle chieste sulla riga.
     *
     * Per una verifica `$riga` è la vista `verifica_documents`, dove la
     * materia si chiama `materia` e non `subject_code`.
     *
     * @return array{0:string,1:list<int|string>}
     */
    public static function nellaScuola(
        string $riga,
        int $scuola,
        int $attore,
        ?string $indirizzo = null,
        ?string $classe = null,
        ?string $materia = null,
        bool $ancheSenza = false,
        string $fonte = self::CONTENUTO,
    ): array {
        [$inS, $args] = self::esiste(
            $riga,
            $scuola,
            $classe !== null ? [$classe] : [],
            $indirizzo,
            null,
            false,
            false,
            $materia,
            $ancheSenza,
            $fonte
        );
        if ($attore <= 0) {
            return [$inS, $args];
        }
        $sulla = [];
        $argsRiga = [$attore];
        foreach (['indirizzo' => $indirizzo, 'classe' => $classe] as $col => $valore) {
            if ($valore === null) {
                continue;
            }
            $sulla[] = $ancheSenza ? "({$riga}.{$col} = ? OR {$riga}.{$col} IS NULL)" : "{$riga}.{$col} = ?";
            $argsRiga[] = $valore;
        }
        if ($materia !== null) {
            $sulla[] = $fonte === self::VERIFICA ? "{$riga}.materia = ?" : "{$riga}.subject_code = ?";
            $argsRiga[] = $materia;
        }
        $sql = "({$inS} OR ({$riga}.teacher_id = ?"
            . ' AND NOT EXISTS (SELECT 1 FROM content_publications p0 WHERE p0.' . self::colonna($fonte) . " = {$riga}.id)"
            . ($sulla !== [] ? ' AND ' . implode(' AND ', $sulla) : '')
            . '))';
        return [$sql, [...$args, ...$argsRiga]];
    }

    // ── Domande puntuali ─────────────────────────────────────────────────

    /** Il documento ha una pubblicazione (nello stato dato) in quella scuola? */
    public static function haPubblicazione(int $contenuto, ?int $scuola, ?string $stato = 'published', string $fonte = self::CONTENUTO): bool
    {
        $dove = [self::colonna($fonte) . ' = ?'];
        $args = [$contenuto];
        if ($scuola !== null) {
            $dove[] = 'institute_id = ?';
            $args[] = $scuola;
        }
        if ($stato !== null) {
            $dove[] = 'visibility = ?';
            $args[] = $stato;
        }
        $st = Database::connection()->prepare(
            'SELECT 1 FROM content_publications WHERE ' . implode(' AND ', $dove) . ' LIMIT 1'
        );
        $st->execute($args);
        return (bool)$st->fetchColumn();
    }

    /**
     * Il contenuto è pubblicato in una scuola in cui lavorano sia l'attore sia
     * il proprietario? È il confine del pool e delle condivisioni (ADR-037):
     * prima bastava che i due docenti avessero una scuola in comune, anche se
     * il contenuto stava in un'altra.
     */
    public static function inScuolaComune(int $contenuto, int $attore, int $proprietario, string $fonte = self::CONTENUTO): bool
    {
        $st = Database::connection()->prepare(
            'SELECT 1 FROM content_publications p
               JOIN teacher_institutes ta ON ta.institute_id = p.institute_id AND ta.user_id = ?
               JOIN teacher_institutes tp ON tp.institute_id = p.institute_id AND tp.user_id = ?
              WHERE p.' . self::colonna($fonte) . ' = ?
              LIMIT 1'
        );
        $st->execute([$attore, $proprietario, $contenuto]);
        return (bool)$st->fetchColumn();
    }

    /**
     * Le pubblicazioni di un contenuto, con le sigle, per chi deve decidere
     * riga per riga (mappe) o mostrarle (esportazione).
     *
     * @return list<array{id:int,institute_id:int,indirizzo:?string,classe:?string,materia:?string,is_primary:bool,visibility:string,archive_visible:bool}>
     */
    public static function delContenuto(int $contenuto): array
    {
        $st = Database::connection()->prepare(
            'SELECT p.id, p.institute_id, ci.code AS indirizzo, cc.code AS classe, cm.code AS materia,
                    p.is_primary, p.visibility, p.archive_visible
               FROM content_publications p
               LEFT JOIN curriculum_entries ci ON ci.id = p.indirizzo_id
               LEFT JOIN curriculum_entries cc ON cc.id = p.classe_id
               LEFT JOIN curriculum_entries cm ON cm.id = p.subject_id
              WHERE p.teacher_content_id = ?
              ORDER BY p.is_primary DESC, p.institute_id, ci.code, cc.code'
        );
        $st->execute([$contenuto]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'              => (int)$r['id'],
                'institute_id'    => (int)$r['institute_id'],
                'indirizzo'       => $r['indirizzo'] !== null ? (string)$r['indirizzo'] : null,
                'classe'          => $r['classe'] !== null ? (string)$r['classe'] : null,
                'materia'         => $r['materia'] !== null ? (string)$r['materia'] : null,
                'is_primary'      => (bool)$r['is_primary'],
                'visibility'      => (string)$r['visibility'],
                'archive_visible' => (bool)$r['archive_visible'],
            ];
        }
        return $out;
    }
}
