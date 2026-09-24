<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Audit\ActivityLogger;
use App\Services\Security\CambioEmail;
use App\Services\Security\TwoFactorPolicy;
use App\Support\PaginaConGettone;
use PDO;

/**
 * «Il mio account»: email, password e verifica in due passaggi in un posto solo
 * (15/9/2026, richiesta dell'utente: nella barra dell'amministrazione c'erano
 * «Password» e «Due fattori», e l'email non si poteva cambiare da nessuna pagina).
 *
 * Routes:
 *   GET  /me/account                    → la pagina
 *   POST /me/account/email              → chiede il cambio dell'email (password + nuovo indirizzo)
 *   GET  /me/account/email/conferma     → pagina del link ricevuto al nuovo indirizzo
 *   POST /me/account/email/conferma     → conferma e cambia l'email
 *
 * La conferma è un POST dietro un bottone, non il GET del link: alcuni programmi
 * di posta aprono i link da soli per controllarli, e un GET che cambia l'email
 * la cambierebbe senza che nessuno l'abbia deciso.
 */
final class AccountController
{
    private const MESSAGGI = [
        CambioEmail::RICHIESTA_INVIATA => ['ok', 'Ti abbiamo scritto al nuovo indirizzo: l\'email cambia quando apri il link, entro un\'ora. Al vecchio indirizzo è arrivato un avviso.'],
        CambioEmail::PASSWORD_ERRATA   => ['error', 'La password non è corretta.'],
        CambioEmail::EMAIL_NON_VALIDA  => ['error', 'Il nuovo indirizzo non è valido.'],
        CambioEmail::UGUALE            => ['error', 'È già l\'indirizzo del tuo account.'],
        CambioEmail::GIA_IN_USO        => ['error', 'Quell\'indirizzo è già usato da un altro account.'],
        CambioEmail::TROPPO_PRESTO     => ['error', 'Hai appena chiesto un cambio: controlla la posta, o riprova fra due minuti.'],
        CambioEmail::SENZA_POSTA       => ['error', 'Questa installazione non manda posta: l\'email non si può confermare.'],
        CambioEmail::ERRORE            => ['error', 'Non è stato possibile chiedere il cambio. Riprova.'],
    ];

    public function __construct(private ?CambioEmail $cambio = null)
    {
    }

    private function cambio(): CambioEmail
    {
        return $this->cambio ??= new CambioEmail();
    }

    public function page(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::redirect('/login');
        }
        $user = Auth::user() ?? [];
        $st = Database::connection()->prepare('SELECT email, totp_enabled, two_factor_method FROM users WHERE id = ? LIMIT 1');
        $st->execute([(int)($user['id'] ?? 0)]);
        $riga = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $esito = (string)($req->query['esito'] ?? '');

        $view = View::default();
        return Response::html($view->render('layout/shell', [
            'title' => 'Il mio account — Pantedu',
            'body'  => $view->render('profile/account', [
                'csrf'        => Csrf::token(),
                'user'        => $user,
                'email'       => (string)($riga['email'] ?? ''),
                'dueFattori'  => (bool)($riga['totp_enabled'] ?? false),
                'metodo'      => $riga['two_factor_method'] ?? null,
                'obbligatori' => (new TwoFactorPolicy())->requiredForRole((string)($user['role'] ?? '')),
                'messaggio'   => self::MESSAGGI[$esito] ?? ($esito === 'confermata' ? ['ok', 'Email cambiata.'] : null),
                'tornaA'      => \in_array((string)($user['role'] ?? ''), ['administrator', 'institute_admin'], true) ? '/admin' : '/area-docente/profilo',
            ]),
            'modal' => false,
        ]));
    }

    public function email(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::redirect('/login');
        }
        $utente = (int)(Auth::user()['id'] ?? 0);
        $esito = $this->cambio()->richiedi(
            $utente,
            (string)($req->post['password'] ?? ''),
            (string)($req->post['nuova_email'] ?? ''),
            \App\Services\Waf\EdgeContext::clientIp($req->server ?? [])
        );
        ActivityLogger::event(
            'email_change_requested',
            subjectType: 'user',
            subjectId:   (string)$utente,
            // Il nuovo indirizzo non va nel registro: basta il dominio.
            details:     ['esito' => $esito, 'dominio' => self::dominio((string)($req->post['nuova_email'] ?? ''))],
            outcome:     $esito === CambioEmail::RICHIESTA_INVIATA ? 'ok' : 'denied',
        );
        return Response::redirect('/me/account?esito=' . rawurlencode($esito) . '#email');
    }

    public function confermaPagina(Request $req): Response
    {
        $token = (string)($req->query['token'] ?? '');
        // Il gettone è nell'indirizzo della pagina: non va come Referer al
        // pulsante, ai fogli di stile, agli script (24/9/2026, PaginaConGettone).
        return PaginaConGettone::html([
            'title' => 'Conferma il nuovo indirizzo — Pantedu',
            'body'  => View::default()->render('profile/email_conferma', [
                'csrf'  => Csrf::token(),
                'token' => $token,
                'nuova' => $this->cambio()->nuovaDelToken($token),
                'fatto' => false,
            ]),
            'modal' => true,
        ]);
    }

    public function conferma(Request $req): Response
    {
        $token = (string)($req->post['token'] ?? '');
        $esito = $this->cambio()->conferma($token);
        if ($esito !== null) {
            ActivityLogger::event(
                'email_changed',
                subjectType: 'user',
                subjectId:   (string)$esito['utente'],
                details:     ['da_dominio' => self::dominio($esito['vecchia']), 'a_dominio' => self::dominio($esito['nuova'])],
            );
            Csrf::rotate();
        }
        $view = View::default();
        return Response::html($view->render('layout/shell', [
            'title' => 'Conferma il nuovo indirizzo — Pantedu',
            'body'  => $view->render('profile/email_conferma', [
                'csrf'  => Csrf::token(),
                'token' => '',
                'nuova' => $esito['nuova'] ?? null,
                'fatto' => $esito !== null,
            ]),
            'modal' => true,
        ]));
    }

    private static function dominio(string $email): string
    {
        $at = strrpos($email, '@');
        return $at !== false ? strtolower(substr($email, $at + 1)) : '';
    }
}
