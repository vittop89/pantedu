<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

/**
 * Phase PDF-Import — parsing robusto del JSON restituito dagli LLM.
 *
 * I modelli spesso avvolgono il JSON in fence ```json … ``` o aggiungono testo
 * intorno. Questo helper centralizza: rimozione fence + json_decode + fallback
 * che isola il primo blocco {…} o […]. Usato da extraction, numeri, difficoltà
 * (prima la stessa logica era duplicata in più servizi).
 */
final class LlmJson
{
    /** @return mixed array/valore decodificato, o null se non parsabile. */
    public static function decode(string $text): mixed
    {
        $t = trim((string)preg_replace('/^```[a-zA-Z]*\s*|\s*```$/m', '', trim($text)));
        if ($t === '') {
            return null;
        }
        $d = json_decode($t, true);
        if ($d !== null) {
            return $d;
        }
        // Fallback: isola il primo oggetto {…} o array […] nel testo.
        foreach ([['{', '}'], ['[', ']']] as [$open, $close]) {
            $s = strpos($t, $open);
            $e = strrpos($t, $close);
            if ($s !== false && $e !== false && $e > $s) {
                $d = json_decode(substr($t, $s, $e - $s + 1), true);
                if ($d !== null) {
                    return $d;
                }
            }
        }
        return null;
    }

    /** Decodifica attesa come array (oggetto/lista); [] se non parsabile. */
    public static function decodeArray(string $text): array
    {
        $d = self::decode($text);
        return is_array($d) ? $d : [];
    }
}
