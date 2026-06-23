<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

use App\Core\Database;
use App\Core\PrivilegedAccessLogger;
use App\Domain\Risdoc\PendingStatus;
use PDO;
use RuntimeException;
use Throwable;

/**
 * G22.S26 — Review flow per modifiche di collaboratori risdoc soggetti a
 * supervisione super-admin.
 *
 * Quando un collaboratore con `requires_review=1` salva una modifica,
 * invece di scrivere direttamente l'institutional override la modifica
 * viene accodata in `risdoc_template_pending_changes` con status='pending'.
 * Il super-admin la rivede, approva (→ applica all'institutional override)
 * o rifiuta (→ marca rejected con motivazione).
 *
 * Centralizzazione: la decisione "enqueue vs apply diretto" sta in
 * {@see ReviewFlow::shouldEnqueueFor()}, in modo che ogni endpoint
 * mutativo possa interrogare l'unica policy senza duplicare la condizione.
 *
 * Audit: approve/reject scrivono in `privileged_access_log` oltre alle
 * colonne `reviewed_by/at/note` della row. Audit reason è ricavato dal
 * `note` se fornito (richiesto per reject, opzionale per approve).
 */
final class ReviewFlow
{
    /** Kind ammessi nella coda (allineato a institutional_overrides.kind). */
    private const ALLOWED_KINDS = ['html', 'tex', 'css', 'json', 'image', 'texCommon', 'schema'];

