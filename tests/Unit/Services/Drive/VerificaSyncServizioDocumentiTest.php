<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Drive;

use App\Services\Crypto\EncryptedBlobStore;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\Drive\VerificaSyncService;
use App\Services\Verifica\VerificaDocumentService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La sincronizzazione delle verifiche a più file ricompone il .tex con il deposito
 * cifrato giusto (15/9/2026).
 *
 * Fino a quel giorno il deposito si passava a VerificaDocumentService come terzo
 * argomento, cioè al posto della transazione: ogni verifica a più file finiva in
 * un TypeError, nel registro di produzione. PHPStan lo vedeva e la sua
 * segnalazione stava nella baseline.
 */
final class VerificaSyncServizioDocumentiTest extends TestCase
{
    #[Test]
    public function il_servizio_dei_documenti_usa_il_deposito_della_sincronizzazione(): void
    {
        $deposito = new EncryptedBlobStore('verifiche_enc', new TeacherCryptoService(bin2hex(random_bytes(32))), sys_get_temp_dir());
        $sync = new VerificaSyncService(null, null, $deposito);

        $metodo = new \ReflectionMethod(VerificaSyncService::class, 'servizioDocumenti');
        $servizio = $metodo->invoke($sync);

        $this->assertInstanceOf(VerificaDocumentService::class, $servizio);
        $this->assertSame($deposito, (new \ReflectionProperty(VerificaDocumentService::class, 'store'))->getValue($servizio));
    }
}
