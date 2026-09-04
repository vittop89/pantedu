<?php

$base = dirname(__DIR__, 2);

return [
    // Whitelist of absolute roots where reads/writes are allowed.
    // Keys are stable labels used throughout code; values must be real paths.
    // Phase 18 — whitelist ridotta: content legacy (eser, verifiche,
    // lab, mappe, didattica, risdoc, strcomp, drafts) servito da DB +
    // storage_objects, non più via filesystem raw. Rimangono i root
    // di infrastruttura (temp, tex_pdf, img, storage_*, log_data).
    'roots' => [
        'temp'           => $base . '/temp',
        'verifiche_temp' => $base . '/verifiche/temp',
        'tex_pdf'        => $base . '/tex_pdf',
        'img'            => $base . '/img',
        'log_data'       => $base . '/log/data',
        'storage_temp'   => $base . '/storage/temp',
        'storage_backups' => $base . '/storage/backups',
    ],

    // Filename -> allowed extensions for save operations.
    'allowed_extensions' => [
        'tex'   => ['tex'],
        'latex' => ['tex'],
        'pdf'   => ['pdf'],
        'image' => ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'],
        'json'  => ['json'],
        'html'  => ['html', 'htm'],
        'php'   => ['php'],
        'any'   => ['tex','pdf','png','jpg','jpeg','gif','svg','webp','json','html','htm','txt','log','csv','md'],
    ],

    // Max upload size (bytes) per operation type
    'max_sizes' => [
        'tex'   => 5  * 1024 * 1024,
        'pdf'   => 30 * 1024 * 1024,
        'image' => 10 * 1024 * 1024,
        'json'  => 10 * 1024 * 1024,
        'any'   => 50 * 1024 * 1024,
    ],
];
