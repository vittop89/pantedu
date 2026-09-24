<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Quello che segue un'approvazione confermata (24/9/2026): il consenso del
 * genitore per un minore, la rotazione della sessione se chi approva è lo
 * stesso utente, l'evento nel registro delle attività.
 *
 * Viene dopo la transazione di RegistrationService::approve() perché manda
 * posta o tocca la sessione, e non si annulla. Tolto da lì per le dimensioni
 * del file (tools/ci/dimensioni.json), senza cambiare il comportamento.
 */
final class PassiDopoLApprovazione
{
    /**
     * @param array<string,mixed> $entry la domanda approvata
     * @param \Closure(): ?Mailer $posta
     */
    public static function esegui(
        int $nuovoId,
        string $idDomanda,
        array $entry,
        string $actor,
        \Closure $posta,
    ): void {
        $isMinor = !empty($entry['is_minor']);

        // Da qui l'account c'è e la domanda no. Ciò che segue manda posta o
        // tocca la sessione: non si annulla, quindi viene dopo la conferma.

        // Phase 25.C2+C7+C8 — Per studenti minori: trigger parent consent
        // workflow. ParentConsentService::request genera token, salva
        // pending_consent row. ParentConsentMailer invia email al
        // genitore con link /parent-consent/{token} (Phase 25.C8).
        //
        // Il gettone esiste in chiaro solo nell'email (23/9/2026, A-67).
        // Prima c'era un «fail-safe»: il gettone andava in error_log,
        // insieme all'indirizzo del genitore, e restava in chiaro nel
        // database, perche' l'amministratore lo recuperasse se l'invio
        // falliva. Ma chi legge un registro o un backup non e' il
        // genitore: con quel gettone poteva dare o rifiutare il consenso
        // dell'art. 8 al posto suo. Adesso nel database c'e' l'hash, nei
        // registri niente. Un gettone perso non si recupera: una nuova
        // ParentConsentService::request() ne genera un altro (oggi nessuna
        // interfaccia la offre: se l'invio fallisce, la richiesta resta
        // ferma fino alla scadenza).
        //
        // Dopo la conferma (24/9/2026): un'email al genitore partita per un
        // account poi annullato porterebbe un gettone che non vale niente.
        if ($isMinor && !empty($entry['parent_email'])) {
            $studentId = $nuovoId;
            if ($studentId > 0) {
                try {
                    $parentSvc = new \App\Services\Gdpr\ParentConsentService();
                    $parentName = !empty($entry['parent_name']) ? (string)$entry['parent_name'] : null;
                    $token = $parentSvc->request(
                        $studentId,
                        (string)$entry['parent_email'],
                        $parentName
                    );

                    // Phase 25.C8 — invio email al genitore (mittente da
                    // app/Config/mail.php; vuoto = nessun invio)
                    $baseMailer = ($posta)();
                    if ($baseMailer !== null) {
                        // 23/9/2026 — il collegamento di conferma è il
                        // messaggio: senza `app.url` non parte, e
                        // IndirizzoPubblico lo registra (il catch qui sotto
                        // lo scrive in error_log). Il ripiego era il
                        // dominio di produzione.
                        $siteUrl = \App\Support\IndirizzoPubblico::radice('consenso_genitori');
                        // paths.storage e non __DIR__: in produzione lo storage
                        // e' disaccoppiato dal checkout. Dal 23/9/2026 questo
                        // registro dice a chi e quando e' partita l'email, ma
                        // non contiene piu' il gettone (ParentConsentMailer):
                        // non e' piu' il posto da cui recuperarlo.
                        $logFile = (string)Config::get('app.paths.storage') . '/logs/mail_audit.log';
                        $mailer = new \App\Services\ParentConsentMailer(
                            $baseMailer,
                            $siteUrl,
                            $logFile,
                            (string)Config::get('mail.dpo_email', '')
                        );
                        $mailer->requestConsent(
                            (string)$entry['parent_email'],
                            $token,
                            (string)$entry['first_name'],
                            $parentName
                        );
                    }
                } catch (\Throwable $e) {
                    error_log("[parent_consent] failed for student_id=$studentId: " . $e->getMessage());
                }
            }
        }

        // Phase 19 — session rotation: se l'utente approvato e' lo stesso
        // loggato correntemente (caso raro, self-approve), rigenera session ID
        // + refresh claims per prevenire session fixation.
        if (
            session_status() === PHP_SESSION_ACTIVE
            && ($_SESSION['username'] ?? '') === $entry['username']
        ) {
            \App\Core\Session::regenerate();
            \App\Core\Auth::refreshCurrentUserClaims();
        }

        \App\Services\Audit\ActivityLogger::event(
            'registration_approved',
            subjectType: 'registration',
            subjectId:   $idDomanda,
            details:     [
                'username'    => $entry['username'],
                'role'        => $entry['role'],
                'approved_by' => $actor,
                'is_minor'    => !empty($entry['is_minor']),
                // Per un minore l'approvazione non attiva ancora nulla:
                // l'account resta fermo finche' il genitore non conferma.
                'account_active_now' => empty($entry['is_minor']),
            ],
        );
    }
}
