<?php

namespace App\Support;

use RuntimeException;

/**
 * Path traversal guard. Every legacy endpoint that accepts a
 * user-supplied path MUST go through SafePath::resolve() before
 * touching the filesystem.
 */
final class SafePath
{
    /**
     * Resolves a user-supplied relative or absolute path against the
     * configured roots and returns a canonicalized absolute path that
     * is guaranteed to live inside at least one of the allowed roots.
     *
     * @param string        $input         user path (relative or absolute)
     * @param list<string>  $allowedRoots  absolute directories
     * @param bool          $mustExist     if true, the file/dir must exist
     *
     * @throws RuntimeException on traversal / out-of-root / missing when required
     */
    public static function resolve(string $input, array $allowedRoots, bool $mustExist = false): string
    {
        $input = trim($input);
        if ($input === '') {
            throw new RuntimeException('empty_path');
        }
        if (str_contains($input, "\0")) {
            throw new RuntimeException('null_byte_in_path');
        }

        $input = str_replace('\\', '/', $input);

        // Expand relative input against each allowed root
        $absolute = $input;
        if (!self::isAbsolute($input)) {
            // try each root until one contains it
            foreach ($allowedRoots as $root) {
                $candidate = rtrim($root, '/\\') . '/' . ltrim($input, '/');
                $real      = self::realish($candidate);
                if ($real !== null && self::isInside($real, $root)) {
                    if ($mustExist && !file_exists($candidate)) {
                        continue;
                    }
                    return $real;
                }
            }
            throw new RuntimeException('path_not_in_allowed_roots');
        }

        $real = self::realish($absolute);
        if ($real === null) {
            if ($mustExist) {
                throw new RuntimeException('path_does_not_exist');
            }
            // file doesn't exist yet — resolve parent dir
            $parent = dirname($absolute);
            $parentReal = self::realish($parent);
            if ($parentReal === null) {
                throw new RuntimeException('parent_does_not_exist');
            }
            $real = rtrim($parentReal, '/\\') . '/' . basename($absolute);
        }

        foreach ($allowedRoots as $root) {
            if (self::isInside($real, $root)) {
                return $real;
            }
        }
        throw new RuntimeException('path_outside_allowed_roots');
    }

    public static function isInside(string $path, string $root): bool
    {
        $rp = self::realish($root) ?? rtrim(str_replace('\\', '/', $root), '/');
        $p  = str_replace('\\', '/', $path);
        $rp = rtrim(str_replace('\\', '/', $rp), '/') . '/';
        return str_starts_with($p . '/', $rp);
    }

    public static function isAbsolute(string $p): bool
    {
        $p = str_replace('\\', '/', $p);
        return $p !== ''
            && ($p[0] === '/' || preg_match('#^[A-Za-z]:/#', $p) === 1);
    }

    /** realpath that tolerates non-existent files (returns null). */
    public static function realish(string $p): ?string
    {
        $p = str_replace('\\', '/', $p);
        $r = realpath($p);
        if ($r !== false) {
            return str_replace('\\', '/', $r);
        }

        // manual normalize if file doesn't exist: resolve . and ..
        $parts = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        $prefix = str_starts_with($p, '/') ? '/' : '';
        $joined = $prefix . implode('/', $parts);
        return $joined !== '' ? $joined : null;
    }

    public static function extensionAllowed(string $path, array $allowed): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return \in_array($ext, $allowed, true);
    }
}
