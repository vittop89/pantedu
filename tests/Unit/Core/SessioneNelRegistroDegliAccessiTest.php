<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\AccessLogger;
use App\Core\Config;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il registro degli accessi non conserva l'id di sessione (23/9/2026).
 *
 * Ogni voce di `access_log.json` conteneva `session_id()` in chiaro, di
 * sessioni vive: chi legge il registro — il super-amministratore dal pannello,
 * o chi ha accesso ai file dei dati — poteva presentarsi con quel cookie ed
 * essere il docente, senza la motivazione che ADR-006 chiede per leggerne i
 * contenuti (revisione architetturale 2026-09, A-64). Ora c'è un'impronta
 * HMAC troncata: raggruppa le richieste della stessa sessione, e dall'impronta
 * non si torna all'id.
 *
 * Le richieste passano da `session_id()` vero, in un processo PHP separato: in
 * PHPUnit l'uscita è già cominciata, e PHP non lascia più cambiare l'id.
 * Provato nei due versi: la stessa sessione ha la stessa impronta, due
 * sessioni diverse no. E le voci scritte prima perdono l'id alla prima
 * scrittura, e non lo mostrano nemmeno in lettura. La chiave dell'HMAC è
 * derivata con HKDF dal segreto del WAF, con un'etichetta sua: la prova
 * rifà il calcolo e lo confronta con le due scorciatoie sbagliate.
 */
final class SessioneNelRegistroDegliAccessiTest extends TestCase
{
    /** Segreto della prova, passato anche al processo figlio. */
    private const SEGRETO = 'segreto-di-prova-per-le-impronte-0123456789abcdef';

