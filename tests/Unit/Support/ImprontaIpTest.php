<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Core\Config;
use App\Support\ImprontaIp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'impronta degli IP è un HMAC con chiave, non uno SHA-256 nudo (24/9/2026).
 *
 * Fino a quel giorno i registri tenevano `hash('sha256', $ip)`: un IPv4 si
 * ritrova provando i circa 4,3 miliardi di valori possibili, e l'impronta non
 * nascondeva niente a chi aveva il registro. Adesso la calcola solo
 * `ImprontaIp`, con una chiave derivata con HKDF dal segreto del server.
 *
 * Nei due versi: lo stesso indirizzo dà la stessa impronta, indirizzi diversi
 * no; l'impronta non è lo SHA-256 dell'indirizzo (sul codice di prima lo era:
 * è la controprova); senza segreto non c'è impronta e c'è un'anomalia, con il
 * segreto non c'è anomalia. In produzione una chiave generata sul posto da
 * `app/Config/waf.php` si vede fra le anomalie; con il segreto dell'ambiente,
 * o fuori dalla produzione, no.
 *
 * Lo strumento `tools/audit/impronta_ip.php` gira in un processo suo, con un
 * ambiente e una cartella dati della prova: senza `WAF_HMAC_SECRET` valido
 * esce con 1 e non scrive niente; con il segreto stampa l'impronta
 * dell'applicazione, la stessa da due cartelle dati diverse. Sul codice di
 * prima, senza segreto, usciva con 0, due impronte diverse e un file di
 * chiave nuovo in ognuna.
 */
final class ImprontaIpTest extends TestCase
{
    /** Segreto della prova: lungo abbastanza per `app/Config/waf.php`. */
    private const SEGRETO = 'segreto-di-prova-per-le-impronte-degli-ip-0123456789';

    private const IP = '203.0.113.9';

    private mixed $segretoPrima = null;
    private mixed $origineSegretoPrima = null;
    private mixed $registriPrima = null;
    private mixed $ambientePrima = null;
    /** @var array{0: bool, 1: mixed} se `$_ENV` aveva la chiave, e che cosa */
    private array $envPrima = [false, null];
    private string|false $putenvPrima = false;
    private string $registri = '';
    /** @var list<string> cartelle temporanee da togliere alla fine (dati d'istanza delle prove) */
    private array $daTogliere = [];

    protected function setUp(): void
    {
        $this->segretoPrima = Config::get('waf.hmac_secret');
        $this->registriPrima = Config::get('app.paths.logs');
        $this->ambientePrima = Config::get('app.env');
        $this->envPrima = [\array_key_exists('WAF_HMAC_SECRET', $_ENV), $_ENV['WAF_HMAC_SECRET'] ?? null];
        $this->putenvPrima = getenv('WAF_HMAC_SECRET');
        $this->origineSegretoPrima = Config::get('waf.hmac_secret_dall_ambiente');
        Config::set('waf.hmac_secret', self::SEGRETO);
        // Il segreto viene dall'ambiente, come in produzione.
        Config::set('waf.hmac_secret_dall_ambiente', true);
        // Le anomalie di queste prove vanno in una cartella loro, non nel
        // registro dell'istanza.
        $this->registri = $this->cartellaTemporanea('pantedu-impronta-ip-');
        Config::set('app.paths.logs', $this->registri);
    }

    protected function tearDown(): void
    {
        Config::set('waf.hmac_secret', $this->segretoPrima);
        Config::set('waf.hmac_secret_dall_ambiente', $this->origineSegretoPrima);
        Config::set('app.paths.logs', $this->registriPrima);
        Config::set('app.env', $this->ambientePrima);
        if ($this->envPrima[0]) {
            $_ENV['WAF_HMAC_SECRET'] = $this->envPrima[1];
        } else {
            unset($_ENV['WAF_HMAC_SECRET']);
        }
        putenv($this->putenvPrima === false ? 'WAF_HMAC_SECRET' : 'WAF_HMAC_SECRET=' . $this->putenvPrima);
        foreach ($this->daTogliere as $cartella) {
            self::togli($cartella);
        }
    }

