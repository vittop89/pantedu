<?php

declare(strict_types=1);

namespace Tests\Unit\Ops;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Il controllo `lavori` della diagnostica e la cartella dei salvataggi
 * cifrati, con lo script vero in un processo a parte (23/9/2026).
 *
 * Il difetto (revisione architetturale del 23/9/2026, A-35; voce 71 del
 * registro del debito): la diagnostica leggeva `$_ENV['BACKUP_DIR'] ?? …`.
 * Con `BACKUP_DIR=` vuota, come in `.env.example`, lo schema diventava
 * `/pantedu-backup-*.tar.gpg`: la radice del disco esiste e si legge, i file
 * lì non ci sono, e il controllo dava «nessun file prodotto» a ogni giro —
 * mentre `tools/backup/encrypted_backup.sh` scriveva in /var/backups/pantedu.
 * Un allarme fisso insegna a ignorare gli allarmi.
 *
 * Adesso la cartella viene da `backup.dir` (app/Config/backup.php), che con
 * la variabile vuota vale il predefinito dello script. Si prova nei due versi:
 * vuota → nessun allarme sulla radice; una cartella vera e vuota → l'allarme
 * c'è, e nomina quella cartella; con un salvataggio di oggi → nessun allarme.
 *
 * I dati si fingono fuori dal repository (un'installazione vera: il controllo
 * `lavori` altrove non gira). La variabile si imposta dopo il bootstrap e la
 * configurazione si ricarica: `.env.local` (caricato con `createMutable`)
 * scavalcherebbe l'ambiente del processo.
 */
final class DiagnosticaSalvataggioCifratoTest extends TestCase
{
    private string $cartella = '';

    protected function setUp(): void
    {
        $this->cartella = sys_get_temp_dir() . '/pantedu-salvataggi-' . bin2hex(random_bytes(6));
        // `storage/logs` dei dati non c'è: i lavori che ci scrivono (versioni,
        // integrità) si saltano, e restano da guardare i salvataggi.
        mkdir($this->cartella . '/dati/storage', 0700, true);
        mkdir($this->cartella . '/registri', 0700, true);
        mkdir($this->cartella . '/salvataggi', 0700, true);
        file_put_contents($this->cartella . '/lancia.php', <<<'PHP'
<?php
$repo = (string)getenv('PROVA_REPO');
require_once $repo . '/app/bootstrap.php';
$dati = (string)getenv('PROVA_DATI');
\App\Core\Config::set('app.paths.data_base', $dati);
\App\Core\Config::set('app.paths.storage', $dati . '/storage');
\App\Core\Config::set('app.paths.logs', (string)getenv('PROVA_REGISTRI'));
$_ENV['BACKUP_DIR'] = (string)getenv('PROVA_BACKUP_DIR');
\App\Core\Config::set('backup', require $repo . '/app/Config/backup.php');
ini_set('display_errors', 'stderr');
$argv = ['diagnostica.php', '--json', '--solo=lavori'];
require $repo . '/tools/ops/diagnostica.php';
PHP);
    }

    protected function tearDown(): void
    {
        $this->svuota($this->cartella);
    }

    private function svuota(string $dir): void
    {
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (in_array(basename($f), ['.', '..'], true)) {
                continue;
            }
            is_dir($f) && !is_link($f) ? $this->svuota($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    /** @return array{esito: string, prova: string, uscita: string} */
    private function lavori(string $backupDir): array
    {
        $env = array_merge(getenv(), [
            'PROVA_REPO'       => \dirname(__DIR__, 3),
            'PROVA_DATI'       => $this->cartella . '/dati',
            'PROVA_BACKUP_DIR' => $backupDir,
            'PROVA_REGISTRI'   => $this->cartella . '/registri',
        ]);
        $proc = proc_open(
            [PHP_BINARY, $this->cartella . '/lancia.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tubi,
            $this->cartella,
            $env,
        );
        self::assertIsResource($proc);
        $uscita = (string)stream_get_contents($tubi[1]);
        $errori = (string)stream_get_contents($tubi[2]);
        proc_close($proc);

        $json = json_decode($uscita, true);
        self::assertIsArray($json, "la diagnostica stampa JSON: {$uscita}{$errori}");
        foreach ($json['controlli'] ?? [] as $c) {
            if (($c['nome'] ?? '') === 'lavori') {
                return ['esito' => (string)$c['esito'], 'prova' => (string)$c['prova'], 'uscita' => $uscita . $errori];
            }
        }
        self::fail("nessun controllo `lavori` nell'uscita: {$uscita}{$errori}");
    }

    #[Test]
    public function backup_dir_vuota_non_cerca_i_salvataggi_alla_radice(): void
    {
        $r = $this->lavori('');

        // Sul codice di prima: «salvataggio cifrato: nessun file prodotto
        // (/pantedu-backup-*.tar.gpg)», a ogni giro.
        self::assertStringNotContainsString('(/pantedu-backup-', $r['prova'], $r['uscita']);
    }

    #[Test]
    public function una_cartella_senza_salvataggi_e_un_guasto_che_la_nomina(): void
    {
        $cartella = $this->cartella . '/salvataggi';
        $r = $this->lavori($cartella);

        self::assertSame('guasto', $r['esito'], $r['uscita']);
        self::assertStringContainsString(
            "salvataggio cifrato: nessun file prodotto ({$cartella}/pantedu-backup-*.tar.gpg)",
            $r['prova'],
        );
    }

    #[Test]
    public function un_salvataggio_di_oggi_non_e_un_guasto(): void
    {
        $cartella = $this->cartella . '/salvataggi';
        file_put_contents($cartella . '/pantedu-backup-' . date('Ymd') . '.tar.gpg', 'x');
        $r = $this->lavori($cartella);

        self::assertSame('regge', $r['esito'], $r['uscita']);
        self::assertStringContainsString('salvataggio cifrato: pantedu-backup-', $r['prova']);
        self::assertStringContainsString('di oggi', $r['prova']);
    }
}
