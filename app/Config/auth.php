<?php

// Phase S2 Fase 2 — usa PANTEDU_DATA_PATH se valorizzato (path fuori repo).
$base = $_ENV['PANTEDU_DATA_PATH'] ?? dirname(__DIR__, 2);

return [
    'paths' => [
        'admin_users'         => $base . '/log/data/admin_users.json',
        'collaborators'       => $base . '/log/data/collaborators.json',
        'eser_base'           => $base . '/eser',
        'blocked_credentials' => $base . '/log/data/blocked_credentials.json',
        'blocked_ips'         => $base . '/log/data/blocked_ips.json',
        'registered_users'    => $base . '/storage/data/users.json',
        'registrations'       => $base . '/storage/data/registrations.json',
    ],

    'rate_limit' => [
        'max_attempts'    => (int)($_ENV['LOGIN_MAX_ATTEMPTS']    ?? 5),
        'lockout_seconds' => (int)($_ENV['LOGIN_LOCKOUT_SECONDS'] ?? 300),
    ],

    'session_pattern' => '#(?:eser|lab|map)_([a-z]+)(\d+[sb]?)#',
];
