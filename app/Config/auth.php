<?php

// Phase S2 Fase 2 — usa PANTEDU_DATA_PATH se valorizzato (path fuori repo).
$base = \App\Core\Config::cartellaDati();

// 2026-09-20 — quello che l'applicazione SCRIVE sta sotto `<dati>/storage`, e
// basta. `<dati>` in produzione appartiene a root: la procedura di ripristino
// (docs/ops/ripristino.md) dà a www-data il solo `storage/`, il salvataggio
// notturno archivia il solo `storage/`, e l'avvio del container prova a
// scrivere nel solo `storage/`. Un file fuori di lì non è protetto, non è
// salvato e non è controllato — e la sua scrittura fallisce in silenzio.
//
// La cache JSON dei blocchi era in `<dati>/log/data/`: ci si scriveva (e ci si
// legge, `App\Services\BlockList`) fuori dall'unico albero coperto. Il
// database resta la fonte di verità (`waf_blocked_*`), quindi il file che
// resta indietro nella vecchia cartella non porta via niente:
// `WafSecurityRepository` continua a leggerlo per l'import legacy.
$legacyLog = $base . '/log/data';

return [
    'paths' => [
        // Sola LETTURA, e legacy: lo scrive nessuno, lo legge solo
        // `tools/import_legacy_users_to_db.php`. Gli utenti stanno nel
        // database dalla Phase 18.
        'admin_users'         => $legacyLog . '/admin_users.json',
        'blocked_credentials' => $base . '/storage/security/blocked_credentials.json',
        'blocked_ips'         => $base . '/storage/security/blocked_ips.json',
        // Dove stavano prima: l'import legacy di `WafSecurityRepository` le
        // guarda ancora, così un'istanza già in servizio non perde i blocchi
        // che ha solo lì.
        'blocked_credentials_legacy' => $legacyLog . '/blocked_credentials.json',
        'blocked_ips_legacy'         => $legacyLog . '/blocked_ips.json',
        // Dal 24/9/2026 non lo scrive e non lo legge più l'applicazione: era
        // la copia degli account approvati, hash compreso, che il login non
        // usava. Il percorso resta per il lavoro notturno, che cancella la
        // copia rimasta (`App\Services\Gdpr\ConservazioneDelleIscrizioni`), e
        // per l'import legacy della Phase 18.
        'registered_users'    => $base . '/storage/data/users.json',
        // Le domande di iscrizione in attesa: si cancellano dopo
        // `retention.pending_registration_days` giorni, senza IP né
        // User-Agent e senza storico (ConservazioneDelleIscrizioni).
        'registrations'       => $base . '/storage/data/registrations.json',
    ],

    'rate_limit' => [
        'max_attempts'    => (int)($_ENV['LOGIN_MAX_ATTEMPTS']    ?? 5),
        'lockout_seconds' => (int)($_ENV['LOGIN_LOCKOUT_SECONDS'] ?? 300),
    ],

    'session_pattern' => '#(?:eser|lab|map)_([a-z]+)(\d+[sb]?)#',
];
