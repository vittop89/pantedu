<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * La pagina dell'amministratore di istituto (ADR-040, 14/9/2026).
 *
 * È il posto dove atterra dopo l'accesso, e per ora l'unico della zona
 * `istituto`: dice chi è, quale istituto amministra e che cosa può fare oggi.
 * Le funzioni sull'istituto (registrazioni, docenti, classi ammesse…) si
 * aprono una alla volta, con l'ambito verificato da Auth::isAdminOfInstitute:
 * l'elenco sta nell'ADR.
 */
final class IstitutoController
{
    public function page(Request $req): Response
    {
        $iid = Auth::currentInstitute();
        $istituto = null;
        if ($iid !== null && Database::isAvailable()) {
            $stmt = Database::connection()->prepare('SELECT id, code, name, city FROM institutes WHERE id = ? LIMIT 1');
            $stmt->execute([$iid]);
            $riga = $stmt->fetch(\PDO::FETCH_ASSOC);
            $istituto = is_array($riga) ? $riga : null;
        }

        $view = View::default();
        $body = $view->render('istituto/index', [
            'utente'   => Auth::user(),
            'istituto' => $istituto,
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => 'Il tuo istituto — Pantedu',
            'body'  => $body,
            'modal' => false,
        ]));
    }
}
