<?php

return [
    'name'                => $_ENV['SESSION_COOKIE_NAME']       ?? 'PANTEDU_SID',
    'lifetime'            => (int)($_ENV['SESSION_LIFETIME']    ?? 1800),
    'regenerate_interval' => (int)($_ENV['SESSION_REGENERATE_INTERVAL'] ?? 300),
    // 'secure' forza cookie solo su HTTPS. Se .env non specifica, auto-detect:
    // true se richiesta arriva via HTTPS, false altrimenti. Evita cookie morto
    // in dev XAMPP HTTP locale.
    'secure'              => isset($_ENV['SESSION_COOKIE_SECURE'])
        ? filter_var($_ENV['SESSION_COOKIE_SECURE'], FILTER_VALIDATE_BOOLEAN)
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? 0) == 443),
    'httponly'            => true,
    'samesite'            => $_ENV['SESSION_COOKIE_SAMESITE'] ?? 'Lax',
];
