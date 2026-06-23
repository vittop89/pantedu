<?php

namespace App\Middleware;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\TeacherContentRepository;

/**
 * Phase 18 — Hard cutover legacy route → 410 Gone con smart redirect.
 *
 * Route legacy filesystem (/eser, /verifiche, /lab, /mappe, /didattica,
 * /risdoc, /strcomp_bes_altro, /drafts) ora:
 *   1. Tenta redirect 302 a /studio/{type}/{ind}/{cls}/{subj}/{topic}
 *      se l'URL è parsabile + teacher_content row esiste.
 *   2. Altrimenti ritorna 410 Gone con JSON/HTML di hint.
 *
 * Scope esclusioni:
 *   - Asset statici (/js, /css, /img, /tema, /mappe con estensione file)
 *     sono pre-routati a LegacyController, non passano di qui.
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

    private function tryResolveRedirect(string $path): ?string
    {
        if (!Config::get('database.enabled') || !Database::isAvailable()) {
            return null;
        }
        $m = \explode('/', \trim($path, '/'));
        if (\count($m) < 1) {
            return null;
        }
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
                    $topic = \str_replace('_', ' ', $fm[2]);
                    if ($this->teacherContentExists($type, $subj, $ind, $cls, $topic)) {
                        return \sprintf(
                            '/studio/%s/%s/%s/%s/%s',
                            $type,
                            \rawurlencode($ind),
                            \rawurlencode($cls),
                            \rawurlencode($subj),
                            \rawurlencode($topic)
                        );
                    }
                }
            }
        }

        // Pattern verifiche: /verifiche/{ind}/{ind}{cls}/{SUBJ}/{title}.php
        if ($head === 'verifiche' && \count($m) >= 5) {
            if (
                \preg_match('#^([a-z]+)(\d+[sb]?)$#i', $m[2], $sm)
                && $sm[1] === $m[1]
            ) {
                $ind = $m[1];
                $cls = $sm[2];
                $subj = $m[3];
                $file = $m[4];
                if (\preg_match('#^(?:\d+_)?[A-Z]+-(.+?)\.php$#', $file, $fm)) {
                    $topic = \str_replace('_', ' ', $fm[1]);
                    if ($this->teacherContentExists($type, $subj, $ind, $cls, $topic)) {
                        return \sprintf(
                            '/studio/%s/%s/%s/%s/%s',
                            $type,
                            \rawurlencode($ind),
                            \rawurlencode($cls),
                            \rawurlencode($subj),
                            \rawurlencode($topic)
                        );
                    }
                }
            }
        }

        return null;
    }

    private function teacherContentExists(string $type, string $subj, string $ind, string $cls, string $topic): bool
    {
        try {
            $repo = new TeacherContentRepository();
            $rows = $repo->search([
                'content_type' => $type,
                'subject_code' => $subj,
                'indirizzo'    => $ind,
                'classe'       => $cls,
                'topic'        => $topic,
                'limit'        => 1,
            ]);
            return !empty($rows);
        } catch (\Throwable) {
            return false;
        }
    }

    private function hintFor(string $path): string
    {
        $m = \explode('/', \trim($path, '/'));
        $head = $m[0] ?? '';
        $type = self::TYPE_MAP[$head] ?? 'esercizio';
        return "/studio/$type/{indirizzo}/{classe}/{subject}/{topic}";
    }
}
