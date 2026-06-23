<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Phase G8 — CRUD per verifica_documents (TEX/PDF cifrato per docente).
 *
 * Una row per verifica salvata via 💾 SalvaTEX. Owner = teacher_id.
 * Cross-teacher access non e' previsto in G8 (verifiche restano private
 * al docente; lo studente vede solo i link via fm-db-block sidebar
 * pubblicata in pagina del docente, ma il blob TEX non e' downloadable
 * senza essere il owner).
 *
 * @phpstan-type Doc array{
 *   id: int,
 *   teacher_id: int,
 *   materia: string,
 *   title: string,
 *   fm_db_section: string,
 *   exercise_ids: list<int>,
 *   tex_blob_path: string,
 *   tex_blob_kv: int,
 *   tex_size: int,
 *   pdf_blob_path: ?string,
 *   pdf_blob_kv: ?int,
 *   pdf_size: ?int,
 *   pdf_filename: ?string,
 *   pdf_uploaded_at: ?string,
 *   created_at: string,
 *   updated_at: string
 * }
 */
class VerificaDocumentRepository
{
    public function create(array $data): int
    {
        // G22.S2 — tex_sha256 (CHAR(64)) opzionale: se non presente nei
        // record legacy (pre-migration 030) resta NULL e disabilita la
        // cache PDF per quella row. Validazione defensive: deve essere
        // 64 hex chars o null.
        $sha = $data['tex_sha256'] ?? null;
        if ($sha !== null) {
            $sha = (string)$sha;
            if ($sha === '' || !preg_match('/^[a-f0-9]{64}$/', $sha)) {
                $sha = null;
            }
        }

        // G22.S4.B.2 — tex_files (JSON encoded) per multi-blob storage:
        // [{path, blob_path, blob_kv, sha256}, ...]. Se presente, il
        // tex_blob_path/kv/size legacy puo' essere null. Se assente, si usa
        // il blob singolo legacy (back-compat row pre-S4.B.2).
        $files = $data['tex_files'] ?? null;
        $filesJson = null;
        if (\is_array($files) && $files) {
            $filesJson = \json_encode(\array_values($files), JSON_UNESCAPED_UNICODE);
        }

        // G22.S20 v2.C2 Fase B — Solo FK ids (varchar dropped, varchar esposte
        // via VIEW `verifica_documents`). INSERT/UPDATE su tabella sottostante.
        $sql = 'INSERT INTO verifica_documents_data
                (teacher_id, materia_id, indirizzo_id, classe_id,
                 title, fm_db_section,
                 batch_id, variant, version_label,
                 exercise_ids, selection_json,
                 tex_blob_path, tex_blob_kv, tex_size,
                 tex_files, tex_sha256)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = Database::connection()->prepare($sql);
        $tid = (int)$data['teacher_id'];
        $L = \App\Support\CurriculumLookup::class;
        $indirizzoId = !empty($data['indirizzo']) ? $L::idFromCodeForTeacher('indirizzi', (string)$data['indirizzo'], $tid) : null;
        $classeId    = !empty($data['classe'])    ? $L::idFromCodeForTeacher('classi', (string)$data['classe'], $tid) : null;
        $materiaId   = !empty($data['materia'])   ? $L::idFromCodeForTeacher('materie', (string)$data['materia'], $tid) : null;

        // Per multi-file storage, le 3 colonne legacy sono NULL.
        $blobPath = isset($data['tex_blob_path']) && $data['tex_blob_path'] !== ''
            ? (string)$data['tex_blob_path'] : null;
        $blobKv = isset($data['tex_blob_kv']) ? (int)$data['tex_blob_kv'] : null;
        $size   = isset($data['tex_size'])    ? (int)$data['tex_size']    : null;

        $stmt->execute([
            $tid,
            $materiaId,
            $indirizzoId,
            $classeId,
            (string)$data['title'],
            (string)($data['fm_db_section'] ?? 'VERIFICHE'),
            isset($data['batch_id']) ? (string)$data['batch_id'] : null,
            (string)($data['variant'] ?? ''),
            isset($data['version_label']) && $data['version_label'] !== '' ? (string)$data['version_label'] : null,
            \json_encode(array_map('intval', $data['exercise_ids'] ?? []), JSON_UNESCAPED_UNICODE),
            isset($data['selection_json']) ? (string)$data['selection_json'] : null,
            $blobPath,
            $blobKv,
            $size,
            $filesJson,
            $sha,
        ]);
        return (int)Database::connection()->lastInsertId();
    }

