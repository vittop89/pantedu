<?php

/**
 * Audit — motivazione degli interventi amministrativi e log degli accessi.
 *
 * `RequiresAuditReasonMiddleware` leggeva `audit.reason_mode` da un file che
 * non esisteva (revisione architetturale 2026-09, A8): il valore arrivava
 * solo da `getenv('AUDIT_REASON_MODE')`, che vede l'ambiente del processo ma
 * non il `.env` (Dotenv popola $_ENV, non putenv). Da qui in poi la fonte e'
 * questa; l'ambiente del processo resta un ripiego per chi lo imposta nel
 * servizio systemd.
 *
 *   reason_mode   enforce  → 400 se X-Audit-Reason manca o non e' valida
 *                            (predefinito)
 *                 warn     → logga l'assenza e procede
 *                 disabled → nessun controllo
 *   access_log_max_entries  righe tenute in access_log.json (le piu' vecchie
 *                 vengono scartate a ogni scrittura)
 *
 * 2026-09-23 — il predefinito e' `enforce`, anche per un valore sconosciuto
 * (revisione architetturale del 23/9, A-37). Era `warn`, il modo del
 * rollout: finito il 2/9/2026, da quando la produzione dice `enforce`. Ma
 * l'invariante dipendeva da una riga di un file: senza la chiave, o con un
 * errore di battitura, le mutazioni dei super-admin passavano senza
 * motivazione e il registro annotava soltanto che mancava. Un modo piu'
 * permissivo ora va chiesto per nome; in produzione non parte nemmeno
 * (docker/verifica-avvio.php).
 */

return [
    'reason_mode' => (static function (): string {
        $mode = strtolower((string)($_ENV['AUDIT_REASON_MODE'] ?? (getenv('AUDIT_REASON_MODE') ?: 'enforce')));
        return in_array($mode, ['enforce', 'warn', 'disabled'], true) ? $mode : 'enforce';
    })(),
    'access_log_max_entries' => (int)($_ENV['LOG_MAX_ENTRIES'] ?? 1000),
];