    /** `WAF_HMAC_SECRET` dell'ambiente, o nessuno con null. */
    private static function segretoNellAmbiente(?string $segreto): void
    {
        if ($segreto === null) {
            unset($_ENV['WAF_HMAC_SECRET']);
            putenv('WAF_HMAC_SECRET');
            return;
        }
        $_ENV['WAF_HMAC_SECRET'] = $segreto;
        putenv('WAF_HMAC_SECRET=' . $segreto);
    }

    private function cartellaTemporanea(string $prefisso): string
    {
        $cartella = sys_get_temp_dir() . '/' . $prefisso . bin2hex(random_bytes(6));
        mkdir($cartella, 0700, true);
        $this->daTogliere[] = $cartella;
        return $cartella;
    }

    private static function togli(string $cartella): void
    {
        if (!is_dir($cartella)) {
            return;
        }
        $giro = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cartella, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($giro as $voce) {
            $voce->isDir() ? @rmdir($voce->getPathname()) : @unlink($voce->getPathname());
        }
        @rmdir($cartella);
    }

    /** @return list<string> i file sotto una cartella, relativi. */
    private static function fileSotto(string $cartella): array
    {
        $trovati = [];
        $giro = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cartella, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($giro as $voce) {
            if ($voce->isFile()) {
                $trovati[] = substr($voce->getPathname(), \strlen($cartella) + 1);
            }
        }
        sort($trovati);
        return $trovati;
    }

    /** @return list<array<string, mixed>> le anomalie scritte dalla prova. */
    private function anomalie(): array
    {
        $righe = @file($this->registri . '/anomalie.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_map(static fn(string $r): array => (array)json_decode($r, true), $righe);
    }

    #[Test]
    public function lo_stesso_indirizzo_da_la_stessa_impronta_e_indirizzi_diversi_no(): void
    {
        $a = ImprontaIp::di(self::IP);
        self::assertIsString($a);
        self::assertSame(32, \strlen($a), '32 byte, come la colonna VARBINARY(32)');
        self::assertSame($a, ImprontaIp::di(self::IP));
        self::assertNotSame($a, ImprontaIp::di('203.0.113.10'), 'un indirizzo vicino ha un\'impronta diversa');
        self::assertNotSame($a, ImprontaIp::di('2001:db8::9'));
    }

    #[Test]
    public function l_impronta_non_e_lo_sha256_dell_indirizzo(): void
    {
        // La controprova: sul codice di prima i registri avevano esattamente
        // questi due valori, che chiunque ricalcola provando gli indirizzi.
        self::assertNotSame(hash('sha256', self::IP, true), ImprontaIp::di(self::IP));
        self::assertNotSame(hash('sha256', self::IP), ImprontaIp::esadecimale(self::IP));
    }

    #[Test]
    public function e_un_hmac_con_la_chiave_derivata_dal_segreto_con_un_etichetta_sua(): void
    {
        $chiave = hash_hkdf('sha256', self::SEGRETO, 32, 'pantedu/impronta-ip/v1');
        self::assertSame(hash_hmac('sha256', self::IP, $chiave, true), ImprontaIp::di(self::IP));

        // Non la chiave dell'impronta di sessione, né il segreto usato così
        // com'è: le etichette separano gli usi.
        $sessione = hash_hkdf('sha256', self::SEGRETO, 32, 'pantedu/access-log/impronta-sessione/v1');
        self::assertNotSame(hash_hmac('sha256', self::IP, $sessione, true), ImprontaIp::di(self::IP));
        self::assertNotSame(hash_hmac('sha256', self::IP, self::SEGRETO, true), ImprontaIp::di(self::IP));
    }

    #[Test]
    public function con_un_altro_segreto_l_impronta_cambia(): void
    {
        $prima = ImprontaIp::di(self::IP);
        Config::set('waf.hmac_secret', self::SEGRETO . '-diverso');
        self::assertNotSame($prima, ImprontaIp::di(self::IP));
    }

    #[Test]
    public function la_forma_esadecimale_e_la_stessa_impronta_in_64_cifre(): void
    {
        $esa = ImprontaIp::esadecimale(self::IP);
        self::assertIsString($esa);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $esa, 'la colonna CHAR(64) resta quella');
        self::assertSame(bin2hex((string)ImprontaIp::di(self::IP)), $esa);
    }

