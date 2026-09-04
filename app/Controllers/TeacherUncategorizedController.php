<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\TeacherContentRepository;
use App\Services\Audit\ActivityLogger;
use App\Support\AuthHelpers;
use InvalidArgumentException;

/**
 * I contenuti del docente a cui manca l'indirizzo.
 *
 * PERCHE' SERVE
 *   La navigazione filtra per indirizzo: un contenuto che non ce l'ha non
 *   compare da nessuna parte, e non c'e' modo di arrivarci. Non e' un caso
 *   raro — nascono cosi' tutti quelli creati prima che gli indirizzi
 *   esistessero. Senza questa pagina l'unico sintomo e' "dove sono finite le
 *   mie mappe", ed e' una domanda a cui l'interfaccia non sapeva rispondere.
 *
 * PERCHE' A GRUPPI
 *   Assegnare l'indirizzo a cinquanta contenuti uno per uno non lo fa nessuno.
 *   Raggruppati per classe, materia e tipo diventano una decina di scelte, e
 *   ognuna e' evidente: "le mappe di matematica della prima" sanno da sole
 *   dove vanno.
 *
 * Routes:
 *   GET  /area-docente/da-categorizzare
 *   POST /area-docente/da-categorizzare
 */
final class TeacherUncategorizedController
{
    private TeacherContentRepository $repo;

    public function __construct(?TeacherContentRepository $repo = null)
    {
        $this->repo = $repo ?? new TeacherContentRepository();
    }

    public function index(Request $req): Response
    {
        if (!AuthHelpers::isTeacherOrAdmin()) {
            return Response::html('<h1>403</h1><p>Solo docenti.</p>', 403);
        }
        $uid = (int)(Auth::user()['id'] ?? 0);
        if ($uid <= 0) {
            return Response::redirect('/login');
        }

        $gruppi    = $this->repo->daCategorizzare($uid);
        $indirizzi = $this->vociDelDocente($uid, 'indirizzi');
        $classi    = $this->vociDelDocente($uid, 'classi');
        $materie   = $this->vociDelDocente($uid, 'materie');
        $csrf      = Csrf::token();
        $flash     = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        // Come le altre pagine /area-docente/*: la vista si include e si porta
        // dietro la cornice (nav + layout app.php, quindi sidebar). Renderla
        // con lo shell nudo faceva sparire proprio la nav da cui si arriva.
        \ob_start();
        require __DIR__ . '/../../views/area_docente/da_categorizzare.php';
        $html = (string)\ob_get_clean();

        $r = new Response($html, 200);
        $r->headers['Content-Type'] = 'text/html; charset=UTF-8';
        return $r;
    }

    public function save(Request $req): Response
    {
        if (!AuthHelpers::isTeacherOrAdmin()) {
            return Response::html('<h1>403</h1><p>Solo docenti.</p>', 403);
        }
        $uid = (int)(Auth::user()['id'] ?? 0);
        $ids = $req->post['ids'] ?? '';
        $ids = array_filter(array_map('intval', explode(',', (string)$ids)));

        // Ogni campo e' facoltativo: si puo' mettere la materia oggi e la
        // classe domani. Zero significa "non scelto", non "azzera".
        $scelte = [];
        foreach (['indirizzo_id', 'classe_id', 'subject_id'] as $campo) {
            $v = (int)($req->post[$campo] ?? 0);
            $scelte[$campo] = $v > 0 ? $v : null;
        }

        try {
            $fatti = $this->repo->assegnaCategorie(
                $uid,
                $ids,
                $scelte['indirizzo_id'],
                $scelte['classe_id'],
                $scelte['subject_id'],
            );
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = [
                'type'  => 'error',
                'title' => 'Voce non valida.',
                'message' => 'Scegli fra le voci su cui lavori.',
            ];
            return Response::redirect('/area-docente/da-categorizzare');
        }

        ActivityLogger::event(
            'content_categorized',
            subjectType: 'user',
            subjectId:   (string)$uid,
            details:     $scelte + ['campi' => $fatti],
        );
        $_SESSION['flash'] = $fatti > 0
            ? ['type' => 'success', 'title' => "$fatti etichette messe.",
               'message' => 'I contenuti completi di indirizzo, classe e materia compaiono nella navigazione.']
            : ['type' => 'warn', 'title' => 'Nessuna modifica.',
               'message' => "Quei campi erano gia' pieni: forse li hai gia' sistemati."];
        return Response::redirect('/area-docente/da-categorizzare');
    }

    /**
     * Le voci di un tipo su cui il docente lavora, in tutti i suoi istituti.
     *
     * Non solo quelle della scuola corrente: un contenuto senza etichette puo'
     * essere di qualunque periodo, e restringere alla scuola selezionata ora
     * renderebbe impossibile sistemare il resto senza cambiare istituto e
     * tornare qui.
     *
     * @return list<array{id:int,code:string,label:string,istituto:string}>
     */
    private function vociDelDocente(int $uid, string $kind): array
    {
        $st = \App\Core\Database::connection()->prepare(
            'SELECT c.id, c.code, c.label, COALESCE(i.name, i.code) AS istituto
               FROM curriculum_entries c
               JOIN institutes i ON i.id = c.institute_id
              WHERE c.kind = ? AND c.owner_user_id = ? AND c.active = 1
              ORDER BY istituto, c.code'
        );
        $st->execute([$kind, $uid]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
