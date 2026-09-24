<?php

namespace App\Support;

/**
 * Phase 17 — Helper per referenziare gli asset Vite hashati in produzione.
 *
 * Vite emette `public/build/manifest.json` con mapping entry → file hashato.
 * In dev mode Vite è in esecuzione su :5173 e serve gli asset raw (no hash),
 * quindi i template referenziano gli entry originali.
 *
 * Uso nei template:
 *
 *   <?php if (\App\Support\ViteManifest::devMode()): ?>
 *       <script type="module" src="http://localhost:5173/@vite/client"></script>
 *       <script type="module" src="http://localhost:5173/js/modules/bootstrap.js"></script>
 *   <?php else: ?>
 *       <script type="module" src="<?= \App\Support\ViteManifest::url('js/modules/bootstrap.js') ?>"></script>
 *   <?php endif; ?>
 *
 * Dev mode flag: `APP_VITE_DEV=1` (o true, yes, on) in `.env`.
 */
final class ViteManifest
{
    private static ?array $manifest = null;

    /**
     * `app.vite_dev` (APP_VITE_DEV). Fino al 23/9/2026 si leggeva qui, e
     * valeva solo la stringa 'true': `APP_VITE_DEV=1`, come scrive
     * wiki/dev-workflow.md, non accendeva il dev server (A-35).
     */
    public static function devMode(): bool
    {
        return (bool) \App\Core\Config::get('app.vite_dev', false);
    }

    /** URL completo dell'asset hashato. Fallback: path raw non-bundled. */
    public static function url(string $entry): string
    {
        if (self::devMode()) {
            return "http://localhost:5173/{$entry}";
        }
        $manifest = self::load();
        $info = $manifest[$entry] ?? null;
        if (!$info || empty($info['file'])) {
            // Fallback: il file non è nel manifest → serve raw (legacy path).
            return "/{$entry}";
        }
        return "/build/{$info['file']}";
    }

    /** Ritorna eventuali CSS importati dall'entry (necessari in <head>). */
    public static function css(string $entry): array
    {
        if (self::devMode()) {
            return [];
        }
        $manifest = self::load();
        $info = $manifest[$entry] ?? null;
        return array_map(fn($f) => "/build/{$f}", (array)($info['css'] ?? []));
    }

    /**
     * URL dei sub-chunks importati staticamente dall'entry. Da emettere come
     * <link rel="modulepreload"> in <head> per parallel fetch (browser scopre
     * la dipendenza solo quando parsa l'entry → preload risparmia 1 roundtrip).
     *
     * Phase 2 perf optim (Fase 2): senza modulepreload i sub-chunks vengono
     * fetched serialmente dopo il parsing dell'entry → waterfall lento Slow 3G.
     */
    public static function imports(string $entry): array
    {
        if (self::devMode()) {
            return [];
        }
        $manifest = self::load();
        $info = $manifest[$entry] ?? null;
        $chunks = (array)($info['imports'] ?? []);
        $out = [];
        foreach ($chunks as $key) {
            $sub = $manifest[$key] ?? null;
            if ($sub && !empty($sub['file'])) {
                $out[] = "/build/{$sub['file']}";
            }
        }
        return $out;
    }

    /**
     * Helper one-shot per il template: emette <script type="module"> dell'entry
     * + <link rel="modulepreload"> per tutti i sub-chunks statici + <link
     * rel="stylesheet"> per i CSS importati (se l'entry importa CSS).
     *
     * Output esempio (entry "bootstrap"; il nonce è quello di Csp):
     *   <link rel="modulepreload" nonce="…" href="/build/assets/_chunk.HASH.js" crossorigin>
     *   <link rel="modulepreload" nonce="…" href="/build/assets/_preload-helper.HASH.js" crossorigin>
     *   <script nonce="…" type="module" src="/build/assets/bootstrap.HASH.js" crossorigin></script>
     *
     * Fallback se manifest assente / entry non trovato:
     *   <script nonce="…" type="module" src="/{entry}"></script>  (legacy raw path)
     */
    public static function script(string $entry): string
    {
        // Il nonce della CSP su ogni script e su ogni modulepreload (23/9/2026,
        // A-16): con 'strict-dynamic' 'self' non conta, e il browser carica solo
        // ciò che porta il nonce. Prima gli script lo ricevevano dalla
        // timbratura del middleware, i modulepreload mai: in modalità rigorosa
        // il precaricamento dei chunk era bloccato, e il chunk arrivava solo
        // quando l'entry lo importava.
        $csp = Csp::attributo();
        if (self::devMode()) {
            return '<script' . Csp::attributo() . ' type="module" src="http://localhost:5173/@vite/client"></script>'
                 . '<script' . Csp::attributo() . ' type="module" src="http://localhost:5173/'
                 . htmlspecialchars($entry, ENT_QUOTES) . '"></script>';
        }
        $manifest = self::load();
        $info = $manifest[$entry] ?? null;
        if (!$info || empty($info['file'])) {
            // Fallback raw — comportamento pre-Vite (entry path letterale).
            return '<script' . Csp::attributo() . ' type="module" src="/' . htmlspecialchars($entry, ENT_QUOTES) . '"></script>';
        }

        $html = '';
        foreach (self::imports($entry) as $chunkUrl) {
            $html .= '<link rel="modulepreload"' . $csp . ' href="' . htmlspecialchars($chunkUrl, ENT_QUOTES) . '" crossorigin>' . "\n";
        }
        foreach (self::css($entry) as $cssUrl) {
            $html .= '<link rel="stylesheet" href="' . htmlspecialchars($cssUrl, ENT_QUOTES) . '">' . "\n";
        }
        $html .= '<script' . Csp::attributo() . ' type="module" src="' . htmlspecialchars(self::url($entry), ENT_QUOTES) . '" crossorigin></script>';
        return $html;
    }

    private static function load(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }
        $path = dirname(__DIR__, 2) . '/public/build/.vite/manifest.json';
        if (!is_file($path)) {
            // Fallback path legacy (Vite <5 emetteva in root public/build/)
            $path = dirname(__DIR__, 2) . '/public/build/manifest.json';
        }
        if (!is_file($path)) {
            return self::$manifest = [];
        }
        $data = json_decode((string)@file_get_contents($path), true);
        return self::$manifest = is_array($data) ? $data : [];
    }
}
