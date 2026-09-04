<?php

/**
 * Phase 14 — storage provider config.
 *
 * default_provider=local durante fase hosting legacy. Per passare a S3/R2
 * basta cambiare questo valore (nessuna modifica a controller).
 */

return [
    'default_provider' => $_ENV['STORAGE_PROVIDER'] ?? 'local',
    'signing_secret'   => $_ENV['STORAGE_SIGNING_SECRET'] ?? '',

    'local' => [
        // Phase S2 Fase 2 — usa PANTEDU_DATA_PATH se valorizzato (path
        // fuori repo per produzione), altrimenti default sul repo (dev locale).
        'root' => ($_ENV['PANTEDU_DATA_PATH'] ?? dirname(__DIR__, 2)) . '/storage/objects',
    ],

    's3' => [
        'endpoint'   => $_ENV['STORAGE_S3_ENDPOINT']   ?? '',
        'bucket'     => $_ENV['STORAGE_S3_BUCKET']     ?? '',
        'region'     => $_ENV['STORAGE_S3_REGION']     ?? 'auto',
        'access_key' => $_ENV['STORAGE_S3_ACCESS_KEY'] ?? '',
        'secret'     => $_ENV['STORAGE_S3_SECRET']     ?? '',
    ],
];
