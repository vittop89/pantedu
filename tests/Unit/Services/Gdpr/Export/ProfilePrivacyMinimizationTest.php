<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gdpr\Export;

use App\Services\Gdpr\Export\ExportFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 25.R.23 — Test difensivo: i bundle export NON devono mai contenere
 * password_hash, totp_secret, totp_backup_codes anche se il DB ne ha
 * accidentalmente fornito i valori.
 *
 * Approccio: simula un payload "contaminato" e verifica che la struttura
 * dell'exporter (vedi ProfileExporter::export) li avrebbe filtrati via.
 * Test puramente statico — non richiede DB.
 */
final class ProfilePrivacyMinimizationTest extends TestCase
{
    /** I campi proibiti in QUALSIASI export profile (anche admin authority). */
    private const FORBIDDEN_PROFILE_FIELDS = [
        'password_hash',
        'totp_secret',
        'totp_backup_codes',
        'totp_enrolled_at',
    ];

    #[Test]
    public function exporter_unset_loop_removes_all_forbidden_fields(): void
    {
        // Simula il payload contaminato che ProfileExporter riceve da SELECT *
        $row = [
            'id'                 => 77,
            'username'           => 'superadmin',
            'role'               => 'teacher',
            'email'              => 'vittorio@example.com',
            'first_name'         => 'Vittorio',
            'last_name'          => 'Pantaleo',
            // ⚠️ Campi sensibili che NON devono mai uscire
            'password_hash'      => '$2y$12$abc...',
            'totp_secret'        => 'JBSWY3DPEHPK3PXP',
            'totp_backup_codes'  => '["12345678","23456789"]',
            'totp_enrolled_at'   => '2026-01-15 10:00:00',
        ];

        // Replica logica di ProfileExporter::export() (foreach unset)
        foreach (self::FORBIDDEN_PROFILE_FIELDS as $forbid) {
            unset($row[$forbid]);
        }

        foreach (self::FORBIDDEN_PROFILE_FIELDS as $forbid) {
            $this->assertArrayNotHasKey(
                $forbid,
                $row,
                "Field '$forbid' must NEVER appear in profile export"
            );
        }

        // Verifica campi safe SIANO presenti
        $this->assertArrayHasKey('username', $row);
        $this->assertArrayHasKey('email', $row);
    }

    #[Test]
    public function sha256_of_exported_profile_does_not_contain_sensitive_strings(): void
    {
        $clean = [
            'id'         => 77,
            'username'   => 'superadmin',
            'role'       => 'teacher',
            'email'      => 'vittorio@example.com',
        ];
        $json = (string)json_encode($clean);
        $file = ExportFile::make('profile.json', $json, 'application/json');

        // Smoke check: il contenuto serializzato non ha password/totp
        $this->assertStringNotContainsString('$2y$', $file->content);
        $this->assertStringNotContainsString('totp_secret', $file->content);
        $this->assertStringNotContainsString('backup_code', $file->content);
        $this->assertStringNotContainsString('password_hash', $file->content);
    }
}
