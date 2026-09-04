<?php

namespace App\Services\Contract;

use JsonSchema\Validator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Phase 19 — Validator JSON-Schema per contract `pantedu.content.v1`.
 *
 * Uso soft-fail: `validate()` ritorna lista errori senza throw. Il caller
 * decide se loggare/abortire. Integrato in ContractAggregate::load per
 * loggare warning su contract legacy non conformi senza rompere il flow.
 */
final class ContractSchemaValidator
{
    private static ?object $schemaCache = null;

    public function __construct(
        private readonly string $schemaPath = '',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return list<string> errori strutturati (empty = valid)
     */
    public function validate(array $contract): array
    {
        $schema = $this->loadSchema();
        if ($schema === null) {
            return ['schema_unavailable'];
        }

        $data = \json_decode(\json_encode($contract, JSON_UNESCAPED_UNICODE), false);
        $validator = new Validator();
        $validator->validate($data, $schema);

        if ($validator->isValid()) {
            return [];
        }

        $errors = [];
        foreach ($validator->getErrors() as $err) {
            $path = $err['property'] ?: '(root)';
            $errors[] = "$path: {$err['message']}";
        }
        return $errors;
    }

    public function isValid(array $contract): bool
    {
        return $this->validate($contract) === [];
    }

    private function loadSchema(): ?object
    {
        if (self::$schemaCache !== null) {
            return self::$schemaCache;
        }
        $path = $this->schemaPath !== ''
            ? $this->schemaPath
            : \dirname(__DIR__, 3) . '/schemas/pantedu.content.v1.json';
        if (!\is_file($path)) {
            $this->logger->warning('contract_schema_missing', ['path' => $path]);
            return null;
        }
        $raw = \file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $schema = \json_decode($raw);
        return self::$schemaCache = \is_object($schema) ? $schema : null;
    }

    /** Reset cache (utile nei test). */
    public static function clearCache(): void
    {
        self::$schemaCache = null;
    }
}
