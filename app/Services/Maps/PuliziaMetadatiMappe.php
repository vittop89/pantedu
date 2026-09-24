<?php

declare(strict_types=1);

namespace App\Services\Maps;

use App\Services\Audit\ContentActionLogger;
use PDO;
use stdClass;

/**
 * Toglie dalle mappe il `layout` e il `body_pt` d'esempio che il modale ✎
 * scriveva a ogni «Salva» fino al 19/9/2026 (analisi C-export-bodypt e
 * F-metadati-modifica-mappa): il seme di «Stile esercizi», con i titoli
 * «Esercizi per studenti» e «Verifiche» e due blocchi vuoti. Da lì la barra
 * mostrava il 📥 su una mappa, e lo ZIP TeX portava via due titoli.
 *
 * La regola, stretta apposta, nell'ordine in cui si applica:
 *   - una mappa con il corpo nelle colonne cifrate si elenca e non si tocca,
 *     e si guarda per prima: lì il corpo in chiaro non c'è, quindi non lo si
 *     può confrontare con il seme;
 *   - si tocca solo una mappa il cui `body_pt` è ESATTAMENTE il seme
 *     (stessi nodi, stesse chiavi, stesso ordine): un corpo diverso, anche di
 *     poco, si elenca e non si tocca;
 *   - da quella mappa si toglie il `body_pt`, e il `layout` solo se è
 *     «exercises», il valore che scriveva il modale;
 *   - `updated_at` resta quello di prima (`SET updated_at = updated_at`),
 *     altrimenti il giro notturno di Drive rimanda tutte queste mappe;
 *   - la scrittura vale solo se `metadata_json` è ancora quello letto: una
 *     modifica arrivata nel frattempo vince, e la riga si elenca;
 *   - ogni mappa pulita lascia una riga in content_action_log.
 *
 * Le chiavi che il modale ha cancellato (`mappa.href_hide`, `mappa.drawio_id`,
 * `display: hide`, `contract_key`) non si ricostruiscono da qui: il database
 * non le conserva.
 *
 * Strumento: tools/maps/pulisci_metadati_mappe.php. Prova:
 * tests/Integration/PuliziaMetadatiMappeTest.php.
 */