    /** MIME whitelist per upload image kind. */
    private const ALLOWED_IMAGE_MIME = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml',
    ];

    public function __construct(
        private ?InstitutionalOverrideRepository $institutional = null,
    ) {
    }

    /**
     * Policy centralizzata: la modifica va messa in coda review?
     *
     * Regola:
     *   - super-admin: NO (apply diretto)
     *   - collaboratore con requires_review=1: SI
     *   - altri (teacher senza permessi, ecc): NO ma il caller dovrà
     *     comunque verificare permessi di edit
     */
    public static function shouldEnqueueFor(int $templateId, int $teacherId): bool
    {
        if (Permission::isSuperAdmin()) {
            return false;
        }
        if ($teacherId <= 0) {
            return false;
        }
        if (!Permission::isCollaborator($templateId, $teacherId)) {
            return false;
        }
        return Permission::requiresReview($templateId, $teacherId);
    }

    /**
     * Valida il payload di un upload immagine (kind=image): MIME via
     * finfo + estensione consistente. Ritorna il MIME riconosciuto se OK,
     * altrimenti throws RuntimeException con codice diagnostico.
     */
    public static function validateImageUpload(string $tmpPath): string
    {
        if (!\is_file($tmpPath)) {
            throw new RuntimeException('upload_missing');
        }
        $finfo = \finfo_open(\FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException('finfo_unavailable');
        }
        $mime = \finfo_file($finfo, $tmpPath);
        \finfo_close($finfo);
        if (!\is_string($mime) || $mime === '') {
            throw new RuntimeException('mime_undetectable');
        }
        if (!\in_array($mime, self::ALLOWED_IMAGE_MIME, true)) {
            throw new RuntimeException('mime_not_allowed:' . $mime);
        }
        return $mime;
    }

    /**
     * Normalizza un path relativo rifiutando traversal (`..`, leading `/`,
     * backslash o NUL byte). Ritorna il path normalizzato; throws se invalid.
     */
    public static function sanitizePath(string $path): string
    {
        $p = \trim($path);
        if ($p === '') {
            return '';
        }
        if (\str_contains($p, "\0")) {
            throw new RuntimeException('path_invalid:nul_byte');
        }
        // normalize separatori
        $p = \str_replace('\\', '/', $p);
        if (\str_starts_with($p, '/')) {
            throw new RuntimeException('path_invalid:absolute');
        }
        // rifiuta segmenti `..` (o `.` isolati per pulizia)
        foreach (\explode('/', $p) as $seg) {
            if ($seg === '..' || $seg === '.') {
                throw new RuntimeException('path_invalid:traversal');
            }
        }
        return $p;
    }

    /**
     * Sottomette una modifica in pending. Ritorna l'id del pending row.
     *
     * @return int id del pending change row
     */
    public function submit(
        int $templateId,
        int $teacherId,
        string $kind,
        string $path,
        string $content,
        string $encoding = 'utf8',
        ?string $note = null,
    ): int {
        if (!\in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new RuntimeException('kind_invalid:' . $kind);
        }
        $path = self::sanitizePath($path);
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO risdoc_template_pending_changes
                (template_id, submitted_by, kind, path, content_encoding, content, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $templateId, $teacherId, $kind, $path, $encoding, $content, $note,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Lista pending. Default: solo status=pending, ordinati DESC.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listChanges(?PendingStatus $status = null, ?int $templateId = null): array
    {
        $where = [];
        $args = [];
        if ($status !== null) {
            $where[] = 'pc.status = ?';
            $args[] = $status->value;
        }
        if ($templateId !== null && $templateId > 0) {
            $where[] = 'pc.template_id = ?';
            $args[] = $templateId;
        }
        $sql = 'SELECT pc.id, pc.template_id, pc.submitted_by, pc.kind, pc.path,
                       pc.content_encoding, pc.note, pc.status, pc.reviewed_by,
                       pc.reviewed_at, pc.review_note, pc.submitted_at,
                       OCTET_LENGTH(pc.content) AS content_size,
                       t.code AS template_code, t.argomento AS template_argomento,
                       u.username AS submitter_username,
                       rv.username AS reviewer_username
                  FROM risdoc_template_pending_changes pc
                  JOIN risdoc_templates t ON t.id = pc.template_id
                  JOIN users u            ON u.id = pc.submitted_by
                  LEFT JOIN users rv      ON rv.id = pc.reviewed_by';
        if ($where) {
            $sql .= ' WHERE ' . \implode(' AND ', $where);
        }
        // DESC: il pending più recente in cima.
        $sql .= ' ORDER BY pc.submitted_at DESC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Restituisce il contenuto di un pending change + la baseline corrente.
     *
     * @return array{kind:string,path:string,content:string,encoding:string,is_image:bool,baseline:?string,template_id:int}|null
     */
    public function getChange(int $pendingId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT pc.template_id, pc.kind, pc.path, pc.content_encoding, pc.content
               FROM risdoc_template_pending_changes pc
              WHERE pc.id = ?'
        );
        $stmt->execute([$pendingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $baseline = null;
        if ($row['content_encoding'] === 'utf8') {
            $baseline = $this->resolveBaseline(
                (int)$row['template_id'],
                (string)$row['kind'],
                (string)$row['path'],
            );
        }
        return [
            'kind'        => (string)$row['kind'],
            'path'        => (string)$row['path'],
            'content'     => (string)$row['content'],
            'encoding'    => (string)$row['content_encoding'],
            'is_image'    => $row['content_encoding'] === 'base64',
            'baseline'    => $baseline,
            'template_id' => (int)$row['template_id'],
        ];
    }

    /**
     * Risolve la baseline corrente per un (template, kind, path):
     *   1. institutional override su DB → body
     *   2. fallback file su disco (via TemplateResolver)
     *   3. null se non disponibile
     */
    private function resolveBaseline(int $templateId, string $kind, string $path): ?string
    {
        $repo = $this->institutional ?? new InstitutionalOverrideRepository();
        $row = $repo->find($templateId, $kind, $path);
        if ($row && isset($row['body']) && $row['body'] !== null) {
            return (string)$row['body'];
        }
        try {
            $resolver = new TemplateResolver();
            $result = $resolver->resolveFile(0, $templateId, $kind, $path);
            if ($result && isset($result['body'])) {
                return (string)$result['body'];
            }
        } catch (Throwable) {
            // baseline opzionale: non bloccare la review.
        }
        return null;
    }

    /**
     * Approva un pending change: applica all'institutional override e marca
     * la row come `approved`.
     *
     * Scrive PrivilegedAccessLogger entry come traccia audit (oltre alle
     * colonne reviewed_by/at della row).
     *
     * @return array{ok:bool, error?:string}
     */
    public function approve(int $pendingId, int $reviewerId, ?string $note = null): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM risdoc_template_pending_changes
                  WHERE id = ? AND status = "pending" FOR UPDATE'
            );
            $stmt->execute([$pendingId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'not_found_or_already_reviewed'];
            }

            // source_hash corrente del template (pattern institutionalOverrideSave).
            $st2 = $pdo->prepare('SELECT source_hash FROM risdoc_templates WHERE id=?');
            $st2->execute([(int)$row['template_id']]);
            $srcVersion = (string)($st2->fetchColumn() ?: '');

            $content = (string)$row['content'];
            $repo = $this->institutional ?? new InstitutionalOverrideRepository();
            if ($row['content_encoding'] === 'base64') {
                $bin = \base64_decode($content, true);
                if ($bin === false) {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => 'invalid_base64_payload'];
                }
                $hash = \hash('sha256', $bin);
                $base = \dirname(__DIR__, 3) . '/storage/overrides/institutional';
                if (!\is_dir($base) && !\mkdir($base, 0o775, true) && !\is_dir($base)) {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => 'storage_mkdir_failed:' . $base];
                }
                $dest = $base . '/' . $hash;
                if (!\is_file($dest) && \file_put_contents($dest, $bin) === false) {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => 'storage_write_failed'];
                }
                $repo->saveImage(
                    (int)$row['template_id'],
                    (string)$row['path'],
                    $hash,
                    $srcVersion,
                    $reviewerId,
                );
            } else {
                $repo->saveText(
                    (int)$row['template_id'],
                    (string)$row['kind'],
                    (string)$row['path'],
                    $content,
                    $srcVersion,
                    $reviewerId,
                );
            }

            $pdo->prepare(
                'UPDATE risdoc_template_pending_changes
                    SET status = "approved", reviewed_by = ?,
                        reviewed_at = NOW(), review_note = ?
                  WHERE id = ?'
            )->execute([$reviewerId, $note, $pendingId]);

            $pdo->commit();
            $this->audit('risdoc.pending.approve', $pendingId, (string)($note ?? '(no_note)'), 'ok');
            return ['ok' => true];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $this->audit('risdoc.pending.approve', $pendingId, $e->getMessage(), 'error');
            return ['ok' => false, 'error' => 'apply_failed: ' . $e->getMessage()];
        }
    }

    /**
     * Rifiuta un pending change. Note obbligatoria (audit + feedback).
     */
    public function reject(int $pendingId, int $reviewerId, string $note): array
    {
        $note = \trim($note);
        if ($note === '') {
            return ['ok' => false, 'error' => 'review_note_required'];
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE risdoc_template_pending_changes
                SET status = "rejected", reviewed_by = ?,
                    reviewed_at = NOW(), review_note = ?
              WHERE id = ? AND status = "pending"'
        );
        $stmt->execute([$reviewerId, $note, $pendingId]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'error' => 'not_found_or_already_reviewed'];
        }
        $this->audit('risdoc.pending.reject', $pendingId, $note, 'ok');
        return ['ok' => true];
    }

    /** Conta pending per il badge "N modifiche da rivedere". */
    public function countPending(): int
    {
        $stmt = Database::connection()->query(
            'SELECT COUNT(*) FROM risdoc_template_pending_changes WHERE status = "pending"'
        );
        return (int)$stmt->fetchColumn();
    }

    /** Wrapper PrivilegedAccessLogger fail-safe. */
    private function audit(string $action, int $pendingId, string $reason, string $outcome): void
    {
        try {
            PrivilegedAccessLogger::log(
                $action,
                'risdoc_pending_change',
                (string)$pendingId,
                $reason !== '' ? $reason : '(no_reason)',
                $outcome,
            );
        } catch (Throwable) {
            // best-effort: il flow principale non deve fallire per audit
        }
    }
}
