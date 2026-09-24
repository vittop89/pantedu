<?php

declare(strict_types=1);

/**
 * Le tabelle che tools/audit/purge_old_logs.php svuota delle righe più vecchie,
 * con i giorni di conservazione e la colonna della data.
 *
 * Una conservazione dichiarata (registro art. 30, informativa §5) che nessun job
 * applica è una dichiarazione falsa: TabelleDaPurgareTest controlla che ogni
 * tabella e colonna esista davvero.
 *
 * @return array<string, array{days:int, ts_col:string}>
 */
return [
    // 2026-09-04 — cinque anni come gli accessi privilegiati, non sette:
    // i sette non avevano una ragione scritta, e un registro senza ragione
    // e' esattamente cio' che l'art. 5(1)(e) vieta. Cinque e' il termine di
    // prescrizione degli abusi che questi registri servono a ricostruire.
    'content_action_log'    => ['days' => 1825, 'ts_col' => 'occurred_at'],
    // 1825 giorni e non dieci anni: i documenti consegnati promettono cinque
    // anni, e la conservazione piu' lunga la sapeva solo questo file.
    'privileged_access_log' => ['days' => 1825, 'ts_col' => 'created_at'],
    'crypto_access_log'     => ['days' => 1825, 'ts_col' => 'accessed_at'],
    // 30 giorni (2026-09-04, erano 90): e' l'unico registro con l'IP in
    // chiaro che tocca anche le sessioni degli studenti con credenziale di
    // classe; un mese basta a bloccare gli abusi ricorrenti.
    'waf_logs'              => ['days' => 30,       'ts_col' => 'ts'],
    // 2026-09-02 — le due tabelle che la descrizione qui sopra elencava ma
    // che il codice non toccava. Senza, sarebbero cresciute per sempre.
    //
    // audit_activity_log: due anni. Dentro ci sono anche le operazioni degli
    // studenti, minori compresi; le azioni che meritano dieci anni hanno gia'
    // le loro tabelle (privileged_access_log, crypto_access_log). Due anni
    // coprono l'anno scolastico in corso e il precedente, che e' l'orizzonte
    // entro cui a una scuola viene chiesto conto di qualcosa.
    'audit_activity_log'     => ['days' => 2 * 365, 'ts_col' => 'occurred_at'],
    'teacher_recovery_audit' => ['days' => 1825, 'ts_col' => 'created_at'],
    // 2026-09-04 — era dichiarato "permanente, mai cancellato": una
    // conservazione senza termine non e' ammessa dall'art. 5(1)(e). Dieci
    // anni dall'evento, la prescrizione ordinaria (art. 2946 c.c.): oltre,
    // nessuno puo' piu' contestare un consenso dato o revocato.
    'consent_audit'          => ['days' => 3650, 'ts_col' => 'accessed_at'],
    // 2026-09-15 — il registro (B.6-bis, B.6-ter) diceva «purga insieme ai log
    // d'accesso», ma queste due tabelle qui non c'erano: i link di recupero
    // password e i codici del secondo fattore restavano per sempre. Un anno,
    // come i log d'accesso; con loro i link del cambio email (migrazione 131).
    'password_resets'        => ['days' => 365, 'ts_col' => 'created_at'],
    'two_factor_email_codes' => ['days' => 365, 'ts_col' => 'created_at'],
    'email_change_requests'  => ['days' => 365, 'ts_col' => 'created_at'],
    // 2026-09-24 — le tabelle che la rilettura dei documenti legali ha trovato
    // senza un termine applicato da nessuno. Ognuna con il suo perché.
    //
    // Tentativi di accesso falliti (IP e nome utente in chiaro): si toglievano
    // solo al tentativo successivo o al login riuscito. Un giorno basta al
    // filtro, che li conta nell'ora.
    'waf_login_failures'          => ['days' => 1, 'ts_col' => 'created_at'],
    // Richieste al recapito privacy: un anno, come dichiara l'informativa.
    'dpo_requests'                => ['days' => 365, 'ts_col' => 'created_at'],
    // Segnalazioni di rimozione: cinque anni, come dichiara la procedura; dal
    // 24/9/2026 senza l'IP in chiaro (migrazione 141).
    'takedown_requests'           => ['days' => 1825, 'ts_col' => 'submitted_at'],
    // Consensi, consensi dei genitori, verbale dei Termini e avvisi delle
    // versioni legali: dieci anni, la prescrizione ordinaria (art. 2946 c.c.),
    // come consent_audit. Sono la prova di un consenso o di un'accettazione.
    'consents'                    => ['days' => 3650, 'ts_col' => 'granted_at'],
    'parent_consents'             => ['days' => 3650, 'ts_col' => 'requested_at'],
    'user_tos_acceptance'         => ['days' => 3650, 'ts_col' => 'accepted_at'],
    'legal_version_notifications' => ['days' => 3650, 'ts_col' => 'sent_at'],
    // Richieste di cancellazione: cinque anni, la prova che un diritto è
    // stato esercitato e rispettato.
    'deletion_requests'           => ['days' => 1825, 'ts_col' => 'requested_at'],
    // Avvisi di inattività partiti (migrazione 142): cinque anni, come dichiara
    // l'informativa. Sono la prova che l'avviso è partito prima della
    // cancellazione; restano anche dopo, puntando al segnaposto dell'account.
    'avvisi_di_inattivita'        => ['days' => 1825, 'ts_col' => 'inviato_at'],
    // Senza termine, di proposito: crypto_custody_events, il registro della
    // custodia della chiave master (chi l'ha generata, copiata, verificata,
    // distrutta). Non contiene dati di altri che il titolare.
];
