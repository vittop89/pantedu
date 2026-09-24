<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\TexCompile\ErroriTex;
use App\Services\TexCompile\TexCompileClient;

/**
 * Compile ad-hoc di un sorgente TeX arbitrario → PDF.
 *
 * Usato dal modal `fm-tikz-modal` per renderizzare la preview con la stessa
 * pipeline (PDF + PDF.js) della verifica preview, eliminando le differenze
 * di qualità dovute a SVG-as-paths di /render-tikz.
 *
 * Il client invia un sorgente che può essere:
 *   - Documento completo con \documentclass
 *   - Frammento con preamble + \begin{document} (legacy pantedu)
 *   - Frammento puro \begin{tikzpicture}...
 *
 * Il controller wrappa nel formato standalone se necessario, poi invia al
 * VPS /compile, restituisce PDF binario o errore JSON.
 *
 * Gli errori di pdflatex (24/9/2026). Il servizio compila in nonstopmode e dà
 * per riuscito ogni PDF prodotto, anche quando pdflatex ha scritto errori e
 * il disegno è venuto a metà: il modal mostrava il PDF, diceva «ok», e il log
 * compariva solo quando il PDF non usciva affatto. Ora si chiede il log intero
 * (`with_artifacts`), se ne leggono gli errori (ErroriTex) e, se ce n'è anche
 * uno, la risposta è 422 `tex_errors` con gli errori, l'estratto del log che
 * comincia da loro e il PDF parziale in `pdf_b64`, perché il modal possa
 * mostrare tutti e due.
 */
final class TexAdhocCompileController
{
    private const MAX_SOURCE_BYTES = 1 * 1024 * 1024; // 1 MB

    /** POST /api/tex/compile-adhoc-pdf — solo utenti autenticati. */
    public function compileTikzPdf(Request $req): Response
    {
        if (!Auth::user()) {
            return Response::fail('unauthorized', 401);
        }

        $body = $req->json();
        $tex  = (string)($body['tex'] ?? '');
        if ($tex === '') {
            return Response::fail('tex_empty', 400);
        }
        if (\strlen($tex) > self::MAX_SOURCE_BYTES) {
            return Response::fail('tex_too_large', 413);
        }

        $border = (string)($body['border'] ?? '2pt');
        if (!preg_match('/^\d+(?:\.\d+)?(?:pt|mm|cm|em)?$/u', $border)) {
            $border = '2pt';
        }

        $tex = $this->wrapTikzSource($tex, $border);

        $client = TexCompileClient::tryDefault();
        if (!$client) {
            return Response::fail('tex_compile_disabled', 503);
        }

        try {
            $result = $client->compile(
                texSource: $tex,
                docId:     'adhoc_tikz_' . substr(hash('sha256', $tex), 0, 12),
                engine:    'pdflatex',
                passes:    1,
                withArtifacts: true,
            );
        } catch (\Throwable $e) {
            return Response::json([
                'ok' => false,
                'error' => 'compile_exception',
                'message' => substr($e->getMessage(), 0, 800),
            ], 502);
        }

        $log    = (string)($result['log'] ?? '');
        $errori = ErroriTex::daLog($log);

        if (empty($result['ok'])) {
            return Response::json([
                'ok'      => false,
                'error'   => 'compile_failed',
                'log'     => ErroriTex::estratto($log, $errori),
                'errors'  => $errori,
                'http'    => (int)($result['http_status'] ?? 0),
            ], 422);
        }

        $pdf = (string)($result['pdf'] ?? '');
        if ($pdf === '') {
            return Response::fail('empty_pdf', 502);
        }

        if ($errori !== []) {
            return Response::json([
                'ok'      => false,
                'error'   => 'tex_errors',
                'log'     => ErroriTex::estratto($log, $errori),
                'errors'  => $errori,
                'pdf_b64' => base64_encode($pdf),
            ], 422);
        }

        return new Response($pdf, 200, [
            'Content-Type'          => 'application/pdf',
            'Cache-Control'         => 'private, max-age=300',
            'X-Compile-Mode'        => 'adhoc-tikz',
            'X-Compile-Duration-Ms' => (string)($result['duration_ms'] ?? 0),
        ]);
    }

    /**
     * Wrap TikZ source in standalone document. CRUCIALE: include lo stesso
     * font setup di verifica.sty (helvet sans-serif + T1 fontenc + sfdefault)
     * cosicché il render PDF sia visivamente identico alla verifica preview.
     *
     * Senza il font setup, il default Computer Modern Roman (serif) produce
     * glifi piccoli/curvi difficili da leggere a basse dimensioni nel modal
     * preview (es. "1" letta come "9", "%" come "50").
     */
    private function wrapTikzSource(string $src, string $border): string
    {
        $src = trim($src);
        if (preg_match('/^\s*\\\\documentclass\b/m', $src)) {
            return $src;
        }

        // Setup font matching verifica.sty (\verificaFontNormal):
        // helvet sans-serif scaled + T1 fontenc + sfdefault.
        $fontSetup = "\\usepackage[scaled]{helvet}\n"
                   . "\\usepackage[T1]{fontenc}\n"
                   . "\\renewcommand{\\familydefault}{\\sfdefault}\n";

        // Caso 2: preamble dell'autore + \begin{document}.
        // Iniettiamo font setup PRIMA di \begin{document}.
        if (preg_match('/\\\\begin\s*\{\s*document\s*\}/u', $src)) {
            $patched = preg_replace(
                '/(\\\\begin\s*\{\s*document\s*\})/u',
                $fontSetup . "$1",
                $src,
                1,
            ) ?? $src;
            return "\\documentclass[tikz,border={$border}]{standalone}\n" . $patched . "\n";
        }

        // Caso 3: frammento puro
        $body = $src;
        if (!preg_match('/\\\\begin\s*\{\s*tikzpicture\s*\}/u', $body)) {
            $body = "\\begin{tikzpicture}\n" . $body . "\n\\end{tikzpicture}";
        }
        return "\\documentclass[tikz,border={$border}]{standalone}\n"
            . "\\usepackage{tikz}\n"
            . "\\usepackage{amsmath,amssymb}\n"
            . $fontSetup
            . "\\begin{document}\n"
            . $body . "\n"
            . "\\end{document}\n";
    }
}