final class PuliziaMetadatiMappe
{
    /**
     * Il `body_pt` che il modale seminava: `exercisesSeedPt()` in
     * js/modules/features/sidepage-modal-content.js. Le due copie le tiene
     * uguali tests/Fixtures/seme-esercizi-body-pt.json, letto da una prova
     * PHP e da una prova vitest.
     */
    public const SEME = [
        ['_type' => 'sectionHeader', 'level' => 1, 'text' => 'Esercizi per studenti'],
        ['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => '', 'marks' => []]]],
        ['_type' => 'sectionHeader', 'level' => 1, 'text' => 'Verifiche'],
        ['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => '', 'marks' => []]]],
    ];

    private const STRUMENTO = 'tools/maps/pulisci_metadati_mappe.php';

    /**
     * Vero se questo `body_pt` è esattamente il seme del modale: stessi nodi,
     * stesse chiavi, stesso ordine. La domanda la fa anche
     * `TeacherContentRepository`, per distinguere il segnaposto da un corpo
     * che qualcuno ha scritto (revisione della PR #145, 20/9/2026): la
     * risposta sta qui, in un posto solo.
     */
    public static function eIlSeme(mixed $bodyPt): bool
    {
        return $bodyPt === self::SEME;
    }

    // Per esteso, non promossa `readonly`: semgrep leggerebbe il file a metà
    // (tools/ci/semgrep-censimento.json, «file_non_letti»).
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array{
     *   da_pulire: list<array{id:int, teacher_id:int, tolte:list<string>, restano:list<string>}>,
     *   pulite: list<int>,
     *   cambiate_nel_frattempo: list<int>,
     *   corpo_diverso: list<int>,
     *   solo_layout: list<int>,
     *   corpo_cifrato: list<int>
     * }
     */
    public function esegui(bool $applica, ?int $docente = null): array
    {
        $esito = [
            'da_pulire' => [], 'pulite' => [], 'cambiate_nel_frattempo' => [],
            'corpo_diverso' => [], 'solo_layout' => [], 'corpo_cifrato' => [],
        ];
        $righe = $this->pdo->prepare(
            "SELECT id, teacher_id, metadata_json, body_pt_ct
               FROM teacher_content_data
              WHERE content_subtype = 'mappa' AND metadata_json IS NOT NULL"
            . ($docente !== null ? ' AND teacher_id = ?' : '')
            . ' ORDER BY id'
        );
        $righe->execute($docente !== null ? [$docente] : []);
        $scrivi = $this->pdo->prepare(
            "UPDATE teacher_content_data
                SET metadata_json = ?, updated_at = updated_at
              WHERE id = ? AND content_subtype = 'mappa' AND metadata_json = ?"
        );

        foreach ($righe->fetchAll(PDO::FETCH_ASSOC) as $riga) {
            $id = (int)$riga['id'];
            $grezzo = (string)$riga['metadata_json'];
            $comeArray = json_decode($grezzo, true);
            $comeOggetto = json_decode($grezzo, false);
            if (!\is_array($comeArray) || !$comeOggetto instanceof stdClass) {
                continue;
            }
            $haCorpo = array_key_exists('body_pt', $comeArray);
            $haLayout = array_key_exists('layout', $comeArray);
            if (!$haCorpo && !$haLayout) {
                continue;
            }
            // Il corpo cifrato si guarda per primo, prima del `body_pt` in
            // chiaro: con il dual-write acceso il corpo NON sta nei metadati
            // (`TeacherContentRepository::extractBodyPt` lo toglie da
            // `metadata_json` per cifrarlo), quindi una riga così non arriva
            // né al confronto con il seme né alla porta del solo layout. Messo
            // dopo, il controllo non poteva scattare nel caso che dichiara e
            // la riga si elencava fra quelle col solo layout (revisione della
            // PR #145, 20/9/2026).
            if ($riga['body_pt_ct'] !== null) {
                $esito['corpo_cifrato'][] = $id;
                continue;
            }
            if (!$haCorpo) {
                $esito['solo_layout'][] = $id;
                continue;
            }
            if (!self::eIlSeme($comeArray['body_pt'])) {
                $esito['corpo_diverso'][] = $id;
                continue;
            }

            $tolte = ['body_pt'];
            unset($comeOggetto->body_pt);
            if (($comeArray['layout'] ?? null) === 'exercises') {
                $tolte[] = 'layout';
                unset($comeOggetto->layout);
            }
            $restano = array_keys(get_object_vars($comeOggetto));
            $esito['da_pulire'][] = [
                'id' => $id, 'teacher_id' => (int)$riga['teacher_id'],
                'tolte' => $tolte, 'restano' => $restano,
            ];
            if (!$applica) {
                continue;
            }

            $nuovo = null;
            if ($restano !== []) {
                $nuovo = json_encode($comeOggetto, JSON_UNESCAPED_UNICODE);
                if ($nuovo === false) {
                    // Non si scrive un null al posto di metadati che non si sanno codificare.
                    $esito['cambiate_nel_frattempo'][] = $id;
                    continue;
                }
            }
            $scrivi->execute([$nuovo, $id, $grezzo]);
            if ($scrivi->rowCount() === 1) {
                $esito['pulite'][] = $id;
                ContentActionLogger::log(
                    ContentActionLogger::ACTION_UPDATED,
                    (int)$riga['teacher_id'],
                    $id,
                    'mappa',
                    ['changed_fields' => ['metadata'], 'tolte' => $tolte, 'strumento' => self::STRUMENTO]
                );
            } else {
                $esito['cambiate_nel_frattempo'][] = $id;
            }
        }
        return $esito;
    }
}
