<?php

return [
    'name'                => $_ENV['SESSION_COOKIE_NAME']       ?? 'PANTEDU_SID',
    'lifetime'            => (int)($_ENV['SESSION_LIFETIME']    ?? 1800),
    // 2026-09-14 — al posto della rotazione dell'id ogni cinque minuti (tolta:
    // disconnetteva chi aveva richieste in parallelo), una durata massima dal
    // login, in secondi. Vedi Session::enforceAbsoluteLifetime.
    'absolute_lifetime'   => (int)($_ENV['SESSION_ABSOLUTE_LIFETIME'] ?? 43200),
    // 2026-09-14 (ADR-039) — dove stanno le sessioni si sceglie qui, non più
    // dall'esistenza della tabella `sessions`:
    //   - 'file' (predefinito): nella cartella `save_path`, che se vuota è
    //     `storage/sessions` dei dati d'istanza. Sta sul volume dei dati, quindi
    //     sopravvive allo scambio dei container del rilascio;
    //   - 'database': DbSessionHandler, nella tabella `sessions`. Serve con più
    //     macchine; prima di usarlo in produzione vanno fatte le cose scritte
    //     nell'ADR.
    'driver'              => $_ENV['SESSION_DRIVER']            ?? 'file',
    'save_path'           => $_ENV['SESSION_SAVE_PATH']         ?? '',
    // 'secure' forza cookie solo su HTTPS. Se .env non specifica, auto-detect:
    // true se richiesta arriva via HTTPS, false altrimenti. Evita cookie morto
    // in dev XAMPP HTTP locale. Dal 23/9/2026 (A-35) anche una riga vuota o un
    // valore sconosciuto ricadono sull'auto-detect: prima valevano «falso», e
    // `SESSION_COOKIE_SECURE=` mandava il cookie anche in chiaro.
    'secure'              => \App\Core\Config::booleanoDallAmbiente(
        'SESSION_COOKIE_SECURE',
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? 0) == 443,
    ),
    'httponly'            => true,
    'samesite'            => $_ENV['SESSION_COOKIE_SAMESITE'] ?? 'Lax',
];
