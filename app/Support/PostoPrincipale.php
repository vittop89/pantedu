<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Il posto principale di un documento: dove sta (ADR-037, fase 4c).
 *
 * Dalla fase 1 la principale la scrivevano i trigger delle migrazioni 117 e
 * 119, ricavandola dalle colonne indirizzo_id, classe_id e subject_id
 * (materia_id per le verifiche) della riga. Quelle tre colonne cadono in tre
 * rilasci, e questa classe è il punto unico in cui l'applicazione scrive la
 * principale al loro posto, con le regole delle procedure
 * pub_ricalcola_contenuto (118) e pub_ricalcola_verifica (119):
 *
 *   - la scuola del posto viene dalla materia, poi dalla classe, poi
 *     dall'indirizzo; senza nessuna etichetta il documento non sta in nessuna
 *     scuola e la principale non c'è;
 *   - per un contenuto lo stato della principale lo decide la riga, che resta
 *     l'interruttore del documento (opzione 2 della fase 4c): bozza se lo scope
 *     è «per più classi», altrimenti la visibilità della riga; l'archivio dalla
 *     riga;
 *   - per una verifica lo stato è del docente («Dove vale»): spostare il posto
 *     lo conserva, e una principale nuova nasce in bozza. La materia di una
 *     verifica resta anche nella riga (chiave del suo indice unico): chi la
 *     passa qui passa la stessa.
 *
 * I TRE RILASCI
 *   1. (4c-1, migrazione 121) l'applicazione scrive colonne e principale; i
 *      trigger scrivevano per primi lo stesso risultato. Le viste leggono le
 *      sigle dalla principale.
 *   2. (4c-2, migrazione 122) l'applicazione non scrive più le colonne e i
 *      trigger non ci sono più: la principale la scrive solo questa classe.
 *   3. (4c-3) la migrazione toglie le colonne.
 *
 * Chi scrive etichette o stato di una riga chiama questa classe nella stessa
 * transazione. Chi lo dimentica lo scopre la diagnostica quotidiana
 * (pub_verifica_allineamento: lo stato di ogni principale e la scuola di ogni
 * pubblicazione) e le prove `PostoPrincipaleTest` e `SenzaEtichetteNellaRigaTest`.
 */
final class PostoPrincipale
{
    public const CONTENUTO = 'contenuto';
    public const VERIFICA  = 'verifica';

    /**
     * La scuola di un posto: dalla materia, poi dalla classe, poi
     * dall'indirizzo. Null senza etichette.
     */
    public static function scuola(PDO $pdo, ?int $indirizzo, ?int $classe, ?int $materia): ?int
    {
        foreach ([$materia, $classe, $indirizzo] as $voce) {
            if ($voce === null || $voce <= 0) {
                continue;
            }
            $st = $pdo->prepare('SELECT institute_id FROM curriculum_entries WHERE id = ? LIMIT 1');
            $st->execute([$voce]);
            $scuola = $st->fetchColumn();
            if ($scuola !== false && $scuola !== null) {
                return (int)$scuola;
            }
        }
        return null;
    }

