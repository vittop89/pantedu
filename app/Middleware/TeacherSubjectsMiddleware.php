<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\TeacherSubjectService;
use Throwable;

/**
 * Al primo accesso, un docente dichiara quali materie insegna.
 *
 * In modalita' `single` il docente non si registra da solo: lo crea l'admin.
 * Quindi non esiste un "momento dell'iscrizione" in cui chiedergli le materie,
 * e il primo accesso e' il punto piu' vicino che ci sia — con il vantaggio che
 * non si puo' saltare, mentre una voce nel profilo si.
 *
 * Serve perche' senza materie il docente non puo' categorizzare niente: ogni
 * contenuto che pubblica resta senza subject_code, e dal lato studente il
 * filtro per materia non lo trova. Meglio chiederglielo una volta all'inizio
 * che lasciarlo scoprire dopo aver caricato del lavoro.
 *
 * Quello che dichiara e' una PROPOSTA, non l'ultima parola: l'admin la corregge
 * da /admin/sections, che e' dove stanno le altre decisioni della scuola.
 *
 * Gate mirato, non globale: blocca l'area docente, non tutto il sito. Un
 * docente fermo qui deve comunque poter uscire, leggere le note legali e
 * arrivare al proprio profilo — un muro che impedisce anche quello non e' un
 * gate, e' un blocco.
 */
final class TeacherSubjectsMiddleware
{
    /** La pagina di scelta e le vie d'uscita non possono rimbalzare a se stesse. */
    private const EXEMPT_PATHS = [
        '/area-docente/materie',
        '/area-docente/profilo',
        '/logout',
    ];

    private TeacherSubjectService $subjects;

    public function __construct(?TeacherSubjectService $subjects = null)
    {
        $this->subjects = $subjects ?? new TeacherSubjectService();
    }

    public function handle(Request $req, callable $next): Response
    {
        if (!Auth::check() || Auth::isSuperAdmin()) {
            return $next($req);
        }

        $path = (string)($req->server['REQUEST_URI'] ?? '/');
        if (($qs = strpos($path, '?')) !== false) {
            $path = substr($path, 0, $qs);
        }
        $path = rtrim($path, '/') ?: '/';
        if (in_array($path, self::EXEMPT_PATHS, true)) {
            return $next($req);
        }

        try {
            $user = Auth::user();
            $uid  = (int)($user['id'] ?? 0);
            $inst = (int)(Auth::currentInstitute() ?? 0);
            // Senza istituto non c'e' vocabolario da cui scegliere: il docente
            // deve prima collegarsi a una scuola, e per quello ha il profilo.
            if ($uid <= 0 || $inst <= 0) {
                return $next($req);
            }
            if ($this->subjects->forTeacher($uid, $inst) !== []) {
                return $next($req);
            }
            // Se l'istituto non ha ancora materie a catalogo, la schermata
            // sarebbe vuota: chiedere di scegliere fra niente e' peggio che
            // non chiedere. Lo segnala il pannello admin, non questo muro.
            if ($this->subjects->available($inst) === []) {
                return $next($req);
            }
        } catch (Throwable) {
            // Un problema di lettura non deve chiudere fuori il docente.
            return $next($req);
        }

        return Response::redirect('/area-docente/materie');
    }
}