    private string $cartella = '';
    private mixed $segretoPrima = null;

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-impronta-sessione-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700, true);
        $this->segretoPrima = Config::get('waf.hmac_secret');
        Config::set('waf.hmac_secret', self::SEGRETO);
    }

    protected function tearDown(): void
    {
        Config::set('waf.hmac_secret', $this->segretoPrima);
        foreach (glob($this->cartella . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cartella);
    }

    #[Test]
    public function nessuna_voce_contiene_l_id_e_la_stessa_sessione_ha_la_stessa_impronta(): void
    {
        $idA = 'provasessioneA' . bin2hex(random_bytes(8));
        $idB = 'provasessioneB' . bin2hex(random_bytes(8));
        $this->richieste($idA, $idB);

        $grezzo = (string)file_get_contents($this->cartella . '/access_log.json');
        $debug = (string)file_get_contents($this->cartella . '/debug.log');
        foreach ([$idA, $idB] as $id) {
            self::assertStringNotContainsString($id, $grezzo, "l'id di sessione non finisce nel registro");
            self::assertStringNotContainsString($id, $debug, "né nella riga di uscita di debug.log");
        }

        $voci = json_decode($grezzo, true);
        self::assertIsArray($voci);
        self::assertCount(3, $voci);
        foreach ($voci as $voce) {
            self::assertArrayNotHasKey('session_id', $voce);
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string)$voce['session_fingerprint']);
        }
        self::assertSame($voci[0]['session_fingerprint'], $voci[1]['session_fingerprint'],
            'due richieste della stessa sessione si raggruppano');
        self::assertNotSame($voci[0]['session_fingerprint'], $voci[2]['session_fingerprint'],
            'due sessioni diverse no');
        self::assertSame(AccessLogger::improntaDellaSessione($idA), $voci[0]['session_fingerprint']);

        self::assertStringContainsString('session=' . $voci[0]['session_fingerprint'], $debug,
            "l'uscita si ritrova fra gli accessi della stessa sessione");
    }

    #[Test]
    public function l_impronta_e_un_hmac_e_non_un_semplice_hash(): void
    {
        // Con un hash senza chiave, da un id rubato altrove si direbbe quali
        // voci sono sue. Con la chiave del server no.
        $id = 'provasessioneC' . bin2hex(random_bytes(8));
        $impronta = AccessLogger::improntaDellaSessione($id);

        self::assertNotNull($impronta);
        self::assertNotSame(substr(hash('sha256', $id), 0, 16), $impronta);
        Config::set('waf.hmac_secret', self::SEGRETO . '-altro');
        self::assertNotSame($impronta, AccessLogger::improntaDellaSessione($id), "dipende dal segreto");
        self::assertNull(AccessLogger::improntaDellaSessione(''), 'senza sessione, niente impronta');
    }

    #[Test]
    public function la_chiave_e_derivata_con_hkdf_e_un_etichetta_sua(): void
    {
        // Il segreto del WAF firma i suoi cookie di sessione e le sfide:
        // l'impronta usa una chiave derivata con un'etichetta propria, così un
        // uso non presta la chiave all'altro. L'etichetta è parte del formato:
        // cambiandola cambiano tutte le impronte, e si perde il raggruppamento
        // a cavallo del cambio.
        $id = 'provasessioneD' . bin2hex(random_bytes(8));
        $impronta = AccessLogger::improntaDellaSessione($id);

        self::assertNotSame(substr(hash_hmac('sha256', $id, self::SEGRETO), 0, 16), $impronta,
            'non il segreto del WAF usato direttamente come chiave');
        self::assertNotSame(
            substr(hash_hmac('sha256', $id, hash_hkdf('sha256', self::SEGRETO, 32)), 0, 16),
            $impronta,
            'né una chiave derivata senza etichetta',
        );
        $chiave = hash_hkdf('sha256', self::SEGRETO, 32, 'pantedu/access-log/impronta-sessione/v1');
        self::assertSame(substr(hash_hmac('sha256', $id, $chiave), 0, 16), $impronta,
            "HMAC-SHA256 con la chiave HKDF dell'etichetta pantedu/access-log/impronta-sessione/v1");
    }

    #[Test]
    public function le_voci_scritte_prima_perdono_l_id_anche_in_lettura(): void
    {
        $vecchio = 'vecchiasessione' . bin2hex(random_bytes(8));
        file_put_contents($this->cartella . '/access_log.json', json_encode([[
            'timestamp' => '2026-09-22 09:00:00', 'date' => '2026-09-22', 'time' => '09:00:00',
            'username' => 'zz_docente', 'role' => 'teacher', 'linkref' => '/studio',
            'user_agent' => null, 'ip_address' => '192.0.2.10', 'session_id' => $vecchio,
            'action' => 'access',
        ]]));
        $registro = new AccessLogger($this->cartella);

        // In lettura, prima di qualunque scrittura: il pannello non lo mostra.
        $letto = $registro->recent(10);
        self::assertStringNotContainsString($vecchio, (string)json_encode($letto));
        self::assertSame(AccessLogger::improntaDellaSessione($vecchio), $letto[0]['session_fingerprint']);

        // Alla prima scrittura il file si riscrive senza.
        $registro->logAccess('zz_docente', 'teacher', '/studio/altro', 'access');
        $grezzo = (string)file_get_contents($this->cartella . '/access_log.json');
        self::assertStringNotContainsString($vecchio, $grezzo);
        self::assertStringNotContainsString('"session_id"', $grezzo);
    }

    /** Quattro richieste in un processo separato: A, A, B, poi l'uscita di A. */
    private function richieste(string $idA, string $idB): void
    {
        $figlio = $this->cartella . '/richieste.php';
        file_put_contents($figlio, <<<'PHP'
<?php
[$script, $base, $cartella, $segreto, $idA, $idB] = $argv;
require $base . '/vendor/autoload.php';
// Il segreto prima della configurazione: così app/Config/waf.php non cerca né
// genera la chiave dell'istanza.
$_ENV['WAF_HMAC_SECRET'] = $segreto;
\App\Core\Config::load($base . '/app/Config');
\App\Core\Config::set('waf.hmac_secret', $segreto);
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
$registro = new \App\Core\AccessLogger($cartella);
session_id($idA);
$registro->logAccess('zz_docente', 'teacher', '/studio/primo', 'access');
$registro->logAccess('zz_docente', 'teacher', '/studio/secondo', 'access');
session_id($idB);
$registro->logAccess('zz_altro', 'teacher', '/studio/terzo', 'access');
session_id($idA);
$registro->logAccess('zz_docente', 'teacher', null, 'logout');
echo "fatto\n";
PHP);
        $cmd = [PHP_BINARY, $figlio, \dirname(__DIR__, 3), $this->cartella, self::SEGRETO, $idA, $idB];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, \dirname(__DIR__, 3));
        self::assertIsResource($proc);
        $uscita = (string)stream_get_contents($pipes[1]);
        $errori = (string)stream_get_contents($pipes[2]);
        $esito = proc_close($proc);
        self::assertSame(0, $esito, "il processo figlio finisce bene: {$errori}");
        self::assertSame("fatto\n", $uscita, $errori);
    }
}
