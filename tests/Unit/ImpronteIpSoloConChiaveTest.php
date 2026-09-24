<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Un'impronta di IP si calcola solo con `App\Support\ImprontaIp` (24/9/2026).
 *
 * Fino a quel giorno dieci punti del codice scrivevano nei registri
 * `hash('sha256', $ip)`, ognuno per conto suo: uno SHA-256 senza chiave, che
 * per un IPv4 si rovescia provando tutti gli indirizzi. Adesso passano tutti
 * da ImprontaIp, che usa un HMAC con chiave. Una formula nuova copiata da una
 * vecchia non si nota in revisione: la si cerca nel codice.
 *
 * Il cercatore legge i token PHP (non i commenti, non le stringhe che non
 * sono chiavi) dei file di `app/`, `public/`, `routes/`, `views/`, `tools/` e
 * `bin/`, e segnala:
 *
 *   - ogni chiamata a `hash()`, `md5()`, `sha1()` o `crc32()` fra i cui
 *     argomenti c'è qualcosa che porta un indirizzo;
 *   - ogni chiamata a `hash_hmac()` o a `sodium_crypto_generichash()` il cui
 *     dato porta un indirizzo e la cui chiave manca o è scritta nel codice:
 *     una stringa (anche vuota), un numero, una costante, o una loro
 *     concatenazione — tutto ciò che non contiene una variabile né una
 *     chiamata. Si leggono per posizione e per nome (`data:`, `key:`,
 *     `message:`).
 *
 * «Porta un indirizzo»: una variabile, una proprietà, una costante o una
 * funzione con `ip`, `ips`, `ipv4`, `ipv6`, `addr`, `address` o `forwarded`
 * fra le parti del nome, spezzato su `_` e sulle maiuscole (`$ip`, `$ipHash`,
 * `clientIp()`, `$req->ip`, non `$zip`); oppure una stringa che è un nome
 * con le stesse parti, cioè le chiavi di `$_SERVER` e degli array
 * (`'REMOTE_ADDR'`, `'HTTP_CF_CONNECTING_IP'`, `'HTTP_X_FORWARDED_FOR'`,
 * `'HTTP_X_REAL_IP'`, `'HTTP_CLIENT_IP'`, `'HTTP_FORWARDED'`, `'ip'`,
 * `'client_ip'`). Le eccezioni sono nominate qui sotto col perché, e
 * un'eccezione che il codice non contiene più fa fallire la prova.
 *
 * Che cosa non vede: un indirizzo passato sotto un nome che non lo dice
 * (`hash('sha256', $valore)`); una chiave calcolata da una chiamata che
 * restituisce una costante (`str_repeat('0', 32)`); le altre funzioni
 * (`hash_init()` con `HASH_HMAC`, `crypt()`, `password_hash()`, le
 * funzioni di un'estensione o di una libreria); e le formule scritte in SQL
 * (`SHA2(...)`, solo nella migrazione 100, già applicata).
 *
 * Provato nei due versi: trova le formule che il codice aveva fino al 24/9 e
 * quelle con le chiavi dei proxy, l'HMAC con la chiave vuota o scritta nel
 * codice e BLAKE2b senza chiave; non segnala un gettone, una variabile che
 * contiene «ip» dentro una parola, lo User-Agent, un HMAC con una chiave che
 * viene da una variabile o da una chiamata, un commento o una stringa che ne
 * parlano, né un metodo di un oggetto che si chiama `hash`.
 */
final class ImpronteIpSoloConChiaveTest extends TestCase
{
    private const CARTELLE = ['app', 'public', 'routes', 'views', 'tools', 'bin'];

    /** Funzioni di hash senza chiave. */
    private const FUNZIONI = ['hash', 'md5', 'sha1', 'crc32'];

    /**
     * Funzioni con una chiave: la posizione del dato e della chiave, e i nomi
     * degli argomenti. Senza chiave, o con una chiave scritta nel codice,
     * valgono quanto quelle di sopra.
     *
     * @var array<string, array{dato: int, chiave: int, nomi: list<string>}>
     */
    private const FUNZIONI_CON_CHIAVE = [
        'hash_hmac' => ['dato' => 1, 'chiave' => 2, 'nomi' => ['algo', 'data', 'key', 'binary']],
        'sodium_crypto_generichash' => ['dato' => 0, 'chiave' => 1, 'nomi' => ['message', 'key', 'length']],
    ];

    /** Parti di un nome che dicono «qui c'è un indirizzo». */
    private const PARTI_DI_INDIRIZZO = ['ip', 'ips', 'ipv4', 'ipv6', 'addr', 'address', 'forwarded'];

    /**
     * Chiamate che hanno un indirizzo fra gli argomenti e restano, per file,
     * con quante sono e il perché.
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const ECCEZIONI = [
        'app/Services/AnomalyDetectionService.php' => [1,
            'la chiave di raggruppamento di un\'anomalia «troppi accessi»: si calcola al volo dal '
            . 'registro degli accessi e sta nella stessa voce dell\'indirizzo in chiaro; non si salva'],
        'tools/audit/impronta_ip.php' => [1,
            'stampa lo SHA-256 senza chiave di un indirizzo noto, per cercarlo nelle righe scritte '
            . 'prima del 24/9/2026; non lo salva'],
    ];

    #[Test]
    public function nel_codice_nessuna_impronta_di_ip_senza_chiave(): void
    {
        $radice = \dirname(__DIR__, 2);
        $trovate = [];
        foreach (self::CARTELLE as $cartella) {
            if (!is_dir($radice . '/' . $cartella)) {
                continue;
            }
            $giro = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice . '/' . $cartella));
            foreach ($giro as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $relativo = substr($file->getPathname(), \strlen($radice) + 1);
                $righe = self::impronteSenzaChiave((string)file_get_contents($file->getPathname()));
                if ($righe !== []) {
                    $trovate[$relativo] = $righe;
                }
            }
        }

        $fuoriRegola = [];
        foreach ($trovate as $file => $righe) {
            $ammesse = self::ECCEZIONI[$file][0] ?? 0;
            if (\count($righe) > $ammesse) {
                $fuoriRegola[] = $file . ':' . implode(',', $righe);
            }
        }
        self::assertSame([], $fuoriRegola,
            "un'impronta di IP senza chiave: si usa App\\Support\\ImprontaIp::di() o ::esadecimale()");

        foreach (self::ECCEZIONI as $file => [$quante, $perche]) {
            self::assertCount($quante, $trovate[$file] ?? [],
                "l'eccezione per {$file} non corrisponde più al codice ({$perche}): va tolta o aggiornata");
        }
    }

    #[Test]
    public function il_cercatore_trova_le_formule_di_prima(): void
    {
        // Le forme che c'erano nel codice fino al 24/9/2026, una per riga.
        $vecchie = [
            "\$x = \$ip ? hash('sha256', \$ip, true) : null;",
            "\$x = \$ipHash ? hash('sha256', (string)\$ipHash, true) : null;",
            "return \\hash('sha256', \$ip, true);",
            "\$x = \$ip !== '' ? hash('sha256', \$ip) : null;",
            "\$x = substr(hash('sha256', (\$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') . '|' . date('Y-m-d')), 0, 16);",
            "\$x = 'ea:' . hash('sha256', \$g['ip'] . '|' . \$g['section']);",
            "\$x = hash('sha256', self::clientIp());",
            "\$x = md5(\$remoteAddr);",
            "\$x = sha1(\$client_ip);",
        ];
        foreach ($vecchie as $riga) {
            // La riga 1 è l'apertura `<?php`: la formula è sulla 2.
            self::assertSame([2], self::impronteSenzaChiave("<?php\n" . $riga . "\n"), $riga);
        }
    }

    #[Test]
    public function il_cercatore_trova_le_chiavi_dei_proxy_e_le_chiavi_scritte_nel_codice(): void
    {
        // Le forme che la prima versione della guardia non vedeva (revisione
        // del 24/9/2026), e le loro parenti.
        $nuove = [
            "\$x = hash(\"sha256\", \$_SERVER[\"HTTP_CF_CONNECTING_IP\"]);",
            "\$x = hash(\"sha256\", \$_SERVER[\"HTTP_X_FORWARDED_FOR\"] ?? \"\");",
            "\$x = hash('sha256', \$_SERVER['HTTP_X_REAL_IP']);",
            "\$x = hash('sha256', \$_SERVER['HTTP_CLIENT_IP']);",
            "\$x = hash('sha256', \$_SERVER['HTTP_TRUE_CLIENT_IP']);",
            "\$x = hash('sha256', \$_SERVER['HTTP_FORWARDED']);",
            "\$x = hash('sha256', \"{\$_SERVER['REMOTE_ADDR']}|giorno\");",
            "\$x = hash('sha256', \"\$_SERVER[REMOTE_ADDR]\");",
            "\$x = hash('sha256', \$req->ip);",
            "\$x = hash_hmac(\"sha256\", \$ip, \"\");",
            "\$x = hash_hmac('sha256', \$ip, 'una-chiave-scritta-nel-codice', true);",
            "\$x = hash_hmac('sha256', \$clientIp, self::CHIAVE);",
            "\$x = hash_hmac('sha256', \$_SERVER['REMOTE_ADDR'], 'pre' . 'fisso');",
            "\$x = \\hash_hmac('sha256', \$ip, 0);",
            "\$x = hash_hmac(algo: 'sha256', data: \$ip, key: '');",
            "\$x = hash_hmac(key: '', data: \$ip, algo: 'sha256');",
            "\$x = sodium_crypto_generichash(\$ip);",
            "\$x = sodium_crypto_generichash(\$ip, '');",
            "\$x = \\sodium_crypto_generichash(\$_SERVER['HTTP_CF_CONNECTING_IP'], '', 32);",
            "\$x = md5(\$ip);",
            "\$x = sha1(\$_SERVER['REMOTE_ADDR']);",
            "\$x = crc32(\$ipAddress);",
        ];
        foreach ($nuove as $riga) {
            self::assertSame([2], self::impronteSenzaChiave("<?php\n" . $riga . "\n"), $riga);
        }
    }

    #[Test]
    public function il_cercatore_non_segnala_quello_che_non_e_un_impronta_di_ip(): void
    {
        $innocue = [
            "\$x = hash('sha256', \$token);",
            "\$x = hash('sha256', \$zip . \$shipping);",
            "\$x = hash_hmac('sha256', \$ip, \$chiave, true);",
            "\$x = ImprontaIp::di(\$ip);",
            "// \$x = hash('sha256', \$ip, true);",
            "/** hash('sha256', \$ip) era la formula di prima */",
            "\$x = \"hash('sha256', \$ip)\";",
            "\$x = \$this->hash(\$ip);",
            "\$x = Qualcosa::hash(\$ip);",
            "\$x = hash('sha256', \$descrizione);",
            // Lo User-Agent resta SHA-256 senza chiave: è dichiarato.
            "\$x = hash('sha256', \$_SERVER['HTTP_USER_AGENT'] ?? '');",
            "\$x = hash('sha256', \$_SERVER['REQUEST_URI']);",
            // Una stringa qualunque non è una chiave di array.
            "\$x = hash('sha256', 'nota sul campo ip' . \$token);",
            // Chiavi che vengono da una variabile o da una chiamata.
            "\$x = hash_hmac('sha256', \$ip, hash_hkdf('sha256', \$segreto, 32, 'etichetta'), true);",
            "\$x = hash_hmac('sha256', \$ip, Config::get('waf.hmac_secret'));",
            "\$x = hash_hmac('sha256', \$ip, \$this->chiave);",
            "\$x = hash_hmac(algo: 'sha256', data: \$ip, key: \$chiave);",
            "\$x = sodium_crypto_generichash(\$ip, \$chiave);",
            // Un HMAC con chiave scritta nel codice di un dato che non è un
            // indirizzo è un altro discorso.
            "\$x = hash_hmac('sha256', \$token, '');",
            "\$x = sodium_crypto_generichash(\$messaggio);",
            // La chiave può chiamarsi come vuole: conta il dato.
            "\$x = hash_hmac('sha256', \$sessione, \$chiaveIp);",
        ];
        foreach ($innocue as $riga) {
            self::assertSame([], self::impronteSenzaChiave("<?php\n" . $riga . "\n"), $riga);
        }
    }

    /**
     * Le righe delle chiamate che impronterebbero un indirizzo senza chiave.
     *
     * @return list<int>
     */
    private static function impronteSenzaChiave(string $codice): array
    {
        $token = token_get_all($codice);
        $quanti = \count($token);
        $righe = [];
        for ($i = 0; $i < $quanti; $i++) {
            $t = $token[$i];
            if (!\is_array($t) || !\in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $nome = strtolower(ltrim($t[1], '\\'));
            $senzaChiave = \in_array($nome, self::FUNZIONI, true);
            if (!$senzaChiave && !isset(self::FUNZIONI_CON_CHIAVE[$nome])) {
                continue;
            }
            // Un metodo o una funzione dichiarata con quel nome non è la funzione di PHP.
            $prima = self::vicino($token, $i, -1);
            if ($prima !== null && \in_array(self::testo($token[$prima]), ['->', '?->', '::', 'function'], true)) {
                continue;
            }
            $aperta = self::vicino($token, $i, 1);
            if ($aperta === null || self::testo($token[$aperta]) !== '(') {
                continue;
            }
            $argomenti = self::argomenti($token, $aperta);
            if ($senzaChiave) {
                foreach ($argomenti as $argomento) {
                    if (self::portaUnIndirizzo($token, $argomento)) {
                        $righe[] = $t[2];
                        break;
                    }
                }
                continue;
            }
            $regola = self::FUNZIONI_CON_CHIAVE[$nome];
            $perPosizione = self::perPosizione($token, $argomenti, $regola['nomi']);
            $dato = $perPosizione[$regola['dato']] ?? null;
            $chiave = $perPosizione[$regola['chiave']] ?? null;
            if ($dato !== null && self::portaUnIndirizzo($token, $dato)
                && ($chiave === null || self::scrittaNelCodice($token, $chiave))) {
                $righe[] = $t[2];
            }
        }
        return $righe;
    }

    /**
     * Gli argomenti della chiamata aperta in `$aperta`: per ciascuno il primo
     * e l'ultimo indice dei suoi token. Un argomento vuoto (la virgola finale)
     * non c'è.
     *
     * @param list<mixed> $token
     * @return list<array{0: int, 1: int}>
     */
    private static function argomenti(array $token, int $aperta): array
    {
        $argomenti = [];
        $profondita = 0;
        $inizio = $aperta + 1;
        $quanti = \count($token);
        for ($j = $aperta; $j < $quanti; $j++) {
            $t = $token[$j];
            $testo = self::testo($t);
            $apre = \in_array($testo, ['(', '[', '{', '${', '#['], true)
                || (\is_array($t) && $t[0] === T_CURLY_OPEN);
            if ($apre) {
                $profondita++;
                continue;
            }
            $chiude = \in_array($testo, [')', ']', '}'], true);
            if (($chiude && $profondita === 1) || ($testo === ',' && $profondita === 1)) {
                if (self::vicino($token, $inizio - 1, 1) !== null && self::vicino($token, $inizio - 1, 1) < $j) {
                    $argomenti[] = [$inizio, $j - 1];
                }
                $inizio = $j + 1;
                if ($chiude) {
                    break;
                }
                continue;
            }
            if ($chiude) {
                $profondita--;
            }
        }
        return $argomenti;
    }

    /**
     * Gli argomenti messi al loro posto: prima quelli per posizione, poi
     * quelli per nome (`data: $ip`), con il nome tolto.
     *
     * @param list<mixed> $token
     * @param list<array{0: int, 1: int}> $argomenti
     * @param list<string> $nomi
     * @return array<int, array{0: int, 1: int}>
     */
    private static function perPosizione(array $token, array $argomenti, array $nomi): array
    {
        $posti = [];
        $posizione = 0;
        foreach ($argomenti as [$inizio, $fine]) {
            $primo = self::vicino($token, $inizio - 1, 1);
            $dopo = $primo === null ? null : self::vicino($token, $primo, 1);
            $conNome = $primo !== null && $dopo !== null && $dopo <= $fine
                && \is_array($token[$primo]) && $token[$primo][0] === T_STRING
                && self::testo($token[$dopo]) === ':';
            if ($conNome) {
                $indice = array_search(strtolower((string)$token[$primo][1]), $nomi, true);
                if ($indice !== false) {
                    $posti[$indice] = [$dopo + 1, $fine];
                }
                continue;
            }
            $posti[$posizione++] = [$inizio, $fine];
        }
        return $posti;
    }

    /**
     * Fra i token dell'argomento c'è qualcosa che porta un indirizzo?
     *
     * @param list<mixed> $token
     * @param array{0: int, 1: int} $argomento
     */
    private static function portaUnIndirizzo(array $token, array $argomento): bool
    {
        for ($j = $argomento[0]; $j <= $argomento[1]; $j++) {
            $t = $token[$j];
            if (!\is_array($t)) {
                continue;
            }
            if (\in_array($t[0], [T_VARIABLE, T_STRING, T_STRING_VARNAME], true)
                && self::nomeDiIndirizzo(ltrim($t[1], '$'))) {
                return true;
            }
            // Una stringa che è un nome: una chiave di $_SERVER o di un array.
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $contenuto = substr($t[1], 1, -1);
                if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $contenuto) === 1 && self::nomeDiIndirizzo($contenuto)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * La chiave è scritta nel codice? Cioè non contiene né una variabile né
     * una chiamata: stringhe (anche vuote), numeri, costanti e le loro
     * concatenazioni.
     *
     * @param list<mixed> $token
     * @param array{0: int, 1: int} $argomento
     */
    private static function scrittaNelCodice(array $token, array $argomento): bool
    {
        for ($j = $argomento[0]; $j <= $argomento[1]; $j++) {
            $t = $token[$j];
            if (!\is_array($t)) {
                continue;
            }
            if (\in_array($t[0], [T_VARIABLE, T_STRING_VARNAME, T_NEW], true)) {
                return false;
            }
            $nome = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_STATIC];
            if (\in_array($t[0], $nome, true)) {
                $dopo = self::vicino($token, $j, 1);
                if ($dopo !== null && self::testo($token[$dopo]) === '(') {
                    return false;
                }
            }
        }
        return true;
    }

    private static function nomeDiIndirizzo(string $nome): bool
    {
        $parti = preg_split('/_|(?<=[a-z0-9])(?=[A-Z])/', $nome) ?: [];
        foreach ($parti as $parte) {
            if (\in_array(strtolower($parte), self::PARTI_DI_INDIRIZZO, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * L'indice del token significativo più vicino (non spazio, non commento)
     * nella direzione data.
     *
     * @param list<mixed> $token
     */
    private static function vicino(array $token, int $da, int $verso): ?int
    {
        for ($j = $da + $verso; $j >= 0 && $j < \count($token); $j += $verso) {
            $t = $token[$j];
            if (\is_array($t) && \in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $j;
        }
        return null;
    }

    private static function testo(mixed $t): string
    {
        return \is_array($t) ? (string)$t[1] : (string)$t;
    }
}
