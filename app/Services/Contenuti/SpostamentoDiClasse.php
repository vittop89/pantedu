<?php

declare(strict_types=1);

namespace App\Services\Contenuti;

use App\Core\Database;
use App\Repositories\VerificaDocumentRepository;
use App\Support\PostoPrincipale;
use InvalidArgumentException;
use PDO;

/**
 * Spostare i materiali di un docente da una classe a un'altra, dal sito.
 *
 * PERCHE' SERVE
 *   I contenuti nascono con la classe scelta nella barra laterale, e cambiarla
 *   dopo — a un contenuto per volta — non lo fa nessuno. Il caso vero: i
 *   materiali archiviati sull'anno («2») quando la scuola ha le sezioni
 *   («2A»): vanno spostati a blocchi, dal profilo, senza passare da uno
 *   strumento a riga di comando sul server.
 *
 * COSA SPOSTA
 *   I contenuti (`teacher_content_data`) e le verifiche
 *   (`verifica_documents_data`) del docente che stanno nella classe di
 *   partenza e che lui ha scelto. Cambia SOLO la classe; e il corso
 *   (`indirizzo_id`) quando la classe di arrivo appartiene a un corso, perche'
 *   una sezione «2A» dello scientifico e' dello scientifico. La materia non
 *   si tocca, il contenuto neanche. Una verifica si sposta intera: tutte le
 *   varianti e le versioni che stanno nella classe di partenza, come per
 *   «Dove vale» e la condivisione (VerificaDocumentRepository::righeDellaVerifica).
 *
 * I POSTI DEL DOCUMENTO (ADR-037)
 *   Il posto principale e' la pubblicazione principale, e lo scrive
 *   App\Support\PostoPrincipale (fase 4c): indirizzo, classe e materia non
 *   stanno piu' nella riga, e le viste li ricavano dalla principale. I posti
 *   in piu' scelti
 *   da «Dove vale» che stanno nella classe di partenza, nella stessa scuola,
 *   seguono lo spostamento: e' la regola che avevano i bersagli della
 *   pubblicazione per piu' classi, diventati pubblicazioni del docente con la
 *   migrazione 118.
 *
 *   Due posti che finiscono nella stessa classe diventano uno solo quando
 *   togliere il secondo non cambia chi vede che cosa: stesso archivio, e uno
 *   stato fra bozza e pubblicato che il posto che resta copre. Il caso di tutti
 *   i giorni e' l'anno con il suo bersaglio: contenuto «per piu' classi» in «2»
 *   (principale in bozza) pubblicato per «2A»; spostato in 2A, la principale
 *   diventa pubblicata (lo scope torna «una classe») e il bersaglio non serve
 *   piu'. Quando i due posti dicono cose diverse (un archivio diverso, uno
 *   archiviato) restano tutti e due, e il risultato li conta: li sistema il
 *   docente da «Dove vale».
 *
 * LE REGOLE DEL CATALOGO (ADR-035)
 *   La classe di arrivo dev'essere una voce della scuola che il docente ha
 *   spuntato, altrimenti i materiali finirebbero sotto un'etichetta che nei
 *   suoi menu' non esiste; e lo stesso vale per il corso della classe di
 *   arrivo. Se manca la spunta, lo strumento si ferma e dice quale.
 *
 * LE SEZIONI CHE IL DOCENTE NON PUO' PIU' USARE (ADR-041)
 *   Tolto l'incarico, la spunta sulla sezione si sospende e i materiali restano
 *   li'. Come classe di PARTENZA la sezione c'e' lo stesso, finche' ci sono
 *   suoi materiali (classiDiPartenza): cosi' li porta sull'anno, o in una
 *   sezione di cui ha l'incarico, senza che l'amministratore debba ridargli
 *   l'incarico. Come classe di ARRIVO no. Da una sezione cosi' l'elenco e lo
 *   spostamento prendono anche i contenuti che li' hanno solo un posto in piu'
 *   di «Dove vale»: in produzione erano tutti cosi'. Per questi si sposta il
 *   posto, e la principale resta dov'e'.
 */
