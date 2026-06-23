<?php

declare(strict_types=1);

namespace App\Services\Risdoc\Pt;

use JsonSchema\Validator;

/**
 * Validator Portable Text (Risdoc subset) — Phase 22.2.
 *
 * Wrapper su `justinrainbow/json-schema` (già in composer). Carica lo schema
 * statico da `schemas/risdoc/_pt/portable-text.schema.json` e restituisce
 * report con errori localizzati.
 *
 * Use:
 * ```php
 * $result = PtValidator::validate($ptArray);
 * if (!$result['valid']) { throw new RuntimeException(implode('; ', $result['errors'])); }
 *
 * // Shortcut con exception:
 * PtValidator::assertValid($ptArray);
 * ```
 *
 * Schema cached via static (1 file read per-process).
 */
final class PtValidator
{
    /** @var object|null cached JSON schema */
    private static ?object $schemaCache = null;
/**
     * @param array<int, array<string, mixed>> $pt Portable Text root array
     * @return array{valid: bool, errors: list<string>}
     */
    public static function validate(array $pt): array
    {
        $validator = new Validator();
// justinrainbow/json-schema opera su stdClass, non su assoc array.
        // Re-encoding con depth safe è il modo idiomatico.
        $data = json_decode(json_encode($pt, JSON_THROW_ON_ERROR));
        $validator->validate($data, self::schema());
        if ($validator->isValid()) {
            return ['valid' => true, 'errors' => []];
        }

        $errors = [];
        foreach ($validator->getErrors() as $err) {
            $property = $err['property'] !== '' ? $err['property'] : '(root)';
            $errors[] = \sprintf('[%s] %s', $property, $err['message']);
        }
        return ['valid' => false, 'errors' => $errors];
    }

    /**
     * Come `validate()` ma throw su invalid. Utile nei setter di entità.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValid(array $pt): void
    {
        $result = self::validate($pt);
        if (!$result['valid']) {
            throw new \InvalidArgumentException('Portable Text validation failed: ' . implode('; ', $result['errors']),);
        }
    }

    private static function schema(): object
    {
        if (self::$schemaCache !== null) {
            return self::$schemaCache;
        }
        // PtValidator.php live in app/Services/Risdoc/Pt/ → 4 livelli up = project root.
        $path = dirname(__DIR__, 4) . '/schemas/risdoc/_pt/portable-text.schema.json';
        $raw  = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Schema Portable Text non leggibile: $path");
        }
        $decoded = json_decode($raw, false, flags: JSON_THROW_ON_ERROR);
        if (!\is_object($decoded)) {
            throw new \RuntimeException('Schema Portable Text malformato');
        }
        return self::$schemaCache = $decoded;
    }

    /** Reset cache — utile nei test. */
    public static function flushCache(): void
    {
        self::$schemaCache = null;
    }
}
