<?php

/**
 * Phase 14 — retention policy GDPR (art. 5 §1 e).
 *
 * Tutti i valori sono in giorni. Attenzione: cambiare retention dopo
 * rollout richiede aggiornamento informativa privacy.
 */

return [
    'inactive_account_days'   => 730,   // 2 anni: anonimizza account mai loggato
    'pending_registration_days' => 30,  // registrazioni pending mai approvate
    'access_log_days'         => 365,   // access_log.json storico
    'privileged_log_days'     => 1825,  // 5 anni: audit accessi privilegiati
    'backup_db_days'          => 90,
    'backup_files_days'       => 30,

    // Flag kill-switch: retention_enabled=false → job CLI stampa soltanto
    // (dry-run). Utile in pre-rollout.
    'retention_enabled' => filter_var($_ENV['GDPR_RETENTION_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
];
