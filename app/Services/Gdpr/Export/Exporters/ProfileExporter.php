<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export\Exporters;

use App\Core\Database;
use App\Services\Gdpr\Export\ContentExporterInterface;
use App\Services\Gdpr\Export\ExportContext;
use App\Services\Gdpr\Export\ExportSection;
use PDO;

/**
 * Phase 25.R.23 — Exporter dati profilo utente (Art. 15 GDPR).
 *
 * Esporta: username, ruolo, nome/cognome/email, status, istituto, timestamps.
 * Esclude: password_hash, totp_secret, totp_backup_codes (campi sensibili crypto).
 * Per richieste admin/authority: opzionalmente include birth_date (minori Art. 8).
 */
final class ProfileExporter implements ContentExporterInterface
{
    public function getKey(): string
    {
        return 'profile';
    }
    public function getLabel(): string
    {
        return 'Profilo utente (dati anagrafici)';
    }
    public function getCategory(): string
    {
        return 'profile';
    }
    public function isAvailableForSelfService(): bool
    {
        return true;
    }
    public function isAvailableForAuthority(): bool
    {
        return true;
    }

    public function export(ExportContext $ctx): ExportSection
    {
        $section = new ExportSection('profile', 'profile', $this->getLabel());

        $stmt = Database::connection()->prepare(
            'SELECT id, username, role, first_name, last_name, email,
                    status, active, institute_id, admin_institute_id,
                    birth_date, created_at, approved_at, approved_by, deleted_at
             FROM users WHERE id = ?'
        );
        $stmt->execute([$ctx->userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $section->summary['_error'] = 'user_not_found';
            return $section;
        }

        // Phase 25.R.23 bonus — Minimizzazione: anche per admin authority,
        // NON esportiamo password_hash + totp_secret + totp_backup_codes
        // (sono dati di autenticazione, non identificativi del data subject).
        // La query sopra già esclude password_hash dal SELECT, ma se in futuro
        // qualcuno aggiunge LEFT JOIN o SELECT * → guard esplicito.
        foreach (['password_hash', 'totp_secret', 'totp_backup_codes', 'totp_enrolled_at'] as $forbid) {
            unset($row[$forbid]);
        }

        $section->addJsonFile('profile.json', $row);
        $section->setSummary([
            'username' => $row['username'],
            'role'     => $row['role'],
            'status'   => $row['status'],
        ]);

        return $section;
    }
}
