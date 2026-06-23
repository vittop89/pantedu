<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\Crypto\TeacherCryptoService;
use PDO;
use RuntimeException;

/**
 * Phase G1.a — Persistence per teacher_drive_oauth.
 *
 * Wrappa il refresh_token via TeacherCryptoService (envelope encryption,
 * ADR-006): la riga DB contiene SOLO ciphertext + iv + tag + key_version.
 * Disconnect = DELETE row → token diventa unreadable, idempotent.
 *
 * Niente lookup by email o scope: identita' utente = teacher_id (FK users).
 *
 * @phpstan-type DriveOAuthRow array{
 *   teacher_id: int,
 *   scope: string,
 *   email: ?string,
 *   drive_root_id: ?string,
 *   connected_at: string,
 *   last_sync_at: ?string
 * }
 */
final class DriveOAuthRepository
{
    private TeacherCryptoService $crypto;

    public function __construct(?TeacherCryptoService $crypto = null)
    {
        $this->crypto = $crypto ?? new TeacherCryptoService();
    }

    /**
     * Salva (o aggiorna) il refresh_token per il teacher dato.
     *
     * Usato da DriveController::callback() dopo il code exchange OAuth.
     * Re-connect dello stesso teacher sovrascrive la row precedente
     * (UPSERT via ON DUPLICATE KEY UPDATE).
     */
    public function upsert(
        int $teacherId,
        string $refreshToken,
        string $scope,
        ?string $email = null,
        ?string $driveRootId = null
    ): void {
        if (!$this->crypto->isConfigured()) {
            throw new RuntimeException('drive_oauth_kms_not_configured');
        }

        $envelope = $this->crypto->encrypt($teacherId, $refreshToken);

        $sql = 'INSERT INTO teacher_drive_oauth
                (teacher_id, refresh_token_ct, refresh_token_iv,
                 refresh_token_tag, refresh_token_kv, scope, email,
                 drive_root_id, connected_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                  refresh_token_ct  = VALUES(refresh_token_ct),
                  refresh_token_iv  = VALUES(refresh_token_iv),
                  refresh_token_tag = VALUES(refresh_token_tag),
                  refresh_token_kv  = VALUES(refresh_token_kv),
                  scope             = VALUES(scope),
                  email             = VALUES(email),
                  drive_root_id     = COALESCE(VALUES(drive_root_id), drive_root_id),
                  connected_at      = NOW()';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            $teacherId,
            $envelope['ciphertext'],
            $envelope['iv'],
            $envelope['tag'],
            $envelope['kv'],
            $scope,
            $email,
            $driveRootId,
        ]);
    }

    /**
     * Recupera + decifra il refresh_token. Restituisce null se il teacher
     * non e' connesso (no row). RuntimeException se KMS non configurato
     * o decrypt fallisce (tampering o KEK shred-ed).
     */
    public function getRefreshToken(int $teacherId): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT refresh_token_ct, refresh_token_iv, refresh_token_tag, refresh_token_kv
             FROM teacher_drive_oauth WHERE teacher_id = ? LIMIT 1'
        );
        $stmt->execute([$teacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return $this->crypto->decrypt($teacherId, [
            'ciphertext' => $row['refresh_token_ct'],
            'iv'         => $row['refresh_token_iv'],
            'tag'        => $row['refresh_token_tag'],
            'kv'         => (int)$row['refresh_token_kv'],
        ]);
    }

    /**
     * Metadata (no token). Usato da /teacher/drive/status per UI pill.
     *
     * @return DriveOAuthRow|null
     */
    public function getMetadata(int $teacherId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT teacher_id, scope, email, drive_root_id, connected_at, last_sync_at
             FROM teacher_drive_oauth WHERE teacher_id = ? LIMIT 1'
        );
        $stmt->execute([$teacherId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'teacher_id'    => (int)$row['teacher_id'],
            'scope'         => (string)$row['scope'],
            'email'         => $row['email'] !== null ? (string)$row['email'] : null,
            'drive_root_id' => $row['drive_root_id'] !== null ? (string)$row['drive_root_id'] : null,
            'connected_at'  => (string)$row['connected_at'],
            'last_sync_at'  => $row['last_sync_at'] !== null ? (string)$row['last_sync_at'] : null,
        ];
    }

    public function isConnected(int $teacherId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM teacher_drive_oauth WHERE teacher_id = ? LIMIT 1'
        );
        $stmt->execute([$teacherId]);
        return (bool)$stmt->fetchColumn();
    }

    /** Disconnect = elimina row. Idempotent. */
    public function delete(int $teacherId): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM teacher_drive_oauth WHERE teacher_id = ?'
        );
        $stmt->execute([$teacherId]);
    }

    public function updateDriveRootId(int $teacherId, string $driveRootId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE teacher_drive_oauth SET drive_root_id = ? WHERE teacher_id = ?'
        );
        $stmt->execute([$driveRootId, $teacherId]);
    }

    public function touchLastSync(int $teacherId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE teacher_drive_oauth SET last_sync_at = NOW() WHERE teacher_id = ?'
        );
        $stmt->execute([$teacherId]);
    }

    /**
     * Phase G6 — aggiorna scope ed email senza toccare il refresh_token
     * cifrato (caso re-consent Google senza nuovo refresh_token).
     */
    public function updateScopeOnly(int $teacherId, string $scope, ?string $email): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE teacher_drive_oauth
             SET scope = ?, email = COALESCE(?, email), connected_at = NOW()
             WHERE teacher_id = ?'
        );
        $stmt->execute([$scope, $email, $teacherId]);
    }
}
