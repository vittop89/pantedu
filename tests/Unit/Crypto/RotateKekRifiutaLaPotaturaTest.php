<?php

declare(strict_types=1);

namespace Tests\Unit\Crypto;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `rotate_kek.php --prune-old-kv` si rifiuta prima di leggere la
 * configurazione (23/9/2026).
 *
 * Il perché, misurato sul database, è in
 * tests/Integration/Crypto/PotaturaDelleVersioniTest.php: la potatura
 * cancellava versioni di chiave con cui restano cifrati dati che
 * `--reencrypt` non ricifra. Questa prova, senza database, guarda il punto in
 * cui il rifiuto avviene: prima del bootstrap, quindi senza aprire
 * `.env.local` (sul VPS ogni lettura da una sessione interattiva finisce nel
 * rapporto di auditd) e senza connettersi.
 *
 * Come VistoSenzaSegretiTest: lo script vero, copiato in una cartella
 * temporanea con `vendor` e `app` collegati, lanciato con `open_basedir` che
 * esclude la radice del repository. Il bootstrap cerca `.env` e `.env.local`
 * proprio lì, quindi un tentativo di leggerli lascia un avviso
 * `open_basedir`: assente nel rifiuto, presente nell'altro verso, dove lo
 * script senza `--prune-old-kv` passa oltre e arriva alla configurazione.
 */
final class RotateKekRifiutaLaPotaturaTest extends TestCase
{
    private string $specchio = '';

    protected function setUp(): void
    {
        $repo = \dirname(__DIR__, 3);
        $this->specchio = sys_get_temp_dir() . '/pantedu-rotate-kek-' . bin2hex(random_bytes(6));
        mkdir($this->specchio . '/tools/crypto', 0775, true);
        copy($repo . '/tools/crypto/rotate_kek.php', $this->specchio . '/tools/crypto/rotate_kek.php');
        symlink($repo . '/vendor', $this->specchio . '/vendor');
        symlink($repo . '/app', $this->specchio . '/app');
    }

    protected function tearDown(): void
    {
        if ($this->specchio === '' || !is_dir($this->specchio)) {
            return;
        }
        unlink($this->specchio . '/tools/crypto/rotate_kek.php');
        unlink($this->specchio . '/vendor');
        unlink($this->specchio . '/app');
        rmdir($this->specchio . '/tools/crypto');
        rmdir($this->specchio . '/tools');
        rmdir($this->specchio);
    }

    #[Test]
    public function con_prune_old_kv_si_ferma_senza_leggere_la_configurazione(): void
    {
        $esito = $this->lancia(['--all', '--reencrypt', '--prune-old-kv']);

        self::assertSame(2, $esito['codice'], $esito['errori']);
        self::assertStringContainsString('--prune-old-kv è disattivato', $esito['errori']);
        self::assertStringContainsString('Niente è stato fatto', $esito['errori']);
        self::assertStringNotContainsString('open_basedir', $esito['errori'], 'senza aver cercato i file d\'ambiente');
        self::assertSame('', $esito['uscita'], 'né il riepilogo della rotazione');
    }

    #[Test]
    public function senza_prune_old_kv_passa_oltre_e_arriva_alla_configurazione(): void
    {
        $esito = $this->lancia(['--all', '--reencrypt', '--dry-run']);
        $tutto = $esito['uscita'] . $esito['errori'];

        self::assertStringNotContainsString('--prune-old-kv è disattivato', $tutto);
        self::assertStringContainsString('open_basedir', $tutto, "il bootstrap ha cercato .env e .env.local\n$tutto");
        // Senza file d'ambiente non c'è la chiave master: lo script si ferma
        // lì, prima del database.
        self::assertStringContainsString('KMS_MASTER_KEY mancante', $tutto);
        self::assertSame(1, $esito['codice'], $tutto);
    }

    /**
     * @param list<string> $argomenti
     * @return array{codice: int, uscita: string, errori: string}
     */
    private function lancia(array $argomenti): array
    {
        $repo = \dirname(__DIR__, 3);
        $aperte = implode(PATH_SEPARATOR, [$this->specchio, $repo . '/vendor', $repo . '/app', sys_get_temp_dir()]);
        $comando = array_merge(
            [PHP_BINARY, '-d', 'open_basedir=' . $aperte, '-d', 'display_errors=stderr',
             '-d', 'error_reporting=-1', '-d', 'log_errors=0', $this->specchio . '/tools/crypto/rotate_kek.php'],
            $argomenti,
        );
        $proc = proc_open(
            $comando,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->specchio,
            ['PATH' => (string)getenv('PATH')]
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $uscita = (string)stream_get_contents($pipes[1]);
        $errori = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['codice' => proc_close($proc), 'uscita' => $uscita, 'errori' => $errori];
    }
}