    /**
     * Il contenuto `$id` sta qui. Etichette null = nessuna: senza scuola la
     * principale si toglie.
     */
    public static function contenuto(PDO $pdo, int $id, ?int $indirizzo, ?int $classe, ?int $materia): void
    {
        [$indirizzo, $classe, $materia] = self::normalizza($indirizzo, $classe, $materia);
        $st = $pdo->prepare('SELECT visibility, publish_scope, archive_visible FROM teacher_content_data WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $riga = $st->fetch(PDO::FETCH_ASSOC);
        if (!$riga) {
            return;
        }
        $scuola = self::scuola($pdo, $indirizzo, $classe, $materia);
        if ($scuola === null) {
            $pdo->prepare('DELETE FROM content_publications WHERE primary_of_tc = ?')->execute([$id]);
            return;
        }
        $stato = $riga['publish_scope'] === 'classes' ? 'draft' : (string)$riga['visibility'];
        $archivio = (int)$riga['archive_visible'];

        $st = $pdo->prepare('SELECT id FROM content_publications WHERE primary_of_tc = ? LIMIT 1');
        $st->execute([$id]);
        $esistente = $st->fetchColumn();
        if ($esistente !== false) {
            $pdo->prepare(
                'UPDATE content_publications
                    SET institute_id = ?, indirizzo_id = ?, classe_id = ?, subject_id = ?, visibility = ?, archive_visible = ?
                  WHERE id = ?'
            )->execute([$scuola, $indirizzo, $classe, $materia, $stato, $archivio, (int)$esistente]);
            return;
        }
        $pdo->prepare(
            "INSERT INTO content_publications
                (teacher_content_id, institute_id, indirizzo_id, classe_id, subject_id,
                 is_primary, origine, primary_of_tc, visibility, archive_visible)
             VALUES (?, ?, ?, ?, ?, 1, 'riga', ?, ?, ?)"
        )->execute([$id, $scuola, $indirizzo, $classe, $materia, $id, $stato, $archivio]);
    }

    /**
     * La riga del contenuto ha cambiato visibilità, scope o archivio, non il
     * posto: la principale ne prende lo stato.
     */
    public static function statoDelContenuto(PDO $pdo, int $id): void
    {
        $pdo->prepare(
            "UPDATE content_publications p
               JOIN teacher_content_data d ON d.id = p.primary_of_tc
                SET p.visibility = IF(d.publish_scope = 'classes', 'draft', d.visibility),
                    p.archive_visible = d.archive_visible
              WHERE p.primary_of_tc = ?"
        )->execute([$id]);
    }

    /**
     * La verifica `$id` (una riga: una variante, una versione) sta qui. Lo
     * stato di una principale che c'era resta; una nuova nasce in bozza.
     */
    public static function verifica(PDO $pdo, int $id, ?int $indirizzo, ?int $classe, ?int $materia): void
    {
        [$indirizzo, $classe, $materia] = self::normalizza($indirizzo, $classe, $materia);
        $st = $pdo->prepare('SELECT 1 FROM verifica_documents_data WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        if (!$st->fetchColumn()) {
            return;
        }
        $scuola = self::scuola($pdo, $indirizzo, $classe, $materia);
        if ($scuola === null) {
            $pdo->prepare('DELETE FROM content_publications WHERE primary_of_vd = ?')->execute([$id]);
            return;
        }
        $st = $pdo->prepare('SELECT id FROM content_publications WHERE primary_of_vd = ? LIMIT 1');
        $st->execute([$id]);
        $esistente = $st->fetchColumn();
        if ($esistente !== false) {
            $pdo->prepare(
                'UPDATE content_publications SET institute_id = ?, indirizzo_id = ?, classe_id = ?, subject_id = ? WHERE id = ?'
            )->execute([$scuola, $indirizzo, $classe, $materia, (int)$esistente]);
            return;
        }
        $pdo->prepare(
            "INSERT INTO content_publications
                (verifica_document_id, institute_id, indirizzo_id, classe_id, subject_id,
                 is_primary, origine, primary_of_vd, visibility, archive_visible)
             VALUES (?, ?, ?, ?, ?, 1, 'riga', ?, 'draft', 0)"
        )->execute([$id, $scuola, $indirizzo, $classe, $materia, $id]);
    }

    /**
     * Dove sta adesso un documento, per chi ne cambia una sola etichetta.
     *
     * @param self::CONTENUTO|self::VERIFICA $fonte
     * @return array{indirizzo:?int,classe:?int,materia:?int}
     */
    public static function dove(PDO $pdo, string $fonte, int $id): array
    {
        $colonna = $fonte === self::VERIFICA ? 'primary_of_vd' : 'primary_of_tc';
        $st = $pdo->prepare("SELECT indirizzo_id, classe_id, subject_id FROM content_publications WHERE $colonna = ? LIMIT 1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return [
            'indirizzo' => $r && $r['indirizzo_id'] !== null ? (int)$r['indirizzo_id'] : null,
            'classe'    => $r && $r['classe_id'] !== null ? (int)$r['classe_id'] : null,
            'materia'   => $r && $r['subject_id'] !== null ? (int)$r['subject_id'] : null,
        ];
    }

    /** @return array{0:?int,1:?int,2:?int} gli id non positivi valgono «nessuna etichetta» */
    private static function normalizza(?int $indirizzo, ?int $classe, ?int $materia): array
    {
        $n = static fn(?int $v): ?int => $v !== null && $v > 0 ? $v : null;
        return [$n($indirizzo), $n($classe), $n($materia)];
    }
}
