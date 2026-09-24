<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Il bundle dei certificati delle autorità (CA) per le chiamate HTTPS in
 * uscita: servizio TeX, Drive, fornitori dell'import dei PDF, HIBP, liste di
 * threat intel, GitHub.
 *
 * Una regola sola, in quest'ordine:
 *
 *   1. la variabile d'ambiente `CA_BUNDLE` (`app.ca_bundle`), se indica un
 *      file leggibile;
 *   2. il bundle di sistema di Linux, nei percorsi delle distribuzioni
 *      (Debian e Ubuntu — anche l'immagine dell'applicazione, che installa
 *      `ca-certificates` —, poi RHEL e Alpine);
 *   3. niente: null, e curl e openssl usano il loro.
 *
 * Un `CA_BUNDLE` che non indica un file si salta e si passa al punto 2: la
 * verifica TLS resta accesa, con il bundle di sistema.
 *
 * 23/9/2026 (revisione architetturale 2026-09, A-42) — prima c'erano quattro
 * modi. `app/Config/tex_compile.php`, `drive.php` e `pdf_import.php` avevano
 * come default `C:\xampp\apache\bin\curl-ca-bundle.crt`, un percorso della
 * vecchia installazione su Windows che su Linux non esiste (i chiamanti lo
 * scartavano con `is_file`), con tre variabili d'ambiente diverse
 * (`TEX_COMPILE_CA_BUNDLE`, `DRIVE_CA_BUNDLE`, `PDF_IMPORT_CA_BUNDLE`);
 * `HibpService` e `WafThreatIntelService` cercavano ciascuno per conto suo
 * con composer/ca-bundle, le impostazioni di php.ini e i percorsi di Linux;
 * `GitHubSyncService` cercava `storage/ca-bundle/cacert.pem` nella radice del
 * codice. Adesso tutti chiedono qui, quando parte la chiamata, e la prova
 * `BundleCaTest` fallisce se nel codice ricompare un altro modo di cercarlo.
 *
 * Non si chiede nella configurazione (`app/Config/*.php`): quella si carica a
 * ogni richiesta e da ogni strumento, e non deve toccare il disco fuori dalla
 * copia (VistoSenzaSegretiTest la fa girare con `open_basedir`).
 */
final class BundleCa
{
    /** I bundle di sistema delle distribuzioni Linux, nell'ordine in cui si cercano. */
    public const PERCORSI_DI_SISTEMA = [
        '/etc/ssl/certs/ca-certificates.crt', // Debian, Ubuntu (e l'immagine dell'applicazione)
        '/etc/pki/tls/certs/ca-bundle.crt',   // RHEL, Fedora
        '/etc/ssl/cert.pem',                  // Alpine
    ];

    /**
     * Il bundle da dare a curl (`CURLOPT_CAINFO`, `verify` di Guzzle), o null
     * per lasciare quello di curl e openssl.
     */
    public static function percorso(): ?string
    {
        return self::trova((string)\App\Core\Config::get('app.ca_bundle', ''), self::PERCORSI_DI_SISTEMA);
    }

    /**
     * La regola, senza leggere l'ambiente: le prove le passano i percorsi.
     *
     * @param list<string> $sistema
     */
    public static function trova(string $dichiarato, array $sistema): ?string
    {
        $dichiarato = trim($dichiarato);
        if ($dichiarato !== '' && is_file($dichiarato) && is_readable($dichiarato)) {
            return $dichiarato;
        }
        foreach ($sistema as $percorso) {
            if (is_file($percorso) && is_readable($percorso)) {
                return $percorso;
            }
        }
        return null;
    }
}
