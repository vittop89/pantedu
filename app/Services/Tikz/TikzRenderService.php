<?php

declare(strict_types=1);

namespace App\Services\Tikz;

use App\Core\Config;
use App\Services\Crypto\TeacherCryptoService;
use App\Services\TexCompile\TikzRenderClient;
use RuntimeException;

/**
 * G22.S15 — Servizio cache + render per TikZ → SVG via VPS.
 *
 * Architettura:
 *
 *   ┌──────────────────────────────────────────────────────────────┐
 *   │                       TikzRenderService                       │
 *   ├──────────────────────────────────────────────────────────────┤
 *   │ getOrRender($source, $scope, $teacherId): array{svg, source} │
 *   │  1. Normalizza sorgente TikZ (whitespace + tag HTML residui)  │
 *   │  2. Calcola SHA-256 del normalizzato → hash                  │
 *   │  3. Look up cache:                                           │
 *   │     - public scope → storage/cache/tikz/public/{p}/{h}.svg    │
 *   │     - teacher scope → storage/cache/tikz/teacher_{tid}/...    │
 *   │       (.bin cifrato envelope AES-256-GCM via TeacherCrypto)  │
 *   │  4. Cache HIT → ritorna SVG + 'cache'                        │
 *   │  5. Cache MISS → POST VPS /render-tikz, salva in cache       │
 *   │     (cifra se scope=teacher), ritorna SVG + 'compile'        │
 *   └──────────────────────────────────────────────────────────────┘
 *
 * GDPR (ADR-006 + ADR-007):
 *  - Public scope: TikZ in admin templates (modelli_tikz.php). Nessuna PII.
 *    Cache in chiaro, condivisa, no auth su READ.
 *  - Teacher scope: TikZ in contenuto del docente (eser/, verifiche/,
 *    area-docente/). Puo contenere dati personali in label.
 *    Cache cifrata envelope per docente. Crypto-shredding O(1) via
 *    TeacherCryptoService::shred() in caso di Art.17 oblio.
 */
final class TikzRenderService
{
    public const SCOPE_PUBLIC  = 'public';
    public const SCOPE_TEACHER = 'teacher';

    /** Bytes max source TikZ accettato (1 MB). */
    public const MAX_SOURCE_BYTES = 1 * 1024 * 1024;
    /** Bytes max SVG generato accettato (10 MB, allineato a TikzService::saveSvg). */
    public const MAX_SVG_BYTES = 10 * 1024 * 1024;

    private string $basePath;

