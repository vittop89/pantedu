<?php

/**
 * Configurazione GDPR — versione del testo informativa.
 *
 * Fino al 2026-09-01 questo file NON esisteva: ConsentService leggeva
 * `Config::get('gdpr.text_version', '1.0')` e ricadeva sempre sul default,
 * mentre l'informativa dichiarava versione 2.0. I consensi risultavano quindi
 * archiviati come "1.0" indipendentemente dal testo effettivamente mostrato —
 * un problema di accountability ex art. 7 §1, perche' non si poteva dimostrare
 * A QUALE testo l'interessato avesse acconsentito.
 *
 * REGOLA: questo valore deve corrispondere al campo `versione:` nel
 * frontmatter di `docs/privacy/informativa.md`. Se le due divergono, i
 * consensi vengono registrati contro una versione che non esiste.
 *
 * EFFETTO DI UN CAMBIO: `ConsentService::needsReconfirm()` inizia a
 * restituire i tipi di consenso che l'utente aveva prestato sulla versione
 * precedente, e il client riapre il pannello dei consensi (vedi
 * js/modules/core/cookie-consent.js). Il vecchio consenso NON viene revocato:
 * resta valido e tracciato finche' l'utente non si esprime di nuovo.
 *
 * PERCHE' NON E' UN GATE BLOCCANTE: l'art. 7 §4 richiede che il consenso sia
 * liberamente prestato. Subordinare l'accesso al servizio alla sua conferma lo
 * vizierebbe. Diversamente da ToS/AUP — che sono accettazione contrattuale e
 * hanno un gate duro (TosAcceptanceMiddleware) — qui l'utente puo' chiudere il
 * pannello e continuare a usare la piattaforma.
 */

declare(strict_types=1);

return [
    // Deve combaciare con docs/privacy/informativa.md → frontmatter `versione:`
    'text_version' => $_ENV['GDPR_TEXT_VERSION'] ?? '2.2',
];