final class SpostamentoDiClasse
{
    private const STATI_UNIBILI = ['draft', 'published'];

    public function __construct(private ?PDO $pdo = null, private ?MaterialiSuSezioniNonAmmesse $rimasti = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /**
     * Le classi da cui il docente puo' spostare: quelle spuntate, e le sezioni
     * che non puo' piu' usare ma dove ha ancora materiali (`sospesa` = true).
     *
     * @return list<array{id:int,code:string,label:string,istituto:string,institute_id:int,indirizzo:?string,sospesa:bool}>
     */
    public function classiDiPartenza(int $uid): array
    {
        $out = array_map(static fn(array $c): array => $c + ['sospesa' => false], $this->classiDelDocente($uid));
        $gia = array_column($out, 'id');
        $this->rimasti ??= new MaterialiSuSezioniNonAmmesse($this->pdo);
        foreach ($this->rimasti->righe($uid) as $r) {
            if (\in_array($r['id'], $gia, true)) {
                continue;
            }
            $out[] = [
                'id' => $r['id'], 'code' => $r['code'], 'label' => $r['label'], 'istituto' => $r['istituto'],
                'institute_id' => $r['institute_id'], 'indirizzo' => $r['indirizzo'], 'sospesa' => true,
            ];
        }
        usort($out, static fn(array $a, array $b): int => [$a['istituto'], $a['code']] <=> [$b['istituto'], $b['code']]);
        return $out;
    }

    /**
     * Le classi che il docente ha spuntato, in tutti i suoi istituti, con il
     * corso della sezione (null = vale per tutti).
     *
     * @return list<array{id:int,code:string,label:string,istituto:string,institute_id:int,indirizzo:?string}>
     */
    public function classiDelDocente(int $uid): array
    {
        $st = $this->db()->prepare(
            'SELECT c.id, c.code, COALESCE(ct.label_override, c.label) AS label,
                    COALESCE(i.name, i.code) AS istituto, c.institute_id, c.indirizzo
               FROM curriculum_teacher ct
               JOIN curriculum_entries c ON c.id = ct.curriculum_id
               JOIN institutes i ON i.id = c.institute_id
              WHERE c.kind = "classi" AND ct.user_id = ? AND ct.active = 1 AND c.active = 1
              ORDER BY istituto, c.code'
        );
        $st->execute([$uid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'           => (int)$r['id'],
                'code'         => (string)$r['code'],
                'label'        => (string)$r['label'],
                'istituto'     => (string)$r['istituto'],
                'institute_id' => (int)$r['institute_id'],
                'indirizzo'    => $r['indirizzo'] !== null ? (string)$r['indirizzo'] : null,
            ];
        }
        return $out;
    }

    /**
     * I materiali del docente nella classe indicata. Le verifiche una per
     * verifica, con quante varianti stanno in quella classe.
     *
     * Ci sono anche i documenti che li' hanno solo un posto in piu' di «Dove
     * vale»: `principale_in` dice dove sta la loro principale; per quelli con
     * la principale li' e' null. Fino al 15/9/2026 solo da una sezione che il
     * docente non poteva piu' usare: da una sua classe la pagina diceva «Nessun
     * materiale» anche con decine di posti in piu' (le principali dell'utente
     * stanno sugli anni, i posti in piu' sulle sezioni).
     *
     * @return array{
     *   contenuti: list<array{id:int,tipo:string,title:string,indirizzo:?string,materia:?string,sezione:?string,principale_in:?string}>,
     *   verifiche: list<array{id:int,title:string,indirizzo:?string,materia:?string,varianti:int,ids:list<int>,principale_in:?string}>
     * }
     */
    public function elenco(int $uid, int $classeId): array
    {
        $db = $this->db();
        $posto = " OR EXISTS (SELECT 1 FROM content_publications p
                               WHERE p.teacher_content_id = d.id AND p.classe_id = ? AND p.origine = 'docente')";
        $st = $db->prepare(
            "SELECT d.id, d.content_subtype AS tipo, d.title, d.classe_id,
                    ci.code AS indirizzo, cm.code AS materia, s.label AS sezione, cc.code AS classe
               FROM teacher_content d
               LEFT JOIN sidebar_sections s    ON s.id  = d.section_id
               LEFT JOIN curriculum_entries ci ON ci.id = d.indirizzo_id
               LEFT JOIN curriculum_entries cm ON cm.id = d.subject_id
               LEFT JOIN curriculum_entries cc ON cc.id = d.classe_id
              WHERE d.teacher_id = ? AND (d.classe_id = ?{$posto})
              ORDER BY s.position, s.label, d.content_subtype, cm.code, d.title, d.id"
        );
        $st->execute([$uid, $classeId, $classeId]);
        $contenuti = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $contenuti[] = [
                'id'        => (int)$r['id'],
                'tipo'      => (string)$r['tipo'],
                'title'     => (string)$r['title'],
                'indirizzo' => $r['indirizzo'] !== null ? (string)$r['indirizzo'] : null,
                'materia'   => $r['materia'] !== null ? (string)$r['materia'] : null,
                'sezione'   => $r['sezione'] !== null ? (string)$r['sezione'] : null,
                'principale_in' => (int)$r['classe_id'] === $classeId ? null : (string)($r['classe'] ?? '—'),
            ];
        }

