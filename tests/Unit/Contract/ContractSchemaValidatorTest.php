<?php

namespace Tests\Unit\Contract;

use App\Services\Contract\ContractSchemaValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContractSchemaValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        ContractSchemaValidator::clearCache();
    }

    #[Test]
    public function accepts_minimal_valid_contract(): void
    {
        $v = new ContractSchemaValidator();
        $errors = $v->validate([
            '$schema' => 'pantedu.content.v1',
            'groups'  => [],
        ]);
        $this->assertSame([], $errors);
    }

    #[Test]
    public function rejects_missing_schema_field(): void
    {
        $v = new ContractSchemaValidator();
        $errors = $v->validate([
            'groups' => [],
        ]);
        $this->assertNotEmpty($errors);
    }

    #[Test]
    public function rejects_wrong_schema_constant(): void
    {
        $v = new ContractSchemaValidator();
        $errors = $v->validate([
            '$schema' => 'wrong.schema.v1',
            'groups'  => [],
        ]);
        $this->assertNotEmpty($errors);
    }

    #[Test]
    public function rejects_invalid_group_type_enum(): void
    {
        $v = new ContractSchemaValidator();
        $errors = $v->validate([
            '$schema' => 'pantedu.content.v1',
            'groups'  => [
                ['type' => 'Pluto'],
            ],
        ]);
        $this->assertNotEmpty($errors);
    }

    #[Test]
    public function accepts_full_contract_with_items(): void
    {
        $v = new ContractSchemaValidator();
        $errors = $v->validate([
            '$schema' => 'pantedu.content.v1',
            'title'   => 'Forze',
            'version' => 3,
            'groups'  => [
                [
                    'kind'  => 'problem-group',
                    'type'  => 'Collect',
                    'id'    => 'g1',
                    'title' => 'Esercizi',
                    'items' => [
                        ['id' => 'uuid-1', 'question' => [['type' => 'text', 'content' => 'Q1']]],
                    ],
                ],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    #[Test]
    public function isValid_returns_bool(): void
    {
        $v = new ContractSchemaValidator();
        $this->assertTrue($v->isValid(['$schema' => 'pantedu.content.v1', 'groups' => []]));
        $this->assertFalse($v->isValid(['groups' => []]));
    }
}
