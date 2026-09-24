<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\Curriculum\CurriculumTeacherRepository;
use App\Repositories\InstituteRepository;
use App\Services\Audit\ActivityLogger;
use App\Services\CurriculumService;
use App\Services\InstituteMergeService;

/**
 * Il catalogo di un istituto, per l'amministratore (ADR-035, fase 4).
 *
 *   GET  /admin/institutes/{id}/catalogo            il vocabolario, con la provenienza di
 *                                                   ogni voce, quanti docenti l'hanno spuntata
 *                                                   e quanti contenuti ci puntano
 *   POST /admin/institutes/{id}/catalogo            una voce nuova (origine «istituto»)
 *   POST /admin/institutes/{id}/catalogo/{entry}    rinomina · attiva · disattiva · accetta · elimina
 *
 * È l'unico posto dove l'etichetta di una voce si corregge una volta per
 * tutti — il docente dal profilo cambia solo ciò che vede lui — e dove le voci
 * entrate nel vocabolario senza passare dal dataset MIUR (`origine =
 * 'docente'`, promosse dalla migrazione 113 da una copia di un docente) si
 * rivedono: si accettano come voci della scuola, o si tolgono se nessuno le
 * usa. Una voce con spunte o contenuti sopra non si elimina: prima si
 * spostano, e questo pannello lo dice.
 */
final class AdminCatalogoController
{
    private const AZIONI = ['rinomina', 'attiva', 'disattiva', 'accetta', 'elimina'];

    private function svc(): CurriculumService
    {
        return new CurriculumService(
            jsonPath:  Config::get('app.paths.storage') . '/data/curriculum.json',
        );
    }

    /** @param array<string,mixed> $params */
    public function index(Request $req, array $params): Response
    {
        $iid = (int)($params['id'] ?? 0);
        $istituto = $iid > 0 ? (new InstituteRepository())->findById($iid) : null;
        if ($istituto === null) {
            return Response::html('Istituto non trovato', 404);
        }
        $catalogo = $this->svc()->all($iid, null);
        $docenti  = (new CurriculumTeacherRepository())->docentiPerVoce($iid);
        $contenuti = $this->contenutiPerVoce($iid);
        foreach ($catalogo as $kind => &$voci) {
            foreach ($voci as &$v) {
                $v['docenti']   = $docenti[$v['id']] ?? 0;
                $v['contenuti'] = $contenuti[$v['id']] ?? 0;
            }
            unset($v);
        }
        unset($voci);

        $view = View::default();
        $body = $view->render('admin/institutes_catalogo', [
            'istituto' => $istituto,
            'catalogo' => $catalogo,
            'flash'    => $_SESSION['flash'] ?? null,
            'csrf'     => Csrf::token(),
        ]);
        unset($_SESSION['flash']);
        return Response::html($view->render('layout/shell', [
            'title' => 'Catalogo — ' . (string)($istituto['name'] ?? $iid) . ' — Admin',
            'body'  => $body,
        ]));
    }

    /**
     * Una voce nuova nel vocabolario della scuola (`origine = 'istituto'`):
     * una sezione o una materia che il dataset MIUR non porta.
     *
     * @param array<string,mixed> $params
     */
    public function aggiungi(Request $req, array $params): Response
    {
        $iid   = (int)($params['id'] ?? 0);
        $torna = '/admin/institutes/' . $iid . '/catalogo';
        if (!Database::isAvailable()) {
            return $this->flash($torna, 'error', 'Database non disponibile.');
        }
        if ($iid <= 0 || (new InstituteRepository())->findById($iid) === null) {
            return $this->flash($torna, 'error', 'Istituto non trovato.');
        }
        $kind  = trim((string)($req->post['kind'] ?? ''));
        $code  = strtoupper(trim((string)($req->post['code'] ?? '')));
        $label = trim((string)($req->post['label'] ?? ''));
        try {
            $rec = $this->svc()->add($kind, [
                'code'      => $code,
                'label'     => $label,
                'group'     => trim((string)($req->post['group'] ?? '')),
                'indirizzo' => trim((string)($req->post['indirizzo'] ?? '')),
            ], $iid, null, 'istituto');
        } catch (\RuntimeException $e) {
            // ADR-042 — i due rifiuti nuovi detti a parole.
            $motivo = match ($e->getMessage()) {
                'anno_senza_indirizzo'  => 'un anno si aggiunge dentro un indirizzo: scrivi la sigla del corso',
                'indirizzo_sconosciuto' => 'il corso indicato non è fra gli indirizzi della scuola',
                default                 => $e->getMessage(),
            };
            return $this->flash($torna, 'error', sprintf('Voce «%s» non aggiunta: %s.', $code, $motivo));
        }
        $actor = (int)(Auth::user()['id'] ?? 0);
        ActivityLogger::event(
            'catalogo_istituto_modifica',
            subjectType: 'curriculum_entries',
            subjectId:   (string)$rec['id'],
            details:     ['kind' => $kind, 'code' => $code, 'institute_id' => $iid, 'azione' => 'aggiungi', 'label' => $label],
            actorUserId: $actor > 0 ? $actor : null,
        );
        return $this->flash($torna, 'success', sprintf('«%s» (%s) aggiunta al vocabolario.', $label, $code));
    }

