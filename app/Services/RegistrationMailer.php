<?php

namespace App\Services;

use App\Support\IndirizzoPubblico;

/**
 * Wrapper su Mailer per gli eventi del registration flow.
 * Template email in italiano, plain text.
 */
final class RegistrationMailer
{
    public function __construct(
        private readonly Mailer $mailer,
        /**
         * La radice dei collegamenti. Null (23/9/2026) = `app.url`, chiesta a
         * IndirizzoPubblico al momento dell'invio e non alla costruzione: il
         * controller costruisce il mailer a ogni richiesta, anche solo per
         * mostrare il modulo, e un'anomalia per una pagina vista sarebbe
         * rumore. Fino a quel giorno il controller ripiegava sul dominio di
         * produzione.
         */
        private readonly ?string $siteUrl,
        private readonly string $logFile,
        /**
         * Casella a cui risponde l'iscritto. Il From e' send-only: senza
         * questo, chi riceve "in attesa di approvazione" o "richiesta non
         * approvata" e chiede spiegazioni scrive a noreply@. E' proprio nel
         * rifiuto che una risposta serve, perche' li' l'utente non ha altro
         * canale evidente.
         *
         * 23/9/2026 — la passa il controller (`mail.contact_email`); vuota,
         * il Reply-To resta il mittente. Il predefinito era la casella di
         * produzione.
         */
        private readonly string $replyTo = '',
    ) {
    }

    /**
     * I collegamenti di queste email sono di cortesia: il messaggio è
     * l'esito (in attesa, approvato con il nome utente, rifiutato), e parte
     * anche senza `app.url` (IndirizzoPubblico::radiceSeConfigurata).
     */
    private function sito(): ?string
    {
        return $this->siteUrl !== null
            ? rtrim($this->siteUrl, '/')
            : IndirizzoPubblico::radiceSeConfigurata('iscrizione');
    }

    private function rispostaA(): ?string
    {
        return $this->replyTo !== '' ? $this->replyTo : null;
    }

    /** Email al nuovo iscritto dopo il submit: "in attesa di approvazione". */
    public function pending(string $to, string $firstName): bool
    {
        $sito    = $this->sito();
        $accesso = $sito !== null
            ? "Puoi accedere a $sito una volta ricevuta la conferma."
            : 'Potrai accedere una volta ricevuta la conferma.';
        $subject = 'Registrazione Pantedu — in attesa di approvazione';
        $body    = <<<TXT
Ciao $firstName,

la tua richiesta di registrazione a Pantedu è stata ricevuta.
Un amministratore la esaminerà al più presto e riceverai una
seconda email quando l'account sarà attivo.

$accesso

— Pantedu
TXT;
        $this->mailer->logSend($to, $subject, $body, $this->logFile);
        return $this->mailer->send($to, $subject, $body, $this->rispostaA());
    }

    /** Email dopo l'approvazione: link di accesso. */
    public function approved(string $to, string $firstName, string $username): bool
    {
        $sito     = $this->sito();
        $accesso  = $sito !== null
            ? "Accedi qui: $sito/login"
            : 'Accedi dalla pagina di accesso di Pantedu.';
        $subject  = 'Pantedu — account approvato';
        $body     = <<<TXT
Ciao $firstName,

l'amministratore ha approvato la tua registrazione. Il tuo
nome utente è: $username

$accesso

— Pantedu
TXT;
        $this->mailer->logSend($to, $subject, $body, $this->logFile);
        return $this->mailer->send($to, $subject, $body, $this->rispostaA());
    }

    /**
     * Email a un indirizzo che ha già un account, quando qualcuno ci prova a
     * iscriversi (24/9/2026).
     *
     * Il modulo risponde «domanda ricevuta» sia a una domanda nuova sia a
     * un'email già usata: una risposta diversa direbbe a chiunque quali
     * indirizzi hanno un account. La differenza la sa solo chi legge la
     * casella, e qui trova che cosa fare. Nessun nome: quello del modulo lo
     * ha scritto chi ha provato, che può non essere il titolare.
     */
    public function giaRegistrato(string $to): bool
    {
        $sito     = $this->sito();
        $recupero = $sito !== null
            ? "puoi sceglierne una nuova da qui: $sito/password/forgot"
            : 'puoi sceglierne una nuova dalla pagina di accesso, con «Password dimenticata?».';
        $subject  = 'Pantedu — questo indirizzo ha già un account';
        $body     = <<<TXT
Ciao,

qualcuno ha chiesto di iscriversi a Pantedu con questo indirizzo
email, che ha già un account. Non è stata aperta nessuna nuova
domanda, e il tuo account non è cambiato.

Se sei stato tu e non ricordi la password, $recupero

Se non sei stato tu, puoi ignorare questa email.

— Pantedu
TXT;
        $this->mailer->logSend($to, $subject, $body, $this->logFile);
        return $this->mailer->send($to, $subject, $body, $this->rispostaA());
    }

    /**
     * Email a un indirizzo che ha già una domanda in attesa, quando ne arriva
     * un'altra (24/9/2026): stessa ragione di {@see self::giaRegistrato()}.
     */
    public function giaInAttesa(string $to): bool
    {
        $giorni  = \App\Services\Gdpr\ConservazioneDelleIscrizioni::giorniConfigurati();
        $subject = 'Pantedu — c\'è già una domanda in attesa';
        $body    = <<<TXT
Ciao,

qualcuno ha chiesto di iscriversi a Pantedu con questo indirizzo
email, per il quale c'è già una domanda di iscrizione in attesa.
Non ne è stata aperta un'altra: vale quella di prima, e riceverai
un'email quando un amministratore l'avrà esaminata.

Se non sei stato tu, puoi ignorare questa email: una domanda non
approvata si cancella da sola dopo $giorni giorni.

— Pantedu
TXT;
        $this->mailer->logSend($to, $subject, $body, $this->logFile);
        return $this->mailer->send($to, $subject, $body, $this->rispostaA());
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

Per chiarimenti puoi rispondere a questa email.

— Pantedu
TXT;
        $this->mailer->logSend($to, $subject, $body, $this->logFile);
        return $this->mailer->send($to, $subject, $body, $this->rispostaA());
    }
}