    #[Test]
    public function l_indirizzo_si_normalizza_prima_dell_impronta(): void
    {
        self::assertSame(ImprontaIp::di('2001:db8::1'), ImprontaIp::di('2001:DB8:0:0::1'), 'IPv6 in forma canonica');
        self::assertSame(ImprontaIp::di(self::IP), ImprontaIp::di('  ' . self::IP . ' '));
        self::assertSame(ImprontaIp::di(self::IP), ImprontaIp::di(self::IP . ', 10.0.0.1'), 'di un elenco conta il primo');

        foreach ([null, '', '   ', 'unknown', 'UNKNOWN', '0.0.0.0'] as $senza) {
            self::assertNull(ImprontaIp::di($senza), 'nessun indirizzo: ' . var_export($senza, true));
            self::assertNull(ImprontaIp::esadecimale($senza));
        }
        self::assertFileDoesNotExist($this->registri . '/anomalie.jsonl', 'un indirizzo che manca non è un\'anomalia');
    }

    #[Test]
    public function senza_segreto_non_c_e_impronta_e_c_e_un_anomalia(): void
    {
        // I tre modi in cui il segreto manca: vuoto, troppo corto per
        // app/Config/waf.php, o il suo ripiego di soli zeri, che tutti
        // conoscono.
        foreach (['', 'corto', str_repeat('0', 64)] as $segreto) {
            Config::set('waf.hmac_secret', $segreto);
            self::assertNull(ImprontaIp::di(self::IP), 'niente ripiego senza chiave');
            self::assertNull(ImprontaIp::esadecimale(self::IP));
            self::assertNull(ImprontaIp::delGiorno(self::IP, '2026-09-24'));
        }

        $codici = array_map(static fn(array $a): string => (string)($a['codice'] ?? ''), $this->anomalie());
        self::assertContains('impronta_ip_senza_segreto', $codici);
    }

    #[Test]
    public function in_produzione_una_chiave_generata_sul_posto_si_vede_fra_le_anomalie(): void
    {
        // La configurazione vera di app/Config/waf.php, con WAF_HMAC_SECRET
        // mancante o troppo corto: genera la sua chiave e la scrive nella
        // cartella dati, qui una della prova.
        foreach ([null, str_repeat('s', 31)] as $nellAmbiente) {
            [$waf, $dati] = $this->configurazioneDelWaf($nellAmbiente);
            self::assertFileExists($dati . '/storage/keys/waf_hmac.key', 'waf.php ha generato la sua chiave');
            self::assertFalse($waf['hmac_secret_dall_ambiente']);
            Config::set('app.env', 'production');
            // Un registro per caso: la seconda riga, nella stessa finestra, si conterebbe e basta.
            $this->registri = $this->cartellaTemporanea('pantedu-impronta-ip-');
            Config::set('app.paths.logs', $this->registri);

            self::assertNotNull(ImprontaIp::di(self::IP), 'la chiave è segreta: l\'impronta si calcola lo stesso');
            $anomalie = $this->anomalie();
            self::assertCount(1, $anomalie);
            self::assertSame('impronta_ip_senza_segreto', $anomalie[0]['codice'] ?? null);
            self::assertSame('chiave_generata_sul_posto', $anomalie[0]['dettagli']['causa'] ?? null);
            self::assertStringNotContainsString((string)$waf['hmac_secret'], (string)json_encode($anomalie),
                'la chiave non finisce nel registro');
        }
    }

    #[Test]
    public function in_produzione_con_il_segreto_dell_ambiente_nessuna_anomalia(): void
    {
        // L'altro verso, con la stessa configurazione vera: il segreto è
        // WAF_HMAC_SECRET dell'ambiente, e waf.php non scrive niente.
        [$waf, $dati] = $this->configurazioneDelWaf(self::SEGRETO);
        self::assertSame(self::SEGRETO, $waf['hmac_secret']);
        self::assertTrue($waf['hmac_secret_dall_ambiente']);
        self::assertSame([], self::fileSotto($dati));
        Config::set('app.env', 'production');

        self::assertNotNull(ImprontaIp::di(self::IP));
        self::assertNotNull(ImprontaIp::delGiorno(self::IP, '2026-09-24'));
        self::assertFileDoesNotExist($this->registri . '/anomalie.jsonl');
    }

