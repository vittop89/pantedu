<?php

declare(strict_types=1);

namespace App\Services\Maps;

use App\Core\Config;
use RuntimeException;

/**
 * Phase G4 — Signed URL minting per accesso al blob mappa decifrato.
 *
 * Pattern presigned-URL classico: il server emette un token HMAC-SHA256
 * che incapsula (content_id, mode, exp). Chi possiede il token puo'
 * recuperare il blob senza autenticazione esplicita; il check di
 * permesso e' gia' avvenuto al momento della firma (caller deve aver
 * gia' verificato `MapPermissionService::canView/canCopy`).
 *
 * TTL clamp: [60, 3600] secondi. Default 600 (10 min) — sufficiente per
 * caricare l'iframe drawio + lasciare slack per refresh.
 *
 * Mode:
 *   - "view": read-only stream del blob al viewer
 *   - "copy": stream + flag UI (la save scrivera' una NUOVA row)
 *
 * Il signing_secret riusa STORAGE_SIGNING_SECRET (gia' presente per il
 * proxy /storage/signed). Nessuna chiave nuova da configurare.
 *
 * Layout token:
 *   t = base64url(json({i, m, e}))
 *   s = hex(hmac_sha256(t, secret))
 *
 *   i = content_id (int)
 *   m = mode ("view"|"copy")
 *   e = unix expiration timestamp (int)
 */
final class MapSignedUrlService
{
    public const MODE_VIEW = 'view';
    public const MODE_COPY = 'copy';
    private const ALLOWED_MODES = [self::MODE_VIEW, self::MODE_COPY];

    private const TTL_MIN = 60;
    private const TTL_MAX = 3600;
    private const TTL_DEFAULT = 600;

    public function mint(int $contentId, string $mode = self::MODE_VIEW, int $ttlSeconds = self::TTL_DEFAULT): string
    {
        if (!\in_array($mode, self::ALLOWED_MODES, true)) {
            throw new \InvalidArgumentException("invalid mode: $mode");
        }
        $ttl = max(self::TTL_MIN, min($ttlSeconds, self::TTL_MAX));
        $payload = [
            'i' => $contentId,
            'm' => $mode,
            'e' => time() + $ttl,
        ];
        $t = self::b64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $s = hash_hmac('sha256', $t, $this->secret());
        return '/api/maps/dl?t=' . $t . '&s=' . $s;
    }

    /**
     * Verifica un token. Restituisce {content_id, mode, exp} se valido,
     * lancia RuntimeException con error code stabile se invalid/expired.
     *
     * @return array{content_id:int, mode:string, exp:int}
     */
    public function verify(string $t, string $s): array
    {
        $expected = hash_hmac('sha256', $t, $this->secret());
        if (!hash_equals($expected, $s)) {
            throw new RuntimeException('signature_mismatch');
        }
        $raw = self::b64UrlDecode($t);
        if ($raw === false) {
            throw new RuntimeException('token_invalid');
        }
        $data = json_decode($raw, true);
        if (!\is_array($data) || !isset($data['i'], $data['m'], $data['e'])) {
            throw new RuntimeException('token_payload_invalid');
        }
        if ((int)$data['e'] < time()) {
            throw new RuntimeException('token_expired');
        }
        if (!\in_array((string)$data['m'], self::ALLOWED_MODES, true)) {
            throw new RuntimeException('mode_invalid');
        }
        return [
            'content_id' => (int)$data['i'],
            'mode'       => (string)$data['m'],
            'exp'        => (int)$data['e'],
        ];
    }

    private function secret(): string
    {
        $secret = (string)Config::get('storage.signing_secret', '');
        if ($secret === '') {
            throw new RuntimeException('storage_signing_secret_missing');
        }
        return $secret;
    }

    private static function b64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function b64UrlDecode(string $b64): string|false
    {
        $padded = strtr($b64, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($padded, true);
    }
}
