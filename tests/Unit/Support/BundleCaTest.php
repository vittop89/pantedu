<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Core\Config;
use App\Support\BundleCa;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;

/**
 * Il bundle delle CA si trova in un modo solo (23/9/2026, revisione
 * architetturale 2026-09, rilievo A-42).
 *
 * Prima: tre file di configurazione (`tex_compile.php`, `drive.php`,
 * `pdf_import.php`) con il default `C:\xampp\apache\bin\curl-ca-bundle.crt`
 * e tre variabili d'ambiente diverse; `HibpService` e `WafThreatIntelService`
 * con una ricerca propria (composer/ca-bundle, php.ini, percorsi di Linux);
 * `GitHubSyncService` con `storage/ca-bundle/cacert.pem` nella radice del
 * codice. Adesso `App\Support\BundleCa`: la variabile `CA_BUNDLE`, poi il
 * bundle di sistema di Linux, poi niente.
 *
 * Qui la regola nei suoi rami, la configurazione che la usa, e un controllo
 * sul codice: nessun file di `app/` cerca il bundle per conto suo. Il
 * controllo è provato anche nel verso che scatta, su un testo che cerca il
 * bundle alla vecchia maniera.
 *
 * Controprova (misurata il 23/9/2026): sul codice di origin/main la prova
 * della configurazione fallisce (le tre chiavi ci sono, con il percorso
 * XAMPP), e il controllo sul codice trova i tre file di configurazione, gli
 * undici file che leggevano `*.ca_bundle` (tredici letture), HibpService,
 * WafThreatIntelService e GitHubSyncService.
 */
final class BundleCaTest extends TestCase
{
    private string $cartella = '';

    /** @var array<string, mixed> */
    private array $configPrima = [];

