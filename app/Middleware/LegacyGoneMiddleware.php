<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Phase 18 — Hard cutover legacy route → 410 Gone con smart redirect.
 *
 * Route legacy filesystem (/eser, /verifiche, /lab, /mappe, /didattica,
 * /risdoc, /strcomp_bes_altro, /drafts) ora:
 *   1. Redirect 302 a /studio/{type}/{ind}/{cls}/{subj}/{topic} se l'URL è
 *      di una delle forme qui sotto: la destinazione si calcola dal solo URL.
 *   2. Altrimenti 410 Gone con JSON/HTML di hint.
 *
 * 23/9/2026 (revisione architetturale A-54) — il redirect partiva solo se nel
 * database c'era un contenuto con quelle sigle e quell'argomento, cercato
 * senza filtro per docente, istituto o visibilità: 302 o 410 dicevano se
 * esisteva un contenuto che chi chiedeva poteva non avere il diritto di
 * vedere, anche senza login su /mappe. Adesso il middleware non legge il
 * database: la risposta dipende solo dall'URL, e se il contenuto c'è e chi
 * chiede lo può vedere lo decide la pagina di /studio, con le sue regole.
 * Con il nginx del container gli URL `.php` non arrivano qui (404 di nginx,
 * tests/ops/rotte-come-nginx.test.sh): il redirect serve a chi pubblica il
 * sito con il `.htaccess` di Apache.
 *
 * Pattern URL supportati:
 *   /eser/{ind}/eser_{ind}{cls}/{SUBJ}/{num}_..{-title-}..{ind}{cls}.php
 *   /verifiche/{ind}/{ind}{cls}/{SUBJ}/{num}_MAT-Title.php
 *   /lab/{ind}/lab_{ind}{cls}/{SUBJ}/{num}_MAT-Title.php
 *   /didattica/{ind}/didattica_{ind}{cls}/{SUBJ}/{num}_MAT-Title.php
 *
 * Il mapping type deriva dal 1° segmento.
 */
final class LegacyGoneMiddleware
{
    private const TYPE_MAP = [
        // Opzione A (migr 078): lab→esercizio ; didattica/risdoc/bes→document.
        'eser'              => 'esercizio',
        'verifiche'         => 'verifica',
        'lab'               => 'esercizio',
        'mappe'             => 'mappa',
        'didattica'         => 'document',
        'risdoc'            => 'document',
        'strcomp_bes_altro' => 'document',
        'drafts'            => 'esercizio',
    ];

    public function handle(Request $req, callable $next): Response
    {
        $path = (string)($req->path ?? $req->server['REQUEST_URI'] ?? '');
        $path = (string)\parse_url($path, PHP_URL_PATH);

        $redirect = $this->tryResolveRedirect($path);
        if ($redirect !== null) {
            return Response::redirect($redirect, 302);
        }

        $hint = $this->hintFor($path);
        if ($req->wantsJson()) {
            return Response::json([
                'error' => 'gone',
                'message' => 'Legacy route removed (Phase 18).',
                'hint' => $hint,
            ], 410);
        }
        $body = '<!doctype html><meta charset="utf-8"><title>410 Gone</title>'
              . '<div style="font-family:system-ui;padding:2rem;max-width:640px;margin:auto">'
              . '<h1>410 — Risorsa rimossa</h1>'
              . '<p>Questa route legacy è stata dismessa.</p>'
              . '<p>Prova: <code>' . \htmlspecialchars($hint, ENT_QUOTES) . '</code></p>'
              . '<p><a href="/">← home</a></p></div>';
        return new Response($body, 410, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * La destinazione del redirect, dal solo percorso: nessuna lettura del
     * database, quindi la stessa risposta che il contenuto esista o no.
     */
    private function tryResolveRedirect(string $path): ?string
    {
        $m = \explode('/', \trim($path, '/'));
        $head = $m[0];
        if (!isset(self::TYPE_MAP[$head])) {
            return null;
        }
        $type = self::TYPE_MAP[$head];

        // Pattern: /{head}/{ind}/{head}_{ind}{cls}/{SUBJ}/{num}_...php
        if (\count($m) >= 5 && \preg_match('#^[a-z0-9_-]+$#i', $m[1])) {
            $ind = $m[1];
            if (\preg_match('#^' . \preg_quote($head, '#') . '_(?:' . \preg_quote($ind, '#') . ')?([a-z0-9]+)$#i', $m[2], $sm)) {
                $cls  = $sm[1];
                $subj = $m[3];
                $file = $m[4];
                if (\preg_match('#^([\d.]+)_[A-Z]+-(.+?)(?:-' . \preg_quote($ind . $cls, '#') . ')?\.php$#', $file, $fm)) {
                    return self::studio($type, $ind, $cls, $subj, \str_replace('_', ' ', $fm[2]));
                }
            }
        }

        // Pattern verifiche: /verifiche/{ind}/{ind}{cls}/{SUBJ}/{title}.php
        if ($head === 'verifiche' && \count($m) >= 5) {
            if (
                \preg_match('#^([a-z]+)(\d+[sb]?)$#i', $m[2], $sm)
                && $sm[1] === $m[1]
            ) {
                if (\preg_match('#^(?:\d+_)?[A-Z]+-(.+?)\.php$#', $m[4], $fm)) {
                    return self::studio($type, $m[1], $sm[2], $m[3], \str_replace('_', ' ', $fm[1]));
                }
            }
        }

        return null;
    }

    private static function studio(string $type, string $ind, string $cls, string $subj, string $topic): string
    {
        return \sprintf(
            '/studio/%s/%s/%s/%s/%s',
            $type,
            \rawurlencode($ind),
            \rawurlencode($cls),
            \rawurlencode($subj),
            \rawurlencode($topic)
        );
    }

    private function hintFor(string $path): string
    {
        $m = \explode('/', \trim($path, '/'));
        $head = $m[0] ?? '';
        $type = self::TYPE_MAP[$head] ?? 'esercizio';
        return "/studio/$type/{indirizzo}/{classe}/{subject}/{topic}";
    }
}
