<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Support\ClassAccessGrant;

/**
 * Chi può studiare: uno studente (o chi ha un ruolo superiore) con il suo
 * account, oppure un ospite entrato con la credenziale di classe del docente.
 *
 * PERCHÉ ESISTE (14/9/2026)
 *   Le pagine e le API di studio stavano nel gruppo degli studenti
 *   (`auth` + `role:student`). La credenziale di classe (ADR-032) non apre una
 *   sessione autenticata: mette un grant in sessione, e i controller lo sanno
 *   leggere. Ma il middleware `auth` guarda solo la sessione autenticata, quindi
 *   l'ospite con la credenziale riceveva 401 da ogni API di studio e la pagina
 *   lo mandava al login. Misurato con una sonda sulla suite end-to-end: la
 *   modalità dichiarata al DPO e nell'informativa non mostrava niente.
 *
 * LA REGOLA
 *   - Con un account: le stesse porte di prima, nello stesso ordine —
 *     AuthMiddleware (password da cambiare, secondo fattore obbligatorio) e poi
 *     RoleMiddleware con la zona `student`.
 *   - Senza account: passa solo chi ha almeno una credenziale valida.
 *     `ClassAccessGrant::all()` la riverifica sul database a ogni richiesta, quindi
 *     una credenziale spenta, scaduta o cancellata non apre più niente.
 *   - Tutti gli altri: come AuthMiddleware, 401 a chi chiede JSON e il login alle
 *     pagine.
 *
 * Cosa vede l'ospite lo decidono i controller, con le regole della credenziale
 * (ContentVisibilityPolicy: i contenuti pubblicati del docente della
 * credenziale, nella sua scuola, per la classe coperta). Qui si decide solo chi
 * entra, e il gruppo di rotte che lo usa è di sola lettura.
 */
final class StudioMiddleware
{
    public function handle(Request $req, callable $next): Response
    {
        if (Auth::check()) {
            return (new AuthMiddleware())->handle(
                $req,
                static fn(Request $r): Response => (new RoleMiddleware())->handle($r, $next, 'student')
            );
        }
        if (ClassAccessGrant::all() !== []) {
            return $next($req);
        }
        if ($req->wantsJson()) {
            return Response::json(['error' => 'unauthenticated'], 401);
        }
        return Response::redirect('/login?redirect=' . urlencode((string)($req->server['REQUEST_URI'] ?? '/')));
    }
}
