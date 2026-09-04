<?php

declare(strict_types=1);

namespace App\Services\Verifica;

/**
 * G19.49m — Template file store per la nuova architettura verifiche.
 *
 * Layout:
 *   storage/templates/verifiche/
 *     _default/                          ← system defaults (fallback globale)
 *       texCommon/
 *         verifica.sty                   ← preambolo (LaTeX package)
 *         intestazione.tex               ← header docente/classe/data
 *         ulteriori_misure.tex           ← blocco "ulteriori misure"
 *         BES_DSA/
 *           misure_dispensative.tex      ← strumenti compensativi BES/DSA
 *           compensazione_orale.tex      ← footer compensazione orale
 *       versioni/
 *         main_NOR.tex                   ← template main per variante
 *         main_SOL.tex
 *         main_DSA.tex
 *         main_DIS.tex
 *       griglie/
 *         {indirizzo}_{materia}.tex      ← griglia per indirizzo+materia
 *
 *     {institute_code}/                  ← override per istituto (es. XXPS00000A)
 *       {stessa struttura}               ← cascade: institute → _default
 *
 * Cascade lookup (read): {institute_code}/{path} → _default/{path}.
 * Write: scrive sempre nello scope esplicito ({institute_code} o _default).
 *
 * Path validation: ogni `relPath` viene normalizzato + verificato contro
 * traversal (`..`); solo file dell'allowlist sono leggibili/scrivibili.
 */
final class TemplateFileStore
{
    public const SCOPE_DEFAULT = '_default';

    /**
     * Allowlist dei path relativi consentiti. Tutto fuori da questa lista
     * viene rifiutato (sia in lettura che scrittura).
     */
    public const ALLOWED_PATHS = [
        'texCommon/verifica.sty',
        'texCommon/intestazione.tex',
        'texCommon/ulteriori_misure.tex',
        'texCommon/BES_DSA/misure_dispensative.tex',
        'texCommon/BES_DSA/compensazione_orale.tex',
        'versioni/main_NOR.tex',
        'versioni/main_SOL.tex',
        'versioni/main_DSA.tex',
        'versioni/main_DIS.tex',
        // Griglie: pattern dinamico — vedi `isAllowedGrigliaPath`.
    ];

    private const MAX_BYTES = 200 * 1024;

    public static function rootDir(): string
    {
        return \dirname(__DIR__, 3) . '/storage/templates/verifiche';
    }

