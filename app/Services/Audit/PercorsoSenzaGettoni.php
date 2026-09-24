<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Il percorso di una richiesta come si scrive in un registro: senza i gettoni
 * che vi viaggiano (23/9/2026).
 *
 * PERCHE' ESISTE
 *   Alcuni gettoni viaggiano nel percorso e sono essi stessi una credenziale:
 *   il QR della credenziale di classe (`/accesso-classe/qr/{token}`, anche
 *   permanente) apre il perimetro pubblicato di un docente; il collegamento
 *   del consenso del genitore (`/parent-consent/{token}`) lo conferma. Altri
 *   viaggiano nella query string: il ripristino della password, la conferma
 *   dell'email e della cancellazione (`?token=`), gli indirizzi firmati
 *   (`?t=`), il ritorno da Google (`?code=`).
 *
 *   `audit_activity_log` conservava l'URI intero per due anni: il gettone del
 *   QR ci finiva ogni volta che la rotta rispondeva 429 dietro il NAT della
 *   scuola, e ogni POST di conferma del consenso vi lasciava il suo
 *   (revisione architetturale 2026-09, A-81). `waf_logs` mascherava già il
 *   QR dal 22/9/2026, con una regola sua: da oggi la regola è questa, una.
 *
 * DUE USI
 *   - mascherati(): toglie i gettoni dal percorso e lascia la query, che nel
 *     registro del WAF serve alla diagnosi;
 *   - perIlRegistro(): toglie anche la query. Per il registro delle
 *     operazioni e per quello degli accessi, che devono dire che cosa si è
 *     fatto e dove, non con quali parametri — e un parametro nuovo che porta
 *     un segreto non deve poterci entrare per dimenticanza.
 *
 * Una rotta nuova con un gettone nel percorso va aggiunta a
 * PREFISSI_CON_GETTONE: tests/Unit/Services/Audit/PercorsiSenzaGettoniTest.php
 * lo verifica su routes/web.php.
 */
final class PercorsoSenzaGettoni
{
    /** Al posto del gettone: lo stesso segno che `waf_logs` usa dal 22/9/2026. */
    public const SEGNAPOSTO = '<omesso>';

    /**
     * I prefissi dopo cui il segmento successivo e' un gettone.
     *
     * @var list<string>
     */
    public const PREFISSI_CON_GETTONE = [
        // Credenziale di classe dal QR, permanente o a tempo; più codici
        // separati da virgola sono un pacchetto di classe.
        '/accesso-classe/qr/',
        // Conferma del consenso del genitore dal collegamento ricevuto per posta.
        '/parent-consent/',
    ];

    /**
     * Il percorso con i gettoni mascherati; query string e resto intatti.
     * Funziona anche su un indirizzo completo (il referer del WAF), e senza
     * distinzione di maiuscole: il router le distingue, ma una POST a
     * `/Parent-Consent/{token}` si registra lo stesso (404), col gettone vero.
     */
    public static function mascherati(string $percorso): string
    {
        if ($percorso === '') {
            return '';
        }
        foreach (self::PREFISSI_CON_GETTONE as $prefisso) {
            $percorso = (string)preg_replace(
                '#(' . preg_quote($prefisso, '#') . ')[^/?\#\s]+#i',
                '${1}' . self::SEGNAPOSTO,
                $percorso,
            );
        }
        return $percorso;
    }

    /** Il percorso senza query string né frammento, e con i gettoni mascherati. */
    public static function perIlRegistro(string $uri): string
    {
        $fine = strcspn($uri, '?#');
        return self::mascherati(substr($uri, 0, $fine));
    }
}