        $verifiche = [];
        foreach ($this->verificheNellaClasse($db, $uid, $classeId) as $gruppo) {
            $prima = $gruppo[0];
            $ids = array_column($gruppo, 'id');
            sort($ids);
            $verifiche[] = [
                'id'        => $ids[0],
                'title'     => VerificaDocumentRepository::titoloBase($prima['title']),
                'indirizzo' => $prima['indirizzo'],
                'materia'   => $prima['materia'],
                'varianti'  => count($gruppo),
                'ids'       => $ids,
                'principale_in' => $prima['principale'] ? null : $prima['classe'],
            ];
        }
        return ['contenuti' => $contenuti, 'verifiche' => $verifiche];
    }

    /**
     * Sposta i materiali scelti dalla classe di partenza a quella di arrivo.
     * Tocca solo righe del docente che stanno davvero nella classe di
     * partenza: un id di un collega, inventato, o di un'altra classe non
     * cambia niente e non conta. Le due classi devono essere della stessa
     * scuola: materia e corso di un contenuto sono di un istituto, e una
     * classe di un'altra scuola li lascerebbe con etichette di due scuole.
     *
     * @param list<int|string> $contenuti  id in teacher_content_data
     * @param list<int|string> $verifiche  id in verifica_documents_data (basta una variante)
     * @return array{contenuti:int,verifiche:int,varianti:int,doppi:int,
     *               posti:array{spostati:list<int>,uniti:list<int>,stati:list<int>,scope_classe:list<int>},
     *               classe:string,indirizzo:?string,da_classe:string,da_indirizzo:?string,
     *               ids:array{contenuti:list<int>,verifiche:list<int>}}
     * @throws InvalidArgumentException niente_da_spostare · classe_non_spuntata · stessa_classe · istituti_diversi · indirizzo_non_spuntato:<code>
     */
    public function sposta(int $uid, array $contenuti, array $verifiche, int $classeDaId, int $classeAId): array
    {
        $contenuti = self::ids($contenuti);
        $verifiche = self::ids($verifiche);
        if ($contenuti === [] && $verifiche === []) {
            throw new InvalidArgumentException('niente_da_spostare');
        }
        $partenza = null;
        $arrivo   = null;
        // Si parte anche da una sezione che il docente non puo' piu' usare, se
        // ci ha materiali; si arriva solo in una classe spuntata.
        foreach ($this->classiDiPartenza($uid) as $c) {
            if ($c['id'] === $classeDaId) {
                $partenza = $c;
            }
            if ($c['id'] === $classeAId && !$c['sospesa']) {
                $arrivo = $c;
            }
        }
        if ($partenza === null || $arrivo === null) {
            throw new InvalidArgumentException('classe_non_spuntata');
        }
        if ($partenza['id'] === $arrivo['id']) {
            throw new InvalidArgumentException('stessa_classe');
        }
        if ($partenza['institute_id'] !== $arrivo['institute_id']) {
            throw new InvalidArgumentException('istituti_diversi');
        }
        $classeId = $arrivo['id'];
        $scuola   = $arrivo['institute_id'];

        $db = $this->db();
        // Il corso della sezione di arrivo, come voce spuntata dal docente
        // nello stesso istituto: senza, i materiali finirebbero sotto un corso
        // che nei suoi menu' non c'e'.
        $indirizzoId = null;
        if ($arrivo['indirizzo'] !== null) {
            $st = $db->prepare(
                'SELECT c.id FROM curriculum_entries c
                   JOIN curriculum_teacher ct ON ct.curriculum_id = c.id AND ct.user_id = ? AND ct.active = 1
                  WHERE c.kind = "indirizzi" AND c.institute_id = ? AND c.code = ? AND c.active = 1
                  LIMIT 1'
            );
            $st->execute([$uid, $arrivo['institute_id'], $arrivo['indirizzo']]);
            $id = $st->fetchColumn();
            if ($id === false) {
                throw new InvalidArgumentException('indirizzo_non_spuntato:' . $arrivo['indirizzo']);
            }
            $indirizzoId = (int)$id;
        }

        $esito = ['contenuti' => 0, 'verifiche' => 0, 'varianti' => 0, 'doppi' => 0,
                  'posti' => ['spostati' => [], 'uniti' => [], 'stati' => [], 'scope_classe' => []]];
        // Quali elementi, per il registro: il 15/9/2026 uno spostamento sbagliato
        // si è potuto annullare solo ritrovandoli dall'ora di modifica.
        $spostati = ['contenuti' => [], 'verifiche' => []];
        $inTx = !$db->inTransaction();
        if ($inTx) {
            $db->beginTransaction();
        }
        // Contano anche i documenti che nella classe di partenza hanno solo un
        // posto in piu': per quelli si sposta il posto, e la principale resta
        // dov'e'. Fino al 15/9/2026 solo da una sezione senza incarico.
        try {
            if ($contenuti !== []) {
                $righe = $this->nellaClasse($db, 'teacher_content', $uid, $contenuti, $partenza['id']);
                $conPosto = $this->conUnPostoNellaClasse($db, 'teacher_content_id', 'teacher_content_data', $uid, $contenuti, $partenza['id']);
                $tutti = array_values(array_unique(array_merge($righe, $conPosto)));
                sort($tutti);
                if ($tutti !== []) {
                    if ($righe !== []) {
                        $this->aggiorna($db, PostoPrincipale::CONTENUTO, $uid, $righe, $classeId, $indirizzoId);
                    }
                    $doppi = $this->spostaPosti($db, 'teacher_content_id', $tutti, $scuola, $partenza['id'], $classeId, $indirizzoId, $esito['posti']);
                    $esito['contenuti'] = count($tutti);
                    $esito['doppi'] += count($doppi);
                    $spostati['contenuti'] = $tutti;
                }
            }
            if ($verifiche !== []) {
                // La verifica intera: le varianti scelte portano con se' le altre
                // righe della stessa verifica che stanno nella classe di partenza.
                $scelte = [];
                $principali = [];
                foreach ($this->verificheNellaClasse($db, $uid, $partenza['id']) as $chiave => $gruppo) {
                    $ids = array_column($gruppo, 'id');
                    if (array_intersect($ids, $verifiche) !== []) {
                        $scelte[$chiave] = $ids;
                        foreach ($gruppo as $g) {
                            if ($g['principale']) {
                                $principali[] = $g['id'];
                            }
                        }
                    }
                }
                $righe = $scelte !== [] ? array_merge(...array_values($scelte)) : [];
                if ($righe !== []) {
                    if ($principali !== []) {
                        $this->aggiorna($db, PostoPrincipale::VERIFICA, $uid, $principali, $classeId, $indirizzoId);
                    }
                    $doppi = $this->spostaPosti($db, 'verifica_document_id', $righe, $scuola, $partenza['id'], $classeId, $indirizzoId, $esito['posti']);
                    $esito['verifiche'] = count($scelte);
                    $esito['varianti']  = count($righe);
                    $spostati['verifiche'] = array_values(array_map('intval', $righe));
                    foreach ($scelte as $ids) {
                        if (array_intersect($ids, $doppi) !== []) {
                            $esito['doppi']++;
                        }
                    }
                }
            }
            if ($inTx) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($inTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return $esito + ['classe' => $arrivo['code'], 'indirizzo' => $arrivo['indirizzo'],
                         'da_classe' => $partenza['code'], 'da_indirizzo' => $partenza['indirizzo'], 'ids' => $spostati];
    }

    /**
     * Gli id scelti che sono righe del docente nella classe di partenza,
     * attraverso la vista (la classe della principale).
     *
     * @param 'teacher_content'|'verifica_documents' $vista
     * @param list<int> $ids
     * @return list<int>
     */
    private function nellaClasse(PDO $db, string $vista, int $uid, array $ids, int $classeDaId): array
    {
        $segnaposto = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT id FROM `$vista` WHERE teacher_id = ? AND classe_id = ? AND id IN ($segnaposto) ORDER BY id");
        $st->execute(array_merge([$uid, $classeDaId], $ids));
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Gli id scelti che sono documenti del docente con un posto in piu' di
     * «Dove vale» nella classe di partenza.
     *
     * @param 'teacher_content_id'|'verifica_document_id' $colonna
     * @param 'teacher_content_data'|'verifica_documents_data' $tabella
     * @param list<int> $ids
     * @return list<int>
     */
    private function conUnPostoNellaClasse(PDO $db, string $colonna, string $tabella, int $uid, array $ids, int $classeDaId): array
    {
        $segnaposto = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare(
            "SELECT DISTINCT t.id FROM `$tabella` t
               JOIN content_publications p ON p.$colonna = t.id AND p.classe_id = ? AND p.origine = 'docente'
              WHERE t.teacher_id = ? AND t.id IN ($segnaposto) ORDER BY t.id"
        );
        $st->execute(array_merge([$classeDaId, $uid], $ids));
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Cambia la classe (e il corso, se quella di arrivo ne ha uno) di righe
     * gia' filtrate da nellaClasse: la principale con PostoPrincipale; della
     * riga cambia solo updated_at.
     *
     * @param PostoPrincipale::CONTENUTO|PostoPrincipale::VERIFICA $fonte
     * @param list<int> $ids
     */
    private function aggiorna(PDO $db, string $fonte, int $uid, array $ids, int $classeId, ?int $indirizzoId): void
    {
        $tabella = $fonte === PostoPrincipale::VERIFICA ? 'verifica_documents_data' : 'teacher_content_data';
        $upd = $db->prepare("UPDATE `$tabella` SET updated_at = NOW() WHERE teacher_id = ? AND id = ?");
        foreach ($ids as $id) {
            $ora = PostoPrincipale::dove($db, $fonte, $id);
            // Senza un corso di arrivo il corso resta quello che era.
            $indirizzo = $indirizzoId ?? $ora['indirizzo'];
            $upd->execute([$uid, $id]);
            if ($fonte === PostoPrincipale::VERIFICA) {
                PostoPrincipale::verifica($db, $id, $indirizzo, $classeId, $ora['materia']);
            } else {
                PostoPrincipale::contenuto($db, $id, $indirizzo, $classeId, $ora['materia']);
            }
        }
    }

    /**
     * I posti in piu' dei documenti spostati che stanno nella classe di
     * partenza vanno in quella di arrivo; poi, posto per posto nella classe di
     * arrivo, i doppioni che si possono unire si uniscono.
     *
     * @param 'teacher_content_id'|'verifica_document_id' $colonna
     * @param list<int> $documenti
     * @param array{spostati:list<int>,uniti:list<int>,stati:list<int>,scope_classe:list<int>} $posti
     * @return list<int> i documenti che restano con due posti nella stessa classe
     */
    private function spostaPosti(PDO $db, string $colonna, array $documenti, int $scuola, int $classeDaId, int $classeId, ?int $indirizzoId, array &$posti): array
    {
        $segnaposto = implode(',', array_fill(0, count($documenti), '?'));
        $st = $db->prepare(
            "SELECT id FROM content_publications
              WHERE $colonna IN ($segnaposto) AND origine = 'docente' AND institute_id = ? AND classe_id = ?"
        );
        $st->execute(array_merge($documenti, [$scuola, $classeDaId]));
        $spostati = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($spostati !== []) {
            $in = implode(',', array_fill(0, count($spostati), '?'));
            $db->prepare(
                "UPDATE content_publications SET classe_id = ?, indirizzo_id = COALESCE(?, indirizzo_id) WHERE id IN ($in)"
            )->execute(array_merge([$classeId, $indirizzoId], $spostati));
            array_push($posti['spostati'], ...$spostati);
        }

        $st = $db->prepare(
            "SELECT id, $colonna AS documento, is_primary, indirizzo_id, visibility, archive_visible
               FROM content_publications
              WHERE $colonna IN ($segnaposto) AND institute_id = ? AND classe_id = ?
              ORDER BY $colonna, indirizzo_id, is_primary DESC, visibility = 'published' DESC, id"
        );
        $st->execute(array_merge($documenti, [$scuola, $classeId]));
        $perPosto = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $perPosto[$p['documento'] . '|' . ($p['indirizzo_id'] ?? '-')][] = $p;
        }

        $doppi = [];
        foreach ($perPosto as $pubblicazioni) {
            if (count($pubblicazioni) < 2) {
                continue;
            }
            $documento = (int)$pubblicazioni[0]['documento'];
            $resta = array_shift($pubblicazioni);
            foreach ($pubblicazioni as $altra) {
                if (!$this->unisci($db, $colonna, $documento, $resta, $altra, $posti)) {
                    $doppi[$documento] = $documento;
                }
            }
        }
        return array_values($doppi);
    }

    /**
     * Toglie `$altra`, che sta nello stesso posto di `$resta`, se chi vede che
     * cosa non cambia. `$resta` e' la principale quando c'e', altrimenti la piu'
     * visibile delle due.
     *
     * Per i contenuti lo stato della principale lo decide la riga (i trigger:
     * bozza se lo scope e' «per piu' classi», altrimenti lo stato della riga), e
     * la riga resta l'interruttore generale: un posto pubblicato conta solo se
     * la riga e' pubblicata. Per le verifiche lo stato della principale e' del
     * docente, e si porta a «pubblicato» se lo era il posto che si toglie.
     *
     * @param array<string,mixed> $resta
     * @param array<string,mixed> $altra
     * @param array{spostati:list<int>,uniti:list<int>,stati:list<int>,scope_classe:list<int>} $posti
     */
    private function unisci(PDO $db, string $colonna, int $documento, array $resta, array $altra, array &$posti): bool
    {
        if (
            (int)$resta['archive_visible'] !== (int)$altra['archive_visible']
            || !\in_array($resta['visibility'], self::STATI_UNIBILI, true)
            || !\in_array($altra['visibility'], self::STATI_UNIBILI, true)
        ) {
            return false;
        }
        $piuVisibile = $altra['visibility'] === 'published' && $resta['visibility'] !== 'published';
        if ($piuVisibile && (int)$resta['is_primary'] === 1) {
            if ($colonna === 'teacher_content_id') {
                // La principale e' in bozza: o per lo scope «per piu' classi»
                // (si torna a «una classe», e la principale prende lo stato
                // della riga), o perche' la riga e' in bozza (e allora il posto
                // pubblicato non mostrava niente, e non mostrera' niente dopo).
                // Una riga archiviata no: l'archivio legge gli stati dei posti, e
                // la principale diventerebbe «archiviata» dove l'altro posto era
                // pubblicato.
                $st = $db->prepare('SELECT publish_scope, visibility FROM teacher_content_data WHERE id = ?');
                $st->execute([$documento]);
                $riga = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                if (($riga['visibility'] ?? '') === 'archived') {
                    return false;
                }
                if (($riga['publish_scope'] ?? '') === 'classes') {
                    $db->prepare("UPDATE teacher_content_data SET publish_scope = 'class' WHERE id = ?")->execute([$documento]);
                    PostoPrincipale::statoDelContenuto($db, $documento);
                    $posti['scope_classe'][] = $documento;
                }
            } else {
                $db->prepare("UPDATE content_publications SET visibility = 'published' WHERE id = ?")->execute([(int)$resta['id']]);
                $posti['stati'][] = (int)$resta['id'];
            }
        } elseif ($piuVisibile) {
            $db->prepare("UPDATE content_publications SET visibility = 'published' WHERE id = ?")->execute([(int)$resta['id']]);
            $posti['stati'][] = (int)$resta['id'];
        }
        $db->prepare("DELETE FROM content_publications WHERE id = ? AND origine = 'docente'")->execute([(int)$altra['id']]);
        $posti['uniti'][] = (int)$altra['id'];
        return true;
    }

    /**
     * Le verifiche del docente in una classe, una per verifica: stessa materia,
     * stesso titolo base (VerificaDocumentRepository::righeDellaVerifica).
     *
     * Anche le verifiche che nella classe hanno solo un posto in piu':
     * `principale` dice se la principale sta li'.
     *
     * @return array<string,list<array{id:int,title:string,indirizzo:?string,materia:?string,principale:bool,classe:?string}>>
     */
    private function verificheNellaClasse(PDO $db, int $uid, int $classeId): array
    {
        $posto = " OR EXISTS (SELECT 1 FROM content_publications p
                               WHERE p.verifica_document_id = v.id AND p.classe_id = ? AND p.origine = 'docente')";
        $st = $db->prepare(
            "SELECT v.id, v.title, v.materia_id, v.classe_id, ci.code AS indirizzo, cm.code AS materia, cc.code AS classe
               FROM verifica_documents v
               LEFT JOIN curriculum_entries ci ON ci.id = v.indirizzo_id
               LEFT JOIN curriculum_entries cm ON cm.id = v.materia_id
               LEFT JOIN curriculum_entries cc ON cc.id = v.classe_id
              WHERE v.teacher_id = ? AND (v.classe_id = ?{$posto})
              ORDER BY cm.code, v.title, v.id"
        );
        $st->execute([$uid, $classeId, $classeId]);
        $gruppi = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $chiave = ($r['materia_id'] ?? '-') . '|' . mb_strtolower(VerificaDocumentRepository::titoloBase((string)$r['title']));
            $gruppi[$chiave][] = [
                'id'         => (int)$r['id'],
                'title'      => (string)$r['title'],
                'indirizzo'  => $r['indirizzo'] !== null ? (string)$r['indirizzo'] : null,
                'materia'    => $r['materia'] !== null ? (string)$r['materia'] : null,
                'principale' => (int)$r['classe_id'] === $classeId,
                'classe'     => $r['classe'] !== null ? (string)$r['classe'] : null,
            ];
        }
        return $gruppi;
    }

    /**
     * @param list<int|string> $valori
     * @return list<int>
     */
    private static function ids(array $valori): array
    {
        $out = [];
        foreach ($valori as $v) {
            $n = (int)$v;
            if ($n > 0 && !\in_array($n, $out, true)) {
                $out[] = $n;
            }
        }
        return $out;
    }
}