    #[Test]
    public function fuori_dalla_produzione_la_chiave_locale_non_e_un_anomalia(): void
    {
        // In sviluppo e in CI WAF_HMAC_SECRET di solito non c'è: segnalarlo
        // ogni volta farebbe un registro che non è mai pulito.
        $this->configurazioneDelWaf(null);
        Config::set('app.env', 'testing');
        self::assertNotNull(ImprontaIp::di(self::IP));
        self::assertFileDoesNotExist($this->registri . '/anomalie.jsonl');
    }

    /**
     * `app/Config/waf.php` valutato con `WAF_HMAC_SECRET` dato (o tolto, con
     * null) e una cartella dati della prova; i due valori del segreto entrano
     * nella configurazione.
     *
     * @return array{0: array<string, mixed>, 1: string} la configurazione e la cartella dati
     */
    private function configurazioneDelWaf(?string $nellAmbiente): array
    {
        $dati = $this->cartellaTemporanea('pantedu-impronta-dati-');
        $datiPrima = [\array_key_exists('PANTEDU_DATA_PATH', $_ENV), $_ENV['PANTEDU_DATA_PATH'] ?? null];
        self::segretoNellAmbiente($nellAmbiente);
        $_ENV['PANTEDU_DATA_PATH'] = $dati;
        try {
            $waf = require \dirname(__DIR__, 3) . '/app/Config/waf.php';
        } finally {
            if ($datiPrima[0]) {
                $_ENV['PANTEDU_DATA_PATH'] = $datiPrima[1];
            } else {
                unset($_ENV['PANTEDU_DATA_PATH']);
            }
        }
        Config::set('waf.hmac_secret', $waf['hmac_secret']);
        Config::set('waf.hmac_secret_dall_ambiente', $waf['hmac_secret_dall_ambiente']);
        return [$waf, $dati];
    }

    #[Test]
    public function con_il_segreto_non_c_e_nessuna_anomalia(): void
    {
        // L'altro verso della prova di sopra: un controllo che scatta sempre
        // non distingue niente.
        self::assertNotNull(ImprontaIp::di(self::IP));
        self::assertNotNull(ImprontaIp::delGiorno(self::IP, '2026-09-24'));
        self::assertFileDoesNotExist($this->registri . '/anomalie.jsonl');
    }

    #[Test]
    public function lo_strumento_stampa_le_due_impronte_da_cercare_nei_registri(): void
    {
        // Due cartelle dati diverse, lo stesso segreto nell'ambiente: la
        // stessa impronta, quella che scrive l'applicazione, e nessun file.
        $segreto = self::segretoDelleVariabiliLocali() ?? self::SEGRETO . '-dello-strumento';
        Config::set('waf.hmac_secret', $segreto);
        $attesa = ImprontaIp::esadecimale(self::IP);
        self::assertNotNull($attesa);

        foreach (['a', 'b'] as $quale) {
            $dati = $this->cartellaTemporanea('pantedu-impronta-dati-' . $quale . '-');
            [$uscita, $esito] = $this->strumento([self::IP], $dati, self::SEGRETO . '-dello-strumento');
            self::assertSame(0, $esito, $uscita);
            self::assertSame(1, preg_match('/^impronta con chiave:\s+([0-9a-f]{64})$/m', $uscita, $m), $uscita);
            self::assertSame($attesa, $m[1], "la stessa impronta che scrive l'applicazione");
            self::assertNotSame(hash('sha256', self::IP), $m[1]);
            self::assertMatchesRegularExpression(
                '/^SHA-256 senza chiave \(prima\):\s+' . hash('sha256', self::IP) . '$/m',
                $uscita,
                'e lo SHA-256 per le righe di prima',
            );
            self::assertStringNotContainsString($segreto, $uscita, 'il segreto non si stampa');
            self::assertSame([], self::fileSotto($dati), 'nessun file nella cartella dati');
        }
    }

