<?php

/**
 * Phase 25.P — multi-tenancy: enforcement ToS/AUP.
 *
 * Vedi:
 *   - docs/legal/tos_docente.md §8 (modifiche ai Termini)
 *   - docs/legal/aup.md §6 (accettazione)
 *   - docs/todo/multitenancy_responsibility_framework.md §3.1
 */

return [
    // Hard gate: utente che non ha accettato la versione EFFICACE dei
    // documenti viene rediretto a /tos-acceptance prima di ogni rotta
    // autenticata. Off = nessun blocco (il banner di preavviso resta attivo).
    'tos_enforce' => filter_var($_ENV['TOS_ENFORCE'] ?? false, FILTER_VALIDATE_BOOLEAN),

    // Preavviso minimo per le modifiche sostanziali, in giorni. Il valore è
    // un impegno contrattuale preso con l'utente (ToS §8 e AUP §6): abbassarlo
    // sotto 30 richiede prima di aggiornare i documenti legali e ripubblicare
    // i PDF, non solo di cambiare questa riga.
    'legal_notice_days' => (int)($_ENV['LEGAL_NOTICE_DAYS'] ?? 30),

    // Milestone (giorni residui) a cui il job cron invia l'email di preavviso.
    'legal_notice_milestones' => [30, 7, 1],
];
