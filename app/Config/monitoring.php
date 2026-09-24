<?php

/**
 * Phase 14 — monitoring/backup thresholds.
 */

return [
    'quota_thresholds' => [
        'warn'     => 70,   // %
        'high'     => 85,
        'critical' => 95,
    ],
    'backup' => [
        // Phase S2 Fase 2 — PANTEDU_DATA_PATH-aware (dati fuori repo).
        'db_dir'     => \App\Core\Config::cartellaDati() . '/storage/backups/db',
        'files_dir'  => \App\Core\Config::cartellaDati() . '/storage/backups/files',
        'max_age_hr' => 48, // oltre 48h → alert stale
    ],
];
