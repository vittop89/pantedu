<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Catena di hash sui registri append-only: la parte pura, senza database.
 *
 * PERCHE' (2026-09-04)
 *   I trigger append-only fermano l'utenza applicativa, non chi amministra il
 *   database o il server — cioe' la stessa persona che i registri dovrebbero
 *   controllare. Non si puo' impedire a un amministratore di riscrivere la
 *   storia; si puo' fare in modo che non possa farlo senza lasciare traccia.
 *   Ogni giorno le righe nuove di ogni registro vengono ridotte a un hash
 *   concatenato con quello del giorno prima; l'impronta finisce fuori dal
 *   server con il backup cifrato. Alterare o cancellare una riga ancora in
 *   conservazione cambia l'impronta ricalcolata, e la verifica lo dice.
 *
 * COME
 *   Una riga diventa una stringa deterministica (colonne in ordine
 *   alfabetico, valori binari in esadecimale, NULL distinto da stringa vuota)
 *   e poi un SHA-256. Il segmento del giorno e' la piega:
 *   head_n = sha256(head_{n-1} || rowhash_1 || ... || rowhash_k).
 */
final class AuditChain
{
    public const GENESIS = 'pantedu-audit-chain-v1';

    /**
     * @param array<string,mixed> $row
     */
    public static function rowHash(array $row): string
    {
        ksort($row, SORT_STRING);
        $parts = [];
        foreach ($row as $col => $val) {
            $parts[] = $col . '=' . self::scalar($val);
        }
        return hash('sha256', implode("\x1f", $parts));
    }

    /**
     * Piega una lista di hash di riga sull'impronta precedente.
     *
     * @param list<string> $rowHashes
     */
    public static function fold(string $prevHead, array $rowHashes): string
    {
        $ctx = hash_init('sha256');
        hash_update($ctx, $prevHead);
        foreach ($rowHashes as $h) {
            hash_update($ctx, $h);
        }
        return hash_final($ctx);
    }

    private static function scalar(mixed $v): string
    {
        if ($v === null) {
            return "\x00NULL";
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        $s = (string)$v;
        // VARBINARY (ip_hash, ua_hash) e qualunque byte non UTF-8: in
        // esadecimale, cosi' la stringa e' stabile e confrontabile.
        if ($s !== '' && !mb_check_encoding($s, 'UTF-8')) {
            return 'hex:' . bin2hex($s);
        }
        return 'str:' . $s;
    }
}
