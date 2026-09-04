<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Gdpr\TakedownRequestService;
use App\Services\Mailer;
use InvalidArgumentException;

/**
 * Phase 25.P — UI admin per gestione coda Notice & Takedown.
 *
 * Routes:
 *   GET  /admin/takedown               → lista coda
 *   GET  /admin/takedown/{id}          → dettaglio
 *   POST /admin/takedown/{id}/action   → applica azione (rimuovi/sospendi/respingi)
 *
 * Protezione: middleware role:super_admin (solo Operatore inizialmente).
 *
 * Phase 25.R.3.1 — refactor: render via View+layout/shell.php anziché HTML
 * hardcoded standalone. Layout coerente con altre pagine /admin/* (topbar,
 * breadcrumb, dark theme, role badge).
 *
 * Notifica uploader: l'azione admin invia la mail di Fase 4 e marca
 * `notified_uploader`. Vedi docs/legal/takedown_procedure.md §3 e §5.2.
 */
final class AdminTakedownController
{
    /** Casella presidiata a cui l'uploader risponde per contestare (14gg). */
    private const ABUSE_MAILBOX = 'operatore@example.net';

    /** Motivo per cui l'azione non genera notifica automatica. */
    private const ACTIONS_WITHOUT_NOTICE = ['pending', 'forwarded_authority'];

    private TakedownRequestService $service;
    private ?Mailer $mailer;

    public function __construct(?TakedownRequestService $service = null, ?Mailer $mailer = null)
    {
        $this->service = $service ?? new TakedownRequestService();
        $this->mailer  = $mailer ?? self::defaultMailer();
    }

    /**
     * Mailer con From send-only e Reply-To su abuse@: l'uploader ha 14 giorni
     * per contestare rispondendo, quindi la risposta deve arrivare in una
     * casella letta, non nel noreply.
     */
    private static function defaultMailer(): ?Mailer
    {
        $from = (string)($_ENV['APP_MAIL_FROM'] ?? '');
        if ($from === '') {
            return null;
        }
        $fromName = (string)($_ENV['APP_MAIL_FROM_NAME'] ?? 'Pantedu');
        return new Mailer($from, $fromName);
    }

