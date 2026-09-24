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
        // ADR-037, fase 4c-2 — indirizzo e classe non stanno più nella riga: sono
        // il posto della pubblicazione principale, scritta sotto. La materia sì:
        // è parte di che cos'è la verifica (l'indice unico uq_verif_doc_title_fk
        // con docente, titolo, variante e versione, che ferma due salvataggi
        // simultanei), e la principale ne porta una copia che la verifica di
        // allineamento confronta.
        $sql = 'INSERT INTO verifica_documents_data
                (teacher_id, materia_id,
                 title, fm_db_section,
                 batch_id, variant, version_label,
                 exercise_ids, selection_json,
                 tex_blob_path, tex_blob_kv, tex_size,
                 tex_files, tex_sha256, source_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = Database::connection()->prepare($sql);
        $tid = (int)$data['teacher_id'];
        $L = \App\Support\CurriculumLookup::class;
        // ADR-037, fase 3 — la copia in un'altra scuola passa gli id già
        // verificati (DoveVale::verificaLuogo): risolvere le sigle nella scuola
        // attiva, che può essere un'altra, le metterebbe nel posto sbagliato.
        $idDato = static fn(string $k): ?int => isset($data[$k]) && (int)$data[$k] > 0 ? (int)$data[$k] : null;
        $indirizzoId = $idDato('indirizzo_id') ?? (!empty($data['indirizzo']) ? $L::idFromCodeForTeacher('indirizzi', (string)$data['indirizzo'], $tid) : null);
        $classeId    = $idDato('classe_id') ?? (!empty($data['classe']) ? $L::idFromCodeForTeacher('classi', (string)$data['classe'], $tid, !empty($data['indirizzo']) ? (string)$data['indirizzo'] : null) : null);
        $materiaId   = $idDato('materia_id') ?? (!empty($data['materia']) ? $L::idFromCodeForTeacher('materie', (string)$data['materia'], $tid) : null);

        // Per multi-file storage, le 3 colonne legacy sono NULL.
        $blobPath = isset($data['tex_blob_path']) && $data['tex_blob_path'] !== ''
            ? (string)$data['tex_blob_path'] : null;
        $blobKv = isset($data['tex_blob_kv']) ? (int)$data['tex_blob_kv'] : null;
        $size   = isset($data['tex_size'])    ? (int)$data['tex_size']    : null;

        // Phase 25.P.3 — fonte (copyright) calcolata dai quesiti della selezione
        // al salvataggio (2026-09-05: prima restava NULL e la verifica non si
        // poteva mai condividere). Solo i valori dell'enum, altrimenti NULL.
        $sourceType = isset($data['source_type'])
            && \in_array((string)$data['source_type'], ['personal', 'book_textbook', 'mixed', 'public_domain', 'cc_licensed'], true)
            ? (string)$data['source_type'] : null;

        $stmt->execute([
            $tid,
            $materiaId,
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
            $sourceType,
        ]);
        $nuova = (int)Database::connection()->lastInsertId();
        // ADR-037, fase 4c — il posto della variante è la sua principale, e la
        // scrive solo l'applicazione (App\Support\PostoPrincipale).
        \App\Support\PostoPrincipale::verifica(Database::connection(), $nuova, $indirizzoId, $classeId, $materiaId);
        return $nuova;
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

    /**
     * Il titolo senza il suffisso di variante («Frazioni — A_SOL» → «Frazioni»):
     * la regola della barra e del modale (verifica-documents-sidepage.js,
     * verifica-detail-modal.js), rifatta sul server.
     */
    public static function titoloBase(string $titolo): string
    {
        return trim((string)preg_replace('/\s*[—-]\s*[AB]_(SOL|NOR|DSA|DIS)\s*$/u', '', $titolo));
    }

    /**
     * Le righe della verifica di cui `$id` è una variante: stesso docente, stessa
     * materia, stesso titolo base. È la verifica come la vede chi la usa — tutte
     * le varianti (A e B, SOL · NOR · DSA · DIS) e tutte le versioni — ed è
     * l'unità a cui si applicano «Dove vale» (ADR-037), la condivisione con i
     * colleghi e i grant. Nessun controllo di proprietà: lo fa il chiamante.
     *
     * @return list<int> vuota se la riga non esiste
     */
    public function righeDellaVerifica(int $id): array
    {
        $pdo = Database::connection();
        $st = $pdo->prepare('SELECT teacher_id, materia_id, title FROM verifica_documents WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return [];
        }
        $base = mb_strtolower(self::titoloBase((string)$r['title']));
        $st = $pdo->prepare(
            'SELECT id, title FROM verifica_documents WHERE teacher_id = ? AND materia_id <=> ? ORDER BY id'
        );
        $st->execute([(int)$r['teacher_id'], $r['materia_id']]);
        $ids = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $riga) {
            if (mb_strtolower(self::titoloBase((string)$riga['title'])) === $base) {
                $ids[] = (int)$riga['id'];
            }
        }
        return $ids;
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
     *
     * ADR-037, fase 3 — con `$scuola` (la scuola attiva della barra) le sigle
     * si confrontano sulle PUBBLICAZIONI in quella scuola, la principale o una
     * scelta dal docente: prima una verifica nata in una scuola compariva
     * nella barra di un'altra se le sigle coincidevano. Le verifiche senza
     * nessuna scuola restano al proprietario in ogni scuola, con le sigle della
     * riga. Senza `$scuola` (esportazioni, sincronizzazione: operazioni del
     * proprietario su tutto) la regola resta quella di prima.
     */
    public function listForTeacher(
        int $teacherId,
        ?string $materia = null,
        ?string $section = null,
        ?string $indirizzo = null,
        ?string $classe = null,
        ?int $scuola = null,
    ): array {
        $sql = 'SELECT * FROM verifica_documents vd WHERE vd.teacher_id = ?';
        $args = [$teacherId];
        $vuoto = static fn(?string $v): ?string => $v !== null && $v !== '' ? $v : null;
        if ($section !== null && $section !== '') {
            $sql .= ' AND vd.fm_db_section = ?';
            $args[] = $section;
        }
        if ($scuola !== null && $scuola > 0) {
            [$nella, $a] = \App\Support\Pubblicazioni::nellaScuola(
                'vd',
                $scuola,
                $teacherId,
                $vuoto($indirizzo),
                $vuoto($classe),
                $vuoto($materia),
                false,
                \App\Support\Pubblicazioni::VERIFICA
            );
            $sql .= ' AND ' . $nella;
            foreach ($a as $v) {
                $args[] = $v;
            }
        } else {
            foreach (['materia' => $materia, 'indirizzo' => $indirizzo, 'classe' => $classe] as $col => $valore) {
                if ($vuoto($valore) !== null) {
                    $sql .= " AND vd.{$col} = ?";
                    $args[] = $valore;
                }
            }
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

    /**
     * ADR-037, fase 3 — le verifiche che vede chi studia: `/api/study/verifica/list`.
     *
     * `$f` sono i filtri di {@see \App\Domain\ContentVisibilityPolicy::studyListFilters()},
     * gli stessi dei contenuti, e si leggono allo stesso modo:
     *   - `grants` (credenziali di classe, il portachiavi): per ogni credenziale
     *     le verifiche del suo docente pubblicate nella sua scuola, per la
     *     classe coperta; negli anni passati solo le marcate «visibile anche
     *     dopo l'anno»;
     *   - `student_scope` (studente con account): pubblicate nella sua scuola,
     *     per il suo indirizzo e la sua classe o il suo anno, di un docente con
     *     un incarico nella sua sezione (teacher_sections: senza incarichi non
     *     vede niente, come per i contenuti);
     *   - `pub_institute_id` (docente, amministratore): nella scuola, in
     *     qualunque stato, con le sigle chieste sulla pubblicazione. Il
     *     chiamante applica poi l'ACL fra colleghi riga per riga.
     * Tutto il resto (ospite senza credenziale, `__deny__`) non vede niente.
     *
     * Prima una verifica arrivava agli studenti se era condivisa con i colleghi
     * (shared_with_pool), per sigle e senza incarichi: condividere e pubblicare
     * erano la stessa casella (il punto aperto di ADR-032).
     *
     * @param array<string,mixed> $f
     * @return list<array<string,mixed>>
     */
    public function listForStudy(array $f, ?string $indirizzo = null, ?string $classe = null): array
    {
        $V = \App\Support\Pubblicazioni::VERIFICA;
        $where = ["vd.fm_db_section = 'VERIFICHE'"];
        $args = [];
        if (!empty($f['grants']) && \is_array($f['grants'])) {
            $parti = [];
            foreach ($f['grants'] as $g) {
                $tid = (int)($g['teacher_id'] ?? 0);
                if ($tid <= 0) {
                    continue;
                }
                $classi = array_values(array_unique(array_map('strval', (array)($g['classi'] ?? []))));
                $ind = isset($g['indirizzo']) && (string)$g['indirizzo'] !== '' ? (string)$g['indirizzo'] : null;
                $scuola = isset($g['institute_id']) && (int)$g['institute_id'] > 0 ? (int)$g['institute_id'] : null;
                $archivio = !empty($g['archivio']);
                [$perimetro, $a] = $classi !== []
                    ? \App\Support\Pubblicazioni::perimetroDiClasse('vd', $scuola, $classi, $ind, $archivio, null, $V)
                    : \App\Support\Pubblicazioni::esiste('vd', $scuola, [], null, 'published', false, $archivio, null, false, $V);
                $parti[] = "(vd.teacher_id = ? AND {$perimetro})";
                $args[] = $tid;
                foreach ($a as $v) {
                    $args[] = $v;
                }
            }
            if ($parti === []) {
                return [];
            }
            $where[] = '(' . implode(' OR ', $parti) . ')';
        } elseif (!empty($f['student_scope'])) {
            $scuola = (int)($f['institute_id'] ?? 0);
            $ind = (string)($f['indirizzo'] ?? '');
            $cls = (string)($f['classe'] ?? '');
            if ($scuola <= 0 || $ind === '' || $cls === '') {
                return [];
            }
            $docenti = (new \App\Services\TeacherSectionService())->teachersForStudent($scuola, $ind, $cls);
            if ($docenti === []) {
                return [];
            }
            $classi = (!empty($f['classi']) && \is_array($f['classi']))
                ? array_values(array_unique(array_map('strval', $f['classi'])))
                : \App\Domain\ClassCode::covering($cls);
            $where[] = 'vd.teacher_id IN (' . implode(',', array_fill(0, count($docenti), '?')) . ')';
            foreach ($docenti as $d) {
                $args[] = $d;
            }
            [$perimetro, $a] = \App\Support\Pubblicazioni::perimetroDiClasse('vd', $scuola, $classi, $ind, !empty($f['archivio']), null, $V);
            $where[] = $perimetro;
            foreach ($a as $v) {
                $args[] = $v;
            }
        } elseif (!empty($f['pub_institute_id'])) {
            [$nella, $a] = \App\Support\Pubblicazioni::nellaScuola(
                'vd',
                (int)$f['pub_institute_id'],
                (int)($f['pub_actor_id'] ?? 0),
                $indirizzo !== null && $indirizzo !== '' ? $indirizzo : null,
                $classe !== null && $classe !== '' ? $classe : null,
                null,
                false,
                $V
            );
            $where[] = $nella;
            foreach ($a as $v) {
                $args[] = $v;
            }
        } else {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT vd.* FROM verifica_documents vd WHERE ' . implode(' AND ', $where) . ' ORDER BY vd.created_at DESC'
        );
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
