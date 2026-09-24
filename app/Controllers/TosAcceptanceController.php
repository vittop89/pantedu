<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Gdpr\TosAcceptanceService;

/**
 * Phase 25.P — click-acceptance ToS+AUP.
 *
 * Routes (routes/web.php):
 *   GET  /tos-acceptance   → form
 *   POST /tos-acceptance   → submit (auth + csrf)
 *
 * Il form serve due casi:
 *   - gate (versione già efficace, utente non in regola): TOS_ENFORCE=true
 *     lo redirige qui e non passa oltre finché non accetta;
 *   - accettazione anticipata durante il preavviso: raggiungibile dal banner,
 *     nessun blocco.
 */
final class TosAcceptanceController
{
    /**
     * Le caselle sono impegni distinti: la validazione `required` lato HTML
     * è aggirabile con un POST diretto, quindi il controllo che valga come
     * consenso informato deve stare qui.
     */
    private const REQUIRED_CHECKBOXES = [
        'read_tos',
        'read_aup',
        'accept_responsibility',
        'accept_safe_harbor',
        'accept_takedown',
    ];

    private TosAcceptanceService $service;

    public function __construct(?TosAcceptanceService $service = null)
    {
        $this->service = $service ?? new TosAcceptanceService();
    }

    /** GET /tos-acceptance — mostra form. */
    public function show(Request $req): Response
    {
        if (! Auth::check()) {
            return Response::redirect('/login');
        }
        $userId = (int) (Auth::user()['id'] ?? 0);

        $pending = array_values(array_filter(
            $this->service->pendingVersions(),
            static fn(array $v) => $v['is_substantial']
        ));
        $target = $this->service->targetVersions();

        // Già in regola e niente in arrivo da anticipare: non c'è nulla da fare qui.
        if ($this->service->hasAccepted($userId) && $pending === []) {
            return Response::redirect('/');
        }

        return $this->renderForm(
            $req,
            $target,
            $pending,
            $this->safeRedirect($req->input('redirect', '/')),
            is_string($req->input('error')) ? (string) $req->input('error') : null
        );
    }

    /** POST /tos-acceptance — submit accettazione. */
    public function submit(Request $req): Response
    {
        if (! Auth::check()) {
            return Response::redirect('/login');
        }
        $userId = (int) (Auth::user()['id'] ?? 0);
        $redirect = $this->safeRedirect($req->input('redirect', '/'));

        foreach (self::REQUIRED_CHECKBOXES as $box) {
            if (empty($req->post[$box])) {
                return $this->renderForm(
                    $req,
                    $this->service->targetVersions(),
                    array_values(array_filter(
                        $this->service->pendingVersions(),
                        static fn(array $v) => $v['is_substantial']
                    )),
                    $redirect,
                    'all_required',
                    422
                );
            }
        }

        $ip = $req->server['REMOTE_ADDR'] ?? '';
        $ua = $req->server['HTTP_USER_AGENT'] ?? null;
        $this->service->recordAcceptance($userId, (string) $ip, is_string($ua) ? $ua : null);

        return Response::redirect($redirect);
    }

    /**
     * Un redirect che inizia con `/` non basta: `//evil.tld` è un URL
     * protocol-relative e porta fuori dal sito.
     */
    private function safeRedirect(mixed $raw): string
    {
        if (! is_string($raw) || $raw === '') {
            return '/';
        }
        if (! str_starts_with($raw, '/') || str_starts_with($raw, '//')) {
            return '/';
        }
        if (str_starts_with($raw, '/tos-acceptance')) {
            return '/';
        }
        return $raw;
    }

    /**
     * @param array{tos: string, aup: string} $target
     * @param list<array<string,mixed>> $pending
     */
    private function renderForm(
        Request $req,
        array $target,
        array $pending,
        string $redirect,
        ?string $error = null,
        int $status = 200,
    ): Response {
        $isEarly = $pending !== [];
        $view = View::default();
        $body = $view->render('legal/tos_acceptance', [
            'tosVersion'    => $target['tos'],
            'aupVersion'    => $target['aup'],
            'isEarly'       => $isEarly,
            'effectiveFrom' => $isEarly ? $pending[0]['effective_from'] : null,
            'pending'       => $pending,
            'csrf'          => Csrf::token(),
            'redirect'      => $redirect,
            'error'         => $error,
        ]);

        return Response::html($view->render('layout/shell', [
            'title' => 'Accettazione Termini di Servizio — pantedu',
            'body'  => $body,
        ]), $status);
    }
}
