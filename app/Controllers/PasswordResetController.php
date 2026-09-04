<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AccessLogger;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Security\PasswordPolicy;
use App\Services\Security\PasswordResetService;
use RuntimeException;

/**
 * Recupero password: modulo di richiesta e pagina di reimpostazione.
 *
 * Il modulo di richiesta risponde SEMPRE allo stesso modo — indirizzo noto o
 * sconosciuto, invio riuscito o fallito. La logica sta in
 * PasswordResetService, che documenta il perche'; qui si tratta solo di non
 * tradirlo per strada, per esempio mostrando un errore diverso quando il
 * servizio non ha trovato nessuno.
 */
final class PasswordResetController
{
    public function showForgot(Request $req): Response
    {
        return $this->render('auth/password_forgot', 'Password dimenticata — Pantedu', [
            'csrf' => Csrf::token(),
            'sent' => isset($req->query['sent']),
        ]);
    }

    public function submitForgot(Request $req): Response
    {
        $email = (string)($req->post['email'] ?? '');
        (new PasswordResetService())->request($email, $this->clientIp($req));

        // Nessun esito nel log applicativo legato all'indirizzo: registrare
        // "richiesta per un indirizzo inesistente" ricostruirebbe nei log la
        // stessa distinzione che la pagina si impegna a non fare.
        (new AccessLogger())->logAccess('anonymous', 'guest', '/password/forgot', 'password_reset_requested');

        return Response::redirect('/password/forgot?sent=1');
    }

    public function showReset(Request $req): Response
    {
        $token = (string)($req->query['token'] ?? '');
        $valid = (new PasswordResetService())->verify($token) !== null;

        return $this->render('auth/password_reset', 'Nuova password — Pantedu', [
            'csrf'  => Csrf::token(),
            'token' => $token,
            'valid' => $valid,
            'error' => $this->errorMessage((string)($req->query['error'] ?? '')),
        ]);
    }

    public function submitReset(Request $req): Response
    {
        $token   = (string)($req->post['token'] ?? '');
        $new     = (string)($req->post['new_password'] ?? '');
        $confirm = (string)($req->post['confirm_password'] ?? '');
        $back    = '/password/reset?token=' . urlencode($token) . '&error=';

        try {
            PasswordPolicy::validate($new, $confirm);
        } catch (RuntimeException $e) {
            return Response::redirect($back . urlencode($e->getMessage()));
        }

        if (!(new PasswordResetService())->consume($token, $new)) {
            return Response::redirect($back . urlencode('invalid_token'));
        }

        (new AccessLogger())->logAccess('anonymous', 'guest', '/password/reset', 'password_reset_completed');
        Csrf::rotate();

        // Nessun accesso automatico dopo la reimpostazione: l'utente rifa' il
        // login. E' anche il punto in cui, se ha la verifica in due passaggi,
        // il codice gli viene chiesto — reimpostare la password non e' e non
        // deve diventare un modo per aggirarla.
        return Response::redirect('/login?reset=1');
    }

    private function errorMessage(string $code): string
    {
        if ($code === '') {
            return '';
        }
        if ($code === 'invalid_token') {
            return 'Link non valido o scaduto. Richiedine uno nuovo.';
        }
        return PasswordPolicy::message($code);
    }

    private function clientIp(Request $req): string
    {
        return \App\Services\Waf\EdgeContext::clientIp($req->server ?? []);
    }

    /** @param array<string,mixed> $data */
    private function render(string $tpl, string $title, array $data): Response
    {
        $view = View::default();
        return Response::html($view->render('layout/shell', [
            'title' => $title,
            'body'  => $view->render($tpl, $data),
            'modal' => true,
        ]));
    }
}
