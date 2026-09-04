<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Phase 25.C8 — Wrapper Mailer per workflow parent consent (Art. 8 GDPR).
 *
 * Email template plain text in italiano. Fail-safe: log su file anche
 * quando il send mail() fallisce, così admin può recuperare il token
 * via DB / log se SMTP è giù.
 */
final class ParentConsentMailer
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly string $siteUrl,
        private readonly string $logFile,
        /**
         * Casella a cui risponde il genitore. Il From è send-only: senza
         * questo, la risposta di chi chiede "che cos'è questa email sui dati
         * di mio figlio?" finisce in noreply@. Art. 8 GDPR fa esercitare al
         * genitore una scelta sul consenso, e chi deve scegliere deve poter
         * chiedere.
         */
        private readonly string $replyTo = 'operatore@example.net',
    ) {
    }

    /**
     * Email al genitore con link di conferma consenso.
     * Token TTL 30g (vedi ParentConsentService::TOKEN_EXPIRY_DAYS).
     */
    public function requestConsent(
        string $parentEmail,
        string $token,
        string $studentFirstName,
        ?string $parentName = null
    ): bool {
        $confirmUrl = rtrim($this->siteUrl, '/') . '/parent-consent/' . $token;
        $greet = $parentName ? "Gentile $parentName," : 'Gentile genitore,';
        $subject = 'Pantedu — consenso parentale richiesto per ' . $studentFirstName;
        $body = <<<TXT
$greet

Suo/a figlio/a $studentFirstName ha richiesto la registrazione
sulla piattaforma didattica Pantedu.

Poiché si tratta di un minore di 14 anni, ai sensi dell'Art. 8 GDPR
e del D.Lgs. 101/2018, è necessario il Suo consenso parentale per
attivare l'account.

Clicchi sul seguente link per confermare il consenso:

$confirmUrl

Il link è valido per 30 giorni. Se non desidera dare il consenso,
ignori questa email — l'account non verrà attivato.

Per maggiori informazioni sulla protezione dei dati dei minori e per
esercitare i diritti previsti dal GDPR (revoca consenso, accesso,
cancellazione), può contattare il DPO: {$this->siteUrl}/dpo-contact

— Pantedu
TXT;
        // Log SEMPRE (audit + recovery se SMTP fail)
        $this->mailer->logSend($parentEmail, $subject, $body, $this->logFile);
        try {
            return $this->mailer->send($parentEmail, $subject, $body, $this->replyTo);
        } catch (\Throwable $e) {
            error_log("[parent_consent_mailer] send failed for $parentEmail: " . $e->getMessage());
            return false;
        }
    }
}
