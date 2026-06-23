<?php

/**
 * Phase G21 — tex-compile-vps client configuration.
 *
 * Configurazione del client per il microservizio server-side compile
 * LaTeX → PDF (codice sorgente in `tools/tex-compile-vps/`, deploy su
 * VPS dedicato es. Hetzner CAX11).
 *
 * Architettura:
 *   Aruba (PHP) → POST /compile (HMAC) → VPS → ritorna PDF binario
 *
 * Le credenziali (endpoint URL e segreto HMAC) si configurano in `.env`
 * di produzione. NON committare valori reali in `.env.example` /repo.
 *
 * Vedi:
 *   - `tools/tex-compile-vps/README.md` (architettura)
 *   - `tools/tex-compile-vps/DEPLOY.md` (procedura deploy VPS)
 *   - `wiki/decisions/ADR-012-tex-compile-vps.md` (decisione)
 */

declare(strict_types=1);

return [
    // URL base del VPS compile (senza trailing slash).
    // Se vuoto → integrazione disabilitata (TexCompileClient lancia eccezione).
    // Esempio: https://tex.pantedu.eu
    'endpoint' => $_ENV['TEX_COMPILE_ENDPOINT'] ?? '',

    // Segreto HMAC condiviso col VPS (stesso valore in /opt/tex-compile/.env).
    // Generato dal provisioning con `openssl rand -hex 32`.
    'secret'   => $_ENV['TEX_COMPILE_SECRET'] ?? '',

    // Timeout HTTP totale per richiesta compile (secondi).
    // Compile pdflatex tipico: 1-10s. Considera margine per documenti TikZ.
    'timeout'  => (int)($_ENV['TEX_COMPILE_TIMEOUT'] ?? 60),

    // Engine LaTeX di default per le compilazioni.
    // Valori ammessi: pdflatex, xelatex, lualatex.
    // pdflatex va bene per la maggior parte dei template scolastici;
    // xelatex serve se si usa fontspec con font OpenType di sistema.
    'default_engine' => $_ENV['TEX_COMPILE_ENGINE'] ?? 'pdflatex',

    // Numero passate compile (per risolvere \ref, \toc, ecc).
    // 2 è standard, 3 se si usa biblatex/biber.
    'default_passes' => (int)($_ENV['TEX_COMPILE_PASSES'] ?? 2),

    // Path al CA bundle aggiornato (Windows/XAMPP fix per cURL error 60
    // "unable to get local issuer certificate"). Su Linux/prod lasciare
    // vuoto: cURL usa il system CA store.
    // Stesso pattern usato in app/Config/drive.php.
    'ca_bundle' => $_ENV['TEX_COMPILE_CA_BUNDLE']
        ?? $_ENV['DRIVE_CA_BUNDLE']
        ?? 'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt',

    // G22.S15 — TikZ → SVG render (endpoint /render-tikz sullo stesso VPS).
    // Usa lo stesso endpoint+secret di /compile (HMAC condiviso).
    'tikz_render' => [
        // Timeout HTTP per render TikZ (tipico 1-3s, margine per documenti complessi).
        'timeout' => (int)($_ENV['TIKZ_RENDER_TIMEOUT'] ?? 25),
        // Override SVG max bytes (default = TikzRenderService::MAX_SVG_BYTES).
        // 0 = usa default.
        'svg_max_bytes' => (int)($_ENV['TIKZ_RENDER_SVG_MAX'] ?? 0),
    ],
];
