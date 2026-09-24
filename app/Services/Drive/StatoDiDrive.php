<?php

declare(strict_types=1);

namespace App\Services\Drive;

use App\Core\Config;
use GuzzleHttp\Exception\ClientException;

/**
 * Lo stato di Drive: quello dell'installazione, e i segnali con cui Google
 * dice che il collegamento di un docente va rifatto (ADR-038, 14/9/2026).
 *
 * Fino a quel giorno lo stato non c'era. Drive si offriva anche senza
 * credenziali OAuth: in produzione, da maggio, ogni notte tutte le mappe del
 * docente collegato finivano in errore. E un collegamento rifiutato da Google
 * restava uguale a uno buono: il giro ci riprovava ogni notte, e l'avviso
 * andava all'amministratore, che non può ricollegare al posto del docente.
 *
 * L'installazione:
 *   - spento: DRIVE_ENABLED non è vero. Drive non si offre; chi ha un
 *     collegamento di prima può solo scollegarlo; il giro esce con zero;
 *   - guasto: DRIVE_ENABLED è vero ma mancano le credenziali. È un guasto
 *     generale: il giro esce con 1 e parte l'avviso;
 *   - acceso: DRIVE_ENABLED è vero e le credenziali ci sono.
 *
 * Il collegamento di un docente va rifatto quando Google rifiuta il suo token
 * o quando manca il permesso su Drive. Può correggerlo solo il docente: lo
 * vede nel cruscotto, il giro lo salta, all'amministratore non arriva niente.
 */
final class StatoDiDrive
{
    public const SPENTO = 'spento';
    public const GUASTO = 'guasto';
    public const ACCESO = 'acceso';

    /** Google ha rifiutato il rinnovo del token (invalid_grant). */
    public const ACCESSO_REVOCATO = 'accesso_revocato';
    /** Il token c'è, ma senza il permesso di scrivere in Drive. */
    public const PERMESSI_INSUFFICIENTI = 'permessi_insufficienti';

    /** Il permesso che serve per scrivere nel Drive del docente (app/Config/drive.php). */
    public const PERMESSO_DRIVE = 'https://www.googleapis.com/auth/drive.file';

    /** I campi di drive.oauth e le chiavi d'ambiente da cui vengono. */
    private const CHIAVI = [
        'client_id'     => 'GOOGLE_DRIVE_CLIENT_ID',
        'client_secret' => 'GOOGLE_DRIVE_CLIENT_SECRET',
    ];

    /** Lo stato di questa installazione, dalla configurazione caricata. */
    public static function attuale(): string
    {
        $drive = Config::get('drive', []);
        return self::dellInstallazione(is_array($drive) ? $drive : []);
    }

    /**
     * @param array<string,mixed> $drive la configurazione drive
     */
    public static function dellInstallazione(array $drive): string
    {
        if (!filter_var($drive['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return self::SPENTO;
        }
        $oauth = $drive['oauth'] ?? [];
        return self::credenzialiMancanti(is_array($oauth) ? $oauth : []) === [] ? self::ACCESO : self::GUASTO;
    }

    /**
     * Le chiavi d'ambiente delle credenziali OAuth che mancano o sono vuote.
     *
     * @param array<string,mixed> $oauth la configurazione drive.oauth
     * @return list<string>
     */
    public static function credenzialiMancanti(array $oauth): array
    {
        $mancanti = [];
        foreach (self::CHIAVI as $campo => $chiave) {
            $valore = $oauth[$campo] ?? '';
            if (!is_string($valore) || trim($valore) === '') {
                $mancanti[] = $chiave;
            }
        }
        return $mancanti;
    }

    /**
     * Il permesso su Drive è fra quelli concessi?
     *
     * Con il consenso granulare di Google il docente può togliere la spunta a
     * Drive, e il token arriva lo stesso, senza quel permesso. Google chiede di
     * leggere il campo scope della risposta: i permessi concessi, separati da
     * spazi.
     */
    public static function permessoConcesso(string $concessi): bool
    {
        return in_array(self::PERMESSO_DRIVE, explode(' ', trim($concessi)), true);
    }

    /**
     * Il motivo per cui il docente deve ricollegare, se il rifiuto di Google è
     * di quel tipo; null se non lo è.
     *
     * Solo invalid_grant sul rinnovo del token. Misurato il 14/9/2026 con
     * google/apiclient e risposte simulate: arriva come ClientException 400,
     * con il motivo nel corpo. invalid_client (401) no: dice che le credenziali
     * dell'installazione sono sbagliate, un guasto generale che non si scarica
     * sui docenti.
     */
    public static function motivoDaRicollegare(\Throwable $e): ?string
    {
        if (!$e instanceof ClientException || $e->getResponse()->getStatusCode() !== 400) {
            return null;
        }
        $corpo = json_decode((string)$e->getResponse()->getBody(), true);
        return is_array($corpo) && ($corpo['error'] ?? null) === 'invalid_grant' ? self::ACCESSO_REVOCATO : null;
    }
}
