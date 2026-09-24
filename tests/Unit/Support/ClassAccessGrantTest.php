<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ClassAccessGrant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** ADR-032 — normalizzazione del grant di classe (parte pura, senza sessione). */
final class ClassAccessGrantTest extends TestCase
{
    #[Test]
    public function un_grant_senza_docente_non_vale(): void
    {
        self::assertNull(ClassAccessGrant::fromArray([]));
        self::assertNull(ClassAccessGrant::fromArray(['teacher_id' => 0, 'classe' => '1A']));
        self::assertNull(ClassAccessGrant::fromArray(['teacher_id' => 'abc']));
    }

    #[Test]
    public function i_campi_vuoti_diventano_null_e_l_istituto_e_intero(): void
    {
        $g = ClassAccessGrant::fromArray([
            'teacher_id'   => '7',
            'institute_id' => '0',
            'indirizzo'    => '  ',
            'classe'       => null,
            'label'        => ' 1A mattina ',
        ]);
        self::assertNotNull($g);
        self::assertSame(7, $g['teacher_id']);
        self::assertNull($g['institute_id']);
        self::assertNull($g['indirizzo']);
        self::assertNull($g['classe']);
        self::assertSame('1A mattina', $g['label']);
        self::assertSame('teacher_access_credentials', $g['source']);
    }

    #[Test]
    public function una_credenziale_delimitata_conserva_classe_e_istituto(): void
    {
        $g = ClassAccessGrant::fromArray([
            'teacher_id' => 3, 'institute_id' => 12, 'indirizzo' => 'SCI', 'classe' => '3A',
            'source' => 'teacher_access_credentials',
        ]);
        self::assertSame(['institute_id' => 12, 'indirizzo' => 'SCI', 'classe' => '3A'], [
            'institute_id' => $g['institute_id'], 'indirizzo' => $g['indirizzo'], 'classe' => $g['classe'],
        ]);
    }
}
