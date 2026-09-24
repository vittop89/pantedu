<?php

// 2026-09-20 — I root di FileService sono una whitelist di SCRITTURA
// (`save()`, `delete()`, `clearRootContents()`). Nascevano tutti da
// `dirname(__DIR__, 2)`, la radice del repository: dall'8/9/2026
// l'applicazione gira in un container e quella radice è l'immagine, che è
// immutabile (`docker/Dockerfile`). Ogni salvataggio finiva nello strato
// scrivibile e spariva al rilascio successivo, senza un errore.
//
// Adesso nascono dalla cartella dei dati (`PANTEDU_DATA_PATH`; in sviluppo,
// se la variabile è vuota, torna a essere la radice del repository). È la
// causa comune di diversi punti a valle, e si corregge qui una volta sola.
//
// Le radici di scrittura stanno tutte sotto `storage/`, non accanto. In
// produzione `<dati>` appartiene a root e solo `<dati>/storage` è di
// www-data: è quello che dà la procedura di ripristino
// (docs/ops/ripristino.md), è quello che archivia il salvataggio notturno
// (tools/backup/encrypted_backup.sh) ed è quello che l'avvio del container
// prova a scrivere (docker/verifica-avvio.php). Una cartella fratello di
// `storage/` non è protetta, non è salvata e non è controllata.
//
// `img/` fa eccezione all'incontrario: è l'unica delle cinque radici
// versionata in git (18 file), lo serve nginx come asset statico e nessuno ci scrive.
// Resta quindi nell'IMMAGINE — la whitelist governa anche le letture
// (`listDirectory()`, `read()`), e puntarlo ai dati avrebbe reso vuoto
// `GET /files/list?directory=img` — ed entra fra le radici di sola lettura,
// così la trappola che si voleva togliere (una `delete` su `/img/…` che
// cancella un file dell'immagine) la toglie il codice e non il percorso.
$base = \App\Core\Config::cartellaDati();
$codice = dirname(__DIR__, 2);

return [
    // Whitelist of absolute roots where reads/writes are allowed.
    // Keys are stable labels used throughout code; values must be real paths.
    // Phase 18 — whitelist ridotta: content legacy (eser, verifiche,
    // lab, mappe, didattica, risdoc, strcomp, drafts) servito da DB +
    // storage_objects, non più via filesystem raw. Rimangono i root
    // di infrastruttura (temp, verifiche_temp, tex_pdf, img, storage_backups).
    'roots' => [
        // `temp` era `<dati>/temp` e non ci scriveva nessuno: l'unico uso era
        // la pulizia notturna, che quindi puliva una cartella vuota mentre
        // `storage/temp` — dove scrivono davvero le stampe — non l'ha pulita
        // mai nessuno. Una cartella sola, e la pulizia la trova.
        'temp'           => $base . '/storage/temp',
        'verifiche_temp' => $base . '/storage/verifiche/temp',
        'tex_pdf'        => $base . '/storage/tex_pdf',
        'img'            => $codice . '/img',
        'storage_backups' => $base . '/storage/backups',
    ],

    // Radici versionate: si leggono, non si scrivono. `FileService` rifiuta
    // `save()`, `delete()`, `deleteFolder()` e `clearRootContents()` su
    // queste, invece di riuscire e togliere un file all'immagine.
    'roots_sola_lettura' => ['img'],

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
