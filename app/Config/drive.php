<?php

/**
 * Phase G1.a — Google Drive OAuth client configuration.
 *
 * Le credenziali (client_id / client_secret) si configurano in .env.local
 * (NON in .env, che e' versionato). Vedi .env.example per i nomi delle
 * variabili e wiki/decisions/ADR-009-drive-integration.md per la procedura
 * di setup OAuth in Google Cloud Console.
 *
 * Scope:
 *   - drive.file: default per operativita' normale (read+write SOLO file
 *     creati dall'app). Privacy-safe: l'app NON vede il resto del Drive.
 *   - drive.readonly: scope UNA TANTUM richiesto in fase G6 (migrazione
 *     legacy mappe gia' caricate su Drive dal docente). Post-migrazione
 *     l'app declassa a drive.file via re-consent.
 */

declare(strict_types=1);

return [
    'oauth' => [
        'client_id'     => $_ENV['GOOGLE_DRIVE_CLIENT_ID']     ?? '',
        'client_secret' => $_ENV['GOOGLE_DRIVE_CLIENT_SECRET'] ?? '',
        // Path callback registrato in Google Cloud Console.
        // Se vuoto, viene calcolato runtime da APP_URL + '/teacher/drive/callback'.
        'redirect_uri'  => $_ENV['GOOGLE_DRIVE_REDIRECT_URI']  ?? '',
    ],

    'scopes' => [
        // Default per operativita' normale. openid+email sono richiesti
        // per popolare email del Google account collegato (display nella
        // status pill). Nessun PII extra rispetto all'email gia' nota
        // (l'OAuth callback la riceve comunque dal claim id_token).
        'default' => [
            'openid',
            'email',
            'https://www.googleapis.com/auth/drive.file',
        ],
        // Migration scope (G6, una tantum).
        'migration' => [
            'https://www.googleapis.com/auth/drive.readonly',
        ],
    ],

    // Nome cartella root creata in Drive del docente alla prima connessione.
    'root_folder_name' => 'Pantedu',

    // Limiti operativi (anti-abuse + Drive API quota: 1000 req/100s/user).
    'limits' => [
        'sync_per_run_max_files' => 200,
        'sync_per_teacher_timeout_s' => 300, // 5min
    ],

    // Path al CA bundle aggiornato (Windows/XAMPP fix per cURL error 60
    // "unable to get local issuer certificate"). Su Linux/prod lasciare
    // vuoto: Guzzle usa il system CA store.
    'ca_bundle' => $_ENV['DRIVE_CA_BUNDLE'] ?? 'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt',
];
