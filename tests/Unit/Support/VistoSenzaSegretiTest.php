<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `visto.php` lavora sul registro delle anomalie e non tocca i segreti (14/9/2026).
 *
 * Sul VPS auditd annota le letture di `.env.local` da una sessione interattiva
 * e il giro di AIDE della notte dopo le segnala. `visto.php` partiva dal
 * bootstrap dell'applicazione, che quel file lo carica: segnare un'anomalia
 * come vista ne produceva un'altra.
 *
 * La prova lancia lo script vero in un processo a parte, dentro una copia della
 * struttura del repository in una cartella temporanea, con `open_basedir` che
 * **esclude** la radice del repository vero: se lo script, o qualcosa che
 * carica, provasse ad aprire un `.env` o un `.env.local`, PHP lo rifiuterebbe
 * e la prova cadrebbe. Nella copia c'è un `.env.local` che Dotenv non sa
 * leggere: anche aprire quello farebbe cadere la prova. Controprova fatta a
 * mano il 14/9: con il `visto.php` di prima la prova cade.
 */
final class VistoSenzaSegretiTest extends TestCase
{
    private string $specchio = '';

    protected function setUp(): void
    {
        $repo = \dirname(__DIR__, 3);
        $this->specchio = sys_get_temp_dir() . '/pantedu-visto-' . bin2hex(random_bytes(6));
        mkdir($this->specchio . '/tools/ops', 0775, true);
        mkdir($this->specchio . '/dati/storage/logs', 0775, true);
        copy($repo . '/tools/ops/visto.php', $this->specchio . '/tools/ops/visto.php');
        symlink($repo . '/vendor', $this->specchio . '/vendor');
        symlink($repo . '/app', $this->specchio . '/app');
        // Un file d'ambiente che Dotenv rifiuterebbe: se qualcuno lo aprisse, si vedrebbe.
        file_put_contents($this->specchio . '/.env.local', "SEGRETO=\"virgolette mai chiuse\n");
    }

    protected function tearDown(): void
    {
        if ($this->specchio === '' || !is_dir($this->specchio)) {
            return;
        }
        $voci = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->specchio, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($voci as $voce) {
            /** @var \SplFileInfo $voce */
            $voce->isDir() && !$voce->isLink() ? rmdir($voce->getPathname()) : unlink($voce->getPathname());
        }
        rmdir($this->specchio);
    }

    /**
     * Lancia la copia di visto.php. `open_basedir` lascia aperte solo la copia e
     * le due cartelle del repository che servono (vendor e app, dove puntano i
     * collegamenti): la radice del repository, con i suoi file d'ambiente, resta fuori.
     *
     * @param list<string>          $argomenti
     * @param array<string, string> $ambiente
     * @return array{codice: int, uscita: string, errori: string}
     */
    private function lancia(array $argomenti, array $ambiente = []): array
    {
        $repo = \dirname(__DIR__, 3);
        $aperte = implode(PATH_SEPARATOR, [$this->specchio, $repo . '/vendor', $repo . '/app']);
        $comando = array_merge(
            [PHP_BINARY, '-d', 'open_basedir=' . $aperte, '-d', 'display_errors=stderr',
             '-d', 'error_reporting=-1', $this->specchio . '/tools/ops/visto.php'],
            $argomenti,
        );
        $env = array_merge(['PATH' => (string)getenv('PATH'), 'USER' => 'prova'], $ambiente);
        $proc = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->specchio, $env);
        $this->assertIsResource($proc);
        $uscita = (string)stream_get_contents($pipes[1]);
        $errori = (string)stream_get_contents($pipes[2]);
        return ['codice' => proc_close($proc), 'uscita' => $uscita, 'errori' => $errori];
    }

    private function registro(string $riga): void
    {
        file_put_contents($this->specchio . '/dati/storage/logs/anomalie.jsonl', $riga . "\n", FILE_APPEND);
    }

    #[Test]
    public function senza_la_cartella_dei_dati_e_con_un_env_local_accanto_si_ferma_senza_leggerlo(): void
    {
        $esito = $this->lancia([]);

        $this->assertSame(2, $esito['codice'], "non sa dove sta il registro: si ferma\n" . $esito['errori']);
        $this->assertStringContainsString('non legge .env.local', $esito['errori']);
        $this->assertStringContainsString('PANTEDU_DATA_PATH=', $esito['errori'], 'e dice come lanciarlo');
        $this->assertStringNotContainsString('open_basedir', $esito['errori'], 'senza aver provato ad aprire niente fuori');
        $this->assertStringNotContainsString('Dotenv', $esito['errori'], 'e senza aver letto il file');
    }

    #[Test]
    public function con_la_cartella_dei_dati_elenca_segna_e_mostra_i_livelli_senza_i_file_d_ambiente(): void
    {
        $quando = date('c', time() - 3600);
        $this->registro(json_encode(['quando' => $quando, 'codice' => 'audit_segreti_letti', 'cosa' => 'prova'], JSON_THROW_ON_ERROR));
        $dati = ['PANTEDU_DATA_PATH' => $this->specchio . '/dati'];

        $elenco = $this->lancia([], $dati);
        $this->assertSame(0, $elenco['codice'], $elenco['errori']);
        $this->assertStringContainsString('audit_segreti_letti', $elenco['uscita'], "elenca l'anomalia nuova");
        $this->assertStringContainsString('PANTEDU_DATA_PATH=' . $this->specchio . '/dati php tools/ops/visto.php --tutto', $elenco['uscita'], 'con il comando giusto');

        // Dal 22/9/2026 la motivazione è obbligatoria: senza, lo script non
        // segna niente (prova dedicata in AnomaliaVisteTest).
        $motivo = 'letture di .env.local dal mio giro di verifica delle 00:10, attribuite una per una';
        $segna = $this->lancia(['audit_segreti_letti', '--perche=' . $motivo], $dati);
        $this->assertSame(0, $segna['codice'], $segna['errori']);
        $this->assertStringContainsString('segnato: audit_segreti_letti', $segna['uscita']);
        $visti = json_decode((string)file_get_contents($this->specchio . '/dati/storage/logs/anomalie.jsonl.visti'), true);
        $this->assertSame($quando, $visti['audit_segreti_letti']['fino_a'] ?? null, "il livello d'acqua è l'ultima occorrenza, nel registro giusto");
        $this->assertSame($motivo, $visti['audit_segreti_letti']['perche'] ?? null, 'e il perché resta scritto');

        $stato = $this->lancia(['--stato'], $dati);
        $this->assertSame(0, $stato['codice'], $stato['errori']);
        $this->assertStringContainsString('audit_segreti_letti', $stato['uscita']);

        foreach ([$elenco, $segna, $stato] as $giro) {
            $this->assertStringNotContainsString('open_basedir', $giro['errori'], 'nessun tentativo di aprire file fuori dalla copia');
            $this->assertStringNotContainsString('Dotenv', $giro['errori']);
        }
    }

    #[Test]
    public function una_cartella_dei_dati_sbagliata_non_ne_crea_una_nuova(): void
    {
        $sbagliata = $this->specchio . '/dati-scritta-male';

        $esito = $this->lancia(['--tutto'], ['PANTEDU_DATA_PATH' => $sbagliata]);

        $this->assertSame(2, $esito['codice'], $esito['errori']);
        $this->assertStringContainsString('la cartella dei dati è sbagliata', $esito['errori']);
        $this->assertDirectoryDoesNotExist($sbagliata, 'un registro vuoto risponderebbe «niente di nuovo»');
    }
}