    /**
     * G22.S4.B.2 — Decodifica e ritorna la manifest tex_files come array
     * di {path, blob_path, blob_kv, sha256}, o array vuoto se la row e'
     * legacy single-blob.
     *
     * @return list<array{path:string, blob_path:string, blob_kv:int, sha256?:string}>
     */
    public function texFiles(int $docId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT tex_files FROM verifica_documents WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$docId]);
        $json = $stmt->fetchColumn();
        if (!\is_string($json) || $json === '') {
            return [];
        }
        $arr = json_decode($json, true);
        if (!\is_array($arr)) {
            return [];
        }
        return array_values(array_filter($arr, static fn($f) => \is_array($f) && isset($f['path'], $f['blob_path'])));
    }

    /**
     * G22.S2 — Cache PDF lookup: trova un altro doc dello STESSO docente
     * con lo stesso tex_sha256 e pdf_blob_path popolato. La row puo'
     * essere riusata come sorgente per cifrare un nuovo PDF blob della
     * row corrente, evitando la chiamata a tex-compile-vps.
     *
     * Esclude la row $excludeId (tipicamente la verifica corrente che si
     * sta compilando, per evitare self-cache hit no-op).
     *
     * Ordine: piu' recente prima → riusa il compile piu' fresco. Limit 1.
     *
     * @return array<string,mixed>|null hydrated row con tex_blob_path/kv,
     *                                  pdf_blob_path/kv/size/filename.
     */
    public function findCachedPdf(int $teacherId, string $sha256, int $excludeId): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            return null;
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM verifica_documents
             WHERE teacher_id = ? AND tex_sha256 = ?
               AND pdf_blob_path IS NOT NULL
               AND id <> ?
             ORDER BY pdf_uploaded_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$teacherId, $sha256, $excludeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /** G19.44 — Trova doc esistenti per (teacher, materia, title, variant, version_label).
     *  Usato per conflict check prima di saveBatch. NULL version_label e
     *  '' string sono considerati equivalenti (LEGACY no-label). */
    public function findExistingForBatch(int $teacherId, string $materia, string $title, array $variants, string $versionLabel): array
    {
        if (!$variants) {
            return [];
        }
        // G19.44 — il base title in DB e' salvato con suffisso variante
        // (`{title} — A_SOL`); cerca tutte le varianti con prefisso `{title} —`.
        $titlePrefix = $title . ' — ';
        $placeholders = implode(',', array_fill(0, \count($variants), '?'));
        $sql = "SELECT * FROM verifica_documents
                WHERE teacher_id = ? AND materia = ? AND title LIKE ?
                  AND variant IN ($placeholders)
                  AND ((? = '' AND (version_label IS NULL OR version_label = ''))
                       OR version_label = ?)";
        $args = [$teacherId, $materia, $titlePrefix . '%'];
        foreach ($variants as $v) {
            $args[] = $v;
        }
        $args[] = $versionLabel;
        $args[] = $versionLabel;
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /** Lista doc di un batch (8 varianti generate insieme). */
    public function listForBatch(int $teacherId, string $batchId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM verifica_documents
             WHERE teacher_id = ? AND batch_id = ?
             ORDER BY variant'
        );
        $stmt->execute([$teacherId, $batchId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = $this->hydrate($r);
        }
        return $out;
    }

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM verifica_documents WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Lista verifiche di un docente filtrate per materia/indirizzo/classe
     * (per fm-db-block render). Indirizzo/classe disponibili dalla migration 027.
     * Per i record legacy (indirizzo/classe NULL): inclusi solo quando entrambi
     * i filtri sono vuoti, altrimenti esclusi (evita la perdita di scope tra
     * verifiche di classi diverse).
     */
    public function listForTeacher(
        int $teacherId,
        ?string $materia = null,
        ?string $section = null,
        ?string $indirizzo = null,
        ?string $classe = null,
    ): array {
        $sql = 'SELECT * FROM verifica_documents WHERE teacher_id = ?';
        $args = [$teacherId];
        if ($materia !== null && $materia !== '') {
            $sql .= ' AND materia = ?';
            $args[] = $materia;
        }
        if ($section !== null && $section !== '') {
            $sql .= ' AND fm_db_section = ?';
            $args[] = $section;
        }
        if ($indirizzo !== null && $indirizzo !== '') {
            $sql .= ' AND indirizzo = ?';
            $args[] = $indirizzo;
        }
        if ($classe !== null && $classe !== '') {
            $sql .= ' AND classe = ?';
            $args[] = $classe;
        }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = $this->hydrate($r);
        }
        return $out;
    }

    /**
     * Phase 25.Q.15 — lista verifiche shared con pool, visibili a studenti
     * (e ad altri docenti dello stesso istituto). Filtra:
     *   - shared_with_pool = 1 (esplicitamente condivise)
     *   - istituto: teacher_id appartiene a teacher_institutes(institute_id)
     *   - indirizzo + classe (sezione studente, opzionale)
     * Niente filtro materia in query (filtro client-side per coerenza con
     * teacher view).
     */
    public function listSharedForInstitute(
        int $instituteId,
        ?string $indirizzo = null,
        ?string $classe = null,
    ): array {
        $sql = 'SELECT vd.* FROM verifica_documents vd
                INNER JOIN teacher_institutes ti
                    ON ti.user_id = vd.teacher_id AND ti.institute_id = ?
                WHERE vd.shared_with_pool = 1
                  AND vd.fm_db_section = ?';
        $args = [$instituteId, 'VERIFICHE'];
        if ($indirizzo !== null && $indirizzo !== '') {
            $sql .= ' AND vd.indirizzo = ?';
            $args[] = $indirizzo;
        }
        if ($classe !== null && $classe !== '') {
            $sql .= ' AND vd.classe = ?';
            $args[] = $classe;
        }
        $sql .= ' ORDER BY vd.created_at DESC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = $this->hydrate($r);
        }
        return $out;
    }

    /** Lista materie distinte usate dal docente (per render multi-block sidebar). */
    public function listMaterieForTeacher(int $teacherId, string $section = 'VERIFICHE'): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT materia FROM verifica_documents
             WHERE teacher_id = ? AND fm_db_section = ?
             ORDER BY materia ASC'
        );
        $stmt->execute([$teacherId, $section]);
        return array_map(static fn($r) => (string)$r['materia'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function attachPdf(
        int $id,
        string $blobPath,
        int $blobKv,
        int $size,
        string $filename
    ): void {
        $stmt = Database::connection()->prepare(
            'UPDATE verifica_documents_data
             SET pdf_blob_path = ?, pdf_blob_kv = ?, pdf_size = ?,
                 pdf_filename = ?, pdf_uploaded_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([$blobPath, $blobKv, $size, $filename, $id]);
    }

    public function detachPdf(int $id): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE verifica_documents_data
             SET pdf_blob_path = NULL, pdf_blob_kv = NULL, pdf_size = NULL,
                 pdf_filename = NULL, pdf_uploaded_at = NULL
             WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    /**
     * G21.1 — Aggiorna il riferimento al blob TEX di una verifica esistente.
     * Usato quando l'utente modifica il TEX dal preview modal.
     * Il caller (Service) ha già scritto il nuovo blob cifrato.
     *
     * G22.S2 — Accetta opzionalmente $sha256 (SHA256 del nuovo TEX) per
     * mantenere allineata la cache PDF: se la sha cambia, il prossimo
     * compilePdf cerchera' un nuovo PDF cached. NULL = lascia invariato.
     */
    public function updateTexBlob(
        int $id,
        string $blobPath,
        int $blobKv,
        int $size,
        ?string $sha256 = null,
    ): void {
        if ($sha256 !== null) {
            if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
                $sha256 = null;
            }
        }

        if ($sha256 === null) {
            $stmt = Database::connection()->prepare(
                'UPDATE verifica_documents_data
                 SET tex_blob_path = ?, tex_blob_kv = ?, tex_size = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?'
            );
            $stmt->execute([$blobPath, $blobKv, $size, $id]);
            return;
        }
        $stmt = Database::connection()->prepare(
            'UPDATE verifica_documents_data
             SET tex_blob_path = ?, tex_blob_kv = ?, tex_size = ?,
                 tex_sha256 = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([$blobPath, $blobKv, $size, $sha256, $id]);
    }

    /**
     * G22.S10 — Aggiorna manifest multi-file (tex_files JSON) + size + sha256.
     * Il caller è responsabile dello scrivere/cancellare i blob su storage.
     *
     * @param list<array{path:string, blob_path:string, blob_kv:int, sha256:string, size:int}> $manifest
     */
    public function updateTexFiles(int $id, array $manifest, int $totalSize, string $sha256): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new \RuntimeException('verifica_invalid_sha256');
        }
        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('verifica_manifest_encode_failed');
        }
        $stmt = Database::connection()->prepare(
            'UPDATE verifica_documents_data
             SET tex_files = ?, tex_size = ?, tex_sha256 = ?,
                 tex_blob_path = NULL, tex_blob_kv = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([$json, $totalSize, $sha256, $id]);
    }

    public function delete(int $id): void
    {
        // Phase 25.R.25 — Pre-fetch teacher_id per audit log content_deleted
        $teacherId = 0;
        try {
            $pre = Database::connection()->prepare(
                'SELECT teacher_id FROM verifica_documents_data WHERE id = ? LIMIT 1'
            );
            $pre->execute([$id]);
            $teacherId = (int)($pre->fetchColumn() ?: 0);
        } catch (\Throwable) {
        }

        $stmt = Database::connection()->prepare(
            'DELETE FROM verifica_documents_data WHERE id = ?'
        );
        $stmt->execute([$id]);

        if ($teacherId > 0) {
            \App\Services\Audit\ContentActionLogger::log(
                \App\Services\Audit\ContentActionLogger::ACTION_DELETED,
                $teacherId,
                $id,
                'verifica'
            );
        }
    }

    public function rename(int $id, string $title): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE verifica_documents_data SET title = ? WHERE id = ?'
        );
        $stmt->execute([$title, $id]);
    }

    /** Decode JSON columns + cast types. */
    private function hydrate(array $row): array
    {
        $row['id']            = (int)$row['id'];
        $row['teacher_id']    = (int)$row['teacher_id'];
        // G22.S4.B.2 — tex_blob_path/kv/size ora NULLable per row multi-file.
        $row['tex_blob_path'] = $row['tex_blob_path'] ?? null;
        $row['tex_blob_kv']   = isset($row['tex_blob_kv']) ? (int)$row['tex_blob_kv'] : null;
        $row['tex_size']      = isset($row['tex_size']) ? (int)$row['tex_size'] : null;
        $row['pdf_blob_kv']   = isset($row['pdf_blob_kv']) ? (int)$row['pdf_blob_kv'] : null;
        $row['pdf_size']      = isset($row['pdf_size']) ? (int)$row['pdf_size'] : null;
        $exIds = json_decode((string)($row['exercise_ids'] ?? '[]'), true);
        $row['exercise_ids'] = is_array($exIds) ? array_values(array_map('intval', $exIds)) : [];
        $row['version_label'] = $row['version_label'] ?? null;

        // G22.S4.B.2 — tex_files JSON decode (manifest multi-blob).
        // Lasciamo grezzo il campo se decode fallisce, cosi' i caller
        // possono fare fallback a tex_blob_path.
        if (isset($row['tex_files']) && \is_string($row['tex_files']) && $row['tex_files'] !== '') {
            $files = json_decode($row['tex_files'], true);
            $row['tex_files'] = \is_array($files) ? $files : null;
        } else {
            $row['tex_files'] = null;
        }

        return $row;
    }
}