    /** GET /admin/takedown — lista coda. */
    public function index(): Response
    {
        $statusFilter = $_GET['status'] ?? null;
        $statusFilter = is_string($statusFilter) ? $statusFilter : null;
        $pending = $this->service->listPending($statusFilter);

        $view = View::default();
        $body = $view->render('admin/takedown_index', [
            'pending'      => $pending,
            'statusFilter' => $statusFilter,
            'user'         => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => 'Takedown — Admin',
            'body'  => $body,
        ]));
    }

    /** GET /admin/takedown/{id} — dettaglio. */
    public function show(Request $req, array $params): Response
    {
        $id = (int)($params['id'] ?? 0);
        $request = $this->service->get($id);
        if ($request === null) {
            return Response::html('<h1>404 — Not Found</h1>', 404);
        }

        $view = View::default();
        $body = $view->render('admin/takedown_show', [
            'request' => $request,
            'csrf'    => Csrf::token(),
            'user'    => Auth::user() ?? ['username' => '-', 'role' => 'guest'],
            'id'      => $id,
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => "Takedown #{$id} — Admin",
            'body'  => $body,
        ]));
    }

    /** POST /admin/takedown/{id}/action — applica azione. */
    public function action(Request $req, array $params): Response
    {
        $id     = (int)($params['id'] ?? 0);
        $action = (string) ($req->post['action'] ?? '');
        $notes  = (string) ($req->post['notes']  ?? '');
        $userId = (int) (Auth::user()['id'] ?? 0);

        if (! in_array($action, TakedownRequestService::ACTIONS, true)) {
            return Response::redirect("/admin/takedown/{$id}?error=invalid_action");
        }
        if (trim($notes) === '') {
            return Response::redirect("/admin/takedown/{$id}?error=notes_required");
        }

        try {
            $newStatus = $action === 'dismissed' ? 'rejected' : 'actioned';
            $this->service->updateStatus($id, $newStatus, $action, $notes, $userId);
        } catch (InvalidArgumentException $e) {
            return Response::redirect("/admin/takedown/{$id}?error=" . urlencode($e->getMessage()));
        }

        // Fase 4 — notifica uploader. Best-effort: l'azione sul contenuto è già
        // stata registrata e non va annullata se la mail non parte; la mancata
        // notifica resta visibile perché notified_uploader NON viene marcato.
        $notified = $this->notifyUploader($id, $action, $notes);

        return Response::redirect("/admin/takedown/{$id}?ok=1" . ($notified ? '' : '&notice=uploader_not_notified'));
    }

    /**
     * Invia all'uploader la comunicazione dell'azione intrapresa (template
     * §5.2 della procedura) e marca `notified_uploader` solo a invio riuscito.
     *
     * Ritorna false anche quando la notifica non è dovuta o non è possibile
     * (azione senza notifica, uploader non identificato, mailer non
     * configurato): il chiamante lo usa solo per avvisare l'admin che il passo
     * va fatto a mano via abuse@.
     */
    private function notifyUploader(int $requestId, string $action, string $notes): bool
    {
        if (in_array($action, self::ACTIONS_WITHOUT_NOTICE, true)) {
            return false;
        }
        if ($this->mailer === null) {
            error_log('[AdminTakedownController] APP_MAIL_FROM non configurata: notifica uploader saltata');
            return false;
        }

        try {
            $contact = $this->service->uploaderContact($requestId);
            if ($contact === null) {
                return false;
            }

            $request = $this->service->get($requestId);
            $siteUrl = (string)(Config::get('app.url') ?: 'https://pantedu.eu');

            $sent = $this->mailer->send(
                $contact['email'],
                self::subjectFor($action, $requestId),
                self::bodyFor($action, $requestId, $contact['name'], $request, $notes, $siteUrl),
                self::ABUSE_MAILBOX
            );

            if ($sent) {
                $this->service->markUploaderNotified($requestId);
            }
            return $sent;
        } catch (\Throwable $e) {
            error_log('[AdminTakedownController::notifyUploader] ' . $e->getMessage());
            return false;
        }
    }

    private static function subjectFor(string $action, int $requestId): string
    {
        $what = match ($action) {
            'removed'        => 'Contenuto rimosso a seguito di segnalazione',
            'suspended_user' => 'Account sospeso a seguito di segnalazione',
            default          => 'Esito della segnalazione su un tuo contenuto',
        };
        return "[pantedu abuse-{$requestId}] {$what}";
    }

    /**
     * @param array<string,mixed>|null $request
     */
    private static function bodyFor(
        string $action,
        int $requestId,
        string $name,
        ?array $request,
        string $notes,
        string $siteUrl
    ): string {
        $submitted = (string)($request['submitted_at'] ?? '—');
        $type      = (string)($request['violation_type'] ?? '—');
        $ref       = (string)($request['content_ref'] ?? '—');

        $azione = match ($action) {
            'removed'        => 'abbiamo rimosso il contenuto identificato qui sotto.',
            'suspended_user' => 'abbiamo sospeso il tuo account. Il contenuto segnalato resta '
                              . 'inaccessibile fino alla conclusione della valutazione.',
            'dismissed'      => 'abbiamo valutato la segnalazione infondata: il contenuto resta '
                              . 'online e nessuna azione è stata presa sul tuo account. '
                              . 'Ti scriviamo per trasparenza, non è richiesta alcuna azione da parte tua.',
            default          => 'abbiamo aggiornato lo stato della segnalazione.',
        };

        $body = "Ciao " . ($name !== '' ? $name : 'utente') . ",\n\n"
              . "a seguito di una segnalazione ricevuta il {$submitted} (rif. #{$requestId}, "
              . "tipologia: {$type}), {$azione}\n\n"
              . "Contenuto contestato: {$ref}\n"
              . "Motivazione: " . ($notes !== '' ? $notes : '—') . "\n\n";

        if ($action !== 'dismissed') {
            $body .= "Hai diritto di contestare questa decisione entro 14 giorni rispondendo a "
                   . "questa email (" . self::ABUSE_MAILBOX . "), allegando motivazione e prove a sostegno.\n\n";
        }

        return $body
             . "Procedura completa: {$siteUrl}/legal/takedown-procedure\n"
             . "Termini di servizio e AUP: {$siteUrl}/legal/tos · {$siteUrl}/legal/aup\n\n"
             . "— Pantedu, operatore tecnico\n";
    }
}