    public function __construct(
        private readonly TikzRenderClient $client,
        private readonly TeacherCryptoService $crypto,
        ?string $basePath = null,
    ) {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath ?? dirname(__DIR__, 3)), '/');
    }

    /**
     * Crea l'istanza standard pescando endpoint+secret da Config.
     * Ritorna null se TEX_COMPILE non e' configurato (rollback path).
     */
    public static function createDefault(): ?self
    {
        $endpoint = (string) Config::get('tex_compile.endpoint', '');
        $secret   = (string) Config::get('tex_compile.secret', '');
        if ($endpoint === '' || $secret === '') {
            return null;
        }
        $client = new TikzRenderClient(
            endpoint:       $endpoint,
            secret:         $secret,
            timeoutSeconds: (int) Config::get('tex_compile.timeout', 25),
            caBundle:       (string) Config::get('tex_compile.ca_bundle', ''),
        );
        $crypto = new TeacherCryptoService();
        return new self($client, $crypto);
    }

    /**
     * Lookup cache; se miss, compila via VPS e salva.
     *
     * @param array{libraries?:list<string>, pgfplots_libraries?:list<string>, extra_packages?:list<string>, border?:string} $opts
     * @return array{svg:string, source:'cache'|'compile', hash:string, duration_ms:?int, log?:string}
     */
    public function getOrRender(
        string $tikzSource,
        string $scope = self::SCOPE_PUBLIC,
        int $teacherId = 0,
        array $opts = [],
    ): array {
        $this->assertScope($scope, $teacherId);
        if ($tikzSource === '' || \strlen($tikzSource) > self::MAX_SOURCE_BYTES) {
            throw new RuntimeException('tikz_source_size_invalid');
        }

        $normalized = self::normalize($tikzSource);

        // G27.tikz.cache.hash — CHIAVE CACHE calcolata QUI, dalla forma
        // normalize() identica a quella del CLIENT (tikz-render-client
        // normalizeTikz → GET lookup ?hash=). Lo stripping sotto (per l'input al
        // render VPS) NON deve entrare nella chiave: prima il server hashava DOPO
        // lo strip (trim del \n finale + rimozione \usetikzlibrary) → hash !=
        // client → cache MISS perenne → ogni visita ri-compilava tutti i TikZ.
        // Bonus: includere le \usetikzlibrary nell'hash è anche più corretto
        // (librerie diverse = render diversi = chiavi diverse).
        $hash = hash('sha256', $normalized);

        // G27.tikz.render.strip — se il source NON e' un documento standalone
        // completo (`\begin{document}` o `\documentclass`), il VPS lo wrappa
        // automaticamente. In tal caso `\usepackage{...}` / `\usetikzlibrary
        // {...}` / `\documentclass` / `\pagestyle` interni finiscono DENTRO
        // `\begin{document}` → pdflatex error "\usepackage in document body".
        // Strippiamo direttive preamble + estraiamo nomi package/library da
        // passare come opts (il VPS li includera' nel suo preamble standalone).
        $extractedLibs = [];
        $extractedExtras = [];
        $isStandalone = (bool)preg_match('#\\\\begin\s*\{\s*document\s*\}|\\\\documentclass#', $normalized);
        if (!$isStandalone) {
            $normalized = preg_replace_callback(
                '#\\\\usepackage(?:\[[^\]]*\])?\{([^}]+)\}\s*#',
                function ($m) use (&$extractedExtras): string {
                    foreach (explode(',', $m[1]) as $n) {
                        $t = trim($n);
                        if ($t !== '' && $t !== 'tikz') {
                            $extractedExtras[] = $t;
                        }
                    }
                    return '';
                },
                $normalized,
            ) ?? $normalized;
            $normalized = preg_replace_callback(
                '#\\\\usetikzlibrary\{([^}]+)\}\s*#',
                function ($m) use (&$extractedLibs): string {
                    foreach (explode(',', $m[1]) as $n) {
                        $t = trim($n);
                        if ($t !== '') {
                            $extractedLibs[] = $t;
                        }
                    }
                    return '';
                },
                $normalized,
            ) ?? $normalized;
            $normalized = (string)preg_replace('#\\\\pagestyle\{[^}]+\}\s*#', '', $normalized);
            $normalized = trim($normalized);
        }
        // NB: $hash già calcolato sopra dalla forma normalize() (vedi
        // G27.tikz.cache.hash) — NON ricalcolare dallo $normalized strippato.

        // 1. Tenta cache
        $cached = $this->readCache($scope, $teacherId, $hash);
        if ($cached !== null) {
            return [
                'svg'         => $cached,
                'source'      => 'cache',
                'hash'        => $hash,
                'duration_ms' => null,
            ];
        }

        // 2. MISS: compila via VPS
        $libs    = self::stringList($opts['libraries']           ?? []);
        $pgflibs = self::stringList($opts['pgfplots_libraries']  ?? []);
        $extras  = self::stringList($opts['extra_packages']      ?? []);
        // G27.tikz.render.strip — merge libs/packages estratti dal source
        $libs   = array_values(array_unique(array_merge($libs, $extractedLibs)));
        $extras = array_values(array_unique(array_merge($extras, $extractedExtras)));
        $border  = (string)($opts['border'] ?? '2pt');

        $r = $this->client->render(
            $normalized,
            libraries:         $libs,
            pgfplotsLibraries: $pgflibs,
            extraPackages:     $extras,
            border:            $border,
            docId:             'tikz_' . substr($hash, 0, 12),
        );
        if (!$r['ok']) {
            throw new TikzRenderException(
                (string)($r['log'] ?? 'compile_failed'),
                (int)($r['http_status'] ?? 0)
            );
        }
        $svg = (string)($r['svg'] ?? '');
        if ($svg === '' || \strlen($svg) > self::MAX_SVG_BYTES) {
            throw new RuntimeException('svg_size_invalid');
        }

        // 3. Persisti in cache
        $this->writeCache($scope, $teacherId, $hash, $svg);

        return [
            'svg'         => $svg,
            'source'      => 'compile',
            'hash'        => $hash,
            'duration_ms' => $r['duration_ms'] ?? null,
        ];
    }

    /**
     * Solo lookup, senza compilazione. Ritorna null se cache miss.
     */
    public function lookup(string $scope, int $teacherId, string $hash): ?string
    {
        $this->assertScope($scope, $teacherId);
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException('invalid_hash');
        }
        return $this->readCache($scope, $teacherId, $hash);
    }

    /** Cancella la cache di un docente (utile post-shredding o reset). */
    public function purgeTeacherCache(int $teacherId): int
    {
        if ($teacherId <= 0) {
            throw new RuntimeException('invalid_teacher_id');
        }
        $dir = $this->cacheRootForTeacher($teacherId);
        if (!is_dir($dir)) {
            return 0;
        }
        return $this->rrmdir($dir);
    }

    // ─────────────────────────── normalizzazione ───────────────────────────

    /**
     * Normalizza sorgente TikZ:
     *   - rimuove tag HTML residui (br/p/span — alcuni editor li lasciano)
     *   - decode TUTTE le entita HTML (named + &#NNN; + &#xHH;) via
     *     html_entity_decode (gli editor tipo Quill convertono lettere
     *     accentate italiane in &#192; etc; senza decode finiscono in
     *     pdflatex come `&` di alignment → compile error).
     *   - normalizza whitespace (CRLF→LF, trim multipli)
     *   - inietta font setup (helvet sans-serif + T1 fontenc + sfdefault)
     *     CENTRALIZED: stesso setup di verifica.sty `\verificaFontNormal`,
     *     applicato anche dal TexAdhocCompileController per il modal preview.
     *     Senza: glifi CMR serif piccoli/curvi diventano illeggibili nel
     *     render SVG del page e textarea preview.
     * Hash stabile = stesso contenuto logico → stessa cache.
     * Idempotente: normalize(normalize(x)) == normalize(x).
     */
    public static function normalize(string $src): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $src);
        $s = preg_replace('#<br\s*/?>#i', "\n", $s) ?? $s;
        $s = preg_replace('#</?(?:p|span|div|b|i|u)\b[^>]*>#i', '', $s) ?? $s;
        // Decode entita HTML named + numeric (decimal/hex). ENT_HTML5 copre
        // tutti i casi che gli editor browser-side generano (Quill, contenteditable).
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // collapse trailing whitespace per riga + LF finale singolo
        $s = preg_replace("/[ \t]+\n/", "\n", $s) ?? $s;
        $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;
        $s = trim($s) . "\n";

        // Inietta font setup helvet/sfdefault (idempotente: skip se gia' presente).
        // Solo se il source ha un preamble + \begin{document} (Case 2 VPS).
        // Se gia' contiene \documentclass (Case 1) o e' frammento puro (Case 3),
        // il VPS aggiungera' il proprio wrap → font setup gestito altrove.
        if (
            preg_match('/\\\\begin\s*\{\s*document\s*\}/u', $s)
            && !str_contains($s, '\\documentclass')
            && !str_contains($s, '\\renewcommand{\\familydefault}')
        ) {
            $fontSetup = "\\usepackage[scaled]{helvet}\n"
                       . "\\usepackage[T1]{fontenc}\n"
                       . "\\renewcommand{\\familydefault}{\\sfdefault}\n";
            $s = preg_replace(
                '/(\\\\begin\s*\{\s*document\s*\})/u',
                $fontSetup . "$1",
                $s,
                1,
            ) ?? $s;
        }

        return $s;
    }

    // ─────────────────────────── cache filesystem ───────────────────────────

    private function readCache(string $scope, int $teacherId, string $hash): ?string
    {
        $path = $this->cachePath($scope, $teacherId, $hash);
        if (!is_file($path)) {
            return null;
        }
        $blob = @file_get_contents($path);
        if ($blob === false || $blob === '') {
            return null;
        }
        if ($scope === self::SCOPE_PUBLIC) {
            return $blob;
        }
        // Teacher scope: blob = [12B IV][16B TAG][... CT ...]
        return $this->decryptBlob($teacherId, $blob);
    }

    private function writeCache(string $scope, int $teacherId, string $hash, string $svg): void
    {
        $path = $this->cachePath($scope, $teacherId, $hash);
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('cache_mkdir_failed');
        }
        $payload = $scope === self::SCOPE_PUBLIC
            ? $svg
            : $this->encryptBlob($teacherId, $svg);

        // Atomic write: file temporaneo + rename
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new RuntimeException('cache_write_failed');
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('cache_rename_failed');
        }
    }

    private function cachePath(string $scope, int $teacherId, string $hash): string
    {
        // Hash gia validato (^[a-f0-9]{64}$ in lookup) o derivato da
        // hash('sha256', ...) in getOrRender → no traversal possibile.
        // teacherId e' int → no injection nel path.
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException('invalid_hash');
        }
        $prefix = substr($hash, 0, 2); // sharding 256-way
        if ($scope === self::SCOPE_PUBLIC) {
            return $this->basePath . "/storage/cache/tikz/public/{$prefix}/{$hash}.svg";
        }
        return $this->basePath . "/storage/cache/tikz/teacher_{$teacherId}/{$prefix}/{$hash}.bin";
    }

    private function cacheRootForTeacher(int $teacherId): string
    {
        return $this->basePath . "/storage/cache/tikz/teacher_{$teacherId}";
    }

    // ─────────────────────────── envelope encryption ───────────────────────

    private function encryptBlob(int $teacherId, string $svg): string
    {
        if (!$this->crypto->isConfigured()) {
            throw new RuntimeException('crypto_not_configured');
        }
        $env = $this->crypto->encrypt($teacherId, $svg);
        // Format on disk: [1B version][1B kv][12B IV][16B TAG][... CT ...]
        // version=1 per future-proof
        return \chr(1)
             . \chr((int)$env['kv'] & 0xFF)
             . $env['iv']
             . $env['tag']
             . $env['ciphertext'];
    }

    private function decryptBlob(int $teacherId, string $blob): string
    {
        if (\strlen($blob) < 1 + 1 + 12 + 16 + 1) {
            throw new RuntimeException('cache_blob_truncated');
        }
        $version = \ord($blob[0]);
        if ($version !== 1) {
            throw new RuntimeException('cache_blob_version_unknown');
        }
        $kv  = \ord($blob[1]);
        $iv  = substr($blob, 2, 12);
        $tag = substr($blob, 14, 16);
        $ct  = substr($blob, 30);
        return $this->crypto->decrypt($teacherId, [
            'ciphertext' => $ct,
            'iv'         => $iv,
            'tag'        => $tag,
            'kv'         => $kv,
        ]);
    }

    // ─────────────────────────── helpers ───────────────────────────────────

    private function assertScope(string $scope, int $teacherId): void
    {
        if ($scope === self::SCOPE_PUBLIC) {
            return;
        }
        if ($scope === self::SCOPE_TEACHER) {
            if ($teacherId <= 0) {
                throw new RuntimeException('teacher_scope_requires_teacher_id');
            }
            return;
        }
        throw new RuntimeException('invalid_scope');
    }

    /** @param mixed $v @return list<string> */
    private static function stringList($v): array
    {
        if (!\is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (\is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    private function rrmdir(string $dir): int
    {
        $count = 0;
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $count += $this->rrmdir($path);
            } elseif (@unlink($path)) {
                $count++;
            }
        }
        @rmdir($dir);
        return $count;
    }
}
