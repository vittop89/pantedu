<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Services\Risdoc\CompilationStoragePolicy;
use PDO;
use Throwable;

/**
 * Le compilazioni che il server, oggi, si rifiuterebbe di salvare — e che
 * sono salvate.
 *
 * PERCHE' ESISTE (22 settembre 2026)
 *
 * Il Titolare della piattaforma puo' decidere, per i docenti di un Istituto, che le compilazioni
 * dei modelli istituzionali non restino sul server: `compilation_storage = 0`,
 * dal pannello `/admin/institutes`. Da quel momento
 * `CompilationController::save` risponde 403 e la bozza resta nel browser del
 * docente.
 *
 * L'interruttore pero' vale **da adesso in avanti**. `InstituteRepository`
 * esegue una sola istruzione — `UPDATE institutes SET compilation_storage = ?`
 * — e niente tocca le righe gia' scritte. Un Istituto puo' quindi trovarsi
 * marcato «non si conserva» con le conservazioni di prima ancora nel database:
 * la dichiarazione dice una cosa, i dati ne dicono un'altra, e nessuno se ne
 * accorge perche' tutto funziona.
 *
 * E' la forma di guasto che questa diagnostica insegue: non qualcosa che si
 * rompe, ma qualcosa che **smette di essere vero senza dirlo**. Verso un DPO
 * che ha chiesto quella configurazione, e' anche la differenza fra una misura
 * applicata e una misura dichiarata.
 *
 * COSA NON FA
 *
 * Non cancella niente, e non e' previsto che lo faccia da qui. Cancellare in
 * blocco le bozze di altri docenti sarebbe l'unico punto della piattaforma in
 * cui un amministratore distrugge contenuti che non puo' nemmeno leggere —
 * sono cifrati con la chiave di ciascuno — e contraddirebbe l'impianto, dove
 * ogni accesso amministrativo ai contenuti di un docente gli viene notificato.
 * Qui si rende **visibile** lo scarto; a chiuderlo e' il docente, che esporta
 * il PDF e cancella la sua compilazione, o uno strumento lanciato con
 * intenzione esplicita e motivazione a registro.
 *
 * LA REGOLA E' QUELLA DELLA POLITICA, NON UNA SUA COPIA
 *
 * `CompilationStoragePolicy::storageDisabledForTeacher` nega il salvataggio a
 * un docente se **uno** dei suoi Istituti attivi ha l'interruttore spento — un
 * docente puo' appartenere a piu' Istituti, ed e' la lettura prudente. Qui si
 * misura con lo stesso criterio, per docente e non per Istituto: misurare
 * altrimenti darebbe un elenco che non corrisponde a cio' che il server
 * rifiuta, cioe' un allarme che non si sa come chiudere. Se quella politica
 * cambia, questa guardia cambia con lei.
 */
final class ConservazioneDichiarata
{
    /**
     * Logica pura: fra i docenti con compilazioni istituzionali salvate, quelli
     * a cui oggi il salvataggio e' negato.
     *
     * I tipi dichiarati ammettono le stringhe perche' questa funzione converte
     * apposta: PDO, a seconda del driver e di `PDO::ATTR_STRINGIFY_FETCHES`,
     * restituisce gli id e i COUNT come stringhe. Dichiarare solo `int` e poi
     * convertire sarebbe una firma che mente sul proprio ingresso.
     *
     * @param array<array-key,int|numeric-string> $perDocente    id del docente => quante ne ha salvate
     * @param list<int|numeric-string>            $docentiNegati id dei docenti a cui oggi si nega
     * @return array<int,int> id del docente => quante, solo per chi e' negato
     */
    public static function incoerenti(array $perDocente, array $docentiNegati): array
    {
        $negati = [];
        foreach ($docentiNegati as $id) {
            $negati[(int)$id] = true;
        }

        $fuori = [];
        foreach ($perDocente as $docente => $quante) {
            $docente = (int)$docente;
            $quante  = (int)$quante;
            if ($quante > 0 && isset($negati[$docente])) {
                $fuori[$docente] = $quante;
            }
        }
        ksort($fuori);

        return $fuori;
    }

    /**
     * L'interruttore esiste in questo database?
     *
     * `compilation_storage` arriva con la migration 103. Dove non c'e', nessun
     * Istituto puo' dichiarare che non si conserva, e l'invariante non ha
     * oggetto: e' `non_applicabile`, non «regge». La differenza conta, perche'
     * un «regge» su una colonna assente e' esattamente il verde che non misura.
     */
    public static function interruttoreEsiste(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT compilation_storage FROM institutes LIMIT 1')->fetchColumn();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Le due domande al database, poi la logica pura.
     *
     * @return array{
     *     istitutiSpenti: int,
     *     docentiNegati: int,
     *     istituzionaliSalvate: int,
     *     incoerenti: array<int,int>
     * }
     */
    public static function nelDatabase(PDO $pdo): array
    {
        $istitutiSpenti = (int)$pdo->query(
            'SELECT COUNT(*) FROM institutes WHERE active = 1 AND compilation_storage = 0'
        )->fetchColumn();

        /** @var list<int> $docentiNegati */
        $docentiNegati = array_map('intval', $pdo->query(
            'SELECT DISTINCT ti.user_id
               FROM teacher_institutes ti
               JOIN institutes i ON i.id = ti.institute_id
              WHERE i.active = 1 AND i.compilation_storage = 0'
        )->fetchAll(PDO::FETCH_COLUMN));

        // La tabella vera, non la vista: `risdoc_compilations` e' una vista, e
        // contare da li' e' contare quello che la vista lascia passare.
        $st = $pdo->prepare(
            'SELECT c.teacher_id, COUNT(*) AS quante
               FROM risdoc_compilations_data c
               JOIN risdoc_templates t ON t.id = c.template_id
              WHERE t.category = ?
              GROUP BY c.teacher_id'
        );
        $st->execute([CompilationStoragePolicy::INSTITUTIONAL_CATEGORY]);

        $perDocente = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $perDocente[(int)$r['teacher_id']] = (int)$r['quante'];
        }

        return [
            'istitutiSpenti'       => $istitutiSpenti,
            'docentiNegati'        => \count($docentiNegati),
            'istituzionaliSalvate' => array_sum($perDocente),
            'incoerenti'           => self::incoerenti($perDocente, $docentiNegati),
        ];
    }
}
