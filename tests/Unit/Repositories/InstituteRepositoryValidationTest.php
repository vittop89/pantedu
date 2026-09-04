<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\InstituteRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 25.R.1.1 — Validation guard tests for InstituteRepository.
 *
 * I metodi assert* sono private; usiamo Reflection per testarli direttamente
 * senza dipendere da DB (upsert() richiede Database::connection()).
 *
 * Background: OWASP ZAP fuzz aveva inserito ~64 entries malformi in tabella
 * institutes (path traversal, SQL injection, PowerShell payload). I guard
 * impediscono nuove pollution.
 */
final class InstituteRepositoryValidationTest extends TestCase
{
    private InstituteRepository $repo;
    private \ReflectionMethod $code;
    private \ReflectionMethod $name;
    private \ReflectionMethod $loc;

    protected function setUp(): void
    {
        $this->repo = new InstituteRepository();
        $ref = new ReflectionClass($this->repo);
        $this->code = $ref->getMethod('assertValidCode');
        $this->code->setAccessible(true);
        $this->name = $ref->getMethod('assertValidName');
        $this->name->setAccessible(true);
        $this->loc = $ref->getMethod('assertValidLocation');
        $this->loc->setAccessible(true);
    }

    // ── code ───────────────────────────────────────────────────

    public function testValidCodesAccepted(): void
    {
        $valid = [
            'AB',
            'ITIS-MAGGI',
            'LSEsempio',
            'MIUR-LICEO-MILANO',
            'A1_B2-C3',
            str_repeat('A', 40),
        ];
        foreach ($valid as $c) {
            $this->code->invoke($this->repo, $c);
        }
        $this->assertTrue(true);
    }

    public function testCodeRejectsPathTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/institute_code_invalid/');
        $this->code->invoke($this->repo, '../../etc/passwd');
    }

    public function testCodeRejectsShellInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->code->invoke($this->repo, ';start-sleep -s 15');
    }

    public function testCodeRejectsSqlInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->code->invoke($this->repo, "1' OR '1'='1");
    }

    public function testCodeRejectsTooShort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->code->invoke($this->repo, 'A');
    }

    public function testCodeRejectsTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->code->invoke($this->repo, str_repeat('A', 41));
    }

    public function testCodeRejectsHtmlChars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->code->invoke($this->repo, '<script>');
    }

    // ── name ───────────────────────────────────────────────────

    public function testValidNamesAccepted(): void
    {
        $valid = [
            'Liceo Scientifico di Esempio',
            'ITIS Maggi - Lecco',
            'Istituto Comprensivo "Falcone"',
            'Scuola Media (sede succursale)',
            'École française',
        ];
        foreach ($valid as $n) {
            $this->name->invoke($this->repo, $n);
        }
        $this->assertTrue(true);
    }

    public function testNameRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->name->invoke($this->repo, '');
    }

    public function testNameRejectsTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->name->invoke($this->repo, str_repeat('A', 201));
    }

    public function testNameRejectsXssPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->name->invoke($this->repo, '<script>alert(1)</script>');
    }

    public function testNameRejectsShellPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->name->invoke($this->repo, ';get-help');
    }

    public function testNameRejectsTemplateInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->name->invoke($this->repo, 'Test${jndi:ldap://x}');
    }

    public function testNameRejectsHtmlComment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->name->invoke($this->repo, 'foo<!-- bar -->');
    }

    public function testNameRejectsPathTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->name->invoke($this->repo, '../../../Windows/system.ini');
    }

    public function testNameRejectsBackticks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->name->invoke($this->repo, 'foo`whoami`');
    }

    // ── location ───────────────────────────────────────────────

    public function testValidCityAccepted(): void
    {
        $this->loc->invoke($this->repo, 'Lecco', 'city');
        $this->loc->invoke($this->repo, 'Reggio nell\'Emilia', 'city');
        $this->assertTrue(true);
    }

    public function testCityRejectsInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->loc->invoke($this->repo, '<svg onload=1>', 'city');
    }

    public function testRegionRejectsTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->loc->invoke($this->repo, str_repeat('A', 101), 'region');
    }
}
