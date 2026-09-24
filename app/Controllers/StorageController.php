<?php

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\StorageObjectRepository;
use App\Support\Storage\StorageFactory;
use Throwable;

/**
 * Phase 14 — serve oggetti storage via signed URL (HMAC-SHA256).
 *
 *   GET /storage/signed?t=<payload>&s=<signature>
 *
 * Il payload è base64(json({k:key, e:expiry})); la signature è
 * HMAC-SHA256(payload, STORAGE_SIGNING_SECRET). Nessun auth di sessione:
 * il possesso del token firmato = autorizzazione temporanea.
 *
 * Il Content-Type proviene dalla colonna `mime` in `storage_objects`,
 * con fallback per estensione.
 */
final class StorageController
{
    public function signed(Request $req): Response
    {
        $payload = (string)($req->query['t'] ?? '');
        $sig     = (string)($req->query['s'] ?? '');
        if ($payload === '' || $sig === '') {
            return Response::json(['error' => 'missing_token'], 400);
        }
        $secret = (string)Config::get('storage.signing_secret', '');
        if ($secret === '') return Response::json(['error' => 'storage_not_configured'], 500);

        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $sig)) {
            return Response::json(['error' => 'bad_signature'], 403);
        }
        $decoded = json_decode((string)base64_decode($payload, true) ?: '', true);
        if (!is_array($decoded) || !isset($decoded['k'], $decoded['e'])) {
            return Response::json(['error' => 'bad_payload'], 400);
        }
        if ((int)$decoded['e'] < time()) {
            return Response::json(['error' => 'expired'], 410);
        }

        $key = (string)$decoded['k'];
        try {
            $provider = StorageFactory::default();
            $bytes    = $provider->get($key);

            // mime da storage_objects, fallback estensione
            $mime = 'application/octet-stream';
            try {
                $row = (new StorageObjectRepository())->findByKey($provider->name(), $key);
                if ($row && !empty($row['mime'])) $mime = (string)$row['mime'];
            } catch (Throwable) { /* tabella non presente o DB down → fallback */ }

            if ($mime === 'application/octet-stream') {
                $mime = self::mimeByExt($key);
            }

            $r = new Response($bytes, 200);
            $r->headers['Content-Type']   = $mime;
            $r->headers['Cache-Control']  = 'private, max-age=60';
            $r->headers['Content-Length'] = (string)strlen($bytes);
            return $r;
        } catch (Throwable $e) {
            return Response::json(['error' => 'not_found'], 404);
        }
    }

    private static function mimeByExt(string $key): string
    {
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        return match ($ext) {
            'pdf'  => 'application/pdf',
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'webp' => 'image/webp',
            'html' => 'text/html; charset=UTF-8',
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'json' => 'application/json',
            'txt', 'tex' => 'text/plain; charset=UTF-8',
            default => 'application/octet-stream',
        };
    }
}
