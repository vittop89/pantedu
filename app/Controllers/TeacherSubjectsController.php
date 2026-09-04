<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit\ActivityLogger;
use App\Services\TeacherSubjectService;
use InvalidArgumentException;

/**
 * Il docente dichiara le materie che insegna.
 *
 * Routes:
 *   GET  /area-docente/materie  → schermata di scelta
 *   POST /area-docente/materie  → salva
 *
 * E' una PROPOSTA, non l'ultima parola: la scuola decide, e l'admin corregge
 * da /admin/sections. Ma chiederla al docente e' l'unico modo di partire con
 * qualcosa di sensato senza che l'admin debba indovinare per ognuno.
 *
 * La stessa pagina serve due momenti diversi: il primo accesso, dove ci si
 * arriva perche' il middleware ci manda, e le volte successive, dove ci si
 * arriva dal profilo per aggiungerne una. Il testo cambia, la sostanza no.
 */
final class TeacherSubjectsController
{
    private TeacherSubjectService $subjects;

    public function __construct(?TeacherSubjectService $subjects = null)
    {
        $this->subjects = $subjects ?? new TeacherSubjectService();
    }

    public function form(Request $req): Response
    {
        [$uid, $inst] = $this->scope();
        if ($uid <= 0 || $inst <= 0) {
            return Response::redirect('/area-docente/profilo');
        }

        $csrf        = Csrf::token();
        $disponibili = $this->subjects->available($inst);
        $mie         = array_column($this->subjects->forTeacher($uid, $inst), 'code');
        $flash       = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        // La cornice completa e non lo shell nudo. Il middleware che porta qui
        // dichiara di voler lasciare aperte due uscite (profilo e logout): con
        // lo shell non erano cliccabili da nessuna parte, e un muro senza porte
        // non e' un gate, e' un blocco.
        \ob_start();
        require __DIR__ . '/../../views/teacher/materie.php';
        $html = (string)\ob_get_clean();

        $r = new Response($html, 200);
        $r->headers['Content-Type'] = 'text/html; charset=UTF-8';
        return $r;
    }

    public function save(Request $req): Response
    {
        [$uid, $inst] = $this->scope();
        if ($uid <= 0 || $inst <= 0) {
            return Response::redirect('/area-docente/profilo');
        }

        $codici = $req->post['materia'] ?? [];
        $codici = is_array($codici) ? $codici : [$codici];
        if ($codici === []) {
            // Zero materie riporterebbe il docente qui al prossimo click: meglio
            // dirlo adesso che farglielo scoprire dal rimbalzo.
            $_SESSION['flash'] = [
                'type'  => 'error',
                'title' => 'Scegline almeno una.',
                'message' => 'Senza materie non puoi categorizzare i contenuti, '
                    . 'e i tuoi studenti non li troverebbero. Se manca la tua, scrivi all\'amministratore.',
            ];
            return Response::redirect('/area-docente/materie');
        }

        try {
            $esito = $this->subjects->set($uid, $inst, $codici, $uid);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'error', 'title' => 'Non è stato possibile salvare.',
                                  'message' => $e->getMessage()];
            return Response::redirect('/area-docente/materie');
        }

        ActivityLogger::event(
            'teacher_subjects_self_declared',
            subjectType: 'user',
            subjectId:   (string)$uid,
            details:     ['institute_id' => $inst] + $esito,
        );
        $_SESSION['flash'] = [
            'type'  => 'success',
            'title' => 'Materie salvate.',
            'message' => 'Le trovi nel selettore in alto a sinistra. Se qualcosa non torna, '
                . 'le corregge l\'amministratore dell\'istituto.',
        ];
        return Response::redirect('/area-docente/dashboard');
    }

    /** @return array{0:int,1:int} [user_id, institute_id] */
    private function scope(): array
    {
        $user = Auth::user();
        return [(int)($user['id'] ?? 0), (int)(Auth::currentInstitute() ?? 0)];
    }
}
