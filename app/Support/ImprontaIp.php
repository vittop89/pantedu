<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * L'impronta di un indirizzo IP, per i registri che non devono tenerlo in
 * chiaro. È l'unico posto che la calcola.
 *
 * PERCHÉ (24/9/2026)
 *   Fino a oggi i registri tenevano `hash('sha256', $ip)`: uno SHA-256 senza
 *   chiave, calcolato in dieci punti diversi. Un indirizzo IPv4 è uno di
 *   circa 4,3 miliardi di valori: chi ha il registro li prova tutti, in pochi
 *   secondi su una scheda grafica, e ritrova ogni indirizzo. Quell'impronta
 *   non nascondeva niente a chi leggeva il registro, e i documenti che la
 *   dicevano «non ricostruibile» dicevano il falso.
 *
 * COME
 *   Un HMAC-SHA256 dell'indirizzo, con una chiave derivata con HKDF da
 *   `waf.hmac_secret` — lo stesso schema dell'impronta di sessione del
 *   registro degli accessi (`AccessLogger::improntaDellaSessione`), con
 *   un'etichetta sua: le due chiavi derivate sono diverse, e nessuna delle
 *   due firma i cookie del WAF. Senza la chiave, provare tutti gli IPv4 non
 *   serve: per ognuno manca il valore con cui confrontarlo. Con la chiave
 *   (cioè sul server) un indirizzo noto si confronta ancora con il registro:
 *   `tools/audit/impronta_ip.php`.
 *
 *   Resta un dato pseudonimo, non anonimo: chi ha il segreto del server e il
 *   registro può ancora ritrovare gli indirizzi per tentativi. Per questo il
 *   registro resta dato personale.
 *
 * FORMA
 *   `di()` dà 32 byte grezzi, per le colonne VARBINARY(32);
 *   `esadecimale()` le stesse in 64 cifre, per le colonne CHAR(64)
 *   (`password_resets`, `email_change_requests`). Stesse lunghezze dello
 *   SHA-256 di prima: nessuna migrazione.
 *
 * SENZA SEGRETO
 *   Niente impronta (null) e un'anomalia (`impronta_ip_senza_segreto`), mai
 *   un ripiego senza chiave: sarebbe di nuovo lo SHA-256 nudo, con in più
 *   l'apparenza di una protezione. Vale anche per il ripiego di
 *   `app/Config/waf.php` fatto di soli zeri, che è una chiave che tutti
 *   conoscono.
 *
 *   Senza `WAF_HMAC_SECRET` nell'ambiente, `waf.php` non lascia il segreto
 *   vuoto: ne genera uno e lo scrive in `storage/keys/waf_hmac.key` della
 *   cartella dati (o, se non ci riesce, uno nuovo a ogni processo). È una
 *   chiave vera, che nessuno conosce, e l'impronta si calcola lo stesso; ma
 *   vale solo per quella cartella dati, `tools/audit/impronta_ip.php` non la
 *   usa, e cambia quando il segreto arriva. In sviluppo e in CI è lo stato
 *   normale. In produzione (`APP_ENV=production`) non deve succedere —
 *   `docker/verifica-avvio.php` non fa partire il container — e se succede lo
 *   stesso, per esempio in un processo lanciato fuori dal container, si
 *   registra la stessa anomalia, con la causa nei dettagli (24/9/2026). Lo
 *   dice `waf.hmac_secret_dall_ambiente`, che `waf.php` calcola accanto al
 *   segreto senza cambiare come lo sceglie.
 *
 * LE RIGHE DI PRIMA
 *   Hanno lo SHA-256 senza chiave e restano come sono: non si ricalcolano
 *   senza l'indirizzo, e nei registri con la catena di impronte
 *   (`AuditChain`) riscriverle romperebbe la catena. Se ne vanno con il
 *   termine della loro tabella. Un'impronta nuova e una vecchia dello stesso
 *   indirizzo non coincidono: il raggruppamento a cavallo del cambio si
 *   perde, e nessun codice le confronta.
 *
 * Se il segreto cambia, cambiano le impronte: come per la sessione, si perde
 * solo il confronto a cavallo del cambio.
 */
final class ImprontaIp
{
    /** Etichetta HKDF: separa questa chiave da quella dell'impronta di sessione. */
    private const INFO = 'pantedu/impronta-ip/v1';

