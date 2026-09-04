<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Repositories\ExerciseRepository;
use App\Services\OwnershipService;
use PDO;
use Throwable;

final class TeacherController
{
    private OwnershipService $owners;

    public function __construct(?OwnershipService $owners = null)
    {
        $this->owners = $owners ?? new OwnershipService(
            Config::get('app.paths.storage') . '/data/ownership.json'
        );
    }

    public function dashboard(Request $req): Response
    {
        // G22.S22 — Allineato al pattern delle altre pagine /area-docente:
        // il view fa include diretto di views/layout/app.php (sidebar +
        // chrome coerenti). La precedente render via layout/shell mostrava
        // dashboard senza sidebar al reload.
        $user   = Auth::user() ?? ['username' => '-', 'role' => 'guest'];
        $counts = $this->dashboardCounts((int)($user['id'] ?? 0));
        ob_start();
        require __DIR__ . '/../../views/teacher/dashboard.php';
        $html = ob_get_clean();
        $r = new Response($html, 200);
        $r->headers['Content-Type'] = 'text/html; charset=UTF-8';
        return $r;
    }

    /**
     * G22.S25 — Conteggi dashboard dal source-of-truth attuale (teacher_content
     * + verifica_documents). La precedente lettura via OwnershipService
     * (tabella ownership legacy path-based) restituiva sempre 0 post
     * G22.S22 refactor: la tabella ownership non è più aggiornata.
     *
     * @return array{mappe:int,eser:int,lab:int,verifiche:int}
     */
    private function dashboardCounts(int $teacherId): array
    {
        $zero = ['mappe' => 0, 'eser' => 0, 'lab' => 0, 'verifiche' => 0];
        if ($teacherId <= 0 || !Config::get('database.enabled') || !Database::isAvailable()) {
            return $zero;
        }
        $pdo = Database::connection();
        // teacher_content per type, escluse archived
        $stmt = $pdo->prepare(
            "SELECT content_type, COUNT(*) AS n FROM teacher_content
              WHERE teacher_id = ? AND visibility <> 'archived'
              GROUP BY content_type"
        );
        $stmt->execute([$teacherId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            switch ((string)$r['content_type']) {
                case 'mappa':
                    $zero['mappe']     = (int)$r['n'];
                    break;
                case 'esercizio':
                    $zero['eser']      = (int)$r['n'];
                    break;
                case 'lab':
                    $zero['lab']       = (int)$r['n'];
                    break;
                case 'verifica':
                    $zero['verifiche'] = (int)$r['n'];
                    break;
            }
        }
        // Aggiunge verifica_documents (TEX/PDF compilati) al conteggio verifiche
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM verifica_documents WHERE teacher_id = ?'
        );
        $stmt->execute([$teacherId]);
        $zero['verifiche'] += (int)$stmt->fetchColumn();
        return $zero;
    }

    public function resources(Request $req): Response
    {
        $user     = Auth::user() ?? ['username' => '-', 'role' => 'guest'];
        $username = (string)($user['username'] ?? '-');
        return Response::json(['ok' => true, 'resources' => $this->owners->listFor($username)]);
    }

    // G22.S15.bis Fase 5+ — RIMOSSI 3 metodi M11 dead-path:
    //   verifiche(), downloadVerifica(), cloneExercise()
    // Endpoint sostituiti da:
    //   GET /api/teacher/content?type=verifica   (TeacherContentController)
    //   GET /api/verifica/{id}/tex                (VerificaController)
    //   POST /api/teacher/content                  (TeacherContentController)
    // JS frontend non chiamava nessuno di questi endpoint (audit zero call).
    // Tabelle teacher_verifiche/teacher_exercises in drop via migration.
}
