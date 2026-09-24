<?php

declare(strict_types=1);

namespace App\Services\Contenuti;

use App\Core\Database;
use App\Services\Audit\ActivityLogger;
use InvalidArgumentException;
use PDO;

/**
 * ADR-037, fase 3 — «Dove vale» per le verifiche.
 *
 * Una verifica, per chi la usa, è quella che mostra il suo modale: tutte le
 * varianti (A e B, SOL · NOR · DSA · DIS) e tutte le versioni con lo stesso
 * titolo e la stessa materia. Nel database ogni variante è una riga di
 * `verifica_documents_data`, con le sue pubblicazioni. Qui si governano
 * insieme: un posto aggiunto vale per tutte le varianti, uno stato cambiato
 * cambia per tutte, un posto tolto sparisce da tutte.
 *
 * DIFFERENZE DAI CONTENUTI
 *   - Lo stato della principale lo sceglie il docente, da qui: la riga di una
 *     verifica non ha una visibilità da cui derivarlo (migrazione 119).
 *   - Una versione salvata dopo nasce con la sola principale, in bozza: il
 *     posto la mostra «in parte» (N varianti su M) finché non la si pubblica.
 *
 * Pubblicare ai propri studenti non è condividere con i colleghi: il blocco
 * per il diritto d'autore (art. 70-bis) resta sulla condivisione, come per i
 * contenuti.
 *
 * Gli invarianti sono quelli di DoveVale (S1 proprietario, S2 scuola del
 * docente, S3 voci di quella scuola, S6 registro con gli id), con la stessa
 * verifica del posto.
 */
final class DoveValeVerifica
{
    public function __construct(private ?PDO $pdo = null, private ?DoveVale $doveVale = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    private function doveVale(): DoveVale
    {
        return $this->doveVale ??= new DoveVale($this->pdo);
    }

    /**
     * Il titolo senza il suffisso di variante («Frazioni — A_SOL» → «Frazioni»):
     * la stessa regola del modale e della barra (verifica-detail-modal.js).
     */
    public static function titoloBase(string $titolo): string
    {
        return \App\Repositories\VerificaDocumentRepository::titoloBase($titolo);
    }

    /**
     * Le righe della verifica di cui `$verifica` è una variante: stesso
     * docente, stessa materia, stesso titolo base
     * (VerificaDocumentRepository::righeDellaVerifica). S1: per chi non è il
     * proprietario la verifica «non esiste».
     *
     * @return list<int>
     * @throws InvalidArgumentException non_trovato
     */
    public function righe(int $verifica, int $docente): array
    {
        $st = $this->db()->prepare('SELECT teacher_id FROM verifica_documents_data WHERE id = ? LIMIT 1');
        $st->execute([$verifica]);
        $proprietario = $st->fetchColumn();
        if ($proprietario === false || $docente <= 0 || (int)$proprietario !== $docente) {
            throw new InvalidArgumentException('non_trovato');
        }
        return (new \App\Repositories\VerificaDocumentRepository())->righeDellaVerifica($verifica);
    }

    /**
     * I posti della verifica, uno per scuola, indirizzo e classe (la principale a
     * parte), con lo stato comune o «misto» e quante varianti ci sono.
     *
     * @return array{
     *   verifica: array{id:int,titolo:string,varianti:int},
     *   posti: list<array{id:int,scuola_id:int,scuola:string,indirizzo:?string,classe:?string,materia:?string,
     *     indirizzo_id:?int,classe_id:?int,materia_id:?int,principale:bool,stato:string,archivio:bool,varianti:int}>,
     *   limite: int
     * }
     */
    public function elenco(int $verifica, int $docente): array
    {
        $righe = $this->righe($verifica, $docente);
        $in = implode(',', array_fill(0, count($righe), '?'));
        $st = $this->db()->prepare(
            "SELECT p.id, p.verifica_document_id, p.institute_id, COALESCE(i.name, i.code) AS scuola,
                    ci.code AS indirizzo, cc.code AS classe, cm.code AS materia,
                    p.indirizzo_id, p.classe_id, p.subject_id, p.is_primary, p.visibility, p.archive_visible
               FROM content_publications p
               JOIN institutes i ON i.id = p.institute_id
               LEFT JOIN curriculum_entries ci ON ci.id = p.indirizzo_id
               LEFT JOIN curriculum_entries cc ON cc.id = p.classe_id
               LEFT JOIN curriculum_entries cm ON cm.id = p.subject_id
              WHERE p.verifica_document_id IN ({$in})
              ORDER BY p.is_primary DESC, scuola, ci.code, cc.code, p.id"
        );
        $st->execute($righe);
        $posti = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $chiave = implode('|', [(int)$r['is_primary'], (int)$r['institute_id'], (int)$r['indirizzo_id'], (int)$r['classe_id']]);
            if (!isset($posti[$chiave])) {
                $posti[$chiave] = [
                    'id'           => (int)$r['id'],
                    'scuola_id'    => (int)$r['institute_id'],
                    'scuola'       => (string)$r['scuola'],
                    'indirizzo'    => $r['indirizzo'] !== null ? (string)$r['indirizzo'] : null,
                    'classe'       => $r['classe'] !== null ? (string)$r['classe'] : null,
                    'materia'      => $r['materia'] !== null ? (string)$r['materia'] : null,
                    'indirizzo_id' => $r['indirizzo_id'] !== null ? (int)$r['indirizzo_id'] : null,
                    'classe_id'    => $r['classe_id'] !== null ? (int)$r['classe_id'] : null,
                    'materia_id'   => $r['subject_id'] !== null ? (int)$r['subject_id'] : null,
                    'principale'   => (int)$r['is_primary'] === 1,
                    'stato'        => (string)$r['visibility'],
                    'archivio'     => (int)$r['archive_visible'] === 1,
                    'varianti'     => 0,
                    '_righe'       => [],
                ];
            }
            $p = &$posti[$chiave];
            if ($p['stato'] !== (string)$r['visibility']) {
                $p['stato'] = 'misto';
            }
            $p['archivio'] = $p['archivio'] && (int)$r['archive_visible'] === 1;
            $p['_righe'][(int)$r['verifica_document_id']] = true;
            $p['varianti'] = count($p['_righe']);
            unset($p);
        }
        $st = $this->db()->prepare('SELECT title FROM verifica_documents_data WHERE id = ?');
        $st->execute([$verifica]);
        $titolo = self::titoloBase((string)$st->fetchColumn());
        return [
            'verifica' => ['id' => $verifica, 'titolo' => $titolo, 'varianti' => count($righe)],
            'posti'    => array_values(array_map(static function (array $p): array {
                unset($p['_righe']);
                return $p;
            }, $posti)),
            'limite'   => DoveVale::LIMITE,
        ];
    }

