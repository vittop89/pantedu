<?php

namespace App\Support;

use RuntimeException;

/**
 * Typed, fail-fast input extractor.
 * Usage:
 *     $v = new Validator($_POST);
 *     $name = $v->string('fileName', max: 255);
 *     $data = $v->string('content');
 *     $dir  = $v->string('dir', regex: '#^[A-Za-z0-9_\-/.]+$#');
 */
final class Validator
{
    public function __construct(private readonly array $source)
    {
    }

    public function string(
        string $key,
        ?int $min = null,
        ?int $max = null,
        ?string $regex = null,
        bool $required = true,
        ?string $default = null,
    ): string {
        $raw = $this->source[$key] ?? null;
        if ($raw === null || $raw === '') {
            if ($required) {
                throw new RuntimeException("missing_field:$key");
            }
            return $default ?? '';
        }
        if (!\is_string($raw)) {
            throw new RuntimeException("not_string:$key");
        }
        $raw = trim($raw);
        if ($min !== null && strlen($raw) < $min) {
            throw new RuntimeException("too_short:$key");
        }
        if ($max !== null && strlen($raw) > $max) {
            throw new RuntimeException("too_long:$key");
        }
        if ($regex !== null && !preg_match($regex, $raw)) {
            throw new RuntimeException("invalid_format:$key");
        }
        return $raw;
    }

    public function int(string $key, ?int $min = null, ?int $max = null, bool $required = true, int $default = 0): int
    {
        $raw = $this->source[$key] ?? null;
        if ($raw === null || $raw === '') {
            if ($required) {
                throw new RuntimeException("missing_field:$key");
            }
            return $default;
        }
        $filtered = filter_var($raw, FILTER_VALIDATE_INT);
        if ($filtered === false) {
            throw new RuntimeException("not_int:$key");
        }
        if ($min !== null && $filtered < $min) {
            throw new RuntimeException("too_low:$key");
        }
        if ($max !== null && $filtered > $max) {
            throw new RuntimeException("too_high:$key");
        }
        return $filtered;
    }

    public function in(string $key, array $allowed, bool $required = true, mixed $default = null): mixed
    {
        $raw = $this->source[$key] ?? null;
        if ($raw === null || $raw === '') {
            if ($required) {
                throw new RuntimeException("missing_field:$key");
            }
            return $default;
        }
        if (!\in_array($raw, $allowed, true)) {
            throw new RuntimeException("not_in_allowed:$key");
        }
        return $raw;
    }

    public function filename(string $key, array $allowedExtensions, int $maxLen = 255): string
    {
        $name = $this->string($key, max: $maxLen);
        if (str_contains($name, "\0")) {
            throw new RuntimeException("null_byte:$key");
        }
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            throw new RuntimeException("path_in_filename:$key");
        }
        if (!SafePath::extensionAllowed($name, $allowedExtensions)) {
            throw new RuntimeException("extension_not_allowed:$key");
        }
        return $name;
    }

    /**
     * Web path validation: rifiuta path traversal (`..`), null bytes,
     * percorsi assoluti tipo Windows (`C:`), e applica una whitelist
     * di caratteri ammessi. Usato dai controller che ricevono path
     * relativi al document root prima di farli passare a SafePath::resolve().
     *
     * @param string|null $extPattern  estensioni ammesse, es. ['php','html']
     */
    public function webPath(string $key, ?array $extPattern = null, int $maxLen = 500): string
    {
        $path = $this->string($key, max: $maxLen);
        if (str_contains($path, "\0")) {
            throw new RuntimeException("null_byte:$key");
        }
        if (str_contains($path, '..')) {
            throw new RuntimeException("traversal:$key");
        }
        if (preg_match('#^[A-Za-z]:#', $path)) {
            throw new RuntimeException("absolute_path:$key");
        }
        // Caratteri permessi: letter/digit/_/-/./ /
        if (!preg_match('#^[/A-Za-z0-9_\- ./]+$#', $path)) {
            throw new RuntimeException("invalid_chars:$key");
        }
        if ($extPattern !== null && !SafePath::extensionAllowed($path, $extPattern)) {
            throw new RuntimeException("extension_not_allowed:$key");
        }
        return $path;
    }

    /**
     * Email RFC-validata (filter_var). Lancia not_email:$key se invalida.
     */
    public function email(string $key, int $maxLen = 254, bool $required = true, ?string $default = null): string
    {
        $raw = $this->string($key, max: $maxLen, required: $required, default: $default ?? '');
        if ($raw === '') {
            return $default ?? '';
        }
        $email = filter_var($raw, FILTER_VALIDATE_EMAIL);
        if ($email === false) {
            throw new RuntimeException("not_email:$key");
        }
        return $email;
    }

    /**
     * Boolean da form/JSON ("1","true","on","yes" → true; "0","false","off","no" → false).
     * Lancia not_bool:$key se il valore non è interpretabile.
     */
    public function bool(string $key, bool $required = false, bool $default = false): bool
    {
        $raw = $this->source[$key] ?? null;
        if ($raw === null || $raw === '') {
            if ($required) {
                throw new RuntimeException("missing_field:$key");
            }
            return $default;
        }
        $b = filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($b === null) {
            throw new RuntimeException("not_bool:$key");
        }
        return $b;
    }
}
