<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

use App\Services\PdfImport\Session\SessionStorage;

/**
 * Log append-only delle operazioni LLM di una sessione PDF-import (estrazione,
 * scan numeri, argomenti, traduzione, soluzioni). Serve a dare VISIBILITÀ al
 * docente su cosa succede col provider (timeout, ritentativi, durate, token).
 *
 * Salvato in `llm_audit.json` nello storage della sessione (cifrato e cancellato
 * con la sessione). NON memorizza prompt/immagini né testo sensibile: solo
 * metadati operazione (op, stato, ms, modello, token, eventuale errore).
 */
final class LlmAuditLog
{
    private const FILE = 'llm_audit.json';
    private const CAP = 200;

    /** Millisecondi trascorsi da $t0 (microtime(true)), per il campo 'ms' del log. */
    public static function ms(float $t0): int
    {
        return (int)round((microtime(true) - $t0) * 1000);
    }

    /** @param array<string,mixed> $entry */
    public static function record(SessionStorage $storage, string $prefix, int $teacherId, array $entry): void
    {
        if ($prefix === '') {
            return;
        }
        try {
            $log = $storage->getJson($prefix, self::FILE, $teacherId);
            if (!is_array($log)) {
                $log = [];
            }
            $entry['ts'] = date('H:i:s');
            $log[] = $entry;
            if (count($log) > self::CAP) {
                $log = array_slice($log, -self::CAP);
            }
            $storage->putJson($prefix, self::FILE, $log, $teacherId);
        } catch (\Throwable) {
            /* il log non deve mai bloccare l'operazione */
        }
    }

    /** @return list<array<string,mixed>> ultime $last voci */
    public static function read(SessionStorage $storage, string $prefix, int $teacherId, int $last = 60): array
    {
        if ($prefix === '') {
            return [];
        }
        try {
            $log = $storage->getJson($prefix, self::FILE, $teacherId);
            if (!is_array($log)) {
                return [];
            }
            return array_values(array_slice($log, -$last));
        } catch (\Throwable) {
            return [];
        }
    }
}
