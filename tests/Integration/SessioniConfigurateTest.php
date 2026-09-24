<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Dove stanno le sessioni si sceglie in configurazione (ADR-039, 14/9/2026).
 *
 * Misurato quel giorno in produzione: la tabella `sessions` non c'era, quindi
 * Session::start ripiegava sui file nella cartella predefinita di PHP, che nel
 * container è la sua /tmp. Ogni rilascio cambia container: tutte le sessioni
 * restavano nel container fermato, e tutti gli utenti erano fuori.
 *
 * Qui, con Session::start vero e processi PHP separati come richieste:
 *   - a file, la sessione vive nella cartella configurata e sopravvive a una
 *     richiesta servita da un altro processo; con un'altra cartella, come la
 *     /tmp di un container nuovo, si perde (il difetto di prima);
 *   - un id che il server non conosce non si accetta, anche con PHP avviato
 *     senza la modalità stretta;
 *   - servendo pagine (server incorporato di PHP), la cartella che manca si
 *     crea leggibile solo da chi serve; una che non si può creare, o il
 *     database scelto senza la tabella, sono un errore 500, non un ripiego in
 *     silenzio.
 */
final class SessioniConfigurateTest extends TestCase
{
    private string $radice = '';
    private string $figlio = '';
    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        $this->radice = sys_get_temp_dir() . '/pantedu-sessioni-configurate-' . bin2hex(random_bytes(6));
        mkdir($this->radice, 0700, true);
        $this->figlio = $this->radice . '/richiesta.php';
        file_put_contents($this->figlio, <<<'PHP'
<?php
// Una richiesta: la configurazione dice dove stanno le sessioni, Session::start fa il resto.
$base = (string)getenv('PROVA_BASE');
require $base . '/vendor/autoload.php';
foreach (['.env', '.env.local'] as $f) {
    if (is_file("$base/$f")) {
        \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
    }
}
\App\Core\Config::load($base . '/app/Config');
ini_set('session.use_cookies', '0');
\App\Core\Config::set('session.driver', (string)getenv('PROVA_DRIVER'));
\App\Core\Config::set('session.save_path', (string)getenv('PROVA_CARTELLA'));
$id = (string)getenv('PROVA_ID');
if ($id !== '') {
    session_id($id);
}
\App\Core\Session::start();
if (getenv('PROVA_AZIONE') === 'prepara') {
    $_SESSION['autenticato'] = true;
    $_SESSION['login_time'] = time();
}
echo json_encode(['id' => session_id(), 'autenticato' => $_SESSION['autenticato'] ?? null]), "\n";
session_write_close();
PHP);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->svuota($this->radice);
    }

    private function svuota(string $cartella): void
    {
        if (!is_dir($cartella)) {
            return;
        }
        @chmod($cartella, 0700);
        foreach (scandir($cartella) ?: [] as $voce) {
            if ($voce === '.' || $voce === '..') {
                continue;
            }
            $percorso = $cartella . '/' . $voce;
            is_dir($percorso) && !is_link($percorso) ? $this->svuota($percorso) : @unlink($percorso);
        }
        @rmdir($cartella);
    }

    /**
     * @param array<string, string> $env
     * @param list<string>          $ini opzioni -d per PHP
     * @return array{id: string, autenticato: mixed}
     */
    private function richiesta(array $env, array $ini = []): array
    {
        $cmd = [PHP_BINARY];
        foreach ($ini as $opzione) {
            $cmd[] = '-d';
            $cmd[] = $opzione;
        }
        $cmd[] = $this->figlio;
        $tutte = array_merge(getenv(), ['PROVA_BASE' => dirname(__DIR__, 2), 'PROVA_DRIVER' => 'file', 'PROVA_ID' => '', 'PROVA_AZIONE' => 'leggi'], $env);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $tutte);
        $this->assertIsResource($proc);
        $uscita = (string)stream_get_contents($pipes[1]);
        $errori = (string)stream_get_contents($pipes[2]);
        proc_close($proc);
        $esito = json_decode(trim($uscita), true);
        $this->assertIsArray($esito, "la richiesta risponde: {$uscita}{$errori}");
        return ['id' => (string)$esito['id'], 'autenticato' => $esito['autenticato'] ?? null];
    }

    #[Test]
    public function a_file_la_sessione_vive_nella_cartella_configurata_e_con_un_altra_si_perde(): void
    {
        $volume = $this->radice . '/volume-dei-dati';
        $tmpDelContainerNuovo = $this->radice . '/tmp-del-container-nuovo';
        mkdir($volume, 0700);
        mkdir($tmpDelContainerNuovo, 0700);

        $id = $this->richiesta(['PROVA_CARTELLA' => $volume, 'PROVA_AZIONE' => 'prepara'])['id'];
        $this->assertNotSame('', $id);
        $this->assertFileExists($volume . '/sess_' . $id, 'la sessione sta nella cartella configurata');

        $stessaCartella = $this->richiesta(['PROVA_CARTELLA' => $volume, 'PROVA_ID' => $id]);
        $this->assertSame($id, $stessaCartella['id']);
        $this->assertTrue($stessaCartella['autenticato'], 'un altro processo con la stessa cartella la ritrova: il container nuovo sul volume');

        $altraCartella = $this->richiesta(['PROVA_CARTELLA' => $tmpDelContainerNuovo, 'PROVA_ID' => $id]);
        $this->assertNull($altraCartella['autenticato'], 'con la sua cartella vuota no: il difetto di prima, a ogni rilascio');
    }

    #[Test]
    public function un_id_che_il_server_non_conosce_non_si_accetta_anche_se_php_lo_accetterebbe(): void
    {
        $volume = $this->radice . '/volume-dei-dati';
        mkdir($volume, 0700);
        $inventato = 'inventato' . bin2hex(random_bytes(10));

        $esito = $this->richiesta(['PROVA_CARTELLA' => $volume, 'PROVA_ID' => $inventato], ['session.use_strict_mode=0']);

        $this->assertNotSame($inventato, $esito['id'], 'la modalità stretta la mette l\'applicazione, in ogni ambiente');
        $this->assertFileDoesNotExist($volume . '/sess_' . $inventato);
    }

    /**
     * Il server incorporato di PHP, con una pagina che avvia la sessione.
     *
     * @param array<string, string> $env
     * @return array{0: int, 1: string} codice HTTP e corpo
     */
    private function pagina(array $env): array
    {
        $pagina = $this->radice . '/pagina.php';
        file_put_contents($pagina, (string)file_get_contents($this->figlio));
        $tutte = array_merge(getenv(), ['PROVA_BASE' => dirname(__DIR__, 2), 'PROVA_DRIVER' => 'file', 'PROVA_ID' => '', 'PROVA_AZIONE' => 'prepara'], $env);

        for ($tentativo = 0; $tentativo < 5; $tentativo++) {
            $porta = random_int(20000, 45000);
            $this->server = proc_open(
                [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-S', "127.0.0.1:{$porta}", $pagina],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                $this->radice,
                $tutte,
            );
            for ($attesa = 0; $attesa < 50; $attesa++) {
                $prova = @fsockopen('127.0.0.1', $porta, $codice, $messaggio, 0.1);
                if ($prova !== false) {
                    fclose($prova);
                    $risposta = @file_get_contents("http://127.0.0.1:{$porta}/", false, stream_context_create([
                        'http' => ['ignore_errors' => true, 'timeout' => 10],
                    ]));
                    /** @var list<string> $http_response_header */
                    $stato = (int)(explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1] ?? 0);
                    proc_terminate($this->server);
                    proc_close($this->server);
                    $this->server = null;
                    return [$stato, (string)$risposta];
                }
                usleep(100_000);
            }
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
        $this->fail('il server incorporato di PHP non è partito');
    }

    #[Test]
    public function servendo_pagine_la_cartella_che_manca_si_crea_leggibile_solo_da_chi_serve(): void
    {
        $cartella = $this->radice . '/dati/storage/sessions';
        mkdir($this->radice . '/dati/storage', 0700, true);

        [$stato, $corpo] = $this->pagina(['PROVA_CARTELLA' => $cartella]);

        $this->assertSame(200, $stato, $corpo);
        $this->assertDirectoryExists($cartella);
        $this->assertSame(0700, fileperms($cartella) & 0777, 'i nomi dei file sono gli id di sessione');
        $id = (string)(json_decode(trim($corpo), true)['id'] ?? '');
        $this->assertFileExists($cartella . '/sess_' . $id);
    }

    #[Test]
    public function servendo_pagine_una_cartella_che_non_si_puo_creare_e_un_errore_non_un_ripiego(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('da root ogni cartella si crea');
        }
        mkdir($this->radice . '/bloccata', 0500);

        [$stato, $corpo] = $this->pagina(['PROVA_CARTELLA' => $this->radice . '/bloccata/sessions']);

        $this->assertSame(500, $stato, 'prima si ripiegava in silenzio sulla cartella di PHP');
        $this->assertStringNotContainsString('"id"', $corpo, 'e nessuna sessione è partita');
    }

    #[Test]
    public function servendo_pagine_il_database_scelto_senza_tabella_e_un_errore_e_con_la_tabella_funziona(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['.env', '.env.local'] as $f) {
            if (is_file("$base/$f")) {
                \Dotenv\Dotenv::createMutable($base, $f)->safeLoad();
            }
        }
        Config::load($base . '/app/Config');
        try {
            $tabella = Database::connection()->query("SHOW TABLES LIKE 'sessions'");
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB non disponibile: ' . $e->getMessage());
        }
        $c_e = $tabella !== false && $tabella->fetch() !== false;

        [$stato, $corpo] = $this->pagina(['PROVA_DRIVER' => 'database', 'PROVA_CARTELLA' => '']);

        if ($c_e) {
            // Dove la tabella c'è (un database nato da schema.sql), il gestore su database lavora.
            $this->assertSame(200, $stato, $corpo);
            $id = (string)(json_decode(trim($corpo), true)['id'] ?? '');
            Database::connection()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
        } else {
            $this->assertSame(500, $stato, 'senza la tabella non si ripiega sui file: prima succedeva, e nessuno sapeva dove stavano le sessioni');
        }
    }
}