    #[Test]
    public function lo_strumento_senza_il_segreto_dell_ambiente_esce_con_1_e_non_scrive_niente(): void
    {
        if (self::segretoDelleVariabiliLocali() !== null) {
            self::markTestSkipped('.env.local definisce WAF_HMAC_SECRET e vince sull\'ambiente del processo: '
                . 'qui il segreto non si può togliere.');
        }
        // Mancante, vuoto, più corto di 32 byte, e il ripiego di soli zeri di
        // waf.php. Sul codice di prima, nei primi tre: esito 0, un'impronta
        // per cartella e storage/keys/waf_hmac.key in ognuna.
        foreach ([null, '', str_repeat('s', 31), str_repeat('0', 64)] as $segreto) {
            $dati = $this->cartellaTemporanea('pantedu-impronta-dati-');
            [$uscita, $esito] = $this->strumento([self::IP], $dati, $segreto);
            $caso = var_export($segreto === null ? null : \strlen($segreto), true);
            self::assertSame(1, $esito, "segreto di lunghezza {$caso}: {$uscita}");
            self::assertMatchesRegularExpression('/WAF_HMAC_SECRET|waf\.hmac_secret/', $uscita);
            self::assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $uscita, 'nessuna impronta');
            self::assertSame([], self::fileSotto($dati), "nessuna chiave scritta (segreto di lunghezza {$caso})");
        }
    }

    #[Test]
    public function lo_strumento_senza_indirizzo_non_stampa_impronte(): void
    {
        foreach ([[], ['unknown'], ['0.0.0.0']] as $argomenti) {
            $dati = $this->cartellaTemporanea('pantedu-impronta-dati-');
            [$uscita, $esito] = $this->strumento($argomenti, $dati, self::SEGRETO);
            self::assertSame(2, $esito, $uscita);
            self::assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $uscita);
            self::assertSame([], self::fileSotto($dati));
        }
    }

    /**
     * `WAF_HMAC_SECRET` di `.env.local`, se c'è e basta: lo strumento carica
     * quel file come l'applicazione, e lì vince sull'ambiente del processo.
     * In CI il file non c'è.
     */
    private static function segretoDelleVariabiliLocali(): ?string
    {
        $file = \dirname(__DIR__, 3) . '/.env.local';
        if (!is_file($file)) {
            return null;
        }
        $valori = \Dotenv\Dotenv::parse((string)file_get_contents($file));
        $segreto = (string)($valori['WAF_HMAC_SECRET'] ?? '');
        return \strlen($segreto) >= 32 ? $segreto : null;
    }

    /**
     * Lancia `tools/audit/impronta_ip.php` in un ambiente della prova (una
     * cartella dati sua, e `WAF_HMAC_SECRET` dato o tolto) e ne restituisce
     * uscita (stdout e stderr insieme) ed esito.
     *
     * @param list<string> $argomenti
     * @return array{0: string, 1: int}
     */
    private function strumento(array $argomenti, string $dati, ?string $segreto): array
    {
        $ambiente = getenv();
        unset($ambiente['WAF_HMAC_SECRET']);
        $ambiente['PANTEDU_DATA_PATH'] = $dati;
        if ($segreto !== null) {
            $ambiente['WAF_HMAC_SECRET'] = $segreto;
        }
        $comando = array_merge([PHP_BINARY, \dirname(__DIR__, 3) . '/tools/audit/impronta_ip.php'], $argomenti);
        $processo = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubi, null, $ambiente);
        self::assertIsResource($processo);
        $uscita = (string)stream_get_contents($tubi[1]) . (string)stream_get_contents($tubi[2]);
        fclose($tubi[1]);
        fclose($tubi[2]);
        return [$uscita, proc_close($processo)];
    }

    #[Test]
    public function l_impronta_del_giorno_cambia_col_giorno_e_non_e_lo_sha256(): void
    {
        $oggi = ImprontaIp::delGiorno(self::IP, '2026-09-24');
        self::assertIsString($oggi);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $oggi);
        self::assertSame($oggi, ImprontaIp::delGiorno(self::IP, '2026-09-24'));
        self::assertNotSame($oggi, ImprontaIp::delGiorno(self::IP, '2026-09-25'), 'un giorno dopo non si collega');
        self::assertNotSame($oggi, ImprontaIp::delGiorno('203.0.113.10', '2026-09-24'));
        // La formula di prima delle segnalazioni CSP.
        self::assertNotSame(substr(hash('sha256', self::IP . '|2026-09-24'), 0, 16), $oggi);
        self::assertNull(ImprontaIp::delGiorno('0.0.0.0', '2026-09-24'));
    }
}
