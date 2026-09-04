<?php

// Phase S2 Fase 2 (2026-05-22) — Decoupling data dir.
// PANTEDU_DATA_PATH=/var/lib/pantedu-data fa puntare tutti i path
// dei dati app FUORI dal repo git. Backward-compat: se vuoto, i path
// restano dentro la base del repo (modello attuale per dev locale).
$_dataBase = $_ENV['PANTEDU_DATA_PATH'] ?? dirname(__DIR__, 2);

return [
    'env'        => $_ENV['APP_ENV']      ?? 'production',
    'debug'      => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url'        => $_ENV['APP_URL']      ?? '',
    'timezone'   => $_ENV['APP_TIMEZONE'] ?? 'Europe/Rome',

    // Phase S2 (ADR-017) — Deployment mode: 'single' (S1) | 'institute' (S2).
    'deployment_mode'        => in_array(($_ENV['DEPLOYMENT_MODE'] ?? 'single'), ['single', 'institute'], true)
        ? ($_ENV['DEPLOYMENT_MODE'] ?? 'single')
        : 'single',
    // ADR-032 (2026-09-03) — Scenario di esercizio: 'personal' (1) |
    // 'colleagues' (2) | 'institute' (3). Vuoto = dedotto dal modo legacy.
    // Override runtime da /admin/system/deployment (deployment_scenario.json).
    'deployment_scenario'    => in_array(($_ENV['DEPLOYMENT_SCENARIO'] ?? ''), ['personal', 'colleagues', 'institute'], true)
        ? $_ENV['DEPLOYMENT_SCENARIO']
        : '',
    // Lo scenario 3 e' attivabile solo se chi conduce l'istanza dichiara che
    // sta su infrastruttura qualificata ACN (Regolamento cloud ACN, Decreto
    // direttoriale n. 21007/24). Non e' una scelta da pannello: e' un fatto
    // sull'infrastruttura, e si dichiara qui.
    'instance_acn_qualified' => filter_var($_ENV['INSTANCE_ACN_QUALIFIED'] ?? false, FILTER_VALIDATE_BOOLEAN),
    // 2026-09-04 — modelli istituzionali risdoc (piano annuale, relazione
    // finale, scheda progetto) nel catalogo dei docenti. false = nascosti a
    // tutti gli account dell'istanza, autore compreso (ToS §2.5). Letto da
    // TemplateResolver::institutionalTemplatesEnabled().
    'risdoc_institutional_templates' => filter_var(
        $_ENV['RISDOC_INSTITUTIONAL_TEMPLATES'] ?? (getenv('RISDOC_INSTITUTIONAL_TEMPLATES') !== false ? getenv('RISDOC_INSTITUTIONAL_TEMPLATES') : true),
        FILTER_VALIDATE_BOOLEAN
    ),
    'institute_owner_email'  => $_ENV['INSTITUTE_OWNER_EMAIL'] ?? '',
    'institute_legal_name'   => $_ENV['INSTITUTE_LEGAL_NAME']  ?? '',
    // Nome dell'operatore/gestore dell'istanza (data controller in modo
    // single S1). Se vuoto, le trust page usano un'etichetta generica.
    'instance_operator_name' => $_ENV['INSTANCE_OPERATOR_NAME'] ?? '',

    'paths' => [
        // Code (sempre dal repo)
        'base'    => dirname(__DIR__, 2),
        'app'     => dirname(__DIR__),
        'public'  => dirname(__DIR__, 2) . '/public',
        'routes'  => dirname(__DIR__, 2) . '/routes',
        'views'   => dirname(__DIR__, 2) . '/views',
        // Phase S2 Fase 2 fix — `legacy` = entry-point PHP root (index.php
        // legacy, cron handlers, log serve, partials). E' CODICE non dati,
        // quindi deve restare nel repo. Non confondere con data_base.
        'legacy'  => dirname(__DIR__, 2),
        // Data (configurable via PANTEDU_DATA_PATH)
        'data_base' => $_dataBase,
        'storage' => $_dataBase . '/storage',
        'logs'    => $_dataBase . '/storage/logs',
    ],
];
