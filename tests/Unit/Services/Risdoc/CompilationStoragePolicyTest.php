<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Risdoc;

use App\Services\Risdoc\CompilationStoragePolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Solo i modelli istituzionali (categoria `modelli`) sono soggetti alla scelta dell'Istituto. */
final class CompilationStoragePolicyTest extends TestCase
{
    #[Test]
    public function la_categoria_modelli_e_istituzionale_le_altre_no(): void
    {
        self::assertTrue(CompilationStoragePolicy::isInstitutional(['category' => 'modelli']));
        self::assertFalse(CompilationStoragePolicy::isInstitutional(['category' => 'risorse']));
        self::assertFalse(CompilationStoragePolicy::isInstitutional(['category' => 'altro']));
        self::assertFalse(CompilationStoragePolicy::isInstitutional(['category' => 'bes']));
        self::assertFalse(CompilationStoragePolicy::isInstitutional([]));
    }

    #[Test]
    public function un_modello_non_istituzionale_si_salva_sempre_senza_toccare_il_db(): void
    {
        // Nessuna connessione: allowedFor non deve nemmeno interrogare il DB.
        self::assertTrue(CompilationStoragePolicy::allowedFor(0, ['category' => 'risorse']));
        self::assertTrue(CompilationStoragePolicy::allowedFor(12345, ['category' => 'risorse']));
    }

    #[Test]
    public function senza_docente_non_c_e_istituto_che_possa_negare(): void
    {
        self::assertFalse(CompilationStoragePolicy::storageDisabledForTeacher(0));
    }
}
