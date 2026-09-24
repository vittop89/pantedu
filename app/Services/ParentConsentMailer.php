<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Phase 25.C8 — Wrapper Mailer per workflow parent consent (Art. 8 GDPR).
 *
 * Email template plain text in italiano. Ogni invio lascia una riga nel
 * registro della posta, anche quando il send fallisce: a chi, quando, con
 * quale testo — ma senza il gettone (23/9/2026, A-67).
 *
 * Fino a quel giorno il registro copiava il collegamento intero, ed era il
 * «fail-safe» da cui l'amministratore recuperava il gettone se la posta non
 * partiva. Un gettone leggibile in un registro, pero', lo legge chiunque
 * abbia il registro o un suo backup, e con quello si da' o si rifiuta il
 * consenso dell'art. 8 al posto del genitore. Il gettone in chiaro sta solo
 * nell'email; nel database c'e' il suo hash (ParentConsentService).
 */
final class ParentConsentMailer
{
    // Proprietà classiche e non promosse con `readonly`: l'analizzatore PHP
    // di semgrep non legge `readonly` nei parametri del costruttore, e il
    // file finiva fra quelli letti solo in parte, dove le regole non girano
    // (tools/ci/cancello-semgrep.mjs, 23/9/2026).
    private Mailer $mailer;
    private string $siteUrl;
    private string $logFile;
    /**
     * Casella a cui risponde il genitore. Il From è send-only: senza
     * questo, la risposta di chi chiede "che cos'è questa email sui dati
     * di mio figlio?" finisce in noreply@. Art. 8 GDPR fa esercitare al
     * genitore una scelta sul consenso, e chi deve scegliere deve poter
     * chiedere.
     *
     * 23/9/2026 — la passa chi costruisce (`mail.dpo_email`); vuota, il
     * Reply-To resta il mittente. Fino a quel giorno il predefinito era la
     * casella del DPO di produzione, anche per un'altra istanza.
     */
    private string $replyTo;

    public function __construct(
        Mailer $mailer,
        string $siteUrl,
        string $logFile,
        string $replyTo = '',
    ) {
        $this->mailer = $mailer;
        $this->siteUrl = $siteUrl;
        $this->logFile = $logFile;
        $this->replyTo = $replyTo;
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
        // Registro SEMPRE, anche se l'invio fallira': ma il testo che si
        // registra ha il gettone coperto. Nell'email parte quello vero.
        $bodyPerRegistro = $token === '' ? $body : str_replace($token, '[gettone omesso]', $body);
        $this->mailer->logSend($parentEmail, $subject, $bodyPerRegistro, $this->logFile);
        try {
            return $this->mailer->send($parentEmail, $subject, $body, $this->replyTo !== '' ? $this->replyTo : null);
        } catch (\Throwable $e) {
            // Senza l'indirizzo: nel registro della posta c'e' gia', con l'ora.
            error_log('[parent_consent_mailer] invio non riuscito: ' . $e->getMessage());
            return false;
        }
    }
}
