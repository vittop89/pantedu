<?php

namespace App\Services;

/**
 * Wrapper su Mailer per gli eventi del registration flow.
 * Template email in italiano, plain text.
 */
final class RegistrationMailer
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly string $siteUrl,
        private readonly string $logFile,
    ) {
    }

    /** Email al nuovo iscritto dopo il submit: "in attesa di approvazione". */
    public function pending(string $to, string $firstName): bool
    {
        $subject = 'Registrazione Pantedu — in attesa di approvazione';
        $body    = <<<TXT
Ciao $firstName,

la tua richiesta di registrazione a Pantedu è stata ricevuta.
Un amministratore la esaminerà al più presto e riceverai una
seconda email quando l'account sarà attivo.

Puoi accedere a {$this->siteUrl} una volta ricevuta la conferma.

— Pantedu
TXT;
        $this->mailer->logSend($to, $subject, $body, $this->logFile);
        return $this->mailer->send($to, $subject, $body);
    }

    /** Email dopo l'approvazione: link di accesso. */
    public function approved(string $to, string $firstName, string $username): bool
    {
        $loginUrl = rtrim($this->siteUrl, '/') . '/login';
        $subject  = 'Pantedu — account approvato';
        $body     = <<<TXT
Ciao $firstName,

l'amministratore ha approvato la tua registrazione. Il tuo
nome utente è: $username

Accedi qui: $loginUrl

— Pantedu
TXT;
        $this->mailer->logSend($to, $subject, $body, $this->logFile);
        return $this->mailer->send($to, $subject, $body);
    }

    /** Email dopo il rifiuto (opzionale con motivo). */
    public function rejected(string $to, string $firstName, string $reason = ''): bool
    {
        $subject = 'Pantedu — registrazione rifiutata';
        $note    = $reason !== '' ? "\n\nMotivazione: $reason" : '';
        $body    = <<<TXT
Ciao $firstName,

ti informiamo che la tua richiesta di registrazione a Pantedu
non è stata approvata.$note

Per chiarimenti contatta l'amministratore del sito.

— Pantedu
TXT;
        $this->mailer->logSend($to, $subject, $body, $this->logFile);
        return $this->mailer->send($to, $subject, $body);
    }
}
