<?php

namespace App\Support\Storage;

use App\Core\Config;
use RuntimeException;

/**
 * Costruisce il provider di storage secondo `storage.default_provider`
 * in config. Fase attuale: `local`. Phase cloud: cambia solo la config.
 */
final class StorageFactory
{
    private static ?StorageProvider $memo = null;

    public static function default(): StorageProvider
    {
        if (self::$memo !== null) return self::$memo;
        $kind = (string)Config::get('storage.default_provider', 'local');
        self::$memo = match ($kind) {
            'local' => self::local(),
            's3'    => self::s3(),
            default => throw new RuntimeException("unknown_storage_provider:$kind"),
        };
        return self::$memo;
    }

    /** Test hook: reset memo tra test. */
    public static function reset(): void
    {
        self::$memo = null;
    }

    private static function local(): LocalStorageProvider
    {
        $root   = (string)Config::get('storage.local.root',
            (string)Config::get('app.paths.storage') . '/objects');
        $secret = (string)Config::get('storage.signing_secret',
            (string)($_ENV['STORAGE_SIGNING_SECRET'] ?? ''));
        return new LocalStorageProvider(rootDir: $root, signingSecret: $secret);
    }

    private static function s3(): S3CompatibleStorageProvider
    {
        return new S3CompatibleStorageProvider(
            endpoint:  (string)Config::get('storage.s3.endpoint', ''),
            bucket:    (string)Config::get('storage.s3.bucket', ''),
            region:    (string)Config::get('storage.s3.region', ''),
            accessKey: (string)Config::get('storage.s3.access_key', ''),
            secret:    (string)Config::get('storage.s3.secret', ''),
        );
    }
}