    /** Sotto questa lunghezza `app/Config/waf.php` non accetta il segreto. */
    private const SEGRETO_MINIMO = 32;

    /**
     * L'impronta con chiave dell'indirizzo, 32 byte grezzi.
     *
     * Null se non c'è un indirizzo (vuoto, `unknown`, `0.0.0.0`) o se manca
     * il segreto del server.
     */
    public static function di(?string $ip): ?string
    {
        $ip = self::normalizza($ip);
        if ($ip === null) {
            return null;
        }
        $chiave = self::chiave();
        if ($chiave === null) {
            return null;
        }
        return hash_hmac('sha256', $ip, $chiave, true);
    }

    /** Come `di()`, in 64 cifre esadecimali minuscole. */
    public static function esadecimale(?string $ip): ?string
    {
        $grezza = self::di($ip);
        return $grezza === null ? null : bin2hex($grezza);
    }

    /**
     * Un'impronta che cambia ogni giorno, 16 cifre esadecimali: per un
     * registro che deve contare gli indirizzi di una giornata senza poterli
     * seguire da un giorno all'altro (le segnalazioni CSP).
     */
    public static function delGiorno(?string $ip, string $giorno): ?string
    {
        $ip = self::normalizza($ip);
        if ($ip === null) {
            return null;
        }
        $chiave = self::chiave();
        if ($chiave === null) {
            return null;
        }
        return substr(hash_hmac('sha256', $ip . '|' . $giorno, $chiave), 0, 16);
    }

    /**
     * L'indirizzo come lo si impronta: senza spazi, il primo di un elenco
     * separato da virgole, e un IPv6 nella sua forma canonica (così
     * `2001:DB8:0::1` e `2001:db8::1` danno la stessa impronta). Null per
     * vuoto, `unknown` e `0.0.0.0`, che è il valore di `EdgeContext` quando
     * non c'è un indirizzo.
     */
    public static function normalizza(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }
        $ip = trim(explode(',', $ip)[0]);
        if ($ip === '' || strtolower($ip) === 'unknown' || $ip === '0.0.0.0') {
            return null;
        }
        // Un valore che non è un indirizzo si impronta com'è: faceva così
        // anche lo SHA-256 di prima, e scartarlo toglierebbe l'unica traccia.
        $binario = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        $canonico = $binario !== false ? inet_ntop($binario) : false;
        return $canonico !== false ? $canonico : $ip;
    }

    /** La chiave dell'HMAC, o null (con un'anomalia) se il segreto manca. */
    private static function chiave(): ?string
    {
        $segreto = (string)Config::get('waf.hmac_secret', '');
        if (\strlen($segreto) < self::SEGRETO_MINIMO || trim($segreto, '0') === '') {
            Anomalia::registra(
                'impronta_ip_senza_segreto',
                'Manca il segreto del server (waf.hmac_secret), o è il ripiego di soli zeri: '
                    . "l'impronta dell'IP non si calcola e il registro riceve null.",
                ['causa' => 'nessun_segreto'],
            );
            return null;
        }
        if (self::chiaveGenerataSulPosto()) {
            Anomalia::registra(
                'impronta_ip_senza_segreto',
                "In produzione WAF_HMAC_SECRET non è nell'ambiente: app/Config/waf.php usa una chiave generata "
                    . "sul posto (storage/keys/waf_hmac.key). Le impronte degli IP si calcolano, ma valgono solo "
                    . "per questa cartella dati e tools/audit/impronta_ip.php non le ritrova.",
                ['causa' => 'chiave_generata_sul_posto'],
                'impronta_ip_senza_segreto:chiave_generata_sul_posto',
            );
        }
        return hash_hkdf('sha256', $segreto, 32, self::INFO);
    }

    /**
     * In produzione, il segreto in uso non è `WAF_HMAC_SECRET` dell'ambiente?
     * Allora l'ha preso `app/Config/waf.php` dal suo file, o l'ha generato
     * sul posto: lo dice `waf.hmac_secret_dall_ambiente`, calcolato lì
     * (l'ambiente si legge solo in `app/Config`, ADR-049). Fuori dalla
     * produzione è lo stato normale e non si segnala.
     */
    private static function chiaveGenerataSulPosto(): bool
    {
        if ((string)Config::get('app.env', 'production') !== 'production') {
            return false;
        }
        return Config::get('waf.hmac_secret_dall_ambiente') !== true;
    }
}
