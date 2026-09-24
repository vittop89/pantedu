<?php

/**
 * Posta in uscita — l'unico punto in cui si leggono APP_MAIL_* e le caselle
 * dell'istanza (CONTACT_EMAIL, DPO_EMAIL, ABUSE_EMAIL, SECURITY_EMAIL).
 *
 * Prima (revisione architetturale 2026-09, intervento P7) dodici file
 * leggevano `$_ENV['APP_MAIL_FROM']` per conto proprio, ognuno con il suo
 * default: qui i valori sono uno, e chi manda posta parte da
 * `Mailer::fromConfig()`.
 *
 *   from            vuoto = questa istanza non manda posta; i flussi che la
 *                   richiedono lo dicono (registrazione, reset password,
 *                   secondo fattore via email, takedown)
 *   from_name       nome mostrato accanto al mittente
 *   reply_to        Reply-To degli avvisi di aggiornamento delle policy, e degli
 *                   avvisi di incarichi tolti quando l'amministratore non ha un
 *                   indirizzo valido
 *   contact_email   casella generale dell'istanza: risposte alle email di
 *                   iscrizione, contatti del modale autore; ed è il ripiego
 *                   delle tre qui sotto
 *   dpo_email       casella del DPO (contatti, consenso dei genitori,
 *                   avvisi di custodia delle chiavi)
 *   abuse_email     casella delle segnalazioni di contenuti (Notice &
 *                   Takedown, RFC 2142): riceve il modulo pubblico, e l'autore
 *                   del contenuto la usa per contestare
 *   security_email  casella delle segnalazioni di vulnerabilità (pagina
 *                   /security; `public/.well-known/security.txt` è un file
 *                   statico e va tenuto allineato a mano)
 *   resend_api_key  vuota = fallback su PHP mail() (sviluppo)
 *
 * 23/9/2026 — nessuna casella ha più un valore scritto qui. `dpo_email`
 * ripiegava su quella del DPO di produzione, e altre quattro caselle stavano
 * scritte nei controller e nei servizi: su un'altra istanza, o con la
 * configurazione vuota, le segnalazioni — con nome, email e IP di chi segnala
 * — e le risposte dei docenti arrivavano al titolare sbagliato (revisione del
 * 23/9/2026, rilievo A-13). Adesso il ripiego è la casella generale
 * dell'istanza, e senza nemmeno quella la casella è vuota: chi manda posta lo
 * dice (nessuna notifica, il Reply-To resta il mittente) invece di scrivere
 * a qualcun altro.
 * I valori dell'istanza di produzione stanno nel `.env` versionato.
 */

$contatto = trim((string)($_ENV['CONTACT_EMAIL'] ?? ''));

return [
    'from'           => (string)($_ENV['APP_MAIL_FROM'] ?? ''),
    'from_name'      => (string)($_ENV['APP_MAIL_FROM_NAME'] ?? '') ?: 'Pantedu',
    'reply_to'       => (string)($_ENV['APP_MAIL_REPLY_TO'] ?? ''),
    'contact_email'  => $contatto,
    'dpo_email'      => trim((string)($_ENV['DPO_EMAIL'] ?? '')) ?: $contatto,
    'abuse_email'    => trim((string)($_ENV['ABUSE_EMAIL'] ?? '')) ?: $contatto,
    'security_email' => trim((string)($_ENV['SECURITY_EMAIL'] ?? '')) ?: $contatto,
    'resend_api_key' => (string)($_ENV['RESEND_API_KEY'] ?? ''),
];