    private ?string $envPrima = null;

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-bundle-ca-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);
        /** @var array<string, mixed> $prima */
        $prima = (new ReflectionProperty(Config::class, 'items'))->getValue();
        $this->configPrima = $prima;
        $this->envPrima = isset($_ENV['CA_BUNDLE']) ? (string)$_ENV['CA_BUNDLE'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->envPrima === null) {
            unset($_ENV['CA_BUNDLE']);
        } else {
            $_ENV['CA_BUNDLE'] = $this->envPrima;
        }
        (new ReflectionProperty(Config::class, 'items'))->setValue(null, $this->configPrima);
        foreach (glob($this->cartella . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cartella);
    }

    private function file(string $nome): string
    {
        $percorso = $this->cartella . '/' . $nome;
        file_put_contents($percorso, "-----BEGIN CERTIFICATE-----\nprova\n-----END CERTIFICATE-----\n");
        return $percorso;
    }

    // ── La regola ─────────────────────────────────────────────────────────

    #[Test]
    public function la_variabile_d_ambiente_vince_se_indica_un_file(): void
    {
        $dichiarato = $this->file('mio.pem');
        $sistema = $this->file('sistema.pem');

        $this->assertSame($dichiarato, BundleCa::trova($dichiarato, [$sistema]));
    }

    #[Test]
    public function senza_variabile_si_prende_il_primo_bundle_di_sistema_che_c_e(): void
    {
        $sistema = $this->file('sistema.pem');

        $this->assertSame($sistema, BundleCa::trova('', [$this->cartella . '/manca.crt', $sistema]));
    }

    #[Test]
    public function una_variabile_che_non_indica_un_file_si_salta(): void
    {
        $sistema = $this->file('sistema.pem');

        $this->assertSame($sistema, BundleCa::trova($this->cartella . '/non-c-e.pem', [$sistema]));
        $this->assertSame($sistema, BundleCa::trova('   ', [$sistema]));
    }

    #[Test]
    public function senza_niente_null_e_curl_usa_il_suo(): void
    {
        $this->assertNull(BundleCa::trova('', [$this->cartella . '/manca.crt']));
        $this->assertNull(BundleCa::trova('', []));
    }

    /** `CA_BUNDLE` arriva dalla configurazione (`app.ca_bundle`), che la legge dall'ambiente. */
    #[Test]
    public function percorso_legge_ca_bundle_dall_ambiente(): void
    {
        $dichiarato = $this->file('dall-ambiente.pem');
        $_ENV['CA_BUNDLE'] = $dichiarato;
        Config::load(\dirname(__DIR__, 3) . '/app/Config');

        $this->assertSame($dichiarato, Config::get('app.ca_bundle'));
        $this->assertSame($dichiarato, BundleCa::percorso());

        Config::set('app.ca_bundle', '');
        $this->assertSame(BundleCa::trova('', BundleCa::PERCORSI_DI_SISTEMA), BundleCa::percorso());
    }

    // ── Chi la usa ────────────────────────────────────────────────────────

    /**
     * I tre file di configurazione non hanno più la chiave `ca_bundle` (e con
     * lei il default di Windows): il bundle si chiede a BundleCa quando parte
     * la chiamata. Non si calcola nella configurazione, che si carica a ogni
     * richiesta e non deve toccare il disco fuori dalla copia: il primo
     * tentativo lo faceva, e VistoSenzaSegretiTest l'ha fermato
     * (`open_basedir` su /etc/ssl).
     */
    #[Test]
    public function la_configurazione_non_ha_piu_un_bundle_suo(): void
    {
        Config::load(\dirname(__DIR__, 3) . '/app/Config');

        foreach (['tex_compile', 'drive', 'pdf_import'] as $file) {
            $this->assertIsArray(Config::get($file), "app/Config/$file.php si carica");
            $this->assertNull(Config::get("$file.ca_bundle"), "$file.ca_bundle");
        }
    }

    /**
     * I modi di cercare il bundle che non passano da BundleCa: percorsi di
     * sistema scritti a mano, il bundle di XAMPP, le impostazioni di php.ini
     * lette direttamente, composer/ca-bundle, un bundle nella radice del
     * codice.
     *
     * @return list<string>
     */
    private static function ricercheProprie(string $testo): array
    {
        $trovate = [];
        $forme = [
            'bundle di XAMPP'            => '/curl-ca-bundle|xampp[\\\\\/]+apache/i',
            'percorso di sistema'        => '#/etc/ssl/certs/ca-certificates\.crt|/etc/pki/tls/certs|/etc/ssl/cert\.pem#',
            'impostazione di php.ini'    => '/ini_get\(\s*[\'"](?:curl\.cainfo|openssl\.cafile)/',
            'composer/ca-bundle'         => '/CaBundle::/',
            'bundle nella radice'        => '#storage/ca-bundle#',
            'chiave di configurazione'   => '/Config::get\\(\\s*[\'"][a-z_]+\\.ca_bundle[\'"]/',
        ];
        foreach ($forme as $nome => $regex) {
            if (preg_match($regex, $testo) === 1) {
                $trovate[] = $nome;
            }
        }
        return $trovate;
    }

    #[Test]
    public function nessun_file_di_app_cerca_il_bundle_per_conto_suo(): void
    {
        $radice = \dirname(__DIR__, 3);
        $fuori = [];
        $letti = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice . '/app', RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $relativo = substr($f->getPathname(), \strlen($radice) + 1);
            if ($relativo === 'app/Support/BundleCa.php') {
                continue;
            }
            $letti++;
            foreach (self::ricercheProprie((string)file_get_contents($f->getPathname())) as $forma) {
                $fuori[] = "$relativo: $forma";
            }
        }

        // 483 file il 23/9/2026: un controllo che non legge niente dice sempre di sì.
        $this->assertGreaterThan(400, $letti, 'il controllo legge i file di app/, non zero');
        $this->assertSame([], $fuori, "Il bundle delle CA si chiede a App\\Support\\BundleCa::percorso().\n  "
            . implode("\n  ", $fuori));
    }

    /** Il verso opposto: le ricerche di prima le riconosce tutte. */
    #[Test]
    public function il_controllo_riconosce_le_ricerche_di_prima(): void
    {
        $this->assertSame(['bundle di XAMPP'], self::ricercheProprie(
            "'ca_bundle' => \$_ENV['DRIVE_CA_BUNDLE'] ?? 'C:\\\\xampp\\\\apache\\\\bin\\\\curl-ca-bundle.crt',"
        ));
        $this->assertSame(['percorso di sistema', 'impostazione di php.ini', 'composer/ca-bundle'], self::ricercheProprie(
            "return \\Composer\\CaBundle\\CaBundle::getSystemCaRootBundlePath();\n"
            . "\$iniCa = (string)ini_get('openssl.cafile') ?: (string)ini_get('curl.cainfo');\n"
            . "foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt'] as \$p) {}"
        ));
        $this->assertSame(['bundle nella radice'], self::ricercheProprie(
            "\$caBundle = dirname(__DIR__, 3) . '/storage/ca-bundle/cacert.pem';"
        ));
        $this->assertSame(['chiave di configurazione'], self::ricercheProprie(
            "\$caBundle = (string)\\App\\Core\\Config::get('tex_compile.ca_bundle', '');"
        ));
        $this->assertSame([], self::ricercheProprie("\$caBundle = \\App\\Support\\BundleCa::percorso();"));
    }
}