    /**
     * Pubblica la verifica anche in un posto: una pubblicazione per ogni
     * variante che non ce l'ha già. Torna quante ne ha aggiunte.
     *
     * @throws InvalidArgumentException non_trovato · stato_non_valido · non_consentito · scuola_non_tua ·
     *   voce_non_valida · voce_non_spuntata · classe_di_un_altro_corso · gia_pubblicato · troppe_pubblicazioni
     */
    public function aggiungi(
        int $verifica,
        int $docente,
        int $scuola,
        int $indirizzo,
        int $classe,
        int $materia,
        string $stato = 'draft',
        bool $archivio = false,
    ): int {
        $righe = $this->righe($verifica, $docente);
        if (!\in_array($stato, DoveVale::STATI, true)) {
            throw new InvalidArgumentException('stato_non_valido');
        }
        // ADR-028 — come per i contenuti: pubblicare in più posti è la
        // visibilità «più classi», che un profilo limitato non ha.
        if (!(new \App\Services\TeacherCapabilityPolicy($this->pdo))->visibilityAllowed($docente, 'classes')) {
            throw new InvalidArgumentException('non_consentito');
        }
        $this->doveVale()->verificaLuogo($docente, $scuola, $indirizzo, $classe, $materia);

        $db = $this->db();
        $in = implode(',', array_fill(0, count($righe), '?'));
        $st = $db->prepare(
            "SELECT COUNT(DISTINCT institute_id, COALESCE(indirizzo_id, 0), COALESCE(classe_id, 0))
               FROM content_publications WHERE verifica_document_id IN ({$in})"
        );
        $st->execute($righe);
        if ((int)$st->fetchColumn() >= DoveVale::LIMITE) {
            throw new InvalidArgumentException('troppe_pubblicazioni');
        }
        $st = $db->prepare(
            "SELECT DISTINCT verifica_document_id FROM content_publications
              WHERE verifica_document_id IN ({$in}) AND institute_id = ? AND indirizzo_id <=> ? AND classe_id <=> ?"
        );
        $st->execute([...$righe, $scuola, $indirizzo, $classe]);
        $gia = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $mancanti = array_values(array_diff($righe, $gia));
        if ($mancanti === []) {
            throw new InvalidArgumentException('gia_pubblicato');
        }

        $ins = $db->prepare(
            'INSERT INTO content_publications
                (verifica_document_id, institute_id, indirizzo_id, classe_id, subject_id,
                 is_primary, origine, visibility, archive_visible)
             VALUES (?, ?, ?, ?, ?, 0, "docente", ?, ?)'
        );
        $nuove = [];
        foreach ($mancanti as $riga) {
            $ins->execute([$riga, $scuola, $indirizzo, $classe, $materia, $stato, $archivio ? 1 : 0]);
            $nuove[] = (int)$db->lastInsertId();
        }

        ActivityLogger::event('pubblicazione_aggiunta', 'verifica_documents', (string)$verifica, [
            'pubblicazioni' => $nuove,
            'varianti'      => $mancanti,
            'istituto'      => $scuola,
            'indirizzo'     => $indirizzo,
            'classe'        => $classe,
            'materia'       => $materia,
            'stato'         => $stato,
        ]);
        return count($nuove);
    }

