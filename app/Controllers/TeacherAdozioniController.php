<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\Curriculum\AdozioniRepository;
use App\Repositories\Curriculum\CurriculumTeacherRepository;
use App\Support\CurriculumLookup;
use App\Support\Sources\SourcesRegistryStore;
use App\Support\TeacherContextResolver;

/**
 * I libri in adozione che riguardano il docente (ADR-036).
 *
 *   GET /api/teacher/adozioni[?classe=2A&materia=MAT&tutte=1&anno=2026/2027]
 *
 * Per default: i libri dell'istituto corrente del docente, per le classi e le
 * materie che ha spuntato nel proprio catalogo (un anno secco copre le sue
 * sezioni). `classe` e `materia` restringono; `tutte=1` ignora le spunte e
 * mostra tutto l'istituto — per chi cerca un libro di una classe che non ha
 * ancora attivato. Ogni riga porta la proposta di fonte pronta per il
 * registro di /area-docente/fonti e dice se il libro è già lì.
 */
final class TeacherAdozioniController
{
    // Per esteso, non promosse `readonly`: semgrep leggerebbe il file a metà
    // (tools/ci/semgrep-censimento.json, «file_non_letti»; 23/9/2026).
    private ?AdozioniRepository $adozioni;
    private ?CurriculumTeacherRepository $relazione;

    public function __construct(
        ?AdozioniRepository $adozioni = null,
        ?CurriculumTeacherRepository $relazione = null,
    ) {
        $this->adozioni = $adozioni;
        $this->relazione = $relazione;
    }

    public function index(Request $req): Response
    {
        if (!Database::isAvailable()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = TeacherContextResolver::currentTeacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $iid = CurriculumLookup::instituteForTeacher($tid);
        if ($iid === null || $iid <= 0) {
            return Response::json(\App\Services\SenzaScuola::risposta(), 404);
        }

        $adozioni  = $this->adozioni ?? new AdozioniRepository();
        $relazione = $this->relazione ?? new CurriculumTeacherRepository();

        $tutte   = (string)($req->query['tutte'] ?? '') === '1';
        $classe  = trim((string)($req->query['classe'] ?? ''));
        $materia = trim((string)($req->query['materia'] ?? ''));
        $anno    = trim((string)($req->query['anno'] ?? ''));

        // Le spunte del docente: classi e materie attive nell'istituto.
        $classiMie  = [];
        $materieMie = [];
        foreach ($relazione->perDocente($tid, $iid, null, true) as $r) {
            if ($r['kind'] === 'classi') {
                $classiMie[] = (string)$r['code'];
            } elseif ($r['kind'] === 'materie') {
                $materieMie[] = (string)$r['code'];
            }
        }

        $classi  = $classe !== '' ? [$classe] : ($tutte ? [] : $classiMie);
        $materie = $materia !== '' ? [$materia] : ($tutte ? [] : $materieMie);
        if ($anno === '') {
            $anno = (string)($adozioni->annoPiuRecente($iid) ?? '');
        }

        // Senza spunte non c'è niente da filtrare: si dice, invece di
        // mostrare tutto il catalogo come se fosse una scelta.
        $senzaSpunte = !$tutte && $classe === '' && $materia === '' && $classiMie === [];

        // Il catalogo è della scuola attiva, il registro delle fonti sta nella
        // casa dei file privati, dove /area-docente/fonti lo scrive. Fino al
        // 23/9/2026 lo si cercava nella scuola attiva (A-7): per un docente di
        // due scuole, con la seconda attiva, nessun libro risultava già preso.
        $presenti = SourcesRegistryStore::giaPresenti($tid, TeacherContextResolver::privateFilesInstituteId($tid));
        $libri = [];
        if (!$senzaSpunte) {
            foreach ($adozioni->perClassiEMaterie($iid, $classi, $materie, $anno !== '' ? $anno : null) as $r) {
                $proposta = AdozioniRepository::propostaDiFonte($r);
                $isbn = strtoupper((string)preg_replace('/[^0-9Xx]/', '', (string)$r['isbn']));
                $libri[] = [
                    'id'              => (int)$r['id'],
                    'anno_scolastico' => (string)$r['anno_scolastico'],
                    'classe'          => (string)$r['classe'],
                    'indirizzo'       => $r['indirizzo'],
                    'materia'         => $r['materia'],
                    'disciplina'      => (string)$r['disciplina'],
                    'isbn'            => (string)$r['isbn'],
                    'titolo'          => (string)$r['titolo'],
                    'sottotitolo'     => $r['sottotitolo'],
                    'autori'          => $r['autori'],
                    'editore'         => $r['editore'],
                    'volume'          => $r['volume'],
                    'prezzo'          => $r['prezzo'] !== null ? (float)$r['prezzo'] : null,
                    'nuova_adozione'  => (bool)$r['nuova_adozione'],
                    'consigliato'     => (bool)$r['consigliato'],
                    'origine'         => (string)$r['origine'],
                    'proposta'        => $proposta,
                    'in_registro'     => ($isbn !== '' && isset($presenti['isbn'][$isbn]))
                        || isset($presenti['key'][$proposta['key']]),
                ];
            }
        }

        return Response::json([
            'ok'              => true,
            'institute_id'    => $iid,
            'anno_scolastico' => $anno !== '' ? $anno : null,
            'classi_mie'      => $classiMie,
            'materie_mie'     => $materieMie,
            'filtro'          => ['classe' => $classe, 'materia' => $materia, 'tutte' => $tutte],
            'senza_spunte'    => $senzaSpunte,
            'catalogo_vuoto'  => $adozioni->conta($iid) === 0,
            'libri'           => $libri,
        ]);
    }
}
