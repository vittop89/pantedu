<?php

declare(strict_types=1);

/**
 * Phase 26 — Validate OpenAPI spec against 3.1 schema.
 *
 * Usage: composer openapi:validate
 */

require __DIR__ . '/../../vendor/autoload.php';

use League\OpenAPIValidation\PSR7\ValidatorBuilder;

$file = __DIR__ . '/../../docs/api/openapi.full.yaml';

if (!is_file($file)) {
    fprintf(STDERR, "[validate] file not found: $file\n");
    fprintf(STDERR, "[validate] run: composer openapi:build\n");
    exit(1);
}

try {
    $builder = (new ValidatorBuilder())->fromYamlFile($file);
    $builder->getRoutedRequestValidator();
    fwrite(STDOUT, "[validate] OpenAPI VALID — $file\n");
    exit(0);
} catch (\Throwable $e) {
    fprintf(STDERR, "[validate] INVALID: %s\n", $e->getMessage());
    exit(1);
}
