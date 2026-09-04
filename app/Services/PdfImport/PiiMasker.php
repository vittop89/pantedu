<?php

declare(strict_types=1);

namespace App\Services\PdfImport;

/**
 * Phase PDF-Import — masking PII prima delle chiamate al provider (OWASP LLM02).
 *
 * I PDF scolastici possono contenere dati personali (nomi su frontespizi,
 * codici fiscali, email). Prima di inviare QUALSIASI testo a un provider LLM
 * (specie cloud), i PII riconoscibili via pattern vengono redatti.
 *
 * Scope volutamente conservativo: codice fiscale italiano + email. Non pretende
 * completezza (i nomi liberi non sono rilevabili via regex); riduce il rischio
 * di leak dei pattern PII deterministici verso provider esterni.
 */
final class PiiMasker
{
    // Codice fiscale: 6 lettere, 2 cifre, lettera, 2 cifre, lettera, 3 cifre, lettera.
    private const RX_CF    = '/\b[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]\b/i';
    private const RX_EMAIL = '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i';

    /**
     * Redige CF ed email dal testo.
     *
     * @param int $count out-param: numero di redazioni effettuate (telemetry).
     */
    public static function mask(string $text, ?int &$count = null): string
    {
        $n = 0;
        $text = (string)preg_replace_callback(self::RX_CF, static function () use (&$n) {
            $n++;
            return '[CF_REDATTO]';
        }, $text);
        $text = (string)preg_replace_callback(self::RX_EMAIL, static function () use (&$n) {
            $n++;
            return '[EMAIL_REDATTA]';
        }, $text);
        $count = $n;
        return $text;
    }

    /** True se il testo contiene almeno un PII riconoscibile. */
    public static function hasPii(string $text): bool
    {
        return (bool)preg_match(self::RX_CF, $text) || (bool)preg_match(self::RX_EMAIL, $text);
    }
}
