<?php

/**
 * Cifratura dei contenuti del docente (Phase 25.D, ADR-024/025).
 *
 *   dual_write        true → create/update scrivono plaintext e ciphertext
 *   read_from         'ciphertext' → le letture decifrano (dopo il backfill);
 *                     'plaintext' (default) → leggono le colonne in chiaro
 *   allow_regenerate  true → consente di rigenerare la KEK di un docente che
 *                     ha gia' dati cifrati (di norma bloccato: li renderebbe
 *                     illeggibili). Solo per i casi documentati nell'audit.
 *
 * La chiave madre (KMS_MASTER_KEY) non passa dalla configurazione, per non
 * finire in un dump: la legge e la giudica App\Services\Crypto\ChiaveMadre,
 * l'unico punto di app/ che la legge (23/9/2026, A-36). I test governano
 * questi flag con Config::set() (revisione 2026-09, P7).
 */

return [
    'dual_write'       => \App\Core\Config::booleanoDallAmbiente('CRYPTO_DUAL_WRITE', false),
    'read_from'        => (($_ENV['CRYPTO_READ_FROM'] ?? '') === 'ciphertext') ? 'ciphertext' : 'plaintext',
    'allow_regenerate' => \App\Core\Config::booleanoDallAmbiente('ALLOW_CRYPTO_REGENERATE', false),
];
