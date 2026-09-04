<?php

declare(strict_types=1);

namespace Tests\Unit\PdfImport;

use App\Services\PdfImport\PiiMasker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PiiMaskerTest extends TestCase
{
    #[Test]
    public function masks_codice_fiscale_and_email(): void
    {
        $in = 'Studente RSSMRA85T10A562S, contatto mario.rossi@example.it per info.';
        $count = 0;
        $out = PiiMasker::mask($in, $count);

        $this->assertStringContainsString('[CF_REDATTO]', $out);
        $this->assertStringContainsString('[EMAIL_REDATTA]', $out);
        $this->assertStringNotContainsString('RSSMRA85T10A562S', $out);
        $this->assertStringNotContainsString('mario.rossi@example.it', $out);
        $this->assertSame(2, $count);
    }

    #[Test]
    public function leaves_clean_text_untouched(): void
    {
        $in = 'Calcola il limite di \\(f(x)\\) per \\(x \\to 0\\).';
        $count = 0;
        $out = PiiMasker::mask($in, $count);
        $this->assertSame($in, $out);
        $this->assertSame(0, $count);
    }

    #[Test]
    public function has_pii_detects_presence(): void
    {
        $this->assertTrue(PiiMasker::hasPii('email: a.b@c.it'));
        $this->assertTrue(PiiMasker::hasPii('CF RSSMRA85T10A562S'));
        $this->assertFalse(PiiMasker::hasPii('nessun dato personale qui'));
    }
}
