<?php

declare(strict_types=1);

namespace App\Services\Gdpr\Export\Exporters;

use App\Core\Database;
use App\Services\Gdpr\Export\ContentExporterInterface;
use App\Services\Gdpr\Export\ExportContext;
use App\Services\Gdpr\Export\ExportSection;
use PDO;

/**
 * Phase 25.R.23.2 — Exporter classe_keys (per recovery published_content).
 *
 * `published_content` è cifrato con classe_key (KEK per anno scolastico,
 * indirizzo, classe). La chiave è in `classe_keys.wrapped_key` (varbinary)
 * cifrata con KMS_MASTER_KEY (envelope encryption, analogo a teacher_keys).
 *
 * Esporta:
 *   - classe_keys.json: metadata (NO wrapped_key plaintext — è cifrata, ma
 *     varbinary base64-encoded richiede decifratura con KMS off-line)
 *   - Solo per scope authority/admin (Art. 6(1)(c) magistratura).
 *
 * USO AUTORITÀ:
 *   1. Autorità riceve bundle + classe_keys.json
 *   2. Per decifrare published_content[X].body_ct: serve classe_key plaintext.
 *   3. Data controller (Operatore) usa KMS_MASTER_KEY off-line per unwrap
 *      classe_keys[X].wrapped_key → plaintext key.
 *   4. AES-256-GCM-decrypt published_content[X] con quella key.
 *
 * NON disponibile in self-service (utente non ha permessi su classe_keys).
 */
final class ClasseKeysExporter implements ContentExporterInterface
{
    public function getKey(): string
    {
        return 'classe_keys';
    }
    public function getLabel(): string
    {
        return 'Chiavi crittografiche classe (per published_content recovery)';
    }
    public function getCategory(): string
    {
        return 'meta';
    }
    public function isAvailableForSelfService(): bool
    {
        return false; // riservato authority
    }
    public function isAvailableForAuthority(): bool
    {
        return true;
    }

    public function export(ExportContext $ctx): ExportSection
    {
        $section = new ExportSection('classe_keys', 'audit', $this->getLabel());

        // Esporta solo le classe_keys associate a published_content del docente target.
        try {
            $stmt = Database::connection()->prepare(
                'SELECT DISTINCT ck.id, ck.indirizzo, ck.classe, ck.anno_scolastico,
                        ck.key_version, HEX(ck.wrapped_key) AS wrapped_key_hex,
                        LENGTH(ck.wrapped_key) AS wrapped_key_size,
                        ck.created_at, ck.rotated_at, ck.archived_at
                 FROM classe_keys ck
                 INNER JOIN published_content pc ON pc.classe_key_id = ck.id
                 WHERE pc.teacher_id = ?
                    OR (pc.teacher_id IS NULL AND pc.source_id IN (
                        SELECT id FROM teacher_content WHERE teacher_id = ?
                    ))
                 ORDER BY ck.anno_scolastico DESC, ck.indirizzo, ck.classe'
            );
            $stmt->execute([$ctx->userId, $ctx->userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $section->summary['_error'] = 'query_failed: ' . $e->getMessage();
            return $section;
        }

        $section->addJsonFile('classe_keys.json', [
            'note' => 'wrapped_key è cifrata con KMS_MASTER_KEY (envelope encryption AES-256-GCM). '
                    . 'Per decifrare published_content del docente, l\'autorità deve richiedere al data '
                    . 'controller (data controller off-line) l\'unwrap delle chiavi tramite KMS_MASTER_KEY. '
                    . 'Vedi docs/security/operations/authority-cooperation.md §3.4.',
            'algorithm'   => 'AES-256-GCM envelope, wrapped_key = KMS_unwrap(wrapped_key_bytes)',
            'wrap_format' => '[12B IV][16B GCM tag][32B encrypted key material]',
            'keys'        => $rows,
        ]);

        $section->setSummary([
            'count' => count($rows),
            'note'  => 'wrapped_key esportata in HEX (cifrata). Decryption richiede KMS_MASTER_KEY off-line.',
        ]);
        return $section;
    }
}