    /** @param array<string,mixed> $params */
    public function modifica(Request $req, array $params): Response
    {
        $iid   = (int)($params['id'] ?? 0);
        $entry = (int)($params['entry'] ?? 0);
        $azione = (string)($req->post['azione'] ?? '');
        $torna = '/admin/institutes/' . $iid . '/catalogo';
        if (!Database::isAvailable()) {
            return $this->flash($torna, 'error', 'Database non disponibile.');
        }
        $svc  = $this->svc();
        $voce = $entry > 0 ? $svc->getById($entry) : null;
        if ($voce === null || (int)$voce['institute_id'] !== $iid || $iid <= 0) {
            return $this->flash($torna, 'error', 'Voce non trovata in questo istituto.');
        }
        if (!\in_array($azione, self::AZIONI, true)) {
            return $this->flash($torna, 'error', 'Azione sconosciuta.');
        }

        $actor = (int)(Auth::user()['id'] ?? 0);
        $dettagli = ['kind' => $voce['kind'], 'code' => $voce['code'], 'institute_id' => $iid, 'azione' => $azione];
        try {
            switch ($azione) {
                case 'rinomina':
                    $label = trim((string)($req->post['label'] ?? ''));
                    $svc->updateById($entry, ['label' => $label]);
                    $dettagli['da'] = $voce['label'];
                    $dettagli['a']  = $label;
                    $msg = sprintf('«%s» rinominata in «%s», per tutti.', $voce['label'], $label);
                    break;
                case 'attiva':
                case 'disattiva':
                    $svc->updateById($entry, ['active' => $azione === 'attiva' ? 'true' : 'false']);
                    $msg = sprintf('«%s» %s.', $voce['label'], $azione === 'attiva' ? 'attivata' : 'disattivata');
                    break;
                case 'accetta':
                    $svc->updateById($entry, ['origine' => 'istituto']);
                    $msg = sprintf('«%s» è una voce della scuola.', $voce['label']);
                    break;
                default:
                    $docenti   = (new CurriculumTeacherRepository())->docentiPerVoce($iid)[$entry] ?? 0;
                    $contenuti = $this->contenutiPerVoce($iid)[$entry] ?? 0;
                    if ($docenti > 0 || $contenuti > 0) {
                        return $this->flash($torna, 'error', sprintf(
                            '«%s» non si elimina: %d docenti l\'hanno spuntata e %d contenuti ci puntano. Prima si spostano.',
                            $voce['label'],
                            $docenti,
                            $contenuti
                        ));
                    }
                    $svc->removeById($entry);
                    $msg = sprintf('«%s» tolta dal vocabolario.', $voce['label']);
            }
        } catch (\Throwable $e) {
            return $this->flash($torna, 'error', 'Modifica non applicata: ' . $e->getMessage());
        }

        ActivityLogger::event(
            'catalogo_istituto_modifica',
            subjectType: 'curriculum_entries',
            subjectId:   (string)$entry,
            details:     $dettagli,
            actorUserId: $actor > 0 ? $actor : null,
        );
        return $this->flash($torna, 'success', $msg);
    }

    /**
     * Quanti riferimenti ha ogni voce dell'istituto, sommati su tutte le
     * tabelle che puntano a `curriculum_entries` (scoperte dallo schema, come
     * fa la fusione degli istituti: così una tabella nuova non si dimentica).
     *
     * @return array<int,int> curriculum_id → riferimenti
     */
    private function contenutiPerVoce(int $iid): array
    {
        $pdo = Database::connection();
        $out = [];
        foreach ((new InstituteMergeService($pdo))->referrersTo('curriculum_entries') as [$tabella, $colonna]) {
            if ($tabella === 'curriculum_teacher') {
                continue; // le spunte si contano a parte
            }
            $st = $pdo->prepare(
                "SELECT r.`$colonna` AS id, COUNT(*) AS n
                   FROM `$tabella` r
                   JOIN curriculum_entries ce ON ce.id = r.`$colonna`
                  WHERE ce.institute_id = ?
                  GROUP BY r.`$colonna`"
            );
            $st->execute([$iid]);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['id']] = ($out[(int)$r['id']] ?? 0) + (int)$r['n'];
            }
        }
        return $out;
    }

    private function flash(string $to, string $type, string $message): Response
    {
        $_SESSION['flash'] = ['type' => $type, 'title' => 'Catalogo', 'message' => $message];
        return Response::redirect($to);
    }
}