    /** Valida path relativo (allowlist + traversal). Throw se invalido.
     *  PROBLEM-7 hardening: rifiuta NUL byte e control chars (defense-in-depth
     *  contro path-injection sub-string negli ALLOWED_PATHS). */
    public static function assertPathValid(string $relPath): void
    {
        $rel = ltrim($relPath, '/');
        if (str_contains($rel, '..') || str_contains($rel, '\\')) {
            throw new \RuntimeException('invalid_path:traversal');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $rel)) {
            throw new \RuntimeException('invalid_path:control_chars');
        }
        if (!self::isAllowedPath($rel)) {
            throw new \RuntimeException('invalid_path:not_allowed:' . $rel);
        }
    }

    public static function isAllowedPath(string $relPath): bool
    {
        if (\in_array($relPath, self::ALLOWED_PATHS, true)) {
            return true;
        }
        return self::isAllowedGrigliaPath($relPath);
    }

    /** Griglie: `griglie/{indirizzo}_{materia}.tex` con codici alfanumerici.
     *  G22.S15.bis Fase 5+ — accetta indirizzo uppercase (SCI/ART/LIN) post
     *  refactor codici_uppercase, oltre alle varianti legacy lowercase (sc/ar). */
    public static function isAllowedGrigliaPath(string $relPath): bool
    {
        return (bool)preg_match('#^griglie/[A-Za-z0-9_]+_[A-Z0-9]+\.tex$#', $relPath);
    }

    /** Valida scope: `_default`, codice istituto (alfanumerico+dash/underscore),
     *  o scope teacher `t_{id}` (es. `t_77`). */
    public static function assertScopeValid(string $scope): void
    {
        if ($scope === self::SCOPE_DEFAULT) {
            return;
        }
        if (preg_match('/^t_\d+$/', $scope)) {
            return; // teacher scope
        }
        if (!preg_match('/^[A-Za-z0-9_-]{2,64}$/', $scope)) {
            throw new \RuntimeException('invalid_scope:' . $scope);
        }
    }

    /** G20.1 — Helper: scope teacher per id. */
    public static function teacherScope(int $teacherId): string
    {
        return 't_' . $teacherId;
    }

    /** G20.1 — Cascade lookup multi-livello: teacher → institute → default.
     *  Ritorna content del primo livello che ha il file, null se nessuno. */
    public static function readCascade(string $relPath, ?int $teacherId, ?string $instituteCode): ?string
    {
        self::assertPathValid($relPath);
        $tried = [];
        if ($teacherId !== null && $teacherId > 0) {
            $tried[] = self::teacherScope($teacherId);
        }
        if ($instituteCode !== null && $instituteCode !== '') {
            $tried[] = $instituteCode;
        }
        $tried[] = self::SCOPE_DEFAULT;
        foreach ($tried as $scope) {
            $path = self::rootDir() . "/$scope/$relPath";
            if (is_file($path)) {
                $content = @file_get_contents($path);
                if ($content !== false) {
                    return $content;
                }
            }
        }
        return null;
    }

    /**
     * Read with cascade: prima cerca in {scope}/, poi fallback in _default/.
     * Ritorna `null` se assente in entrambi.
     */
    /** @var array<string,?string> Cache request-scoped (scope|relPath → content). */
    private static array $cacheRead = [];

    public static function read(string $scope, string $relPath): ?string
    {
        self::assertScopeValid($scope);
        self::assertPathValid($relPath);
        // Cache hit: i template sono read-only nella vita di una request
        // (TexBuilder.build legge fino a ~10 file × 4 varianti = 40 read).
        $key = $scope . '|' . $relPath;
        if (array_key_exists($key, self::$cacheRead)) {
            return self::$cacheRead[$key];
        }

        $primary = self::rootDir() . "/$scope/$relPath";
        if (is_file($primary)) {
            $content = @file_get_contents($primary);
            if ($content !== false) {
                return self::$cacheRead[$key] = $content;
            }
        }
        if ($scope !== self::SCOPE_DEFAULT) {
            $fallback = self::rootDir() . '/' . self::SCOPE_DEFAULT . "/$relPath";
            if (is_file($fallback)) {
                $content = @file_get_contents($fallback);
                if ($content !== false) {
                    return self::$cacheRead[$key] = $content;
                }
            }
        }
        return self::$cacheRead[$key] = null;
    }

    /** Invalida cache (per editor che salva template + rilegge). */
    public static function clearReadCache(?string $scope = null, ?string $relPath = null): void
    {
        if ($scope === null) {
            self::$cacheRead = [];
            return;
        }
        $prefix = $scope . '|';
        if ($relPath !== null) {
            unset(self::$cacheRead[$prefix . $relPath]);
            return;
        }
        foreach (array_keys(self::$cacheRead) as $k) {
            if (str_starts_with($k, $prefix)) {
                unset(self::$cacheRead[$k]);
            }
        }
    }

    /**
     * Read raw nello scope esplicito (no cascade). Per editor:
     * vogliamo distinguere se override custom vs default ereditato.
     */
    public static function readRaw(string $scope, string $relPath): ?string
    {
        self::assertScopeValid($scope);
        self::assertPathValid($relPath);
        $path = self::rootDir() . "/$scope/$relPath";
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        return $content === false ? null : $content;
    }

    public static function write(string $scope, string $relPath, string $content): void
    {
        self::assertScopeValid($scope);
        self::assertPathValid($relPath);
        if (\strlen($content) > self::MAX_BYTES) {
            throw new \RuntimeException('file_too_large');
        }
        $path = self::rootDir() . "/$scope/$relPath";
        $dir  = \dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException('write_failed:' . $path);
        }
        // Invalida cache read per (scope, relPath) e per i fallback dipendenti
        // (se si scrive su _default, le read di altri scope con cascade vanno
        // invalidate; pulisco tutto per semplicita').
        self::clearReadCache($scope === self::SCOPE_DEFAULT ? null : $scope, $relPath);
    }

    public static function delete(string $scope, string $relPath): bool
    {
        self::assertScopeValid($scope);
        self::assertPathValid($relPath);
        $path = self::rootDir() . "/$scope/$relPath";
        if (!is_file($path)) {
            return false;
        }
        $ok = @unlink($path);
        if ($ok) {
            self::clearReadCache($scope === self::SCOPE_DEFAULT ? null : $scope, $relPath);
        }
        return $ok;
    }

    /** Lista file presenti (con cascade) per uno scope. */
    public static function list(string $scope): array
    {
        self::assertScopeValid($scope);
        $out = [];
        // Path statici allowlisted
        foreach (self::ALLOWED_PATHS as $rel) {
            $primary  = self::rootDir() . "/$scope/$rel";
            $fallback = self::rootDir() . '/' . self::SCOPE_DEFAULT . "/$rel";
            $out[] = [
                'path'        => $rel,
                'scope'       => $scope,
                'is_override' => is_file($primary),
                'has_default' => is_file($fallback),
                'size'        => is_file($primary) ? (int)@filesize($primary)
                              : (is_file($fallback) ? (int)@filesize($fallback) : 0),
            ];
        }
        // Griglie: scan dei file presenti in scope+default
        $griglieDir = self::rootDir() . "/$scope/griglie";
        $defaultGriglieDir = self::rootDir() . '/' . self::SCOPE_DEFAULT . '/griglie';
        $griglieFiles = [];
        foreach ([$griglieDir, $defaultGriglieDir] as $dir) {
            if (is_dir($dir)) {
                foreach (glob($dir . '/*.tex') ?: [] as $f) {
                    $name = basename($f);
                    if (self::isAllowedGrigliaPath("griglie/$name")) {
                        $griglieFiles["griglie/$name"] = true;
                    }
                }
            }
        }
        foreach (array_keys($griglieFiles) as $rel) {
            $primary  = self::rootDir() . "/$scope/$rel";
            $fallback = self::rootDir() . '/' . self::SCOPE_DEFAULT . "/$rel";
            $out[] = [
                'path'        => $rel,
                'scope'       => $scope,
                'is_override' => is_file($primary),
                'has_default' => is_file($fallback),
                'size'        => is_file($primary) ? (int)@filesize($primary)
                              : (is_file($fallback) ? (int)@filesize($fallback) : 0),
            ];
        }
        return $out;
    }
}
