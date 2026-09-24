<?php

declare(strict_types=1);

namespace Tests\Unit\Crypto;

use App\Services\Crypto\TeacherCryptoService;
use App\Services\PdfImport\PdfImportContext;
use App\Services\PdfImport\ProviderKeyStore;
use App\Services\PdfImport\Session\SessionStorage;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Unit\Contract\InMemoryStorageProvider;

/**
 * L'import dei PDF e la chiave madre (23/9/2026, revisione architetturale
 * A-36).
 *
 * `SessionStorage` (le pagine e i JSON di una sessione d'import) e
 * `ProviderKeyStore` (le chiavi API del docente) cifrano con la chiave del
 * docente; se la cifratura non riesce ripiegano sul chiaro, ma solo in
 * sviluppo, senza chiave. Prima guardavano da sé che `KMS_MASTER_KEY` non
 * fosse vuota, ora chiedono a `ChiaveMadre` se è presente. Una chiave
 * presente ma malformata deve fermarli come una valida: se la domanda
 * diventasse «è valida?», una chiave sbagliata in produzione farebbe scrivere
 * in chiaro le pagine dei PDF e le chiavi API. Nessuna prova copriva i due
 * ripieghi.
 *
 * La controprova: senza chiave il ripiego c'è ancora, quindi il «no» delle
 * prove con la chiave malformata non viene dall'impianto.
 */
final class ImportPdfConLaChiaveMadreTest extends TestCase
{
    private const VARIABILE = 'KMS_MASTER_KEY';
    private const DOCENTE = 7;

    /** @var array{env:bool, envValore:mixed} */
    private array $prima;
    private int $docentePrima = 0;
    private string $cartella = '';

    protected function setUp(): void
    {
        $this->prima = [
            'env'       => \array_key_exists(self::VARIABILE, $_ENV),
            'envValore' => $_ENV[self::VARIABILE] ?? null,
        ];
        unset($_ENV[self::VARIABILE]);
        $this->docentePrima = PdfImportContext::teacherId();
        PdfImportContext::setTeacher(self::DOCENTE);
        $this->cartella = sys_get_temp_dir() . '/pantedu-import-chiave-' . bin2hex(random_bytes(6));
        mkdir($this->cartella, 0700);
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::VARIABILE]);
        if ($this->prima['env']) {
            $_ENV[self::VARIABILE] = $this->prima['envValore'];
        }
        PdfImportContext::setTeacher($this->docentePrima);
        foreach (glob($this->cartella . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cartella);
    }

    #[Test]
    public function senza_chiave_si_scrive_in_chiaro_come_in_sviluppo(): void
    {
        $memoria = new InMemoryStorageProvider();
        (new SessionStorage($memoria, new TeacherCryptoService()))
            ->putJson('sessione', 'raw.json', ['pagina' => 1], self::DOCENTE);
        self::assertSame('{"pagina":1}', $memoria->raw('sessione/raw.json'));

        $file = $this->cartella . '/keys.json';
        (new ProviderKeyStore($file, new TeacherCryptoService()))->set('openai', 'zz-chiave-api-di-prova');
        self::assertStringContainsString('zz-chiave-api-di-prova', (string)file_get_contents($file));
    }

    #[Test]
    #[DataProviderExternal(ChiaveMadreTest::class, 'malformate')]
    public function con_una_chiave_malformata_le_pagine_dell_import_non_si_scrivono_in_chiaro(string $valore): void
    {
        $_ENV[self::VARIABILE] = $valore;
        $memoria = new InMemoryStorageProvider();
        $archivio = new SessionStorage($memoria, new TeacherCryptoService());

        $errore = null;
        try {
            $archivio->putSourcePdf('sessione', '%PDF-1.4 zz', self::DOCENTE);
        } catch (RuntimeException $e) {
            $errore = $e;
        }

        self::assertNotNull($errore, 'il PDF si è scritto in chiaro');
        self::assertStringContainsString('kms_not_configured', $errore->getMessage());
        self::assertSame([], $memoria->listPrefix('sessione'), 'e nell\'archivio non c\'è niente');
    }

    #[Test]
    #[DataProviderExternal(ChiaveMadreTest::class, 'malformate')]
    public function con_una_chiave_malformata_le_chiavi_api_non_si_scrivono_in_chiaro(string $valore): void
    {
        $_ENV[self::VARIABILE] = $valore;
        $file = $this->cartella . '/keys.json';

        $errore = null;
        try {
            (new ProviderKeyStore($file, new TeacherCryptoService()))->set('openai', 'zz-chiave-api-di-prova');
        } catch (RuntimeException $e) {
            $errore = $e;
        }

        self::assertNotNull($errore, 'la chiave API si è scritta in chiaro');
        self::assertStringContainsString('kms_not_configured', $errore->getMessage());
        self::assertFileDoesNotExist($file);
    }
}
