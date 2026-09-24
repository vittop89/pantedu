<?php

/**
 * L'impronta di un indirizzo IP noto, per cercarlo nei registri.
 *
 * Dal 24/9/2026 i registri non tengono più `sha256(ip)`, che chiunque può
 * calcolare, ma un'impronta con chiave (`App\Support\ImprontaIp`): la sa
 * calcolare solo chi ha il segreto del server. Quindi per rispondere a «questo
 * indirizzo compare nei registri?» serve questo strumento, lanciato dove gira
 * l'applicazione (in produzione nel container, che ha il segreto
 * nell'ambiente).
 *
 * Stampa le due forme da cercare:
 *   - l'impronta con chiave, per le righe scritte da quel giorno;
 *   - lo SHA-256 senza chiave, per le righe scritte prima, che restano fino al
 *     termine della loro tabella.
 * Nelle colonne VARBINARY(32) (`ip_hash`, `request_ip_hash`, …) si cerca con
 * `UNHEX('<esadecimale>')`; in `password_resets` e `email_change_requests`
 * (`requested_ip_hash`, CHAR(64)) con la stringa esadecimale.
 *
 * SOLO CON IL SEGRETO DELL'AMBIENTE (24/9/2026)
 *   Senza `WAF_HMAC_SECRET` di almeno 32 byte, `app/Config/waf.php` non si
 *   ferma: si inventa una chiave e la scrive in `storage/keys/waf_hmac.key`
 *   della cartella dati. Lo strumento, lanciato fuori dal container (o con
 *   un'altra cartella dati), stampava allora l'impronta con quella chiave
 *   nuova, che non è quella dei registri, ed esito 0: «l'indirizzo non
 *   compare» senza che niente l'avesse cercato davvero. Misurato: due
 *   cartelle dati, due impronte diverse per lo stesso indirizzo, esito 0 e un
 *   file di chiave in più.
 *   Adesso il segreto si legge dall'ambiente (`.env`, `.env.local` e le
 *   variabili del processo, come fa l'applicazione) **prima** di caricare la
 *   configurazione, così `waf.php` non gira e non scrive niente; poi si
 *   controlla che la configurazione usi proprio quello, come
 *   `docker/verifica-avvio.php`. Altrimenti niente impronta ed esito 1.
 *
 * Non stampa il segreto né la chiave derivata.
 *
 * Uso:
 *   php tools/audit/impronta_ip.php <indirizzo>
 *
 * Esce con 0 se ha stampato l'impronta; 1 se `WAF_HMAC_SECRET` dell'ambiente
 * manca, è più corto di 32 byte o non è quello della configurazione (niente
 * impronta, niente file scritti); 2 se l'indirizzo manca.
 */

declare(strict_types=1);

use App\Core\Config;
use App\Support\ImprontaIp;
use Dotenv\Dotenv;

$radice = dirname(__DIR__, 2);
require $radice . '/vendor/autoload.php';

// Prima l'indirizzo: senza, non serve leggere niente.
$ip = ImprontaIp::normalizza($argv[1] ?? null);
if ($ip === null) {
    fwrite(STDERR, "Uso: php tools/audit/impronta_ip.php <indirizzo>\n");
    exit(2);
}

// L'ambiente come lo carica app/bootstrap.php, e niente configurazione.
if (is_file($radice . '/.env')) {
    Dotenv::createImmutable($radice)->safeLoad();
}
if (is_file($radice . '/.env.local')) {
    Dotenv::createMutable($radice, '.env.local')->safeLoad();
}
// Letto come lo legge app/Config/waf.php.
$segreto = (string)($_ENV['WAF_HMAC_SECRET'] ?? (getenv('WAF_HMAC_SECRET') ?: ''));
if (strlen($segreto) < 32) {
    fwrite(
        STDERR,
        "WAF_HMAC_SECRET manca nell'ambiente, o è più corto di 32 byte: l'impronta dei registri "
            . "non si calcola da qui. Si lancia dove gira l'applicazione (in produzione nel container).\n",
    );
    exit(1);
}

Config::load($radice . '/app/Config');
$usato = Config::get('waf.hmac_secret');
if (!is_string($usato) || !hash_equals($segreto, $usato)) {
    fwrite(
        STDERR,
        "La configurazione non usa WAF_HMAC_SECRET dell'ambiente: un'impronta calcolata qui non sarebbe "
            . "quella dei registri.\n",
    );
    exit(1);
}

$conChiave = ImprontaIp::esadecimale($ip);
if ($conChiave === null) {
    fwrite(STDERR, "Il segreto del server (waf.hmac_secret) non è utilizzabile: l'impronta non si calcola.\n");
    exit(1);
}

echo "indirizzo:                    {$ip}\n";
echo "impronta con chiave:          {$conChiave}\n";
echo 'SHA-256 senza chiave (prima):  ' . hash('sha256', $ip) . "\n";
exit(0);