    /**
     * Cambia lo stato di un posto per tutte le varianti, principale compresa.
     * `$pubblicazione` è l'id che l'elenco dà al posto. Torna le righe toccate.
     *
     * @throws InvalidArgumentException non_trovato · stato_non_valido
     */
    public function impostaStato(int $verifica, int $pubblicazione, int $docente, string $stato, ?bool $archivio = null): int
    {
        if (!\in_array($stato, DoveVale::STATI, true)) {
            throw new InvalidArgumentException('stato_non_valido');
        }
        [$p, $righe] = $this->posto($verifica, $pubblicazione, $docente);
        $in = implode(',', array_fill(0, count($righe), '?'));
        $colonne = 'visibility = ?';
        $args = [$stato];
        if ($archivio !== null) {
            $colonne .= ', archive_visible = ?';
            $args[] = $archivio ? 1 : 0;
        }
        $st = $this->db()->prepare(
            "UPDATE content_publications SET {$colonne}
              WHERE verifica_document_id IN ({$in}) AND is_primary = ?
                AND institute_id = ? AND indirizzo_id <=> ? AND classe_id <=> ?"
        );
        $st->execute([...$args, ...$righe, (int)$p['is_primary'], (int)$p['institute_id'], $p['indirizzo_id'], $p['classe_id']]);
        $toccate = $st->rowCount();
        ActivityLogger::event('pubblicazione_stato', 'verifica_documents', (string)$verifica, [
            'pubblicazione' => $pubblicazione,
            'principale'    => (int)$p['is_primary'] === 1,
            'istituto'      => (int)$p['institute_id'],
            'da'            => (string)$p['visibility'],
            'a'             => $stato,
            'varianti'      => $righe,
        ] + ($archivio !== null ? ['archivio' => $archivio] : []));
        return $toccate;
    }

    /**
     * Toglie un posto da tutte le varianti. La principale non si toglie: si
     * sposta con le etichette della verifica.
     *
     * @throws InvalidArgumentException non_trovato · principale_non_si_toglie
     */
    public function togli(int $verifica, int $pubblicazione, int $docente): int
    {
        [$p, $righe] = $this->posto($verifica, $pubblicazione, $docente);
        if ((string)$p['origine'] !== 'docente') {
            throw new InvalidArgumentException('principale_non_si_toglie');
        }
        $in = implode(',', array_fill(0, count($righe), '?'));
        $st = $this->db()->prepare(
            "DELETE FROM content_publications
              WHERE verifica_document_id IN ({$in}) AND origine = 'docente'
                AND institute_id = ? AND indirizzo_id <=> ? AND classe_id <=> ?"
        );
        $st->execute([...$righe, (int)$p['institute_id'], $p['indirizzo_id'], $p['classe_id']]);
        $tolte = $st->rowCount();
        ActivityLogger::event('pubblicazione_tolta', 'verifica_documents', (string)$verifica, [
            'pubblicazione' => $pubblicazione,
            'istituto'      => (int)$p['institute_id'],
            'varianti'      => $righe,
        ]);
        return $tolte;
    }

    /**
     * La pubblicazione indicata, se è di una variante della verifica del
     * docente, e le righe della verifica.
     *
     * @return array{0:array<string,mixed>,1:list<int>}
     * @throws InvalidArgumentException non_trovato
     */
    private function posto(int $verifica, int $pubblicazione, int $docente): array
    {
        $righe = $this->righe($verifica, $docente);
        $st = $this->db()->prepare(
            'SELECT id, verifica_document_id, institute_id, indirizzo_id, classe_id, is_primary, origine, visibility
               FROM content_publications WHERE id = ? LIMIT 1'
        );
        $st->execute([$pubblicazione]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p || $p['verifica_document_id'] === null || !\in_array((int)$p['verifica_document_id'], $righe, true)) {
            throw new InvalidArgumentException('non_trovato');
        }
        $p['indirizzo_id'] = $p['indirizzo_id'] !== null ? (int)$p['indirizzo_id'] : null;
        $p['classe_id'] = $p['classe_id'] !== null ? (int)$p['classe_id'] : null;
        return [$p, $righe];
    }
}
