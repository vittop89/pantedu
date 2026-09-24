<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PacchettoZip;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Il pacchetto ZIP non resta su disco.
 *
 * Decisione dell'utente del 21/9/2026 sulle esportazioni: finché l'archivio
 * veniva scritto in una cartella e consegnato per indirizzo, c'era un file da
 * sorvegliare, una durata da promettere e una rotta che lo dava a *un* docente
 * qualunque. Adesso l'archivio vive quanto la richiesta.
 *
 * Qui si misura l'unica cosa che conta davvero — che non resti niente — nei due
 * versi: quando tutto va bene e quando qualcosa si rompe a metà. Il secondo
 * caso è quello che di solito non si prova, ed è quello che lascia i file.
 */
final class PacchettoZipTest extends TestCase
{
    /** I file temporanei presenti adesso, per confrontarli dopo. */
    private function temporanei(): array
    {
        return array_values(array_filter(
            glob(sys_get_temp_dir() . '/zzprova_*') ?: [],
            static fn(string $f): bool => is_file($f),
        ));
    }

    #[Test]
    public function restituisce_un_archivio_vero(): void
    {
        $byte = PacchettoZip::byte(static function (ZipArchive $zip): void {
            $zip->addFromString('main.tex', '\\documentclass{article}');
            $zip->addFromString('texCommon/risdoc.sty', '% stile');
        }, 'zzprova_');

        self::assertSame('PK', substr($byte, 0, 2), 'i primi due byte di uno ZIP');
        self::assertGreaterThan(100, strlen($byte));

        // E si riapre davvero, coi file dentro.
        $tmp = tempnam(sys_get_temp_dir(), 'zzverifica_');
        file_put_contents($tmp, $byte);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($tmp) === true, 'l\'archivio si riapre');
        self::assertSame(2, $zip->numFiles);
        self::assertNotFalse($zip->locateName('main.tex'));
        self::assertNotFalse($zip->locateName('texCommon/risdoc.sty'));
        $zip->close();
        @unlink($tmp);
    }

    #[Test]
    public function quando_va_bene_non_resta_niente(): void
    {
        $prima = $this->temporanei();

        PacchettoZip::byte(static function (ZipArchive $zip): void {
            $zip->addFromString('main.tex', 'x');
        }, 'zzprova_');

        self::assertSame($prima, $this->temporanei(), 'nessun file temporaneo in più');
    }

    #[Test]
    public function quando_si_rompe_a_meta_non_resta_niente_lo_stesso(): void
    {
        // È il verso che di solito nessuno prova, ed è quello che lascia i file
        // in giro: se la costruzione fallisce dopo aver aperto l'archivio.
        $prima = $this->temporanei();

        try {
            PacchettoZip::byte(static function (ZipArchive $zip): void {
                $zip->addFromString('main.tex', 'x');
                throw new RuntimeException('il modello non si costruisce');
            }, 'zzprova_');
            self::fail('l\'eccezione doveva arrivare al chiamante');
        } catch (RuntimeException $e) {
            self::assertSame('il modello non si costruisce', $e->getMessage(),
                'l\'errore vero non si perde per strada');
        }

        self::assertSame($prima, $this->temporanei(), 'nessun file temporaneo in più');
    }

    #[Test]
    public function il_nome_del_file_si_puo_scrivere_in_un_intestazione(): void
    {
        // Le intestazioni parlano ISO-8859-1: un accento grezzo lì dentro fa
        // fallire la risposta, non il nome del file.
        self::assertSame('Relazione_finale_3a.zip', PacchettoZip::nomeSicuro('Relazione finale 3ª'));
        self::assertSame('Piano_annuale.zip', PacchettoZip::nomeSicuro('Piano annuale.zip'));
        self::assertSame('perche.zip', PacchettoZip::nomeSicuro('perché'));
        self::assertSame('pacchetto.zip', PacchettoZip::nomeSicuro(''), 'mai un nome vuoto');
        self::assertSame('pacchetto.zip', PacchettoZip::nomeSicuro('«»'), 'mai un nome fatto di niente');

        foreach (['Relazione finale 3ª', 'perché', 'a"b', "a\nb", '«»'] as $brutto) {
            $nome = PacchettoZip::nomeSicuro($brutto);
            self::assertSame(1, preg_match('/^[A-Za-z0-9._-]+\.zip$/', $nome), "nome: $nome");
        }
    }

    #[Test]
    public function le_intestazioni_dicono_quanto_e_lungo_e_di_non_conservarlo(): void
    {
        $byte = 'PK' . str_repeat('x', 500);
        $h = PacchettoZip::intestazioni('Piano annuale', $byte);

        self::assertSame('application/zip', $h['Content-Type']);
        self::assertSame('attachment; filename="Piano_annuale.zip"', $h['Content-Disposition']);
        self::assertSame((string)strlen($byte), $h['Content-Length']);
        self::assertStringContainsString('no-store', $h['Cache-Control'],
            'un documento di scuola non resta nella cache di un computer condiviso');
    }
}
