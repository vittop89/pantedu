<?php

/**
 * Phase 14 — retention policy GDPR (art. 5 §1 e).
 *
 * Tutti i valori sono in giorni. Attenzione: cambiare retention dopo
 * rollout richiede aggiornamento informativa privacy.
 */

return [
    // 2 anni senza accessi: l'account si cancella (routine dell'art. 17), dopo
    // tre avvisi via email a 60, 30 e 7 giorni (AvvisiDiInattivita, 24/9/2026).
    'inactive_account_days'   => 730,
    // Domande di iscrizione mai approvate né respinte, in registrations.json:
    // le cancella ConservazioneDelleIscrizioni, ogni notte e a ogni scrittura
    // del file (fino al 24/9/2026 il lavoro cancellava da una tabella vuota).
    'pending_registration_days' => 30,
    'privileged_log_days'     => 1825,  // 5 anni: audit accessi privilegiati

    // 2026-09-22 — tolte `access_log_days` (365), `backup_db_days` (90) e
    // `backup_files_days` (30): misurato, NESSUN file le leggeva.
    //
    // Non erano inerti. L'informativa §5 dichiarava 365 giorni per il registro
    // di navigazione e indicava questo file come prova, mentre il contenimento
    // vero e' un troncamento alle ultime mille voci
    // (`app/Config/audit.php`, `access_log_max_entries`), che in produzione
    // coprono circa una settimana. Un numero dichiarato che nessuno applica e'
    // la forma di guasto che questo progetto insegue, e qui stava dentro il
    // documento che serve a dimostrare.
    //
    // Per i backup il termine vero e' `RETENTION_LOCAL_DAYS` nello script di
    // copia, non qui.

    // Flag kill-switch: retention_enabled=false → job CLI stampa soltanto
    // (dry-run). Utile in pre-rollout.
    // 2026-09-23 — anche dall'ambiente del processo: l'unità systemd la dà con
    // `Environment=`, che la PHP da riga di comando dell'host (GPCS) non copia
    // in $_ENV (registro del debito, voce 194).
    'retention_enabled' => \App\Core\Config::booleanoDallAmbiente('GDPR_RETENTION_ENABLED', false, true),
];
